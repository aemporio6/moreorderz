<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\Connect\Connections;
use App\Services\Context;
use App\Services\FinanceExtras as F;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\PartnerService;
use App\Services\PrintAdapters;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Final round: choices per shop, VA salary source, VAT return, loans, budgets, capital calls, statement answers, one-click printing, two-layer stock, phone app. */
class Part8Test extends TestCase
{
    private int $shop; private int $shop2; private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop P8', 'shop_no' => 'P8A', 'iso_branch' => 'P8A', 'f_active' => 1]);
        $this->shop2 = $f('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop P8b', 'shop_no' => 'P8B', 'iso_branch' => 'P8B', 'f_active' => 1]);
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank P8', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 5000, 'opening_date' => '2026-01-01']);
    }

    private function person(string $n): int
    {
        return (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'Q' . random_int(10000, 99999), 'first_name' => $n, 'sur_name' => 'P8', 'f_active' => 1]));
    }

    public function test_a_choice_has_a_company_default_and_shops_can_have_their_own_value(): void
    {
        $this->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'value' => 'BASE'])->assertOk();
        $this->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'shop' => $this->shop2, 'value' => 'RETAIL'])->assertOk();
        $this->assertSame(['BASE', 'RETAIL'], [Choices::get('TEMU_SALE_VALUE', 1, $this->shop), Choices::get('TEMU_SALE_VALUE', 1, $this->shop2)]);
        $this->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'value' => 'RETAIL'])->assertOk();   // a new default reaches every shop without its own value
        $this->assertSame('RETAIL', Choices::get('TEMU_SALE_VALUE', 1, $this->shop));
        $this->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'shop' => $this->shop2, 'value' => ''])->assertOk();   // back to the default
        $c = collect($this->getJson('/api/choices')->json('choices'))->keyBy('key');
        $this->assertTrue($c['TEMU_SALE_VALUE']['per_shop']);
        $this->assertSame(['SPLIT_RULE', 'JOB_HISTORY', 'EXPENSES'], array_column($c['VA_SALARY_SOURCE']['options'], 'value'));
        $this->assertSame(['PICKED_LOT', 'FIFO_AT_CONFIRM'], array_column($c['STOCK_COST_LAYER']['options'], 'value'));
        $this->assertFalse($c['VAULT_REVEAL']['per_shop']);
        $this->postJson('/api/choices', ['key' => 'VAULT_REVEAL', 'shop' => $this->shop, 'value' => 'OWNER'])->assertStatus(422);
        $this->actingAs($this->makeUser('MANAGER'))->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'shop' => $this->shop, 'value' => 'BASE'])->assertStatus(403);
    }

    public function test_va_salary_comes_from_the_chosen_source(): void
    {
        $va = $this->person('Vee');
        $pkr = DB::table('cat_currency')->where('currency_code', 'PKR')->value('id') ?? DB::table('cat_currency')->insertGetId(RecordService::fillNotNull('cat_currency', ['currency_code' => 'PKR', 'currency_name' => 'Rupee']));
        DB::table('t_account_job_history')->insert(RecordService::fillNotNull('t_account_job_history', ['fk_id_account' => $va, 'fk_id_branch' => $this->shop, 'd_job_start_date' => '2026-01-01', 'salary' => 35000, 'fk_id_currency' => $pkr, 'pound_exchange_rate' => 350, 'share_percentage' => 5]));
        Choices::setShop('VA_SALARY_SOURCE', $this->shop, 'JOB_HISTORY', 1);
        $this->assertEqualsWithDelta(100.00, PartnerService::vaSalary(1, $this->shop, '2026-10'), 0.001, '35,000 PKR ÷ 350 = £100');
        $this->assertEqualsWithDelta(0.0, PartnerService::vaSalary(1, $this->shop, '2025-10'), 0.001, 'not employed yet');
        Choices::setShop('VA_SALARY_SOURCE', $this->shop, 'EXPENSES', 1);
        $this->assertEqualsWithDelta(0.0, PartnerService::vaSalary(1, $this->shop, '2026-10'), 0.001, 'booked as an expense: the waterfall takes nothing');
        $this->assertEqualsWithDelta(0.0, PartnerService::vaSalary(1, $this->shop2, '2026-10'), 0.001);
    }

    public function test_vat_return_boxes_per_shop_mode_booking_and_payment(): void
    {
        Choices::set('VAT_REGISTERED', '1', 1);
        Ledger::post(1, '2026-08-10', 'SALE_CONFIRM', $this->shop, [['account' => '1100', 'debit' => 120, 'ref_id' => $this->shop], ['account' => '4000', 'credit' => 120, 'ref_id' => $this->shop]]);
        Ledger::post(1, '2026-08-11', 'SALE_CONFIRM', $this->shop2, [['account' => '1100', 'debit' => 60, 'ref_id' => $this->shop2], ['account' => '4000', 'credit' => 60, 'ref_id' => $this->shop2]]);
        Ledger::post(1, '2026-08-12', 'EXPENSE', null, [['account' => '6000', 'debit' => 50], ['account' => '1400', 'debit' => 10], ['account' => '1000', 'credit' => 60, 'ref_id' => $this->bank]]);
        Choices::setShop('OUTPUT_VAT_MODE', $this->shop2, 'MARKETPLACE', 1);
        $c = F::vatCalc(1, '2026-07-01', '2026-09-30');
        $this->assertSame([20.0, 10.0, 10.0, 160.0], [$c['box1'], $c['box4'], $c['box5'], $c['box6']], 'shop 1: 120 incl. 20 VAT; shop 2: the marketplace pays its VAT');
        $this->assertEqualsWithDelta(50.0, $c['box7'], 0.001);
        $r = $this->postJson('/api/vat-return/book', ['from' => '2026-07-01', 'to' => '2026-09-30', 'reference' => 'TEST-1'])->assertOk()->json();
        $this->assertEqualsWithDelta(-10.0, Ledger::balance(1, '2200'), 0.001, 'VAT payable 10 after the return');
        $this->assertEqualsWithDelta(0.0, Ledger::balance(1, '1400'), 0.001, 'VAT to claim back used up');
        $this->postJson('/api/vat-return/book', ['from' => '2026-07-01', 'to' => '2026-09-30'])->assertStatus(422);
        $this->postJson("/api/vat-return/{$r['id']}/pay", ['bank' => $this->bank, 'date' => '2026-11-01'])->assertOk();
        $this->assertEqualsWithDelta(0.0, Ledger::balance(1, '2200'), 0.001);
        $this->assertSame('PAID', DB::table('mo_vat_returns')->where('id', $r['id'])->value('status'));
        $this->assertTrue(Ledger::trialBalance(1)['balanced'] ?? true);
    }

    public function test_loan_taken_paid_and_due_task(): void
    {
        $id = F::loanNew(1, ['direction' => 'TAKEN', 'counterparty' => 'Bank Loan Ltd', 'amount' => 1000, 'bank' => $this->bank, 'date' => '2026-10-01', 'instalment' => 200, 'frequency' => 'MONTHLY', 'next_due_date' => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertEqualsWithDelta(-1000, Ledger::balance(1, '2400'), 0.001);
        $this->assertSame(1, F::loanTasks());
        $r = $this->postJson("/api/loans/$id/pay", ['principal' => 200, 'interest' => 12.5, 'bank' => $this->bank, 'date' => '2026-11-01'])->assertOk()->json();
        $this->assertEqualsWithDelta(800, $r['balance'], 0.001);
        $this->assertEqualsWithDelta(-800, Ledger::balance(1, '2400'), 0.001);
        $this->assertEqualsWithDelta(12.5, Ledger::balance(1, '6200'), 0.001, 'interest is a finance cost');
        $this->assertSame(0, DB::table('tasks')->where('rule_code', 'FIN_LOAN_DUE')->whereNotIn('status', ['DONE', 'CANCELLED'])->count());
        $this->postJson("/api/loans/$id/pay", ['principal' => 900, 'bank' => $this->bank])->assertStatus(422);
        $given = F::loanNew(1, ['direction' => 'GIVEN', 'counterparty' => 'Cousin', 'amount' => 300, 'bank' => $this->bank]);
        $this->assertEqualsWithDelta(300, Ledger::balance(1, '1150'), 0.001);
    }

    public function test_budget_against_actual(): void
    {
        $this->postJson('/api/budgets', ['year' => 2026, 'category' => 'SOFTWARE', 'amount' => 100])->assertOk();
        DB::table('fin_expenses')->insert(RecordService::fillNotNull('fin_expenses', ['fk_id_ent' => 1, 'd_expense' => '2026-03-05', 'category_code' => 'SOFTWARE', 'amount' => 130, 'amount_gbp' => 130, 'f_active' => 1]));
        $row = collect($this->getJson('/api/budgets?year=2026')->assertOk()->json('rows'))->firstWhere('code', 'SOFTWARE');
        $this->assertSame([100, 130], [(int) $row['budget'][3], (int) $row['actual'][3]]);
        $this->assertSame(1200.0, (float) $row['budget_total']);
    }

    public function test_capital_call_asks_each_partner_his_share_and_received_money_is_capital(): void
    {
        $a = $this->person('Ann'); $b = $this->person('Bob');
        $id = F::callNew(1, ['amount' => 1000, 'reason' => 'New PO for wax', 'partners' => [$a => 60, $b => 40]]);
        $ans = DB::table('fin_capital_call_responses')->where('call_id', $id)->pluck('amount_asked', 'investor_id');
        $this->assertSame([600.0, 400.0], [(float) $ans[$a], (float) $ans[$b]]);
        $ra = DB::table('fin_capital_call_responses')->where(['call_id' => $id, 'investor_id' => $a])->value('id');
        $this->postJson("/api/capital-calls/answer/$ra", ['status' => 'RECEIVED', 'amount' => 600, 'bank' => $this->bank, 'date' => '2026-11-02'])->assertOk();
        $this->assertEqualsWithDelta(-600, Ledger::balance(1, '2150', null, $a), 0.001);
        $rb = DB::table('fin_capital_call_responses')->where(['call_id' => $id, 'investor_id' => $b])->value('id');
        $this->postJson("/api/capital-calls/answer/$rb", ['status' => 'DECLINED'])->assertOk();
        $this->assertSame('CLOSED', DB::table('fin_capital_calls')->where('id', $id)->value('status'));
    }

    public function test_partner_accepts_or_asks_about_a_statement_and_finance_answers(): void
    {
        $inv = $this->person('Pat');
        $pu = $this->makeUser('PARTNER'); DB::table('fin_partner_users')->insert(['user_id' => $pu->id, 'investor_id' => $inv, 'created_at' => now()]);
        $run = DB::table('aud_runs')->insertGetId(RecordService::fillNotNull('aud_runs', ['run_code' => 'RUN-P8', 'fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'period_month' => '2026-09', 'version' => 1, 'status' => 'LOCKED']));
        $st = DB::table('fin_statements')->insertGetId(['period_id' => $run, 'investor_id' => $inv, 'version' => 1, 'superseded' => 0, 'file_type' => 'PDF', 'file_path' => 'statements/p8.pdf', 'partner_profit' => 50, 'partner_balance' => 50, 'created_at' => now()]);
        $this->postJson("/api/statements/$st/answer", ['what' => 'ACCEPT'])->assertStatus(422);   // the Owner is not the partner
        $this->actingAs($pu)->postJson("/api/statements/$st/answer", ['what' => 'ASK', 'question' => 'Why are fees so high?'])->assertOk()->assertJsonPath('status', 'QUERIED');
        $q = DB::table('fin_statement_queries')->where('statement_id', $st)->first();
        $this->assertNotNull($q->task_id, 'Finance gets a task');
        $this->actingAs($this->owner)->postJson("/api/statement-questions/{$q->id}/reply", ['answer' => 'Two promoted listings ran.'])->assertOk();
        $this->assertSame('DONE', DB::table('tasks')->where('id', $q->task_id)->value('status'));
        $this->assertSame('Two promoted listings ran.', $this->actingAs($pu)->getJson('/api/statement-questions')->json('rows.0.answer'));
        $this->actingAs($pu)->postJson("/api/statements/$st/answer", ['what' => 'ACCEPT'])->assertOk();
        $this->assertSame('ACCEPTED', DB::table('fin_statements')->where('id', $st)->value('partner_status'));
    }

    private function pdf(): string
    {
        $d = new \Dompdf\Dompdf(); $d->loadHtml('<div style="font-size:20pt">TEST LABEL</div>'); $d->setPaper([0, 0, 283.46, 425.2]); $d->render();
        $p = tempnam(sys_get_temp_dir(), 'p8') . '.pdf'; file_put_contents($p, $d->output()); return $p;
    }

    public function test_one_click_printing_ipp_raw_and_print_helper(): void
    {
        // IPP: a Print-Job request with the PDF, answered "successful-ok"
        $pdf = $this->pdf(); $req = PrintAdapters::ippRequest('ipp://10.0.0.5:631/ipp/print', 2, 'PDFDATA');
        $this->assertSame("\x01\x01\x00\x02", substr($req, 0, 4)); $this->assertStringContainsString('application/pdf', $req); $this->assertStringEndsWith("\x03PDFDATA", $req);
        Http::fake(['10.0.0.5:631/*' => Http::response("\x01\x01\x00\x00\x00\x00\x00\x01\x03", 200, ['Content-Type' => 'application/ipp'])]);
        $printer = (object) ['network_address' => 'ipp://10.0.0.5:631/ipp/print', 'dpi' => 203, 'fk_id_ent' => 1];
        $this->assertSame('Sent to the printer over IPP', PrintAdapters::send('IPP', $printer, $pdf, 2, 1, 100, 150));
        Http::assertSent(fn (HttpRequest $q) => $q->url() === 'http://10.0.0.5:631/ipp/print' && $q->hasHeader('Content-Type', 'application/ipp'));
        // raw: the label becomes a 1-bit picture in the printer's language and goes to port 9100
        $bmp = PrintAdapters::bitmap($pdf, 203); [$bytes, $h, $rows, $w] = $bmp;
        $this->assertSame([$bytes * $h, true], [strlen($rows), $w > 700 && $w < 900], '100 mm at 203 dpi is about 800 dots wide');
        $this->assertStringStartsWith('SIZE 100.0 mm,150.0 mm', PrintAdapters::rawCommands('TSPL', $bmp, 3, 100, 150));
        $this->assertStringEndsWith("PRINT 1,3\r\n", PrintAdapters::rawCommands('TSPL', $bmp, 3, 100, 150));
        $this->assertMatchesRegularExpression('/^\^XA\^PW\d+\^LL\d+\^FO0,0\^GFA,\d+,\d+,\d+,[0-9A-F]+\^FS\^PQ2\^XZ$/', PrintAdapters::rawCommands('ZPL', $bmp, 2, 100, 150));
        $srv = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) explode(':', stream_socket_get_name($srv, false))[1];
        $out = PrintAdapters::send('RAW_TSPL', (object) ['network_address' => "127.0.0.1:$port", 'dpi' => 203, 'fk_id_ent' => 1], $pdf, 1, 1, 100, 150);
        $c = stream_socket_accept($srv, 5); $got = stream_get_contents($c); fclose($c); fclose($srv);
        $this->assertSame('Sent to the printer as TSPL', $out); $this->assertStringStartsWith('SIZE 100.0 mm', $got); $this->assertStringEndsWith("PRINT 1,1\r\n", $got);
        // print helper: the job waits, the helper with the right token takes it and reports it printed
        Connections::save(['adapter' => 'PRINT_AGENT', 'secrets' => ['agent_token' => 'helper-token-123456']]);
        @mkdir(storage_path('app/print'), 0775, true); copy($pdf, storage_path('app/print/P8TEST.pdf'));
        $adapter = DB::table('print_adapters')->where('code', 'PRINT_AGENT')->value('id');
        $pp = DB::table('printer_profiles')->insertGetId(RecordService::fillNotNull('printer_profiles', ['fk_id_ent' => 1, 'code' => 'P8', 'name' => 'Packing USB', 'printer_kind' => 'THERMAL', 'fk_id_adapter' => $adapter, 'os_printer_name' => 'MUNBYN_USB', 'f_active' => 1]));
        $job = DB::table('print_jobs')->insertGetId(RecordService::fillNotNull('print_jobs', ['fk_id_ent' => 1, 'job_code' => 'P8TEST', 'entity_type' => 'PARCEL', 'entity_ids' => '[1]', 'fk_id_printer_profile' => $pp, 'copies' => 1, 'status' => 'AGENT', 'file_ref' => 'print/P8TEST.pdf', 'requested_at' => now()]));
        $this->getJson('/print-agent/next', ['X-Agent-Token' => 'wrong'])->assertStatus(403);
        $n = $this->getJson('/print-agent/next', ['X-Agent-Token' => 'helper-token-123456'])->assertOk()->json('job');
        $this->assertSame([$job, 'MUNBYN_USB'], [$n['job'], $n['printer']]);
        $this->assertStringStartsWith('%PDF', base64_decode($n['pdf_base64']));
        $this->postJson("/print-agent/$job/done", ['ok' => true], ['X-Agent-Token' => 'helper-token-123456'])->assertOk();
        $this->assertSame('PRINTED', DB::table('print_jobs')->where('id', $job)->value('status'));
        @unlink(storage_path('app/print/P8TEST.pdf'));
    }

    public function test_two_layer_stock_swaps_the_oldest_lot_into_the_shipped_place_without_moving_units(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'P8-SKU', 'product_name_vendor' => 'Thing', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $x = $f('cat_inventory_locations', ['location_name' => 'P8-X', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $y = $f('cat_inventory_locations', ['location_name' => 'P8-Y', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        Stock::receive(1, [['product' => $p, 'qty' => 5, 'location' => $x, 'unit_cost' => 1.00]], 'GRN', 1, ['date' => '2026-01-01']);
        Stock::receive(1, [['product' => $p, 'qty' => 5, 'location' => $y, 'unit_cost' => 2.00]], 'GRN', 2, ['date' => '2026-06-01']);
        $ship = Stock::systemLocation(1, 'SHIPPED');
        Stock::transfer(1, $p, 2, $y, $ship, 'picked from Y');   // the picker took the newer lot (cost 2.00)
        $value = Stock::value(1, $p);
        $this->assertSame(2, Stock::swapToFifo(1, $p, 2, $ship));
        $this->assertSame([5, 3, 2], [Stock::balance($p, $x), Stock::balance($p, $y), Stock::balance($p, $ship)], 'every place keeps its units');
        $c = Stock::consumeForSale(1, 999001, $p, 2, $ship);
        $this->assertEqualsWithDelta(2.00, $c['cost'], 0.001, 'the oldest lot (1.00 each) is the one sold');
        $this->assertEqualsWithDelta($value - 2.00, Stock::value(1, $p), 0.001);
        $this->assertTrue(Stock::selfCheck(1)['ok'] ?? true);
    }

    public function test_phone_app_files_are_there(): void
    {
        $m = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame(['MoreOrderz', 'standalone'], [$m['name'], $m['display']]);
        foreach (['sw.js', 'offline.html', 'app/icons/icon-192.png', 'app/icons/icon-512.png'] as $f) $this->assertFileExists(public_path($f));
        $this->assertStringNotContainsString("'/api/'", str_replace("u.pathname.startsWith('/api/')", '', (string) file_get_contents(public_path('sw.js'))), 'API answers are never kept on the phone');
        auth()->logout(); $this->get('/login')->assertSee('manifest.webmanifest', false);
    }
}
