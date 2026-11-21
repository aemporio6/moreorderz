<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-33 bulk actions on tasks – boss only: Reassign to…, Move due date…, Send reminder now, Cancel. At most 200 tasks; reason asked once and written on
 * every task (none for a reminder); a preview shows old → new per row and the rows each task's own rules refuse; apply runs every row through its single
 * action (TaskActions / TaskFlow) so the due-move limit, absence cover and weekly-limit warning still apply; one bulk_jobs row with Undo (not for reminders).
 */
class TaskBulk
{
    public const ACTIONS = ['REASSIGN' => 'Reassign to…', 'DUE' => 'Move due date…', 'REMIND' => 'Send reminder now', 'CANCEL' => 'Cancel'];
    public const MAX = 200;

    private static function check(object $u, string $action, array $ids, array $in): array
    {
        if (! in_array($u->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can change tasks in bulk.');
        if (! isset(self::ACTIONS[$action])) throw new \InvalidArgumentException('Refused: choose an action.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) throw new \InvalidArgumentException('Please select at least one checkbox');
        if (count($ids) > self::MAX) throw new \InvalidArgumentException('Refused: at most 200 tasks at once.');
        if ($action !== 'REMIND' && trim((string) ($in['reason'] ?? '')) === '') throw new \InvalidArgumentException('Refused: a reason is required.');
        if ($action === 'REASSIGN' && empty($in['owner'])) throw new \InvalidArgumentException('Refused: pick an active user.');
        if ($action === 'DUE' && empty($in['date'])) throw new \InvalidArgumentException('Refused: give a real date (YYYY-MM-DD).');
        return $ids;
    }

    /** The rows: [id, ref, title, old, new, refused reason or null]. Nothing is written. */
    public static function preview(object $u, string $action, array $ids, array $in): array
    {
        $ids = self::check($u, $action, $ids, $in); $ent = (int) Context::ent(); $rows = [];
        $who = $action === 'REASSIGN' ? (string) DB::table('t_users')->where('id', (int) $in['owner'])->value('display_name') : '';
        foreach (DB::table('tasks')->whereIn('id', $ids)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderBy('id')->get() as $t) {
            $old = match ($action) { 'REASSIGN' => (string) DB::table('t_users')->where('id', $t->owner_user_id)->value('display_name'), 'DUE' => substr((string) $t->due_at, 0, 10), default => TaskFlow::LABELS[$t->status] ?? $t->status };
            $new = match ($action) { 'REASSIGN' => $who, 'DUE' => (string) $in['date'], 'REMIND' => 'reminder', default => 'Cancelled' };
            $why = null;
            try {      // the row's own rules, without writing: run inside a transaction that is rolled back
                DB::beginTransaction(); self::one($t, $u, $action, $in); DB::rollBack();
            } catch (\InvalidArgumentException $e) { DB::rollBack(); $why = $e->getMessage(); }
            $rows[] = ['id' => (int) $t->id, 'ref' => TaskFlow::ref((int) $t->id), 'title' => $t->title, 'old' => $old, 'new' => $new, 'refused' => $why];
        }
        $ok = count(array_filter($rows, fn ($r) => ! $r['refused']));
        return ['action' => $action, 'label' => self::ACTIONS[$action], 'rows' => $rows, 'ok_count' => $ok, 'confirm' => count($rows) . ' task(s): ' . self::ACTIONS[$action] . ' - continue?'];
    }

    private static function one(object $t, object $u, string $action, array $in): void
    {
        $r = trim((string) ($in['reason'] ?? ''));
        match ($action) {
            'REASSIGN' => TaskActions::reassign((int) $t->id, $u, (int) $in['owner'], $r),
            'DUE' => TaskActions::moveDue((int) $t->id, $u, (string) $in['date'], $r),
            'REMIND' => TaskActions::remind((int) $t->id, $u),
            'CANCEL' => $t->source === 'RULE' ? throw new \InvalidArgumentException('Refused: a rule task closes by itself – a Partner can close it with a reason.') : TaskFlow::cancel((int) $t->id, $u, $r),
        };
    }

    public static function apply(object $u, string $action, array $ids, array $in): array
    {
        $ids = self::check($u, $action, $ids, $in); $ent = (int) Context::ent(); $done = []; $not = []; $warn = [];
        foreach (DB::table('tasks')->whereIn('id', $ids)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderBy('id')->get() as $t) {
            try {
                $before = ['owner_user_id' => $t->owner_user_id, 'due_at' => $t->due_at, 'due_changes' => $t->due_changes, 'flags' => $t->flags, 'status' => $t->status, 'acknowledged_at' => $t->acknowledged_at];
                $res = DB::transaction(function () use ($t, $u, $action, $in) { self::one($t, $u, $action, $in); return null; });
                if ($action === 'REASSIGN') { $l = TaskCreate::load((int) $in['owner']); if ($l['open'] > $l['limit']) $warn[0] = DB::table('t_users')->where('id', (int) $in['owner'])->value('display_name') . " now has {$l['open']} open tasks (limit {$l['limit']})."; }
                $done[] = ['id' => (int) $t->id, 'before' => $before];
            } catch (\InvalidArgumentException $e) { $not[] = TaskFlow::ref((int) $t->id) . ': ' . $e->getMessage(); }
        }
        $job = null;
        if ($done && $action !== 'REMIND')
            $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'BJ-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'task_bulk', 'fk_id_account' => $u->id,
                'change_json' => json_encode(['action' => $action, 'label' => self::ACTIONS[$action], 'in' => array_intersect_key($in, array_flip(['owner', 'date', 'reason'])), 'rows' => $done, 'ent' => Context::ent()]),
                'row_count' => count($done), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
        $n = count($done) . ' of ' . count($ids) . ' done.' . ($not ? ' Not done - ' . implode('; ', array_slice($not, 0, 5)) : '');
        return ['job' => $job, 'done' => count($done), 'not_done' => $not, 'warnings' => array_values($warn), 'message' => $n];
    }

    /** Put the tasks back as they were before the job – a task changed again since is left alone. */
    public static function undo(int $jobId, object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'task_bulk')->first() ?? throw new \InvalidArgumentException('Job not found.');
        if ((int) $j->fk_id_account !== (int) $u->id && ! in_array($u->role_code, ['OWNER', 'PARTNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Refused: only the person who did it, a Partner or a Manager can undo it.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This was already undone.');
        $c = json_decode((string) $j->change_json, true); $back = 0; $kept = 0;
        DB::transaction(function () use ($c, $j, $u, &$back, &$kept) {
            foreach ($c['rows'] as $r) {
                $t = DB::table('tasks')->where('id', $r['id'])->first(); if (! $t) { $kept++; continue; }
                $b = $r['before'];
                $still = match ($c['action']) { 'REASSIGN' => (int) $t->owner_user_id === (int) $c['in']['owner'], 'DUE' => substr((string) $t->due_at, 0, 10) === $c['in']['date'], 'CANCEL' => $t->status === 'CANCELLED', default => false };
                if (! $still) { $kept++; continue; }
                $set = match ($c['action']) {
                    'REASSIGN' => ['owner_user_id' => $b['owner_user_id'], 'acknowledged_at' => $b['acknowledged_at']],
                    'DUE' => ['due_at' => $b['due_at'], 'due_changes' => $b['due_changes'], 'flags' => (string) $b['flags']],
                    'CANCEL' => ['status' => $b['status'], 'cancelled_at' => null, 'cancelled_by_user_id' => null],
                };
                DB::table('tasks')->where('id', $t->id)->update($set + ['last_update_at' => now()]);
                DB::table('task_history')->insert(['task_id' => $t->id, 'at' => now(), 'user_id' => $u->id, 'field' => 'bulk_undo', 'old_value' => null, 'new_value' => $c['action'], 'reason' => 'Undo of ' . $j->job_no]);
                $back++;
            }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        return ['back' => $back, 'kept' => $kept, 'message' => "Undone: $back task" . ($back === 1 ? '' : 's') . ' put back' . ($kept ? " · $kept left alone (changed again since)" : '')];
    }
}
