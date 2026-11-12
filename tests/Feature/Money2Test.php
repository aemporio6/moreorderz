<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\PriceService;
use App\Services\ProfitService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SettlementService as Settle;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Money2Test extends TestCase
{
    private int $shop; private int $p; private int $bin; private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 100, 'location' => $this->bin, 'unit_cost' => 4.00, 'received_date' => '2026-08-01']], 'GRN', 1);
        PriceService::set($this->p, $mp, 8, 20.00, '2026-08-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 5000, 'opening_date' => '2026-08-01']);
    }

    private function confirmed(string $no, int $qty, string $date = '2026-09-05'): int
    {
        $id = Sales::intake($this->shop, [['order_no' => $no, 'd_order' => $date, 'sku' => 'BOWL-01', 'qty' => $qty, 'sale_rate' => 20]])['ids'][0];
        return $id;
    }

    public function test_profit_views_slice_the_same_numbers_three_ways_and_say_what_is_an_estimate(): void
    {
        $this->confirmed('ORD-1', 2); $this->confirmed('ORD-2', 1);
        Sales::confirmMonth($this->shop, '2026-09', true);
        $this->confirmed('ORD-9', 1, '2026-09-20');   // entered after the confirm: not in the figures
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'SOFTWARE', 'amount' => 10, 'date' => '2026-09-10']);
        $v = ProfitService::view(1, 'shop', '2026-09-01', '2026-09-30');
        $row = collect($v['rows'])->firstWhere('name', 'Shop A');
        $this->assertSame([3, 60.0, 12.0, 48.0, 0.0, true], [$row['units'], $row['sales'], $row['cost'], $row['gross'], $row['mp_costs'], $row['estimate']], 'no money file yet → marked as estimate');
        $this->assertSame([60.0, 12.0, 10.0, 38.0], [$v['total']['sales'], $v['total']['cost'], $v['total']['expenses'], $v['total']['profit']]);
        $this->assertSame([1, 20.0], [$v['not_confirmed']['lines'], $v['not_confirmed']['value']]);
        $this->assertSame([38.0, 0.0], [$v['books']['profit'], $v['books']['difference']], 'the views and the ledger agree');
        // the marketplace's file arrives: fees of 3.50 on ORD-1
        $st = DB::table('mo_settlements')->insertGetId(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => 'ST1', 'statement_date' => '2026-09-12', 'currency' => 'GBP', 'total' => 36.5, 'net_sales' => 40, 'fees' => -3.5, 'payable' => 36.5, 'rows_total' => 36.5, 'rows_count' => 1, 'f_rows_complete' => 1, 'status' => 'LOADED']);
        DB::table('fin_settlement_lines')->insert(RecordService::fillNotNull('fin_settlement_lines', ['batch_id' => 1, 'company_id' => 1, 'branch_id' => $this->shop, 'settlement_id' => 'ST1', 'order_id' => 'ORD-1', 'amount_type' => 'TT_COMMISSION', 'amount_description' => 'commission', 'amount' => -3.5, 'bucket' => 'FEE', 'row_hash' => sha1('x'), 'quantity' => 1]));
        Settle::post(1, $st);
        $v = ProfitService::view(1, 'product', '2026-09-01', '2026-09-30');
        $row = collect($v['rows'])->firstWhere('key', 'P' . $this->p);
        $this->assertSame([3.5, 44.5, false], [$row['mp_costs'], $row['profit'], $row['estimate']]);
        $this->assertSame(10.0, collect($v['rows'])->firstWhere('key', 'ZZ')['expenses'], 'overheads have their own row');
        $m = ProfitService::view(1, 'month', '2026-09-01', '2026-09-30');
        $this->assertSame([1, 34.5, 0.0], [count($m['rows']), $m['total']['profit'], $m['books']['difference']]);
    }

    public function test_refund_proposal_accept_books_the_refund_and_reject_needs_a_reason(): void
    {
        $a = $this->confirmed('ORD-1', 1); $b = $this->confirmed('ORD-2', 1);
        $mk = fn ($sale, $ref) => DB::table('t_sale_refund_proposals')->insertGetId(['fk_id_sale' => $sale, 'refund_id' => $ref, 'refund_kind' => 'FULL', 'amount' => 20, 'status' => 'PROPOSED', 'created_at' => now()]);
        $pa = $mk($a, 'R1'); $pb = $mk($b, 'R2');
        try { Settle::decideRefund(1, $pa, true); $this->fail('not confirmed'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not confirmed yet', $e->getMessage()); }
        Sales::confirmMonth($this->shop, '2026-09', true);
        $this->assertSame(40.0, Ledger::balance(1, '1100', null, $this->shop));
        Settle::decideRefund(1, $pa, true);
        $this->assertSame([20.0, 20.0], [Ledger::balance(1, '1100', null, $this->shop), Ledger::balance(1, '5400')]);
        $this->assertNotNull(DB::table('t_sales')->where('id', $a)->value('refunded_at'));
        try { Settle::decideRefund(1, $pa, true); $this->fail('twice'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already decided', $e->getMessage()); }
        try { Settle::decideRefund(1, $pb, false); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        Settle::decideRefund(1, $pb, false, 'Buyer kept the item');
        $this->assertSame('REJECTED', DB::table('t_sale_refund_proposals')->where('id', $pb)->value('status'));
        $v = ProfitService::view(1, 'shop', '2026-09-01', '2026-09-30');
        $this->assertSame(20.0, $v['rows'][0]['mp_costs'], 'the refund shows in the profit view');
    }

    public function test_big_stock_adjustment_and_big_supplier_payment_wait_for_a_second_person(): void
    {
        $mgr = $this->makeUser('MANAGER'); $own = DB::table('t_users')->where('role_code', 'OWNER')->value('id'); $fin = $this->makeUser('FINANCE');
        // stock: 60 units out by a manager → waits; 10 units → at once
        $body = ['product' => $this->p, 'location' => $this->bin, 'qty' => -60, 'reason' => 'Water damage'];
        $this->actingAs($mgr)->postJson('/api/stock/adjust', $body)->assertOk()->assertJsonPath('status', 'WAITING');
        $this->assertSame(100, Stock::available($this->p));
        $this->postJson('/api/stock/adjust', ['qty' => -10] + $body)->assertOk();
        $this->assertSame(90, Stock::available($this->p));
        $ap = DB::table('sys_approvals')->where('action_code', 'STOCK_ADJUST')->value('id');
        $this->postJson("/api/approvals/$ap", ['approve' => true])->assertStatus(422);                       // own request
        $this->actingAs(\App\Models\User::find($own))->postJson("/api/approvals/$ap", ['approve' => true])->assertOk();
        $this->assertSame(30, Stock::available($this->p));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));

        // supplier payment: 3,000 by Finance → waits; Manager approves → booked once
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Sup', 'f_active' => 1]);
        $po = Buy::createPo(['supplier' => $sup, 'currency' => 'GBP', 'date' => '2026-09-01', 'lines' => [['product' => $this->p, 'qty' => 2000, 'unit_price' => 4]]]);
        Buy::confirmPo($po);
        $term = DB::table('t_src_payment_terms')->where('fk_id_po', $po)->orderBy('term_no')->first();
        $pay = ['term' => $term->id, 'amount' => (float) $term->planned_amount, 'date' => '2026-09-02', 'bank' => $this->bank];
        $this->assertGreaterThan(2000, $pay['amount']);
        $this->actingAs($fin)->postJson('/api/pay', $pay)->assertOk()->assertJsonPath('status', 'WAITING');
        $this->assertSame(0, DB::table('t_src_payments')->count(), 'nothing is paid or booked while it waits');
        $this->postJson('/api/pay', $pay)->assertStatus(422)->assertJsonPath('message', 'A payment for this term is already waiting for approval.');
        $ap2 = DB::table('sys_approvals')->where('action_code', 'PAYMENT')->value('id');
        $this->actingAs($mgr)->postJson("/api/approvals/$ap2", ['approve' => true])->assertOk();
        $this->assertSame([1, $pay['amount']], [DB::table('t_src_payments')->count(), Ledger::balance(1, '1300', null, $sup)]);
        $this->assertSame('PAID', DB::table('t_src_payment_terms')->where('id', $term->id)->value('status'));
        $small = DB::table('t_src_payment_terms')->where('fk_id_po', $po)->orderByDesc('term_no')->first();
        $this->actingAs($fin)->postJson('/api/pay', ['term' => $small->id, 'amount' => 100, 'date' => '2026-09-03', 'bank' => $this->bank])->assertOk()->assertJsonPath('status', 'PAID');
    }

    public function test_night_checks_open_and_close_tasks_for_stale_bank_and_missing_money_file(): void
    {
        $profile = DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
        Money::importStatement(1, $this->bank, $profile, "Date,Description,Reference,Amount,Balance,Transaction ID\n01/08/2026,OLD,,-1,,T1\n");
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => 'ST1', 'statement_date' => '2026-08-01', 'currency' => 'GBP', 'status' => 'LOADED']);
        $this->assertSame(['bank' => 1, 'shop' => 1], Money::staleTasks(1));
        $this->assertTrue(TaskEngine::isOpen('FIN_BANK_STALE', [$this->bank]) && TaskEngine::isOpen('FIN_SETTLEMENT_OVERDUE', [$this->shop]));
        Money::importStatement(1, $this->bank, $profile, "Date,Description,Reference,Amount,Balance,Transaction ID\n" . date('d/m/Y') . ",NEW,,-1,,T2\n");
        $this->assertFalse(TaskEngine::isOpen('FIN_BANK_STALE', [$this->bank]));
        $this->assertSame(['bank' => 0, 'shop' => 1], Money::staleTasks(1));
    }

    public function test_money_dashboard_profit_and_refund_screens_answer_with_roles(): void
    {
        $fin = $this->makeUser('FINANCE'); $wh = $this->makeUser('WAREHOUSE');
        $this->actingAs($wh)->getJson('/api/money')->assertStatus(403);
        $this->getJson('/api/profit')->assertStatus(403);
        $d = $this->actingAs($fin)->getJson('/api/money')->assertOk()->json();
        $this->assertSame([5000.0, 400.0, 0], [(float) $d['cash'], (float) $d['stock'], $d['approvals']]);
        $this->getJson('/api/profit?by=month&from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonPath('by', 'month');
        $this->getJson('/api/profit?by=nonsense')->assertStatus(422);
        $this->getJson('/api/refunds')->assertOk()->assertJsonPath('can_edit', true);
        $this->postJson('/api/refunds/999', ['accept' => true])->assertStatus(422);
    }
}
