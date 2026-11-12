<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Ledger;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\ReportService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every report of the catalogue runs for the Owner, and the stock valuation at today equals the books. */
class ReportCatalogueTest extends TestCase
{
    public function test_every_report_runs_and_the_valuation_today_equals_the_ledger(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 30, 'location' => $bin, 'unit_cost' => 2.00, 'received_date' => date('Y-m-d', strtotime('-200 day'))]], 'GRN', 1);
        PriceService::set($p, $mp, 8, 5.00, '2026-01-01');
        $m1 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        Sales::intake($shop, [['order_no' => 'O-1', 'd_order' => $m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 4, 'sale_rate' => 5]]); Sales::confirmMonth($shop, $m1, true);
        $from = date('Y-01-01', strtotime('-1 year')); $to = date('Y-m-d');
        foreach (ReportService::catalogue('OWNER') as $r) {
            $x = ReportService::run(1, $r['key'], $from, $to, 'OWNER');
            $this->assertIsArray($x['rows'], $r['key']); $this->assertNotEmpty($x['columns'], $r['key']);
        }
        $this->assertCount(25, ReportService::catalogue('OWNER'));
        $v = ReportService::run(1, 'valuation', $from, $to, 'OWNER');
        $this->assertSame([26.0, 52.0], [(float) $v['totals']['units'], $v['totals']['value']]);
        $this->assertSame(Ledger::balance(1, '1200'), $v['totals']['value'], 'valuation at today = stock in the books');
        $this->assertEquals(30, ReportService::run(1, 'valuation', $from, date('Y-m-d', strtotime('-100 day')), 'OWNER')['totals']['units'], 'before the sale all 30 were there');
        $sd = ReportService::run(1, 'slow_dead', $from, $to, 'OWNER');
        $this->assertSame([], $sd['rows'], 'sold last month: not slow');
        $l = ReportService::run(1, 'ledger', $from, $to, 'OWNER');
        $this->assertSame($l['totals']['debit'], $l['totals']['credit']);
        $this->assertNotContains('value', array_column(ReportService::run(1, 'valuation', $from, $to, 'WAREHOUSE')['columns'], 'key'), 'no money for the warehouse');
        $this->assertFalse(ReportService::allowed('ledger', 'WAREHOUSE'));
    }
}
