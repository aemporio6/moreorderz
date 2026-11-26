<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceReports;
use App\Services\ListingsImport;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-19 bundle components, R-D-59 eBay active-listings import, R-FIN-22 the Amazon side reports – the spec tests. */
class ListingsMoneyFilesTest extends TestCase
{
    private int $shop; private int $mp; private int $bin;
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function p(string $sku): int { return $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => $sku, 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']); }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->bin = $this->f('cat_inventory_locations', ['location_name' => 'B-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101', 'iso_branch' => 'S101', 'shop_name' => 'Shop 101', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
    }

    public function test_h19_bundle_reserves_each_component_and_costs_the_sum(): void
    {
        $this->actingAs($this->owner);
        $a = $this->p('CUP-A'); $b = $this->p('LID-B');
        Stock::receive(1, [['product' => $a, 'qty' => 5, 'location' => $this->bin, 'unit_cost' => 3.00, 'received_date' => '2026-08-01'], ['product' => $b, 'qty' => 5, 'location' => $this->bin, 'unit_cost' => 1.50, 'received_date' => '2026-08-01']], 'GRN', 1);
        PriceService::set($a, $this->mp, 3, 9.99, '2026-08-01');
        $l = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $a, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'marketplace_sku' => 'CUP+LID', 'pack_qty' => 1, 'f_bundle' => 1, 'status' => 'ACTIVE', 'f_active' => 1]);
        DB::table('t_listing_components')->insert([['fk_id_listing' => $l, 'fk_id_product' => $a, 'qty_per_unit' => 1, 'f_active' => 1], ['fk_id_listing' => $l, 'fk_id_product' => $b, 'qty_per_unit' => 1, 'f_active' => 1]]);
        $id = Sales::intake($this->shop, [['order_no' => 'B-1', 'd_order' => '2026-08-10', 'sku' => 'CUP+LID', 'qty' => 1, 'sale_rate' => 9.99]])['ids'][0];
        $r = DB::table('wh_reservations')->where('fk_id_sale', $id)->orderBy('fk_id_product')->get(['fk_id_product', 'qty'])->map(fn ($x) => [(int) $x->fk_id_product, (int) $x->qty])->all();
        $this->assertSame([[$a, 1], [$b, 1]], $r, 'bundle "A+B" (1 × A, 1 × B) ordered × 1 → reservations 1 A and 1 B');
        $this->assertSame([4, 4], [Stock::available($a), Stock::available($b)]);
        Sales::confirmMonth($this->shop, '2026-08', true);      /* book as shipped now */
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(4.50, (float) $s->cost_gbp, 'Confirm cost = cost A + cost B');
        $this->assertSame([4, 4], [(int) DB::table('fin_lots')->where('fk_id_product', $a)->sum('qty_left'), (int) DB::table('fin_lots')->where('fk_id_product', $b)->sum('qty_left')]);
    }

    private function listingFile(array $rows): string
    {
        return "Item number,Title,Variation details,Custom label (SKU),Current price\n" . implode("\n", array_map(fn ($r) => implode(',', $r), $rows)) . "\n";
    }

    public function test_d59_listings_diff_and_undo(): void
    {
        $a = $this->p('SKU-A'); $b = $this->p('SKU-B'); $c = $this->p('SKU-C');
        foreach ([[$a, 'SKU-A', 'Old A'], [$b, 'SKU-B', 'Title B']] as [$p, $sku, $t]) $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'marketplace_sku' => $sku, 'listing_title' => $t, 'status' => 'ACTIVE', 'f_active' => 1]);
        try { ListingsImport::import('999_products.csv', $this->listingFile([['1', 'x', '', 'SKU-B', '1']]), $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Kindly set valid File Name, Valid Shop No not found', $e->getMessage()); }
        try { ListingsImport::import('101_products.csv', "Title,Price\nx,1\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Column [Custom label (sku)] missing', $e->getMessage()); }
        $va = User::create(['login_id' => 'va' . uniqid() . '@t', 'password_hash' => bcrypt('x-password-1'), 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        try { ListingsImport::import('101_products.csv', $this->listingFile([['1', 'x', '', 'SKU-B', '1']]), $va); $this->fail('not the seller'); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Refused', $e->getMessage()); }
        $r = ListingsImport::import('101_products.csv', $this->listingFile([['111', 'Title B', '', 'SKU-B', '5.00'], ['112', 'Cup', 'Blue', 'SKU-C', '7.00'], ['113', 'Ghost', '', 'NOT-A-SKU', '1']]), $this->owner);
        $st = fn ($sku) => DB::table('t_product_listings')->where('fk_id_branch', $this->shop)->where('marketplace_sku', $sku)->value('status');
        $this->assertSame(['CLOSED', 'ACTIVE', 'ACTIVE'], [$st('SKU-A'), $st('SKU-B'), $st('SKU-C')], 'shop 101 had A, B listed; file has B, C → A ENDED, C created, B kept');
        $this->assertSame([1, 1, 0, ['NOT-A-SKU']], [$r['new'], $r['ended'], $r['changed'], $r['unknown']]);
        $this->assertSame('Cup Blue', DB::table('t_product_listings')->where('marketplace_sku', 'SKU-C')->value('listing_title'), 'title + variation details');
        ListingsImport::undo($r['job'], $this->owner);
        $this->assertSame(['ACTIVE', null], [$st('SKU-A'), $st('SKU-C')], 'undo puts A back and removes C');
    }

    public function test_fin22_reports_dedupe_and_required(): void
    {
        $ads = "Date,Campaign Name,Campaign Id,Advertised SKU,Spend,7 Day Total Sales\n2026-10-01,Mugs,C-1,MUG1,£3.20,£20.00\n2026-10-02,Mugs,C-1,MUG1,2.10,0\n";
        $r = FinanceReports::import(1, $this->shop, 'ADS', 'ads.csv', $ads, $this->owner);
        $this->assertSame([2, 0], [$r['new'], $r['duplicates']]);
        $r2 = FinanceReports::import(1, $this->shop, 'ADS', 'ads.csv', $ads, $this->owner);
        $this->assertSame([0, 2], [$r2['new'], $r2['duplicates']], 'same file twice → 0 new rows, all duplicates');
        $this->assertStringStartsWith('This exact file was imported on', (string) $r2['same_file']);
        $st = "month-of-charge\tasin\tfnsku\testimated-monthly-storage-fee\nOct 2026\tB00TEST\tX001\t1.25\n";
        FinanceReports::import(1, $this->shop, 'STORAGE', 'st.txt', $st, $this->owner);
        $this->assertSame('2026-10', DB::table('fin_storage_lines')->where('asin', 'B00TEST')->value('month'), '"Mon YYYY" read as YYYY-MM; tab separated');
        FinanceReports::import(1, $this->shop, 'FBA_STOCK', 'inv.csv', "sku,afn-fulfillable-quantity\nMUG1,12\n", $this->owner);
        $this->assertSame(date('Y-m-d'), DB::table('fin_fba_stock_snapshots')->where('sku', 'MUG1')->value('snapshot_date'), 'snapshot date empty → today');
        FinanceReports::import(1, $this->shop, 'RETURNS', 'ret.csv', "return-date,order-id,sku,quantity\n2026-10-03,206-1,MUG1,0\n", $this->owner);
        $this->assertSame(1, (int) DB::table('fin_returns_lines')->where('order_id', '206-1')->value('quantity'), 'qty at least 1');
        try { FinanceReports::import(1, $this->shop, 'REIMB', 'r.csv', "sku,amount-total\nX,1\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('These required columns are not mapped: reimbursement_id.', $e->getMessage()); }
        $this->assertContains('ADS', array_column(FinanceReports::boardRows(1), 'type'), 'feeds the report status board');
    }

    public function test_h20_quantity_per_listing(): void
    {
        $a = $this->p('Q-A'); $b = $this->p('Q-B');
        Stock::receive(1, [['product' => $a, 'qty' => 9, 'location' => $this->bin, 'unit_cost' => 1, 'received_date' => '2026-08-01'], ['product' => $b, 'qty' => 4, 'location' => $this->bin, 'unit_cost' => 1, 'received_date' => '2026-08-01']], 'GRN', 9);
        $prof = $this->f('mp_profiles', ['fk_id_ent' => 1, 'code' => 'P' . uniqid(), 'name' => 'Test profile', 'stock_sync_mode' => 'FILE', 'stock_buffer_units' => 1, 'oos_action' => 'END', 'f_active' => 1]);
        DB::table('t_branches')->where('id', $this->shop)->update(['fk_id_mp_profile' => $prof]);
        $two = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $a, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'marketplace_sku' => 'QA-2PK', 'pack_qty' => 2, 'status' => 'ACTIVE', 'f_active' => 1]);
        $bun = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $a, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'marketplace_sku' => 'QA+QB', 'pack_qty' => 1, 'f_bundle' => 1, 'status' => 'ACTIVE', 'f_active' => 1]);
        DB::table('t_listing_components')->insert([['fk_id_listing' => $bun, 'fk_id_product' => $a, 'qty_per_unit' => 1, 'f_active' => 1], ['fk_id_listing' => $bun, 'fk_id_product' => $b, 'qty_per_unit' => 2, 'f_active' => 1]]);
        $q = collect(\App\Services\ListingQty::rows(1, $this->shop)['rows'])->keyBy('sku');
        $this->assertSame(4, $q['QA-2PK']['qty'], 'available 9, buffer 1, pack 2 → push 4');
        $this->assertSame(1, $q['QA+QB']['qty'], 'bundle = min over components: A (9−1)/1 = 8, B (4−1)/2 = 1');
        DB::table('t_product_listings')->where('id', $two)->update(['stock_buffer_override' => 8]);
        $q = collect(\App\Services\ListingQty::rows(1, $this->shop)['rows'])->keyBy('sku');
        $this->assertSame([0, 'END'], [$q['QA-2PK']['qty'], $q['QA-2PK']['action']], 'available 1 above the buffer → push 0; the profile ends the listing');
        $this->assertStringContainsString('"QA-2PK","","0","END"', \App\Services\ListingQty::csv(1, $this->shop));
        /* R-H-20 rule example 2: available 1 → push 0 at once and event PRODUCT_OOS (not at the next schedule) */
        DB::table('t_product_listings')->where('id', $two)->update(['stock_buffer_override' => null]);
        $bin2 = $this->bin; \App\Services\Context::set(1, null);
        Stock::adjust(1, $a, $bin2, -9, 'Damage');
        \App\Services\ProductStockFlag::apply($a);
        $this->assertSame(1, DB::table('sys_events')->where('event_code', 'PRODUCT_OOS')->where('entity_id', $a)->count());
        \App\Services\EventBus::runQueue();      /* in production the event runs after the commit; the test transaction holds it */
        $rows = DB::table('stock_push_queue')->where('fk_id_branch', $this->shop)->orderBy('id')->get();
        $this->assertSame([[$two, 0, 'END', 'FILE', 'PENDING'], [$bun, 0, 'END', 'FILE', 'PENDING']], $rows->map(fn ($r) => [(int) $r->fk_id_listing, (int) $r->qty, $r->action, $r->via, $r->status])->all(), 'both listings that use the product get their 0 at once');
        $this->assertTrue(\App\Services\TaskEngine::isOpen('LIST_OOS_PUSH', [$this->shop]));
        $this->assertSame(0, \App\Services\ListingQty::pushZero($a), 'never queued twice while waiting');
        $preview = \App\Services\ListingQty::zeroCsv(1, $this->shop, true);
        $this->assertSame(2, DB::table('stock_push_queue')->where('status', 'PENDING')->count(), 'the preview writes nothing');
        $this->assertStringContainsString('"QA-2PK","","0","END"', \App\Services\ListingQty::zeroCsv(1, $this->shop));
        $this->assertSame([0, false], [DB::table('stock_push_queue')->where('status', 'PENDING')->count(), \App\Services\TaskEngine::isOpen('LIST_OOS_PUSH', [$this->shop])]);
    }

    /** Inventory (d) rest: the listings report brings the quantity each shop shows; the Shop quantity check compares it with what we can send. */
    public function test_shop_quantity_check(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $a = $this->p('SQ-A'); $b = $this->p('SQ-B'); $c = $this->p('SQ-C');
        Stock::adjust(1, $a, $this->bin, 10, 'opening', ['unit_cost' => 1]); Stock::adjust(1, $c, $this->bin, 5, 'opening', ['unit_cost' => 1]);
        $file = "Item number,Title,Custom label (SKU),Current price,Available quantity\n1,A,SQ-A,2,12\n2,B,SQ-B,2,0\n3,C,SQ-C,2,2\n";
        ListingsImport::import('101_active.csv', $file, $this->owner);
        $this->assertSame(12, (int) DB::table('t_product_listings')->where('marketplace_sku', 'SQ-A')->value('listed_qty'));
        $d = $this->getJson('/api/inventory/shop-check?shop=' . $this->shop)->assertOk()->json();
        $st = array_column($d['rows'], 'state', 'sku');
        $this->assertEquals(['SQ-A' => 'OVERSELL', 'SQ-B' => 'MATCH', 'SQ-C' => 'SHORT'], array_intersect_key($st, array_flip(['SQ-A', 'SQ-B', 'SQ-C'])));
        $row = collect($d['rows'])->firstWhere('sku', 'SQ-A'); $this->assertSame([12, 10, 2], [$row['shown'], $row['should'], $row['diff']]);
        $this->assertSame('OVERSELL', $d['rows'][0]['state'], 'what can hurt comes first');
        $this->assertSame(1, $this->getJson('/api/inventory/shop-check?state=SHORT&shop=' . $this->shop)->json('count'));
        // a file without a quantity column keeps the last quantity
        ListingsImport::import('101_active2.csv', "Item number,Title,Custom label (SKU),Current price\n1,A,SQ-A,2\n2,B,SQ-B,2\n3,C,SQ-C,2\n", $this->owner);
        $this->assertSame(12, (int) DB::table('t_product_listings')->where('marketplace_sku', 'SQ-A')->value('listed_qty'));
    }
}
