<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dashboard cards in isolation (R-F_SETUP-15): every card is its own call; a failing card returns {error} – the text users see never holds SQL –
 * while the error goes to sys_error_log (card, user, company, message, query name) and raises one admin alert per card per day (SYS_ERROR, dedupe).
 * Also the buying cards of the rules: supplier payments box (R-F_SETUP-21) and Needs action over the open cycles (R-F_SETUP-20).
 */
class DashCards
{
    public const MSG = 'This section could not load – the admin has been told';
    /** Tests only: cards that must fail. */
    public static array $break = [];

    public static function run(int $ent, string $card, string $label, callable $fn): array
    {
        try {
            if (app()->runningUnitTests() && in_array($card, self::$break, true)) throw new \RuntimeException("SQLSTATE[42S22]: Column not found (test break of $card)");
            return ['card' => $card, 'label' => $label, 'ok' => true, 'data' => $fn()];
        } catch (\Throwable $e) {
            report($e);
            $day = date('Y-m-d'); $fp = sha1("dash|$card|" . get_class($e) . "|$day");
            $msg = mb_substr("Dashboard card $label ($card): " . $e->getMessage(), 0, 1000);
            $old = DB::table('sys_error_log')->where('fingerprint', $fp)->first();
            if ($old) DB::table('sys_error_log')->where('id', $old->id)->update(['times_seen' => DB::raw('times_seen + 1'), 'last_seen' => now()]);
            else DB::table('sys_error_log')->insert(RecordService::fillNotNull('sys_error_log', ['level' => 'ERROR', 'message' => $msg, 'file' => mb_substr($e->getFile(), -250), 'line' => $e->getLine(), 'fk_id_ent' => $ent,
                'user_id' => Context::userId(), 'url' => 'card:' . $card, 'context_json' => json_encode(['card' => $card, 'label' => $label, 'query' => $card, 'company' => $ent, 'user' => Context::userId()]), 'fingerprint' => $fp, 'times_seen' => 1, 'f_alerted' => 1, 'created_at' => now(), 'last_seen' => now()]));
            TaskEngine::open('SYS_ERROR', ['dash', $card, $day], "A dashboard card could not load ($label)", ['ent' => $ent, 'description' => $msg]);      // one admin alert per card per day
            return ['card' => $card, 'label' => $label, 'ok' => false, 'error' => self::MSG];
        }
    }

