<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The sourcing alert-code registry (R-B_SOURCING-26, X4) and the per-cycle Overview: pipeline (R-17), Needs action, Quantity check (R-39), Contacts.
 * One detector per code finds the problems; the sweep turns them into tasks (dedupe + auto-close) and the cycle page shows the same rows.
 */
class CycleAlerts
{
    /** the 12 step groups of R-17 (old order), with their tables; the key is the one cycles keep in skipped_steps */
    public const GROUPS = [
        'rfq' => 'RFQ / Quotations', 'samples' => 'Samples / Approval', 'po' => 'Purchase Orders · PO Lines · Cartons', 'pay' => 'Payment Schedule · Payments', 'production' => 'Production', 'qc' => 'QC / Inspection',
        'allocation' => 'Destination Allocation', 'shipment' => 'Shipments', 'freight' => 'Freight Costs', 'customs' => 'Customs / Import', 'grn' => 'Landing / GRN', 'landed_cost' => 'Inventory Receiving · Landed Cost',
    ];
    /** R-F_SETUP-19 strip colours: done teal, current black, pending grey, missing amber, skipped teal hatched */
    public const STRIP = ['done' => '#0F5E63', 'current' => '#16181D', 'pending' => '#E1E4DE', 'missing' => '#E8A33D', 'skipped' => 'hatched'];
    public const CODES = ['SRC_PAY_DUE', 'SRC_QC_FAILED', 'SRC_SHIP_ARRIVING', 'SRC_SHIP_OVERDUE', 'SRC_GRN_SHORT', 'SRC_LOT_NO_COST', 'SRC_QTY_MISMATCH', 'SRC_OVER_ALLOC', 'SRC_STEP_MISSING', 'SRC_DOC_MISSING', 'STK_NO_LOCATION', 'STK_NO_COST', 'STK_LEDGER_MISMATCH'];
    private const HISTORICAL_ONLY = ['SRC_PAY_DUE', 'SRC_GRN_SHORT', 'SRC_SHIP_OVERDUE', 'SRC_LOT_NO_COST'];
    public const DOC_STEPS = ['po' => ['t_src_po', 'po_number', 'fk_id_po', 'PO'], 'shipment' => ['t_src_shipments', 'shipment_code', 'fk_id_shipment', 'Shipment'], 'qc' => ['t_src_qc', 'qc_code', 'fk_id_qc', 'QC'], 'customs' => ['t_src_customs', 'customs_code', 'fk_id_customs', 'Customs'], 'grn' => ['t_src_grn', 'grn_number', 'fk_id_grn', 'GRN'], 'sample' => ['t_src_samples', 'sample_code', 'fk_id_sample', 'Sample']];

