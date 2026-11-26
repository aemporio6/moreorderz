<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-94 Stock › Inventory sharing. */
class InventorySharingTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1); }
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_d94_which_shop_gets_which_sku_and_is_it_in_sync(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Mp94', 'f_active' => 1]);
        $prof = $this->f('mp_profiles', ['fk_id_ent' => 1, 'code' => 'P94', 'name' => 'Profile 94', 'fk_id_marketplace' => $mp, 'f_active' => 1, 'stock_sync_mode' => 'FILE', 'stock_buffer_units' => 2, 'oos_action' => 'ZERO']);
        $shop = fn (string $no, ?int $p) => $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => $no, 'iso_branch' => $no, 'shop_name' => "Shop $no", 'f_active' => 1, 'fk_id_mp_profile' => $p]);
        $a = $shop('94A', $prof); $b = $shop('94B', $prof); $c = $shop('94C', null);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SH-94', 'product_name_vendor' => 'Shared thing', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $loc = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'WH 94', 'kind' => 'WAREHOUSE', 'f_active' => 1, 'f_sellable' => 1]);
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 12, 'location' => $loc, 'unit_cost' => 1]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $l = fn (int $s, string $status, float $price, string $ful = 'FBM') => $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $s, 'fk_id_marketplace' => $mp, 'marketplace_sku' => 'SH-94', 'listing_title' => 'Shared thing', 'status' => $status, 'fulfilment' => $ful, 'price' => $price, 'f_active' => 1, 'pack_qty' => 1, 'f_bundle' => 0]);
        $la = $l($a, 'ACTIVE', 9.99); $lb = $l($b, 'ACTIVE', 10.49); $lc = $l($c, 'INACTIVE', 11.00);
        // the rule: SKU visible on 2 shops, hidden on a third → 2 rows sharing, 1 hidden
        $d = $this->getJson('/api/inventory-sharing?sku=SH-94')->assertOk()->json();
        $this->assertSame(['sharing' => 2, 'hidden' => 1, 'out_of_sync' => 0], $d['summary']); $rows = collect($d['rows'])->keyBy('id');
        $this->assertSame([10, 9.99, 'Shown', 'NEVER', 'FILE'], [$rows[$la]['qty'], $rows[$la]['price'], $rows[$la]['visibility'], $rows[$la]['state'], $rows[$la]['sync']], 'quantity offered = available 12 minus the profile buffer 2');
        $this->assertSame(['Hidden', 0, 'HIDDEN'], [$rows[$lc]['visibility'], $rows[$lc]['qty'], $rows[$lc]['state']]);
        // a failed push shows red "out of sync" with the error and a Push now button
        DB::table('stock_push_queue')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $a, 'fk_id_listing' => $la, 'qty' => 10, 'action' => 'SET', 'via' => 'FILE', 'status' => 'FAILED', 'reason' => 'PUSH_NOW', 'note' => 'Shop refused the file: bad SKU', 'created_at' => now()]);
        DB::table('stock_push_queue')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $b, 'fk_id_listing' => $lb, 'qty' => 10, 'action' => 'SET', 'via' => 'FILE', 'status' => 'EXPORTED', 'reason' => 'PUSH_NOW', 'created_at' => now(), 'done_at' => now()]);
        $o = $this->getJson('/api/inventory-sharing?out_of_sync=1')->assertOk()->json(); $this->assertCount(1, $o['rows']);
        $this->assertSame([$la, 'OUT_OF_SYNC', 'Out of sync – Shop refused the file: bad SKU', true, 'FAILED'], [$o['rows'][0]['id'], $o['rows'][0]['state'], $o['rows'][0]['state_text'], $o['rows'][0]['can_push'], $o['rows'][0]['last_result']]);
        $this->assertSame('IN_SYNC', collect($this->getJson("/api/inventory-sharing?shop=$b")->json('rows'))->firstWhere('id', $lb)['state']);
        // a GET writes nothing; bulk actions
        $n = DB::table('stock_push_queue')->count(); $this->getJson('/api/inventory-sharing'); $this->assertSame($n, DB::table('stock_push_queue')->count());
        $this->postJson('/api/inventory-sharing', ['action' => 'PUSH', 'ids' => []])->assertStatus(422)->assertJsonPath('message', 'Tick at least one row.');
        $r = $this->postJson('/api/inventory-sharing', ['action' => 'PUSH', 'ids' => [$la, $lc]])->assertOk()->json(); $this->assertSame([1, 1], [$r['done'], count($r['skipped'])]);
        $q = DB::table('stock_push_queue')->where('fk_id_listing', $la)->orderByDesc('id')->first(); $this->assertSame([10, 'SET', 'PENDING', 'PUSH_NOW'], [(int) $q->qty, $q->action, $q->status, $q->reason]);
        $this->assertSame('WAITING', collect($this->getJson('/api/inventory-sharing?sku=SH-94')->json('rows'))->firstWhere('id', $la)['state']);
        $this->postJson('/api/inventory-sharing', ['action' => 'HIDE', 'ids' => [$lb]])->assertOk()->assertJsonPath('message', '1 row(s) hidden on the shop.'); $this->assertSame('INACTIVE', DB::table('t_product_listings')->where('id', $lb)->value('status'));
        $this->postJson('/api/inventory-sharing', ['action' => 'SHOW', 'ids' => [$lb, $lc]])->assertOk()->assertJsonPath('done', 2);
        $this->postJson('/api/inventory-sharing', ['action' => 'PRICE', 'ids' => [$lb], 'value' => 0])->assertStatus(422);
        $this->postJson('/api/inventory-sharing', ['action' => 'PRICE', 'ids' => [$lb], 'value' => '12.5'])->assertOk(); $this->assertSame('12.50', DB::table('t_product_listings')->where('id', $lb)->value('price'));
        $this->assertSame(1, DB::table('sys_audit_log')->where('table_name', 't_product_listings')->where('record_id', $lb)->where('reason', 'Inventory sharing')->where('changes_json', 'like', '%12.5%')->count());
        // rights: a VA may look but not change
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson('/api/inventory-sharing', ['action' => 'HIDE', 'ids' => [$la]])->assertStatus(403);
        $this->assertTrue(DB::table('sidebar_modules')->where('url', '#/inventory-sharing')->exists());
    }
}
