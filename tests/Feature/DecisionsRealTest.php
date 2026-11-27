<?php

namespace Tests\Feature;

use App\Services\CashForecast;
use App\Services\Decisions;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ROADMAP 10: the decision engine on real-shaped data – REBALANCE_FBA (FBA cover < 21 days, warehouse > 60 days) and CAPITAL_CALL (cash low point vs ICC orders due). */
class DecisionsRealTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_rebalance_fba_from_a_snapshot_sales_and_warehouse_stock(): void
    {
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'RB1', 'shop_name' => 'Rebalance shop', 'f_active' => 1]);
        $sku = 'RB-' . uniqid(); $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => 'Dog lead', 'f_active' => 1]);
        $loc = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Main shelf RB', 'kind' => 'WAREHOUSE', 'f_sellable' => 1, 'f_active' => 1]);
        $lot = $this->f('fin_lots', ['company_id' => 1, 'sku' => $sku, 'fk_id_product' => $p, 'source' => 'GRN', 'received_date' => '2026-10-01', 'qty' => 120, 'qty_left' => 120, 'unit_cost_gbp' => 2, 'created_at' => now()]);
        DB::table('fin_lot_balances')->insert(['fk_id_lot' => $lot, 'fk_id_location' => $loc, 'qty' => 120, 'updated_at' => now()]);
        DB::table('fin_fba_stock_snapshots')->insert(['batch_id' => 0, 'branch_id' => $shop, 'snapshot_date' => date('Y-m-d', strtotime('-1 day')), 'sku' => $sku, 'fulfillable' => 10, 'reserved' => 0, 'inbound_working' => 0, 'inbound_shipped' => 0, 'inbound_receiving' => 0, 'unfulfillable' => 0]);
        for ($i = 1; $i <= 3; $i++) $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'fk_product_id' => $p, 'sku' => $sku, 'order_no' => "RB-O$i", 'd_order' => date('Y-m-d', strtotime("-$i days")), 'qty' => 10, 'total_amount' => 100, 'status' => 'Confirmed', 'sale_source' => 'active']);
        DB::table('icc_inputs')->insert(RecordService::fillNotNull('icc_inputs', ['fk_id_product' => $p, 'fk_id_enterprise' => 1, 'margin_unit' => 4]));
        $d = collect(Decisions::all(1, 'REBALANCE_FBA')['items'])->firstWhere('title', "Send 45 × $sku to FBA (Rebalance shop)");
        $this->assertNotNull($d, 'sold 30 in 30 days = 1 a day; FBA 10 days, warehouse 120 days → send min(120 − 30, 45) = 45');
        $this->assertEquals(44.0, $d['amount'], '11 sales days lost (21 − 10) × 1 a day × margin 4');
        $this->assertStringContainsString('FBA has 10 days of stock, the warehouse 120 days', $d['why']);
        DB::table('fin_fba_stock_snapshots')->insert(['batch_id' => 0, 'branch_id' => $shop, 'snapshot_date' => date('Y-m-d'), 'sku' => $sku, 'fulfillable' => 40, 'reserved' => 0, 'inbound_working' => 0, 'inbound_shipped' => 0, 'inbound_receiving' => 0, 'unfulfillable' => 0]);
        $this->assertNull(collect(Decisions::all(1, 'REBALANCE_FBA')['items'])->first(fn ($x) => str_contains($x['title'], $sku)), 'the latest snapshot has 40 days: no decision');
    }

    public function test_capital_call_when_orders_due_exceed_the_cash_low_point(): void
    {
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CC-' . uniqid(), 'product_name_vendor' => 'Big order', 'f_active' => 1]);
        $r = ['fk_id_product' => $p, 'run_at' => now(), 'status' => 'RED', 'order_by' => date('Y-m-d', strtotime('+10 days')), 'goods' => 900000];
        DB::table('icc_results')->insert(RecordService::fillNotNull('icc_results', $r));
        $f = CashForecast::build(1, 13); $low = $f['rows'] ? min(array_column($f['rows'], 'balance')) : (float) $f['bank_now'];
        $icc = 0.0; foreach (DB::table('icc_results')->whereIn('id', DB::table('icc_results')->selectRaw('MAX(id)')->groupBy('fk_id_product'))->get() as $x) if (in_array($x->status, ['RED', 'YELLOW', 'GREEN'], true) && $x->order_by && $x->order_by <= date('Y-m-d', strtotime('+90 days')) && DB::table('t_product_vendors')->where('id', $x->fk_id_product)->where('fk_id_ent', 1)->where('f_active', 1)->exists()) $icc += (float) $x->goods;
        $c = Decisions::all(1, 'CAPITAL_CALL')['items'][0] ?? null;
        $this->assertNotNull($c, 'an order of 900,000 due in 10 days is more than the cash');
        $this->assertEquals(round((float) ($f['alert_limit'] ?? 0) + $icc - $low, 2), $c['amount'], 'shortfall = keep-at-least + ICC orders due in 90 days − lowest cash of 13 weeks');
        $this->assertStringContainsString('ICC orders due in 90 days', $c['why']); $this->assertSame('#/capital-calls', $c['go']);
        DB::table('icc_results')->where('fk_id_product', $p)->update(['order_by' => date('Y-m-d', strtotime('+200 days'))]);
        $this->assertEmpty(array_filter(Decisions::all(1, 'CAPITAL_CALL')['items'], fn ($x) => $x['amount'] > 800000), 'an order due after 90 days is not counted');
    }
}
