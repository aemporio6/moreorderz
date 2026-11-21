<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Report contents of the sourcing hub:
 *  - R-F_SETUP-3 Landed Cost (per cycle): every cost element, methods, target unit cost, variance and variance %, total row.
 *  - R-F_SETUP-4 Outstanding Supplier Payments: FX-correct outstanding per PO, base figures, next term due date, ageing chip, Pay action, grouped by supplier.
 *  - R-F_SETUP-9 PO Payment Position: one card per term with its payments, payments not linked to a term (Link to term), totals warnings, Pay this term.
 */
class SourcingReports
{
    public const BUCKETS = ['product_cost' => 'Product cost', 'packaging_cost' => 'Packaging', 'inspection_cost' => 'Inspection', 'origin_cost' => 'Origin', 'freight_cost' => 'Freight', 'insurance_cost' => 'Insurance',
        'customs_duty' => 'Duty', 'destination_cost' => 'Destination', 'last_mile_cost' => 'Last mile', 'other_cost' => 'Other'];

    /** R-F_SETUP-3 */
    public static function landed(int $ent, int $cycleId): array
    {
        $c = DB::table('t_src_cycles')->where('id', $cycleId)->where('fk_id_ent', $ent)->first(['id', 'cycle_code', 'target_unit_cost']) ?? throw new \InvalidArgumentException('Not found.');
        $po = DB::table('t_src_po')->where('fk_id_cycle', $cycleId)->where('fk_id_ent', $ent)->pluck('id')->all();
        $rows = []; $sum = array_fill_keys(array_keys(self::BUCKETS), 0.0) + ['total' => 0.0, 'accepted' => 0.0];
        foreach (DB::table('t_src_landed_cost')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($q) => $q->where('fk_id_cycle', $cycleId)->orWhereIn('fk_id_po', $po ?: [0]))->orderBy('id')->get() as $l) {
            $target = self::target($l, $c);
            $r = ['id' => (int) $l->id, 'lc_code' => $l->lc_code, 'version' => (int) $l->run_version, 'status' => $l->landed_status, 'superseded' => (bool) $l->superseded_by];
            $tot = 0.0; foreach (self::BUCKETS as $k => $_) { $r[$k] = round((float) $l->$k, 2); $tot += (float) $l->$k; }
            $r['total'] = round($tot, 2); $r['accepted'] = (float) $l->accepted_qty;
            $r['per_unit'] = $r['accepted'] > 0 ? round($tot / $r['accepted'], 4) : 0.0; $r['chip'] = $r['accepted'] > 0 ? null : 'No accepted qty';
            $r['freight_method'] = $l->freight_method ?: 'QUANTITY'; $r['duty_method'] = $l->duty_method ?: 'VALUE';
            $r['target'] = $target; $r['variance'] = $target !== null && $r['accepted'] > 0 ? round($r['per_unit'] - $target, 4) : null;
            $r['variance_pct'] = $r['variance'] !== null && $target > 0 ? round($r['variance'] / $target * 100, 1) : null; $r['over_target'] = $r['variance'] !== null && $r['variance'] > 0;
            $r['stored_total'] = (float) $l->total_cost; $r['stored_per_unit'] = (float) $l->landed_cost_per_unit;
            $rows[] = $r;
            if (! $r['superseded']) { foreach (self::BUCKETS as $k => $_) $sum[$k] += $r[$k]; $sum['total'] += $r['total']; $sum['accepted'] += $r['accepted']; }
        }
        foreach ($sum as $k => $v) $sum[$k] = round($v, 2);
        $sum['per_unit'] = $sum['accepted'] > 0 ? round($sum['total'] / $sum['accepted'], 4) : 0.0;
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code], 'buckets' => self::BUCKETS, 'rows' => $rows, 'totals' => $sum, 'base' => Fx::base($ent),
            'note' => 'Total = product + packaging + inspection + origin + freight + insurance + duty + destination + last mile + other. Import VAT is never part of the landed cost. Replaced runs are shown faded and left out of the totals.'];
    }

    /** Target unit cost of a landed-cost row: its PO's cycle product (one product), else the cycle's. */
    private static function target(object $l, object $c): ?float
    {
        $cp = DB::table('t_src_po_lines as pl')->join('t_src_cycle_products as cp', 'cp.id', '=', 'pl.fk_id_cycle_product')->where('pl.fk_id_po', $l->fk_id_po)->where('pl.f_active', 1)->whereNotNull('cp.target_unit_cost')->distinct()->pluck('cp.target_unit_cost');
        if ($cp->count() === 1) return round((float) $cp->first(), 4);
        $one = DB::table('t_src_cycle_products')->where('fk_id_cycle', $c->id)->where('f_active', 1)->whereNotNull('target_unit_cost')->where('target_unit_cost', '>', 0)->pluck('target_unit_cost');
        if ($one->count() === 1) return round((float) $one->first(), 4);
        return $c->target_unit_cost !== null && (float) $c->target_unit_cost > 0 ? round((float) $c->target_unit_cost, 4) : null;
    }

    /** Paid in the PO currency: a payment in the PO currency counts as it is, another currency is converted at its payment date. */
    public static function paidPoCcy(int $ent, object $po): float
    {
        $poCur = Fx::code($po->fk_id_currency, $ent); $sum = 0.0;
        foreach (DB::table('t_src_payments')->where('fk_id_po', $po->id)->where('f_active', 1)->get(['payment_amount', 'fk_id_currency', 'base_amount', 'payment_date']) as $p) {
            $cur = $p->fk_id_currency ? Fx::code($p->fk_id_currency, $ent) : $poCur;
            if ($cur === $poCur) { $sum += (float) $p->payment_amount; continue; }
            $date = (string) ($p->payment_date ?: date('Y-m-d')); $rPo = Fx::rate($poCur, $date, $ent);
            $base = (float) $p->base_amount ?: Fx::toBase((float) $p->payment_amount, $cur, $date, $ent);
            $sum += $rPo > 0 ? $base / $rPo : 0;
        }
        return round($sum, 2);
    }

    /** R-F_SETUP-4 */
    public static function outstanding(int $ent, array $f = []): array
    {
        $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+7 days')); $base = Fx::base($ent); $groups = []; $grand = 0.0;
        $q = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('t_src_cycles as c', 'c.id', '=', 'p.fk_id_cycle')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereNotIn('p.status', ['CANCELLED', 'DRAFT'])
            ->when(! empty($f['supplier']), fn ($w) => $w->where('p.fk_id_supplier', (int) $f['supplier']))->when(! empty($f['cycle']), fn ($w) => $w->where('p.fk_id_cycle', (int) $f['cycle']))
            ->get(['p.id', 'p.po_number', 'p.po_code', 'p.po_total', 'p.fk_id_currency', 'p.fk_id_supplier', 's.supplier_name', 'c.id as cycle_id', 'c.cycle_code']);
        foreach ($q as $po) {
            $cur = Fx::code($po->fk_id_currency, $ent);
            if (! empty($f['currency']) && strtoupper($f['currency']) !== $cur) continue;
            $paid = self::paidPoCcy($ent, $po); $out = round((float) $po->po_total - $paid, 2);
            if ($out <= 0.009) continue;
            $term = DB::table('t_src_payment_terms')->where('fk_id_po', $po->id)->where('f_active', 1)->where('status', '<>', 'PAID')->orderByRaw('due_date IS NULL, due_date')->orderBy('term_no')->first(['id', 'term_no', 'due_date', 'remaining_amount', 'planned_amount']);
            $due = $term->due_date ?? null;
            $chip = ! $due ? ['No due date', 'grey'] : ($due < $today ? ['Overdue', 'red'] : ($due <= $soon ? ['Due ≤ 7 days', 'amber'] : ['Not due', 'grey']));
            if (! empty($f['ageing']) && $f['ageing'] !== $chip[0]) continue;
            $outBase = round($out * Fx::rate($cur, $today, $ent), 2);
            $paidBase = round((float) DB::table('t_src_payments')->where('fk_id_po', $po->id)->where('f_active', 1)->sum('base_amount'), 2);
            $k = (int) $po->fk_id_supplier;
            $groups[$k] ??= ['supplier' => $po->supplier_name ?: '—', 'rows' => [], 'subtotal_base' => 0.0];
            $groups[$k]['rows'][] = ['po_id' => (int) $po->id, 'supplier' => $po->supplier_name, 'po' => $po->po_number ?: $po->po_code, 'cycle' => $po->cycle_code, 'cycle_id' => $po->cycle_id ? (int) $po->cycle_id : null, 'currency' => $cur,
                'po_total' => round((float) $po->po_total, 2), 'paid' => $paid, 'paid_base' => $paidBase, 'outstanding' => $out, 'outstanding_base' => $outBase, 'next_due' => $due, 'ageing' => $chip[0], 'tone' => $chip[1],
                'pay' => ['fk_id_po' => (int) $po->id, 'fk_id_term' => $term ? (int) $term->id : null, 'fk_id_supplier' => $k ?: null, 'fk_id_cycle' => $po->cycle_id ? (int) $po->cycle_id : null, 'payment_amount' => $term ? round((float) ($term->remaining_amount ?: $term->planned_amount), 2) : $out]];
            $groups[$k]['subtotal_base'] = round($groups[$k]['subtotal_base'] + $outBase, 2); $grand += $outBase;
        }
        foreach ($groups as &$g) usort($g['rows'], fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']); unset($g);
        $groups = array_values($groups); usort($groups, fn ($a, $b) => strcasecmp($a['supplier'], $b['supplier']));
        return ['base' => $base, 'groups' => $groups, 'total_label' => 'TOTAL OUTSTANDING', 'total_base' => round($grand, 2), 'chips' => ['Not due', 'Due ≤ 7 days', 'Overdue', 'No due date']];
    }

    /** R-F_SETUP-6: shipments in transit (no arrival, ETA ascending, no-ETA last, red when late) + transfers in transit, and the ledger TRANSIT check. */
    public static function inTransit(int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $late = fn ($d) => $d && $d < $today ? (int) ((strtotime($today) - strtotime($d)) / 86400) : 0;
        $ships = DB::table('t_src_shipments as s')->leftJoin('t_src_po as p', 'p.id', '=', 's.fk_id_po')->leftJoin('t_src_cycles as c', 'c.id', '=', DB::raw('COALESCE(s.fk_id_cycle, p.fk_id_cycle)'))->leftJoin('t_src_forwarders as f', 'f.id', '=', 's.fk_id_forwarder')
            ->where('s.fk_id_ent', $ent)->where('s.f_active', 1)->where(fn ($q) => $q->whereNull('s.actual_arrival')->orWhere('s.actual_arrival', '0000-00-00'))->orderByRaw('s.eta IS NULL, s.eta')->orderBy('s.id')
            ->get(['s.id', 's.shipment_code', 'p.id as po_id', 'p.po_number', 'c.id as cycle_id', 'c.cycle_code', 's.shipping_mode', 'f.forwarder_name', 's.fk_id_warehouse', 's.actual_shipped_qty', 's.confirmed_qty', 's.planned_qty', 's.etd', 's.eta', 's.status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'shipment' => $r->shipment_code, 'po' => $r->po_number, 'po_id' => $r->po_id ? (int) $r->po_id : null, 'cycle' => $r->cycle_code, 'cycle_id' => $r->cycle_id ? (int) $r->cycle_id : null, 'mode' => $r->shipping_mode, 'forwarder' => $r->forwarder_name,
                'destination' => GrnPrint::locName((int) $r->fk_id_warehouse), 'units' => (float) max((float) $r->actual_shipped_qty, (float) $r->confirmed_qty, (float) $r->planned_qty), 'etd' => $r->etd, 'eta' => $r->eta, 'days_late' => $late($r->eta), 'late' => $late($r->eta) > 0, 'status' => $r->status])->all();
        $trans = DB::table('t_src_transfers as t')->leftJoin('t_src_cycles as c', 'c.id', '=', 't.fk_id_cycle')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.qty_in_transit', '>', 0)->orderByRaw('t.expected_arrival IS NULL, t.expected_arrival')->orderBy('t.id')
            ->get(['t.id', 't.transfer_code', 'c.cycle_code', 't.qty_in_transit', 't.transfer_date', 't.expected_arrival', 't.status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'transfer' => $r->transfer_code, 'cycle' => $r->cycle_code, 'qty' => (float) $r->qty_in_transit, 'transfer_date' => $r->transfer_date, 'expected' => $r->expected_arrival, 'days_late' => $late($r->expected_arrival), 'late' => $late($r->expected_arrival) > 0, 'status' => $r->status])->all();
        $u1 = array_sum(array_column($ships, 'units')); $u2 = array_sum(array_column($trans, 'qty'));
        $ledger = (float) DB::table('fin_lot_balances as b')->join('cat_inventory_locations as l', 'l.id', '=', 'b.fk_id_location')->where('l.fk_id_ent', $ent)->where('l.kind', 'TRANSIT')->sum('b.qty');
        $diff = round($ledger - ($u1 + $u2), 3);
        return ['shipments' => $ships, 'transfers' => $trans, 'units_shipments' => $u1, 'units_transfers' => $u2, 'ledger_transit' => $ledger, 'ledger_diff' => $diff,
            'warning' => abs($diff) > 0.0005 ? 'Ledger TRANSIT differs by ' . rtrim(rtrim(number_format($diff, 3, '.', ''), '0'), '.') . ' units' : null];
    }

    /** R-F_SETUP-7: supplier scorecard – every measure in its own aggregate (no multiplied rows). */
    public static function supplierPerf(int $ent, string $from, string $to): array
    {
        $pos = DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotNull('fk_id_supplier')->whereBetween('po_date', [$from, $to])->whereNotIn('status', ['CANCELLED'])->get(['id', 'fk_id_supplier', 'fk_id_cycle', 'po_date', 'required_ship_date', 'exchange_rate']);
        $rows = []; $unassigned = [];
        $supOfCycle = fn ($cy) => DB::table('t_src_po')->where('fk_id_cycle', $cy)->where('f_active', 1)->whereNotNull('fk_id_supplier')->distinct()->pluck('fk_id_supplier');
        foreach ($pos->groupBy('fk_id_supplier') as $sid => $list) {
            $ids = $list->pluck('id')->all();
            $value = round((float) DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->whereIn('l.fk_id_po', $ids)->where('l.f_active', 1)->sum(DB::raw('l.line_total * COALESCE(NULLIF(p.exchange_rate, 0), 1)')), 2);
            $qc = DB::table('t_src_qc')->whereIn('fk_id_po', $ids)->where('f_active', 1)->selectRaw('COALESCE(SUM(qty_failed),0) f, COALESCE(SUM(qty_inspected),0) i')->first();
            $ships = DB::table('t_src_shipments')->whereIn('fk_id_po', $ids)->where('f_active', 1)->get(['id', 'fk_id_po', 'actual_departure']);
            $grn = DB::table('t_src_grn')->whereIn('fk_id_shipment', $ships->pluck('id')->all() ?: [0])->where('f_active', 1)->get(['id', 'grn_number', 'missing_units', 'damaged_units', 'claim_status']);
            // a GRN without shipment counts for the supplier only when its cycle has exactly one supplier
            foreach (DB::table('t_src_grn')->whereIn('fk_id_cycle', $list->pluck('fk_id_cycle')->filter()->unique()->all() ?: [0])->where('f_active', 1)->where(fn ($q) => $q->whereNull('fk_id_shipment')->orWhere('fk_id_shipment', 0))->get(['id', 'grn_number', 'fk_id_cycle', 'missing_units', 'damaged_units', 'claim_status']) as $g) {
                $s = $supOfCycle($g->fk_id_cycle); if ($s->count() === 1) $grn->push($g); else $unassigned[$g->id] = ['grn' => $g->grn_number, 'missing' => (float) $g->missing_units, 'damaged' => (float) $g->damaged_units];
            }
            $grn = $grn->unique('id');
            $byPo = $list->keyBy('id'); $dep = $ships->filter(fn ($x) => $x->actual_departure);
            $onTime = $dep->filter(fn ($x) => ($r = $byPo[$x->fk_id_po]->required_ship_date ?? null) && $x->actual_departure <= $r)->count();
            $actual = $dep->map(fn ($x) => ($d = $byPo[$x->fk_id_po]->po_date ?? null) ? (strtotime($x->actual_departure) - strtotime($d)) / 86400 : null)->filter(fn ($x) => $x !== null);
            $promised = $list->filter(fn ($p) => $p->po_date && $p->required_ship_date)->map(fn ($p) => (strtotime($p->required_ship_date) - strtotime($p->po_date)) / 86400);
            $claims = $grn->filter(fn ($g) => ($g->claim_status ?? '') !== '' || DB::table('tasks')->where('dedupe_key', 'SRC_GRN_SHORT:' . $g->grn_number)->exists())->count();
            $rows[] = ['supplier_id' => (int) $sid, 'supplier' => DB::table('t_src_suppliers')->where('id', $sid)->value('supplier_name') ?? ('#' . $sid), 'pos' => count($ids), 'value' => $value,
                'defect_pct' => (float) $qc->i > 0 ? round((float) $qc->f / (float) $qc->i * 100, 2) : 0.0, 'missing' => (float) $grn->sum('missing_units'), 'damaged' => (float) $grn->sum('damaged_units'),
                'on_time_pct' => $dep->count() ? round($onTime / $dep->count() * 100, 1) : null, 'lead_actual' => $actual->count() ? round($actual->avg(), 1) : null, 'lead_promised' => $promised->count() ? round($promised->avg(), 1) : null, 'claims' => $claims];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        return ['from' => $from, 'to' => $to, 'base' => Fx::base($ent), 'rows' => $rows, 'unassigned_grn' => array_values($unassigned)];
    }

    /** R-F_SETUP-9 */
    public static function poPosition(int $ent, int $poId): array
    {
        $po = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->where('p.id', $poId)->where('p.fk_id_ent', $ent)->first(['p.*', 's.supplier_name']) ?? throw new \InvalidArgumentException('Not found.');
        $cur = Fx::code($po->fk_id_currency, $ent);
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $poId)->where('f_active', 1)->orderBy('term_no')->get();
        $pays = DB::table('t_src_payments')->where('fk_id_po', $poId)->where('f_active', 1)->orderBy('payment_date')->orderBy('id')->get(['id', 'payment_code', 'payment_date', 'payment_amount', 'payment_method', 'txn_ref', 'fk_id_term']);
        $termIds = $terms->pluck('id')->map(fn ($x) => (int) $x)->all();
        $cards = $terms->map(function ($t) use ($pays) {
            $mine = $pays->where('fk_id_term', $t->id)->values(); $paid = round((float) $t->paid_amount, 2); $planned = round((float) $t->planned_amount, 2); $rem = round((float) $t->remaining_amount, 2);
            $st = (string) $t->status; $label = ['PAID' => 'Paid', 'PART_PAID' => 'Partially Paid', 'PARTIAL' => 'Partially Paid', 'PARTIALLY_PAID' => 'Partially Paid'][strtoupper($st)] ?? ucfirst(strtolower(str_replace('_', ' ', $st)));
            $done = $planned > 0 ? round($paid / $planned * 100) : 0;
            $no = (int) $t->term_no; return ['id' => (int) $t->id, 'term_no' => $no, 'text' => "Term $no: {$t->milestone} — " . rtrim(rtrim(number_format((float) $t->pct, 2, '.', ''), '0'), '.') . '% = ' . number_format($planned, 2) . ' · Paid ' . number_format($paid, 2) . ' · Remaining ' . number_format($rem, 2) . " · $label ($done%)",
                'status' => $label, 'tone' => $label === 'Paid' ? 'green' : ($label === 'Partially Paid' ? 'amber' : 'grey'), 'remaining' => $rem, 'due_date' => $t->due_date,
                'payments' => $mine->map(fn ($p) => ['id' => (int) $p->id, 'code' => $p->payment_code, 'date' => $p->payment_date, 'amount' => round((float) $p->payment_amount, 2), 'method' => $p->payment_method, 'txn_ref' => $p->txn_ref])->all()];
        })->all();
        $loose = $pays->filter(fn ($p) => ! $p->fk_id_term || ! in_array((int) $p->fk_id_term, $termIds, true))->values()->map(fn ($p) => ['id' => (int) $p->id, 'code' => $p->payment_code, 'date' => $p->payment_date, 'amount' => round((float) $p->payment_amount, 2), 'method' => $p->payment_method, 'txn_ref' => $p->txn_ref])->all();
        $warn = []; $pct = round((float) $terms->sum('pct'), 2); $planned = round((float) $terms->sum('planned_amount'), 2);
        if ($terms->count() && abs($pct - 100) > 0.001) $warn[] = 'Terms add up to ' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . ' %, not 100 %';
        if ($terms->count() && abs($planned - (float) $po->po_total) > 0.01) $warn[] = 'Terms total differs from PO total by ' . number_format(abs($planned - (float) $po->po_total), 2);
        $paid = self::paidPoCcy($ent, $po);
        return ['po' => ['id' => (int) $po->id, 'number' => $po->po_number ?: $po->po_code, 'supplier' => $po->supplier_name, 'total' => round((float) $po->po_total, 2), 'currency' => $cur, 'cycle' => $po->fk_id_cycle ? (int) $po->fk_id_cycle : null, 'supplier_id' => $po->fk_id_supplier ? (int) $po->fk_id_supplier : null],
            'paid' => $paid, 'remaining' => round((float) $po->po_total - $paid, 2), 'cards' => $cards, 'not_linked' => $loose, 'warnings' => $warn,
            'unpaid_terms' => collect($cards)->filter(fn ($c) => $c['status'] !== 'Paid')->map(fn ($c) => ['id' => $c['id'], 'label' => "Term {$c['term_no']}"])->values()->all()];
    }

    /** "Link to term": a payment of the PO that has no (active) term gets one of the PO's terms; the term's paid / remaining / status follow. */
    public static function linkPayment(int $ent, int $paymentId, int $termId): array
    {
        $p = DB::table('t_src_payments')->where('id', $paymentId)->where('fk_id_ent', $ent)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Not found.');
        $t = DB::table('t_src_payment_terms')->where('id', $termId)->where('fk_id_po', $p->fk_id_po)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Choose a term of this PO.');
        return DB::transaction(function () use ($p, $t, $ent) {
            DB::table('t_src_payments')->where('id', $p->id)->update(['fk_id_term' => $t->id, 'updated_at' => now()]);
            foreach (array_filter([(int) $p->fk_id_term, (int) $t->id]) as $tid) self::refreshTerm($tid);
            Audit::log('t_src_payments', (int) $p->id, 'UPDATE', ['fk_id_term' => [$p->fk_id_term, $t->id]], 'Linked to a term from the PO payment position', $ent);
            return ["message" => "Payment {$p->payment_code} linked to Term " . (int) $t->term_no . "."];
        });
    }

    private static function refreshTerm(int $termId): void
    {
        $t = DB::table('t_src_payment_terms')->where('id', $termId)->first(); if (! $t) return;
        $paid = round((float) DB::table('t_src_payments')->where('fk_id_term', $termId)->where('f_active', 1)->sum('payment_amount'), 2); $plan = (float) $t->planned_amount;
        DB::table('t_src_payment_terms')->where('id', $termId)->update(['paid_amount' => $paid, 'remaining_amount' => max(0, round($plan - $paid, 2)), 'completion_pct' => $plan > 0 ? round($paid / $plan * 100, 2) : 0, 'status' => $paid <= 0 ? 'DUE' : ($paid + 0.009 >= $plan ? 'PAID' : 'PART_PAID')]);
    }
}
