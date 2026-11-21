<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * One task state table for web, phone, API and WhatsApp (R-G_CORE-1 status machine, R-G_CORE-5 Done gate).
 * Moves by a person: TODO→DOING/STUCK; DOING→STUCK/DONE; STUCK→DOING/DONE; REWORK→DOING; DONE→APPROVED/REWORK by an approver (TaskScore);
 * any open status→CANCELLED by a boss with a reason. Only the system skips steps. A RULE task is never marked done by hand.
 * Done gate, in this order: open checklist items; subtasks not APPROVED / CANCELLED; evidence_required and no counting evidence (VOICE never counts).
 * DONE from TODO or REWORK by WhatsApp / phone first moves to DOING (history shows both).
 */
class TaskFlow
{
    public const LABELS = ['TODO' => 'Open', 'DOING' => 'In progress', 'STUCK' => 'Stuck', 'DONE' => 'Waiting approval', 'REWORK' => 'Rework', 'APPROVED' => 'Closed', 'CANCELLED' => 'Cancelled'];
    public const OPEN = ['TODO', 'DOING', 'STUCK', 'DONE', 'REWORK'];
    public const MOVES = ['TODO' => ['DOING', 'STUCK'], 'DOING' => ['STUCK', 'DONE'], 'STUCK' => ['DOING', 'DONE'], 'REWORK' => ['DOING'], 'DONE' => [], 'APPROVED' => [], 'CANCELLED' => []];
    public const COUNTING = ['SCREENSHOT', 'PHOTO', 'FILE', 'LINK', 'REFERENCE'];

    /** R-G_CORE-3: the four priorities (list TASK_PRIORITY, sort URGENT > HIGH > NORMAL > LOW) and the Notify level derived from them (never stored). */
    public const PRIORITIES = ['URGENT' => 'Urgent', 'HIGH' => 'High', 'NORMAL' => 'Normal', 'LOW' => 'Low'];
    public static function level(?string $priority): string { return strtoupper((string) $priority) === 'URGENT' ? 'URGENT' : 'NORMAL'; }

    /** N1 "New task" to the owner – at once; only an URGENT task goes through quiet hours (AlertRules::send). */
    public static function announce(int $id): array
    {
        $t = DB::table('tasks')->where('id', $id)->first(); if (! $t || ! $t->owner_user_id) return [];
        $n = (string) DB::table('t_users')->where('id', $t->owner_user_id)->value('display_name');
        return AlertRules::send('N1', (int) $t->owner_user_id, ['first_name' => strtok($n, ' ') ?: $n, 'task_ref' => self::ref($id), 'title' => $t->title, 'due' => $t->due_at ? date('D d M', strtotime((string) $t->due_at)) : 'no date',
            'link' => rtrim((string) config('app.url'), '/') . '/#/tasks'], $id, "N1:$id");
    }

