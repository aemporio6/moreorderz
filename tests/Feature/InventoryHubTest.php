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

    /** Inventory (c)(d)(e): FBA is a ledger location per Amazon shop; the FBA report is checked against it and booked; move units and change owner from the inventory. */
    public function test_fba_locations_check_book_and_actions(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'AMAZON-FR', 'f_active' => 1]);
        $fr = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'FR' . substr(uniqid(), -4), 'iso_branch' => 'FR', 'shop_name' => 'Amazon France', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'FBA-1', 'product_name_vendor' => 'FBA mug', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'landing_price' => 2.5]);
        $wh = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Main WH', 'kind' => 'WAREHOUSE', 'f_active' => 1]);
        \App\Services\StockService::adjust(1, $p, $wh, 50, 'opening', ['unit_cost' => 2.5]);
        $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $fr, 'fk_id_marketplace' => $mp, 'marketplace_sku' => 'FR-FBA-1', 'price' => 12, 'status' => 'ACTIVE', 'f_active' => 1]);
        DB::table('fin_fba_stock_snapshots')->insert([['batch_id' => 1, 'branch_id' => $fr, 'snapshot_date' => now()->toDateString(), 'sku' => 'FR-FBA-1', 'fulfillable' => 18, 'reserved' => 2], ['batch_id' => 1, 'branch_id' => $fr, 'snapshot_date' => now()->toDateString(), 'sku' => 'NOT-OURS', 'fulfillable' => 5, 'reserved' => 0]]);
        $c = $this->getJson('/api/inventory/fba')->assertOk()->json();
        $line = collect($c['rows'])->firstWhere('sku', 'FR-FBA-1'); $this->assertSame([20, 0, 20], [$line['report'], $line['ledger'], $line['diff']]);
        $this->assertNull(collect($c['rows'])->firstWhere('sku', 'NOT-OURS')['product']); $this->assertSame(1, $c['totals']['unknown']);
        DB::table('t_product_listings')->where('fk_id_product', $p)->update(['asin' => 'B0FBA00001']);
        DB::table('fin_fba_stock_snapshots')->insert(['batch_id' => 1, 'branch_id' => $fr, 'snapshot_date' => now()->toDateString(), 'sku' => 'B0FBA00001', 'fulfillable' => 0, 'reserved' => 0]);
        $this->assertSame($p, collect($this->getJson('/api/inventory/fba')->json('rows'))->firstWhere('sku', 'B0FBA00001')['product'], 'a report line with the ASIN finds its product');
        DB::table('fin_fba_stock_snapshots')->where('sku', 'B0FBA00001')->delete();
        $q2 = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'NOT-OURS-NOW', 'product_name_vendor' => 'Linked later', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->postJson('/api/inventory/fba/link', ['shop' => $fr, 'sku' => 'NOT-OURS', 'product' => $q2])->assertOk();
        $this->assertSame($q2, collect($this->getJson('/api/inventory/fba')->json('rows'))->firstWhere('sku', 'NOT-OURS')['product'], 'linked once, known from then on');
        $loc = (int) DB::table('cat_inventory_locations')->where('kind', 'FBA')->where('fk_id_branch', $fr)->value('id'); $this->assertGreaterThan(0, $loc, 'the FBA location of the shop');
        // the 20 units were sent from the warehouse and not booked → move them
        $this->postJson('/api/inventory/fba', ['lines' => [$fr . '|FR-FBA-1'], 'from' => $wh])->assertOk()->assertJsonPath('booked', 1);
        $this->assertSame([30, 20], [\App\Services\StockService::balance($p, $wh), \App\Services\StockService::balance($p, $loc)]);
        $this->assertSame(30, \App\Services\StockService::available($p), 'FBA units are not merchant stock');
        $x = collect($this->getJson('/api/inventory?q=FBA-1')->json('rows'))->firstWhere('id', $p); $this->assertSame([20, 'ledger'], [$x['stock']['fba'], $x['stock']['fba_source']]);
        // Amazon now reports 17 → a loss of 3 is booked
        DB::table('fin_fba_stock_snapshots')->insert(['batch_id' => 2, 'branch_id' => $fr, 'snapshot_date' => now()->addDay()->toDateString(), 'sku' => 'FR-FBA-1', 'fulfillable' => 17, 'reserved' => 0]);
        $this->assertSame(-3, collect($this->getJson('/api/inventory/fba')->json('rows'))->firstWhere('sku', 'FR-FBA-1')['diff']);
        $this->postJson('/api/inventory/fba', ['lines' => [$fr . '|FR-FBA-1']])->assertOk(); $this->assertSame(17, \App\Services\StockService::balance($p, $loc));
        // move units and change owner from the inventory
        $o = $this->getJson("/api/inventory/$p/options")->assertOk()->json(); $this->assertTrue($o['can']); $this->assertNotEmpty($o['lots']);
        $tr = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Second WH', 'kind' => 'WAREHOUSE', 'f_active' => 1]);
        $this->postJson("/api/inventory/$p/transfer", ['from' => $wh, 'to' => $tr, 'qty' => 5])->assertOk(); $this->assertSame(5, \App\Services\StockService::balance($p, $tr));
        $this->postJson("/api/inventory/$p/transfer", ['from' => $wh, 'to' => $tr, 'qty' => 999])->assertStatus(422);
        $partner = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OW' . random_int(100, 999), 'first_name' => 'Omar', 'f_active' => 1]);
        $this->postJson("/api/inventory/$p/owner", ['lot' => $o['lots'][0]['id'], 'qty' => 4, 'to' => $partner])->assertOk();
        $this->assertSame(4, (int) DB::table('fin_lots')->where('fk_id_product', $p)->where('fk_id_owner', $partner)->sum('qty_left'));
        $va = $this->makeUser('VA'); $this->actingAs($va); \App\Services\Perm::flush(); $this->postJson("/api/inventory/$p/transfer", ['from' => $wh, 'to' => $tr, 'qty' => 1])->assertStatus(403);
        // (f) a stock person limited to another shop does not see this product in the ledger, the lots or its stock page
        $other = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'OT' . substr(uniqid(), -4), 'iso_branch' => 'OT', 'shop_name' => 'Other shop', 'f_active' => 1]);
        $wk = $this->makeUser('WAREHOUSE'); \App\Services\Perm::setScope($wk->id, 'SHOP', [$other], $this->owner->id); $this->actingAs($wk); \App\Services\Perm::flush();
        if (\App\Services\Perm::has($wk, 'stock.SEE')) {
            $this->assertNotContains($p, array_column($this->getJson('/api/stock/ledger?product=' . $p)->assertOk()->json('rows'), 'product_id'));
            $this->getJson("/api/stock/product/$p")->assertStatus(403);
        }
    }

    /** Next #5: Guide me knows the new screens – unlinked FBA SKUs, shops without quantities, files waiting in Import anything. */
    public function test_guide_me_on_the_new_screens(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $mp = DB::table('cat_marketplaces')->insertGetId(\App\Services\RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AMAZON_GM']));
        $shop = DB::table('t_branches')->insertGetId(\App\Services\RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'GM1', 'iso_branch' => 'GM1', 'shop_name' => 'Shop GM', 'f_active' => 1]));
        \App\Services\FbaLedger::locations(1);
        DB::table('fin_fba_stock_snapshots')->insert(\App\Services\RecordService::fillNotNull('fin_fba_stock_snapshots', ['branch_id' => $shop, 'snapshot_date' => date('Y-m-d'), 'sku' => 'B0GUIDEME1', 'fulfillable' => 4, 'reserved' => 0]));
        $p = DB::table('t_product_vendors')->insertGetId(\App\Services\RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'GM-1', 'product_name_vendor' => 'Guide lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']));
        DB::table('t_product_listings')->insert(\App\Services\RecordService::fillNotNull('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'marketplace_sku' => 'GM-1-M', 'status' => 'ACTIVE', 'f_active' => 1, 'fulfilment' => 'FBM']));
        \Illuminate\Support\Facades\Cache::flush();
        $g = $this->getJson('/api/guide-now?route=' . urlencode('#/inventory'))->assertOk()->json('items');
        $texts = implode(' | ', array_column($g, 'text'));
        $this->assertStringContainsString('FBA SKU(s) / ASIN(s) are not linked', $texts);
        $this->assertStringContainsString('No shop has sent its quantities yet', $texts);
        DB::table('mo_intake')->insert(['fk_id_ent' => 1, 'name' => 'x.pdf', 'status' => 'NEW', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringContainsString('wait to be placed', implode(' | ', array_column($this->getJson('/api/guide-now?route=' . urlencode('#/import'))->json('items'), 'text')));
    }
}
