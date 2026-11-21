<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskLists;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-36 My tasks, 37 All tasks + export, 38 dashboard, 39 Needs attention – the spec examples. */
class TaskListsTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Job', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'last_update_at' => now(),
            'due_at' => date('Y-m-d', strtotime('+5 days')) . ' 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '', 'acknowledged_at' => now()]));
    }
    private function day(int $d): string { return date('Y-m-d', strtotime("$d days")) . ' 23:59:59'; }

    public function test_r36_tiles_late_1_and_waiting_on_me_3(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $other = $this->makeUser('VA');
        $this->task(['owner_user_id' => $mgr->id, 'due_at' => $this->day(-2)]);                          // 1 late
        $this->task(['owner_user_id' => $mgr->id, 'due_at' => $this->day(0), 'title' => 'Today']);      // due today
        foreach ([1, 2] as $i) $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DONE', 'done_at' => now()]);      // 2 to approve
        $this->task(['owner_user_id' => $other->id, 'status' => 'STUCK', 'stuck_on_type' => 'USER', 'stuck_on_id' => $mgr->id, 'stuck_since' => now()]);  // 1 stuck on him
        $d = TaskLists::mine($mgr);
        $this->assertSame([2, 1, 1, 3], [$d['tiles']['open'], $d['tiles']['late'], $d['tiles']['due_today'], $d['tiles']['waiting_on_me']]);
        $this->assertSame('Today', $d['tasks'][1]['title'], 'late first, then due today');
        $this->assertSame([2, 1], [count($d['waiting']['to_approve']), count($d['waiting']['stuck_on_me'])]);
        $this->assertSame('Nobody is waiting on you.', TaskLists::mine($va)['empty']['waiting']);
    }

    public function test_r37_chips_and_manager_export_of_late_has_17_columns(): void
    {
        $mgr = $this->makeUser('MANAGER'); $partner = $this->makeUser('PARTNER'); $va = $this->makeUser('VA');
        $l = $this->task(['owner_user_id' => $va->id, 'due_at' => $this->day(-1), 'amount_gbp' => 120]);
        $this->task(['owner_user_id' => $va->id, 'status' => 'APPROVED']);
        $a = TaskLists::all($mgr, 'late', []);
        $chips = collect($a['chips'])->pluck('count', 'key');
        $this->assertGreaterThanOrEqual(1, $chips['late']); $this->assertGreaterThanOrEqual(1, $chips['closed']); $this->assertSame(array_keys(TaskLists::CHIPS), $chips->keys()->all());
        $this->assertContains($l, array_column($a['tasks'], 'id')); $this->assertArrayNotHasKey('amount_gbp', $a['tasks'][0], 'amount column absent for a Manager');
        $this->assertSame(25, $a['per']);
        $this->assertSame([$l], array_column(TaskLists::all($mgr, 'all', ['q' => 'T-' . str_pad((string) $l, 4, '0', STR_PAD_LEFT)])['tasks'], 'id'), 'search by T-id');
        [$name, $csv] = TaskLists::export($mgr, 'late', [], '2026-11-06 14:15:00');
        $this->assertSame('tasks_late_20261106_141500.csv', $name);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $head = str_getcsv(explode("\n", substr($csv, 3))[0]);
        $this->assertCount(17, $head); $this->assertSame('id', $head[0]); $this->assertSame('score', $head[16]);
        $this->assertCount(18, str_getcsv(explode("\n", substr(TaskLists::export($partner, 'late', [])[1], 3))[0]), 'Partners get amount_gbp as column 18');
        $this->actingAs($mgr)->get('/api/tasks/export?chip=late')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        // a VA sees only his own / assigned / helper / watcher tasks in All tasks
        $this->assertSame([], array_values(array_diff(array_column(TaskLists::all($this->makeUser('VA'), 'all', [])['tasks'], 'id'), [])));
    }

    public function test_r38_closed_this_week_2_of_which_1_no_proof(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $a = $this->task(['owner_user_id' => $va->id, 'status' => 'APPROVED', 'approved_at' => now()]);
        $this->task(['owner_user_id' => $va->id, 'status' => 'APPROVED', 'approved_at' => now()]);
        DB::table('task_evidence')->insert(RecordService::fillNotNull('task_evidence', ['task_id' => $a, 'type' => 'PHOTO', 'file_name' => 'p.jpg', 'created_at' => now()]));
        $d = TaskLists::dashboard($mgr, ['person' => $va->id]);
        $this->assertSame([2, 'of which 1 no proof'], [$d['tiles']['closed_week'], $d['tiles']['closed_week_line']]);
        $this->assertArrayHasKey('modules', $d); $this->assertArrayHasKey('attention', $d);
        try { TaskLists::dashboard($va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: the tasks dashboard is for a Partner or Manager.', $e->getMessage()); }
        // data-quality rule tasks (new): one a day while an open task is not linked to a record (due_at is required in this database, so "No due date" stays 0)
        $this->task(['owner_user_id' => $va->id, 'linked_module' => null]);
        $this->assertContains('TASK_NOT_LINKED', TaskLists::dataQuality(1)); $this->assertSame([], array_values(array_intersect(['TASK_NOT_LINKED'], TaskLists::dataQuality(1))), 'once a day');
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'TASK_NOT_LINKED')->count());
    }

    public function test_r39_stuck_first_then_late_with_the_why_text(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); DB::table('t_users')->where('id', $va->id)->update(['display_name' => 'Asad']);
        $late = $this->task(['owner_user_id' => $va->id, 'due_at' => date('Y-m-d', strtotime('-2 days')) . ' 23:59:59', 'last_update_at' => '2026-11-01 10:00:00', 'title' => 'Late one']);
        $stuck = $this->task(['owner_user_id' => $va->id, 'status' => 'STUCK', 'stuck_on_type' => 'SUPPLIER', 'stuck_on_text' => 'Supplier X', 'stuck_since' => '2026-11-01 09:00:00', 'title' => 'Stuck one']);
        $rows = array_values(array_filter(TaskLists::attention($mgr, 1, 100, ['person' => $va->id])['rows'], fn ($r) => in_array($r['id'], [$late, $stuck], true)));
        $this->assertSame([$stuck, $late], array_column($rows, 'id'), 'stuck shown first');
        $this->assertSame('Late 2 days, last update 2026-11-01 — ask Asad why', $rows[1]['why']);
        $this->assertSame('stuck on Supplier X since 2026-11-01 — ask Asad why', $rows[0]['why']);
        $this->assertSame(10, TaskLists::attention($mgr)['per']);
    }
}
