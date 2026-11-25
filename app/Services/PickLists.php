<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-28 Partial, abandoned and reprinted pick lists.
 * - Idle: an open list not touched for WH_PICK_IDLE_MINUTES (default 120) shows "idle".
 * - Abandoned: after WH_PICK_ABANDON_HOURS (default 8) the unpicked lines are released (line RELEASED); their reservations go back to OPEN
 *   (they stay reserved and join the next list); units already picked stay in DISPATCH for packing; WH_PICK_ABANDONED tells the supervisor.
 * - Reassign to another picker. Resume after a browser refresh = the next unconfirmed line (state is on the server).
 * - Reprint of a started list prints only the lines still to pick (PrintService pick sheet).
 */
class PickLists
{
    public const OPEN = ['OPEN', 'IN_PROGRESS'];

    private static function touched(object $t): int { return (int) strtotime((string) ($t->last_activity_at ?: $t->created_at)); }

    /** OPEN / IN_PROGRESS / IDLE / DONE / ABANDONED – the state the screens show. */
    public static function state(object $t, ?int $ent = null): string
    {
        if (! in_array($t->status, self::OPEN, true)) return (string) $t->status;
        $idle = (int) Settings::get('WH_PICK_IDLE_MINUTES', $ent ?? (int) $t->fk_id_ent, 120);
        return time() - self::touched($t) >= $idle * 60 ? 'IDLE' : (string) $t->status;
    }

    /** The next line to confirm (resume after refresh). */
    public static function next(int $taskId): ?object
    {
        return DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $taskId)->where('line_status', 'OPEN')->orderBy('line_no')->first(['id', 'line_no']);
    }

    /** Release the unpicked lines of one list (abandon). Returns the number of lines released. */
    public static function abandon(int $taskId, string $why = 'Abandoned'): int
    {
        return DB::transaction(function () use ($taskId, $why) {
            $t = DB::table('wh_pick_tasks')->where('id', $taskId)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Pick list not found.');
            if (! in_array($t->status, self::OPEN, true)) throw new \InvalidArgumentException("Pick list {$t->task_code} is " . strtolower($t->status) . ' – nothing to release.');
            $lines = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $taskId)->where('line_status', 'OPEN')->lockForUpdate()->get();
            DB::table('wh_pick_task_lines')->whereIn('id', $lines->pluck('id')->all() ?: [0])->update(['line_status' => 'RELEASED']);
            foreach ($lines->pluck('fk_id_reservation')->unique()->filter() as $rid) {
                $r = DB::table('wh_reservations')->where('id', $rid)->lockForUpdate()->first();
                if (! $r || $r->status !== 'ALLOCATED' || DB::table('wh_pick_task_lines')->where('fk_id_reservation', $rid)->where('line_status', 'OPEN')->exists()) continue;
                DB::table('wh_reservations')->where('id', $rid)->update(['status' => 'OPEN', 'updated_at' => now()]);
                DB::table('wh_reservation_history')->insert(['fk_id_reservation' => $rid, 'old_status' => 'ALLOCATED', 'new_status' => 'OPEN', 'old_qty' => $r->qty, 'new_qty' => $r->qty, 'reason' => "Pick list {$t->task_code} released ($why)", 'changed_by' => Context::userId(), 'changed_at' => now()]);
                DB::table('t_sales')->where('id', $r->fk_id_sale)->where('wh_status', 'PICKING')->update(['wh_status' => 'RESERVED']);
            }
            $picked = (int) DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $taskId)->sum('qty_done');
            DB::table('wh_pick_tasks')->where('id', $taskId)->update(['status' => 'ABANDONED', 'finished_at' => now()]);
            TaskEngine::open('WH_PICK_ABANDONED', [$t->task_code], "Pick list {$t->task_code} abandoned: " . $lines->count() . " line(s) released to the queue, $picked picked unit(s) wait in DISPATCH for packing",
                ['ent' => (int) $t->fk_id_ent, 'module' => 'wh_pick_tasks', 'record_id' => $taskId, 'fix_url' => '#/orders']);
            Audit::log('wh_pick_tasks', $taskId, 'UPDATE', ['status' => 'ABANDONED', 'released lines' => $lines->count(), 'why' => $why], null, (int) $t->fk_id_ent);
            return $lines->count();
        });
    }

    /** Job (mo:dispatch-watch): every open list untouched for WH_PICK_ABANDON_HOURS is released. */
    public static function sweep(int $ent): int
    {
        $h = (int) Settings::get('WH_PICK_ABANDON_HOURS', $ent, 8); $n = 0;
        foreach (DB::table('wh_pick_tasks')->where('fk_id_ent', $ent)->whereIn('status', self::OPEN)->whereRaw('COALESCE(last_activity_at, created_at) <= ?', [now()->subHours($h)])->pluck('id') as $id) { self::abandon((int) $id, "not touched for $h h"); $n++; }
        return $n;
    }

    /** Give the list to another picker. */
    public static function reassign(int $taskId, int $userId, ?object $u): array
    {
        Perm::need($u, 'orders.WAREHOUSE'); $ent = (int) Context::ent();
        $t = DB::table('wh_pick_tasks')->where('id', $taskId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Pick list not found.');
        if (! in_array($t->status, self::OPEN, true)) throw new \InvalidArgumentException("Pick list {$t->task_code} is " . strtolower($t->status) . ' – it cannot be reassigned.');
        $p = DB::table('t_users')->where('id', $userId)->where('fk_id_ent', $ent)->where('f_active', 1)->first(['id', 'display_name']) ?? throw new \InvalidArgumentException('Choose an active user.');
        DB::table('wh_pick_tasks')->where('id', $taskId)->update(['assigned_to' => $p->id]);
        Audit::log('wh_pick_tasks', $taskId, 'UPDATE', ['assigned_to' => [$t->assigned_to, $p->id]], null, $ent);
        return ['message' => "Pick list {$t->task_code} given to {$p->display_name}."];
    }

    /** The lists screen: open / idle / abandoned lists with progress. */
    public static function lists(int $ent): array
    {
        return DB::table('wh_pick_tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.assigned_to')->where('t.fk_id_ent', $ent)->where('t.task_type', 'ORDER')
            ->where(fn ($w) => $w->whereIn('t.status', self::OPEN)->orWhere('t.created_at', '>=', now()->subDays(2)))->orderByDesc('t.id')->limit(100)
            ->get(['t.id', 't.fk_id_ent', 't.task_code', 't.status', 't.created_at', 't.last_activity_at', 't.assigned_to', 'u.display_name as picker',
                DB::raw('(SELECT COUNT(*) FROM wh_pick_task_lines l WHERE l.fk_id_pick_task = t.id) as lines_all'), DB::raw("(SELECT COUNT(*) FROM wh_pick_task_lines l WHERE l.fk_id_pick_task = t.id AND l.line_status IN ('DONE','SHORT')) as lines_done")])
            ->map(fn ($t) => ['id' => (int) $t->id, 'code' => $t->task_code, 'state' => self::state($t, $ent), 'picker' => $t->picker, 'assigned_to' => $t->assigned_to ? (int) $t->assigned_to : null, 'done' => (int) $t->lines_done, 'lines' => (int) $t->lines_all, 'touched' => $t->last_activity_at ?: $t->created_at])->all();
    }
}
