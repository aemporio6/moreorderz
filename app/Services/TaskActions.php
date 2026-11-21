<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Task actions around the status machine (TaskFlow): R-G_CORE-10 acknowledge, R-G_CORE-11 dependencies (start refused while blocked, loop check,
 * unblock on approve / cancel), R-G_CORE-12 subtasks (one level, under an open parent, roll-up to DONE), R-G_CORE-13 due-date moves (limit, Partner after it,
 * owner's request), R-G_CORE-14 reassign, R-G_CORE-15 cancel Undo (10 minutes) and hiding a wrong evidence file. Every refusal starts with "Refused:".
 */
class TaskActions
{
    public const UNDO_MIN = 10;

    private static function task(int $id): object { return DB::table('tasks')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Task not found.'); }
    private static function boss(object $u): bool { return in_array($u->role_code, TaskScore::BOSS, true); }
    private static function proofOffRight(object $u): bool { return Choices::has('TASK_PROOF_OFF_ROLES', (int) (Context::ent() ?: ($u->fk_id_ent ?? 0)) ?: null, $u->role_code ?? null); }      // Settings › Choices
    private static function partner(object $u): bool { return in_array($u->role_code, ['OWNER', 'PARTNER'], true); }
    private static function name(int $id): string { return (string) (DB::table('t_users')->where('id', $id)->value('display_name') ?: '#' . $id); }
    private static function first(int $id): string { $n = self::name($id); return strtok($n, ' ') ?: $n; }
    private static function link(): string { return rtrim((string) config('app.url'), '/') . '/#/tasks'; }
    private static function hist(int $task, ?int $user, string $field, ?string $old, ?string $new, ?string $reason): void
    {
        DB::table('task_history')->insert(['task_id' => $task, 'at' => now(), 'user_id' => $user, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null]);
    }
    private static function open(object $t): void
    {
        if (! in_array($t->status, TaskFlow::OPEN, true)) throw new \InvalidArgumentException('Refused: the task is ' . (TaskFlow::LABELS[$t->status] ?? $t->status) . ' – only an open task can be changed.');
    }

    /* ---------- R-G_CORE-10 acknowledge ---------- */

    public static function acknowledge(int $id, object $u, string $via = 'APP'): string
    {
        $t = self::task($id); $ref = TaskFlow::ref($id);
        if ((int) $t->owner_user_id !== (int) $u->id) throw new \InvalidArgumentException($via === 'WHATSAPP' ? "Only the owner acknowledges $ref." : 'Refused: only the owner acknowledges a task.');
        if ($t->acknowledged_at) throw new \InvalidArgumentException('Already acknowledged.');
        self::open($t);
        DB::table('tasks')->where('id', $id)->update(['acknowledged_at' => now(), 'last_update_at' => now()]);
        self::hist($id, (int) $u->id, 'acknowledged_at', null, 'ACK', $via === 'APP' ? null : "By $via");
        EventBus::fire('TASK_ACK', 'tasks', $id, ['via' => $via], (int) $t->fk_id_ent);
        return "Thanks - $ref acknowledged. Due " . date('D d M', strtotime((string) $t->due_at)) . '.';
    }

    /* ---------- R-G_CORE-11 dependencies ---------- */

    /** The refusal when a TODO task waits for a task that is not APPROVED / CANCELLED (null = free). */
    public static function blockedBy(object $t): ?string
    {
        if (! $t->depends_on_task_id) return null;
        $d = DB::table('tasks')->where('id', $t->depends_on_task_id)->first(['id', 'title', 'status']);
        if (! $d || in_array($d->status, ['APPROVED', 'CANCELLED'], true)) return null;
        return "Refused: blocked until task #{$d->id} (\"{$d->title}\") is APPROVED.";
    }

    /** No task waits for itself, nor for a task that (up to 10 levels) waits for it. */
    public static function loopCheck(int $id, int $dep): void
    {
        if ($id === $dep) throw new \InvalidArgumentException('Refused: a task cannot wait for itself.');
        $cur = $dep;
        for ($i = 0; $i < 10 && $cur; $i++) {
            $cur = (int) DB::table('tasks')->where('id', $cur)->value('depends_on_task_id');
            if ($cur === $id) throw new \InvalidArgumentException('Refused: ' . TaskFlow::ref($dep) . ' already waits for this task – that would be a loop.');
        }
    }

    /** Set or clear "Waits for" on an open task (assigner or a boss). */
    public static function setDependency(int $id, object $u, ?int $dep): string
    {
        $t = self::task($id); self::open($t);
        if (! self::boss($u) && (int) $t->assigned_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the person who assigned it, a Partner or a Manager can change what it waits for.');
        if ($dep) {
            if (! DB::table('tasks')->where('id', $dep)->exists()) throw new \InvalidArgumentException('Refused: the task it depends on does not exist.');
            self::loopCheck($id, $dep);
        }
        DB::table('tasks')->where('id', $id)->update(['depends_on_task_id' => $dep ?: null, 'last_update_at' => now()]);
        self::hist($id, (int) $u->id, 'depends_on_task_id', $t->depends_on_task_id ? (string) $t->depends_on_task_id : null, $dep ? (string) $dep : null, null);
        return $dep ? TaskFlow::ref($id) . ' waits for ' . TaskFlow::ref($dep) . '.' : TaskFlow::ref($id) . ' waits for nothing now.';
    }

    /** The dependency $id is approved / cancelled: every waiting TODO task gets history "unblocked" and N10. */
    public static function unblock(int $id, string $how): int
    {
        $n = 0;
        foreach (DB::table('tasks')->where('depends_on_task_id', $id)->where('status', 'TODO')->get() as $w) {
            self::hist((int) $w->id, null, 'unblocked', null, (string) $id, "task #$id $how"); $n++;
            if ($w->owner_user_id) AlertRules::send('N10', (int) $w->owner_user_id, ['first_name' => self::first((int) $w->owner_user_id), 'task_ref' => TaskFlow::ref((int) $w->id), 'title' => $w->title, 'cleared_ref' => TaskFlow::ref($id), 'link' => self::link()], (int) $w->id, "N10:{$w->id}:$id");
        }
        return $n;
    }

    /* ---------- R-G_CORE-12 subtasks ---------- */

    /** The parent a new subtask may go under (open, itself not a subtask). */
    public static function parentFor(int $parent, int $ent): object
    {
        $p = DB::table('tasks')->where('id', $parent)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->first() ?? throw new \InvalidArgumentException('Refused: the parent task does not exist.');
        if ($p->parent_task_id) throw new \InvalidArgumentException('Refused: a subtask cannot have subtasks (one level only).');
        if (! in_array($p->status, TaskFlow::OPEN, true) || $p->status === 'DONE') throw new \InvalidArgumentException('Refused: a subtask can only be added under an open task.');
        return $p;
    }

    /** [approved, total] of a parent's subtasks (cancelled ones are left out of the total). */
    public static function progress(int $parent): array
    {
        $s = DB::table('tasks')->where('parent_task_id', $parent)->where('status', '<>', 'CANCELLED')->pluck('status');
        return [$s->filter(fn ($x) => $x === 'APPROVED')->count(), $s->count()];
    }

    /** A subtask was approved or cancelled: when none is open any more and one is approved, the parent goes DONE by itself (actor System) and N7 goes to its assigner. */
    public static function rollUp(int $sub): bool
    {
        $pid = (int) DB::table('tasks')->where('id', $sub)->value('parent_task_id'); if (! $pid) return false;
        $p = DB::table('tasks')->where('id', $pid)->first();
        if (! $p || ! in_array($p->status, ['TODO', 'DOING', 'STUCK', 'REWORK'], true)) return false;
        if (DB::table('tasks')->where('parent_task_id', $pid)->whereNotIn('status', ['APPROVED', 'CANCELLED'])->exists()) return false;
        [$ok] = self::progress($pid); if (! $ok) return false;
        $left = array_filter(TaskFlow::missing($p), fn ($m) => $m['code'] !== 'SUBTASKS');
        if ($left) { self::hist($pid, null, 'subtasks', null, 'ALL_APPROVED', 'all subtasks approved – ' . mb_substr(reset($left)['text'], 0, 180)); return false; }
        DB::table('tasks')->where('id', $pid)->update(['status' => 'DONE', 'done_at' => now(), 'start_at' => $p->start_at ?: now(), 'last_update_at' => now()]);
        self::hist($pid, null, 'status', $p->status, 'DONE', 'all subtasks approved');
        EventBus::fire('TASK_DONE', 'tasks', $pid, ['from' => $p->status, 'to' => 'DONE', 'via' => 'SYSTEM'], (int) $p->fk_id_ent);
        $to = $p->assigned_by_user_id ? [(int) $p->assigned_by_user_id] : DB::table('t_users')->whereIn('role_code', ['PARTNER', 'MANAGER'])->where('f_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
        foreach ($to as $uid) AlertRules::send('N7', $uid, ['first_name' => self::first($uid), 'task_ref' => TaskFlow::ref($pid), 'title' => $p->title, 'owner_name' => $p->owner_user_id ? self::name((int) $p->owner_user_id) : 'the team', 'link' => self::link()], $pid, "N7:$pid:" . date('YmdHi') . ':0');      // same key as the TaskAlerts N7 sweep (done_at, n = 0)
        return true;
    }

    /* ---------- R-G_CORE-13 due date ---------- */

    public static function limit(): int { return max(0, (int) AlertRules::threshold('due_move_limit')) ?: 2; }

    private static function checkDate(object $t, string $date): string
    {
        $date = trim($date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date) || date('Y-m-d', strtotime($date)) !== $date) throw new \InvalidArgumentException('Refused: give a real date (YYYY-MM-DD).');
        if ($date === substr((string) $t->due_at, 0, 10)) throw new \InvalidArgumentException('Refused: that is already the due date.');
        if ($date < date('Y-m-d')) throw new \InvalidArgumentException('Refused: the new due date cannot be in the past.');
        return $date;
    }

    public static function moveDue(int $id, object $u, string $date, string $reason, ?string $requested = null): string
    {
        return DB::transaction(function () use ($id, $u, $date, $reason, $requested) {
            $t = DB::table('tasks')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Task not found.');
            if (! self::boss($u) && (int) $t->assigned_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the person who assigned it, a Partner or a Manager can move the due date.');
            self::open($t);
            if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required to move the due date.');
            $date = self::checkDate($t, $date); $lim = self::limit();
            if ((int) $t->due_changes >= $lim && ! self::partner($u)) throw new \InvalidArgumentException("Refused: the due date has already moved $lim times - only a Partner can move it again.");
            $n = (int) $t->due_changes + 1;
            $flags = array_values(array_filter(explode(',', (string) $t->flags))); if ($n > $lim && ! in_array('DUE_MOVED', $flags, true)) $flags[] = 'DUE_MOVED';
            DB::table('tasks')->where('id', $id)->update(['due_at' => $date . ' 23:59:59', 'due_changes' => $n, 'flags' => implode(',', $flags), 'last_update_at' => now()]);
            self::hist($id, (int) $u->id, 'due_at', substr((string) $t->due_at, 0, 10), $date, trim($reason) . ($requested ? " (requested by $requested)" : ''));
            Audit::log('tasks', $id, 'UPDATE', ['due_at' => [substr((string) $t->due_at, 0, 10), $date], 'due_changes' => $n], trim($reason));
            return 'Due date moved to ' . date('d M Y', strtotime($date)) . " – $n of $lim used" . ($n > $lim ? ' (due moved ' . $n . '×)' : '') . '.';
        });
    }

    /** The owner asks for a new date; the assigner (or a boss) approves = the move by him (counted) or rejects = unchanged. */
    public static function requestDue(int $id, object $u, string $date, string $reason): string
    {
        $t = self::task($id); self::open($t);
        if ((int) $t->owner_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the owner can request a new due date.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required to request a new due date.');
        $date = self::checkDate($t, $date);
        if (self::pendingRequest($id)) throw new \InvalidArgumentException('Refused: a new date is already requested – wait for the answer.');
        self::hist($id, (int) $u->id, 'due_request', substr((string) $t->due_at, 0, 10), $date, trim($reason));
        $to = (int) $t->assigned_by_user_id ?: (int) DB::table('t_users')->where('role_code', 'MANAGER')->where('f_active', 1)->orderBy('id')->value('id');
        if ($to) Notify::push($to, 'TASK', self::name((int) $u->id) . ' asks to move ' . TaskFlow::ref($id) . ' to ' . date('d M', strtotime($date)) . ': ' . trim($reason), '#/tasks?scope=all', $id);
        return 'Asked for ' . date('d M Y', strtotime($date)) . ' – the person who assigned it decides.';
    }

    /** The open request of a task: the last due_request row with no decision after it. */
    public static function pendingRequest(int $id): ?object
    {
        $r = DB::table('task_history')->where('task_id', $id)->where('field', 'due_request')->orderByDesc('id')->first(); if (! $r) return null;
        return DB::table('task_history')->where('task_id', $id)->where('field', 'due_request_decided')->where('id', '>', $r->id)->exists() ? null : $r;
    }

    public static function decideDue(int $id, object $u, bool $approve, ?string $reason = null): string
    {
        $t = self::task($id); $r = self::pendingRequest($id) ?? throw new \InvalidArgumentException('Refused: there is no open date request.');
        if (! self::boss($u) && (int) $t->assigned_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the person who assigned it, a Partner or a Manager can decide.');
        if (! $approve && trim((string) $reason) === '') throw new \InvalidArgumentException('Refused: give the reason for saying no.');
        $msg = $approve ? self::moveDue($id, $u, (string) $r->new_value, (string) $r->reason, self::name((int) $r->user_id)) : 'Request rejected – the due date stays ' . date('d M Y', strtotime((string) $t->due_at)) . '.';
        self::hist($id, (int) $u->id, 'due_request_decided', (string) $r->new_value, $approve ? 'APPROVED' : 'REJECTED', $approve ? null : trim((string) $reason));
        if ($r->user_id) Notify::push((int) $r->user_id, 'TASK', TaskFlow::ref($id) . ': ' . $msg, '#/tasks', $id);
        return $msg;
    }

    /* ---------- R-G_CORE-14 reassign ---------- */

    /** Active people with open count / weekly limit, least loaded first (absent people last, with their cover). */
    public static function people(int $ent): array
    {
        $rows = [];
        foreach (DB::table('t_users')->where('f_active', 1)->orderBy('display_name')->get(['id', 'display_name', 'login_id']) as $p) {
            $l = TaskCreate::load((int) $p->id); $a = TaskOwner::absent((int) $p->id); $cover = $a ? TaskOwner::usable((int) $p->id) : 0;
            $rows[] = ['id' => (int) $p->id, 'name' => $p->display_name ?: $p->login_id, 'open' => $l['open'], 'limit' => $l['limit'], 'absent' => (bool) $a, 'cover' => $cover && $cover !== (int) $p->id ? self::name($cover) : null];
        }
        usort($rows, fn ($x, $y) => [$x['absent'], $x['open'] / max(1, $x['limit']), $x['name']] <=> [$y['absent'], $y['open'] / max(1, $y['limit']), $y['name']]);
        return $rows;
    }

    public static function reassign(int $id, object $u, int $to, string $reason): array
    {
        return DB::transaction(function () use ($id, $u, $to, $reason) {
            $t = DB::table('tasks')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Task not found.');
            if ($u->role_code === 'VA') throw new \InvalidArgumentException('Refused: a VA cannot reassign tasks.');
            self::open($t);
            if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required to reassign.');
            if (! DB::table('t_users')->where('id', $to)->where('f_active', 1)->exists()) throw new \InvalidArgumentException('Refused: pick an active user.');
            if ((int) $t->owner_user_id === $to) throw new \InvalidArgumentException('Refused: that person already owns it.');
            $warn = [];
            if (TaskOwner::absent($to)) { $c = TaskOwner::usable($to); $warn[] = self::name($to) . ' is absent' . ($c && $c !== $to ? ' – ' . self::name($c) . ' covers.' : ' and has no cover.'); }
            DB::table('tasks')->where('id', $id)->update(['owner_user_id' => $to, 'acknowledged_at' => null, 'reassigned_at' => now(), 'last_update_at' => now()]);
            self::hist($id, (int) $u->id, 'owner_user_id', $t->owner_user_id ? (string) $t->owner_user_id : null, (string) $to, trim($reason));
            if (DB::table('task_helpers')->where('task_id', $id)->where('user_id', $to)->delete()) self::hist($id, (int) $u->id, 'helper_removed', (string) $to, null, 'became owner');
            Audit::log('tasks', $id, 'UPDATE', ['owner_user_id' => [$t->owner_user_id, $to]], trim($reason));
            EventBus::fire('TASK_REASSIGNED', 'tasks', $id, ['from' => $t->owner_user_id, 'to' => $to, 'reason' => trim($reason)], (int) $t->fk_id_ent);
            AlertRules::send('N11', $to, ['first_name' => self::first($to), 'task_ref' => TaskFlow::ref($id), 'title' => $t->title, 'by' => self::name((int) $u->id), 'due' => date('D d M', strtotime((string) $t->due_at)), 'reason' => trim($reason), 'link' => self::link()], $id, "N11:$id:$to:" . date('YmdHis'));
            $l = TaskCreate::load($to); if ($l['open'] > $l['limit']) $warn[] = self::name($to) . " now has {$l['open']} open tasks (limit {$l['limit']}).";
            return ['message' => TaskFlow::ref($id) . ' now belongs to ' . self::name($to) . '.', 'warnings' => $warn];
        });
    }

    /* ---------- R-G_CORE-15 cancel Undo, hide evidence ---------- */

    public static function undoCancel(int $id, object $u): string
    {
        $t = self::task($id);
        if ($t->status !== 'CANCELLED') throw new \InvalidArgumentException('Refused: the task is not cancelled.');
        if (! self::boss($u) && (int) $t->cancelled_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the person who cancelled it, a Partner or a Manager can undo.');
        if (! $t->cancelled_at || strtotime((string) $t->cancelled_at) < time() - self::UNDO_MIN * 60) throw new \InvalidArgumentException('Refused: a cancel can be undone for ' . self::UNDO_MIN . ' minutes only.');
        $prev = (string) DB::table('task_history')->where('task_id', $id)->where('field', 'status')->where('new_value', 'CANCELLED')->orderByDesc('id')->value('old_value') ?: 'TODO';
        DB::table('tasks')->where('id', $id)->update(['status' => $prev, 'cancelled_at' => null, 'cancelled_by_user_id' => null, 'last_update_at' => now()]);
        self::hist($id, (int) $u->id, 'status', 'CANCELLED', $prev, 'Undo cancel');
        Audit::log('tasks', $id, 'UPDATE', ['status' => ['CANCELLED', $prev]], 'Undo cancel');
        return TaskFlow::ref($id) . ' is back to ' . (TaskFlow::LABELS[$prev] ?? $prev) . '.';
    }

    /** A wrong evidence file is hidden with a reason (the file is kept, it no longer counts as evidence). */
    public static function hideEvidence(int $evidenceId, object $u, string $reason): string
    {
        $e = DB::table('task_evidence')->where('id', $evidenceId)->first() ?? throw new \InvalidArgumentException('Evidence not found.');
        $t = self::task((int) $e->task_id);
        if (! self::boss($u) && (int) $t->owner_user_id !== (int) $u->id && (int) $t->assigned_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the owner, the person who assigned it, a Partner or a Manager can hide evidence.');
        if ($e->hidden_at) throw new \InvalidArgumentException('Refused: already hidden.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required to hide evidence.');
        DB::table('task_evidence')->where('id', $evidenceId)->update(['hidden_at' => now(), 'hidden_by' => $u->id, 'hidden_reason' => mb_substr(trim($reason), 0, 255)]);
        self::hist((int) $e->task_id, (int) $u->id, 'evidence_hidden', (string) $evidenceId, null, trim($reason));
        return 'Evidence hidden – the file is kept in the history.';
    }

    /* ---------- R-G_CORE-32 send reminder now ---------- */

    public const REMIND_OPEN = ['TODO', 'DOING', 'STUCK', 'REWORK'];

    public static function remind(int $id, object $u): string
    {
        $t = self::task($id);
        if ((int) $t->owner_user_id === (int) $u->id) throw new \InvalidArgumentException('Refused: that is your own task.');
        if (! self::boss($u) && (int) $t->assigned_by_user_id !== (int) $u->id) throw new \InvalidArgumentException('Refused: only the person who assigned it, a Partner or a Manager can send a reminder.');
        if (! in_array($t->status, self::REMIND_OPEN, true)) throw new \InvalidArgumentException('Refused: only open work can be reminded.');
        if (! $t->owner_user_id) throw new \InvalidArgumentException('Refused: the task has no owner.');
        $ids = AlertRules::send('MANUAL', (int) $t->owner_user_id, ['first_name' => self::first((int) $t->owner_user_id), 'by' => self::name((int) $u->id), 'task_ref' => TaskFlow::ref($id), 'title' => $t->title,
            'due' => $t->due_at ? date('D d M', strtotime((string) $t->due_at)) : 'no date', 'link' => self::link()], $id, "R:$id:" . date('YmdHi'));
        if (! $ids) throw new \InvalidArgumentException('Reminder already sent this minute (or the person has no WhatsApp number - see the message list).');
        self::hist($id, (int) $u->id, 'reminder_sent', null, (string) $t->owner_user_id, null);
        $failed = DB::table('tm_messages')->whereIn('id', $ids)->where('status', 'FAILED')->value('error');
        return 'Reminder sent to ' . self::name((int) $t->owner_user_id) . ($failed ? " (in-app only – $failed)" : '') . '.';
    }

    /* ---------- R-G_CORE-97 rule tasks: "Close with reason" snoozes ---------- */

    public static function snoozeDays(): int { return max(1, (int) AlertRules::threshold('rule_snooze_days') ?: 7); }

    /** Until when a closed rule task keeps its rule quiet (null = not snoozed / ended / over). */
    public static function snoozedUntil(object $t): ?string
    {
        $f = explode(',', (string) $t->flags);
        if ($t->status !== 'CANCELLED' || ! in_array('SNOOZED', $f, true) || in_array('SNOOZE_ENDED', $f, true) || ! $t->cancelled_at) return null;
        $until = date('Y-m-d H:i:s', strtotime((string) $t->cancelled_at . ' +' . self::snoozeDays() . ' days'));
        return $until > now()->toDateTimeString() ? $until : null;
    }

    public static function closeRule(int $id, object $u, string $reason): string
    {
        return DB::transaction(function () use ($id, $u, $reason) {
            $t = DB::table('tasks')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Task not found.');
            if (! self::partner($u)) throw new \InvalidArgumentException('Refused: only a Partner can close a rule task with a reason.');
            if ($t->source !== 'RULE') throw new \InvalidArgumentException('Refused: only a task opened by a rule is closed with a reason – cancel this one.');
            self::open($t);
            if (trim($reason) === '') throw new \InvalidArgumentException('Refused: a reason is required.');
            $f = array_values(array_diff(array_filter(explode(',', (string) $t->flags)), ['SNOOZE_ENDED'])); $f[] = 'SNOOZED';
            DB::table('tasks')->where('id', $id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by_user_id' => $u->id, 'flags' => implode(',', array_unique($f)), 'last_update_at' => now()]);
            $d = self::snoozeDays();
            self::hist($id, (int) $u->id, 'status', $t->status, 'CANCELLED', 'Closed with reason: ' . trim($reason) . " – the rule stays quiet $d days");
            Audit::log('tasks', $id, 'UPDATE', ['status' => [$t->status, 'CANCELLED'], 'snooze_days' => $d], trim($reason));
            self::unblock($id, 'closed with reason');
            return TaskFlow::ref($id) . " closed – {$t->rule_code} will not open this again for $d days.";
        });
    }

    public static function endSnooze(int $id, object $u): string
    {
        $t = self::task($id);
        if (! self::partner($u)) throw new \InvalidArgumentException('Refused: only a Partner can end a snooze.');
        if (! self::snoozedUntil($t)) throw new \InvalidArgumentException('Refused: this task is not snoozed.');
        DB::table('tasks')->where('id', $id)->update(['flags' => trim((string) $t->flags . ',SNOOZE_ENDED', ',')]);
        self::hist($id, (int) $u->id, 'snooze', 'SNOOZED', 'ENDED', null);
        return 'Snooze ended – the rule opens a new task when the problem is still there.';
    }

    /** Open snoozes of the company (for the Everyone view). */
    public static function snoozes(int $ent): array
    {
        return DB::table('tasks')->where('source', 'RULE')->where('status', 'CANCELLED')->where('flags', 'like', '%SNOOZED%')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))
            ->orderByDesc('cancelled_at')->limit(100)->get()->filter(fn ($t) => self::snoozedUntil($t))->map(fn ($t) => ['id' => (int) $t->id, 'ref' => TaskFlow::ref((int) $t->id), 'title' => $t->title, 'rule' => $t->rule_code,
                'until' => substr((string) self::snoozedUntil($t), 0, 10), 'by' => $t->cancelled_by_user_id ? self::name((int) $t->cancelled_by_user_id) : '',
                'reason' => (string) DB::table('task_history')->where('task_id', $t->id)->where('new_value', 'CANCELLED')->orderByDesc('id')->value('reason')])->values()->all();
    }

    /* ---------- R-G_CORE-6 proof switch ---------- */

    public static function proofOff(int $id, object $u, string $reason): string
    {
        $t = self::task($id); self::open($t);
        if (! self::proofOffRight($u)) throw new \InvalidArgumentException('Refused: only a Partner or Manager may switch "evidence required" off.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Refused: give a reason for switching evidence off.');
        if (! (int) $t->evidence_required) throw new \InvalidArgumentException('Refused: proof is already off.');
        DB::table('tasks')->where('id', $id)->update(['evidence_required' => 0, 'evidence_waived_by' => $u->id, 'evidence_waived_at' => now(), 'last_update_at' => now()]);
        self::hist($id, (int) $u->id, 'evidence_required', '1', '0', trim($reason));
        return 'Proof switched off.';
    }

    public static function proofOn(int $id, object $u): string
    {
        $t = self::task($id); self::open($t);
        if (! self::boss($u)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can require proof again.');
        DB::table('tasks')->where('id', $id)->update(['evidence_required' => 1, 'evidence_waived_by' => null, 'evidence_waived_at' => null, 'last_update_at' => now()]);
        self::hist($id, (int) $u->id, 'evidence_required', '0', '1', null);
        return 'Proof is required again.';
    }

    /* ---------- the work on the task page: checklist, proof, comments (R-G_CORE-41 tabs) ---------- */

    private static function contributor(object $t, object $u): bool
    {
        return TaskFlow::mayWork($t, $u) || (int) $t->assigned_by_user_id === (int) $u->id || DB::table('task_helpers')->where('task_id', $t->id)->where('user_id', $u->id)->exists();
    }

    public static function tick(int $itemId, object $u, bool $done): string
    {
        $c = DB::table('task_checklist')->where('id', $itemId)->first() ?? throw new \InvalidArgumentException('Checklist item not found.');
        $t = self::task((int) $c->task_id); self::open($t);
        if ($t->status === 'DONE') throw new \InvalidArgumentException('Refused: the checklist is locked once the task is done.');      // R-G_CORE-27
        if (! self::contributor($t, $u)) throw new \InvalidArgumentException('Refused: only the people working on the task can tick its checklist.');
        DB::table('task_checklist')->where('id', $itemId)->update(['is_done' => $done ? 1 : 0, 'done_by' => $done ? $u->id : null, 'done_at' => $done ? now() : null]);
        self::hist((int) $t->id, (int) $u->id, 'checklist', $done ? '0' : '1', $done ? '1' : '0', mb_substr((string) $c->text, 0, 200));
        DB::table('tasks')->where('id', $t->id)->update(['last_update_at' => now()]); TaskAlerts::fresh((int) $t->id);
        return $done ? 'Ticked.' : 'Unticked.';
    }

    /** R-G_CORE-27: add a checklist item (text up to 255, sorted in steps of 10) while the task is open and not done. */
    public static function addItem(int $id, object $u, string $text): string
    {
        $t = self::task($id); self::open($t);
        if ($t->status === 'DONE') throw new \InvalidArgumentException('Refused: the checklist is locked once the task is done.');
        if (! self::contributor($t, $u)) throw new \InvalidArgumentException('Refused: only the people working on the task can add to its checklist.');
        $text = trim($text); if ($text === '') throw new \InvalidArgumentException('Write the item first.');
        if (mb_strlen($text) > 255) throw new \InvalidArgumentException('A checklist item is at most 255 characters.');
        $sort = (int) ceil(((int) DB::table('task_checklist')->where('task_id', $id)->max('sort')) / 10) * 10 + 10;
        DB::table('task_checklist')->insert(['task_id' => $id, 'text' => $text, 'sort' => $sort, 'is_done' => 0]);
        self::hist($id, (int) $u->id, 'checklist', null, 'ADDED', mb_substr($text, 0, 200));
        DB::table('tasks')->where('id', $id)->update(['last_update_at' => now()]); TaskAlerts::fresh($id);
        return 'Item added.';
    }

    public static function comment(int $id, object $u, string $text): string { return TaskComments::add($id, $u, $text)['message']; }      // R-G_CORE-28

    /** Proof by the web / phone camera: a file (photo, PDF, office), a link or a reference. */
    public static function addProof(int $id, object $u, ?\Illuminate\Http\UploadedFile $file, ?string $link, ?string $ref, ?string $note = null, ?string $kind = null): string
    {
        TaskEvidence::add($id, $u, $file, $link, $ref, $note, $kind);      // R-G_CORE-29
        return 'Proof added.';
    }

    /* ---------- R-G_CORE-41 the task record page ---------- */

    public static function page(int $id, object $u): array
    {
        $t = self::task($id);
        if (! TaskFlow::canSee($t, $u)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Task not found.');
        $p = self::panel($id, $u); $partner = self::partner($u); $name = fn ($x) => $x ? self::name((int) $x) : null;
        $row = TaskLists::decorate(DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')->where('t.id', $id)->get(['t.*', 'u.display_name as owner', 'c.name as category']), $u)[0];
        $stale = (int) AlertRules::threshold('stale_days') ?: 7; $banners = [];
        if (str_contains((string) $t->flags, 'STALE') || (in_array($t->status, TaskAlerts::ACTIVE, true) && strtotime((string) ($t->last_update_at ?: $t->created_at)) < time() - $stale * 86400)) {      // R-G_CORE-27 (day count new)
            $sd = (int) floor((time() - strtotime((string) ($t->last_update_at ?: $t->created_at))) / 86400);
            $banners[] = ['STALE', "No update for a while (STALE – $sd days). Tick a checklist item, add proof or write a comment to clear it."];
        }
        if (str_contains((string) $t->flags, 'REPEAT_BLOCKED')) $banners[] = ['REPEAT_BLOCKED', 'The next repeat of this task was not created because this one is still open. Close it and the next one appears.'];
        if ($p['waits_for'] && $p['waits_for']['blocked']) $banners[] = ['BLOCKED', "Blocked: waits for {$p['waits_for']['ref']} {$p['waits_for']['title']}. No reminders until it is approved."];
        if ($t->status === 'STUCK') $banners[] = ['STUCK', 'Stuck since ' . substr((string) $t->stuck_since, 0, 10) . ' on ' . (Guide::STUCK_ON[$t->stuck_on_type] ?? 'something') . ': ' . ($t->stuck_on_type === 'USER' ? $name($t->stuck_on_id) : $t->stuck_on_text)];
        if ($t->parent_task_id) $banners[] = ['SUBTASK', 'This is a subtask of ' . TaskFlow::ref((int) $t->parent_task_id) . ' – the parent is done when all its subtasks are approved.'];
        if ($t->owner_user_id && ($a = TaskOwner::absent((int) $t->owner_user_id))) { $c = TaskOwner::usable((int) $t->owner_user_id); $banners[] = ['ABSENT', self::name((int) $t->owner_user_id) . ' is away until ' . ($a->to_date ?: 'further notice') . ($c && $c !== (int) $t->owner_user_id ? ' – ' . self::name($c) . ' covers.' : ' and has no cover.')]; }
        $repeat = TaskRepeat::line($t);      // R-G_CORE-26 "weekly · Mon · next 2026-11-16" / "last one"
        $ev = TaskEvidence::gallery($id);      // R-G_CORE-29 gallery
        $hist = DB::table('task_history as h')->leftJoin('t_users as x', 'x.id', '=', 'h.user_id')->where('h.task_id', $id)->orderByDesc('h.id')->limit(200)->get(['h.at', 'h.field', 'h.old_value', 'h.new_value', 'h.reason', 'x.display_name as by']);
        $can = $p['can'] + ['proof_off' => self::proofOffRight($u) && in_array($t->status, TaskFlow::OPEN, true) && (int) $t->evidence_required, 'proof_on' => self::boss($u) && in_array($t->status, TaskFlow::OPEN, true) && ! (int) $t->evidence_required,
            'work' => in_array($t->status, TaskFlow::OPEN, true) && self::contributor($t, $u)];
        return ['task' => $row, 'panel' => $p, 'can' => $can, 'banners' => array_map(fn ($b) => ['code' => $b[0], 'text' => $b[1]], $banners),
            'facts' => array_filter(['Reference' => $t->reference_id, 'Assigned by (approver)' => $name($t->assigned_by_user_id) ?: 'System', 'Helpers' => implode(', ', $row['helpers']) ?: null, 'Link' => $row['linked'],
                'Marketplace' => $t->marketplace, 'Product' => $t->product_id ? '#' . $t->product_id : null, 'Amount £' => $partner && $t->amount_gbp !== null ? number_format((float) $t->amount_gbp, 2) : null,
                'Confidential' => (int) $t->is_confidential ? 'Yes' : null, 'Repeat' => $repeat,
                'Proof required' => (int) $t->evidence_required ? 'Yes' : 'No' . ($t->evidence_waived_at ? ' (switched off ' . date('d M H:i', strtotime((string) $t->evidence_waived_at)) . ')' : ''),
                'Blocked by' => $p['waits_for'] && $p['waits_for']['blocked'] ? $p['waits_for']['ref'] : null], fn ($v) => $v !== null && $v !== ''),
            'score' => $t->status === 'APPROVED' && $t->score !== null ? ['final' => (float) $t->score, 'band' => TaskScore::band((float) $t->score)] : ($t->status === 'DONE' ? ['preview' => TaskScore::parts($t, 'GOOD')] : null),
            'checklist' => DB::table('task_checklist')->where('task_id', $id)->orderBy('sort')->get(['id', 'text', 'is_done', 'done_at']),
            'proof' => $ev, 'comments' => TaskComments::rows($id), 'people' => TaskComments::people((int) $t->fk_id_ent ?: null),
            'history' => $hist, 'stuck_on' => Guide::STUCK_ON, 'qualities' => array_keys(TaskScore::QUALITY)];
    }

    /* ---------- the panel of one task ---------- */

    public static function panel(int $id, object $u): array
    {
        $t = self::task($id);
        if (! TaskFlow::canSee($t, $u)) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'This task belongs to someone else.');
        $dep = $t->depends_on_task_id ? DB::table('tasks')->where('id', $t->depends_on_task_id)->first(['id', 'title', 'status']) : null;
        $subs = DB::table('tasks')->where('parent_task_id', $id)->orderBy('id')->get(['id', 'title', 'status']);
        [$ok, $all] = self::progress($id); $req = self::pendingRequest($id); $open = in_array($t->status, TaskFlow::OPEN, true); $lim = self::limit();
        $canDue = $open && (self::boss($u) || (int) $t->assigned_by_user_id === (int) $u->id);
        return ['id' => $id, 'ref' => TaskFlow::ref($id), 'title' => $t->title, 'status' => $t->status, 'label' => TaskFlow::LABELS[$t->status] ?? $t->status, 'due' => substr((string) $t->due_at, 0, 10),
            'ack' => $t->acknowledged_at ? 'Yes · ' . date('d M H:i', strtotime((string) $t->acknowledged_at)) : null,
            'due_changes' => (int) $t->due_changes, 'due_limit' => $lim, 'due_moved' => str_contains((string) $t->flags, 'DUE_MOVED'),
            'waits_for' => $dep ? ['id' => (int) $dep->id, 'ref' => TaskFlow::ref((int) $dep->id), 'title' => $dep->title, 'status' => $dep->status, 'blocked' => ! in_array($dep->status, ['APPROVED', 'CANCELLED'], true)] : null,
            'parent' => $t->parent_task_id ? ['id' => (int) $t->parent_task_id, 'ref' => TaskFlow::ref((int) $t->parent_task_id)] : null,
            'subtasks' => $subs->map(fn ($s) => ['id' => (int) $s->id, 'ref' => TaskFlow::ref((int) $s->id), 'title' => $s->title, 'status' => $s->status, 'label' => TaskFlow::LABELS[$s->status] ?? $s->status])->all(),
            'progress' => $all ? "$ok of $all approved" : null, 'progress_pct' => $all ? (int) round($ok * 100 / $all) : 0,
            'request' => $req ? ['date' => $req->new_value, 'reason' => $req->reason, 'by' => self::name((int) $req->user_id)] : null,
            'can' => ['ack' => $open && ! $t->acknowledged_at && (int) $t->owner_user_id === (int) $u->id,
                'move_due' => $canDue && ((int) $t->due_changes < $lim || self::partner($u)), 'request_due' => $open && (int) $t->owner_user_id === (int) $u->id && ! $req && ! $canDue,
                'decide_due' => (bool) $req && $canDue, 'reassign' => $open && $u->role_code !== 'VA', 'cancel' => $open && self::boss($u),
                'undo' => $t->status === 'CANCELLED' && $t->cancelled_at && strtotime((string) $t->cancelled_at) >= time() - self::UNDO_MIN * 60 && (self::boss($u) || (int) $t->cancelled_by_user_id === (int) $u->id),
                'subtask' => ! $t->parent_task_id && in_array($t->status, ['TODO', 'DOING', 'STUCK', 'REWORK'], true), 'waits' => $canDue,
                'remind' => in_array($t->status, self::REMIND_OPEN, true) && (int) $t->owner_user_id !== (int) $u->id && (self::boss($u) || (int) $t->assigned_by_user_id === (int) $u->id),
                'close_rule' => $open && $t->source === 'RULE' && self::partner($u), 'end_snooze' => self::partner($u) && self::snoozedUntil($t) !== null],
            'people' => $open && $u->role_code !== 'VA' ? self::people((int) Context::ent()) : [], 'health' => TaskHealth::of($t), 'rule' => $t->source === 'RULE' ? $t->rule_code : null,
            'snoozed_until' => self::snoozedUntil($t)];
    }
}