    /** Dedupe key of a code for one record: `<code>:<record code>` (R-26) – PO number + term no for a payment term, shipment / GRN / lot codes. */
    public static function key(string $code, int $id): array
    {
        return match ($code) {
            'SRC_PAY_DUE' => ($t = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.id', $id)->first(['p.po_number', 't.term_no'])) ? [$t->po_number, 'T' . $t->term_no] : [$id],
            'SRC_SHIP_ARRIVING', 'SRC_SHIP_OVERDUE' => [DB::table('t_src_shipments')->where('id', $id)->value('shipment_code') ?: $id],
            'SRC_GRN_SHORT' => [DB::table('t_src_grn')->where('id', $id)->value('grn_number') ?: $id],
            'SRC_LOT_NO_COST' => [DB::table('fin_lots')->where('id', $id)->value('lot_code') ?: $id],
            default => [$id],
        };
    }

    private static function cycles(int $ent, ?int $only = null)
    {
        return DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CANCELLED', 'Cancelled'])->when($only, fn ($q) => $q->where('id', $only))->get(['id', 'cycle_code', 'cycle_type', 'skipped_steps', 'created_by'])->keyBy('id');
    }

    private static function manager(int $ent): int { return TaskEngine::fewestOpen('MANAGER', $ent) ?: (int) DB::table('t_users')->where('role_code', 'OWNER')->where('f_active', 1)->value('id'); }

    private static function poIds(array $cycleIds): array { return DB::table('t_src_po')->whereIn('fk_id_cycle', $cycleIds ?: [0])->where('f_active', 1)->pluck('fk_id_cycle', 'id')->all(); }

    /** Counts of active rows per group, and the state of the pipeline (R-17). */
    public static function pipeline(object $c): array
    {
        $id = $c->id; $po = array_keys(self::poIds([$id])); $n = fn (string $t, string $col = 'fk_id_cycle', ?array $in = null) => (int) DB::table($t)->where('f_active', 1)->when($in === null, fn ($q) => $q->where($col, $id), fn ($q) => $q->whereIn($col, $in ?: [0]))->count();
        $cnt = ['rfq' => $n('t_src_rfq'), 'samples' => $n('t_src_samples'), 'po' => count($po), 'pay' => $n('t_src_payment_terms', 'fk_id_po', $po) + $n('t_src_payments'), 'production' => $n('t_src_production'), 'qc' => $n('t_src_qc'),
            'allocation' => $n('t_src_allocations'), 'shipment' => (int) DB::table('t_src_shipments')->where('f_active', 1)->where(fn ($q) => $q->where('fk_id_cycle', $id)->orWhereIn('fk_id_po', $po ?: [0]))->count(), 'freight' => $n('t_src_freight'), 'customs' => $n('t_src_customs'),
            'grn' => $n('t_src_grn'), 'landed_cost' => $n('t_src_inventory_receipts') + $n('t_src_landed_cost', 'fk_id_po', $po)];
        $skip = array_values(array_filter(array_map('trim', explode(',', (string) $c->skipped_steps))));
        $back = array_values(array_filter($skip, fn ($k) => ($cnt[$k] ?? 0) > 0));      // a record added to a skipped step un-skips it (R-17)
        if ($back) {
            $skip = array_values(array_diff($skip, $back)); DB::table('t_src_cycles')->where('id', $id)->update(['skipped_steps' => implode(',', $skip)]); $c->skipped_steps = implode(',', $skip);
            foreach ($back as $k) { DB::table('sys_workflow_state')->updateOrInsert(['workflow' => 'SRC_CYCLE', 'entity_id' => $id, 'step_key' => $k], ['status' => 'OPEN', 'reason' => 'STEP_UNSKIPPED', 'done_by' => Context::userId(), 'done_at' => now()]);
                Audit::log('t_src_cycles', $id, 'UPDATE', ['STEP_UNSKIPPED' => self::GROUPS[$k]], 'A record was added to a skipped step'); }
        }
        $keys = array_keys(self::GROUPS); $last = -1; foreach ($keys as $i => $k) if ($cnt[$k] > 0) $last = $i;
        $current = null; foreach ($keys as $i => $k) if ($i > $last && $cnt[$k] === 0 && ! in_array($k, $skip, true)) { $current = $k; break; }
        if ($last < 0) $current = current(array_filter($keys, fn ($k) => ! in_array($k, $skip, true))) ?: null;
        $missing = []; foreach ($keys as $i => $k) if ($i < $last && $cnt[$k] === 0 && ! in_array($k, $skip, true)) $missing[] = $k;      // empty, not skipped, before the last step with records
        $steps = []; foreach ($keys as $k) $steps[] = ['key' => $k, 'name' => self::GROUPS[$k], 'count' => $cnt[$k], 'state' => in_array($k, $skip, true) && ! $cnt[$k] ? 'skipped' : ($cnt[$k] ? 'done' : ($k === $current ? 'current' : (in_array($k, $missing, true) ? 'missing' : 'pending')))];
        $col = StepColours::forCycle((int) $id, $po, $skip);      // R-B_SOURCING-16: the colour from the data (done / bad lists in config/workflows.php)
        foreach ($steps as &$st) { $st['colour'] = $col[$st['key']] ?? 'pend'; $st['colour_label'] = config('workflows.labels.' . $st['colour']); $st['done'] = $st['count'] > 0 || in_array($st['key'], $skip, true); $st['strip'] = self::STRIP[$st['state']]; } unset($st);
        // R-F_SETUP-19: progress = stages with records ÷ (12 − skipped); skip counts as done (hatched), never as progress
        $withRec = count(array_filter($cnt)); $den = count($keys) - count(array_filter($skip, fn ($k) => isset(self::GROUPS[$k]) && ! $cnt[$k]));
        return ['legend' => StepColours::legend(), 'strip_legend' => self::STRIP, 'progress_pct' => $den > 0 ? (int) round($withRec / $den * 100) : 100, 'last_done' => $last >= 0 ? $keys[$last] : null, 'steps' => $steps, 'current' => $current, 'current_name' => $current ? self::GROUPS[$current] : 'Complete', 'last_name' => $last >= 0 ? self::GROUPS[$keys[$last]] : null, 'missing' => $missing, 'missing_text' => $missing ? 'record missing: ' . implode(', ', array_map(fn ($k) => self::GROUPS[$k], $missing)) : null];
    }

    /**
     * Sourcing dashboard (R-F_SETUP-19): one row per open cycle from the stage engine – strip, current stage, missing text, progress %,
     * suppliers (distinct, active cycle products only), products with target qty, PO numbers, Next action (first Needs-action item, R-F_SETUP-20, else "—"),
     * Days open (floor of days since the sourcing date, never below 0; 0 without a date), cycle cell "code + entered dd Mon · status".
     * Tiles: open cycles per current stage (a "Complete" cycle is not counted). Paged.
     */
    public static function dashboard(int $ent, int $page = 1, int $per = 30): array
    {
        $all = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'Closed', 'CANCELLED', 'Cancelled', 'COMPLETED', 'Completed'])->orderByDesc('id')
            ->get(['id', 'cycle_code', 'cycle_type', 'skipped_steps', 'created_by', 'status', 'sourcing_date', 'created_at', 'product_name']);
        $tiles = array_fill_keys(array_keys(self::GROUPS), 0); $rows = [];
        $page = max(1, $page); $from = ($page - 1) * $per; $today = new \DateTimeImmutable('today');
        foreach ($all as $i => $c) {
            $pl = self::pipeline($c); if ($pl['current']) $tiles[$pl['current']]++;
            if ($i < $from || $i >= $from + $per) continue;
            $po = DB::table('t_src_po')->where('fk_id_cycle', $c->id)->where('f_active', 1)->orderBy('id')->get(['po_number', 'po_code']);
            $prods = DB::table('t_src_cycle_products as cp')->leftJoin('t_src_suppliers as s', 's.id', '=', 'cp.fk_id_supplier')->where('cp.fk_id_cycle', $c->id)->where('cp.f_active', 1)->orderBy('cp.id')->get(['cp.product_name', 'cp.target_qty', 's.supplier_name']);
            $needs = self::detect($ent, (int) $c->id); usort($needs, fn ($a, $b) => [$a['p'], $a['due'] ?? '9999'] <=> [$b['p'], $b['due'] ?? '9999']);
            $days = $c->sourcing_date ? max(0, (int) floor(($today->getTimestamp() - strtotime((string) $c->sourcing_date)) / 86400)) : 0;
            $rows[] = ['id' => (int) $c->id, 'code' => $c->cycle_code, 'cell' => $c->cycle_code . ' · entered ' . ($c->created_at ? date('d M', strtotime((string) $c->created_at)) : '—') . ' · ' . ($c->status ?: '—'),
                'steps' => array_map(fn ($st) => array_intersect_key($st, array_flip(['key', 'name', 'state', 'strip', 'count', 'done'])), $pl['steps']), 'current' => $pl['current'], 'current_name' => $pl['current_name'], 'missing_text' => $pl['missing_text'], 'progress_pct' => $pl['progress_pct'],
                'suppliers' => $prods->pluck('supplier_name')->filter()->unique()->values()->all(), 'products' => $prods->map(fn ($p) => trim($p->product_name . ' ' . number_format((float) $p->target_qty, 0, '.', '')))->all(),
                'pos' => $po->map(fn ($p) => $p->po_number ?: $p->po_code)->filter()->values()->all(), 'next_action' => $needs[0]['title'] ?? '—', 'days_open' => $days];
        }
        return ['tiles' => array_map(fn ($k) => ['key' => $k, 'name' => self::GROUPS[$k], 'open' => $tiles[$k]], array_keys(self::GROUPS)), 'rows' => $rows, 'total' => $all->count(), 'page' => $page, 'per_page' => $per,
            'legend' => [['Done', self::STRIP['done']], ['Current', self::STRIP['current']], ['Pending', self::STRIP['pending']], ['Record missing', self::STRIP['missing']], ['Skipped', 'hatched']]];
    }

    /** Quantity check per cycle product (R-39, v1.7: read from the GRN lines). */
    public static function quantities(int $cycleId): array
    {
        $out = [];
        foreach (DB::table('t_src_cycle_products as cp')->leftJoin('t_product_vendors as p', 'p.id', '=', 'cp.fk_id_product')->where('cp.fk_id_cycle', $cycleId)->get(['cp.id', 'cp.fk_id_product', 'p.SKU_VENDOR as sku', 'cp.product_name']) as $cp) {
            $ord = (float) DB::table('t_src_po_lines')->where('f_active', 1)->where('fk_id_cycle', $cycleId)->where(fn ($q) => $q->where('fk_id_cycle_product', $cp->id)->orWhere(fn ($w) => $w->whereNull('fk_id_cycle_product')->where('fk_id_product', $cp->fk_id_product)))->sum('qty_ordered');
            $al = DB::table('t_src_allocations')->where('f_active', 1)->where('fk_id_cycle', $cycleId)->where('fk_id_cycle_product', $cp->id)->selectRaw('COALESCE(SUM(allocated_qty),0) planned, COALESCE(SUM(confirmed_qty),0) confirmed, COALESCE(SUM(dispatched_qty),0) dispatched')->first();
            $g = DB::table('t_src_grn_lines as l')->join('t_src_grn as g', 'g.id', '=', 'l.fk_id_grn')->where('g.fk_id_cycle', $cycleId)->where('g.f_active', 1)->where('l.fk_id_product', $cp->fk_id_product)
                ->selectRaw('COALESCE(SUM(l.expected_qty),0) expected, COALESCE(SUM(l.received_qty),0) received, COALESCE(SUM(l.accepted_qty),0) accepted, COALESCE(SUM(l.damaged_qty),0) damaged, COALESCE(SUM(l.missing_qty),0) missing')->first();
            $planned = (float) $al->planned; $ok = fn ($a, $b) => abs($a - $b) < 0.001;
            if ($ord > 0 && ! $ok($planned, $ord)) $chip = $planned < $ord ? ['amber', ($ord - $planned) . ' unallocated'] : ['amber', ($planned - $ord) . ' OVER'];
            elseif ((float) $g->received > 0 || (float) $g->expected > 0) $chip = $ok((float) $g->expected, (float) $g->received + (float) $g->missing) && $ok((float) $g->received, (float) $g->accepted + (float) $g->damaged) ? ['teal', 'Balanced'] : ['red', 'Mismatch'];
            elseif ((float) $al->dispatched > 0) $chip = ['grey', 'In transit'];
            elseif ($ord > 0) $chip = ['teal', 'OK'];
            else $chip = ['grey', '—'];
            $out[] = ['cycle_product' => (int) $cp->id, 'product' => (int) $cp->fk_id_product, 'sku' => $cp->sku, 'name' => $cp->product_name, 'ordered' => $ord, 'planned' => $planned, 'confirmed' => (float) $al->confirmed, 'dispatched' => (float) $al->dispatched,
                'expected' => (float) $g->expected, 'received' => (float) $g->received, 'accepted' => (float) $g->accepted, 'damaged' => (float) $g->damaged, 'missing' => (float) $g->missing, 'chip' => $chip[1], 'tone' => $chip[0]];
        }
        return $out;
    }

    /** Every problem of the registry for one company (or one cycle): [code, key, cycle, p, title, detail, chip, due, opt]. */
    public static function detect(int $ent, ?int $cycleId = null): array
    {
        $cy = self::cycles($ent, $cycleId); $ids = $cy->keys()->all(); $po = self::poIds($ids); $today = date('Y-m-d'); $rows = [];
        $hist = fn ($cid) => strtoupper((string) ($cy[$cid]->cycle_type ?? '')) === 'HISTORICAL';
        $add = function (string $code, array $key, ?int $cid, string $p, string $title, string $detail, string $chip, ?string $due, array $opt) use (&$rows, $hist) {
            if ($cid && $hist($cid) && ! in_array($code, self::HISTORICAL_ONLY, true)) return;      // old-entry cycles: no step-gap rules
            $rows[] = ['code' => $code, 'key' => $key, 'cycle' => $cid, 'p' => $p, 'title' => $title, 'detail' => $detail, 'chip' => $chip, 'due' => $due, 'opt' => $opt];
        };
        $days = (int) Settings::get('SRC_PAY_REMIND_DAYS', $ent, 3);
        // SRC_PAY_DUE: not Paid and planned > 0; a due date only moves the reminder (no due date → never lost)
        foreach (DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('p.f_active', 1)->where('t.status', '<>', 'PAID')->where('t.planned_amount', '>', 0)->whereNotIn('p.status', ['CANCELLED', 'DRAFT'])
            ->where(fn ($q) => $q->whereNull('t.due_date')->orWhere('t.due_date', '<=', now()->addDays($days)->toDateString()))->when($cycleId, fn ($q) => $q->where('p.fk_id_cycle', $cycleId))->get(['t.id', 't.term_no', 't.milestone', 't.planned_amount', 't.remaining_amount', 't.due_date', 'p.id as po', 'p.po_number', 'p.fk_id_cycle', 'p.exchange_rate', 'p.created_by']) as $t)
            $add('SRC_PAY_DUE', [$t->po_number, 'T' . $t->term_no], (int) $t->fk_id_cycle, 'P1', "Pay Term {$t->term_no} · {$t->milestone}", 'Remaining ' . number_format((float) $t->remaining_amount, 2) . ' of ' . number_format((float) $t->planned_amount, 2) . " ({$t->po_number})", 'Payment Schedule', $t->due_date,
                ['module' => 't_src_po', 'record_id' => $t->po, 'amount' => round((float) $t->remaining_amount * (float) ($t->exchange_rate ?: 1), 2), 'fix_url' => "#/po/{$t->po}"]);
        // SRC_QC_FAILED: failed units and no corrective action
        foreach (DB::table('t_src_qc')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('fk_id_cycle', $ids ?: [0])->where('qty_failed', '>', 0)->where(fn ($q) => $q->whereNull('corrective_action')->orWhere('corrective_action', ''))->get() as $q)
            $add('SRC_QC_FAILED', [$q->qc_code], (int) $q->fk_id_cycle, 'P1', "QC failures need corrective action · {$q->qc_code}", (int) $q->qty_failed . ' failed of ' . (int) $q->qty_inspected . ' inspected', 'QC / Inspection', $q->inspection_date, ['module' => 't_src_qc', 'record_id' => $q->id, 'fix_url' => '#/cycle-extras/' . $q->fk_id_cycle]);
        // SRC_SHIP_ARRIVING / SRC_SHIP_OVERDUE
        $soon = now()->addDays((int) Settings::get('SRC_SHIP_ARRIVING_DAYS', $ent, 3))->toDateString();
        foreach (DB::table('t_src_shipments')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('actual_arrival')->whereNotNull('eta')->when($cycleId, fn ($w) => $w->where(fn ($q) => $q->where('fk_id_cycle', $cycleId)->orWhereIn('fk_id_po', array_keys($po) ?: [0])))->get() as $s) {
            $cid = (int) ($s->fk_id_cycle ?: ($po[$s->fk_id_po] ?? DB::table('t_src_po')->where('id', $s->fk_id_po)->value('fk_id_cycle') ?? 0)) ?: null; $eta = date('d-m-Y', strtotime($s->eta));
            if ($s->eta < $today) $add('SRC_SHIP_OVERDUE', [$s->shipment_code ?: $s->id], $cid, 'P1', "Shipment overdue · {$s->shipment_code}", "ETA was $eta - chase forwarder", 'Shipments', $s->eta, ['module' => 't_src_shipments', 'record_id' => $s->id, 'fix_url' => ($cid ? "#/cycle/$cid" : "#/po/{$s->fk_id_po}")]);
            elseif ($s->eta <= $soon) $add('SRC_SHIP_ARRIVING', [$s->shipment_code ?: $s->id], $cid, 'P2', "Shipment arriving · {$s->shipment_code}", "ETA $eta – prepare GRN", 'Shipments', $s->eta, ['module' => 't_src_shipments', 'record_id' => $s->id, 'fix_url' => "#/cycle/$cid"]);
        }
        // SRC_GRN_SHORT: missing or damaged units until the claim is settled or marked "no claim"
        foreach (DB::table('t_src_grn')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('fk_id_cycle', $ids ?: [0])->where(fn ($q) => $q->where('missing_units', '>', 0)->orWhere('damaged_units', '>', 0))->where(fn ($q) => $q->whereNull('claim_status')->orWhereNotIn('claim_status', ['SETTLED', 'NO_CLAIM']))->get() as $g)
            $add('SRC_GRN_SHORT', [$g->grn_number ?: $g->id], (int) $g->fk_id_cycle, 'P2', "Claim short delivery · {$g->grn_number}", 'Received ' . (int) $g->units_received . ' · Accepted ' . ((int) $g->units_received - (int) $g->damaged_units) . ' · Damaged ' . (int) $g->damaged_units . ' · Missing ' . (int) $g->missing_units, 'Landing / GRN', $g->grn_date, ['module' => 't_src_grn', 'record_id' => $g->id, 'fix_url' => "#/cycle/{$g->fk_id_cycle}"]);
        // SRC_LOT_NO_COST: an active lot without a landed unit cost
        foreach (DB::table('fin_lots as l')->leftJoin('t_src_inventory_receipts as r', 'r.id', '=', 'l.fk_id_receipt')->where('l.company_id', $ent)->where('l.qty_left', '>', 0)->where(fn ($q) => $q->whereNull('l.unit_cost_gbp')->orWhere('l.unit_cost_gbp', '<=', 0))->when($cycleId, fn ($q) => $q->where('l.fk_id_cycle', $cycleId))->get(['l.id', 'l.lot_code', 'l.sku', 'l.fk_id_cycle', 'r.receipt_code']) as $l)
            $add('SRC_LOT_NO_COST', [$l->lot_code ?: $l->id], $l->fk_id_cycle ? (int) $l->fk_id_cycle : null, 'P2', 'Enter Landed Unit Cost · ' . ($l->receipt_code ?: $l->lot_code ?: $l->sku), 'Field empty on this lot', 'Inventory Receiving', null, ['module' => 'fin_lots', 'record_id' => $l->id, 'fix_url' => $l->fk_id_cycle ? "#/cycle/{$l->fk_id_cycle}" : '#/stock']);
        foreach ($cy as $c) {
            // SRC_QTY_MISMATCH / SRC_OVER_ALLOC from the quantity check (R-39)
            foreach (self::quantities((int) $c->id) as $q) {
                $what = $q['sku'] ?: $q['name']; $opt = ['module' => 't_src_cycles', 'record_id' => $c->id, 'fix_url' => "#/cycle/{$c->id}"];
                if ($q['chip'] === 'Mismatch') $add('SRC_QTY_MISMATCH', [$c->cycle_code, $what], (int) $c->id, 'P1', "Quantity check · $what", 'Mismatch: ' . abs($q['expected'] - $q['received'] - $q['missing']) + abs($q['received'] - $q['accepted'] - $q['damaged']) . ' units unexplained', 'Quantity check', null, $opt);
                if (str_ends_with($q['chip'], 'unallocated') || str_ends_with($q['chip'], 'OVER')) $add('SRC_OVER_ALLOC', [$c->cycle_code, $what], (int) $c->id, 'P2', "Quantity check · $what", $q['chip'], 'Quantity check', null, $opt);
            }
            // SRC_STEP_MISSING: an empty step (not skipped) before the last step with records
            $pl = self::pipeline($c);
            foreach ($pl['missing'] as $k) $add('SRC_STEP_MISSING', [$c->cycle_code, $k], (int) $c->id, 'P2', 'Record missing · ' . self::GROUPS[$k], 'Stage before ' . ($pl['current'] ? $pl['current_name'] : $pl['last_name']) . ' has no record', self::GROUPS[$k], null, ['module' => 't_src_cycles', 'record_id' => $c->id, 'fix_url' => "#/cycle/{$c->id}", 'owner_user_id' => (int) $c->created_by ?: self::manager($ent)]);
        }
        // SRC_DOC_MISSING: a required document of the step not linked, or a linked Drive URL that is broken
        $req = DB::table('src_doc_requirements')->where('fk_id_ent', $ent)->where('f_active', 1)->get();
        foreach ($req as $r) {
            if (! isset(self::DOC_STEPS[$r->step_key])) continue; [$t, $codeCol, $fk, $label] = self::DOC_STEPS[$r->step_key];
            $recs = DB::table($t)->where('f_active', 1)->where('fk_id_ent', $ent)->whereIn('fk_id_cycle', $ids ?: [0])->get(['id', $codeCol . ' as code', 'fk_id_cycle', 'created_by']);
            foreach ($recs as $x) {
                $has = DB::table('t_src_documents')->where('f_active', 1)->where($fk, $x->id)->whereRaw('LOWER(TRIM(doc_type)) = ?', [mb_strtolower(trim($r->doc_type))])->exists();
                if (! $has) $add('SRC_DOC_MISSING', [$x->code, $r->doc_type], (int) $x->fk_id_cycle, 'P2', "Document missing · {$r->doc_type} · {$x->code}", "{$x->code} has no {$r->doc_type}", $label, null, ['module' => $t, 'record_id' => $x->id, 'fix_url' => "#/cycle/{$x->fk_id_cycle}", 'owner_user_id' => (int) $x->created_by ?: self::manager($ent)]);
            }
        }
        foreach (DB::table('t_src_documents')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('fk_id_cycle', $ids ?: [0])->where(fn ($q) => $q->whereNull('drive_url')->orWhere('drive_url', 'not regexp', '^[Hh][Tt][Tt][Pp][Ss]?://'))->get() as $d)
            $add('SRC_DOC_MISSING', ['DOC' . $d->id, $d->doc_type ?: 'document'], (int) $d->fk_id_cycle, 'P2', 'Document missing · ' . ($d->doc_type ?: 'document') . ' · ' . ($d->doc_name ?: 'DOC' . $d->id), 'link broken', 'Documents', null, ['module' => 't_src_documents', 'record_id' => $d->id, 'fix_url' => "#/cycle/{$d->fk_id_cycle}", 'owner_user_id' => (int) ($d->uploaded_by ?: $d->created_by) ?: self::manager($ent)]);
        // stock checks (company-wide, not per cycle)
        if (! $cycleId) {
            foreach (DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where('p.stock', '>', 0)->whereRaw('NOT EXISTS (SELECT 1 FROM fin_lot_balances b JOIN fin_lots l ON l.id = b.fk_id_lot WHERE l.fk_id_product = p.id AND b.qty > 0 AND b.fk_id_location IS NOT NULL)')->get(['p.id', 'p.SKU_VENDOR', 'p.stock']) as $p)
                $add('STK_NO_LOCATION', [$p->SKU_VENDOR ?: $p->id], null, 'P2', "Stock without a location · {$p->SKU_VENDOR}", (int) $p->stock . ' unit(s) in stock, no location holds them', 'Stock', null, ['module' => 't_product_vendors', 'record_id' => $p->id, 'fix_url' => "#/stock/product/{$p->id}"]);
            foreach (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('stock', '>', 0)->where(fn ($q) => $q->whereNull('landing_price')->orWhere('landing_price', '<=', 0))->get(['id', 'SKU_VENDOR', 'stock']) as $p)
                $add('STK_NO_COST', [$p->SKU_VENDOR ?: $p->id], null, 'P2', "Stock without a cost · {$p->SKU_VENDOR}", (int) $p->stock . ' unit(s) in stock, landing price empty', 'Stock', null, ['module' => 't_product_vendors', 'record_id' => $p->id, 'fix_url' => "#/stock/product/{$p->id}"]);
        }
        return $rows;
    }

    /** Turn the problems into tasks, close the tasks whose problem is gone (every 15 minutes, on save of a sourcing record, and at night). */
    public static function sweep(int $ent, ?array $only = null): array
    {
        $rows = self::detect($ent); $out = [];
        foreach ($only ?? self::CODES as $code) {
            if ($code === 'STK_LEDGER_MISMATCH') { $out[$code] = array_intersect_key(StockService::selfCheck($ent), ['opened' => 1, 'closed' => 1]); continue; }
            $out[$code] = TaskEngine::sync($code, array_map(fn ($r) => ['key' => $r['key'], 'title' => $r['title'] . ' – ' . $r['detail'], 'opt' => $r['opt']], array_values(array_filter($rows, fn ($r) => $r['code'] === $code))), null, $ent);
        }
        return $out;
    }

    private static bool $dirty = false;
    /** A sourcing record was saved: sweep once at the end of the request (not inside its transaction). */
    public static function touched(): void
    {
        if (self::$dirty || app()->runningUnitTests()) return; self::$dirty = true;
        app()->terminating(function () { self::$dirty = false; if ($e = Context::ent()) try { self::sweep((int) $e); } catch (\Throwable $x) { report($x); } });
    }

    /** Overview tab of one cycle: pipeline, Needs action (P1, P2, then due date), quantity check, contacts. A part that fails shows a short note and alerts the admin once. */
    public static function overview(int $ent, int $cycleId, ?object $u): array
    {
        $c = self::cycles($ent, $cycleId)->first() ?? throw new \InvalidArgumentException('Cycle not found.');
        $safe = function (string $part, callable $fn) use ($ent) { try { return $fn(); } catch (\Throwable $e) { report($e); self::healthAlert($ent, $part, $e); return ['error' => 'This section could not load – the admin has been told']; } };
        $pipe = $safe('pipeline', fn () => self::pipeline($c));
        $needs = $safe('needs', function () use ($ent, $cycleId) {
            $rows = array_map(fn ($r) => ['code' => $r['code'], 'p' => $r['p'], 'title' => $r['title'], 'detail' => $r['detail'], 'chip' => $r['chip'], 'due' => $r['due'], 'fix' => $r['opt']['fix_url'] ?? null], self::detect($ent, $cycleId));
            usort($rows, fn ($a, $b) => [$a['p'], $a['due'] ?? '9999'] <=> [$b['p'], $b['due'] ?? '9999']); return $rows; });
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code, 'type' => $c->cycle_type], 'pipeline' => $pipe, 'needs' => $needs, 'next_action' => is_array($needs) && ! isset($needs['error']) && $needs ? $needs[0]['title'] : '—',
            'quantities' => $safe('quantities', fn () => self::quantities($cycleId)), 'contacts' => $safe('contacts', fn () => self::contacts($ent, $cycleId))];
    }

    private static function healthAlert(int $ent, string $part, \Throwable $e): void
    {
        $msg = mb_substr("Cycle overview › $part: " . $e->getMessage(), 0, 1000); $fp = sha1('cycle_overview|' . $part . '|' . get_class($e));
        $old = DB::table('sys_error_log')->where('fingerprint', $fp)->first();
        if ($old) DB::table('sys_error_log')->where('id', $old->id)->update(['times_seen' => DB::raw('times_seen + 1'), 'last_seen' => now()]);
        else DB::table('sys_error_log')->insert(RecordService::fillNotNull('sys_error_log', ['level' => 'ERROR', 'message' => $msg, 'file' => mb_substr($e->getFile(), -250), 'line' => $e->getLine(), 'fk_id_ent' => $ent, 'fingerprint' => $fp, 'times_seen' => 1, 'f_alerted' => 1, 'created_at' => now(), 'last_seen' => now()]));
        TaskEngine::open('SYS_ERROR', ['cycle_overview', $part], "A part of the cycle overview could not load ($part)", ['ent' => $ent, 'description' => $msg]);      // one admin alert per broken part (dedupe)
    }

    private static function wa(?string $phone): ?string { $d = preg_replace('/\D/', '', (string) $phone); if (str_starts_with((string) $phone, '00')) $d = substr($d, 2); return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null; }

    /** Every party of the cycle with role, phone and a WhatsApp link (supplier, forwarder, inspector, investor, cycle owner). */
    public static function contacts(int $ent, int $cycleId): array
    {
        $po = array_keys(self::poIds([$cycleId])); $p = []; $put = function (string $role, string $kind, ?int $id, ?string $name, ?string $phone, ?string $wa = null) use (&$p) { if (! $name) return; $k = $kind . ':' . ($id ?: $name); if (isset($p[$k])) { $p[$k]['roles'][] = $role; $p[$k]['roles'] = array_values(array_unique($p[$k]['roles'])); return; }
            $p[$k] = ['key' => $k, 'name' => $name, 'roles' => [$role], 'phone' => $phone, 'whatsapp' => self::wa($wa ?? $phone), 'initials' => mb_strtoupper(mb_substr($name, 0, 1) . (($i = mb_strrpos($name, ' ')) !== false ? mb_substr($name, $i + 1, 1) : ''))]; };
        $sup = DB::table('t_src_suppliers')->whereIn('id', array_merge(DB::table('t_src_po')->whereIn('id', $po ?: [0])->pluck('fk_id_supplier')->all(), DB::table('t_src_rfq')->where('fk_id_cycle', $cycleId)->where('status', 'ACCEPTED')->pluck('fk_id_supplier')->all(), DB::table('t_src_shipments')->whereIn('fk_id_po', $po ?: [0])->pluck('fk_id_supplier')->all()) ?: [0])->get();
        foreach ($sup as $s) $put(stripos((string) $s->supplier_type, 'agent') !== false ? 'Agent' : 'Supplier', 'S', (int) $s->id, $s->supplier_name, $s->phone_whatsapp);      // a sourcing agent is a supplier of type Agent
        foreach (DB::table('t_src_forwarders')->whereIn('id', array_merge(DB::table('t_src_freight')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->pluck('fk_id_forwarder')->all(), DB::table('t_src_shipments')->whereIn('fk_id_po', $po ?: [0])->pluck('fk_id_forwarder')->all()) ?: [0])->get() as $f) $put('Forwarder', 'F', (int) $f->id, $f->forwarder_name, $f->phone);
        foreach (DB::table('t_src_qc')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->whereNotNull('inspector')->where('inspector', '<>', '')->distinct()->pluck('inspector') as $i) $put('Inspector', 'I', null, $i, null);
        foreach (DB::table('t_src_investors as i')->join('t_src_investments as v', 'v.fk_id_investor', '=', 'i.id')->where('v.fk_id_cycle', $cycleId)->where('v.f_active', 1)->distinct()->get(['i.id', 'i.investor_name', 'i.phone']) as $i) $put('Investor', 'V', (int) $i->id, $i->investor_name, $i->phone);
        $c = DB::table('t_src_cycles')->where('id', $cycleId)->first(['fk_id_owner', 'created_by']);
        if ($c && $c->fk_id_owner && ($a = DB::table('t_account')->where('id', $c->fk_id_owner)->first(['id', 'first_name', 'sur_name', 'whatsapp_number']))) $put('Cycle owner', 'A', (int) $a->id, trim($a->first_name . ' ' . $a->sur_name), $a->whatsapp_number, $a->whatsapp_number);      // the WhatsApp number chosen from the person's own mobiles (R-C-48)
        elseif ($c && $c->created_by && ($u = DB::table('t_users')->where('id', $c->created_by)->first(['id', 'display_name']))) $put('Cycle owner', 'U', (int) $u->id, $u->display_name, null);
        return array_values($p);
    }

    /** The WhatsApp button: the chat link, and the message logged on the cycle. */
    public static function whatsapp(int $ent, int $cycleId, string $key, string $text): array
    {
        $party = collect(self::contacts($ent, $cycleId))->firstWhere('key', $key) ?? throw new \InvalidArgumentException('This person is not on the cycle.');
        if (! $party['whatsapp']) throw new \InvalidArgumentException($party['name'] . ' has no WhatsApp number. Add the phone (or choose the WhatsApp number from the person\'s mobiles) first.');
        Audit::log('t_src_cycles', $cycleId, 'UPDATE', ['whatsapp to' => $party['name'] . ' (' . implode(', ', $party['roles']) . ')', 'message' => mb_substr($text, 0, 500)], null, $ent);
        return ['url' => 'https://wa.me/' . $party['whatsapp'] . ($text !== '' ? '?text=' . rawurlencode($text) : ''), 'message' => 'Logged on the cycle.'];
    }

    /** GRN claim: settled, or "no claim" with a reason – closes SRC_GRN_SHORT. */
    public static function claim(int $ent, int $grnId, string $status, string $reason): array
    {
        $g = DB::table('t_src_grn')->where('id', $grnId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('GRN not found.');
        if (! in_array($status, ['SETTLED', 'NO_CLAIM', 'OPEN'], true)) throw new \InvalidArgumentException('Choose Settled, No claim or Open.');
        if ($status === 'NO_CLAIM' && trim($reason) === '') throw new \InvalidArgumentException('Give the reason for "no claim".');
        DB::table('t_src_grn')->where('id', $grnId)->update(['claim_status' => $status, 'claim_reason' => mb_substr(trim($reason), 0, 255) ?: null, 'claim_date' => $status === 'OPEN' ? null : date('Y-m-d'), 'updated_at' => now(), 'updated_by' => Context::userId()]);
        Audit::log('t_src_grn', $grnId, 'UPDATE', ['claim' => $status, 'reason' => $reason], $reason ?: null, $ent);
        if ($status !== 'OPEN') TaskEngine::close('SRC_GRN_SHORT', self::key('SRC_GRN_SHORT', $grnId), $status === 'SETTLED' ? 'Claim settled' : 'No claim: ' . $reason);
        return ['message' => $status === 'SETTLED' ? 'Claim settled.' : ($status === 'NO_CLAIM' ? 'Marked "no claim".' : 'Claim open again.')];
    }
}
