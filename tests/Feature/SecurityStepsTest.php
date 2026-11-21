<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\PasswordReset;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-65 forgot password, R-G_CORE-59 / 98 2FA before secrets and the re-ask after 60 minutes idle – spec examples. */
class SecurityStepsTest extends TestCase
{
    public function test_r65_forgot_password_same_answer_one_time_link(): void
    {
        $u = $this->makeUser('VA');
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'PR' . random_int(10000, 99999), 'first_name' => 'Pat', 'f_active' => 1]));
        DB::table('t_account_emails')->insert(RecordService::fillNotNull('t_account_emails', ['fk_id_account' => $acc, 'email' => 'pat@example.test', 'f_active' => 1]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $acc]);
        $before = DB::table('tm_messages')->count();
        $t = null; $this->assertSame(PasswordReset::ANSWER, PasswordReset::request($u->login_id, 'wrong@example.test', '1.2.3.4', $t));
        $this->assertNull($t); $this->assertSame($before, DB::table('tm_messages')->count(), 'no mail sent');
        $this->assertSame('reset: no match', DB::table('sys_audit_log')->where('action', 'RESET_REQUEST')->orderByDesc('id')->value('reason'));
        $this->post('/forgot', ['login' => $u->login_id, 'email' => 'nobody@x.test'])->assertOk()->assertSee(PasswordReset::ANSWER);
        $this->assertSame(PasswordReset::ANSWER, PasswordReset::request($u->login_id, 'PAT@example.test', '1.2.3.4', $t));
        $this->assertNotNull($t);
        $mail = DB::table('tm_messages')->where('channel', 'EMAIL')->orderByDesc('id')->first();
        $this->assertSame(['pat@example.test', 'Your new password'], [$mail->to_address, $mail->subject]); $this->assertStringContainsString('within 30 minutes', $mail->text);
        DB::table('sys_user_sessions')->insert(['user_id' => $u->id, 'fk_id_ent' => 1, 'session_hash' => str_repeat('a', 64), 'started_at' => now(), 'last_seen_at' => now()]);
        $this->get("/reset/$t")->assertOk()->assertSee('Choose a new password');
        $this->post("/reset/$t", ['password' => 'short', 'password_again' => 'short'])->assertSessionHasErrors('password');
        $this->post("/reset/$t", ['password' => 'a-new-long-pass', 'password_again' => 'a-new-long-pass'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('a-new-long-pass', (string) DB::table('t_users')->where('id', $u->id)->value('password_hash')));
        $this->assertSame('PWD_RESET', DB::table('sys_user_sessions')->where('user_id', $u->id)->value('end_reason'), 'other sessions end');
        $this->get("/reset/$t")->assertOk()->assertSee('This link has expired or was used already');
        Choices::set('RESET_LINK_MINUTES', '60', 1); PasswordReset::request($u->login_id, 'pat@example.test', null, $t);
        $this->assertStringContainsString('within 60 minutes', (string) DB::table('tm_messages')->where('channel', 'EMAIL')->orderByDesc('id')->value('text'));
    }

    public function test_r59_r98_secrets_need_the_code_and_are_asked_again_after_60_minutes_idle(): void
    {
        $g = new Google2FA(); $secret = $g->generateSecretKey(32);
        $o = $this->makeUser('OWNER'); DB::table('t_users')->where('id', $o->id)->update(['totp_secret' => encrypt($secret)]); $o->totp_secret = encrypt($secret);
        $this->actingAs($o);
        $this->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->assertStatus(428)->assertJsonPath('need', 'CODE');
        $this->postJson('/api/me/secure-code', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/me/secure-code', ['code' => $g->getCurrentOtp($secret)])->assertOk();
        $this->assertNotSame(428, $this->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->status(), 'open for this session');
        // 10:00 open, 10:50 still open, idle until 11:55 → asked again (secure_reask_minutes 60)
        $this->assertNotSame(428, $this->withSession(['secure_ok_at' => time() - 3000, 'secure_seen' => time() - 3000])->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->status());
        $this->withSession(['secure_ok_at' => time() - 7000, 'secure_seen' => time() - 3900])->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->assertStatus(428);
        Choices::set('SECURE_REASK_MINUTES', '15', 1);
        $this->withSession(['secure_ok_at' => time() - 3000, 'secure_seen' => time() - 1000])->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->assertStatus(428);
        // a login without the phone app: set it up first (default), or reveal without it when the choice says so
        $m = $this->makeUser('OWNER'); $this->actingAs($m);
        $this->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->assertStatus(428)->assertJsonPath('need', 'SETUP');
        Choices::set('SECURE_2FA', 'IF_SET_UP', 1); $this->assertNotSame(428, $this->postJson('/api/reveal/t_account_banks/1', ['field' => 'x'])->status());
        // 5 wrong codes lock secure pages for 15 minutes (RIGHTS_DENIED)
        $this->actingAs($o);
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/me/secure-code', ['code' => '000000']);
        $this->postJson('/api/me/secure-code', ['code' => $g->getCurrentOtp($secret)])->assertStatus(422)->assertJsonPath('message', 'Too many wrong codes – secure pages are locked for 15 minutes.');
        $this->assertSame(1, DB::table('sys_events')->where('event_code', 'RIGHTS_DENIED')->where('entity_id', $o->id)->count());
    }
}
