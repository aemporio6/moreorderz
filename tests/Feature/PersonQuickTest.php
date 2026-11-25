<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ExternalContact;
use App\Services\PartyDefaults;
use App\Services\PersonRoles;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-9 one-transaction quick save + status contract, R-C-11 live section status, R-C-12 derived defaults on both forms, R-C-16 roles saved as a difference. */
class PersonQuickTest extends TestCase
{
    private array $x = [];
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    protected function setUp(): void
    {
        parent::setUp(); $u = uniqid();
        foreach (['a', 'b', 'c'] as $k) $this->x[$k] = $this->f('cat_account_types', ['account_type_name' => strtoupper($k) . " role $u", 'role_code' => strtoupper($k) . substr($u, -6), 'iso_account_type' => strtoupper($k) . substr($u, -6), 'is_ec' => 1, 'is_paid' => 0, 'sort' => 1, 'f_active' => 1]);
        $this->x['uk'] = DB::table('cat_country')->where('country_name', 'United Kingdom')->value('id') ?: $this->f('cat_country', ['country_name' => 'United Kingdom', 'iso_code' => 'GB', 'country_code' => 44, 'f_active' => 1]);
        $this->x['bank'] = $this->f('cat_banks', ['bank_name' => "Quick Bank $u", 'bank_short_name' => 'QB', 'country_id' => $this->x['uk'], 'f_active' => 1]);
        $this->x['u'] = $u;
    }

    private function base(array $over = []): array
    {
        return array_replace(['account_code' => \App\Services\AccountCode::suggest(1, true), 'first_name' => 'Quick', 'sur_name' => 'Save' . $this->x['u'], 'current_country' => $this->x['uk'], 'roles' => [$this->x['a']], 'addresses' => [['line1' => '1 High St', 'post_code' => 'AB1 2CD', 'country' => $this->x['uk'], 'type' => 'O']],
            'mobiles' => [['mobile_no' => '+44 7700 9' . random_int(10000, 99999), 'country' => $this->x['uk'], 'type' => 'O'], ['mobile_no' => '+44 7700 8' . random_int(10000, 99999), 'country' => $this->x['uk'], 'type' => 'O']], 'emails' => [['email' => 'q' . $this->x['u'] . '@example.test']],
            'banks' => [['country' => $this->x['uk'], 'bank_id' => $this->x['bank'], 'account_no' => '12345678', 'sort_code' => '10-20-30']]], $over);
    }

    public function test_c9_nothing_is_saved_when_a_step_fails_and_the_status_contract(): void
    {
        $this->actingAs($this->owner);
        $n = fn () => [DB::table('t_account')->count(), DB::table('t_account_addresses')->count(), DB::table('t_account_mobiles')->count(), DB::table('t_account_in_types')->count()];
        $before = $n();
        ExternalContact::$failAt = 'bank';
        try { $r = $this->postJson('/api/people/external/new', $this->base())->assertStatus(500)->json(); } finally { ExternalContact::$failAt = null; }
        $this->assertSame('error', $r['status']); $this->assertStringStartsWith('Nothing was saved.', $r['message']);
        $this->assertSame($before, $n(), 'no person row, no address row');
        $this->assertSame('invalid', $this->postJson('/api/people/external/new', $this->base(['first_name' => '']))->assertStatus(422)->json('status'));
        $ok = $this->postJson('/api/people/external/new', $this->base())->assertOk()->json();
        $code = DB::table('t_account')->where('id', $ok['id'])->value('account_code');
        $this->assertSame(['ok', "External Contact $code saved.", '#/rec/external_contact/' . $ok['id']], [$ok['status'], $ok['message'], $ok['open_route']]);
        $this->assertSame('duplicate', $this->postJson('/api/people/external/new', $this->base())->assertStatus(409)->json('status'));
        $this->getJson('/api/people/external/new')->assertOk()->assertJsonMissingPath('id');
    }

