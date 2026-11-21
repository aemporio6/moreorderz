<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-26 repeating tasks. repeat_type NONE / DAILY / WEEKLY (repeat_weekday 1 = Mon … 7 = Sun, empty = the due date's weekday) / MONTHLY (repeat_day_of_month 1–31,
 * capped to the month's last day; 31 = last day) / EVERY_N_DAYS (repeat_n 1–365); repeat_until optional. The next due = the next date after the latest instance's due date.
 * The next instance is made when the current one is APPROVED, and by the job every 10 minutes when the next due ≤ tomorrow. Never two open instances: an open one gets
 * REPEAT_BLOCKED instead (removed after close). The copy keeps title, description, category, owner (cover if absent), priority, checklist (unticked), helpers, link,
 * reference, marketplace, product, brand, amount, confidential, proof; same assigner; chain by repeat_parent_id; source REPEAT.
 */
class TaskRepeat
{
    public const TYPES = ['NONE' => 'Does not repeat', 'DAILY' => 'Every day', 'WEEKLY' => 'Every week', 'MONTHLY' => 'Every month', 'EVERY_N_DAYS' => 'Every N days'];
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** Check and clean the repeat fields of a form (empty array = fine). */
    public static function check(array &$d): array
    {
        $t = strtoupper(trim((string) ($d['repeat_type'] ?? 'NONE'))) ?: 'NONE';
        if (! isset(self::TYPES[$t])) return ['repeat_type' => 'Choose how the task repeats.'];
        $d['repeat_type'] = $t;
        if ($t === 'EVERY_N_DAYS' && ((int) ($d['repeat_n'] ?? 0) < 1 || (int) $d['repeat_n'] > 365)) return ['repeat_n' => 'Every N days: N is 1 to 365.'];
        if ($t === 'WEEKLY' && ! empty($d['repeat_weekday']) && ((int) $d['repeat_weekday'] < 1 || (int) $d['repeat_weekday'] > 7)) return ['repeat_weekday' => 'Weekday is 1 (Mon) to 7 (Sun).'];
        if ($t === 'MONTHLY' && ! empty($d['repeat_day_of_month']) && ((int) $d['repeat_day_of_month'] < 1 || (int) $d['repeat_day_of_month'] > 31)) return ['repeat_day_of_month' => 'Day of the month is 1 to 31.'];
        return [];
    }

    /** The next due date after $due (null when there is none: not repeating or past repeat_until). */
    public static function nextDue(object $t, ?string $due = null): ?string
    {
        $type = strtoupper((string) ($t->repeat_type ?? 'NONE')); if ($type === 'NONE' || $type === '') return null;
        $due = substr((string) ($due ?? $t->due_at), 0, 10); $d = new \DateTimeImmutable($due);
        $next = match ($type) {
            'DAILY' => $d->modify('+1 day'),
            'WEEKLY' => $t->repeat_weekday ? $d->modify('next ' . ['', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'][(int) $t->repeat_weekday]) : $d->modify('+7 days'),
            'MONTHLY' => (function () use ($d, $t) { $want = (int) ($t->repeat_day_of_month ?: (int) $d->format('j')); $m = $d->modify('first day of next month'); return $m->setDate((int) $m->format('Y'), (int) $m->format('n'), min($want, (int) $m->format('t'))); })(),
            'EVERY_N_DAYS' => $d->modify('+' . max(1, (int) $t->repeat_n) . ' days'),
            default => null,
        };
        if (! $next) return null;
        $s = $next->format('Y-m-d');
        return $t->repeat_until && $s > substr((string) $t->repeat_until, 0, 10) ? null : $s;
    }

    /** "weekly · Mon" + "next 2026-11-16" / "last one". */
    public static function line(object $t): ?string
    {
        $type = strtoupper((string) ($t->repeat_type ?? 'NONE')); if ($type === 'NONE' || $type === '') return null;
        $w = match ($type) { 'WEEKLY' => 'weekly · ' . self::DAYS[(int) ($t->repeat_weekday ?: (int) date('N', strtotime((string) $t->due_at)))], 'MONTHLY' => 'monthly · day ' . ((int) $t->repeat_day_of_month ?: (int) date('j', strtotime((string) $t->due_at))),
            'EVERY_N_DAYS' => 'every ' . (int) $t->repeat_n . ' days', default => 'daily' };
        $n = self::nextDue($t);
        return $w . ' · ' . ($n ? "next $n" : 'last one');
    }

    private static function root(object $t): int { return (int) ($t->repeat_parent_id ?: $t->id); }

    /** The newest instance of a chain. */
    private static function latest(int $root): object
    {
        return DB::table('tasks')->where(fn ($w) => $w->where('id', $root)->orWhere('repeat_parent_id', $root))->orderByDesc('due_at')->orderByDesc('id')->first();
    }

    /** Make the next instance of the chain of $t (when its due exists and no instance is open). Returns the new id or null. */
    public static function createNext(object $t): ?int
    {
        $latest = self::latest(self::root($t)); $due = self::nextDue($latest); if (! $due) return null;
        if (in_array($latest->status, TaskFlow::OPEN, true)) return null;
        if (DB::table('tasks')->where(fn ($w) => $w->where('id', self::root($t))->orWhere('repeat_parent_id', self::root($t)))->whereRaw('DATE(due_at) = ?', [$due])->exists()) return null;
        $owner = TaskOwner::usable((int) $latest->owner_user_id) ?: (int) $latest->owner_user_id;
        $copy = array_intersect_key((array) $latest, array_flip(['fk_id_ent', 'title', 'description', 'category_id', 'assigned_by_user_id', 'priority', 'linked_module', 'linked_record_id', 'reference_id', 'marketplace', 'product_id', 'brand_id',
            'amount_gbp', 'is_confidential', 'evidence_required', 'repeat_type', 'repeat_n', 'repeat_weekday', 'repeat_day_of_month', 'repeat_until']));
        $id = (int) DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $copy + ['owner_user_id' => $owner, 'status' => 'TODO', 'source' => 'REPEAT', 'repeat_parent_id' => self::root($t), 'due_at' => $due . ' 23:59:59',
            'due_changes' => 0, 'rework_count' => 0, 'flags' => '', 'created_at' => now(), 'last_update_at' => now()]));
        foreach (DB::table('task_checklist')->where('task_id', $latest->id)->orderBy('sort')->get() as $c) DB::table('task_checklist')->insert(['task_id' => $id, 'text' => $c->text, 'sort' => $c->sort, 'is_done' => 0]);
        foreach (DB::table('task_helpers')->where('task_id', $latest->id)->pluck('user_id') as $h) DB::table('task_helpers')->insertOrIgnore(['task_id' => $id, 'user_id' => $h]);
        DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => null, 'field' => 'status', 'old_value' => null, 'new_value' => 'TODO', 'reason' => 'Repeat of ' . TaskFlow::ref((int) $latest->id) . ($owner !== (int) $latest->owner_user_id ? ' – given to the cover' : '')]);
        DB::table('tasks')->where('id', $latest->id)->update(['flags' => trim(str_replace('REPEAT_BLOCKED', '', (string) $latest->flags), ', ')]);
        TaskFlow::announce($id);
        return $id;
    }

    /** After a task is approved / cancelled: its flag goes; the next instance is made (R-G_CORE-26). */
    public static function onClosed(int $id): ?int
    {
        $t = DB::table('tasks')->where('id', $id)->first(); if (! $t) return null;
        if (str_contains((string) $t->flags, 'REPEAT_BLOCKED')) DB::table('tasks')->where('id', $id)->update(['flags' => trim(str_replace('REPEAT_BLOCKED', '', (string) $t->flags), ', ')]);
        return $t->repeat_type && $t->repeat_type !== 'NONE' && $t->status === 'APPROVED' ? self::createNext($t) : null;
    }

    /** The job (every 10 minutes): for each chain whose next due ≤ tomorrow, make it – or flag the open instance REPEAT_BLOCKED. */
    public static function run(?string $today = null): array
    {
        $today ??= date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime("$today +1 day")); $out = ['created' => 0, 'blocked' => 0];
        $roots = DB::table('tasks')->whereNotNull('repeat_type')->where('repeat_type', '<>', 'NONE')->selectRaw('COALESCE(repeat_parent_id, id) r')->distinct()->pluck('r');
        foreach ($roots as $r) {
            $l = self::latest((int) $r); $due = self::nextDue($l); if (! $due || $due > $tomorrow) continue;
            if (in_array($l->status, TaskFlow::OPEN, true)) {
                if (! str_contains((string) $l->flags, 'REPEAT_BLOCKED')) { DB::table('tasks')->where('id', $l->id)->update(['flags' => trim((string) $l->flags . ',REPEAT_BLOCKED', ',')]); $out['blocked']++; }
            } elseif ($l->status !== 'CANCELLED' && self::createNext($l)) $out['created']++;
        }
        return $out;
    }

    /** Recurring chains for the Team page (up to 100) with their next date. */
    public static function chains(int $ent): array
    {
        $rows = [];
        foreach (DB::table('tasks')->whereNotNull('repeat_type')->where('repeat_type', '<>', 'NONE')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->selectRaw('COALESCE(repeat_parent_id, id) r')->distinct()->limit(100)->pluck('r') as $r) {
            $l = self::latest((int) $r);
            $rows[] = ['id' => (int) $l->id, 'ref' => TaskFlow::ref((int) $l->id), 'title' => $l->title, 'owner' => (string) DB::table('t_users')->where('id', $l->owner_user_id)->value('display_name'), 'line' => self::line($l), 'blocked' => str_contains((string) $l->flags, 'REPEAT_BLOCKED')];
        }
        return $rows;
    }
}
