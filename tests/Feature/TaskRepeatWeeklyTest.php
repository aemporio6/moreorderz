<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\Settings;
use App\Services\TaskCreate;
use App\Services\TaskRepeat;
use App\Services\TaskScore;
use App\Services\TaskTeam;
use App\Services\TaskWeekly;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-26 repeating tasks, R-G_CORE-44 weekly report, R-G_CORE-40 Team page + leaderboard – the spec examples. */
class TaskRepeatWeeklyTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Load settlement', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'last_update_at' => now(),
            'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '', 'repeat_type' => 'NONE']));
    }

    public function test_r26_next_dates_creation_on_approval_and_blocked_when_open(): void
    {
        $o = fn (array $x) => (object) ($x + ['repeat_n' => null, 'repeat_weekday' => null, 'repeat_day_of_month' => null, 'repeat_until' => null]);
        $this->assertSame('2026-02-28', TaskRepeat::nextDue($o(['repeat_type' => 'MONTHLY', 'repeat_day_of_month' => 31, 'due_at' => '2026-01-31'])));
        $this->assertSame('2028-02-29', TaskRepeat::nextDue($o(['repeat_type' => 'MONTHLY', 'repeat_day_of_month' => 31, 'due_at' => '2028-01-31'])), 'leap year');
        $this->assertSame('2026-11-16', TaskRepeat::nextDue($o(['repeat_type' => 'WEEKLY', 'repeat_weekday' => 1, 'due_at' => '2026-11-09'])));
        $this->assertNull(TaskRepeat::nextDue($o(['repeat_type' => 'DAILY', 'repeat_until' => '2026-11-05', 'due_at' => '2026-11-05'])), 'no instance after repeat_until');
        $this->assertSame('weekly · Mon · next 2026-11-16', TaskRepeat::line($o(['repeat_type' => 'WEEKLY', 'repeat_weekday' => 1, 'due_at' => '2026-11-09'])));
        // EVERY_N_DAYS 14, due 05 Nov, approved 06 Nov → the next is due 19 Nov
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'due_at' => '2026-11-05 23:59:59', 'repeat_type' => 'EVERY_N_DAYS', 'repeat_n' => 14, 'status' => 'DONE', 'done_at' => '2026-11-06 10:00:00']);
        DB::table('task_checklist')->insert(['task_id' => $t, 'text' => 'Download', 'sort' => 1, 'is_done' => 1]);
        TaskScore::approve($t, $mgr, 'GOOD', '2026-11-06');
        $n = DB::table('tasks')->where('repeat_parent_id', $t)->first();
        $this->assertSame(['2026-11-19', 'REPEAT', 'TODO', $va->id, $mgr->id], [substr((string) $n->due_at, 0, 10), $n->source, $n->status, (int) $n->owner_user_id, (int) $n->assigned_by_user_id]);
        $this->assertSame(0, (int) DB::table('task_checklist')->where('task_id', $n->id)->value('is_done'), 'checklist copied unticked');
        // the previous one still open the night before → REPEAT_BLOCKED, no new task
        $this->assertSame(0, TaskRepeat::run('2026-11-30')['blocked'], 'not yet'); $r = TaskRepeat::run('2026-12-02');      // the night before the next due (03 Dec)
        $this->assertSame(1, $r['blocked']); $this->assertSame(0, $r['created']);
        $this->assertStringContainsString('REPEAT_BLOCKED', (string) DB::table('tasks')->where('id', $n->id)->value('flags'));
        $this->assertSame(1, DB::table('tasks')->where('repeat_parent_id', $t)->count());
        // repeat on the New task form
        $id = TaskCreate::create(1, ['title' => 'Bank statement', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'repeat_type' => 'WEEKLY', 'repeat_weekday' => 5], $mgr)['id'];
        $this->assertSame(['WEEKLY', 5], [DB::table('tasks')->where('id', $id)->value('repeat_type'), (int) DB::table('tasks')->where('id', $id)->value('repeat_weekday')]);
        try { TaskCreate::create(1, ['title' => 'x', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'repeat_type' => 'EVERY_N_DAYS', 'repeat_n' => 0], $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: every N days: N is 1 to 365.', $e->getMessage()); }
    }

    public function test_r44_sent_once_inside_three_hours_missed_after(): void
    {
        Settings::set('COMPANY_TIMEZONE', 'Europe/London', 1);
        $p = $this->makeUser('PARTNER');
        $mon = new \DateTimeImmutable('2026-11-09 11:30:00', new \DateTimeZone('Europe/London'));
        $this->assertSame(['2026-11-02', '2026-11-08'], TaskWeekly::lastWeek($mon));
        $this->assertGreaterThanOrEqual(1, TaskWeekly::run($mon), 'run at 11:30 Monday → sent (inside 3 h)');
        $this->assertSame(0, TaskWeekly::run($mon->modify('+10 minutes')), 'run again → nothing (key N14:2026-W45 exists)');
        $this->assertTrue(DB::table('tm_messages')->where('event_key', 'like', 'N14:2026-W45|%')->where('channel', 'EMAIL')->where('text', 'like', '<div%')->exists(), 'HTML e-mail');
        $late = new \DateTimeImmutable('2026-11-16 12:30:00', new \DateTimeZone('Europe/London'));
        $this->assertSame(0, TaskWeekly::run($late), 'first run at 12:30 → not sent');
        $this->assertContains('weekly report missed (N14:2026-W46)', TaskWeekly::missed());
        $this->assertContains('weekly report missed (N14:2026-W46)', $this->actingAs($this->owner)->getJson('/api/health')->json('warnings'));
        $this->assertStringContainsString('Due date moved more than twice', TaskWeekly::html(TaskWeekly::data('2026-11-02')));
    }

    public function test_r40_leaderboard_trend_and_team_page(): void
    {
        $a = $this->makeUser('VA'); $b = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER');
        $s = fn ($u, $score, $at) => DB::table('task_scores')->insert(['task_id' => $this->task(['owner_user_id' => $u->id, 'status' => 'APPROVED', 'due_at' => $at]), 'user_id' => $u->id, 'score' => $score, 'on_time_pts' => 40, 'evidence_pts' => 20, 'quality_pts' => 30, 'rework_pts' => 0, 'closed_at' => $at]);
        $s($a, 72, '2026-10-10 10:00:00'); $s($a, 80, '2026-11-10 10:00:00'); $s($b, 60, '2026-11-11 10:00:00');
        $rows = collect(TaskTeam::leaderboard('2026-11')['rows'])->keyBy('user_id');
        $this->assertSame(['▲ +8', 'new'], [$rows[$a->id]['trend'], $rows[$b->id]['trend']]);
        $this->assertSame([1, 100, 100], [$rows[$a->id]['rank'], $rows[$a->id]['on_time'], $rows[$a->id]['proof']]);
        $p = $this->actingAs($mgr)->getJson('/api/tasks/team?month=2026-11')->assertOk()->json();
        foreach (['workload', 'moved', 'categories', 'absence', 'delegations', 'recurring', 'suggested', 'leaderboard'] as $k) $this->assertArrayHasKey($k, $p);
        $this->actingAs($a)->getJson('/api/tasks/team')->assertStatus(422);
    }
}
