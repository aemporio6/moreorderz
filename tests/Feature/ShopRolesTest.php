<?php

namespace Tests\Feature;

use App\Services\Perm;
use App\Services\RecordService;
use App\Services\TaskRoles;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-76 shop form owner cascade, R-G_CORE-20 task roles – the spec examples. */
class ShopRolesTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function opts(string $field, array $parents): array { return $this->getJson('/api/options/t_branch/' . $field . '?' . http_build_query(['parent' => $parents]))->assertOk()->json(); }

    public function test_r76_owner_cascade_addresses_mobiles_companies(): void
    {
        $this->actingAs($this->owner);
        $ahmad = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SA' . random_int(10000, 99999), 'first_name' => 'Ahmad', 'f_active' => 1]);
        $bilal = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SB' . random_int(10000, 99999), 'first_name' => 'Bilal', 'f_active' => 1]);
        $a1 = $this->f('t_account_addresses', ['fk_id_account' => $ahmad, 'line1' => '1 Ahmad Road', 'f_active' => 1]);
        $a2 = $this->f('t_account_addresses', ['fk_id_account' => $ahmad, 'line1' => '2 Ahmad Road', 'f_active' => 1]);
        $this->f('t_account_addresses', ['fk_id_account' => $ahmad, 'line1' => 'Old Ahmad Road', 'f_active' => 0]);
        $b1 = $this->f('t_account_addresses', ['fk_id_account' => $bilal, 'line1' => '9 Bilal Street', 'f_active' => 1]);
        $m1 = $this->f('t_account_mobiles', ['fk_id_account' => $ahmad, 'mobile_no' => '07700900001', 'f_active' => 1]);
        $ids = fn (array $o) => array_map(fn ($x) => (int) $x[0], $o['options']);
        $this->assertSame([$a1, $a2], $ids($this->opts('fk_id_address', ['fk_id_owner' => $ahmad])), "pick owner Ahmad → only Ahmad's 2 active addresses");
        $this->assertSame([$b1], $ids($this->opts('fk_id_address', ['fk_id_owner' => $bilal])), "change owner to Bilal → Bilal's items");
        $this->assertSame([$m1], $ids($this->opts('fk_id_mobile', ['fk_id_owner' => $ahmad])));
        $this->assertSame([], $this->opts('fk_id_mobile', ['fk_id_owner' => $bilal])['options']);
        $this->assertSame('Choose the owner first.', $this->opts('fk_id_address', [])['hint']);
        $this->assertSame('No company for this owner – press + Add', $this->opts('fk_id_company', ['fk_id_owner' => $bilal])['hint']);
        $co = $this->f('cat_company_house', ['company_name' => 'Ahmad Trading Ltd', 'fk_director_1' => $ahmad, 'director_1_share' => 60, 'fk_director_2' => $bilal, 'director_2_share' => 40, 'f_active' => 1]);
        $lbl = collect($this->opts('fk_id_company', ['fk_id_owner' => $ahmad])['options'])->firstWhere(0, (string) $co)[1];
        $this->assertStringContainsString('Ahmad Trading Ltd – [Director 1: Ahmad Share: 60%', $lbl);
        $this->assertStringContainsString('afterPick(f)', file_get_contents(public_path('app/app.js')), 'picking a new owner clears the three fields and reloads them');
    }

    public function test_r20_task_roles_screens_header_and_health(): void
    {
        $va = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER'); $pt = $this->makeUser('PARTNER');
        $this->assertSame(['VA', 'MANAGER', 'PARTNER', 'PARTNER'], [TaskRoles::of($va), TaskRoles::of($mgr), TaskRoles::of($pt), TaskRoles::of($this->owner)]);
        $this->actingAs($va)->getJson('/api/tasks/dashboard')->assertStatus(422)->assertJsonPath('message', 'That page is for Partners and Managers.');
        $this->actingAs($va)->getJson('/api/tasks/team')->assertStatus(422)->assertJsonPath('message', 'That page is for Partners and Managers.');
        $this->actingAs($mgr)->getJson('/api/tasks/dashboard')->assertOk();
        foreach (array_keys(TaskRoles::SCREENS) as $k) $this->assertArrayHasKey("work.$k", Perm::catalogue(), 'the boss screens are rights entries');
        Perm::setRole('VA', 'work.TASK_TEAM', true, (int) $this->owner->id); Perm::flush();
        $this->actingAs($va)->getJson('/api/tasks/team')->assertOk(); /* the Owner can give the right */
        $this->assertMatchesRegularExpression('/· Task Management · \w+day \d{1,2} \w+ \d{4} · viewing as Manager \(/', $this->actingAs($mgr)->getJson('/api/boot')->json('task_header'));
        DB::table('t_users')->where('role_code', 'PARTNER')->update(['f_active' => 0]);
        $this->assertNotEmpty(array_filter(TaskRoles::health(), fn ($w) => str_starts_with($w, 'No Partner set')), 'empty PARTNER role → nobody, and Health says so');
        $this->assertContains('#/tasks?scope=dashboard', collect(\App\Http\Controllers\AppController::menu('OWNER'))->flatMap(fn ($s) => $s['items'])->pluck('route')->all());
        DB::table('cat_src_lov')->insert(['fk_id_ent' => 1, 'lov_group' => 'DASH_MANAGER', 'lov_value' => (string) $va->login_id, 'f_active' => 1, 'created_at' => now()]);
        $this->assertSame(1, TaskRoles::fromOld()); $this->assertSame('MANAGER', DB::table('t_users')->where('id', $va->id)->value('role_code'), 'DASH_MANAGER members → MANAGER');
    }
}
