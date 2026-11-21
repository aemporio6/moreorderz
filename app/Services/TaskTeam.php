<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-40 Team & Settings page (bosses) + monthly leaderboard: workload bars (R-G_CORE-18), "Due date moved more than twice", SLA per category (R-G_CORE-23 – the
 * task_category form), absence next 14 days with covers (R-G_CORE-16), delegations (R-G_CORE-17), recurring tasks with the next date + suggested recurring tasks
 * (R-G_CORE-26). Leaderboard per month (last 12 months): #, Name, Closed, On time %, Proof %, Rework, Avg score, Trend "▲ +n" / "▼ −n" / "— 0" / "new";
 * sorted by avg score, then closed.
 */
class TaskTeam
{
    public const SUGGESTED = [
        ['title' => 'Load the settlement report', 'repeat_type' => 'EVERY_N_DAYS', 'repeat_n' => 14, 'label' => 'Settlement every 14 days'],
        ['title' => 'Warehouse stock count', 'repeat_type' => 'WEEKLY', 'repeat_weekday' => 1, 'label' => 'Warehouse count every Monday'],
        ['title' => 'Load the bank statement', 'repeat_type' => 'WEEKLY', 'repeat_weekday' => 5, 'label' => 'Bank statement every Friday'],
        ['title' => 'Payment review', 'repeat_type' => 'MONTHLY', 'repeat_day_of_month' => 1, 'label' => 'Payment review on the 1st'],
    ];

    private static function onTime(): float { return (float) ((int) AlertRules::threshold('score_on_time') ?: 40); }

    /** One month of the leaderboard (Y-m). */
    public static function leaderboard(string $month): array
    {
        $from = $month . '-01 00:00:00'; $to = date('Y-m-t 23:59:59', strtotime($month . '-01')); $prev = date('Y-m', strtotime($month . '-01 -1 month'));
        $prevAvg = DB::table('task_scores')->whereBetween('closed_at', [$prev . '-01 00:00:00', date('Y-m-t 23:59:59', strtotime($prev . '-01'))])->groupBy('user_id')->selectRaw('user_id, AVG(score) a')->pluck('a', 'user_id');
        $rows = [];
        foreach (DB::table('task_scores as s')->join('t_users as u', 'u.id', '=', 's.user_id')->whereBetween('s.closed_at', [$from, $to])->get(['s.*', 'u.display_name'])->groupBy('user_id') as $uid => $ss) {
            $n = $ss->count(); $avg = (int) round($ss->avg('score'));
            $p = $prevAvg[$uid] ?? null; $d = $p !== null ? $avg - (int) round($p) : null;
            $rows[] = ['user_id' => (int) $uid, 'name' => $ss->first()->display_name, 'closed' => $n, 'on_time' => (int) round(100 * $ss->where('on_time_pts', self::onTime())->count() / $n),
                'proof' => (int) round(100 * $ss->where('evidence_pts', '>', 0)->count() / $n), 'rework' => $ss->where('rework_pts', '<', 0)->count(), 'avg' => $avg,
                'trend' => $d === null ? 'new' : ($d > 0 ? "▲ +$d" : ($d < 0 ? '▼ −' . abs($d) : '— 0'))];
        }
        usort($rows, fn ($a, $b) => [$b['avg'], $b['closed']] <=> [$a['avg'], $a['closed']]);
        foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
        $months = []; for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        return ['month' => $month, 'months' => $months, 'rows' => $rows];
    }

    public static function page(object $u, ?string $month = null): array
    {
        if (! in_array($u->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: the Team page is for a Partner or Manager.');
        $ent = (int) Context::ent();
        $load = [];
        foreach (DB::table('t_users')->where('f_active', 1)->orderBy('display_name')->get(['id', 'display_name']) as $p) {
            $l = TaskCreate::load((int) $p->id); if (! $l['open'] && ! TaskOwner::absent((int) $p->id)) continue;
            $load[] = ['name' => $p->display_name, 'open' => $l['open'], 'limit' => $l['limit'], 'state' => $l['open'] > $l['limit'] ? 'Overloaded' : ($l['open'] === $l['limit'] ? 'Full' : 'OK')];
        }
        $moved = TaskVisibility::apply(DB::table('tasks as t'), $u)->leftJoin('t_users as x', 'x.id', '=', 't.owner_user_id')->where('t.due_changes', '>', 2)->whereNotIn('t.status', ['APPROVED', 'CANCELLED'])
            ->orderByDesc('t.due_changes')->get(['t.id', 't.title', 't.due_changes', 'x.display_name'])->map(fn ($t) => ['id' => (int) $t->id, 'text' => TaskFlow::ref((int) $t->id) . ' ' . $t->title . ' – ' . (int) $t->due_changes . '× (' . ($t->display_name ?: '—') . ')'])->all();
        $today = date('Y-m-d'); $until = date('Y-m-d', strtotime('+14 days'));
        $absence = DB::table('user_absence as a')->leftJoin('t_account as p', 'p.id', '=', 'a.user_id')->leftJoin('t_account as c', 'c.id', '=', 'a.cover_user_id')
            ->where('a.from_date', '<=', $until)->where(fn ($w) => $w->whereNull('a.to_date')->orWhere('a.to_date', '>=', $today))->whereRaw('(a.to_date IS NULL OR a.to_date >= a.from_date)')->orderBy('a.from_date')
            ->get(['a.id', 'a.from_date', 'a.to_date', DB::raw("TRIM(CONCAT_WS(' ', p.first_name, p.sur_name)) as who"), DB::raw("TRIM(CONCAT_WS(' ', c.first_name, c.sur_name)) as cover")]);
        $deleg = DB::table('tm_delegations as d')->whereRaw('(d.to_date IS NULL OR d.to_date >= d.from_date)')->leftJoin('t_users as f', 'f.id', '=', 'd.from_user_id')->leftJoin('t_users as t', 't.id', '=', 'd.to_user_id')->where(fn ($w) => $w->whereNull('d.to_date')->orWhere('d.to_date', '>=', $today))
            ->get(['d.id', 'f.display_name as from_name', 't.display_name as to_name', 'd.from_date', 'd.to_date', 'd.rights']);
        return ['workload' => $load, 'moved' => $moved, 'categories' => DB::table('task_categories')->orderBy('sort')->get(['id', 'name', 'default_due_days', 'ack_hours', 'escalate_to_role', 'evidence_type_default', 'is_active']),
            'absence' => $absence, 'delegations' => $deleg, 'recurring' => TaskRepeat::chains($ent), 'suggested' => self::SUGGESTED,
            'leaderboard' => self::leaderboard($month ?: date('Y-m')), 'hard_limits' => TaskSettings::hardLimits(), 'partner_gaps' => TaskSettings::partnerGaps(), /* R-G_CORE-22 / 16 */ 'forms' => ['category' => 'task_category', 'absence' => 'task_absence', 'delegation' => 'task_delegation'], 'can_delegate' => TaskVisibility::partner($u)];
    }
}
