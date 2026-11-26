<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-19 who sees a task – one rule for the record and every list, count, export, bell, digest and API:
 * - a Partner (Owner counts as Partner) sees all; a CONFIDENTIAL task only its owner and the Partners (not a Manager, cover, helper or watcher);
 * - a Manager sees every task that is not confidential;
 * - anyone else: tasks he owns, assigned, is stuck on, helps, watches, or covers for an absent owner (the cover is new);
 * - only tasks of the company he works in (fk_id_ent = the current company, every company in All companies mode; system rows with 0 / empty too).
 * Not visible → "Task not found." (404).
 */
class TaskVisibility
{
    public static function partner(object $u): bool { return in_array($u->role_code ?? '', ['OWNER', 'PARTNER'], true); }

    /** Owners this person covers today (absent owners whose cover is him). */
    public static function covered(int $userId): array
    {
        $acc = (int) DB::table('t_users')->where('id', $userId)->value('fk_id_account'); if (! $acc) return [];
        $today = date('Y-m-d');
        $absent = DB::table('user_absence')->where('cover_user_id', $acc)->where('from_date', '<=', $today)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $today))->pluck('user_id');
        return DB::table('t_users')->whereIn('fk_id_account', $absent->all() ?: [0])->where('f_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** The rule as a SQL condition on a query of "tasks as t". */
    public static function apply($q, object $u, string $a = 't')
    {
        $ents = Context::all() ? Context::ents() : array_filter([Context::ent() ?: (int) ($u->fk_id_ent ?? 0)]);      // a job without a session uses the person's company; R-G_CORE-102 every company in All companies mode
        if ($ents) $q->where(fn ($w) => $w->whereIn("$a.fk_id_ent", $ents)->orWhere("$a.fk_id_ent", 0)->orWhereNull("$a.fk_id_ent"));
        if (self::partner($u)) return $q;
        $notConf = fn ($w) => $w->whereNull("$a.is_confidential")->orWhere("$a.is_confidential", 0);
        if (($u->role_code ?? '') === 'MANAGER' || Perm::has($u, 'work.tasks_may')) return $q->where(fn ($w) => $w->where($notConf)->orWhere("$a.owner_user_id", $u->id));
        $cov = self::covered((int) $u->id);
        return $q->where(fn ($w) => $w->where("$a.owner_user_id", $u->id)->orWhere(fn ($x) => $x->where($notConf)->where(fn ($y) => $y->where("$a.assigned_by_user_id", $u->id)
            ->orWhere(fn ($s) => $s->where("$a.stuck_on_type", 'USER')->where("$a.stuck_on_id", $u->id))
            ->orWhereIn("$a.id", DB::table('task_helpers')->where('user_id', $u->id)->select('task_id'))->orWhereIn("$a.id", DB::table('task_watchers')->where('user_id', $u->id)->select('task_id'))
            ->when($cov, fn ($c) => $c->orWhereIn("$a.owner_user_id", $cov)))));
    }

    /** The same rule for one task row. */
    public static function canSee(object $t, object $u): bool
    {
        if ((int) $t->owner_user_id === (int) $u->id || self::partner($u)) return true;
        if ((int) ($t->is_confidential ?? 0)) return false;
        if (($u->role_code ?? '') === 'MANAGER' || Perm::has($u, 'work.taskStatus_may')) return true;
        if ((int) $t->assigned_by_user_id === (int) $u->id || (($t->stuck_on_type ?? null) === 'USER' && (int) $t->stuck_on_id === (int) $u->id)) return true;
        if (DB::table('task_helpers')->where('task_id', $t->id)->where('user_id', $u->id)->exists() || DB::table('task_watchers')->where('task_id', $t->id)->where('user_id', $u->id)->exists()) return true;
        return in_array((int) $t->owner_user_id, self::covered((int) $u->id), true);
    }
}
