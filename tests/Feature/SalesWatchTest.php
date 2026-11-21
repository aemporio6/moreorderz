<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SalesWatch;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Night tasks of the selling side: SAL_NO_COST, LST_STATUS, IMP_WAITING – opened once, closed by themselves. */
class SalesWatchTest extends TestCase
{
    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }
    private function open(string $key): bool { return DB::table('tasks')->where('dedupe_key', $key)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists(); }

    public function test_sale_lines_without_cost_one_task_per_shop_and_month(): void
    {
        $this->actingAs($this->owner); $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1]));
        $line = fn (float $raw, string $src = 'active') => DB::table('t_sales')->insertGetId($this->fill('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'order_no' => uniqid('o'), 'd_order' => '2026-07-05', 'sku' => 'S', 'qty' => 1, 'sale_rate' => 9, 'total_amount' => 9, 'raw_price' => $raw, 'buy_amount' => $raw, 'status' => 'Pending', 'sale_source' => $src]));
        $a = $line(0); $b = $line(0); $line(4); $line(0, 'unmatched');      // a not matched line is not "without cost" – it has its own task
        $this->assertSame(1, SalesWatch::noCost(1, 3, '2026-08-10')); $this->assertSame(0, SalesWatch::noCost(1, 3, '2026-08-11'));
        $this->assertSame('2 sale line(s) of 101A Jul 2026 have no cost', DB::table('tasks')->where('dedupe_key', "SAL_NO_COST:$shop:2026-07")->value('title'));
        DB::table('t_sales')->whereIn('id', [$a, $b])->update(['raw_price' => 3, 'buy_amount' => 3]); SalesWatch::noCost(1, 3, '2026-08-12'); $this->assertFalse($this->open("SAL_NO_COST:$shop:2026-07"));
    }

    public function test_listing_status_against_stock(): void
    {
        $this->actingAs($this->owner); $pid = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'L-1', 'product_name_vendor' => 'Thing', 'f_active' => 1]));
        $id = DB::table('t_product_listings')->insertGetId($this->fill('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $pid, 'marketplace_sku' => 'EB-L1', 'status' => 'ACTIVE', 'fulfilment' => 'FBM', 'f_active' => 1]));
        $this->assertSame(1, SalesWatch::listingStatus(1)); $this->assertSame('Listing EB-L1 is ACTIVE but nothing is available to sell', DB::table('tasks')->where('dedupe_key', "LST_STATUS:$id")->value('title')); $this->assertSame(0, SalesWatch::listingStatus(1));
        $bin = DB::table('cat_inventory_locations')->where('fk_id_ent', 1)->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'BIN'))->value('id') ?: DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['fk_id_ent' => 1, 'location_code' => 'A-01', 'location_name' => 'A-01', 'f_active' => 1, 'f_sellable' => 1]));
        \App\Services\StockService::receive(1, [['product' => $pid, 'qty' => 3, 'unit_cost' => 1, 'location' => $bin]], 'OPENING', null);
        SalesWatch::listingStatus(1); $this->assertFalse($this->open("LST_STATUS:$id"));      // stock arrived → closed by itself
        DB::table('t_product_listings')->where('id', $id)->update(['status' => 'CLOSED', 'last_report_at' => now()]); $this->assertSame(1, SalesWatch::listingStatus(1));
        $this->assertStringContainsString('was set to CLOSED by a marketplace report while 3 unit(s) are available', DB::table('tasks')->where('dedupe_key', "LST_STATUS:$id")->value('title'));
    }

    public function test_import_batch_waiting_longer_than_the_limit(): void
    {
        $this->actingAs($this->owner);
        $id = DB::table('imp_batches')->insertGetId($this->fill('imp_batches', ['batch_code' => 'IMP-T1', 'fk_id_ent' => 1, 'report_type' => 'SALES_ORDERS', 'source' => 'UPLOAD', 'file_name' => 'july.csv', 'status' => 'PREVIEW', 'created_at' => '2026-08-01 08:00:00']));
        $this->assertSame(0, SalesWatch::importWaiting(1, '2026-08-01 20:00:00')); $this->assertSame(1, SalesWatch::importWaiting(1, '2026-08-02 09:00:00'));      // 24 hours by default
        $this->assertStringStartsWith('Import IMP-T1 (july.csv) waits since 01-08-2026 08:00', DB::table('tasks')->where('dedupe_key', "IMP_WAITING:$id")->value('title'));
        DB::table('imp_batches')->where('id', $id)->update(['status' => 'SAVED']); SalesWatch::importWaiting(1, '2026-08-02 10:00:00'); $this->assertFalse($this->open("IMP_WAITING:$id"));
    }
}
