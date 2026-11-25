<?php

namespace Tests\Feature;

use App\Services\ExternalContact;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-10: External Contact quick form behaviour (roles pills, payment terms, repeatable rows, banks, company, PayPal). */
class ExternalContactTest extends TestCase
{
    private array $x = [];

    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid();
        $this->x = [
            'vendor' => $this->f('cat_account_types', ['account_type_name' => "Vendor $u", 'role_code' => 'V' . substr($u, -6), 'iso_account_type' => 'V' . substr($u, -6), 'is_ec' => 1, 'is_paid' => 0, 'sort' => 1]),
            'contractor' => $this->f('cat_account_types', ['account_type_name' => "Contractor $u", 'role_code' => 'C' . substr($u, -6), 'iso_account_type' => 'C' . substr($u, -6), 'is_ec' => 1, 'is_paid' => 1, 'sort' => 7]),
            'zother' => $this->f('cat_account_types', ['account_type_name' => "Auditor $u", 'role_code' => 'A' . substr($u, -6), 'iso_account_type' => 'A' . substr($u, -6), 'is_ec' => 0, 'is_paid' => 0]),
            'uk' => DB::table('cat_country')->where('country_name', 'United Kingdom')->value('id') ?: $this->f('cat_country', ['country_name' => 'United Kingdom', 'iso_code' => 'GB', 'country_code' => 44]),
            'pk' => DB::table('cat_country')->where('country_name', 'Pakistan')->value('id') ?: $this->f('cat_country', ['country_name' => 'Pakistan', 'iso_code' => 'PK', 'country_code' => 92]),
            'mp' => $this->f('cat_marketplaces', ['marketplace_short' => 'EB' . substr($u, -4), 'marketplace_descr' => "eBay $u"]),
            'gbp' => DB::table('cat_currency')->where('currency_code', 'GBP')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'GBP', 'currency_name' => 'Pound']),
        ];
        $this->x['hsbc'] = $this->f('cat_banks', ['bank_name' => "HSBC $u", 'bank_short_name' => 'HSBC', 'country_id' => $this->x['uk']]);
        $this->x['meezan'] = $this->f('cat_banks', ['bank_name' => "Meezan $u", 'bank_short_name' => 'MZ', 'country_id' => $this->x['pk']]);
    }

    private function base(array $over = []): array
    {
        return array_replace(['account_code' => '', 'first_name' => 'Ali', 'sur_name' => 'Khan', 'current_country' => $this->x['uk'], 'roles' => [$this->x['vendor']], 'mobiles' => [['mobile_no' => '+44 7700 900123']]], $over);
    }

    public function test_the_rule_example_paid_role_needs_the_marketplace(): void
    {
        $this->actingAs($this->owner);
        $m = $this->getJson('/api/people/external/new')->assertOk()->json();
        $ids = array_column($m['roles'], 'id'); $pv = array_search($this->x['vendor'], $ids); $pc = array_search($this->x['contractor'], $ids); $po = array_search($this->x['zother'], $ids);
        $this->assertTrue($pv < $pc && $pc < $po, 'is_ec roles first in sort order, the rest after');
        $this->assertSame($this->x['uk'], $m['default_country']);
        $this->assertMatchesRegularExpression('/-EX$/', $m['code']);

        // Contractor (paid) + salary 50000 without marketplace → the old message
        $r = $this->postJson('/api/people/external/new', $this->base(['account_code' => $m['code'], 'roles' => [$this->x['contractor']], 'job_salary' => '50000', 'job_start_date' => '2026-11-01']))->assertStatus(422);
        $this->assertSame(['Please choose the marketplace this person works on.'], $r->json('errors.job_marketplace'));
        $this->assertSame('Please fix 1 thing(s) before saving.', $r->json('message'));
        // decimals in the salary
        $r = $this->postJson('/api/people/external/new', $this->base(['account_code' => $m['code'], 'roles' => [$this->x['contractor']], 'job_marketplace' => $this->x['mp'], 'job_start_date' => '2026-11-01', 'job_salary' => '500.5']))->assertStatus(422);
        $this->assertSame(['Salary / Rate: whole number only (no decimals or symbols).'], $r->json('errors.job_salary'));
        // terms typed while no paid role is ticked are ignored
        $id = $this->postJson('/api/people/external/new', $this->base(['account_code' => $m['code'], 'job_salary' => '50000']))->assertOk()->json('id');
        $this->assertSame(0, DB::table('t_account_job_history')->where('fk_id_account', $id)->count());
        $this->assertSame('E', DB::table('t_account')->where('id', $id)->value('l_account_kind'));

        // with the marketplace → one job history row, renewal / share / rate blank, dates NULL when blank
        $code2 = $this->getJson('/api/people/external/new')->json('code');
        // R-C-8 the rule test: the same mobile typed another way ("07700 900123" / "+44 7700 900123") → the look-alike panel with reason "mobile"; Save anyway without a reason is refused; with a reason it saves and the history shows it
        $second = $this->base(['account_code' => $code2, 'roles' => [$this->x['contractor'], $this->x['vendor']], 'job_marketplace' => $this->x['mp'], 'job_start_date' => '2026-11-01', 'job_salary' => '50000', 'mobiles' => [['mobile_no' => '07700 900123']]]);
        $dup = $this->postJson('/api/people/external/new', $second)->assertStatus(409)->json(); $this->assertSame(['duplicate', $id], [$dup['status'], $dup['people'][0]['id']]);
        $this->assertContains('name', $dup['people'][0]['reasons']); $this->assertTrue((bool) array_filter($dup['people'][0]['reasons'], fn ($x) => str_starts_with($x, 'mobile ')), 'last 9 digits match');
        $this->postJson('/api/people/external/new', $second + ['dup_reason' => ' '])->assertStatus(409); $this->postJson('/api/people/external/new', $second + ['dup_reason' => 'ok'])->assertStatus(422)->assertJsonPath('message', 'Say why it is saved anyway.');
        $r = $this->postJson('/api/people/external/new', $second + ['dup_reason' => 'Two brothers share the phone'])->assertOk();
        $this->assertStringContainsString('Two brothers share the phone', (string) DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $r->json('id'))->orderByDesc('id')->value('reason'));
        $this->assertSame(1, DB::table('sys_merge_map')->where('entity', 'PARTY')->where('source_id', $r->json('id'))->where('master_id', $id)->count(), 'the pair is a hint of the merge scan');
        $j = DB::table('t_account_job_history')->where('id', $r->json('job_id'))->first();
        $this->assertSame([$this->x['mp'], '2026-11-01', '50000.00', null, null, null], [(int) $j->fk_id_marketplace, $j->d_job_start_date, $j->salary, $j->salary_date, $j->share_percentage, $j->d_contract_renewal_date]);
        $this->assertSame(2, DB::table('t_account_in_types')->where('fk_id_account', $r->json('id'))->count());
    }

    public function test_rows_banks_company_paypal_and_checks(): void
    {
        $this->actingAs($this->owner);
        $code = ExternalContact::meta(1)['code'];
        // no role, bad title, no reach, bank row without a bank, new bank without a name, inactive currency, IBAN wrong
        $e = $this->postJson('/api/people/external/new', ['account_code' => $code, 'first_name' => 'A', 'sur_name' => 'B', 'current_country' => $this->x['uk'], 'title' => 'Sir',
            'banks' => [['account_no' => '12345678'], ['bank_id' => 'new', 'iban' => 'GB00XXXX'], ['bank_id' => '', 'account_no' => '', 'iban' => '']], 'company_mode' => 'new', 'paypal_name' => 'Ali'])->assertStatus(422)->json('errors');
        $this->assertSame('Please pick at least one role (for example Vendor).', $e['roles'][0]);
        $this->assertSame('Please choose a title.', $e['title'][0]);
        $this->assertSame('Add at least a mobile number or an email, so we can reach this person.', $e['mobiles[0][mobile_no]'][0]);
        $this->assertSame('Please choose the bank (or "+ New bank").', $e['banks[0][bank_id]'][0]);
        $this->assertSame('Type the new bank name.', $e['banks[1][new_bank_name]'][0]);
        $this->assertSame('This IBAN does not look right. Please check it.', $e['banks[1][iban]'][0]);
        $this->assertArrayNotHasKey('banks[2][bank_id]', $e, 'an empty bank row is ignored');
        $this->assertSame('Company Name is required (or choose "No company").', $e['company_name'][0]);
        $this->assertSame('Please enter the PayPal email address.', $e['paypal_email'][0]);
        // a company that is not external is refused
        $own = $this->f('cat_company_house', ['company_name' => 'Own Ltd', 'f_external' => 0]);
        $this->assertSame(['Please choose the company.'], $this->postJson('/api/people/external/new', $this->base(['account_code' => $code, 'company_mode' => 'existing', 'company_id' => $own]))->assertStatus(422)->json('errors.company_id'));

        // the full save: rows with no country take the current country (Pakistan); a row set to UK stays UK
        $r = $this->postJson('/api/people/external/new', $this->base(['account_code' => $code, 'title' => 'Mr', 'current_country' => $this->x['pk'],
            'addresses' => [['line1' => '1 Mall Road', 'counti' => 'Lahore'], ['line1' => '2 High St', 'country' => $this->x['uk'], 'post_code' => 'sw1a 1aa']],
            'mobiles' => [['mobile_no' => '0300 1234567'], ['mobile_no' => '']], 'emails' => [['email' => 'Ali@Example.test']],
            'banks' => [['bank_id' => (string) $this->x['meezan'], 'account_no' => '0101 2345', 'currency_id' => $this->x['gbp']], ['bank_id' => 'new', 'new_bank_name' => 'Bank Alfalah X', 'iban' => 'GB82 WEST 1234 5698 7654 32']],
            'company_mode' => 'new', 'company_name' => 'Ali Traders', 'company_vat' => 'gb123', 'paypal_name' => 'Ali K', 'paypal_email' => 'pay@example.test']))->assertOk();
        $id = $r->json('id');
        $this->assertSame([$this->x['pk'], $this->x['uk']], DB::table('t_account_addresses')->where('fk_id_account', $id)->orderBy('id')->pluck('fk_id_country')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('SW1A 1AA', DB::table('t_account_addresses')->where('fk_id_account', $id)->orderByDesc('id')->value('post_code'));
        $this->assertSame([$this->x['pk']], DB::table('t_account_mobiles')->where('fk_id_account', $id)->pluck('fk_id_country')->map(fn ($x) => (int) $x)->all(), 'the empty mobile row is ignored');
        $this->assertSame(['ali@example.test', 'pay@example.test'], DB::table('t_account_emails')->where('fk_id_account', $id)->orderBy('id')->pluck('email')->all(), 'the PayPal e-mail is added to E-mails');
        $co = DB::table('cat_company_house')->where('id', $r->json('company_id'))->first();
        $this->assertSame(['Ali Traders', 'GB123', 1, $this->x['pk']], [$co->company_name, $co->vat_no, (int) $co->f_external, (int) $co->fk_country_id]);
        $banks = DB::table('t_account_banks')->where('fk_id_account', $id)->orderBy('id')->get();
        $this->assertSame(['01012345', 'Business', $this->x['gbp']], [$banks[0]->account_no, $banks[0]->account_type, (int) $banks[0]->fk_currency_id]);
        $newBank = DB::table('cat_banks')->where('id', $banks[1]->fk_bank_id)->first();
        $this->assertSame(['Bank Alfalah X', $this->x['pk']], [$newBank->bank_name, (int) $newBank->country_id]);
        $this->assertSame('GB82WEST12345698765432', $banks[1]->iban_no);
        $this->assertSame((int) DB::table('t_account_emails')->where('fk_id_account', $id)->where('email', 'pay@example.test')->value('id'), (int) DB::table('t_account_paypal')->where('fk_id_account', $id)->value('linked_email'));
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $id)->exists());

        // a second person at the same supplier links the existing external company; the same new bank name in Pakistan is reused
        $r2 = $this->postJson('/api/people/external/new', $this->base(['account_code' => ExternalContact::meta(1)['code'], 'first_name' => 'Sara', 'sur_name' => 'Malik', 'mobiles' => [['mobile_no' => '+92 300 5550001']], 'current_country' => $this->x['pk'], 'company_mode' => 'existing', 'company_id' => $r->json('company_id'),
            'banks' => [['bank_id' => 'new', 'new_bank_name' => 'bank alfalah x', 'account_no' => '998877']]]))->assertOk();
        $this->assertSame((int) $banks[1]->fk_bank_id, (int) DB::table('t_account_banks')->where('fk_id_account', $r2->json('id'))->value('fk_bank_id'));
        $this->assertTrue(DB::table('t_account_company_houses')->where('fk_id_account', $r2->json('id'))->where('fk_company_house_id', $r->json('company_id'))->exists());
        // the code is checked as on every person form
        $this->assertStringStartsWith('Account Code ' . $code . ' is already used', $this->postJson('/api/people/external/new', $this->base(['account_code' => $code]))->assertStatus(422)->json('errors.account_code.0'));
    }
}
