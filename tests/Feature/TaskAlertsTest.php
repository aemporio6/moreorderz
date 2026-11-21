<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskAlerts;
use App\Services\TaskFlow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-50 timed alerts N2 / N3 / N5 / N7 / N12 and R-G_CORE-27 checklist + STALE flag – the spec examples. */
class TaskAlertsTest extends TestCase
{
    private function person(string $role, array $acc = []): object
    {
        $u = $this->makeUser($role);
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', $acc + ['fk_id_ent' => 1, 'account_code' => 'TA' . random_int(10000, 99999), 'first_name' => $role . 'A', 'f_active' => 1, 'wa_enabled' => 1, 'email_enabled' => 1]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a, 'fk_id_ent' => 1]); $u->fk_id_account = $a;
        return $u;
    }
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Book courier', 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => '2026-11-06 10:00:00',
            'last_update_at' => '2026-11-06 10:00:00', 'due_at' => '2026-11-20 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '']));
    }
    private function at(string $utc): CarbonImmutable { return CarbonImmutable::parse($utc, 'UTC'); }
    /** In-app rows of a rule for a task, per receiver. */
    private function bell(int $t, string $code): array
    {
        return DB::table('tm_messages')->where('task_id', $t)->where('rule_code', $code)->where('channel', 'INAPP')->orderBy('id')->get(['user_id', 'text', 'event_key'])->map(fn ($m) => [(int) $m->user_id, $m->text])->all();
    }

    public function test_r50_n2_not_acknowledged_then_escalated_once(): void
    {
        $mgr = $this->person('MANAGER'); $boss = $this->person('MANAGER'); $va = $this->person('VA'); $help = $this->person('VA');
        $cat = DB::table('task_categories')->insertGetId(RecordService::fillNotNull('task_categories', ['name' => 'Courier ' . uniqid(), 'ack_hours' => 4, 'escalate_to_role' => 'MANAGER', 'is_active' => 1]));
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'category_id' => $cat]);
        DB::table('task_helpers')->insert(['task_id' => $t, 'user_id' => $help->id]);
        TaskAlerts::notAck($this->at('2026-11-06 13:59:00')); $this->assertSame([], $this->bell($t, 'N2'), 'before 4 hours nothing');
        TaskAlerts::notAck($this->at('2026-11-06 14:00:00')); TaskAlerts::notAck($this->at('2026-11-06 15:00:00'));
        $got = $this->bell($t, 'N2'); $this->assertEqualsCanonicalizing([$help->id, $mgr->id], array_column($got, 0), 'helpers + assigner, once, never the owner');
        $this->assertStringContainsString('has not acknowledged ' . TaskFlow::ref($t) . ': Book courier after 4 hours', $got[0][1]);
        TaskAlerts::notAck($this->at('2026-11-06 18:00:00')); TaskAlerts::notAck($this->at('2026-11-06 19:00:00'));      // 4 × 2 = 8 hours
        $esc = array_values(array_filter($this->bell($t, 'N2'), fn ($m) => str_starts_with($m[1], 'ESCALATED: ')));
        $this->assertContains($boss->id, array_column($esc, 0), 'escalated once to the Managers');
        $this->assertSame(count(array_unique(array_column($esc, 0))), count($esc), 'escalation once per person');
        $this->assertStringContainsString('after 8 hours', $esc[0][1]);
        // reassigned (new): counting starts again from reassigned_at
        DB::table('tasks')->where('id', $t)->update(['reassigned_at' => '2026-11-07 09:00:00']); $n = count($this->bell($t, 'N2'));
        TaskAlerts::notAck($this->at('2026-11-07 12:00:00')); $this->assertSame($n, count($this->bell($t, 'N2')));
        TaskAlerts::notAck($this->at('2026-11-07 13:00:00')); $this->assertSame($n + 2, count($this->bell($t, 'N2')), 'a new spell after the reassignment');
        DB::table('tasks')->where('id', $t)->update(['acknowledged_at' => '2026-11-07 13:05:00']);
        TaskAlerts::notAck($this->at('2026-11-07 23:00:00')); $this->assertSame($n + 2, count($this->bell($t, 'N2')), 'acknowledged → no more');
    }

    public function test_r50_n3_due_tomorrow_at_the_owners_local_time_once(): void
    {
        $va = $this->person('VA', ['timezone' => 'Asia/Karachi']);
        $t = $this->task(['owner_user_id' => $va->id, 'due_at' => '2026-11-06 23:59:59']);
        TaskAlerts::dueTomorrow($this->at('2026-11-05 03:59:00')); $this->assertSame([], $this->bell($t, 'N3'), '08:59 Karachi – not yet');
        TaskAlerts::dueTomorrow($this->at('2026-11-05 04:00:00')); TaskAlerts::dueTomorrow($this->at('2026-11-05 09:00:00'));
        $this->assertSame([[$va->id, 'Hi VA, ' . TaskFlow::ref($t) . ': Book courier is due tomorrow (Fri 06 Nov). ' . rtrim((string) config('app.url'), '/') . '/#/tasks']], $this->bell($t, 'N3'), 'once per due date');
        // due moved to Sat → tomorrow on Fri, one new N3
        DB::table('tasks')->where('id', $t)->update(['due_at' => '2026-11-07 23:59:59']);
        TaskAlerts::dueTomorrow($this->at('2026-11-06 05:00:00')); $this->assertCount(2, $this->bell($t, 'N3'));
        // blocked → skipped
        $dep = $this->task(['owner_user_id' => $va->id]); $b = $this->task(['owner_user_id' => $va->id, 'due_at' => '2026-11-06 23:59:59', 'depends_on_task_id' => $dep]);
        TaskAlerts::dueTomorrow($this->at('2026-11-05 05:00:00')); $this->assertSame([], $this->bell($b, 'N3'));
    }

    public function test_r50_n5_late_daily_managers_from_day_three(): void
    {
        $mgr = $this->person('MANAGER'); $boss = $this->person('MANAGER'); $va = $this->person('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'due_at' => '2026-11-04 23:59:59', 'status' => 'DOING']);
        TaskAlerts::late($this->at('2026-11-05 08:59:00')); $this->assertSame([], $this->bell($t, 'N5'), 'before late_time 09:00');
        TaskAlerts::late($this->at('2026-11-05 09:00:00')); TaskAlerts::late($this->at('2026-11-05 15:00:00'));
        $d1 = $this->bell($t, 'N5'); $this->assertEqualsCanonicalizing([$va->id, $mgr->id], array_column($d1, 0), 'day 1: owner + assigner, once a day');
        $this->assertStringContainsString('is 1 day(s) late (owner', $d1[0][1]);
        TaskAlerts::late($this->at('2026-11-07 09:00:00'));
        $d3 = array_slice($this->bell($t, 'N5'), 2); $this->assertContains($boss->id, array_column($d3, 0), 'day 3: Managers added');
        $this->assertStringContainsString('is 3 day(s) late', $d3[0][1]);
        DB::table('tasks')->where('id', $t)->update(['status' => 'DONE', 'done_at' => '2026-11-07 10:00:00']); $n = count($this->bell($t, 'N5'));
        TaskAlerts::late($this->at('2026-11-08 09:00:00')); $this->assertSame($n, count($this->bell($t, 'N5')), 'DONE is never late');
    }

    public function test_r50_n7_waiting_approval_repeats_then_managers(): void
    {
        $mgr = $this->person('MANAGER'); $boss = $this->person('MANAGER'); $va = $this->person('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DONE', 'done_at' => '2026-11-06 10:00:00']);
        foreach (['2026-11-06 10:01:00', '2026-11-06 20:00:00', '2026-11-07 10:00:00', '2026-11-07 11:00:00'] as $at) TaskAlerts::waiting($this->at($at));
        $this->assertSame([$mgr->id, $mgr->id], array_column($this->bell($t, 'N7'), 0), 'at done and after 24 hours, to the assigner');
        TaskAlerts::waiting($this->at('2026-11-08 10:00:00'));
        $all = $this->bell($t, 'N7'); $may = array_values(array_filter($all, fn ($m) => str_ends_with($m[1], ' You may approve it.')));
        $this->assertContains($boss->id, array_column($may, 0), 'after 48 hours also the Managers');
        $this->assertNotContains($mgr->id, array_column($may, 0), 'the assigner is not told twice');
        $this->assertSame(3, count(array_filter($all, fn ($m) => $m[0] === $mgr->id)));
        $this->assertSame('[' . TaskFlow::ref($t) . '] Waiting your approval: Book courier', DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'N7')->where('channel', 'EMAIL')->value('subject'));
    }

    public function test_r27_stale_flag_one_n12_per_spell_and_checklist(): void
    {
        $va = $this->person('VA'); $mgr = $this->person('MANAGER');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING', 'created_at' => '2026-10-28 10:00:00', 'last_update_at' => '2026-10-28 10:00:00']);
        TaskAlerts::stale($this->at('2026-11-06 10:00:00')); TaskAlerts::stale($this->at('2026-11-06 10:01:00'));
        $this->assertContains('STALE', explode(',', (string) DB::table('tasks')->where('id', $t)->value('flags')));
        $this->assertSame([[$va->id, 'Hi VA, ' . TaskFlow::ref($t) . ': Book courier has had no update for 9 days. Please update it. ' . rtrim((string) config('app.url'), '/') . '/#/tasks']], $this->bell($t, 'N12'), 'one N12');
        $this->assertStringStartsWith('No update for a while (STALE', collect(TaskActions::page($t, $va)['banners'])->firstWhere('code', 'STALE')['text'] ?? 'none');
        TaskActions::comment($t, $mgr, 'any news?');
        $this->assertContains('STALE', explode(',', (string) DB::table('tasks')->where('id', $t)->value('flags')), 'a comment by someone else is no update');
        TaskActions::comment($t, $va, 'waiting for the courier');
        $this->assertNotContains('STALE', explode(',', (string) DB::table('tasks')->where('id', $t)->value('flags')), 'owner comments → flag removed');
        $upd = (string) DB::table('tasks')->where('id', $t)->value('last_update_at');
        TaskAlerts::stale(CarbonImmutable::parse($upd, 'UTC')->addDays(7)->subMinute()); $this->assertCount(1, $this->bell($t, 'N12'));
        TaskAlerts::stale(CarbonImmutable::parse($upd, 'UTC')->addDays(7)->addMinute()); $this->assertCount(2, $this->bell($t, 'N12'), '7 days later stale again → one new N12');
        // checklist: steps of 10, max 255, history, locked once DONE
        $this->assertSame('Item added.', TaskActions::addItem($t, $va, 'Print label'));
        TaskActions::addItem($t, $va, 'Hand to courier');
        $this->assertSame([10, 20], DB::table('task_checklist')->where('task_id', $t)->orderBy('sort')->pluck('sort')->map(fn ($x) => (int) $x)->all());
        try { TaskActions::addItem($t, $va, str_repeat('x', 256)); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('A checklist item is at most 255 characters.', $e->getMessage()); }
        $ids = DB::table('task_checklist')->where('task_id', $t)->pluck('id')->all();
        foreach ($ids as $i) TaskActions::tick((int) $i, $va, true);
        $this->assertSame('Unticked.', TaskActions::tick((int) $ids[0], $va, false)); TaskActions::tick((int) $ids[0], $va, true);
        $this->assertSame(6, DB::table('task_history')->where('task_id', $t)->where('field', 'checklist')->count(), 'every add / tick / untick in the history');
        TaskFlow::move($t, $va, 'DONE');
        try { TaskActions::tick((int) $ids[0], $va, false); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: the checklist is locked once the task is done.', $e->getMessage()); }
        try { TaskActions::addItem($t, $va, 'late'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: the checklist is locked once the task is done.', $e->getMessage()); }
        TaskAlerts::stale($this->at('2027-01-01 10:00:00')); $this->assertNotContains('STALE', explode(',', (string) DB::table('tasks')->where('id', $t)->value('flags')), 'DONE is never stale');
    }
}