    public function test_c11_counter_of_required_sections(): void
    {
        $this->actingAs($this->owner);
        $in = ['account_code' => 'X-EX', 'first_name' => 'A', 'sur_name' => 'B', 'current_country' => $this->x['uk'], 'roles' => [$this->x['a']], 'mobiles' => [['mobile_no' => '']], 'emails' => ['']];
        $p = $this->postJson('/api/people/external/progress', $in)->assertOk()->json();
        $this->assertSame('2 of 3 required sections done', $p['counter']);
        $st = array_column($p['sections'], 'status', 'key'); $this->assertSame(['done', 'done', 'required', 'optional'], [$st['roles'], $st['profile'], $st['reach'], $st['banks']]);
        $this->assertSame('3 of 3 required sections done', $this->postJson('/api/people/external/progress', ['emails' => ['a@b.test']] + $in)->json('counter'));
        $this->assertSame('3 of 3 required sections done', ExternalContact::progress(['mobiles' => [['mobile_no' => '0770']]] + $in)['counter'], 'mobile and e-mail share the one REACH rule');
    }

    public function test_c12_derived_defaults_quick_and_full(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $r = $this->postJson('/api/people/external/new', $this->base(['company_mode' => 'new', 'company_name' => 'Quick Co ' . $this->x['u'], 'paypal_email' => 'pp' . $this->x['u'] . '@example.test']))->assertOk()->json();
        $p = (int) $r['id']; $m1 = (int) DB::table('t_account_mobiles')->where('fk_id_account', $p)->orderBy('id')->value('id'); $a1 = (int) DB::table('t_account_addresses')->where('fk_id_account', $p)->orderBy('id')->value('id');
        $e1 = (int) DB::table('t_account_emails')->where('fk_id_account', $p)->orderBy('id')->value('id');
        $bank = DB::table('t_account_banks')->where('fk_id_account', $p)->first();
        $link = (int) DB::table('t_account_company_houses')->where('fk_id_account', $p)->where('fk_company_house_id', $r['company_id'])->value('id');
        $this->assertSame(['Business', $m1, $a1, $e1, $link], [$bank->account_type, (int) $bank->linked_mobile, (int) $bank->linked_address, (int) $bank->linked_email, (int) $bank->fk_id_company], 'rule example: Business, first mobile');
        $pp = DB::table('t_account_paypal')->where('fk_id_account', $p)->first();
        $this->assertSame(['Business', 'Quick Save' . $this->x['u'], $m1, $m1, $a1, $this->x['uk']], [$pp->paypal_account_type, $pp->paypal_name, (int) $pp->linked_mobile, (int) $pp->registered_mobile_id, (int) $pp->linked_address, (int) $pp->country_id]);
        $this->assertSame((int) DB::table('t_account_emails')->where('fk_id_account', $p)->where('email', 'pp' . $this->x['u'] . '@example.test')->value('id'), (int) $pp->linked_email, 'PayPal e-mail row listed in E-mails');
        $acc = DB::table('t_account')->where('id', $p)->first(); $this->assertSame((int) $acc->current_nationality, (int) $acc->old_nationality);
        $co = DB::table('cat_company_house')->where('id', $r['company_id'])->first(); $this->assertSame('1 High St, AB1 2CD', $co->company_address); $this->assertSame($m1, (int) $co->fk_director_1_reg_mobile);
        $this->assertSame($a1, (int) DB::table('t_account_mobiles')->where('id', $m1)->value('fk_id_address')); $this->assertSame($m1, (int) DB::table('t_account_emails')->where('id', $e1)->value('fk_id_mobile'));

        // the Full forms give the same defaults (registry rules), and a typed value wins
        $this->assertSame($m1, PartyDefaults::value('rule:firstOwn:t_account_mobiles', $p)); $this->assertSame('Business', PartyDefaults::value('rule:businessIfCompany', $p));
        $full = RecordService::save('t_account_bank', ['fk_id_account' => $p, 'country_id' => $this->x['uk'], 'fk_bank_id' => $this->x['bank'], 'account_no' => '87654321', 'sort_branch_code' => '10-20-31']);
        $b2 = DB::table('t_account_banks')->where('id', $full['id'])->first();
        $this->assertSame(['Business', $m1, $e1, $a1, $link], [$b2->account_type, (int) $b2->linked_mobile, (int) $b2->linked_email, (int) $b2->linked_address, (int) $b2->fk_id_company]);
        $m2 = (int) DB::table('t_account_mobiles')->where('fk_id_account', $p)->orderByDesc('id')->value('id');
        $full = RecordService::save('t_account_bank', ['fk_id_account' => $p, 'country_id' => $this->x['uk'], 'fk_bank_id' => $this->x['bank'], 'account_no' => '87654322', 'sort_branch_code' => '10-20-32', 'linked_mobile' => $m2, 'account_type' => 'Personal']);
        $b3 = DB::table('t_account_banks')->where('id', $full['id'])->first(); $this->assertSame([$m2, 'Personal'], [(int) $b3->linked_mobile, $b3->account_type], 'defaults can be changed');
        $chips = $this->getJson('/api/suggest/t_account_bank/linked_mobile?form[fk_id_account]=' . $p)->assertOk()->json('chips');
        $this->assertStringContainsString("default: the person's first mobile", json_encode($chips));
        $solo = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SO' . random_int(1000, 9999), 'first_name' => 'Solo', 'sur_name' => 'P' . $this->x['u'], 'f_active' => 1]);
        $this->assertSame('Personal', PartyDefaults::value('rule:businessIfCompany', $solo));
    }

