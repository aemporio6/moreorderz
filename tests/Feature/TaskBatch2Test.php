<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskBulk;
use App\Services\TaskEngine;
use App\Services\TaskFlow;
use App\Services\TaskHealth;
use App\Services\TaskScore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-21 health chip, 32 send reminder now, 33 bulk actions, 97 rule tasks closed with a reason snooze 7 days – the spec examples. */
class TaskBatch2Test extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Count bin A1', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(),
            'due_at' => date('Y-m-d', strtotime('+5 days')) . ' 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '']));
    }

    public function test_r21_health_chip_spec_example(): void
    {
        $h = fn ($status, $due, $x = []) => TaskHealth::of((object) ($x + ['status' => $status, 'due_at' => $due, 'depends_on_task_id' => null]), '2026-11-06');
        $this->assertSame(['LATE', 'Late 1 day', '#7C2D12', '#FDEBDD'], array_values(array_slice($h('DOING', '2026-11-05'), 0, 4)));
        $this->assertSame(['DUE_SOON', 'Due tomorrow'], [$h('DOING', '2026-11-07')['code'], $h('DOING', '2026-11-07')['label']]);
        $this->assertSame('WAITING', $h('DONE', '2026-11-01')['code'], 'DONE is waiting, never late');
        $this->assertSame(['CLOSED', 'STUCK', 'NO_DUE', 'DUE_TODAY', 'DUE_SOON', 'ON_TRACK'], [$h('APPROVED', '2026-11-01')['code'], $h('STUCK', '2026-11-01')['code'], $h('TODO', null)['code'], $h('TODO', '2026-11-06')['code'], $h('TODO', '2026-11-08')['code'], $h('TODO', '2026-11-09')['code']]);
        $dep = $this->task([]);
        $this->assertSame('BLOCKED', $h('TODO', '2026-11-20', ['depends_on_task_id' => $dep])['code']);
        $this->assertSame('Late 3 days', $h('TODO', '2026-11-03', ['depends_on_task_id' => $dep])['label'], 'LATE comes before BLOCKED');
    }

    public function test_r32_send_reminder_once_per_minute(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $other = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        $own = $this->task(['owner_user_id' => $mgr->id, 'assigned_by_user_id' => $mgr->id]);
        $done = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DONE']);
        $refused = function (callable $f, string $txt) { try { $f(); $this->fail($txt); } catch (\InvalidArgumentException $e) { $this->assertSame($txt, $e->getMessage()); } };
        $refused(fn () => TaskActions::remind($own, $mgr), 'Refused: that is your own task.');
        $refused(fn () => TaskActions::remind($done, $mgr), 'Refused: only open work can be reminded.');
        $refused(fn () => TaskActions::remind($t, $other), 'Refused: only the person who assigned it, a Partner or a Manager can send a reminder.');
        $this->assertStringStartsWith('Reminder sent to', TaskActions::remind($t, $mgr));
        $refused(fn () => TaskActions::remind($t, $mgr), 'Reminder already sent this minute (or the person has no WhatsApp number - see the message list).');
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'MANUAL')->where('channel', 'INAPP')->count(), 'one message in the log');
        $wa = DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'MANUAL')->where('channel', 'WHATSAPP')->first();
        $this->assertSame(['FAILED', 'No WhatsApp number'], [$wa->status, substr((string) $wa->error, 0, 18)]);
        $this->assertStringStartsWith('Reminder from ', (string) DB::table('tm_messages')->where('task_id', $t)->where('channel', 'INAPP')->value('text'));
        $this->assertSame(1, DB::table('task_history')->where('task_id', $t)->where('field', 'reminder_sent')->count());
    }

    public function test_r33_bulk_move_due_one_refused_and_undo(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $ids = []; for ($i = 0; $i < 5; $i++) $ids[] = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'due_changes' => $i === 2 ? 2 : 0]);
        $new = date('Y-m-d', strtotime('+12 days'));
        try { TaskBulk::preview($va, 'DUE', $ids, ['date' => $new, 'reason' => 'x']); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner or Manager can change tasks in bulk.', $e->getMessage()); }
        try { TaskBulk::preview($mgr, 'DUE', $ids, ['date' => $new]); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: a reason is required.', $e->getMessage()); }
        try { TaskBulk::preview($mgr, 'DUE', range(1, 201), ['date' => $new, 'reason' => 'x']); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: at most 200 tasks at once.', $e->getMessage()); }
        $p = TaskBulk::preview($mgr, 'DUE', $ids, ['date' => $new, 'reason' => 'stock late']);
        $this->assertSame([4, '5 task(s): Move due date… - continue?'], [$p['ok_count'], $p['confirm']]);
        $this->assertSame('Refused: the due date has already moved 2 times - only a Partner can move it again.', $p['rows'][2]['refused']);
        $this->assertSame(0, (int) DB::table('tasks')->where('id', $ids[0])->value('due_changes'), 'preview writes nothing');
        $r = TaskBulk::apply($mgr, 'DUE', $ids, ['date' => $new, 'reason' => 'stock late']);
        $this->assertSame(4, $r['done']); $this->assertStringStartsWith('4 of 5 done. Not done - ' . TaskFlow::ref($ids[2]) . ': Refused: the due date has already moved 2 times', $r['message']);
        $this->assertSame('stock late', DB::table('task_history')->where('task_id', $ids[0])->where('field', 'due_at')->value('reason'), 'each task its own history row with the reason');
        $u = TaskBulk::undo($r['job'], $mgr);
        $this->assertSame(4, $u['back']);
        $this->assertSame([0, substr((string) DB::table('tasks')->where('id', $ids[1])->value('due_at'), 0, 10)], [(int) DB::table('tasks')->where('id', $ids[0])->value('due_changes'), date('Y-m-d', strtotime('+5 days'))]);
        // reminders have no undo
        $rm = TaskBulk::apply($mgr, 'REMIND', [$ids[0], $ids[1]], []);
        $this->assertSame([2, null], [$rm['done'], $rm['job']]);
    }

    public function test_r97_rule_task_close_with_reason_snoozes_seven_days(): void
    {
        $partner = $this->makeUser('PARTNER'); $mgr = $this->makeUser('MANAGER');
        $rule = DB::table('sys_rules')->where('f_active', 1)->where('rule_code', 'SRC_LOT_NO_COST')->value('rule_code') ?? DB::table('sys_rules')->where('f_active', 1)->value('rule_code'); $this->assertNotNull($rule, 'a task rule exists');
        $t = TaskEngine::open($rule, ['SNZ', 1], 'Lot L1 has no cost', ['ent' => 1]);
        try { TaskScore::approve($t, $mgr, 'GOOD'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Refused: problem still present', $e->getMessage()); }
        try { TaskActions::closeRule($t, $mgr, 'x'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner can close a rule task with a reason.', $e->getMessage()); }
        TaskActions::closeRule($t, $partner, 'supplier invoice due Friday');
        $this->assertSame('CANCELLED', DB::table('tasks')->where('id', $t)->value('status'));
        $this->assertNull(TaskEngine::open($rule, ['SNZ', 1], 'Lot L1 has no cost', ['ent' => 1]), 'no task for 7 days');
        $this->assertCount(1, array_filter(TaskActions::snoozes(1), fn ($s) => $s['id'] === $t));
        DB::table('tasks')->where('id', $t)->update(['cancelled_at' => now()->subDays(8)]);      // day 8, cost still empty
        $n = TaskEngine::open($rule, ['SNZ', 1], 'Lot L1 has no cost', ['ent' => 1]);
        $this->assertNotNull($n); $this->assertNotSame($t, $n);
        $this->assertStringStartsWith('Follows ' . TaskFlow::ref($t), (string) DB::table('tasks')->where('id', $n)->value('description'), 'links to the old one');
        // End snooze from the list → the rule may open at once
        $t2 = TaskEngine::open($rule, ['SNZ', 2], 'Lot L2 has no cost', ['ent' => 1]); TaskActions::closeRule($t2, $partner, 'waiting');
        TaskActions::endSnooze($t2, $partner);
        $this->assertNotNull(TaskEngine::open($rule, ['SNZ', 2], 'Lot L2 has no cost', ['ent' => 1]));
    }
}
