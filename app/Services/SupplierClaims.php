<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-56 supplier claim section of a GRN, R-B_SOURCING-57 CREDIT_NOTE payments, R-B_SOURCING-63 a settled claim lowers the cost.
 * - Per GRN line: claim qty (default missing + damaged; lowered with a reason, down to 0) and claim amount (default qty × the line's PO unit price,
 *   PO currency). Header (read-only): units = Σ, amount = Σ, currency = PO currency, base = amount × rate of the GRN date.
 * - Status OPEN → AGREED → SETTLED / REJECTED / NO_CLAIM (NO_CLAIM and REJECTED need a reason); settled as CREDIT_NOTE / REFUND / REPLACEMENT,
 *   supplier reference, settled date; credit used = Σ CREDIT_NOTE payments on the claim, credit remaining = settled credit − used.
 *   SRC_GRN_SHORT closes on SETTLED / REJECTED / NO_CLAIM.
 * - Settled as CREDIT_NOTE or REFUND: the supplier owes us the base amount (Dr supplier advance 1300 / Cr costs on the way 1210) and the cycle gets
 *   a new landed-cost version with a negative "supplier credit" cost on the claimed products (R-60: FINAL by itself when it can be). The claim is
 *   never income. REPLACEMENT changes no cost (the goods come again).
 * - A CREDIT_NOTE payment pays a term from the credit remaining: never linked to a bank line, never cash out, no second booking (the credit already
 *   sits on the supplier's advance account and is used against what is owed).
 */
class SupplierClaims
{
    public const STATUSES = ['OPEN', 'AGREED', 'SETTLED', 'REJECTED', 'NO_CLAIM'];
    public const SETTLED_AS = ['CREDIT_NOTE', 'REFUND', 'REPLACEMENT'];

    private static function grn(int $ent, int $id): object
    {
        return DB::table('t_src_grn')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('GRN not found.');
    }

    /** The PO (currency, rate) of a GRN: through its shipment, else the first PO of its lines. */
    public static function poOf(object $g): ?object
    {
        $po = $g->fk_id_shipment ? DB::table('t_src_shipments')->where('id', $g->fk_id_shipment)->value('fk_id_po') : null;
        $po ??= DB::table('t_src_grn_lines as l')->join('t_src_po_lines as p', 'p.id', '=', 'l.fk_id_po_line')->where('l.fk_id_grn', $g->id)->value('p.fk_id_po');
        return $po ? DB::table('t_src_po')->where('id', $po)->first() : null;
    }

    /** Fill the defaults of lines never touched and work out the header. */
    public static function refresh(int $ent, int $grnId): array
    {
        $g = self::grn($ent, $grnId); $po = self::poOf($g);
        foreach (DB::table('t_src_grn_lines as l')->leftJoin('t_src_po_lines as p', 'p.id', '=', 'l.fk_id_po_line')->where('l.fk_id_grn', $grnId)->whereNull('l.claim_qty')->get(['l.id', 'l.missing_qty', 'l.damaged_qty', 'p.unit_price']) as $l) {
            $q = (float) $l->missing_qty + (float) $l->damaged_qty;
            DB::table('t_src_grn_lines')->where('id', $l->id)->update(['claim_qty' => $q, 'claim_amount' => round($q * (float) $l->unit_price, 2)]);
        }
        $s = DB::table('t_src_grn_lines')->where('fk_id_grn', $grnId)->selectRaw('COALESCE(SUM(claim_qty),0) u, COALESCE(SUM(claim_amount),0) a')->first();
        $cur = $po ? Fx::code($po->fk_id_currency, $ent) : Fx::base($ent); $date = $g->grn_date ?: date('Y-m-d');
        $base = (float) $s->a > 0 ? round((float) $s->a * Fx::rate($cur, $date, $ent), 2) : 0.0;
        $used = round((float) DB::table('t_src_payments')->where('fk_id_grn', $grnId)->where('payment_type', 'CREDIT_NOTE')->where('f_active', 1)->sum('payment_amount'), 2);
        $credit = $g->claim_status === 'SETTLED' && $g->claim_settled_as === 'CREDIT_NOTE' ? round((float) $s->a, 2) : 0.0;
        $set = ['claim_units' => (float) $s->u, 'claim_amount' => round((float) $s->a, 2), 'fk_id_claim_currency' => $po->fk_id_currency ?? null, 'claim_credit_used' => $used, 'claim_credit_remaining' => round(max(0, $credit - $used), 2)];
        if ($g->claim_status !== 'SETTLED') $set['claim_base_amount'] = $base;      // a settled claim keeps the base it was settled at
        if ((float) $s->u > 0 && ! $g->claim_status) $set['claim_status'] = 'OPEN';
        DB::table('t_src_grn')->where('id', $grnId)->update($set);
        return self::show($ent, $grnId);
    }

    public static function show(int $ent, int $grnId): array
    {
        $g = self::grn($ent, $grnId);
        $lines = DB::table('t_src_grn_lines as l')->leftJoin('t_src_po_lines as p', 'p.id', '=', 'l.fk_id_po_line')->where('l.fk_id_grn', $grnId)->orderBy('l.id')
            ->get(['l.id', 'l.sku', 'l.fk_id_product', 'l.received_qty', 'l.damaged_qty', 'l.missing_qty', 'l.claim_qty', 'l.claim_amount', 'l.notes', 'p.unit_price']);
        return ['grn' => ['id' => (int) $g->id, 'code' => $g->grn_number ?: $g->grn_code, 'status' => $g->claim_status, 'settled_as' => $g->claim_settled_as, 'reference' => $g->claim_reference, 'date' => $g->claim_date, 'reason' => $g->claim_reason,
            'units' => (float) $g->claim_units, 'amount' => (float) $g->claim_amount, 'currency' => $g->fk_id_claim_currency ? Fx::code((int) $g->fk_id_claim_currency, $ent) : null, 'base' => (float) $g->claim_base_amount,
            'credit_used' => (float) $g->claim_credit_used, 'credit_remaining' => (float) $g->claim_credit_remaining], 'lines' => $lines,
            'terms' => ($po = self::poOf($g)) ? DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('p.fk_id_supplier', $po->fk_id_supplier)->where('p.fk_id_currency', $po->fk_id_currency)->where('t.f_active', 1)->where('t.remaining_amount', '>', 0)
                ->orderBy('t.due_date')->get(['t.id', 'p.po_number', 't.milestone', 't.remaining_amount'])->map(fn ($t) => ['id' => (int) $t->id, 'name' => "{$t->po_number} · {$t->milestone} · open " . number_format((float) $t->remaining_amount, 2)])->all() : [], 'statuses' => self::STATUSES, 'settled_as' => self::SETTLED_AS, 'base_ccy' => Fx::base($ent)];
    }

    /** One line: claim qty (0 … missing + damaged; a lower figure needs a reason) and optionally its amount. */
    public static function setLine(int $ent, int $lineId, float $qty, ?float $amount, ?string $reason): array
    {
        $l = DB::table('t_src_grn_lines as l')->join('t_src_grn as g', 'g.id', '=', 'l.fk_id_grn')->leftJoin('t_src_po_lines as p', 'p.id', '=', 'l.fk_id_po_line')->where('l.id', $lineId)->where('g.fk_id_ent', $ent)
            ->first(['l.*', 'g.claim_status', 'p.unit_price']) ?? throw new \InvalidArgumentException('GRN line not found.');
        if (in_array($l->claim_status, ['SETTLED', 'REJECTED', 'NO_CLAIM'], true)) throw new \InvalidArgumentException('This claim is closed (' . $l->claim_status . ').');
        $max = (float) $l->missing_qty + (float) $l->damaged_qty + (float) ($l->return_claim_qty ?? 0);      // + supplier-fault returns (R-H-41)
        if ($qty < 0 || $qty > $max + 0.0001) throw new \InvalidArgumentException('The claim quantity is between 0 and ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . ' (missing + damaged).');
        if ($qty < $max - 0.0001 && trim((string) $reason) === '') throw new \InvalidArgumentException('Give the reason for claiming less than the missing + damaged units.');
        $amt = $amount !== null ? round($amount, 2) : round($qty * (float) $l->unit_price, 2);
        DB::table('t_src_grn_lines')->where('id', $lineId)->update(['claim_qty' => $qty, 'claim_amount' => $amt] + ($reason ? ['notes' => mb_substr('Claim: ' . trim($reason), 0, 255)] : []));
        Audit::log('t_src_grn_lines', $lineId, 'UPDATE', ['claim_qty' => [(float) $l->claim_qty, $qty], 'claim_amount' => [(float) $l->claim_amount, $amt]], $reason, $ent);
        return self::refresh($ent, (int) $l->fk_id_grn);
    }

    /** Status change. SETTLED needs "settled as"; NO_CLAIM / REJECTED need a reason. */
    public static function status(int $ent, int $grnId, string $status, array $d = []): array
    {
        return DB::transaction(function () use ($ent, $grnId, $status, $d) {
            $g = DB::table('t_src_grn')->where('id', $grnId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('GRN not found.');
            $status = strtoupper($status); $reason = trim((string) ($d['reason'] ?? ''));
            if (! in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Choose Open, Agreed, Settled, Rejected or No claim.');
            if ($g->claim_status === 'SETTLED' && $status !== 'SETTLED') throw new \InvalidArgumentException('A settled claim stays settled (its credit is in the books).');
            if (in_array($status, ['NO_CLAIM', 'REJECTED'], true) && $reason === '') throw new \InvalidArgumentException('Give the reason for "' . ($status === 'NO_CLAIM' ? 'no claim' : 'rejected') . '".');
            $as = strtoupper((string) ($d['settled_as'] ?? ''));
            if ($status === 'SETTLED' && ! in_array($as, self::SETTLED_AS, true)) throw new \InvalidArgumentException('Say how it was settled: credit note, refund or replacement.');
            self::refresh($ent, $grnId); $g = DB::table('t_src_grn')->where('id', $grnId)->first();
            if ($status === 'SETTLED' && (float) $g->claim_amount <= 0) throw new \InvalidArgumentException('There is nothing to settle: the claim amount is 0.');
            $date = $d['date'] ?? date('Y-m-d');
            DB::table('t_src_grn')->where('id', $grnId)->update(['claim_status' => $status, 'claim_settled_as' => $status === 'SETTLED' ? $as : null, 'claim_reference' => isset($d['reference']) ? mb_substr(trim((string) $d['reference']), 0, 60) : $g->claim_reference,
                'claim_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : $g->claim_reason, 'claim_date' => in_array($status, ['SETTLED', 'REJECTED', 'NO_CLAIM'], true) ? $date : null, 'updated_at' => now(), 'updated_by' => Context::userId()]);
            Audit::log('t_src_grn', $grnId, 'UPDATE', ['claim' => [$g->claim_status, $status], 'settled_as' => $as ?: null], $reason ?: null, $ent);
            if (in_array($status, ['SETTLED', 'REJECTED', 'NO_CLAIM'], true)) TaskEngine::close('SRC_GRN_SHORT', CycleAlerts::key('SRC_GRN_SHORT', $grnId), "Claim $status");
            $out = ['message' => "Claim $status."];
            if ($status === 'SETTLED' && in_array($as, ['CREDIT_NOTE', 'REFUND'], true)) {
                $po = self::poOf($g); $base = (float) $g->claim_base_amount;
                if ($po && $base > 0) Ledger::post($ent, Ledger::openDate($ent), 'SUPPLIER_CLAIM', $grnId, [['account' => '1300', 'debit' => $base, 'ref_id' => (int) $po->fk_id_supplier], ['account' => '1210', 'credit' => $base]], ['memo' => 'Supplier claim settled ' . ($g->grn_number ?: $g->grn_code) . " ($as)"]);
                if ($po) {      // R-63: the credit lowers the cost of the claimed products – a new landed-cost version
                    $last = DB::table('t_src_landed_cost')->where('f_active', 1)->where(fn ($q) => $po->fk_id_cycle ? $q->where('fk_id_cycle', $po->fk_id_cycle) : $q->where('fk_id_po', $po->id))->orderByDesc('id')->first();
                    $in = $last ? ((json_decode((string) $last->warnings, true) ?: [])['input'] ?? []) : [];
                    $r = BuyingService::landedCost((int) $po->id, $in);
                    $out += ['landed' => ['version' => $r['version'], 'status' => $r['status'], 'applied' => $r['applied']]];
                    $out['message'] = "Claim settled – landed cost v{$r['version']} " . ($r['applied'] ? strtolower($r['status']) : 'waits: ' . $r['waiting']);
                }
                if ($as === 'CREDIT_NOTE') self::refresh($ent, $grnId);
            }
            return $out + self::show($ent, $grnId);
        });
    }

    /** Settled credit-note claims of a cycle as landed costs: one negative "supplier credit" cost per claimed product (only that product). */
    public static function landedCredits(int $cycleId): array
    {
        $out = [];
        foreach (DB::table('t_src_grn')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->where('claim_status', 'SETTLED')->whereIn('claim_settled_as', ['CREDIT_NOTE', 'REFUND'])->where('claim_amount', '>', 0)->get() as $g) {
            $lines = DB::table('t_src_grn_lines')->where('fk_id_grn', $g->id)->where('claim_amount', '>', 0)->get(['fk_id_product', 'claim_amount']);
            $left = round((float) $g->claim_base_amount, 2); $n = $lines->count();
            foreach ($lines->values() as $i => $l) {
                $b = $i === $n - 1 ? $left : round((float) $g->claim_base_amount * (float) $l->claim_amount / (float) $g->claim_amount, 2); $left = round($left - $b, 2);
                $out[] = ['Supplier credit ' . ($g->grn_number ?: $g->grn_code), -$b, 'VALUE', 'other_cost', (int) $l->fk_id_product];
            }
        }
        return $out;
    }

    /** R-57: pay a term from a claim's credit – no bank line, no cash out. */
    public static function payWithCredit(int $ent, int $termId, int $grnId, float $amount, ?string $date = null): int
    {
        return DB::transaction(function () use ($ent, $termId, $grnId, $amount, $date) {
            $t = DB::table('t_src_payment_terms')->where('id', $termId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Payment term not found.');
            $g = DB::table('t_src_grn')->where('id', $grnId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('GRN not found.');
            $po = DB::table('t_src_po')->where('id', $t->fk_id_po)->first();
            $gpo = self::poOf($g);
            if (! $gpo || (int) $gpo->fk_id_supplier !== (int) $po->fk_id_supplier) throw new \InvalidArgumentException('The claim is with another supplier.');
            if ($g->claim_status !== 'SETTLED' || $g->claim_settled_as !== 'CREDIT_NOTE') throw new \InvalidArgumentException('Only a claim settled as a credit note can pay a term.');
            if ((int) $gpo->fk_id_currency !== (int) $po->fk_id_currency) throw new \InvalidArgumentException('The credit is in another currency than this PO.');
            $amt = round($amount, 2); $rem = (float) $g->claim_credit_remaining;
            if ($amt <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
            if ($amt > $rem + 0.009) throw new \InvalidArgumentException('Only ' . number_format($rem, 2) . ' credit is left on claim ' . ($g->grn_number ?: $g->grn_code) . '.');
            if ($amt > (float) $t->remaining_amount + 0.009) throw new \InvalidArgumentException('This is more than the ' . number_format((float) $t->remaining_amount, 2) . ' still open on this term.');
            $date ??= date('Y-m-d'); $tol = (float) Settings::get('SRC_MONEY_TOL', $ent, 0.009);
            $base = round($amt * (float) $g->claim_base_amount / max(0.01, (float) $g->claim_amount), 2);      // at the rate the claim was settled
            $pid = (int) DB::table('t_src_payments')->insertGetId(['fk_id_ent' => $ent, 'payment_code' => Numbering::next('PAY', $ent, $date), 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $po->id, 'fk_id_supplier' => $po->fk_id_supplier, 'fk_id_term' => $t->id,
                'payment_type' => 'CREDIT_NOTE', 'payment_method' => 'CREDIT_NOTE', 'payment_amount' => $amt, 'fk_id_currency' => $po->fk_id_currency, 'exchange_rate' => $amt > 0 ? round($base / $amt, 6) : 1, 'base_amount' => $base, 'payment_date' => $date,
                'status' => 'PAID', 'txn_ref' => $g->claim_reference, 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'paid_via' => 'CREDIT_NOTE', 'fk_id_grn' => $grnId, 'notes' => 'Paid from claim ' . ($g->grn_number ?: $g->grn_code)]);
            $paid = round($t->paid_amount + $amt, 2); $left = round($t->planned_amount - $paid, 2);
            DB::table('t_src_payment_terms')->where('id', $t->id)->update(['paid_amount' => $paid, 'remaining_amount' => max(0, $left), 'completion_pct' => $t->planned_amount > 0 ? round($paid / $t->planned_amount * 100, 2) : 100, 'status' => $left <= $tol ? 'PAID' : 'PART_PAID']);
            if ($left <= $tol) TaskEngine::close('SRC_PAY_DUE', CycleAlerts::key('SRC_PAY_DUE', (int) $t->id), 'Term paid');
            Audit::log('t_src_payments', $pid, 'INSERT', ['credit_note' => $amt, 'claim' => $grnId], null, $ent);
            self::refresh($ent, $grnId);
            return $pid;
        });
    }
}
