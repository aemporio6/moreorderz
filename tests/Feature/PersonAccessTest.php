<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-44 the login lives on the person (Access section). */
class PersonAccessTest extends TestCase
{
    public function test_c44_access_section_makes_and_switches_off_a_login_without_any_password_field(): void
    {
        $this->actingAs($this->owner);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $p = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Asma', 'sur_name' => 'Khan', 'f_active' => 1]);
        $f('t_account_emails', ['fk_id_account' => $p, 'email' => 'asma.khan@example.test', 'f_active' => 1]);
        $this->assertNull($this->getJson("/api/people/$p/access")->assertOk()->json('user'));
        $this->postJson("/api/people/$p/access", ['login_id' => 'a-very-long-login-id', 'role_code' => 'VA'])->assertStatus(422)->assertJsonPath('errors.login_id.0', 'Login id can be at most 15 characters.');
        $this->postJson("/api/people/$p/access", ['login_id' => 'asma.k'])->assertStatus(422)->assertJsonPath('errors.role_code.0', 'Choose the role of the new login.');
        $r = $this->postJson("/api/people/$p/access", ['login_id' => 'asma.k', 'role_code' => 'VA'])->assertOk()->json();
        $u = DB::table('t_users')->where('login_id', 'asma.k')->first();
        $this->assertSame($p, (int) $u->fk_id_account); $this->assertNull($u->password_hash, 'no password is typed by anyone');
        $this->assertStringContainsString('link to choose a new password was sent', $r['message']);
        $this->assertSame(1, DB::table('sys_password_resets')->where('user_id', $u->id)->count());
        $this->assertSame(['login_id' => 'asma.k', 'can_login' => true, 'role' => 'VA'], array_intersect_key($r['user'], array_flip(['login_id', 'can_login', 'role'])));
        // unique login id
        $q = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Other', 'f_active' => 1]);
        $this->postJson("/api/people/$q/access", ['login_id' => 'asma.k', 'role_code' => 'VA'])->assertStatus(422)->assertJsonPath('errors.login_id.0', 'This login id is already used.');
        // turn "Can log in" off → the next request of that user is logged out
        DB::table('t_users')->where('id', $u->id)->update(['password_hash' => bcrypt('a-long-password-1')]);
        $this->actingAs(User::find($u->id))->getJson('/api/boot')->assertOk();
        $this->actingAs($this->owner)->postJson("/api/people/$p/access", ['login_id' => 'asma.k', 'can_login' => false])->assertOk();
        $this->actingAs(User::find($u->id))->getJson('/api/boot')->assertStatus(401);
        $this->assertSame('UPDATE', DB::table('sys_audit_log')->where('table_name', 't_users')->where('record_id', $u->id)->orderByDesc('id')->value('action'));
        // the users screen has no password field: a user made there gets the one-time link too
        $this->actingAs($this->owner)->postJson('/api/users', ['login_id' => 'nopass@example.test', 'display_name' => 'No pass', 'role_code' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'User created'));
        $this->assertNull(DB::table('t_users')->where('login_id', 'nopass@example.test')->value('password_hash'));
        // only who may manage users sees the section
        $va = $this->makeUser('VA'); $this->actingAs($va)->getJson("/api/people/$p/access")->assertStatus(403);
    }
}
