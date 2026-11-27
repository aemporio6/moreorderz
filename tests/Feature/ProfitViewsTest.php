<?php

namespace Tests\Feature;

use App\Services\CloseService as Close;
use App\Services\Context;
use App\Services\ProfitService;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-74: profit seen every way (lot, batch, investor, shop, SKU, partner / VA share) with equal grand totals; paid one way (ONE_PROFIT_ROUTE). */
class ProfitViewsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_aug_bizmart_every_view_totals_377_50(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayPV', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'BZ', 'iso_branch' => 'BZ', 'shop_name' => 'Bizmart', 'f_active' => 1]);
        $investor = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Iqbal', 'sur_name' => 'Investor', 'f_active' => 1]);
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BZ-MUG', 'product_name_vendor' => 'Mug', 'f_active' => 1]);
        $poA = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-BZ-A', 'status' => 'CONFIRMED', 'f_active' => 1]); $poB = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-BZ-B', 'status' => 'CONFIRMED', 'f_active' => 1]);
        $lotA = $this->f('fin_lots', ['company_id' => 1, 'sku' => 'BZ-MUG', 'fk_id_product' => $prod, 'po_id' => $poA, 'lot_code' => 'LOT-A', 'source' => 'GRN', 'received_date' => '2026-07-01', 'qty' => 10, 'qty_left' => 8, 'unit_cost_gbp' => 20, 'fk_id_owner' => $investor, 'created_at' => now()]);
        $lotB = $this->f('fin_lots', ['company_id' => 1, 'sku' => 'BZ-MUG', 'fk_id_product' => $prod, 'po_id' => $poB, 'lot_code' => 'LOT-B', 'source' => 'GRN', 'received_date' => '2026-07-02', 'qty' => 10, 'qty_left' => 8, 'unit_cost_gbp' => 30, 'created_at' => now()]);
        $sale = fn (string $order, int $qty, float $total) => $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $prod, 'order_no' => $order, 'd_order' => '2026-08-10', 'qty' => $qty, 'total_amount_gbp' => $total, 'status' => 'Confirmed', 'sale_source' => 'active']);
        $s1 = $sale('BZ-O1', 3, 300); $s2 = $sale('BZ-O2', 1, 200);
        $use = fn (int $s, int $lot, int $q, float $c, ?int $own) => $this->f('fin_lot_consumption', ['lot_id' => $lot, 'sku' => 'BZ-MUG', 'qty' => $q, 'unit_cost_gbp' => $c, 'fk_id_sale' => $s, 'fk_id_owner' => $own, 'consumed_on' => '2026-08-31']);
        $use($s1, $lotA, 2, 20, $investor); $use($s1, $lotB, 1, 30, null); $use($s2, $lotB, 1, 30, null);
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'report_type' => 'EBAY', 'statement_id' => 'BZ-ST', 'statement_date' => '2026-08-31', 'currency' => 'GBP', 'status' => 'POSTED', 'f_rows_complete' => 1]);
        foreach ([['BZ-O1', -15], ['BZ-O2', -7.5]] as [$o, $v]) DB::table('fin_settlement_lines')->insert(RecordService::fillNotNull('fin_settlement_lines', ['company_id' => 1, 'branch_id' => $shop, 'settlement_id' => 'BZ-ST', 'order_id' => $o, 'amount_type' => 'FEE', 'amount' => $v, 'row_hash' => md5($o)]));

        $before = [DB::table('aud_runs')->count(), DB::table('fin_investor_ledger')->count(), DB::table('t_sales')->where('fk_id_branch', $shop)->sum('total_amount_gbp')];
        $tot = [];
        foreach (array_keys(ProfitService::VIEWS) as $by) $tot[$by] = $this->getJson("/api/profit?by=$by&shop=$shop&from=2026-08-01&to=2026-08-31")->assertOk()->json('total.profit');
        $this->assertEquals(array_fill_keys(array_keys(ProfitService::VIEWS), 377.5), $tot, 'every view totals the same eBay profit 377.50');
        $lot = collect(ProfitService::view(1, 'lot', '2026-08-01', '2026-08-31', $shop)['rows'])->keyBy('key');
        $this->assertEquals([150.0, 227.5], [$lot["L$lotA"]['profit'], $lot["L$lotB"]['profit']], 'lot A: 200 − 40 − 10; lot B: 300 − 60 − 12.50 (fees follow the sales of the order)');
        $this->assertSame(['PO-BZ-A', 'PO-BZ-B'], collect(ProfitService::view(1, 'batch', '2026-08-01', '2026-08-31', $shop)['rows'])->pluck('name')->all());
        $inv = collect(ProfitService::view(1, 'investor', '2026-08-01', '2026-08-31', $shop)['rows'])->keyBy('key');
        $this->assertEquals([150.0, 227.5], [$inv["I$investor"]['profit'], $inv['I0']['profit']]); $this->assertSame('Company (pool)', $inv['I0']['name']);
        $this->assertSame([4, 4], [collect(ProfitService::view(1, 'lot', '2026-08-01', '2026-08-31', $shop)['rows'])->sum('units'), ProfitService::view(1, 'shop', '2026-08-01', '2026-08-31', $shop)['total']['units']]);
        // MR-1285 every figure traceable: the parts behind a figure add up to it, with file and row
        $tr = fn ($by, $key, $col) => $this->getJson("/api/profit/trace?by=$by&shop=$shop&from=2026-08-01&to=2026-08-31&key=$key&col=$col")->assertOk()->json();
        $t = $tr('lot', "L$lotA", 'sales'); $this->assertEquals(200.0, collect($t['groups'][0]['rows'])->sum(fn ($r) => $r['cells'][5]));
        $t = $tr('shop', "S$shop", 'mp_costs'); $this->assertEquals(22.5, collect($t['groups'][0]['rows'])->sum(fn ($r) => $r['cells'][3]));
        $t = $tr('lot', "L$lotB", 'mp_costs'); $this->assertEquals(12.5, round(collect($t['groups'][0]['rows'])->sum(fn ($r) => $r['cells'][3]), 2));
        $t = $tr('shop', "S$shop", 'profit'); $this->assertSame(['Sale lines', 'Marketplace costs'], array_column($t['groups'], 'title')); $this->assertEquals(100.0, collect($t['groups'][0]['rows'])->sum(fn ($r) => $r['cells'][6]));
        $this->getJson("/api/profit/trace?by=shop&shop=$shop&from=2026-08-01&to=2026-08-31&key=S999999&col=sales")->assertStatus(422);
        $this->assertEquals($before, [DB::table('aud_runs')->count(), DB::table('fin_investor_ledger')->count(), DB::table('t_sales')->where('fk_id_branch', $shop)->sum('total_amount_gbp')], 'switching the view changes no saved figure');

        // paid one way: a second route on the same shop-month → ONE_PROFIT_ROUTE red
        DB::table('t_branch_profit_routes')->insert(['fk_id_company' => 1, 'fk_id_branch' => $shop, 'profit_route' => 'LOT_PROFIT', 'd_start' => '2026-01-01', 'reason' => 'start', 'created_by' => 1, 'created_at' => now()]);
        $this->assertSame('GREEN', collect(Close::evaluate(1, $shop, '2026-08')['checks'])->firstWhere('code', 'ONE_PROFIT_ROUTE')['status']);
        DB::table('t_branch_profit_routes')->insert(['fk_id_company' => 1, 'fk_id_branch' => $shop, 'profit_route' => 'INVESTOR_INVOICING', 'd_start' => '2026-08-15', 'reason' => 'second', 'created_by' => 1, 'created_at' => now()]);
        $this->assertSame('RED', collect(Close::evaluate(1, $shop, '2026-08')['checks'])->firstWhere('code', 'ONE_PROFIT_ROUTE')['status']);
    }
}
