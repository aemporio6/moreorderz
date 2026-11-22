<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccCompliance;
use App\Services\AccFilings;
use App\Services\AccHub;
use App\Services\AccHubAdmin as H;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** R-ACC-16 reveal with log, R-ACC-17 company Hub settings, R-ACC-18 UTR / NI, R-ACC-20 Hub settings / team / close before, R-ACC-21 company page + tax period tick, R-ACC-22 reminder job – the spec tests. */
class AccHubAdminTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('SECURE_2FA', 'IF_SET_UP', 1); \App\Services\Choices::set('COMPANY_FORM_CHECKS', 'OLD', 1); }
    private function co(array $x = []): int { return (int) DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', $x + ['company_name' => 'Hub Test Ltd', 'fk_id_ent' => 1, 'f_active' => 1])); }
    private function person(): int { return (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'H' . random_int(10000, 99999), 'first_name' => 'Nadia', 'f_active' => 1])); }
    private function item(int $co, string $due, string $area, array $x = []): int { return (int) DB::table('acc_obligations')->insertGetId($x + ['fk_id_ent' => 1, 'entity_type' => 'COMPANY', 'entity_id' => $co, 'fk_type' => 0, 'type_code' => $area === 'VAT' ? 'VAT_RETURN' : 'CT600', 'area' => $area, 'completion' => $area === 'VAT' ? 'FILE_PAY' : 'FILE', 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 0, 'title' => 'T', 'created_at' => now()]); }
    private function va(): User { return User::create(['login_id' => 'acc' . uniqid() . '@test', 'password_hash' => bcrypt('x-password-1'), 'role_code' => 'ACCOUNTANT', 'display_name' => 'Acc', 'fk_id_ent' => 1, 'f_active' => 1]); }

    public function test_r16_reveal_logged_shown_and_denied(): void
    {
        $this->actingAs($this->owner);
        $id = (int) RecordService::save('cat_company_house', ['company_name' => 'Secret Co Ltd', 'fk_id_ent' => 1, 'f_active' => 1, 'gateway_pass' => 'Gw-pass-9876', 'company_utr' => '1234567890'])['id'];
        $row = DB::table('cat_company_house')->where('id', $id)->first();
        $this->assertSame(['••••••876', '••••••890'], [$row->gateway_pass, $row->company_utr], 'masked "••••••" + last 3');
        $this->assertSame('••••••', H::mask('ab12'), '4 characters or fewer → all dots');
        $acc = $this->va(); $this->actingAs($acc);
        $this->postJson("/api/reveal/cat_company_house/$id", ['field' => 'gateway_pass'])->assertStatus(403);
        $this->assertSame('DENIED', json_decode(DB::table('sys_audit_log')->where('table_name', 'cat_company_house')->where('record_id', $id)->where('action', 'REVEAL')->orderByDesc('id')->value('changes_json'), true)['result'], 'DENIED row logged, value not returned');
        H::saveMember(1, (int) $acc->id, ['can_reveal' => 1], $this->owner);
        $this->assertSame('Gw-pass-9876', $this->postJson("/api/reveal/cat_company_house/$id", ['field' => 'gateway_pass'])->assertOk()->json('value'), 'a Hub member with can_reveal sees it');
        $h = H::history('cat_company_house', $id); $this->assertSame(['SHOWN', 'DENIED'], array_column(array_slice($h, 0, 2), 'result'));
        $this->assertNotEmpty($h[0]['at']); $this->assertArrayHasKey('ip', $h[0]);
    }

    public function test_r17_company_settings(): void
    {
        $this->actingAs($this->owner); $co = $this->co(['incorporate_date' => '2023-05-10']);
        try { AccHub::save($co, ['pay_frequency' => 'W', 'paye_registered' => 1], $this->owner); $this->fail('W without first payday saved'); } catch (ValidationException $e) { $this->assertSame('Weekly and 4-weekly pay need the first payday.', $e->errors()['first_payday'][0]); }
        try { AccHub::save($co, ['year_end_day' => 31, 'year_end_month' => 4], $this->owner); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('year_end_day', $e->errors()); }
        try { AccHub::save($co, ['notes' => str_repeat('x', 4001)], $this->owner); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('notes', $e->errors()); }
        AccCompliance::generate(1, $co, '2026-11-22'); $open = DB::table('acc_obligations')->where('entity_id', $co)->where('f_done', 0)->count();
        $r = AccHub::save($co, ['year_end_day' => 29, 'year_end_month' => 2, 'pay_frequency' => 'W', 'first_payday' => '2026-10-02', 'paye_registered' => 1, 'fk_accountant' => $this->owner->id], $this->owner);
        $this->assertStringContainsString('filing(s) given to the accountant', $r['message']);
        $this->assertSame(0, DB::table('acc_obligations')->where('entity_id', $co)->where('f_done', 0)->where('fk_assigned', 0)->count(), 'open unassigned items get the accountant');
        $this->assertTrue(DB::table('acc_obligations')->where('entity_id', $co)->where('type_code', 'PAYE_FPS')->exists(), 'rebuilt with the PAYE rows');
        $this->assertGreaterThan(0, $open);
    }

    public function test_r18_utr_ni(): void
    {
        $p = $this->person();
        $r = H::savePerson(1, $p, ['ni' => 'qq 12 34 56 c', 'utr' => '12345 67890', 'sa_registered' => 1, 'first_tax_year' => 2020], $this->owner);
        $s = DB::table('acc_person_settings')->where('fk_id_account', $p)->first();
        $this->assertSame(['456C', '7890', '••••••456C'], [$s->ni_last4, $s->utr_last4, $r['ni']]);
        $this->assertSame('QQ123456C', \Illuminate\Support\Facades\Crypt::decryptString($s->ni_enc), 'NI "qq 12 34 56 c" → stored QQ123456C');
        $this->assertStringNotContainsString('QQ123456C', (string) $s->ni_enc);
        foreach ([['ni' => 'QQ123456E'], ['utr' => '123'], ['first_tax_year' => 1990]] as $bad) { try { H::savePerson(1, $p, $bad, $this->owner); $this->fail(); } catch (ValidationException) { $this->assertTrue(true); } }
        $this->actingAs($this->owner);
        $this->assertSame('1234567890', $this->postJson("/api/compliance/person/$p/reveal", ['field' => 'utr'])->assertOk()->json('value'));
        H::savePerson(1, $p, ['utr' => '-'], $this->owner); $this->assertNull(DB::table('acc_person_settings')->where('fk_id_account', $p)->value('utr_enc'), '"Remove" clears');
        DB::table('acc_person_settings')->where('fk_id_account', $p)->update(['ni_enc' => 'not-a-cipher']);
        $this->postJson("/api/compliance/person/$p/reveal", ['field' => 'ni'])->assertStatus(422)->assertJsonPath('message', 'The value cannot be read (the encryption key changed). Type it again.');
    }

    public function test_r20_settings_team_close_before(): void
    {
        $this->actingAs($this->owner);
        try { H::saveSettings(1, ['due_soon_days' => 200], $this->owner); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('due_soon_days', $e->errors()); }
        $this->assertStringContainsString('new deadline(s) added', H::saveSettings(1, ['due_soon_days' => 45, 'horizon_months' => 24], $this->owner)['message']);
        $this->assertSame(45, H::settings(1)['due_soon_days']);
        $acc = $this->va(); $this->postJson("/api/compliance/hub/team/{$acc->id}", ['is_accountant' => 1])->assertOk();
        $this->assertSame([(int) $acc->id], array_column(AccFilings::accountants(1), 'id'), 'is_accountant → in Assigned to');
        $this->actingAs($acc); $this->postJson("/api/compliance/hub/team/{$acc->id}", ['can_reveal' => 1])->assertStatus(422);
        $co = $this->co();
        $v1 = $this->item($co, '2026-10-15', 'VAT'); $v2 = $this->item($co, '2026-11-15', 'VAT'); $c1 = $this->item($co, '2026-10-15', 'CT');
        $r = H::closeBefore(1, '2026-11-01', 'VAT', $this->owner);
        $this->assertSame(1, $r['closed']);
        $st = fn ($id) => DB::table('acc_obligations')->where('id', $id)->value('status');
        $this->assertSame(['PAID', 'PENDING', 'PENDING'], [$st($v1), $st($v2), $st($c1)], 'close before 2026-11-01 area VAT → only VAT items due before that date');
        $this->assertStringContainsString(H::BULK_NOTE, (string) DB::table('acc_obligations')->where('id', $v1)->value('notes'));
        $this->assertTrue(DB::table('acc_obligation_log')->where('fk_id_obligation', $v1)->where('action', 'BULK_CLOSE')->exists());
    }

    public function test_r21_tax_period_ticks_the_filing(): void
    {
        $this->actingAs($this->owner); $co = $this->co(['incorporate_date' => '2023-05-10']);
        $ct = $this->item($co, '2026-06-30', 'CT', ['type_code' => 'CT600', 'period_start' => '2024-06-01', 'period_end' => '2025-05-31']);
        $pid = (int) RecordService::save('tax_period', ['fk_id_ent' => 1, 'fk_company_house' => $co, 'period_label' => 'Period ending 31 May 2025', 'period_start' => '2024-06-01', 'period_end' => '2025-05-31', 'status' => 'PREPARED', 'f_active' => 1])['id'];
        $this->assertSame('PENDING', DB::table('acc_obligations')->where('id', $ct)->value('status'));
        RecordService::save('tax_period', ['status' => 'CT600_FILED', 'ct600_filed_date' => '2026-05-20'], $pid);
        $o = DB::table('acc_obligations')->where('id', $ct)->first();
        $this->assertSame(['FILED', 1, '2026-05-20'], [$o->status, (int) $o->f_done, $o->filed_date], 'tax period → CT600_FILED sets the CT600 filing of the same period end to FILED');
        $t = H::companyTax(1, $co); $this->assertCount(1, $t['periods']); $this->assertStringContainsString("fk_company_house]=$co", $t['taxation_url']);
    }

    public function test_r22_job_rows_and_sender(): void
    {
        $this->assertSame('FAILED', H::job(1, '2026-11-22')['status'], 'no sender → FAILED');
        $this->assertTrue(\App\Services\TaskEngine::isOpen('SYS_ERROR', ['acc_reminders_sender', 1]));
        $this->assertSame(0, DB::table('tm_messages')->where('rule_code', 'N17')->count(), 'nothing sent');
        Settings::set('EMAIL_SENDER_ADDRESS', 'filings@example.test', 1);
        $co = $this->co(); DB::table('acc_company_settings')->insert(RecordService::fillNotNull('acc_company_settings', ['fk_id_ent' => 1, 'fk_id_company' => $co, 'f_tracked' => 0]));
        $this->item($co, '2026-11-29', 'CT', ['fk_assigned' => $this->owner->id]);
        $a = H::job(1, '2026-11-22'); $b = H::job(1, '2026-11-22');
        $this->assertSame(['OK', 'OK'], [$a['status'], $b['status']]);
        $this->assertGreaterThan(0, $a['queued']); $this->assertSame(0, $b['queued'], 'same day again → 2 job rows, the second queues 0');
        $this->assertSame(3, DB::table('sys_job_runs')->where('job_code', 'ACC_REMINDERS')->count());
        $this->assertSame('OK', H::lastRun(1)['status']);
    }
}
