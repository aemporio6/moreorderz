<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskHistory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-30 task history with readable labels, R-G_CORE-42 Help › Tasks – spec examples. */
class TaskHistoryGuideTest extends TestCase
{
    public function test_r30_reassign_two_rows_with_reason_and_money_hidden_for_a_va(): void
    {
        $mgr = $this->makeUser('MANAGER'); $a = $this->makeUser('VA'); $b = $this->makeUser('VA'); $partner = $this->makeUser('PARTNER');
        DB::table('t_users')->whereIn('id', [$a->id, $b->id])->update(['display_name' => DB::raw("CONCAT('User ', id)")]);
        $t = DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'T-0030 job', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('+3 days')),
            'source' => 'MANUAL', 'owner_user_id' => $a->id, 'assigned_by_user_id' => $mgr->id, 'acknowledged_at' => now()->subHour(), 'due_changes' => 0, 'evidence_required' => 1, 'flags' => '', 'is_confidential' => 0, 'amount_gbp' => 120]));
        TaskActions::reassign($t, $mgr, $b->id, 'A is on leave');
        $h = TaskHistory::rows($t, $mgr)['rows'];
        $this->assertSame([['Acknowledged', 'A is on leave'], ['Owner', 'A is on leave']], array_map(fn ($r) => [$r['label'], $r['reason']], array_slice($h, 0, 2)), '2 rows with the reason');
        $this->assertSame(['User ' . $a->id, 'User ' . $b->id], [$h[1]['old'], $h[1]['new']], 'user ids → names');
        DB::table('task_history')->insert([['task_id' => $t, 'at' => now(), 'user_id' => $partner->id, 'field' => 'amount_gbp', 'old_value' => '120', 'new_value' => '150', 'reason' => 'price up'],
            ['task_id' => $t, 'at' => now(), 'user_id' => 0, 'field' => 'status', 'old_value' => 'DOING', 'new_value' => 'DONE', 'reason' => null],
            ['task_id' => $t, 'at' => now(), 'user_id' => $mgr->id, 'field' => 'evidence_required', 'old_value' => '1', 'new_value' => '0', 'reason' => 'call only']]);
        $va = collect(TaskHistory::rows($t, $b)['rows'])->keyBy('field');
        $this->assertSame(['(hidden)', '(hidden)', null], [$va['amount_gbp']['old'], $va['amount_gbp']['new'], $va['amount_gbp']['reason']], 'a VA sees "(hidden)"');
        $this->assertSame(['120', '150'], [collect(TaskHistory::rows($t, $partner)['rows'])->firstWhere('field', 'amount_gbp')['old'], collect(TaskHistory::rows($t, $partner)['rows'])->firstWhere('field', 'amount_gbp')['new']]);
        $this->assertSame(['In progress', 'Waiting approval', 'System'], [$va['status']['old'], $va['status']['new'], $va['status']['by']], 'status labels, 0 = System');
        $this->assertSame(['Yes', 'No'], [$va['evidence_required']['old'], $va['evidence_required']['new']]);
        for ($i = 0; $i < 12; $i++) DB::table('task_history')->insert(['task_id' => $t, 'at' => now(), 'user_id' => $mgr->id, 'field' => 'comment', 'new_value' => "c$i"]);
        $p = $this->actingAs($b)->getJson("/api/tasks/$t/page")->assertOk()->json();
        $this->assertSame([12, 17], [count($p['history']), $p['history_total']], 'newest 12, "Show all 17 changes"');
        $this->assertCount(17, $this->getJson("/api/tasks/$t/history")->assertOk()->json('rows'));
    }

    public function test_r42_help_tasks_eight_steps_with_links(): void
    {
        $d = $this->actingAs($this->makeUser('VA'))->getJson('/api/help/tasks')->assertOk()->json();
        $this->assertCount(8, $d['steps']);
        $this->assertSame(['Created', 'Told', 'Reminders', 'Work + checklist', 'Proof', 'Done', 'Approve or rework', 'Score'], array_column($d['steps'], 'step'));
        foreach ($d['steps'] as $s) $this->assertStringStartsWith('#/', $s['link']);
        $this->assertCount(5, $d['born']); $this->assertStringContainsString('T-0012', $d['sample']);
        $this->assertContains('APPROVE 1–4', array_column($d['commands'], 'say'));
    }
}
