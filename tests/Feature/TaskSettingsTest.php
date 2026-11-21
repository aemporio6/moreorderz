<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskCreate;
use App\Services\TaskFlow;
use App\Services\TaskScore;
use App\Services\TaskSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-16 absence, 17 delegation, 22 hard rules (TASK-HR-1…8), 23 categories = SLA table – the spec examples. */
class TaskSettingsTest extends TestCase
{
    private function person(string $role, string $name): object
    {
        $u = $this->makeUser($role);
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'TS' . random_int(10000, 99999), 'first_name' => $name, 'f_active' => 1, 'f_login' => 1]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a, 'display_name' => $name]); $u->fk_id_account = $a; return $u->fresh();
    }
    private function save(string $form, array $v, ?int $id = null)
    {
        return $this->postJson("/api/record/$form" . ($id ? "/$id" : ''), ['values' => $v]);
    }

    public function test_r16_absence_cover_and_end_now(): void
    {
        $mgr = $this->person('MANAGER', 'Mona'); $a = $this->person('VA', 'Ali'); $b = $this->person('VA', 'Bilal'); $c = $this->person('VA', 'Cyra');
        $from = date('Y-m-d', strtotime('-1 day')); $to = date('Y-m-d', strtotime('+3 days'));
        $this->actingAs($a); $this->save('task_absence', ['user_id' => $a->fk_id_account, 'cover_user_id' => $b->fk_id_account, 'from_date' => $from, 'to_date' => $to])->assertStatus(403);      // a VA has no right on the form at all
        $d = ['user_id' => $a->fk_id_account, 'from_date' => $from, 'to_date' => $to]; $this->assertSame(['_form' => 'Refused: only a Partner or Manager can set absences.'], TaskSettings::absence($d, [], null), 'the rule itself');
        $this->actingAs($mgr);
        $this->save('task_absence', ['user_id' => $a->fk_id_account, 'cover_user_id' => $a->fk_id_account, 'from_date' => $from, 'to_date' => $to])->assertStatus(422)->assertJsonFragment(['Refused: someone cannot cover for themselves.']);
        $this->save('task_absence', ['user_id' => $a->fk_id_account, 'cover_user_id' => $b->fk_id_account, 'from_date' => $to, 'to_date' => $from])->assertStatus(422)->assertJsonFragment(['Refused: give a from-date and a to-date on or after it.']);
        $id = $this->save('task_absence', ['user_id' => $a->fk_id_account, 'cover_user_id' => $b->fk_id_account, 'from_date' => $from, 'to_date' => $to])->assertOk()->json('id');
        $r = $this->save('task_absence', ['user_id' => $a->fk_id_account, 'cover_user_id' => $c->fk_id_account, 'from_date' => $to, 'to_date' => $to])->assertStatus(422);
        $this->assertStringStartsWith('Refused: that person already has an absence in those dates. Ali is already away', json_encode($r->json('errors'), JSON_UNESCAPED_UNICODE) ? $r->json('errors.from_date.0') : '');
        $this->save('task_absence', ['user_id' => $c->fk_id_account, 'cover_user_id' => $a->fk_id_account, 'from_date' => $to, 'to_date' => $to])->assertStatus(422)->assertJsonFragment(['Refused: the cover person is also absent in those dates.']);
        // a task created for Ali goes to Bilal with a warning; Bilal may work Ali's open task
        $res = TaskCreate::create(1, ['title' => 'Ship parcels', 'owner' => $a->id, 'due' => date('Y-m-d', strtotime('+1 day'))], $mgr);
        $this->assertSame($b->id, (int) DB::table('tasks')->where('id', $res['id'])->value('owner_user_id')); $this->assertStringContainsString('given to Bilal', implode(' ', $res['warnings']));
        $open = TaskCreate::create(1, ['title' => 'Count', 'owner' => $b->id, 'due' => date('Y-m-d', strtotime('+1 day')), 'evidence' => 0, 'evidence_reason' => 'x'], $mgr)['id'];
        DB::table('tasks')->where('id', $open)->update(['owner_user_id' => $a->id]);
        $this->assertSame('DOING', TaskFlow::move($open, $b, 'DOING')->status, 'the cover works it');
        $this->assertTrue(collect(\App\Services\TaskLists::all($mgr, 'all', [])['tasks'])->firstWhere('id', $open)['absent'], '"Absent" chip');
        // End now → to_date = yesterday; rows never deleted
        TaskSettings::endAbsence($id, $mgr);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), DB::table('user_absence')->where('id', $id)->value('to_date'));
        $this->assertTrue(DB::table('user_absence')->where('id', $id)->exists());
    }

    public function test_r17_delegate_approves_on_behalf(): void
    {
        $pa = $this->person('PARTNER', 'Parveen'); $tl = $this->person('TL', 'Tariq'); $va = $this->person('VA', 'Vee'); $mgr = $this->person('MANAGER', 'Mona');
        $this->actingAs($mgr); $this->save('task_delegation', ['from_user_id' => $pa->id, 'to_user_id' => $tl->id, 'from_date' => date('Y-m-d'), 'to_date' => date('Y-m-d', strtotime('+5 days'))])->assertStatus(422)->assertJsonFragment(['Refused: only a Partner can set delegations.']);
        $this->actingAs($pa);
        $this->save('task_delegation', ['from_user_id' => $pa->id, 'to_user_id' => $pa->id, 'from_date' => date('Y-m-d'), 'to_date' => date('Y-m-d')])->assertStatus(422)->assertJsonFragment(['Refused: pick two different active people.']);
        $id = $this->save('task_delegation', ['from_user_id' => $pa->id, 'to_user_id' => $tl->id, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+5 days'))])->assertOk()->json('id');
        $this->assertSame('APPROVE', DB::table('tm_delegations')->where('id', $id)->value('rights'), 'rights default APPROVE');
        $t = DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'Write listing', 'owner_user_id' => $va->id, 'assigned_by_user_id' => $pa->id, 'status' => 'DONE', 'done_at' => now(), 'due_at' => date('Y-m-d'), 'source' => 'MANUAL', 'priority' => 'NORMAL', 'evidence_required' => 0, 'created_at' => now()]));
        TaskScore::approve($t, $tl, 'GOOD');
        $this->assertSame('approved on behalf of Parveen', DB::table('task_history')->where('task_id', $t)->where('field', 'approved_by')->value('reason'));
        TaskSettings::endDelegation($id, $pa);
        $this->assertNull(TaskSettings::delegationFor($pa->id, $tl), 'End now');
    }

    public function test_r22_hard_rules_task_hr_1_to_8_with_a_va(): void
    {
        $mgr = $this->person('MANAGER', 'Mona'); $va = $this->person('VA', 'Vee'); $other = $this->person('VA', 'Ola');
        $t = TaskCreate::create(1, ['title' => 'HR task', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'checklist' => 'One'], $mgr)['id'];
        $conf = DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'Secret', 'owner_user_id' => $other->id, 'status' => 'TODO', 'is_confidential' => 1, 'amount_gbp' => 50, 'due_at' => date('Y-m-d'), 'source' => 'MANUAL', 'priority' => 'NORMAL', 'created_at' => now()]));
        $this->actingAs($va);
        $this->postJson("/api/tasks/$t/due", ['date' => date('Y-m-d', strtotime('+9 days')), 'reason' => 'x'])->assertStatus(422);                                     // HR-1 / HR-3 due date
        DB::table('tasks')->where('id', $t)->update(['status' => 'DONE', 'assigned_by_user_id' => $va->id]);
        $this->postJson("/api/tasks/$t/approve", ['quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: nobody can approve their own task - not even a Partner.');   // HR-2
        DB::table('tasks')->where('id', $t)->update(['status' => 'DOING', 'assigned_by_user_id' => $mgr->id]);
        $this->postJson("/api/tasks/$t/reassign", ['owner' => $other->id, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Refused: a VA cannot reassign tasks.');   // HR-3
        $this->postJson("/api/tasks/$t/status", ['status' => 'CANCELLED', 'note' => 'x'])->assertStatus(422);
        $this->postJson("/api/tasks/$t/proof-off", ['reason' => 'x'])->assertStatus(422);                                                                              // HR-3 / HR-8
        $this->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertStatus(422);                                                                               // HR-4 checklist + proof
        $this->assertContains($this->deleteJson("/api/tasks/$t")->status(), [404, 405]); $this->assertTrue(DB::table('tasks')->where('id', $t)->exists());                                                                                                         // HR-5 nothing deleted
        $this->assertArrayNotHasKey('amount_gbp', $this->getJson("/api/tasks/$t/page")->json('task'));                                                                // HR-6
        $this->getJson("/api/tasks/$conf/page")->assertStatus(404);                                                                                                  // HR-7
        $this->assertCount(8, TaskSettings::hardLimits());
        $team = $this->actingAs($mgr)->getJson('/api/tasks/team'); $this->assertSame(200, $team->status(), (string) $team->getContent()); $this->assertCount(8, $team->json('hard_limits'));
    }

    public function test_r23_category_returns_due_two_days(): void
    {
        $p = $this->person('PARTNER', 'Pia'); $mgr = $this->person('MANAGER', 'Mona');
        $this->actingAs($mgr); $this->save('task_category', ['name' => 'Returns', 'default_due_days' => 2, 'ack_hours' => 12, 'escalate_to_role' => 'PARTNER'])->assertStatus(422)->assertJsonFragment(['Refused: only a Partner can change task categories.']);
        $this->actingAs($p);
        $id = $this->save('task_category', ['code' => 'RETURNS', 'name' => 'Returns', 'default_due_days' => 2, 'ack_hours' => 12, 'escalate_to_role' => 'PARTNER', 'is_active' => 1])->assertOk()->json('id');
        $this->save('task_category', ['code' => 'RET2', 'name' => ' returns ', 'is_active' => 1])->assertStatus(422)->assertJsonFragment(['Refused: a category with that name already exists.']);
        $this->save('task_category', ['code' => 'VOICE1', 'name' => 'Voice', 'evidence_type_default' => 'VOICE', 'is_active' => 1])->assertStatus(422);
        $this->save('task_category', ['code' => 'LONG1', 'name' => 'Long ack', 'ack_hours' => 721, 'is_active' => 1])->assertStatus(422)->assertJsonFragment(['Refused: acknowledge hours is more than 0 and at most 720.']);
        $c = DB::table('task_categories')->find($id);
        $this->assertSame([2, 12, 'PARTNER', 'SCREENSHOT'], [(int) $c->default_due_days, (int) $c->ack_hours, $c->escalate_to_role, $c->evidence_type_default]);
        $this->assertSame(0, (int) $c->sort % 10, 'order in steps of 10');
        $this->assertSame(date('Y-m-d', strtotime('+2 days')), TaskCreate::prefill($id)['due'], 'new task with Returns → due today + 2');
    }
}
