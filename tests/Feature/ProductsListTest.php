<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductsList;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-35 products list columns, R-D-25 Type chip, R-D-37 Cover status, R-D-38 WAC. */
class ProductsListTest extends TestCase
{
    private int $ebay; private int $amz; private int $bin;

    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->ebay = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayPL', 'f_active' => 1]);
        $this->amz = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmazonPL', 'f_active' => 1]);
        $this->bin = $this->f('cat_inventory_locations', ['location_name' => 'PL-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
    }

    private function product(array $x): int { return $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']); }

    public function test_the_rule_example_pills_and_multiple_owner(): void
    {
        $p = $this->product(['SKU_VENDOR' => 'PL-A', 'product_name_vendor' => 'Lamp shade']);
        $e1 = $this->f('t_enterprise', ['ent_name' => 'Company One', 'iso_ent' => 'PL1', 'f_active' => 1]); $e2 = $this->f('t_enterprise', ['ent_name' => 'Company Two', 'iso_ent' => 'PL2', 'f_active' => 1]);
        $s1 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->ebay, 'shop_no' => 'P1', 'iso_branch' => 'P1', 'shop_name' => 'Shop 1', 'f_active' => 1, 'fk_id_company' => $e1]);
        $s2 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->amz, 'shop_no' => 'P2', 'iso_branch' => 'P2', 'shop_name' => 'Shop 2', 'f_active' => 1, 'fk_id_company' => $e2]);
        DB::table('t_product_visibility')->insert([['fk_id_product' => $p, 'fk_id_marketplace' => $this->ebay, 'visibility_mode' => 'POOL', 'fk_id_branch' => $s1, 'created_at' => now()], ['fk_id_product' => $p, 'fk_id_marketplace' => $this->amz, 'visibility_mode' => 'PRIVATE', 'fk_id_branch' => $s2, 'created_at' => now()]]);
        $d = $this->getJson('/api/products?per=20&q=PL-A')->assertOk()->json(); $r = $d['rows'][0];
        $pills = collect($r['pills'])->keyBy('name');
        $this->assertSame([true, 'POOL', true, 'PRIVATE'], [$pills['eBayPL']['visible'], $pills['eBayPL']['mode'], $pills['AmazonPL']['visible'], $pills['AmazonPL']['mode']], 'eBay (Pool) and Amazon (Private) pills with green dot');
        $this->assertFalse(collect($r['pills'])->where('visible', true)->contains(fn ($x) => ! in_array($x['name'], ['eBayPL', 'AmazonPL'], true)), 'others grey');
        $this->assertSame(['Multiple', ['Company One', 'Company Two']], [$r['owner'], $r['owners']], 'Owner "Multiple" when its shops belong to 2 companies');
        $this->assertSame("PL-A · MP-$p", $r['sku']);
        $this->assertSame('none', $r['listings'], '"none" when 0 real listings');
        // R-D-25: no investors → Pool on some marketplace → CENTRAL; with the Amazon chip → PRIVATE; investors A and B → JOINT
        $this->assertSame('CENTRAL', $r['type']);
        $this->assertSame('PRIVATE', $this->getJson("/api/products?per=20&q=PL-A&mp={$this->amz}")->json('rows.0.type'));
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'A', 'f_active' => 1]); $b = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'B', 'f_active' => 1]);
        DB::table('t_product_investors')->insert([['fk_id_product' => $p, 'fk_id_account' => $a, 'created_at' => now()], ['fk_id_product' => $p, 'fk_id_account' => $b, 'created_at' => now()]]);
        $this->assertSame('JOINT', $this->getJson('/api/products?per=20&q=PL-A')->json('rows.0.type'));
    }

    public function test_rows_parents_and_alone_colours_name_cut_cover_wac_pager_and_view(): void
    {
        $long = str_repeat('Very long product name ', 5);
        $parent = $this->product(['SKU_VENDOR' => 'PL-P', 'product_name_vendor' => $long, 'f_parent' => 1]);
        $this->product(['SKU_VENDOR' => 'PL-P-RED', 'product_name_vendor' => 'Red', 'f_id_parent' => $parent]); $this->product(['SKU_VENDOR' => 'PL-P-BLUE', 'product_name_vendor' => 'Blue', 'f_id_parent' => $parent]);
        $alone = $this->product(['SKU_VENDOR' => 'PL-B', 'product_name_vendor' => 'Alone one', 'min_level' => 5]);
        $oos = $this->product(['SKU_VENDOR' => 'PL-C', 'product_name_vendor' => 'Manual OOS', 'f_out_of_stock' => 1]);
        $off = $this->product(['SKU_VENDOR' => 'PL-D', 'product_name_vendor' => 'Switched off', 'f_active' => 0]);
        $rows = collect($this->getJson('/api/products?per=100&status=all&q=PL-')->assertOk()->json('rows'))->keyBy('id');
        $this->assertSame([$off, $oos, $alone, $parent], $rows->keys()->all(), 'parents and alone only, id DESC (children inside the parent)');
        $this->assertSame(['— 2', '#15a985'], [$rows[$parent]['suffix'], $rows[$parent]['colour']]);
        $this->assertSame(mb_substr($long, 0, 72) . '....', $rows[$parent]['name'], 'name cut at 72 + "...."');
        $this->assertSame(['#f0c902', '#e45573', '#c1318b'], [$rows[$alone]['colour'], $rows[$oos]['colour'], $rows[$off]['colour']], 'alone / manual OOS / inactive (inactive is reached now)');
        // R-D-37: stock 3, min 5, no sales → Urgent (min-level rule)
        Stock::receive(1, [['product' => $alone, 'qty' => 3, 'location' => $this->bin, 'unit_cost' => 2.0]], 'GRN', 1);
        $r = collect($this->getJson('/api/products?per=100&q=PL-B')->json('rows'))->first();
        $this->assertSame(['Urgent', 'min-level rule', 3], [$r['cover'], $r['cover_basis'], $r['qty']]);
        $this->assertSame('Critical', collect($this->getJson('/api/products?per=100&q=PL-C')->json('rows'))->first()['cover']);
        // R-D-38: lot A 10 × £2 + £5 shipping (landed 2.50), lot B 10 × £3 → WAC £2.75
        $w = $this->product(['SKU_VENDOR' => 'PL-W', 'product_name_vendor' => 'Wac']);
        Stock::receive(1, [['product' => $w, 'qty' => 10, 'location' => $this->bin, 'unit_cost' => 2.5], ['product' => $w, 'qty' => 10, 'location' => $this->bin, 'unit_cost' => 3.0]], 'GRN', 2);
        $r = collect($this->getJson('/api/products?per=100&q=PL-W')->json('rows'))->first();
        $this->assertSame([2.75, 'WAC = £55.00 / 20'], [$r['wac'], $r['wac_tip']]);
        // pager, footer, page sizes, default view
        $d = $this->getJson('/api/products?per=10&status=all&q=PL-&page=1')->json();
        $this->assertSame(['Showing 1–5 of 5 · Page 1 of 1', [10, 20, 50, 100]], [$d['footer'], $d['sizes']]);
        $this->postJson('/api/products/view', ['per' => 50, 'set' => 'pricing', 'status' => 'all', 'hidden' => ['image', 'nope']])->assertOk();
        $v = $this->getJson('/api/products')->assertOk()->json();
        $this->assertSame([50, 'pricing', ['image']], [$v['per'], $v['view']['set'], $v['view']['hidden']]);
        $this->assertTrue($v['pricing_needs_mp']);
        $pr = $this->getJson("/api/products?per=20&set=pricing&mp={$this->ebay}&q=PL-W")->json('rows.0');
        $this->assertArrayHasKey('margin', $pr); $this->assertArrayHasKey('description', $pr);
        // exports with the current search
        $csv = $this->get('/api/products/export/csv?status=all&q=PL-B')->assertOk();
        $this->assertStringContainsString('Alone one', $csv->getContent()); $this->assertStringNotContainsString('Manual OOS', $csv->getContent());
        $this->get('/api/products/export/xlsx?q=PL-')->assertOk(); $this->assertStringStartsWith('%PDF', $this->get('/api/products/export/pdf?q=PL-')->assertOk()->getContent());
        $this->assertSame(4, DB::table('sys_field_options')->where('list_code', 'PRODUCT_ROW_STATUS')->count());
    }
    /** R-D-36 the filter bar. */
    public function test_d36_search_mp_id_mode_chips_and_the_other_filters(): void
    {
        $type = $this->f('cat_product_type', ['fk_id_ent' => 1, 'product_type_name' => 'Garden 36', 'f_active' => 1]); $brand = $this->f('cat_brands', ['fk_id_ent' => 1, 'brand_name' => 'Brand 36', 'f_active' => 1]);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S36', 'supplier_name' => 'Supplier 36', 'f_active' => 1]); $co = $this->f('t_enterprise', ['ent_name' => 'Owner Co 36', 'iso_ent' => 'O36', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->ebay, 'shop_no' => 'F36', 'iso_branch' => 'F36', 'shop_name' => 'Shop 36', 'f_active' => 1, 'fk_id_company' => $co]);
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier 36', 'f_active' => 1]); $pre = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'P36', 'fk_id_carrier' => $car, 'f_active' => 1]);
        $a = $this->product(['SKU_VENDOR' => 'F36-A', 'product_name_vendor' => 'Garden Hose', 'upc' => 'UPC36A', 'ean' => '5036000000011', 'fk_id_product_type' => $type, 'fk_id_brand' => $brand, 'fk_id_vendor_inventory' => $sup, 'f_tag2' => 1, 'stock' => 5]);
        $b = $this->product(['SKU_VENDOR' => 'F36-B', 'product_name_vendor' => 'Garden Rake', 'upc' => 'B0ASIN36', 'ean' => '5036000000028', 'stock' => 0]);
        DB::table('t_product_visibility')->insert([['fk_id_product' => $a, 'fk_id_marketplace' => $this->ebay, 'visibility_mode' => 'private', 'fk_id_branch' => $shop, 'created_at' => now()], ['fk_id_product' => $b, 'fk_id_marketplace' => $this->ebay, 'visibility_mode' => 'pool', 'fk_id_branch' => 0, 'created_at' => now()],
            ['fk_id_product' => $b, 'fk_id_marketplace' => $this->amz, 'visibility_mode' => 'private', 'fk_id_branch' => 0, 'created_at' => now()]]);
        DB::table('t_product_marketplace_shipping')->insert(RecordService::fillNotNull('t_product_marketplace_shipping', ['fk_id_product' => $b, 'fk_id_marketplace' => $this->amz, 'fk_id_car_pkg_svc' => $pre]));
        $ids = fn (string $qs) => array_column($this->getJson('/api/products?per=50&status=all&' . $qs)->assertOk()->json('rows'), 'id');
        // the rule: search "mp-12" returns product id 12; any letter case; UPC is searched
        $this->assertSame([$a], $ids('q=mp-' . $a)); $this->assertSame([$a], $ids('q=MP-' . $a)); $this->assertSame([$a], $ids('q=garden hose')); $this->assertSame([$a], $ids('q=upc36a'));
        // the rule: chip eBay + Private shows only products whose eBay visibility is PRIVATE; without a marketplace chip: any marketplace
        $this->assertSame([$a], $ids("q=F36-&mp={$this->ebay}&mode=PRIVATE")); $this->assertSame([$b], $ids("q=F36-&mp={$this->ebay}&mode=POOL")); $this->assertEqualsCanonicalizing([$a, $b], $ids('q=F36-&mode=PRIVATE'));
        $this->assertSame([$a], $ids("q=F36-&category=$type")); $this->assertSame([$a], $ids("q=F36-&brand=$brand")); $this->assertSame([$a], $ids("q=F36-&supplier=$sup")); $this->assertSame([$a], $ids('q=F36-&tag=2'));
        $this->assertSame([$b], $ids('q=F36-&ean=5036000000028')); $this->assertSame([], $ids('q=F36-&ean=50360000000')); $this->assertSame([$b], $ids('q=F36-&asin=B0ASIN36'));
        $this->assertSame([$b], $ids("q=F36-&carrier=$car")); $this->assertEqualsCanonicalizing([$a, $b], $ids("q=F36-&company=$co"), 'A is private in a shop of the company, B is pool on its marketplace'); $this->assertSame([$b], $ids('q=F36-&oos=1'));
        $this->assertSame([], $ids("q=F36-&category=$type&tag=1"), 'filters add up');
        $d = $this->getJson("/api/products?per=20&q=F36-&brand=$brand")->json(); $this->assertSame(['brand' => (string) $brand], $d['filters']); $this->assertContains('Brand 36', array_column($d['filter_lists']['brand'], 'name')); $this->assertContains('Supplier 36', array_column($d['filter_lists']['supplier'], 'name'));
    }
    /** R-D-44 locations of a product: ledger units, full-path label, note, badge, warning for an inactive location. */
    public function test_d44_locations_with_path_note_badge_and_inactive_warning(): void
    {
        $type = $this->f('cat_location_types', ['type_name' => 'Shelf', 'f_active' => 1]);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Warehouse 1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $sh = $this->f('cat_inventory_locations', ['location_name' => 'Shelf 2', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'fk_id_parent' => $wh, 'fk_id_location_type' => $type]);
        $p = $this->product(['SKU_VENDOR' => 'LOC-44', 'product_name_vendor' => 'Located']);
        // the rule: 60 units at "Warehouse 1 › Shelf 2 (Shelf)" → row shows 60 and the label with path
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 60, 'location' => $sh, 'unit_cost' => 1]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $d = $this->getJson("/api/stock/product/$p")->assertOk()->json();
        $this->assertSame([['id' => $sh, 'label' => 'Warehouse 1 › Shelf 2 (Shelf)', 'qty' => 60, 'note' => '', 'inactive' => false]], $d['locations']); $this->assertSame(['Placed 60 of stock 60', null], [$d['placed_text'], $d['location_warning']]);
        $this->postJson("/api/stock/product/$p/location-note", ['location' => $sh, 'note' => 'top shelf, left'])->assertOk(); $this->postJson("/api/stock/product/$p/location-note", ['location' => $sh, 'note' => 'top shelf, right'])->assertOk();
        $this->assertSame(1, DB::table('t_product_locations')->where('fk_id_product', $p)->count()); $this->assertSame(60, (int) \App\Services\StockService::balance($p), 'a note never changes the units');
        DB::table('cat_inventory_locations')->where('id', $sh)->update(['f_active' => 0]); $d2 = $this->getJson("/api/stock/product/$p")->json();
        $this->assertSame(['top shelf, right', true], [$d2['locations'][0]['note'], $d2['locations'][0]['inactive']]); $this->assertStringContainsString('Stock sits on a location that is switched off: Warehouse 1 › Shelf 2 (Shelf)', $d2['location_warning']);
        // old typed quantities: placed only where they reconcile, the rest goes to the review list
        DB::table('cat_inventory_locations')->where('id', $sh)->update(['f_active' => 1]); $un = \App\Services\StockService::systemLocation(1, 'UNASSIGNED');
        $q1 = $this->product(['SKU_VENDOR' => 'OLDQ-1', 'product_name_vendor' => 'Old one']); $q2 = $this->product(['SKU_VENDOR' => 'OLDQ-2', 'product_name_vendor' => 'Old two']);
        foreach ([$q1 => 8, $q2 => 5] as $pid => $n) \App\Services\StockService::receive(1, [['product' => $pid, 'qty' => $n, 'location' => $un, 'unit_cost' => 1]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->f('t_product_locations', ['fk_id_product' => $q1, 'fk_id_location' => $sh, 'qty' => 8, 'notes' => 'bay 3']); $this->f('t_product_locations', ['fk_id_product' => $q2, 'fk_id_location' => $sh, 'qty' => 7]);
        $dry = $this->getJson('/api/stock/old-location-qty')->assertOk()->json(); $this->assertSame([false, 1, 'typed 7, stock 5'], [$dry['applied'], $dry['ready'], collect($dry['review'])->firstWhere('sku', 'OLDQ-2')['reason']]);
        $this->assertSame(8, (int) \App\Services\StockService::balance($q1, $un), 'a dry run moves nothing');
        $this->postJson('/api/stock/old-location-qty')->assertOk()->assertJsonPath('applied', true);
        $this->assertSame([0, 8, 5], [(int) \App\Services\StockService::balance($q1, $un), (int) \App\Services\StockService::balance($q1, $sh), (int) \App\Services\StockService::balance($q2, $un)]);
        $this->assertSame(['0', 'bay 3'], [(string) (int) DB::table('t_product_locations')->where('fk_id_product', $q1)->value('qty'), DB::table('t_product_locations')->where('fk_id_product', $q1)->value('notes')]);
        $this->assertSame(0, $this->postJson('/api/stock/old-location-qty')->json('ready'), 'a second run places nothing');
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/stock/product/$p/location-note", ['location' => $sh, 'note' => 'x'])->assertStatus(403);
    }
}
