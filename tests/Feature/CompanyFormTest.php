<?php

namespace Tests\Feature;

use App\Services\AccHub;
use App\Services\Choices;
use App\Services\Context;
use App\Services\Registry;
use App\Services\RecordService;
use App\Services\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-35: company form – one field per fact, typed codes, no fake dates; Accountancy Hub values read-only (IC-17). */
class CompanyFormTest extends TestCase
{
    private function base(array $x = []): array
    {
        return $x + ['company_name' => 'acme trading ltd', 'fk_country_id' => 1, 'iso_code' => 'ac me', 'registration_no' => 'sc123456', 'company_house_telephone' => '0303 1234 500', 'company_email_1' => 'enquiries@companieshouse.gov.uk', 'company_address' => 'Companies House Crown Way Cardiff CF14 3UZ'];
    }

    private function errors(array $in, ?int $id = null): array { try { RecordService::save('cat_company_house', $in, $id); return []; } catch (ValidationException $e) { return array_map(fn ($m) => $m[0], $e->errors()); } }

    public function test_the_rule_example_codes_typed_upper_in_the_vault_and_no_fake_dates(): void
    {
        $this->actingAs($this->owner);
        $r = RecordService::save('cat_company_house', $this->base(['hmrc_activation_code' => 'abcd 1234 efgh', 'authentication_code' => 'ab12cd', 'company_utr' => '12345 67890', 'vat_no' => 'gb 123 456 789', 'director_1_share' => '60', 'director_2_share' => '40']));
        $c = DB::table('cat_company_house')->where('id', $r['id'])->first();
        $this->assertSame(['Acme trading ltd', 'ACME', 'SC123456', '••••••890', 'GB123456789'], [$c->company_name, $c->iso_code, $c->registration_no, $c->company_utr, $c->vat_no]);      /* the UTR is a vault secret since R-ACC-16 */
        $this->assertSame('1234567890', \Illuminate\Support\Facades\Crypt::decryptString(DB::table('sys_vault')->where(['table_name' => 'cat_company_house', 'record_id' => $c->id, 'column_name' => 'company_utr'])->value('cipher')));
        $this->assertTrue(Vault::isMasked($c->hmrc_activation_code)); $this->assertSame('ABCD1234EFGH', Vault::reveal('cat_company_house', $r['id'], 'hmrc_activation_code')['value']);      // typed, upper, in the vault – no number generated
        $this->assertSame([null, null, null], [$c->incorporate_date, $c->confirmation_statement_date, $c->file_return_date]);      // no "today" by itself
        $this->assertSame(0, DB::table('tasks')->where('linked_module', 'cat_company_house')->where('linked_record_id', $r['id'])->count());
        $due = AccHub::due($c, null); $this->assertSame([null, null], [$due['cs'], $due['accounts']]);
    }

    public function test_every_typed_check_and_the_shares(): void
    {
        $this->actingAs($this->owner);
        $e = $this->errors(['company_name' => 'X']);
        foreach (['fk_country_id', 'iso_code', 'registration_no', 'company_house_telephone', 'company_email_1', 'company_address'] as $f) $this->assertArrayHasKey($f, $e, $f);
        $e = $this->errors($this->base(['company_utr' => '123', 'authentication_code' => 'abc', 'hmrc_activation_code' => 'short', 'vat_no' => 'FR123', 'eori_no' => 'GB123', 'registration_no' => '1234', 'director_1_share' => '70', 'director_2_share' => '40', 'director_3_share' => '101']));
        $this->assertSame('UTR must be 10 digits.', $e['company_utr']); $this->assertStringContainsString('6 letters or digits', $e['authentication_code']); $this->assertStringContainsString('12 letters or digits', $e['hmrc_activation_code']);
        $this->assertStringContainsString('GB followed by 9 or 12 digits', $e['vat_no']); $this->assertStringContainsString('GB followed by 12 digits', $e['eori_no']); $this->assertStringContainsString('8 characters', $e['registration_no']); $this->assertSame('Director 3 Share cannot be more than 100.', $e['director_3_share']);
        $e = $this->errors($this->base(['director_1_share' => '70', 'director_2_share' => '40.5'])); $this->assertSame('Director shares add up to 110.5 % – must not exceed 100 %.', $e['director_1_share']);
        $id = RecordService::save('cat_company_house', $this->base(['eori_no' => 'gb123456789012']))['id']; $this->assertSame('GB123456789012', DB::table('cat_company_house')->where('id', $id)->value('eori_no'));
        $this->assertSame('This ISO code already exists. Please add another', $this->errors($this->base(['company_name' => 'Other', 'iso_code' => 'acme']))['iso_code']);
        $this->assertSame([], $this->errors(['company_utr' => '1234567890'], $id));      // edit: the same company keeps its own ISO code, untouched required fields stay
        $live = $this->get('/api/form/cat_company_house/live?field=iso_code&value=acme')->json(); $this->assertSame([false, 'This ISO code already exists. Please add another'], [$live['ok'], $live['message']]);
        $this->assertSame('GB123456789', $this->get('/api/form/cat_company_house/live?field=vat_no&value=gb%20123456789')->json('value'));
        $def = Registry::definition('cat_company_house', 'OWNER'); $req = collect($def['sections'])->flatMap(fn ($s) => $s['fields'])->where('required', true)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['company_name', 'fk_country_id', 'iso_code', 'registration_no', 'company_house_telephone', 'company_email_1', 'company_address'], $req);

