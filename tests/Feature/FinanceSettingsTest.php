<?php

namespace Tests\Feature;

use App\Services\BankImportCheck;
use App\Services\Context;
use App\Services\FinanceSettings;
use App\Services\MoneyService as Money;
use App\Services\RecordService;
use App\Services\SettlementService;
use App\Services\VatCard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-4 company finance settings, R-FIN-6 finance system settings, R-FIN-24 check → apply of a bank file, R-FIN-63 VAT card periods. */
class FinanceSettingsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_r4_company_settings_checks_defaults_and_owner_only(): void
    {
        DB::table('fin_company_settings')->where('company_id', 1)->delete();
        $this->assertSame(1.0, FinanceSettings::num(1, 'payout_tolerance'), 'the rule\'s test: a company without its row shows payout_tolerance 1.00');
        $this->assertSame(90, FinanceSettings::int(1, 'slow_days'));
        $this->getJson('/api/settings/finance')->assertOk()->assertJsonPath('company_saved', false);
        // the rule's test: corp_tax_rate 120 → refused with the old text
        $this->postJson('/api/settings/finance/company', ['values' => ['corp_tax_rate' => 120]])->assertStatus(422)->assertJsonPath('errors.corp_tax_rate.0', 'corp_tax_rate must be between 0 and 100.');
        $this->postJson('/api/settings/finance/company', ['values' => ['distribution_schedule' => 'WEEKLY']])->assertStatus(422)->assertJsonPath('errors.distribution_schedule.0', 'Distribution schedule must be PER_PERIOD, MONTHLY or QUARTERLY.');
        $this->assertFalse(DB::table('fin_company_settings')->where('company_id', 1)->exists(), 'nothing saved when refused');
        $this->postJson('/api/settings/finance/company', ['values' => ['vat_rate' => 20, 'corp_tax_rate' => 25, 'payout_tolerance' => 2.5, 'distribution_schedule' => 'monthly']])->assertOk();
        $row = DB::table('fin_company_settings')->where('company_id', 1)->first();
        $this->assertSame([20.0, 25.0, 2.5, 'MONTHLY'], [(float) $row->vat_rate, (float) $row->corp_tax_rate, (float) $row->payout_tolerance, $row->distribution_schedule]);
        $this->assertSame(2.5, FinanceSettings::num(1, 'payout_tolerance'), 'the company row wins over the system setting');
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 'fin_company_settings')->where('record_id', 1)->where('changes_json', 'like', '%corp_tax_rate%')->exists(), 'audited');
        $this->actingAs($this->makeUser('FINANCE'))->postJson('/api/settings/finance/company', ['values' => ['vat_rate' => 0]])->assertStatus(403)->assertJsonPath('message', 'Refused: only the Owner changes settings.');
    }

    public function test_r6_system_setting_history_and_the_next_match_run_uses_it(): void
    {
        \App\Services\Settings::set('match_payout_days', '3', 0);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'FSmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'FS1', 'iso_branch' => 'FS' . random_int(100, 999), 'shop_name' => 'Settings shop', 'f_active' => 1]);
        $this->f('mo_settlements', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'statement_id' => 'FS-PAY', 'statement_date' => '2026-10-01', 'payout_date' => '2026-10-01', 'currency' => 'GBP', 'payable' => 777.77, 'status' => 'POSTED']);
        $find = fn () => SettlementService::payoutCandidates(1, 777.77, 'GBP', '2026-10-05', FinanceSettings::int(1, 'match_payout_days'));
        $this->assertCount(0, $find(), '±3 days: a deposit 4 days later is not matched');
        // the rule's test: 3 → 4 – a history row old 3 new 4; the next match run uses ±4 days
        $this->postJson('/api/settings/finance', ['key' => 'match_payout_days', 'value' => '4'])->assertOk();
        $h = collect($this->getJson('/api/settings/finance')->json('history'))->firstWhere('key', 'match_payout_days');
        $this->assertSame(['3', '4'], [(string) $h['from'], (string) $h['to']]);
        $this->assertCount(1, $find(), '±4 days now');
        // checks: unknown key, min / max, Owner only, alert_repeat_days read as at least 1
        $this->postJson('/api/settings/finance', ['key' => 'no_such_key', 'value' => '1'])->assertStatus(422)->assertJsonPath('message', 'Unknown setting no_such_key');
        $this->postJson('/api/settings/finance', ['key' => 'slow_days', 'value' => '-5'])->assertStatus(422);
        $this->postJson('/api/settings/finance', ['key' => 'statement_folder', 'value' => '../public'])->assertStatus(422);
        \App\Services\Settings::set('alert_repeat_days', '0', 0); $this->assertSame(1, FinanceSettings::int(0, 'alert_repeat_days'));
        $this->actingAs($this->makeUser('FINANCE'))->postJson('/api/settings/finance', ['key' => 'slow_days', 'value' => '30'])->assertStatus(403)->assertJsonPath('message', 'Refused: only the Owner changes settings.');
    }

    public function test_r24_reading_rules_dry_run_then_apply_by_token_and_same_file_note(): void
    {
        // the rule's test, part 1: "(1,234.50)" → −1234.50; other number and date rules
        $this->assertSame([-1234.5, 1234.5, 0.0, 0.0, -12.0], [BankImportCheck::num('(1,234.50)'), BankImportCheck::num('£1,234.50'), BankImportCheck::num('-'), BankImportCheck::num('abc'), BankImportCheck::num('€ -12')]);
        $this->assertSame(['2026-04-03', '2026-04-03', '2026-04-03', '2026-04-03'], [BankImportCheck::date('03/04/2026'), BankImportCheck::date('2026-04-03 UTC'), BankImportCheck::date('3 Apr 2026'), BankImportCheck::date('03.04.2026')]);
        $this->assertSame([";", "\t", ','], [BankImportCheck::delimiter('a;b;c'), BankImportCheck::delimiter("a\tb\tc"), BankImportCheck::delimiter('a,b;c')]);
        try { BankImportCheck::checkFile('x.pdf', 10); $this->fail('pdf'); } catch (\InvalidArgumentException $e) { $this->assertSame('Upload the report as CSV / TXT (Excel: File > Save as > CSV UTF-8).', $e->getMessage()); }
        try { BankImportCheck::checkFile('x.csv', 26 * 1024 * 1024); $this->fail('size'); } catch (\InvalidArgumentException $e) { $this->assertSame('File larger than 25 MB.', $e->getMessage()); }

        $bank = Money::createBank(1, ['bank_name' => 'Check bank', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
        $profile = (int) DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
        $csv = "\xEF\xBB\xBFDate;Description;Reference;Amount;Balance;Transaction ID\n03/10/2026;CARD SHOP;;(1.234,50);;C1\n\n04/10/2026;REFUND;;20;;C2\nnot-a-date;X;;1;;C3\n";
        $csv = str_replace('(1.234,50)', '(1234.50)', $csv);      // semicolon file, BOM, a blank line
        $c = $this->postJson("/api/bank/$bank/import/check", ['profile' => $profile, 'csv' => $csv, 'file_name' => 'oct.csv'])->assertOk()->json();
        $this->assertSame([2, 0, 1, null], [$c['new'], $c['duplicates'], $c['error_count'], $c['same_file']]);
        $this->assertSame(-1234.5, $c['sample'][0]['amount']); $this->assertSame('2026-10-03', $c['sample'][0]['txn_date']);
        $this->assertSame(0, DB::table('fin_bank_transactions')->where('bank_account_id', $bank)->count(), 'the dry run writes nothing');
        // another login cannot use the token
        $this->actingAs($this->makeUser('FINANCE'))->postJson('/api/bank/import/apply', ['token' => $c['token']])->assertStatus(422)->assertJsonPath('message', BankImportCheck::EXPIRED);
        $this->actingAs($this->owner);
        $this->postJson('/api/bank/import/apply', ['token' => $c['token']])->assertOk()->assertJsonPath('new', 2);
        $this->postJson('/api/bank/import/apply', ['token' => $c['token']])->assertStatus(422)->assertJsonPath('message', BankImportCheck::EXPIRED);      // deleted after the load
        // the rule's test, part 2: the same file again shows the earlier import date; only the Owner loads it again on purpose
        $c2 = $this->postJson("/api/bank/$bank/import/check", ['profile' => $profile, 'csv' => $csv, 'file_name' => 'oct-again.csv'])->assertOk()->json();
        $this->assertStringStartsWith('This exact file was imported on ' . date('Y-m-d'), $c2['same_file']['text']); $this->assertSame(2, $c2['duplicates']);
        $this->postJson('/api/bank/import/apply', ['token' => $c2['token']])->assertStatus(422);
        $this->postJson('/api/bank/import/apply', ['token' => $c2['token'], 'again' => true])->assertOk()->assertJsonPath('duplicates', 2);
        $this->assertSame(2, DB::table('fin_bank_transactions')->where('bank_account_id', $bank)->count(), 'lines never load twice');
    }

    public function test_r63_vat_card_periods_follow_the_stagger_and_sum_output_and_input_vat(): void
    {
        $co = $this->f('cat_company_house', ['company_name' => 'VAT Co', 'f_active' => 1]);
        DB::table('acc_company_settings')->where('fk_id_ent', 1)->delete();
        DB::table('acc_company_settings')->insert(['fk_id_ent' => 1, 'fk_id_company' => $co, 'vat_registered' => 1, 'vat_frequency' => 'Q', 'vat_stagger' => 2]);
        // the rule's test: stagger 2 → Feb–Apr, May–Jul …
        $p = VatCard::periods(1, '2026-06-10', 3);
        $this->assertSame([['2026-05-01', '2026-07-31'], ['2026-02-01', '2026-04-30'], ['2025-11-01', '2026-01-31']], array_map(fn ($x) => [$x['from'], $x['to']], $p));
        DB::table('acc_company_settings')->where('fk_id_ent', 1)->update(['vat_stagger' => 1]);
        $this->assertSame(['2026-04-01', '2026-06-30'], array_values(VatCard::periods(1, '2026-06-10', 1)[0]));
        DB::table('acc_company_settings')->where('fk_id_ent', 1)->update(['vat_frequency' => 'M']);
        $this->assertSame(['2026-06-01', '2026-06-30'], array_values(VatCard::periods(1, '2026-06-10', 1)[0]));
        // output VAT from settlement TAX lines (converted), input VAT from OUT bank lines (DUTY_VAT / flag), Ignored left out
        $this->f('fin_settlement_lines', ['batch_id' => 0, 'company_id' => 1, 'branch_id' => 0, 'settlement_id' => 'V1', 'bucket' => 'TAX', 'amount' => 200, 'currency' => 'GBP', 'posted_date' => '2026-06-05', 'row_hash' => sha1(uniqid())]);
        $bank = Money::createBank(1, ['bank_name' => 'VAT bank', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 0, 'opening_date' => '2026-01-01']);
        $line = fn (float $a, array $x) => DB::table('fin_bank_transactions')->insert($x + ['batch_id' => 0, 'company_id' => 1, 'bank_account_id' => $bank, 'bank_txn_id' => 'V' . uniqid(), 'txn_date' => '2026-06-07', 'description' => 'VAT', 'amount' => $a, 'amount_gbp' => $a, 'currency' => 'GBP', 'match_status' => 'MANUAL']);
        $line(-50, ['category_code' => 'DUTY_VAT']); $line(-30, ['vat_flag' => 1]); $line(-99, ['vat_flag' => 1, 'match_status' => 'IGNORED']); $line(-10, []);
        $c = VatCard::card(1, '2026-06-01', '2026-06-30');
        $this->assertSame([200.0, 80.0, 120.0], [$c['output_vat'], $c['input_vat'], $c['net']]);
        $this->assertSame('Accountancy Hub', $c['plan']['source']);
    }
}
