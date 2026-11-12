<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_role_can_only_use_the_forms_of_its_own_screens(): void
    {
        $wh = $this->makeUser('WAREHOUSE');
        $create = array_column($this->actingAs($wh)->getJson('/api/boot')->json('create'), 'key');
        $this->assertNotContains('fin_expense', $create);
        $this->assertNotContains('mp_profile', $create);
        $this->actingAs($wh)->getJson('/api/form/mp_profile')->assertStatus(403);
        $this->actingAs($wh)->getJson('/api/list/mp_profile')->assertStatus(403);
        $this->actingAs($wh)->postJson('/api/record/mp_profile', ['values' => ['code' => 'X']])->assertStatus(403);
        $this->actingAs($this->owner)->getJson('/api/form/mp_profile')->assertOk();
    }

    public function test_only_the_owner_manages_users_and_passwords_are_hashed(): void
    {
        $mgr = $this->makeUser('MANAGER');
        $new = ['login_id' => 'packer1', 'display_name' => 'Packer One', 'role_code' => 'WAREHOUSE', 'fk_id_ent' => 1, 'password' => 'a-long-password-1'];
        $this->actingAs($mgr)->postJson('/api/users', $new)->assertStatus(403);
        $this->actingAs($mgr)->getJson('/api/users')->assertOk();
        $this->actingAs($this->makeUser('VA'))->getJson('/api/users')->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/users', ['password' => 'short'] + $new)->assertStatus(422);
        $id = $this->actingAs($this->owner)->postJson('/api/users', $new)->assertOk()->json('id');
        $u = User::find($id);
        $this->assertTrue(Hash::check('a-long-password-1', $u->password_hash));
        $this->assertNull($u->pass, 'the old plain column stays empty');
        $log = DB::table('sys_audit_log')->where(['table_name' => 't_users', 'record_id' => $id])->value('changes_json');
        $this->assertStringNotContainsString('a-long-password-1', (string) $log);
        $this->actingAs($this->owner)->postJson('/api/users', $new)->assertStatus(422);            // same login twice
        $this->actingAs($this->owner)->postJson('/api/users/' . $this->owner->id, ['login_id' => 'owner@test', 'display_name' => 'O', 'role_code' => 'VA', 'fk_id_ent' => 1])->assertStatus(422);   // cannot demote self
        $users = $this->actingAs($this->owner)->getJson('/api/users')->json('users');
        $this->assertArrayNotHasKey('password_hash', $users[0]);
        $this->assertArrayNotHasKey('pass', $users[0]);
    }

    public function test_two_step_sign_in_needs_the_right_code(): void
    {
        $g = new Google2FA();
        $setup = $this->actingAs($this->owner)->getJson('/api/me/two-step')->assertOk()->json();
        $this->assertStringContainsString('<svg', $setup['qr_svg']);
        $this->postJson('/api/me/two-step', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/me/two-step', ['code' => $g->getCurrentOtp($setup['secret'])])->assertOk();
        $stored = DB::table('t_users')->where('id', $this->owner->id)->value('totp_secret');
        $this->assertNotSame($setup['secret'], $stored, 'the secret is stored encrypted');
        $this->post('/logout');
        $this->post('/login', ['login' => 'owner@test', 'password' => 'secret-password'])->assertRedirect('/login/code');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->post('/login/code', ['code' => '111111'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/login/code', ['code' => $g->getCurrentOtp($setup['secret'])])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->actingAs($this->owner)->postJson('/api/me/password', ['current' => 'wrong', 'new' => 'brand-new-password-1'])->assertStatus(422);
        $this->postJson('/api/me/password', ['current' => 'secret-password', 'new' => 'brand-new-password-1'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-password-1', DB::table('t_users')->where('id', $this->owner->id)->value('password_hash')));
    }

    public function test_home_cards_follow_the_role_and_the_data(): void
    {
        $va = $this->makeUser('VA');
        $labels = fn ($u) => array_column($this->actingAs($u)->getJson('/api/home')->assertOk()->json('cards'), 'label');
        $this->assertSame(['My tasks'], $labels($va));
        $this->assertContains('Ledger', $labels($this->owner));
        $this->assertContains('System health', $labels($this->owner));
        DB::table('tasks')->insert(['fk_id_ent' => 1, 'title' => 'Late one', 'category_id' => 1, 'owner_user_id' => $va->id, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => '2026-01-01', 'source' => 'MANUAL']);
        $card = $this->actingAs($va)->getJson('/api/home')->json('cards')[0];
        $this->assertSame(1, $card['value']);
        $this->assertSame('err', $card['tone']);
    }

    public function test_settings_audit_health_and_ledger_are_kept_to_their_roles(): void
    {
        $va = $this->makeUser('VA');
        foreach (['settings', 'audit', 'health', 'ledger'] as $p) $this->actingAs($va)->getJson("/api/$p")->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/settings', ['key' => 'RESEARCH_MIN_MARGIN', 'value' => '30', 'scope' => 'company'])->assertOk();
        $row = collect($this->getJson('/api/settings')->json('settings'))->firstWhere('key', 'RESEARCH_MIN_MARGIN');
        $this->assertSame('25', $row['global']);
        $this->assertSame('30', $row['company']);
        $this->postJson('/api/settings', ['key' => 'NOT_A_SETTING', 'value' => '1', 'scope' => 'global'])->assertStatus(404);
        Ledger::post(1, date('Y-m-d'), 'EXPENSE', 1, [['account' => '6000', 'debit' => 12.5], ['account' => '1000', 'credit' => 12.5, 'ref_id' => 1]]);
        $l = $this->getJson('/api/ledger')->assertOk()->json();
        $this->assertTrue($l['trial']['balanced']);
        $this->assertSame(-12.5, (float) $l['pl']['profit']);
        $this->assertGreaterThan(0, $this->getJson('/api/audit')->json('total'));
        $this->assertArrayHasKey('counts', $this->getJson('/api/health')->assertOk()->json());
    }
}
