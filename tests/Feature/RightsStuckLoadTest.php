<?php

namespace Tests\Feature;

use App\Services\Perm;
use App\Services\RecordService;
use App\Services\RightsMigration;
use App\Services\TaskCreate;
use App\Services\TaskActions;
use App\Services\TaskTeam;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-61 dry-run rights report, R-G_CORE-4 "I am stuck" (cover, party picker), R-G_CORE-18 workload – the spec examples. */
class RightsStuckLoadTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function person(string $role, string $name, int $limit = 6): object
    {
        $u = $this->makeUser($role);
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'RS' . random_int(10000, 99999), 'first_name' => $name, 'f_active' => 1, 'weekly_task_limit' => $limit]);
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a, 'display_name' => $name]); return $u->fresh();
    }
    private function task(int $owner, string $status = 'TODO'): int
    {
        return (int) DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'Task ' . random_int(100, 999), 'owner_user_id' => $owner, 'category_id' => 1, 'priority' => 'NORMAL', 'status' => $status, 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('+2 days')), 'source' => 'MANUAL']);
    }

    public function test_r61_dry_run_report_then_apply_makes_it_equal(): void
    {
        $owner = $this->makeUser('OWNER'); $this->actingAs($owner);
        $this->assertSame('No old rights were loaded – nothing to compare yet.', RightsMigration::report()['message']);
        $role = $this->f('cat_account_roles', ['fk_id_ent' => 1, 'account_type_name' => 'VA', 'iso_account_type' => 'VA', 'f_active' => 1]);
        $rRead = $this->f('cat_rights', ['right_name' => 'Sales read', 'iso_right' => 'SR', 'f_active' => 1]); $rDel = $this->f('cat_rights', ['right_name' => 'Branch delete', 'iso_right' => 'BD', 'f_active' => 1]);
        $cSale = $this->f('cat_module_controllers', ['controller_name' => 't_sale_order', 'iso_controller' => 'SO', 'f_active' => 1]); $cBr = $this->f('cat_module_controllers', ['controller_name' => 't_branch', 'iso_controller' => 'BR', 'f_active' => 1]);
        $cOld = $this->f('cat_module_controllers', ['controller_name' => 'old_only_page', 'iso_controller' => 'OO', 'f_active' => 1]);
        $this->f('t_controller_rights', ['fk_id_controller' => $cSale, 'fk_id_right' => $rRead, 'f_read' => 1, 'f_insert' => 0, 'f_update' => 0, 'f_delete' => 0, 'f_all' => 0]);
        $this->f('t_controller_rights', ['fk_id_controller' => $cOld, 'fk_id_right' => $rRead, 'f_read' => 1, 'f_insert' => 0, 'f_update' => 0, 'f_delete' => 0, 'f_all' => 0]);
        $this->f('t_controller_rights', ['fk_id_controller' => $cBr, 'fk_id_right' => $rDel, 'f_read' => 1, 'f_insert' => 0, 'f_update' => 1, 'f_delete' => 1, 'f_all' => 1]);
        $this->f('t_role_rights', ['fk_id_role' => $role, 'fk_id_right' => $rRead, 'f_active' => 1]);
        $x = $this->person('VA', 'Xena'); $y = $this->person('VA', 'Yusuf');
        $this->f('t_account_rights', ['fk_id_account' => $x->fk_id_account, 'fk_id_right' => $rRead, 'e_status' => 'REVOKED']);
        $this->f('t_account_rights', ['fk_id_account' => $y->fk_id_account, 'fk_id_right' => $rDel, 'e_status' => 'GRANTED']);
        $this->actingAs($owner);
        $r = $this->getJson('/api/permissions/migration')->assertOk()->json();
        $this->assertSame(['old_only_page'], $r['unmapped'], 'an old page with no form is listed, never guessed');
        $this->assertGreaterThan(0, $r['differences']); $this->assertFalse($r['equal']);
        $this->actingAs($this->makeUser('MANAGER'))->postJson('/api/permissions/migration')->assertStatus(403);
        $this->actingAs($owner); $a = $this->postJson('/api/permissions/migration')->assertOk()->json();
        $this->assertGreaterThan(0, $a['written']); $this->assertSame(0, $a['differences']); $this->assertTrue($a['equal']);
        $this->assertSame('All equal: the new rights give every role and person exactly the old rights.', $a['message']);
        Perm::flush();
        $this->assertTrue(Perm::roleCan('VA', 'VA', 'form:t_sale_order:VIEW'), 'role VA has read on t_sale_order');
        $this->assertFalse(Perm::has($x->fresh(), 'form:t_sale_order:VIEW'), 'X REVOKED read → cannot open it');
        $this->assertTrue(Perm::has($y->fresh(), 'form:t_branch:DELETE'), 'Y GRANTED delete on t_branch → can delete');
        $this->assertFalse(Perm::roleCan('VA', 'VA', 'form:t_branch:DELETE'), '… while the VA role cannot');
    }

    public function test_r4_cover_may_mark_and_party_picker(): void
    {
        $ali = $this->person('VA', 'Ali'); $sara = $this->person('VA', 'Sara'); $other = $this->person('VA', 'Omar');
        $this->f('user_absence', ['user_id' => $ali->fk_id_account, 'cover_user_id' => $sara->fk_id_account, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+3 days')), 'created_at' => now()]);
        $t = $this->task($ali->id, 'DOING');
        $this->actingAs($other)->postJson("/api/tasks/$t/stuck", ['type' => 'OTHER', 'text' => 'x'])->assertStatus(403);
        $this->actingAs($ali)->postJson("/api/tasks/$t/stuck", ['type' => 'USER', 'on_id' => $ali->id])->assertStatus(422);
        $type = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', ['SUPPLIER'])->value('id') ?? $this->f('cat_account_types', ['account_type_name' => 'Supplier', 'role_code' => 'SUPPLIER', 'iso_account_type' => 'SUP' . random_int(10, 99), 'f_active' => 1, 'fk_id_ent' => 1]);
        $sup = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SP' . random_int(10000, 99999), 'first_name' => 'Ningbo Lights', 'f_active' => 1]);
        $this->f('t_account_in_types', ['fk_id_account' => $sup, 'fk_id_account_type' => $type, 'f_active' => 1]);
        $notSup = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'NS' . random_int(10000, 99999), 'first_name' => 'Random Person', 'f_active' => 1]);
        $this->actingAs($sara);
        $this->assertContains([$sup, 'Ningbo Lights'], \App\Services\Guide::parties('SUPPLIER', 1));
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'SUPPLIER', 'on_id' => $notSup])->assertStatus(422)->assertJsonPath('message', 'Refused: pick the supplier from the list of suppliers (party master).');
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'SUPPLIER', 'on_id' => $sup])->assertOk();      // the cover marks it
        $row = DB::table('tasks')->where('id', $t)->first(); $this->assertSame(['STUCK', 'SUPPLIER', $sup], [$row->status, $row->stuck_on_type, (int) $row->stuck_on_id]);
        $this->assertSame([$t], array_column(\App\Services\Guide::stuckOnParty($sup), 'id'), 'the supplier shows the tasks stuck on it');
        $form = DB::table('sys_registry')->where('table_name', 't_account')->value('form_key');
        $g = \App\Services\Guide::record($form, $sup, $sara);
        $this->assertContains('#/task/' . $t, array_column($g['blocked'], 'url'), 'the supplier page shows the task stuck on it');
        $p = TaskActions::page($t, $ali->fresh());
        $this->assertContains(['SUPPLIER:' . $sup, 'A supplier · Ningbo Lights'], $p['parties']['SUPPLIER']);
        $this->assertSame('Stuck since ' . date('Y-m-d') . ' on Supplier: Ningbo Lights', collect($p['banners'])->firstWhere('code', 'STUCK')['text'] ?? null);
    }

    public function test_r18_sara_limit_6_warning_on_create_and_reassign_overloaded_on_team(): void
    {
        $mgr = $this->person('MANAGER', 'Mona'); $sara = $this->person('VA', 'Sara', 6); $bob = $this->person('VA', 'Bob');
        foreach (range(1, 6) as $i) $this->task($sara->id);
        $this->assertSame(['open' => 6, 'limit' => 6, 'state' => 'Full'], TaskCreate::load($sara->id));
        $this->actingAs($mgr);
        $r = TaskCreate::create(1, ['title' => 'One more', 'owner' => $sara->id, 'category' => 1, 'due' => date('Y-m-d', strtotime('+3 days')), 'priority' => 'NORMAL'], $mgr);
        $this->assertContains('Sara now has 7 open tasks (limit 6).', $r['warnings'] ?? [], 'saved + warning, never a block');
        $t = $this->task($bob->id);
        $w = TaskActions::reassign($t, $mgr, $sara->id, 'Bob is busy');
        $this->assertContains('Sara now has 8 open tasks (limit 6).', $w['warnings'], 'the same warning on reassign');
        $team = collect(TaskTeam::page($mgr)['workload'])->firstWhere('name', 'Sara');
        $this->assertSame('Overloaded', $team['state']);
    }
}
