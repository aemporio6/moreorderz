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