    public static function ref(int $id): string { return 'T-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT); }

    /** Work (status moves) = owner, a boss (status right), or the cover of the absent owner (R-G_CORE-19); a confidential task: owner and Partners only. */
    public static function mayWork(object $t, object $u): bool
    {
        if ((int) $t->owner_user_id === (int) $u->id) return true;
        if ((int) ($t->is_confidential ?? 0)) return TaskVisibility::partner($u);
        return Perm::has($u, 'work.taskStatus_may') || in_array((int) $t->owner_user_id, TaskVisibility::covered((int) $u->id), true);
    }

    /** May this person see the task (owner, assigner, helper, watcher, a boss, or the status right)? */
    public static function canSee(object $t, object $u): bool { return TaskVisibility::canSee($t, $u); }      // R-G_CORE-19 one rule

    /** What still stops DONE (the Done gate), in the rule's order. Empty = may be done. */
    public static function missing(object $t): array
    {
        $out = [];
        $open = DB::table('task_checklist')->where('task_id', $t->id)->where('is_done', 0)->count();
        if ($open) $out[] = ['code' => 'CHECKLIST', 'text' => "Refused: $open checklist item" . ($open === 1 ? ' is' : 's are') . ' not ticked yet.'];
        $sub = DB::table('tasks')->where('parent_task_id', $t->id)->whereNotIn('status', ['APPROVED', 'CANCELLED'])->count();
        if ($sub) $out[] = ['code' => 'SUBTASKS', 'text' => "Refused: $sub subtask" . ($sub === 1 ? ' is' : 's are') . ' not approved yet. The parent becomes Done by itself when the last subtask is approved (R5).'];
        if ((int) $t->evidence_required === 1 && ! DB::table('task_evidence')->where('task_id', $t->id)->whereNull('hidden_at')->whereIn('type', self::COUNTING)->exists())
            $out[] = ['code' => 'EVIDENCE', 'text' => DB::table('task_evidence')->where('task_id', $t->id)->where('type', 'VOICE')->exists()
                ? 'Refused: a voice note is not evidence. Attach a screenshot, photo, file, link or reference first.' : 'Refused: attach evidence first (screenshot, photo, file, link or reference).'];
        return $out;
    }

    /** The refusal text of a move a person may not make (null = allowed by the state table). */
    public static function moveRefusal(object $t, string $to): ?string
    {
        if (in_array($to, self::MOVES[$t->status] ?? [], true)) return null;
        $label = fn ($s) => self::LABELS[$s] ?? $s;
        return match ($to) {
            'DONE' => $t->status === 'TODO' ? 'Refused: a TODO task cannot be marked done - start it first.' : "Refused: a task in {$label($t->status)} cannot go to {$label($to)}.",
            'DOING' => "Refused: a {$t->status} task cannot be started.",
            'STUCK' => "Refused: a {$t->status} task cannot be marked stuck.",
            default => "Refused: a task in {$label($t->status)} cannot go to {$label($to)}.",
        };
    }

    /** The buttons a person sees = allowed moves ∩ rights (the UI never shows a move the server would refuse). */
    public static function actionsFor(object $t, object $u): array
    {
        $a = [];
        if (self::mayWork($t, $u)) foreach (self::MOVES[$t->status] ?? [] as $to) if (! ($to === 'DONE' && $t->source === 'RULE')) $a[] = $to;
        if ($t->status === 'DONE' && TaskScore::refusal($t, $u) === null) array_push($a, 'APPROVED', 'REWORK');
        if (in_array($t->status, self::OPEN, true) && in_array($u->role_code, TaskScore::BOSS, true)) $a[] = 'CANCELLED';
        return $a;
    }

    private static function history(int $id, int $user, string $from, string $to, ?string $note): void
    {
        DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $user, 'field' => 'status', 'old_value' => $from, 'new_value' => $to, 'reason' => $note ? mb_substr($note, 0, 255) : null]);
    }

    /**
     * A person moves a task. $via = APP | WHATSAPP | PHONE. Throws \InvalidArgumentException with the refusal text; 403 when the task is not his.
     * Returns the task row after the move.
     */
    public static function move(int $id, object $u, string $to, ?string $note = null, string $via = 'APP'): object
    {
        return DB::transaction(function () use ($id, $u, $to, $note, $via) {
            $t = DB::table('tasks')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Task not found.');
            if (! self::mayWork($t, $u)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'This task belongs to someone else.');
            if ($to === 'DONE' && $t->source === 'RULE') throw new \InvalidArgumentException('This task was opened by a rule. It closes by itself when the problem is fixed' . ($t->fix_url ? ' – use the Fix link.' : '.'));
            if ($to === 'STUCK' && ! trim((string) $note)) throw new \InvalidArgumentException('Say what you are stuck on.');
            if ($to === 'DONE' && $via !== 'APP' && in_array($t->status, ['TODO', 'REWORK'], true)) {      // WhatsApp / phone: start first, history shows both
                if ($m = self::missing($t)) throw new \InvalidArgumentException($m[0]['text']);
                DB::table('tasks')->where('id', $id)->update(['status' => 'DOING', 'start_at' => $t->start_at ?: now(), 'last_update_at' => now()]);
                self::history($id, (int) $u->id, $t->status, 'DOING', "Started by $via before done"); $t->status = 'DOING';
            }
            if ($why = self::moveRefusal($t, $to)) throw new \InvalidArgumentException($why);
            if ($to === 'DOING' && $t->status === 'TODO' && ($why = TaskActions::blockedBy($t))) throw new \InvalidArgumentException($why);      // R-G_CORE-11
            if ($to === 'DONE' && ($m = self::missing($t))) throw new \InvalidArgumentException($m[0]['text']);
            if ($t->status === 'STUCK') Guide::unstuck($id);
            DB::table('tasks')->where('id', $id)->update(['status' => $to, 'last_update_at' => now(), 'done_at' => $to === 'DONE' ? now() : null,
                'start_at' => $to === 'DOING' ? ($t->start_at ?: now()) : $t->start_at, 'stuck_since' => $to === 'STUCK' ? now() : null, 'stuck_on_text' => $to === 'STUCK' ? mb_substr((string) $note, 0, 255) : null]);
            self::history($id, (int) $u->id, $t->status, $to, $note ?: ($via !== 'APP' ? "By $via" : null));
            Audit::log('tasks', $id, 'UPDATE', ['status' => [$t->status, $to], 'via' => $via]);
            EventBus::fire(['DOING' => $t->status === 'STUCK' ? 'TASK_UNBLOCKED' : 'TASK_STARTED', 'STUCK' => 'TASK_STUCK', 'DONE' => 'TASK_DONE'][$to] ?? 'TASK_STARTED', 'tasks', $id, ['from' => $t->status, 'to' => $to, 'via' => $via], (int) $t->fk_id_ent);      // an event per move (Home, history)
            return DB::table('tasks')->where('id', $id)->first();
        });
    }

    /** A boss cancels an open task with a reason (tasks are never deleted). */
    public static function cancel(int $id, object $u, string $reason): object
    {
        $t = DB::table('tasks')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Task not found.');
        if (! in_array($u->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can cancel a task.');
        if (! in_array($t->status, self::OPEN, true)) throw new \InvalidArgumentException("Refused: a task in " . self::LABELS[$t->status] . ' cannot go to Cancelled.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required to cancel.');
        DB::table('tasks')->where('id', $id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by_user_id' => $u->id, 'last_update_at' => now()]);
        self::history($id, (int) $u->id, $t->status, 'CANCELLED', trim($reason)); Audit::log('tasks', $id, 'UPDATE', ['status' => [$t->status, 'CANCELLED']], trim($reason));
        EventBus::fire('TASK_CANCELLED', 'tasks', $id, ['from' => $t->status, 'reason' => trim($reason)], (int) $t->fk_id_ent);
        TaskActions::unblock($id, 'cancelled'); TaskActions::rollUp($id); TaskRepeat::onClosed($id);      // R-G_CORE-11 waiting tasks may start, R-G_CORE-12 the parent may be done now
        return DB::table('tasks')->where('id', $id)->first();
    }
}