    public function test_c16_roles_saved_as_a_difference(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $form = RecordService::formOfTable('t_account');
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'RD' . random_int(1000, 9999), 'first_name' => 'Role', 'sur_name' => 'Diff' . $this->x['u'], 'f_active' => 1]);
        $ra = $this->f('t_account_in_types', ['fk_id_account' => $p, 'fk_id_account_type' => $this->x['a'], 'f_active' => 1]); $rb = $this->f('t_account_in_types', ['fk_id_account' => $p, 'fk_id_account_type' => $this->x['b'], 'f_active' => 1]);
        $this->assertEqualsCanonicalizing([$this->x['a'], $this->x['b']], $this->getJson("/api/record/$form/$p")->assertOk()->json('values')['account_role[]']);
        $ev = fn ($c) => DB::table('sys_events')->where('event_code', $c)->where('entity_id', $p)->count();
        $res = $this->postJson("/api/record/$form/$p", ['values' => ['account_role[]' => [$this->x['b'], $this->x['c']]]])->assertOk()->json();
        $this->assertSame(['added' => 1, 'removed' => 1, 'kept' => 1], $res['roles']);
        $this->assertSame($rb, (int) DB::table('t_account_in_types')->where('fk_id_account', $p)->where('fk_id_account_type', $this->x['b'])->where('f_active', 1)->value('id'), 'B row id unchanged');
        $this->assertSame(0, (int) DB::table('t_account_in_types')->where('id', $ra)->value('f_active'));
        $this->assertSame([1, 1], [$ev('PERSON_ROLE_ADDED'), $ev('PERSON_ROLE_REMOVED')]);
        $this->assertSame(2, DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $p)->where('reason', 'like', 'Role %')->count(), 'one history line per change');
        $e = $this->postJson("/api/record/$form/$p", ['values' => ['account_role[]' => []]])->assertStatus(422)->json('errors');
        $this->assertSame('Choose at least one role.', collect($e)->flatten()->first());
        // A comes back on its old row; the where-used guard of R-C-17 still holds
        PersonRoles::sync($p, [$this->x['a'], $this->x['b'], $this->x['c']]); $this->assertSame(1, (int) DB::table('t_account_in_types')->where('id', $ra)->value('f_active')); $this->assertSame(3, DB::table('t_account_in_types')->where('fk_id_account', $p)->count());
        // bulk "Add role to selected"
        $q = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'RE' . random_int(1000, 9999), 'first_name' => 'Other', 'sur_name' => 'One' . $this->x['u'], 'f_active' => 1]);
        $this->postJson('/api/people/roles/add', ['ids' => [$p, $q], 'role' => $this->x['c']])->assertOk()->assertJsonPath('added', 1)->assertJsonPath('already', 1);
        $this->assertSame([$this->x['c']], PersonRoles::current($q));
        $this->getJson('/api/people/roles')->assertOk();
    }
}
