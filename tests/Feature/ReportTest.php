<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\CloseService as Close;
use App\Services\Context;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\PartnerService as Partners;
use App\Services\PriceService;
use App\Services\ProfitService;
use App\Services\RecordService;
use App\Services\ReportService as Reports;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportTest extends TestCase
{
    private string $m1; private string $from; private string $to; private int $shop; private int $bank; private int $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m1 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month')); $this->from = $this->m1 . '-01'; $this->to = date('Y-m-d');
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'min_level' => 500]);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        $own = \App\Models\User::find(DB::table('t_users')->where('role_code', 'OWNER')->value('id')); $this->actingAs($own); Context::set(1, $own->id);
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 5000, 'opening_date' => $this->from]);
        // buy 100 at 4.00 through a PO, pay the deposit, receive
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Sup One', 'f_active' => 1]);
        $po = Buy::createPo(['supplier' => $sup, 'currency' => 'GBP', 'date' => $this->from, 'lines' => [['product' => $p, 'qty' => 100, 'unit_price' => 4]]]); Buy::confirmPo($po);
        $term = DB::table('t_src_payment_terms')->where('fk_id_po', $po)->orderBy('term_no')->first();
        Buy::pay(['term' => $term->id, 'amount' => (float) $term->planned_amount, 'date' => $this->from, 'bank' => $this->bank]);
        Stock::receive(1, [['product' => $p, 'qty' => 100, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => $this->from]], 'GRN', 1);
        PriceService::set($p, $mp, 8, 20.00, $this->from);
        Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => $this->m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 3, 'sale_rate' => 20], ['order_no' => 'ORD-2', 'd_order' => $this->m1 . '-06', 'sku' => 'BOWL-01', 'qty' => 2, 'sale_rate' => 20]]);
        Sales::confirmMonth($this->shop, $this->m1, true);
        Sales::intake($this->shop, [['order_no' => 'ORD-3', 'd_order' => date('Y-m-d'), 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 20]]);      // this month, not confirmed
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'SOFTWARE', 'amount' => 15, 'date' => $this->m1 . '-10']);
        $this->partner = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Partner', 'sur_name' => 'One', 'account_code' => 'P1', 'f_active' => 1]);
        Partners::saveAgreement(1, ['investor_id' => $this->partner, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->from, 'profit_share_pct' => 25]);
        Partners::capitalIn(1, $this->partner, 1000, $this->bank, $this->m1 . '-02');
        Close::lock(1, $this->shop, $this->m1, 'First month.');
    }

    private function rep(string $key, ?string $role = 'OWNER'): array { return Reports::run(1, $key, $this->from, $this->to, $role); }

    public function test_report_totals_agree_with_the_working_screens_and_the_books(): void
    {
        $profit = ProfitService::view(1, 'shop', $this->from, $this->to);
        foreach (['sales_shop', 'sales_product', 'sales_day'] as $k) {
            $r = $this->rep($k);
            $this->assertSame([$profit['total']['sales'], 20.0, 120.0, 6], [$r['totals']['confirmed'], $r['totals']['pending'], $r['totals']['total'], (int) $r['totals']['units']], $k);
        }
        $this->assertSame([3, 3], [(int) $this->rep('sales_shop')['rows'][0]['orders'], count($this->rep('sales_day')['rows'])]);
        $sp = $this->rep('sales_product');
        $this->assertSame([$profit['total']['cost'], 80.0], [$sp['totals']['cost'], $sp['totals']['gross']]);
        $st = $this->rep('stock');
        $this->assertSame([Stock::value(1), Ledger::balance(1, '1200'), 95], [$st['totals']['value'], $st['totals']['value'], (int) $st['totals']['units']], 'stock report = stock value = stock account');
        $this->assertSame('LOW', $st['rows'][0]['state']); $this->assertGreaterThanOrEqual(1, $st['rows'][0]['sold_30']);
        $this->assertSame($st['totals']['value'], $this->rep('stock_location')['totals']['value']);
        $cash = $this->rep('cash');
        $this->assertSame(Ledger::balance(1, '1000'), $cash['totals']['net'], 'net of the cash report = the cash balance (all movements are inside the period)');
        $kinds = array_column($cash['rows'], 'net', 'kind');
        $this->assertSame([5000.0, -15.0, 1000.0], [$kinds['Opening balance'], $kinds['Expense · Software and tools'], $kinds['Partner capital in']]);
        $po = $this->rep('purchases');
        $this->assertSame([1, 100, 400.0, 120.0, '280.00'], [count($po['rows']), (int) $po['totals']['ordered'], $po['totals']['total'], $po['totals']['paid'], $po['rows'][0]['open_cur']]);
        $this->assertSame(Ledger::balance(1, '1300'), $this->rep('suppliers')['totals']['advance']);
        $pa = $this->rep('partners')['rows'][0];     // shop profit 100 − 20 = 80 → 25 % = 20
        $this->assertSame([1000.0, 20.0, 20.0], [$pa['capital'], $pa['profit'], $pa['balance']]);
        $cl = $this->rep('close');
        $this->assertSame([['Locked', 100.0, 80.0, 'First month.'], 'Open'], [[$cl['rows'][0]['status'], $cl['rows'][0]['sales'], $cl['rows'][0]['profit'], $cl['rows'][0]['note']], $cl['rows'][1]['status']]);
    }

    public function test_reports_api_roles_money_hidden_from_warehouse_and_the_spreadsheet_file(): void
    {
        $wh = $this->makeUser('WAREHOUSE'); $va = $this->makeUser('VA'); $fin = $this->makeUser('FINANCE');
        $this->assertSame([], $this->actingAs($va)->getJson('/api/reports')->assertOk()->json('reports'));
        $this->getJson('/api/reports/sales_shop')->assertStatus(403);
        $list = array_column($this->actingAs($wh)->getJson('/api/reports')->json('reports'), 'key');
        $this->assertSame(['stock', 'stock_location'], $list);
        $s = $this->getJson('/api/reports/stock')->assertOk()->json();
        $this->assertNotContains('value', array_column($s['columns'], 'key'), 'warehouse sees units, not money');
        $this->assertArrayNotHasKey('value', $s['rows'][0]);
        $this->getJson('/api/reports/cash')->assertStatus(403);
        $this->actingAs($fin)->getJson('/api/reports/nonsense')->assertStatus(403);
        $this->getJson("/api/reports/cash?from={$this->to}&to={$this->from}")->assertStatus(422);
        $this->assertCount(10, $this->getJson('/api/reports')->json('reports'));
        $csv = $this->get("/api/reports/sales_shop/csv?from={$this->from}&to={$this->to}")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Shop,Orders,Units,"Confirmed sales"', $lines[0]);
        $this->assertSame('Total,3,6,100,20,120', $lines[2]);
        $this->assertSame(1, DB::table('sys_audit_log')->where('action', 'EXPORT')->where('table_name', 'reports')->count(), 'an export is logged');
    }
}
