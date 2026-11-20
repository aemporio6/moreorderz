<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Perm;
use App\Services\PermCatalogue;
use App\Services\Registry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Permission engine: rights per role, made roles, personal rights, shop scope, field rules, access requests, view-as. */
class PermissionTest extends TestCase
{
    public function test_defaults_are_the_role_lists_and_the_owner_has_everything(): void
    {
        $this->assertGreaterThan(95, count(PermCatalogue::ALL));
        foreach (Perm::catalogue() as $code => [$label, $group, $roles]) {
            $this->assertNotSame('', $label, $code);
            foreach (User::ROLES as $role) $this->assertSame($role === 'OWNER' || in_array($role, $roles, true), Perm::has($this->makeUser($role), $code), "$role · $code");
        }
        $this->assertSame('Stock › Move, count and change stock', Perm::label('stock.CHANGE'));
    }

    public function test_a_right_switched_on_and_off_for_a_role_changes_what_the_screens_allow(): void
    {
        $va = $this->makeUser('VA'); $wh = $this->makeUser('WAREHOUSE');
        $this->actingAs($va)->getJson('/api/stock/on-hand')->assertStatus(403)->assertJsonPath('perm', 'stock.SEE')->assertJsonPath('can_request', true);
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'VA', 'code' => 'stock.SEE', 'allowed' => true])->assertOk();
        $this->actingAs($va)->getJson('/api/stock/on-hand')->assertOk();
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'WAREHOUSE', 'code' => 'stock.SEE', 'allowed' => false])->assertOk();
        $this->actingAs($wh)->getJson('/api/stock/on-hand')->assertStatus(403);
        $m = $this->actingAs($this->owner)->getJson('/api/permissions')->assertOk()->json('matrix');
        $this->assertSame([1, 1], $m['VA']['stock.SEE']); $this->assertSame([0, 1], $m['WAREHOUSE']['stock.SEE']); $this->assertSame([1, 0], $m['MANAGER']['stock.SEE']);
        // back to the default: the row goes away
        $this->postJson('/api/permissions/set', ['role' => 'VA', 'code' => 'stock.SEE', 'allowed' => null])->assertOk();
        $this->assertSame(0, DB::table('mo_role_perms')->where('role_code', 'VA')->count());
        $this->actingAs($va)->getJson('/api/stock/on-hand')->assertStatus(403);
        // the Owner role cannot be changed, the key to the rights cannot be handed out, only the Owner opens the screen
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'OWNER', 'code' => 'stock.SEE', 'allowed' => false])->assertStatus(422);
        $this->postJson('/api/permissions/set', ['role' => 'MANAGER', 'code' => 'admin.permissions', 'allowed' => true])->assertStatus(422);
        $this->postJson('/api/permissions/set', ['role' => 'MANAGER', 'code' => 'no.such', 'allowed' => true])->assertStatus(422);
        $this->actingAs($this->makeUser('MANAGER'))->getJson('/api/permissions')->assertStatus(403);
        $this->assertGreaterThanOrEqual(3, DB::table('sys_audit_log')->where('table_name', 'mo_role_perms')->count());                              // every change is in the audit trail
    }

    public function test_a_made_role_starts_like_a_built_in_role_and_a_user_can_have_it(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/permissions/role', ['role_code' => 'SENIOR_VA', 'label' => 'Senior VA', 'like_role' => 'VA'])->assertOk()->assertJsonPath('role', 'SENIOR_VA');
        $this->postJson('/api/permissions/role', ['role_code' => 'MANAGER', 'label' => 'x', 'like_role' => 'VA'])->assertStatus(422);
        $this->postJson('/api/permissions/role', ['role_code' => 'BOSS2', 'label' => 'x', 'like_role' => 'OWNER'])->assertStatus(422);
        $this->postJson('/api/permissions/set', ['role' => 'SENIOR_VA', 'code' => 'stock.SEE', 'allowed' => true])->assertOk();
        $this->assertContains('SENIOR_VA', $this->getJson('/api/users')->assertOk()->json('roles'));
        $this->postJson('/api/users', ['login_id' => 'senior@test', 'display_name' => 'Senior', 'role_code' => 'SENIOR_VA', 'fk_id_ent' => 1, 'password' => 'a-long-password-1', 'f_active' => 1])->assertOk();
        $u = User::where('login_id', 'senior@test')->first();
        $this->assertSame('VA', $u->role_code); $this->assertSame('SENIOR_VA', $u->custom_role);
        $plain = $this->makeUser('VA');
        $this->actingAs($u)->getJson('/api/stock/on-hand')->assertOk();
        $this->actingAs($plain)->getJson('/api/stock/on-hand')->assertStatus(403);
        $this->assertSame(Perm::has($plain, 'orders.SEE'), Perm::has($u, 'orders.SEE'));           // everything else is as a VA
        // a second role copies the changes of the first
        $this->actingAs($this->owner)->postJson('/api/permissions/role', ['role_code' => 'NIGHT_VA', 'label' => 'Night VA', 'like_role' => 'VA', 'copy_from' => 'SENIOR_VA'])->assertOk();
        $this->assertTrue(Perm::roleCan('VA', 'NIGHT_VA', 'stock.SEE'));
        // back to a built-in role clears the made role
        $this->postJson('/api/users/' . $u->id, ['login_id' => 'senior@test', 'display_name' => 'Senior', 'role_code' => 'TL', 'fk_id_ent' => 1, 'f_active' => 1])->assertOk();
        $u->refresh(); $this->assertSame('TL', $u->role_code); $this->assertNull($u->custom_role);
    }

    public function test_one_person_gets_an_extra_right_or_loses_one_with_reason_and_end_date(): void
    {
        $va = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER');
        $this->actingAs($this->owner);
        $this->postJson('/api/permissions/user', ['user_id' => $va->id, 'code' => 'stock.SEE', 'effect' => 'GRANTED'])->assertStatus(422);                       // no reason
        $this->postJson('/api/permissions/user', ['user_id' => $va->id, 'code' => 'stock.SEE', 'effect' => 'GRANTED', 'reason' => 'Covers the warehouse this week', 'until' => date('Y-m-d', strtotime('+7 day'))])->assertOk();
        $this->postJson('/api/permissions/user', ['user_id' => $mgr->id, 'code' => 'stock.SEE', 'effect' => 'REVOKED', 'reason' => 'On notice'])->assertOk();
        $this->postJson('/api/permissions/user', ['user_id' => $this->owner->id, 'code' => 'stock.SEE', 'effect' => 'REVOKED', 'reason' => 'x'])->assertStatus(422);
        $this->assertTrue(Perm::has($va, 'stock.SEE')); $this->assertFalse(Perm::has($mgr, 'stock.SEE'));
        $this->assertTrue(Perm::has($this->makeUser('VA'), 'orders.SEE') === Perm::has($va, 'orders.SEE'));
        $this->assertStringContainsString('given to you personally', $this->getJson('/api/permissions/why?user_id=' . $va->id . '&code=stock.SEE')->assertOk()->json('why'));
        $this->assertStringContainsString('taken away', $this->actingAs($mgr)->getJson('/api/permissions/why?code=stock.SEE')->assertOk()->assertJsonPath('allowed', false)->json('why'));
        $this->actingAs($mgr)->getJson('/api/permissions/why?user_id=' . $va->id . '&code=stock.SEE')->assertStatus(403);                                      // only about himself
        // the end date passes: the right is gone by itself
        DB::table('mo_user_perms')->where('user_id', $va->id)->update(['until' => date('Y-m-d', strtotime('-1 day'))]); Perm::flush();
        $this->assertFalse(Perm::has($va, 'stock.SEE'));
        $this->actingAs($this->owner)->postJson('/api/permissions/user', ['user_id' => $mgr->id, 'code' => 'stock.SEE', 'effect' => null])->assertOk();
        $this->assertTrue(Perm::has($mgr, 'stock.SEE'));
    }

    public function test_forms_menu_items_and_reports_follow_the_same_engine(): void
    {
        $va = $this->makeUser('VA'); $this->actingAs($va);
        $menu = collect($this->getJson('/api/boot')->assertOk()->json('menu'))->flatMap(fn ($s) => $s['items']);
        $hidden = DB::table('sidebar_modules')->whereNotNull('parent_id')->where('e_status', 'ACTIVE')->get()->first(fn ($m) => ! Perm::has($va, 'menu:' . $m->module_key));
        $this->assertNotNull($hidden); $before = $menu->count();
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'VA', 'code' => 'menu:' . $hidden->module_key, 'allowed' => true])->assertOk();
        $this->assertSame($before + 1, collect($this->actingAs($va)->getJson('/api/boot')->json('menu'))->flatMap(fn ($s) => $s['items'])->count());
        // a form the VA cannot open → the Owner switches "See" on for the role
        $form = collect(DB::table('sys_forms')->where('f_active', 1)->pluck('form_key'))->first(fn ($k) => Registry::table($k) && ! Perm::has($va, "form:$k:VIEW"));
        $this->assertNotNull($form);
        $this->getJson("/api/list/$form")->assertStatus(403)->assertJsonPath('perm', "form:$form:VIEW");
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'VA', 'code' => "form:$form:VIEW", 'allowed' => true])->assertOk();
        $this->actingAs($va)->getJson("/api/list/$form")->assertOk();
        $this->assertFalse(Perm::has($va, "form:$form:DELETE"));
        $all = $this->actingAs($this->owner)->getJson('/api/permissions?forms=1')->assertOk()->json();
        $this->assertSame([1, 1], $all['matrix']['VA']["form:$form:VIEW"]); $this->assertGreaterThan(900, count($all["rights"]));
        $this->actingAs($va);
        // reports
        $key = array_key_first(array_filter(\App\Services\ReportService::LIST, fn ($r) => ! in_array('VA', $r[3], true)));
        $this->getJson("/api/reports/$key")->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/permissions/set', ['role' => 'VA', 'code' => "report:$key", 'allowed' => true])->assertOk();
        $this->assertContains($key, array_column($this->actingAs($va)->getJson('/api/reports')->assertOk()->json('reports') ?? $this->getJson('/api/reports')->json(), 'key'));
    }

    public function test_field_rules_hide_a_field_or_make_it_see_only(): void
    {
        $mgr = $this->makeUser('MANAGER'); $this->actingAs($this->owner);
        $names = fn () => array_column(collect($this->getJson('/api/form/src_research')->assertOk()->json('sections'))->flatMap(fn ($s) => $s['fields'])->all(), 'readonly', 'name');
        $this->postJson('/api/permissions/field', ['role' => 'MANAGER', 'form_key' => 'src_research', 'field_name' => 'nope', 'mode' => 'HIDDEN'])->assertStatus(422);
        $this->postJson('/api/permissions/field', ['role' => 'MANAGER', 'form_key' => 'src_research', 'field_name' => 'keywords', 'mode' => 'HIDDEN'])->assertOk();
        $this->postJson('/api/permissions/field', ['role' => 'MANAGER', 'form_key' => 'src_research', 'field_name' => 'title', 'mode' => 'READONLY'])->assertOk();
        $this->assertArrayHasKey('keywords', $names());                                              // the Owner still sees it
        $this->actingAs($mgr); $f = $names();
        $this->assertArrayNotHasKey('keywords', $f); $this->assertTrue((bool) $f['title']);
        // a person's own rule wins over the role's
        $this->actingAs($this->owner)->postJson('/api/permissions/field', ['user_id' => $mgr->id, 'form_key' => 'src_research', 'field_name' => 'keywords', 'mode' => 'EDIT'])->assertOk();
        $this->actingAs($mgr); $this->assertArrayHasKey('keywords', $names());
        $this->actingAs($this->owner)->postJson('/api/permissions/field', ['role' => 'MANAGER', 'form_key' => 'src_research', 'field_name' => 'title', 'mode' => 'DEFAULT'])->assertOk();
        $this->actingAs($mgr); $this->assertFalse((bool) $names()['title']);
    }

    public function test_shop_scope_limits_a_person_to_his_shops(): void
    {
        $fill = new \ReflectionMethod(\App\Services\RecordService::class, 'fillNotNull');
        $a = DB::table('t_branches')->insertGetId($fill->invoke(null, 't_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop A', 'shop_no' => 'A1', 'iso_branch' => 'A1']));
        $b = DB::table('t_branches')->insertGetId($fill->invoke(null, 't_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop B', 'shop_no' => 'B1', 'iso_branch' => 'B1']));
        $mgr = $this->makeUser('MANAGER');
        $this->assertNull(Perm::shops($mgr));
        $this->actingAs($this->owner)->postJson('/api/permissions/scope', ['user_id' => $mgr->id, 'kind' => 'SHOP', 'ids' => [$a]])->assertOk();
        $this->assertSame([$a], Perm::shops($mgr)); $this->assertNull(Perm::shops($this->owner));
        $q = DB::table('t_branches'); Perm::scopeQuery($q, 't_branches', $mgr); $this->assertSame([$a], $q->pluck('id')->all());
        $q = DB::table('t_sales'); Perm::scopeQuery($q, 't_sales', $mgr); $this->assertStringContainsString('fk_id_branch', $q->toSql());
        // any screen: naming another shop is refused, his own shop passes
        $this->actingAs($mgr)->getJson("/api/tracking-upload?shop=$b")->assertStatus(403)->assertJsonPath('message', 'This shop is not one of your shops. Ask the Owner if you need it.');
        $this->assertNotSame(403, $this->getJson("/api/tracking-upload?shop=$a")->status());
        $this->actingAs($this->owner)->postJson('/api/permissions/scope', ['user_id' => $mgr->id, 'kind' => 'SHOP', 'ids' => []])->assertOk();
        $this->assertNull(Perm::shops($mgr));
    }

    public function test_a_refused_person_asks_the_owner_and_the_owner_grants_or_refuses(): void
    {
        $va = $this->makeUser('VA');
        $id = $this->actingAs($va)->postJson('/api/permissions/request', ['code' => 'stock.SEE', 'note' => 'I count stock on Fridays'])->assertOk()->json('id');
        $this->assertSame($id, $this->postJson('/api/permissions/request', ['code' => 'stock.SEE'])->assertOk()->json('id'));                                  // asked twice = one request
        $this->postJson('/api/permissions/request', ['code' => 'orders.SEE'])->assertStatus(Perm::has($va, 'orders.SEE') ? 422 : 200);
        $this->postJson("/api/permissions/requests/$id", ['grant' => true])->assertStatus(403);                                                                // not himself
        $this->actingAs($this->owner);
        $this->assertSame('OPEN', collect($this->getJson('/api/permissions')->json('requests'))->firstWhere('id', $id)['status']);
        $id2 = Perm::request($va, 'buying.SEE', null);
        $this->postJson("/api/permissions/requests/$id2", ['grant' => false])->assertStatus(422);                                                               // refusing needs a reason
        $this->postJson("/api/permissions/requests/$id2", ['grant' => false, 'note' => 'Not part of your work'])->assertOk();
        $this->postJson("/api/permissions/requests/$id", ['grant' => true, 'until' => date('Y-m-d', strtotime('+30 day'))])->assertOk();
        $this->postJson("/api/permissions/requests/$id", ['grant' => true])->assertStatus(422);                                                                 // already decided
        $this->assertTrue(Perm::has($va, 'stock.SEE')); $this->assertFalse(Perm::has($va, 'buying.SEE'));
        $this->actingAs($va)->getJson('/api/stock/on-hand')->assertOk();
    }

    public function test_the_owner_views_the_tool_as_another_role_and_cannot_change_anything_meanwhile(): void
    {
        $this->actingAs($this->makeUser('MANAGER'))->postJson('/api/permissions/view-as', ['role' => 'VA'])->assertStatus(403);
        $this->actingAs($this->owner);
        $all = collect($this->getJson('/api/boot')->json('menu'))->flatMap(fn ($s) => $s['items'])->count();
        $this->postJson('/api/permissions/view-as', ['role' => 'VA'])->assertOk();
        $boot = $this->getJson('/api/boot')->assertOk()->json();
        $this->assertSame('VA', $boot['view_as']); $this->assertSame('VA', $boot['user']['role']);
        $this->assertLessThan($all, collect($boot['menu'])->flatMap(fn ($s) => $s['items'])->count());
        $this->getJson('/api/stock/on-hand')->assertStatus(403);
        $this->postJson('/api/permissions/set', ['role' => 'VA', 'code' => 'stock.SEE', 'allowed' => true])->assertStatus(403);                                // read-only
        $this->postJson('/api/permissions/view-as', ['role' => ''])->assertOk();
        $this->getJson('/api/stock/on-hand')->assertOk();
        $this->assertNull($this->getJson('/api/boot')->json('view_as'));
    }
}
