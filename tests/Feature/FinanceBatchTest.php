<?php

namespace Tests\Feature;

use App\Services\BankCategoryHint;
use App\Services\Fx;
use App\Services\FxMissing;
use App\Services\JobLog;
use App\Services\MoneyService;
use App\Services\ProfitService;
use App\Services\RecordService;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-29 categories, R-FIN-30 category suggestion, R-FIN-26 missing rate task, R-FIN-31 ledger filters / totals, R-FIN-32 lock + run log. */
class FinanceBatchTest extends TestCase
{
    private int $bank;
    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); $this->bank = DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Test Bank', 'account_label' => 'Main FB', 'currency' => 'GBP', 'is_active' => 1]); }
    private function line(string $text, float $amt, array $x = []): object
    {
        $id = DB::table('fin_bank_transactions')->insertGetId($x + ['batch_id' => 0, 'company_id' => 1, 'bank_account_id' => $this->bank, 'txn_date' => '2026-09-12', 'description' => $text, 'amount' => $amt, 'currency' => 'GBP', 'amount_gbp' => $amt, 'match_status' => 'UNMATCHED', 'bank_txn_id' => 'FB' . uniqid()]);
        return DB::table('fin_bank_transactions')->where('id', $id)->first();
    }

    public function test_r29_the_spec_categories_and_a_new_one_counts_in_the_fixed_share(): void
    {
        $alias = ['MARKETPLACE_PAYOUT' => 'SALES_PAYOUT', 'PARTNER_CAPITAL' => 'CAPITAL_IN', 'PARTNER_WITHDRAWAL' => 'WITHDRAWAL'];
        $in = ['MARKETPLACE_PAYOUT', 'PARTNER_CAPITAL', 'LOAN_IN', 'SUPPLIER_REFUND', 'INTERCOMPANY_IN', 'OTHER_IN', 'OWN_TRANSFER_IN', 'INVOICE_RECEIPT'];
        $out = ['SUPPLIER_PAYMENT', 'FREIGHT', 'DUTY_VAT', 'PLATFORM_FEE', 'PPC', 'SUBSCRIPTION', 'SALARY_VA', 'OFFICE', 'PARTNER_WITHDRAWAL', 'LOAN_OUT', 'INTERCOMPANY_OUT', 'TAX_RESERVE', 'OTHER_OUT',
            'OWN_TRANSFER_OUT', 'INVOICE_PAYMENT', 'SAMPLES', 'STOCK_LOSS', 'IMPORT_FREIGHT', 'IMPORT_DUTY', 'VA_SHARE_PAYOUT', 'SALARY_VA', 'HMRC_VAT', 'HMRC_CT', 'HMRC_PAYE', 'ADS_EXTERNAL'];
        $cost = ['FREIGHT', 'PLATFORM_FEE', 'OTHER_OUT', 'SUBSCRIPTION', 'SALARY_VA', 'OFFICE', 'SAMPLES', 'STOCK_LOSS', 'ADS_EXTERNAL'];      // the spec's variable + fixed: in the profit
        $c = DB::table('fin_categories')->get()->keyBy('code');
        foreach (array_merge(array_fill_keys($in, 'IN'), array_fill_keys($out, 'OUT')) as $spec => $dir) {
            $code = $alias[$spec] ?? $spec; $this->assertArrayHasKey($code, $c, $spec); $this->assertSame($dir, $c[$code]->direction, $spec);
            $this->assertSame(in_array($spec, $cost, true), in_array($c[$code]->profit_role, ['FIXED', 'VARIABLE', 'COGS'], true), "$spec in the profit or not");
        }
        $this->assertTrue(DB::table('fin_categories')->where('code', 'SPLIT')->exists(), 'SPLIT marker');
        // the rule's test: a new category WAREHOUSE_RENT OUT / fixed (made from the form) → counted in the fixed share at the next refresh
        $id = RecordService::save('fin_category', ['code' => 'warehouse rent', 'name' => 'Warehouse rent', 'direction' => 'OUT', 'profit_role' => 'FIXED'])['id'];
        $this->assertSame('WAREHOUSE_RENT', DB::table('fin_categories')->where('id', $id)->value('code'));
        DB::table('fin_expenses')->insert(RecordService::fillNotNull('fin_expenses', ['fk_id_ent' => 1, 'd_expense' => '2026-09-15', 'category_code' => 'WAREHOUSE_RENT', 'amount' => 900, 'amount_gbp' => 900, 'f_active' => 1, 'created_at' => now()]));
        $v = ProfitService::view(1, 'shop', '2026-09-01', '2026-09-30');
        $this->assertEqualsWithDelta(900.0, (float) collect($v['rows'])->firstWhere('key', 'ZZ')['expenses'], 0.01, 'overheads (the fixed share) include it');
    }

    public function test_r30_suggestion_chip_never_applied_by_itself_and_learned_payees_first(): void
    {
        $dhl = $this->line('DHL EXPRESS', -84.00);
        $this->assertSame('FREIGHT', BankCategoryHint::suggest(1, $dhl)['code'], 'the rule example');
        $this->assertNull(DB::table('fin_bank_transactions')->where('id', $dhl->id)->value('category_code'), 'still empty until clicked');
        $this->assertSame('SALES_PAYOUT', BankCategoryHint::suggest(1, $this->line('AMZN PAYMENTS EU', 1200))['code'], 'amazon money in = marketplace payout');
        $this->assertNull(BankCategoryHint::suggest(1, $this->line('AMAZON MKTPLACE PMTS', -12.99)), 'amazon money out is not a payout');
        $this->assertSame('PLATFORM_FEE', BankCategoryHint::suggest(1, $this->line('EBAY O*12-34', -30))['code']);
        $this->assertSame('SUPPLIER_PAYMENT', BankCategoryHint::suggest(1, $this->line('SHENZHEN LTD', -650))['code'], '"ltd" below −500');
        $this->assertNull(BankCategoryHint::suggest(1, $this->line('SHENZHEN LTD', -120)), '"ltd" above −500 says nothing');
        // learned: booked once by hand → offered first next time for the same payee
        $a = $this->line('ACME OFFICE SUPPLIES 4471', -55, ['counterparty' => 'Acme Office Supplies']);
        MoneyService::categorise(1, (int) $a->id, ['category' => 'OFFICE']);
        $b = $this->line('ACME OFFICE SUPPLIES 9921', -61, ['counterparty' => 'Acme Office Supplies']);
        $s = BankCategoryHint::suggest(1, $b); $this->assertSame('OFFICE', $s['code']); $this->assertStringContainsString('chosen before', $s['why']);
        $x = $this->getJson('/api/bank?show=UNMATCHED')->assertOk()->json('lines');
        $this->assertSame('FREIGHT', collect($x)->firstWhere('id', $dhl->id)['suggest']['code'], 'the screen gets the chip');
    }

    public function test_r26_latest_rate_on_or_before_and_a_missing_rate_is_a_task_never_one(): void
    {
        Fx::set('EUR', '2026-03-08', 0.86);
        $this->assertSame(0.86, Fx::rate('EUR', '2026-03-10', 1), 'latest rate on or before the date');
        try { Fx::rate('USD', '2026-03-10', 1); $this->fail('no rate must not be 1.0'); } catch (\RuntimeException $e) { $this->assertStringContainsString('No exchange rate for USD', $e->getMessage()); }
        FxMissing::flush();
        $this->assertTrue(TaskEngine::isOpen('FIN_FX_MISSING', ['USD', '2026-03-10']));
        $this->assertSame('Add rate USD 2026-03-10', DB::table('tasks')->where('dedupe_key', 'FIN_FX_MISSING:USD:2026-03-10')->value('title'));
        Fx::set('USD', '2026-03-12', 0.79); $this->assertTrue(TaskEngine::isOpen('FIN_FX_MISSING', ['USD', '2026-03-10']), 'a later rate does not answer an earlier date');
        Fx::set('usd', '2026-03-01', 0.80); $this->assertFalse(TaskEngine::isOpen('FIN_FX_MISSING', ['USD', '2026-03-10']), 'a rate on or before the date closes it');
    }

    public function test_r31_ledger_filters_and_totals(): void
    {
        $this->line('Uncat A', -40, ['txn_date' => '2026-02-01']); $this->line('Uncat B', 100, ['txn_date' => '2026-02-02']);
        $this->line('Booked', -25, ['txn_date' => '2026-02-03', 'category_code' => 'OFFICE', 'match_status' => 'MANUAL']);
        $this->line('Old year', -999, ['txn_date' => '2025-12-31']); $this->line('Ignored one', -5, ['txn_date' => '2026-02-04', 'match_status' => 'IGNORED']);
        $q = fn (array $f) => $this->getJson('/api/bank?' . http_build_query(['show' => 'LEDGER', 'company' => 1, 'from' => '2026-01-01', 'to' => '2026-12-31'] + $f))->assertOk()->json();
        $d = $q(['category' => 'NONE', 'status' => 'UNMATCHED']);
        $this->assertEqualsCanonicalizing(['Uncat A', 'Uncat B'], array_column($d['lines'], 'description'), 'the rule example: no category yet + unmatched');
        $this->assertEquals(['lines' => 2, 'money_in' => 100, 'money_out' => 40, 'net' => 60], $d['ledger']['totals'], 'totals equal the shown rows');
        $this->assertSame(['Booked'], array_column($q(['status' => 'MANUAL'])['lines'], 'description'));
        $this->assertSame(['Booked'], array_column($q(['q' => 'office'])['lines'], 'description'), 'text finds the category name too');
        $this->assertNotContains('Old year', array_column($q([])['lines'], 'description'), 'date range');
        $def = $this->getJson('/api/bank?show=LEDGER')->json('ledger.filters'); $this->assertSame([date('Y-01-01'), date('Y-m-d')], [$def['from'], $def['to']], 'default 1 Jan → today');
    }

    public function test_r32_one_lock_and_one_run_log_line_per_job(): void
    {
        $ok = JobLog::run('FB_TEST', 1, fn () => 7); $this->assertSame(['OK', 7], [$ok['status'], (int) DB::table('sys_job_runs')->where('id', $ok['id'])->value('items_done')]);
        DB::table('sys_job_runs')->insert(['job_code' => 'FB_TEST', 'fk_id_ent' => 1, 'started_at' => now(), 'run_status' => 'RUNNING']);
        $second = JobLog::run('FB_TEST', 1, fn () => 1); $this->assertSame('LOCKED', $second['status'], 'a second run while the first runs exits locked');
        $this->assertSame('LOCKED', DB::table('sys_job_runs')->where('id', $second['id'])->value('run_status'), 'and is logged');
        $bad = JobLog::run('FB_FAIL', 1, function () { throw new \RuntimeException('boom'); }); $this->assertSame(['FAILED', 'boom'], [$bad['status'], DB::table('sys_job_runs')->where('id', $bad['id'])->value('error_text')]);
        Artisan::call('mo:finance-hourly');
        $this->assertTrue(DB::table('sys_job_runs')->where('job_code', 'FIN_HOURLY')->where('fk_id_ent', 1)->whereIn('run_status', ['OK'])->exists(), 'the hourly finance plan runs and logs');
        // live 27-11: one step that cannot finish (a missing exchange rate) no longer stops the others – the run names it
        DB::table('fin_alert_rules')->update(['is_active' => 1]);
        $b = (int) DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Yuan bank', 'account_label' => 'CNY', 'currency' => 'XQZ', 'is_active' => 1, 'opening_balance' => 100]);
        try { JobLog::financeHourly(1); $msg = null; } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
        if ($msg !== null) $this->assertStringContainsString('(the others ran)', $msg);
        DB::table('fin_bank_accounts')->where('id', $b)->delete();
    }
}
