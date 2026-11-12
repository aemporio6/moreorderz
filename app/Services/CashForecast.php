<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cash forecast by week (R-FIN cash view): money in the banks today (as booked), plus what is expected in, minus what is due out, week by week.
 * In: booked marketplace payouts not on the bank yet (on their payout date, else statement date + PAYOUT_DAYS_AFTER_STATEMENT).
 * Out: supplier payment terms still open (by due date), customs not paid yet, expenses waiting for approval, partner withdrawals approved / asked for.
 * Anything already late lands in "now". Shown apart (not dated, so not in the weeks): sales not settled yet (1100) and money the marketplaces hold back (1120).
 */
class CashForecast
{
    public static function build(int $ent, int $weeks = 8): array
    {
        $weeks = max(1, min(26, $weeks)); $today = date('Y-m-d'); $mon = date('Y-m-d', strtotime('monday this week'));
        $slot = function (?string $date) use ($today, $mon, $weeks): int { if (! $date || $date < $today) return 0; $w = (int) floor((strtotime($date) - strtotime($mon)) / 604800); return min(max($w, 0), $weeks); };   // index $weeks = later
        $b = ['in' => array_fill(0, $weeks + 1, 0.0), 'out' => array_fill(0, $weeks + 1, 0.0)]; $items = [];
        $add = function (string $dir, ?string $date, float $amt, string $what, string $link = '') use (&$b, &$items, $slot) { if (round($amt, 2) == 0.0) return; $i = $slot($date); $b[$dir][$i] = round($b[$dir][$i] + $amt, 2); $items[] = ['dir' => $dir, 'date' => $date, 'week' => $i, 'amount' => round($amt, 2), 'what' => $what, 'link' => $link]; };
        $bank = round(array_sum(array_map(fn ($x) => (float) $x['books_base'], MoneyService::banks($ent))), 2);
        $after = (int) Settings::get('PAYOUT_DAYS_AFTER_STATEMENT', $ent, 3);
        foreach (DB::table('mo_settlements as s')->join('t_branches as br', 'br.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('s.status', 'POSTED')->whereNull('s.fk_id_bank_line')->get(['s.statement_id', 's.payable', 's.currency', 's.payout_date', 's.statement_date', 'br.shop_name']) as $s)
            $add('in', $s->payout_date ?: date('Y-m-d', strtotime("{$s->statement_date} +$after day")), Fx::toBase((float) $s->payable, $s->currency, $s->statement_date, $ent), "Payout {$s->shop_name} · {$s->statement_id}", '#/settlements');
        foreach (DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.remaining_amount', '>', 0)->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])->get(['t.remaining_amount', 't.due_date', 't.milestone', 'p.po_number', 'p.fk_id_currency', 'p.exchange_rate', 'p.fk_id_cycle']) as $t)
            $add('out', $t->due_date, round((float) $t->remaining_amount * Fx::rate(Fx::code($t->fk_id_currency, $ent), $today, $ent), 2), "Supplier {$t->po_number} · {$t->milestone}", $t->fk_id_cycle ? '#/cycle/' . $t->fk_id_cycle : '#/buying');
        foreach (DB::table('t_src_customs')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($w) => $w->whereNull('pay_status')->orWhere('pay_status', '<>', 'PAID'))->get(['customs_code', 'base_amount', 'import_vat', 'exchange_rate', 'clearance_date', 'fk_id_cycle']) as $c)
            $add('out', $c->clearance_date ?: $today, round((float) $c->base_amount + (float) $c->import_vat * (float) ($c->exchange_rate ?: 1), 2), "Customs {$c->customs_code} (duty + import VAT)", '#/cycle-extras/' . $c->fk_id_cycle);
        foreach (DB::table('sys_approvals')->where('fk_id_ent', $ent)->where('status', 'PENDING')->whereIn('action_code', ['EXPENSE', 'WITHDRAWAL'])->get(['action_code', 'amount_gbp', 'change_json']) as $a)
            $add('out', $today, (float) $a->amount_gbp, ($a->action_code === 'EXPENSE' ? 'Expense waiting for approval: ' : 'Withdrawal waiting for approval: ') . mb_substr((string) ((json_decode((string) $a->change_json, true) ?: [])['category'] ?? ''), 0, 80), '#/approvals');
        foreach (DB::table('fin_withdrawals')->where('company_id', $ent)->whereIn('status', ['APPROVED'])->get(['amount', 'currency', 'd_needed_by', 'investor_id']) as $w)
            $add('out', $w->d_needed_by ?: $today, Fx::toBase((float) $w->amount, $w->currency ?: Fx::base($ent), $today, $ent), 'Partner withdrawal: ' . PartnerService::name((int) $w->investor_id), '#/partners/' . $w->investor_id);
        $rows = []; $run = $bank;
        for ($i = 0; $i <= $weeks; $i++) {
            $run = round($run + $b['in'][$i] - $b['out'][$i], 2);
            $rows[] = ['week' => $i, 'label' => $i === 0 ? 'Now and late' : ($i === $weeks ? 'Later' : 'Week of ' . date('j M', strtotime("$mon +$i week"))), 'in' => $b['in'][$i], 'out' => $b['out'][$i], 'balance' => $run];
        }
        $low = (float) Settings::get('free_cash_alert_limit', $ent, 0);
        usort($items, fn ($x, $y) => [$x['week'], $x['date']] <=> [$y['week'], $y['date']]);
        return ['bank_now' => $bank, 'rows' => $rows, 'items' => $items, 'base' => Fx::base($ent), 'alert_limit' => $low, 'first_low' => collect($rows)->first(fn ($r) => $low > 0 && $r['balance'] < $low)['label'] ?? null,
            'not_dated' => ['unsettled_sales' => round(Ledger::balance($ent, '1100'), 2), 'held_back' => round(Ledger::balance($ent, '1120'), 2)]];
    }
}