    /** R-F_SETUP-21: over the open cycles, in base currency. */
    public static function payments(int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $soon = date('Y-m-d', strtotime($today . ' +7 days')); $base = Fx::base($ent);
        $cy = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'Closed', 'CANCELLED', 'Cancelled', 'COMPLETED', 'Completed'])->pluck('id')->all();
        $pos = DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('fk_id_cycle', $cy ?: [0])->whereNotIn('status', ['CANCELLED'])->get(['id', 'po_total', 'fk_id_currency', 'po_date', 'exchange_rate']);
        $open = 0.0; foreach ($pos as $p) { $cur = Fx::code($p->fk_id_currency, $ent); $open += $cur === $base ? (float) $p->po_total : (float) $p->po_total * ((float) $p->exchange_rate > 0 ? (float) $p->exchange_rate : Fx::rate($cur, (string) ($p->po_date ?: $today), $ent)); }
        $ids = $pos->pluck('id')->all(); $cur = $pos->pluck('fk_id_currency', 'id');
        $paid = (float) DB::table('t_src_payments')->whereIn('fk_id_po', $ids ?: [0])->where('f_active', 1)->sum('base_amount');
        $due = 0.0; $week = 0.0;
        foreach (DB::table('t_src_payment_terms')->whereIn('fk_id_po', $ids ?: [0])->where('f_active', 1)->where('status', '<>', 'PAID')->get(['fk_id_po', 'remaining_amount', 'due_date']) as $t) {
            $v = (float) $t->remaining_amount * Fx::rate(Fx::code($cur[$t->fk_id_po] ?? null, $ent), $today, $ent);
            $due += $v; if ($t->due_date && $t->due_date <= $soon) $week += $v;
        }
        return ['base' => $base, 'open_po_value' => round($open, 2), 'paid' => round($paid, 2), 'terms_due' => round($due, 2), 'due_7_days' => round($week, 2), 'link' => '#/src-outstanding', 'title' => 'Supplier payments'];
    }

    /** R-F_SETUP-30: Investors card – same sources as the investor report (R-F_SETUP-8). */
    public static function investors(int $ent, string $from, string $to): array
    {
        $inv = DB::table('t_src_investments as i')->leftJoin('t_account as a', 'a.id', '=', 'i.fk_id_investor')->where('i.fk_id_ent', $ent)->where('i.f_active', 1)->whereNotNull('i.fk_id_investor')->whereBetween('i.invest_date', [$from, $to])
            ->get(['i.fk_id_investor as id', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as name"), 'i.fk_id_cycle', 'i.base_amount', 'i.invest_amount']);
        $invested = $inv->groupBy('id')->map(fn ($g) => ['name' => $g->first()->name ?: '#' . $g->first()->id, 'amount' => round($g->sum(fn ($x) => (float) $x->base_amount > 0 ? (float) $x->base_amount : (float) $x->invest_amount), 2), 'cycles' => $g->pluck('fk_id_cycle')->filter()->unique()->count()])
            ->sortByDesc('amount')->take(5)->values()->all();
        $goods = DB::table('fin_lots as l')->leftJoin('t_account as a', 'a.id', '=', 'l.fk_id_owner')->where('l.company_id', $ent)->whereNotNull('l.fk_id_owner')->where('l.fk_id_owner', '>', 0)->where('l.qty_left', '>', 0)
            ->groupBy('l.fk_id_owner', 'a.first_name', 'a.sur_name')->selectRaw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as name, SUM(l.qty_left * l.unit_cost_gbp) as v")->orderByDesc('v')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => round((float) $r->v, 2)])->all();
        $returns = round((float) DB::table('t_inv_period_investors as x')->join('t_inv_periods as p', 'p.id', '=', 'x.fk_id_period')->where('p.fk_id_ent', $ent)->where('p.status', 'CONFIRMED')->whereBetween('p.d_from', [$from, $to])->where('x.fk_id_investor_account', '>', 0)->sum('x.share_amount'), 2);
        $out = round((float) DB::table('fin_withdrawals')->where('company_id', $ent)->whereIn('status', ['APPROVED', 'PAID'])->whereBetween(DB::raw('DATE(COALESCE(approved_at, requested_at))'), [$from, $to])->sum('amount'), 2);
        return ['title' => 'My Investments / Investors', 'invested' => $invested, 'goods' => $goods, 'returns' => $returns, 'withdrawals' => $out, 'empty' => $invested || $goods || $returns || $out ? null : 'No investments in period'];
    }

    /** R-F_SETUP-29: Invoicing / Profit card – the investor settlement engine; hidden margin for the OWNER only. */
    public static function invoicing(int $ent, string $from, string $to, ?string $role): array
    {
        $open = DB::table('t_invoices')->where('fk_id_ent', $ent)->whereColumn('total', '>', 'amount_paid')->whereRaw("UPPER(COALESCE(status,'')) NOT LIKE '%CANCEL%'")->selectRaw('COUNT(*) n, COALESCE(SUM(total - amount_paid),0) v')->first();
        $unc = (int) DB::table('t_inv_periods')->where('fk_id_ent', $ent)->where('status', 'UNCONFIRMED')->count();
        $per = DB::table('t_inv_periods')->where('fk_id_ent', $ent)->whereBetween('d_from', [$from, $to])->selectRaw('COUNT(*) n, COALESCE(SUM(gross_profit),0) g, COALESCE(SUM(final_net_profit),0) net, COALESCE(SUM(capital - actual_cost),0) hm')->first();
        $split = DB::table('t_inv_profit_splits')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('d_effective_from')->orderByDesc('id')->first(['investor_pct', 'owner_pct', 'va_pct', 'handler_pct']);
        $pc = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '%';
        $rows = [['Outstanding to settle (now)', number_format((float) $open->v, 2) . ' (' . (int) $open->n . ' invoices)']];
        if ($unc) $rows[] = ['Periods not settled', (string) $unc];
        $rows[] = ['Settlement periods (period)', (string) (int) $per->n]; $rows[] = ['Gross profit (period)', number_format((float) $per->g, 2)]; $rows[] = ['Net profit (period)', number_format((float) $per->net, 2)];
        if ($role === 'OWNER') $rows[] = ['Hidden margin (period)', number_format((float) $per->hm, 2)];
        if ($split) $rows[] = ['Profit split (Inv/Own/VA/Hnd)', implode(' / ', array_map($pc, [$split->investor_pct, $split->owner_pct, $split->va_pct, $split->handler_pct]))];
        $has = (int) $open->n || $unc || (int) $per->n || $split;
        return ['title' => 'Invoicing / Profit', 'rows' => $has ? $rows : [], 'show' => (bool) $has];
    }

    /**
     * R-F_SETUP-33: team activity per person in the period (one source: the records' created_by / importer, tasks done on time): sales entered, imports,
     * GRNs, sourcing records (POs + payments + shipments + cycles), tasks done on time; top 6 people by total; each line "Name — n sales entered, n imports, …".
     */
    public static function team(int $ent, string $from, string $to): array
    {
        $a = $from . ' 00:00:00'; $b = $to . ' 23:59:59'; $c = [];
        $add = function (string $k, $rows) use (&$c) { foreach ($rows as $uid => $n) if ($uid) $c[(int) $uid][$k] = ($c[(int) $uid][$k] ?? 0) + (int) $n; };
        $cnt = fn (string $t, string $by, string $at, ?string $ent_col = 'fk_id_ent') => DB::table($t)->when($ent_col, fn ($q) => $q->where($ent_col, $ent))->whereBetween($at, [$a, $b])->whereNotNull($by)->groupBy($by)->selectRaw("$by as u, COUNT(*) as n")->pluck('n', 'u');
        $add('sales', $cnt('t_sales', 'created_by', 'created_at'));
        $add('imports', $cnt('t_sale_imports', 'imported_by', 'imported_at'));
        $add('grns', $cnt('t_src_grn', 'created_by', 'created_at'));
        foreach (['t_src_po', 't_src_payments', 't_src_shipments', 't_src_cycles'] as $t) $add('sourcing', $cnt($t, 'created_by', 'created_at'));
        $add('tasks', DB::table('tasks')->where('fk_id_ent', $ent)->whereBetween('done_at', [$a, $b])->where(fn ($q) => $q->whereNull('due_at')->orWhereColumn('done_at', '<=', 'due_at'))->whereNotNull('owner_user_id')->groupBy('owner_user_id')->selectRaw('owner_user_id as u, COUNT(*) as n')->pluck('n', 'u'));
        $names = DB::table('t_users')->whereIn('id', array_keys($c) ?: [0])->pluck('display_name', 'id');
        $lab = ['sales' => 'sales entered', 'imports' => 'imports', 'grns' => 'GRNs', 'sourcing' => 'sourcing records', 'tasks' => 'tasks done on time'];
        $rows = [];
        foreach ($c as $uid => $m) {
            $parts = []; foreach ($lab as $k => $l) if (! empty($m[$k])) $parts[] = $m[$k] . ' ' . $l;
            $name = $names[$uid] ?? ('#' . $uid);
            $rows[] = ['user_id' => $uid, 'name' => $name, 'total' => array_sum($m), 'counts' => $m + array_fill_keys(array_keys($lab), 0), 'line' => $name . ' — ' . implode(', ', $parts), 'drill' => "#/audit?user_id=$uid&from=$from&to=$to"];
        }
        usort($rows, fn ($x, $y) => [$y['total'], $x['name']] <=> [$x['total'], $y['name']]);
        return ['title' => 'Team activity', 'rows' => array_slice($rows, 0, 6), 'empty' => 'No activity in period'];
    }

    /** R-F_SETUP-20: Needs action over the open cycles – P1 then P2, then due date; top 20 with "show all"; the header counts every item. */
    public static function needs(int $ent, bool $all = false): array
    {
        $cy = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'Closed', 'CANCELLED', 'Cancelled', 'COMPLETED', 'Completed'])->pluck('cycle_code', 'id');
        $rows = [];
        foreach ($cy as $id => $code) foreach (CycleAlerts::detect($ent, (int) $id) as $r) $rows[] = ['code' => $r['code'], 'p' => $r['p'], 'title' => $r['title'], 'detail' => $r['detail'], 'chip' => $r['chip'], 'due' => $r['due'], 'cycle' => $code, 'cycle_id' => (int) $id, 'link' => $r['opt']['fix_url'] ?? "#/cycle/$id"];
        usort($rows, fn ($a, $b) => [$a['p'], $a['due'] ?? '9999-12-31', $a['cycle']] <=> [$b['p'], $b['due'] ?? '9999-12-31', $b['cycle']]);
        return ['count' => count($rows), 'rows' => $all ? $rows : array_slice($rows, 0, 20), 'more' => ! $all && count($rows) > 20, 'empty' => 'Nothing pending — all clear.'];
    }
}
