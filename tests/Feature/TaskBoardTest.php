<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskBoard as TB;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-104 Calendar and Kanban views for tasks and due dates. */
class TaskBoardTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Board task', 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-12') . ' 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0]));
    }

    public function test_g104_kanban_drag_is_the_same_move_as_the_buttons(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $other = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'title' => 'Count shelf A']);
        $this->actingAs($va); $d = $this->getJson('/api/tasks/board')->assertOk()->json();
        $this->assertSame(['TODO', 'IN_PROGRESS', 'DONE', 'APPROVED'], array_column($d['kanban'], 'key')); $this->assertSame('kanban', $d['view']);
        $this->assertContains($t, array_column($d['kanban'][0]['cards'], 'id'));
        $this->postJson("/api/tasks/$t/drop", ['column' => 'IN_PROGRESS'])->assertOk()->assertJsonPath('status', 'DOING');
        $this->assertContains($t, array_column($this->getJson('/api/tasks/board')->json('kanban')[1]['cards'], 'id'));
        $this->postJson("/api/tasks/$t/drop", ['column' => 'IN_PROGRESS'])->assertOk()->assertJsonPath('message', 'Already there.');
        $this->postJson("/api/tasks/$t/drop", ['column' => 'NOPE'])->assertStatus(422);
        $this->postJson("/api/tasks/$t/drop", ['column' => 'DONE'])->assertOk()->assertJsonPath('status', 'DONE');
        // the same rights as the button: the owner cannot approve the own task by dragging it
        $this->postJson("/api/tasks/$t/drop", ['column' => 'APPROVED'])->assertStatus(422); $this->assertSame('DONE', DB::table('tasks')->where('id', $t)->value('status'));
        $this->actingAs($mgr)->postJson("/api/tasks/$t/drop", ['column' => 'APPROVED', 'quality' => 'GOOD'])->assertOk(); $this->assertSame('APPROVED', DB::table('tasks')->where('id', $t)->value('status'));
        $this->assertGreaterThanOrEqual(3, DB::table('task_history')->where('task_id', $t)->count(), 'every drag is in the history like a button press');
        // the rule: drag a RULE task (SRC_LOT_NO_COST) to APPROVED while its problem exists → refused with the IC-9 message
        $rt = $this->task(['owner_user_id' => $va->id, 'source' => 'RULE', 'rule_code' => 'SRC_LOT_NO_COST', 'dedupe_key' => 'SRC_LOT_NO_COST:board', 'title' => 'Lot L-9 has no cost', 'status' => 'DOING']);
        $this->actingAs($mgr)->postJson("/api/tasks/$rt/drop", ['column' => 'APPROVED', 'quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: problem still present: Lot L-9 has no cost');
        $this->assertSame('DOING', DB::table('tasks')->where('id', $rt)->value('status'));
        // the view choice is kept per person
        $this->actingAs($va)->postJson('/api/tasks/board/view', ['view' => 'calendar'])->assertOk(); $this->assertSame('calendar', $this->getJson('/api/tasks/board')->json('view'));
        $this->assertSame('kanban', TB::view($other)); $this->postJson('/api/tasks/board/view', ['view' => 'gantt'])->assertStatus(422);
    }

    public function test_g104_calendar_drag_moves_the_due_date_with_history(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $ym = date('Y-m', strtotime('+1 month'));
        // the rule: drag a task from the 12th to the 15th → due date 15th with a history row
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'due_at' => "$ym-12 23:59:59"]);
        $this->actingAs($mgr); $c = $this->getJson("/api/tasks/board?ym=$ym")->assertOk()->json('calendar'); $this->assertSame("$ym-12", collect($c['tasks'])->firstWhere('id', $t)['due']);
        $h = DB::table('task_history')->where('task_id', $t)->count();
        $this->postJson("/api/tasks/$t/due", ['date' => "$ym-15", 'reason' => ''])->assertStatus(422);      // the same check as the button: a reason
        $this->postJson("/api/tasks/$t/due", ['date' => "$ym-15", 'reason' => 'Stock arrives later'])->assertOk();
        $this->assertSame("$ym-15", substr((string) DB::table('tasks')->where('id', $t)->value('due_at'), 0, 10)); $this->assertGreaterThan($h, DB::table('task_history')->where('task_id', $t)->count());
        $this->assertSame("$ym-15", collect($this->getJson("/api/tasks/board?ym=$ym")->json('calendar.tasks'))->firstWhere('id', $t)['due']);
        $this->assertIsArray($c['due_items']);
    }
}
