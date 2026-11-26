<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 26-11 23:14 – Inventory: states of stock from the ledger, owners, pool / private, listings, the story; a shop-limited person sees only his shops. */
class InventoryHubTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_inventory_lines_story_and_shop_scope(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'InvMp', 'f_active' => 1]);
        $a = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'IA' . substr(uniqid(), -4), 'iso_branch' => 'IA', 'shop_name' => 'Inv Shop A', 'f_active' => 1]);
        $b = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'IB' . substr(uniqid(), -4), 'iso_branch' => 'IB', 'shop_name' => 'Inv Shop B', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'INV-1', 'product_name_vendor' => 'Inventory kettle', 'stock' => 40, 'f_active' => 1, 'e_product_type' => 'private', 'product_img' => 'https://img.example.test/k.jpg']);
        $q = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'INV-2', 'product_name_vendor' => 'Other shop only', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'private']);
        $wh = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Inv WH', 'kind' => 'WAREHOUSE', 'f_active' => 1]);
        $tr = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Inv transit', 'kind' => 'TRANSIT', 'f_active' => 1]);
        $own = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'IP' . random_int(100, 999), 'first_name' => 'Zara', 'sur_name' => 'Partner', 'f_active' => 1]);
        $l1 = $this->f('fin_lots', ['company_id' => 1, 'fk_id_product' => $p, 'sku' => 'INV-1', 'qty' => 30, 'qty_left' => 30, 'unit_cost_gbp' => 2, 'received_date' => now()->subDays(200)->toDateString(), 'visibility' => 'PRIVATE', 'source' => 'MANUAL']);
        $l2 = $this->f('fin_lots', ['company_id' => 1, 'fk_id_product' => $p, 'sku' => 'INV-1', 'qty' => 10, 'qty_left' => 10, 'unit_cost_gbp' => 3, 'received_date' => now()->subDays(5)->toDateString(), 'fk_id_owner' => $own, 'visibility' => 'PRIVATE', 'source' => 'MANUAL']);
        DB::table('fin_lot_balances')->insert([['fk_id_lot' => $l1, 'fk_id_location' => $wh, 'qty' => 30], ['fk_id_lot' => $l2, 'fk_id_location' => $tr, 'qty' => 10]]);
        DB::table('t_product_visibility')->insert([['fk_id_product' => $p, 'fk_id_marketplace' => $mp, 'visibility_mode' => 'private', 'fk_id_branch' => $a], ['fk_id_product' => $q, 'fk_id_marketplace' => $mp, 'visibility_mode' => 'private', 'fk_id_branch' => $b]]);
        $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $a, 'fk_id_marketplace' => $mp, 'marketplace_sku' => 'A-INV-1', 'asin' => 'B0INV1', 'price' => 19.99, 'status' => 'ACTIVE', 'f_active' => 1]);
        $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $a, 'fk_product_id' => $p, 'sku' => 'A-INV-1', 'qty' => 15, 'd_order' => now()->subDays(3)->toDateString(), 'order_no' => 'O-INV']);
        DB::table('fin_fba_stock_snapshots')->insert(['batch_id' => 1, 'branch_id' => $a, 'snapshot_date' => now()->toDateString(), 'sku' => 'A-INV-1', 'fulfillable' => 12, 'inbound_shipped' => 4]);
        $d = $this->getJson('/api/inventory?q=INV-1')->assertOk()->json(); $x = $d['rows'][0];
        $this->assertSame([30, 30, 10, 12, 4], [(int) $x['stock']['warehouse'], (int) $x['stock']['available'], (int) $x['stock']['transit'], $x['stock']['fba'], $x['stock']['fba_inbound']]);
        $this->assertEquals(90, $x['value']); $this->assertSame(['B0INV1'], $x['asin']); $this->assertSame(['Inv Shop A'], $x['private']); $this->assertSame(1, $x['listings']['count']);
        $this->assertSame(15, $x['sold30']); $this->assertSame(84, $x['cover_days'], '(30 available + 12 FBA) ÷ 0.5 a day'); $this->assertGreaterThan(180, $x['age_days']);
        $this->assertEqualsCanonicalizing(['Company', 'Zara Partner'], array_column($x['owners'], 'name'));
        $this->assertSame([$p], array_column($this->getJson('/api/inventory?state=aged&q=INV')->json('rows'), 'id'), 'aged filter');
        $s = $this->getJson("/api/inventory/$p")->assertOk()->json(); $this->assertCount(2, $s['where']); $this->assertSame('Inv Shop A', $s['listings'][0]['shop_name']);
        // a person limited to shop B sees only the product of shop B, and not the story of the other
        $va = $this->makeUser('VA'); \App\Services\Perm::setScope($va->id, 'SHOP', [$b], $this->owner->id); $this->actingAs($va); \App\Services\Perm::flush();
        if (\App\Services\Perm::has($va, 'stock.SEE')) {
            $this->assertSame([$q], array_column($this->getJson('/api/inventory?q=INV')->json('rows'), 'id'));
            $this->getJson("/api/inventory/$p")->assertStatus(403);
        } else $this->getJson('/api/inventory')->assertStatus(403);
    }
}