        Choices::set('COMPANY_FORM_CHECKS', 'OLD', 1);      // as the old tool: only the name
        $this->assertSame([], $this->errors(['company_name' => 'Old style', 'company_utr' => '12 3', 'iso_code' => 'acme']));
        $this->assertSame('Company Name is required.', $this->errors(['company_utr' => '1'])['company_name']);
    }

    public function test_gateway_and_codes_are_owner_only(): void
    {
        $this->actingAs($this->owner); $id = RecordService::save('cat_company_house', $this->base(['authentication_code' => 'AB12CD']))['id'];
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); Context::set(1, $m->id);
        $names = collect(Registry::definition('cat_company_house', 'MANAGER')['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('name')->all();
        foreach (['authentication_code', 'gateway_id', 'gateway_pass', 'hmrc_activation_code'] as $f) $this->assertNotContains($f, $names);      // not rendered
        try { RecordService::save('cat_company_house', ['authentication_code' => 'ZZ99ZZ', 'company_utr' => '1234567890'], $id); } catch (\Throwable $e) { }
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $this->assertSame('AB12CD', Vault::reveal('cat_company_house', $id, 'authentication_code')['value']);      // not accepted on save from others
    }

    public function test_hub_values_are_kept_once_and_the_due_dates(): void
    {
        $this->actingAs($this->owner);
        $id = RecordService::save('cat_company_house', $this->base(['incorporate_date' => '2025-03-10']))['id']; $co = fn () => DB::table('cat_company_house')->where('id', $id)->first();
        $d = AccHub::due($co(), null, '2026-01-01'); $this->assertSame(['2026-03-10', '2026-03-24'], [$d['cs']['review'], $d['cs']['due']]);      // no anchor: incorporation + 12 months, + 14 days
        try { AccHub::save($id, ['year_end_day' => '31'], $this->owner); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('year_end_day', $e->errors()); }
        AccHub::save($id, ['year_end_day' => 31, 'year_end_month' => 3, 'cs_review_date' => '2026-04-01', 'cs_date_is' => 'DUE', 'vat_registered' => 1, 'vat_frequency' => 'Q', 'vat_stagger' => 2, 'vat_scheme' => 'Flat Rate'], $this->owner);
        $d = AccHub::due($co(), AccHub::row($id), '2026-01-01');
        $this->assertSame(['2026-03-18', '2026-04-01'], [$d['cs']['review'], $d['cs']['due']]);      // the hub date is the due date: review = − 14 days
        $this->assertSame(['2026-03-31', '2026-12-10'], [$d['accounts']['period_end'], $d['accounts']['due']]);      // first period over 12 months: later of inc + 21 months and end + 3 months
        $this->assertSame('2027-12-31', AccHub::due($co(), AccHub::row($id), '2027-01-01')['accounts']['due']);      // next period: end + 9 months
        DB::table('cat_company_house')->where('id', $id)->update(['file_return_date' => '2026-12-01']); $this->assertSame('2026-12-01', AccHub::due($co(), AccHub::row($id), '2026-01-01')['accounts']['due']);      // Companies House date wins within 45 days
        DB::table('cat_company_house')->where('id', $id)->update(['f_external' => 1]); $this->assertTrue(AccHub::due($co(), AccHub::row($id))['external']);
        DB::table('cat_company_house')->where('id', $id)->update(['f_external' => 0]);
        $hub = collect(RecordService::page('cat_company_house', $id)['sections']['Accountancy Hub (read-only here)'])->keyBy('label');
        $this->assertSame(['31 March', 'Flat Rate', 'Quarterly – Apr / Jul / Oct / Jan', '#/acc-company/' . $id], [$hub['Year end']['value'], $hub['VAT scheme']['value'], $hub['VAT quarters']['value'], $hub['Kept in']['value']]);
        $acct = (int) \App\Models\User::create(['login_id' => 'ada@test', 'password_hash' => bcrypt('x-password-1'), 'role_code' => 'ACCOUNTANT', 'display_name' => 'Ada Books', 'fk_id_ent' => 1, 'f_active' => 1])->id; \App\Services\AccHubAdmin::saveMember(1, $acct, ['is_accountant' => 1], $this->owner); AccHub::save($id, ['year_end_day' => 31, 'year_end_month' => 3, 'vat_registered' => 1, 'vat_scheme' => 'Flat Rate', 'vat_stagger' => 2, 'fk_accountant' => $acct, 'cs_review_date' => '2026-04-01', 'cs_date_is' => 'DUE'], $this->owner);
        $this->assertSame('Ada Books', collect(RecordService::page('cat_company_house', $id)['sections']['Accountancy Hub (read-only here)'])->firstWhere('label', 'Accountant')['value']);
        $pg = AccHub::page($id, $this->owner); $this->assertSame(['DUE', 'Ada Books'], [$pg['values']['cs_date_is'], collect($pg['accountants'])->firstWhere('id', $acct)['name']]);      /* the accountant is a login of the Hub (R-ACC-17) */
        $this->assertFalse(collect(Registry::fields('cat_company_house'))->contains(fn ($f) => in_array($f['field_name'], ['year_end', 'vat_scheme', 'vat_quarter', 'accountant'], true)));      // never stored twice on the company
        $this->assertSame(1, (int) collect(Registry::fields('cat_company_house'))->firstWhere('field_name', 'confirmation_statement_date')['readonly']);
    }
}
