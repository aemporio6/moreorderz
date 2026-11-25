<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-30 one place that works out a term from its payments: paid = Σ the active, booked payments of the term in PO currency
 * (same currency → the amount; another currency → amount × payment rate ÷ PO rate, 2 dp); remaining = planned − paid; completion % = round(paid ÷ planned × 100, 2)
 * (0 when planned = 0); status DUE (pending) / PART_PAID (paid > 0 and remaining > SRC_MONEY_TOL) / PAID (planned > 0 and remaining ≤ SRC_MONEY_TOL).
 * Run after every payment save, change, move to another term (both terms), reversal or delete.
 */
class PaymentTerms
{
    public const COUNTED = ['PAID'];      // PENDING_APPROVAL and CANCELLED payments are not counted

    /** A payment's amount in the PO's currency. */
    public static function inPoCurrency(object $pay, object $po, int $ent): float
    {
        $amt = (float) $pay->payment_amount;
        if (! $pay->fk_id_currency || (int) $pay->fk_id_currency === (int) $po->fk_id_currency) return round($amt, 2);
        $poCur = Fx::code($po->fk_id_currency, $ent); $date = (string) ($pay->payment_date ?: date('Y-m-d'));
        $payRate = (float) $pay->exchange_rate ?: Fx::rate(Fx::code($pay->fk_id_currency, $ent), $date, $ent); $poRate = Fx::rate($poCur, $date, $ent);
        return $poRate > 0 ? round($amt * $payRate / $poRate, 2) : round($amt, 2);
    }

    public static function sync(?int $termId): ?array
    {
        if (! $termId) return null;
        $t = DB::table('t_src_payment_terms')->where('id', $termId)->first(); if (! $t) return null;
        $ent = (int) $t->fk_id_ent; $po = DB::table('t_src_po')->where('id', $t->fk_id_po)->first(); $tol = (float) Settings::get('SRC_MONEY_TOL', $ent, 0.009);
        $paid = 0.0;
        foreach (DB::table('t_src_payments')->where('fk_id_term', $termId)->where('f_active', 1)->whereIn('status', self::COUNTED)->get() as $p) $paid += $po ? self::inPoCurrency($p, $po, $ent) : (float) $p->payment_amount;
        $paid = round($paid, 2); $planned = round((float) $t->planned_amount, 2); $rem = round($planned - $paid, 2);
        $status = $planned > 0 && $rem <= $tol ? 'PAID' : ($paid > 0 ? 'PART_PAID' : 'DUE');
        $row = ['paid_amount' => $paid, 'remaining_amount' => max(0, $rem), 'completion_pct' => $planned > 0 ? round($paid / $planned * 100, 2) : 0.0, 'status' => $status];
        DB::table('t_src_payment_terms')->where('id', $termId)->update($row);
        if ($status === 'PAID') TaskEngine::close('SRC_PAY_DUE', CycleAlerts::key('SRC_PAY_DUE', $termId), 'Term paid');
        return $row;
    }
}
