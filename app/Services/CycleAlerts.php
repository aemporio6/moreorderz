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
    /** R-B_SOURCING-1 the four cycle types and the steps each one skips from the start (a record added to a skipped step un-skips it, R-17). */
    public const TYPE_SKIPS = ['FULL' => '', 'DIRECT' => 'rfq,samples,production,qc', 'QUICK' => 'rfq,samples,production,qc,shipment,freight,customs,landed_cost', 'HISTORICAL' => 'rfq,samples,production,qc'];

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
    /** Live 26-11 'slow': the counts of many cycles read in one GROUP BY per table (the dashboard had ~14 queries per cycle). */
    private static array $pre = [];

    public static function preload(array $ids): void
    {
        self::$pre = []; if (! $ids) return;
        $g = fn (string $t, string $col, array $in) => $in ? DB::table($t)->where('f_active', 1)->whereIn($col, $in)->groupBy($col)->selectRaw("$col k, COUNT(*) n")->pluck('n', 'k')->map(fn ($x) => (int) $x)->all() : [];
        $poOf = []; foreach (self::poIds($ids) as $po => $cy) $poOf[(int) $cy][] = (int) $po;
        $byCy = []; foreach (['t_src_rfq', 't_src_samples', 't_src_payments', 't_src_production', 't_src_qc', 't_src_allocations', 't_src_freight', 't_src_customs', 't_src_grn', 't_src_inventory_receipts'] as $t) $byCy[$t] = $g($t, 'fk_id_cycle', $ids);
        $allPo = array_merge([], ...array_values($poOf)); $byPo = ['t_src_payment_terms' => $g('t_src_payment_terms', 'fk_id_po', $allPo), 't_src_landed_cost' => $g('t_src_landed_cost', 'fk_id_po', $allPo)];
        $ship = DB::table('t_src_shipments')->where('f_active', 1)->where(fn ($q) => $q->whereIn('fk_id_cycle', $ids)->orWhereIn('fk_id_po', $allPo ?: [0]))->get(['id', 'fk_id_cycle', 'fk_id_po']);
        $poCy = []; foreach ($poOf as $cy => $ps) foreach ($ps as $p) $poCy[$p] = $cy;
        StepColours::preload($ids, $allPo);
        foreach ($ids as $id) {
            $ps = $poOf[$id] ?? []; $sum = fn (string $t) => array_sum(array_map(fn ($p) => $byPo[$t][$p] ?? 0, $ps));
            self::$pre[$id] = ['po' => $ps, 'rfq' => $byCy['t_src_rfq'][$id] ?? 0, 'samples' => $byCy['t_src_samples'][$id] ?? 0, 'pay' => $sum('t_src_payment_terms') + ($byCy['t_src_payments'][$id] ?? 0),
                'production' => $byCy['t_src_production'][$id] ?? 0, 'qc' => $byCy['t_src_qc'][$id] ?? 0, 'allocation' => $byCy['t_src_allocations'][$id] ?? 0,
                'shipment' => $ship->filter(fn ($x) => (int) $x->fk_id_cycle === (int) $id || isset($poCy[(int) $x->fk_id_po]) && $poCy[(int) $x->fk_id_po] === (int) $id)->count(),
                'freight' => $byCy['t_src_freight'][$id] ?? 0, 'customs' => $byCy['t_src_customs'][$id] ?? 0, 'grn' => $byCy['t_src_grn'][$id] ?? 0,
                'landed_cost' => ($byCy['t_src_inventory_receipts'][$id] ?? 0) + $sum('t_src_landed_cost')];
        }
    }

    public static function pipeline(object $c): array
    {
        $id = $c->id; $pre = self::$pre[(int) $id] ?? null; $po = $pre ? $pre['po'] : array_keys(self::poIds([$id])); $n = fn (string $t, string $col = 'fk_id_cycle', ?array $in = null) => (int) DB::table($t)->where('f_active', 1)->when($in === null, fn ($q) => $q->where($col, $id), fn ($q) => $q->whereIn($col, $in ?: [0]))->count();
        if ($pre) { $cnt = $pre; $cnt['po'] = count($pre['po']); } else
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
        foreach ($steps as &$st) { $st['colour'] = $col[$st['key']] ?? 'pend'; $st['colour_label'] = config('workflows.labels.' . $st['colour']); $st['done'] = $st['count'] > 0 || in_array($st['key'], $skip, true); $st['strip'] = self::STRIP[$st['state']]; if ($st['key'] === 'pay' && ! $st['count'] && $po && ! in_array('pay', $skip, true)) $st['hint'] = 'Add the payment schedule'; } unset($st);      /* R-B_SOURCING-3 */
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
    public static function dashboard(int $ent, int $page = 1, int $per = 60, array $f = []): array
    {
        // R-F_SETUP-18: open = active and status not Completed / Closed / Cancelled (empty = open); filters run first, then paging (60 per page)
        $all = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($w) => $w->whereNull('status')->orWhereRaw("UPPER(status) NOT IN ('CLOSED','CANCELLED','COMPLETED')"))->orderByDesc('id')
            ->get(['id', 'cycle_code', 'cycle_type', 'skipped_steps', 'created_by', 'status', 'sourcing_date', 'created_at', 'product_name']);
        $sup = (int) ($f['supplier'] ?? 0); $q = mb_strtolower(trim((string) ($f['q'] ?? ''))); $stage = (string) ($f['stage'] ?? '');
        $supOf = fn (int $cy) => DB::table('t_src_cycle_products')->where('fk_id_cycle', $cy)->where('f_active', 1)->whereNotNull('fk_id_supplier')->pluck('fk_id_supplier')
            ->merge(DB::table('t_src_po')->where('fk_id_cycle', $cy)->where('f_active', 1)->whereNotNull('fk_id_supplier')->pluck('fk_id_supplier'))->map(fn ($x) => (int) $x)->unique()->all();
        $hit = function (object $c) use ($q) {
            if ($q === '') return true;
            if (str_contains(mb_strtolower((string) $c->cycle_code), $q)) return true;
            if (DB::table('t_src_cycles')->where('id', $c->id)->whereRaw('LOWER(COALESCE(order_ref, \'\')) LIKE ?', ["%$q%"])->exists()) return true;      /* R-B_SOURCING-50 supplier order reference */
            if (DB::table('t_src_payments')->where('fk_id_cycle', $c->id)->where('f_active', 1)->whereRaw('LOWER(COALESCE(txn_ref, \'\')) LIKE ?', ["%$q%"])->exists()) return true;      /* bank / Wise reference of a payment */
            if (DB::table('t_src_po')->where('fk_id_cycle', $c->id)->where('f_active', 1)->where(fn ($w) => $w->whereRaw('LOWER(po_number) LIKE ?', ["%$q%"])->orWhereRaw('LOWER(po_code) LIKE ?', ["%$q%"]))->exists()) return true;
            if (DB::table('t_src_cycle_products')->where('fk_id_cycle', $c->id)->where('f_active', 1)->where(fn ($w) => $w->whereRaw('LOWER(product_name) LIKE ?', ["%$q%"])->orWhereRaw('LOWER(sku) LIKE ?', ["%$q%"]))->exists()) return true;
            return DB::table('t_src_po_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_cycle', $c->id)->where('l.f_active', 1)->where(fn ($w) => $w->whereRaw('LOWER(p.product_name_vendor) LIKE ?', ["%$q%"])->orWhereRaw('LOWER(p.SKU_VENDOR) LIKE ?', ["%$q%"]))->exists();
        };
        $supCount = []; $pls = []; $tiles = array_fill_keys(array_keys(self::GROUPS), 0); $keep = []; self::preload($all->pluck('id')->map(fn ($x) => (int) $x)->all());
        foreach ($all as $c) {
            $sups = $supOf((int) $c->id); $okQ = $hit($c);
            if ($okQ) foreach ($sups as $s) $supCount[$s] = ($supCount[$s] ?? 0) + 1;
            if (! $okQ || ($sup && ! in_array($sup, $sups, true))) continue;
            if (! empty($f['investor']) && ! DB::table('t_src_investments')->where('fk_id_cycle', $c->id)->where('f_active', 1)->where('fk_id_investor', (int) $f['investor'])->exists()) continue;      /* R-B_SOURCING-50 deep filters */
            if (! empty($f['from']) && (string) $c->sourcing_date < $f['from']) continue;
            if (! empty($f['to']) && (string) $c->sourcing_date > $f['to'] . ' 23:59:59') continue;
            $pl = self::pipeline($c); $pls[$c->id] = $pl; if ($pl['current']) $tiles[$pl['current']]++;
            if ($stage !== '' && $pl['current'] !== $stage) continue;
            $keep[] = $c;
        }
        $rows = []; $page = max(1, $page); $from = ($page - 1) * $per; $today = new \DateTimeImmutable('today');
        foreach ($keep as $i => $c) {
            $pl = $pls[$c->id];
            if ($i < $from || $i >= $from + $per) continue;
            $po = DB::table('t_src_po')->where('fk_id_cycle', $c->id)->where('f_active', 1)->orderBy('id')->get(['po_number', 'po_code']);
            $prods = DB::table('t_src_cycle_products as cp')->leftJoin('t_src_suppliers as s', 's.id', '=', 'cp.fk_id_supplier')->where('cp.fk_id_cycle', $c->id)->where('cp.f_active', 1)->orderBy('cp.id')->get(['cp.product_name', 'cp.target_qty', 's.supplier_name']);
            $needs = ($allNeeds ??= collect(self::detect($ent))->groupBy('cycle'))->get((int) $c->id, collect())->values()->all(); usort($needs, fn ($a, $b) => [$a['p'], $a['due'] ?? '9999'] <=> [$b['p'], $b['due'] ?? '9999']);
            $days = $c->sourcing_date ? max(0, (int) floor(($today->getTimestamp() - strtotime((string) $c->sourcing_date)) / 86400)) : 0;
            $rows[] = ['id' => (int) $c->id, 'code' => $c->cycle_code, 'cell' => $c->cycle_code . ' · entered ' . ($c->created_at ? date('d M', strtotime((string) $c->created_at)) : '—') . ' · ' . ($c->status ?: '—'),
                'steps' => array_map(fn ($st) => array_intersect_key($st, array_flip(['key', 'name', 'state', 'strip', 'count', 'done', 'hint'])), $pl['steps']), 'current' => $pl['current'], 'current_name' => $pl['current_name'], 'missing_text' => $pl['missing_text'], 'progress_pct' => $pl['progress_pct'],
                'suppliers' => $prods->pluck('supplier_name')->filter()->unique()->values()->all(), 'products' => $prods->map(fn ($p) => trim($p->product_name . ' ' . number_format((float) $p->target_qty, 0, '.', '')))->all(),
                'pos' => $po->map(fn ($p) => $p->po_number ?: $p->po_code)->filter()->values()->all(), 'next_action' => $needs[0]['title'] ?? '—', 'days_open' => $days];
        }
        $supNames = $supCount ? DB::table('t_src_suppliers')->whereIn('id', array_keys($supCount))->orderBy('supplier_name')->pluck('supplier_name', 'id') : collect();
        self::$pre = []; StepColours::forget();      // the preloaded counts are only for this page
        return ['tiles' => array_map(fn ($k) => ['key' => $k, 'name' => self::GROUPS[$k], 'open' => $tiles[$k]], array_keys(self::GROUPS)), 'rows' => $rows, 'total' => count($keep), 'page' => $page, 'per_page' => $per,
            'filters' => ['supplier' => $sup ?: null, 'q' => $f['q'] ?? '', 'stage' => $stage ?: null, 'investor' => $f['investor'] ?? null, 'from' => $f['from'] ?? null, 'to' => $f['to'] ?? null, 'stage_name' => self::GROUPS[$stage] ?? null], 'empty' => 'No open cycles match the filter.',
            'suppliers' => $supNames->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n, 'count' => $supCount[$id]])->values()->all(),
            'legend' => [['Done', self::STRIP['done']], ['Current', self::STRIP['current']], ['Pending', self::STRIP['pending']], ['Record missing', self::STRIP['missing']], ['Skipped', 'hatched']]];
    }

    /** Quantity check per cycle product (R-39, v1.7: read from the GRN lines). */
    public static function quantities(int $cycleId): array
    {
        $out = [];
        /* R-39 (new) trail: produced / QC passed per PO (a PO with one product gives them to that product), in stock = lots of the cycle still on hand */
        $poProd = DB::table('t_src_po_lines')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhereIn('line_type', ['', 'PRODUCT']))->get(['fk_id_po', 'fk_id_product'])->groupBy('fk_id_po')
            ->map(fn ($g) => $g->pluck('fk_id_product')->unique()->count() === 1 ? (int) $g->first()->fk_id_product : null);
        $prodBy = []; $qcBy = []; $cyProd = 0.0; $cyQc = 0.0;
        foreach (DB::table('t_src_production')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->get(['fk_id_po', 'produced_qty']) as $r) { $cyProd += (float) $r->produced_qty; if ($pid = $poProd[$r->fk_id_po] ?? (count($poProd) === 1 ? $poProd->first() : null)) $prodBy[$pid] = ($prodBy[$pid] ?? 0) + (float) $r->produced_qty; }
        foreach (DB::table('t_src_qc')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->whereNull('re_inspection_of')->get(['fk_id_po', 'qty_passed']) as $r) { $cyQc += (float) $r->qty_passed; if ($pid = $poProd[$r->fk_id_po] ?? (count($poProd) === 1 ? $poProd->first() : null)) $qcBy[$pid] = ($qcBy[$pid] ?? 0) + (float) $r->qty_passed; }
        $stock = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.fk_id_cycle', $cycleId)
            ->where(fn ($w) => $w->whereNull('c.kind')->orWhereNotIn('c.kind', ['DAMAGED', 'SHIPPED']))->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(b.qty) q')->pluck('q', 'p');
        $poLines = DB::table('t_src_po_lines')->where('f_active', 1)->where('fk_id_cycle', $cycleId)->get(['fk_id_cycle_product', 'fk_id_product', 'qty_ordered']);
        $allocs = DB::table('t_src_allocations')->where('f_active', 1)->where('fk_id_cycle', $cycleId)->get(['fk_id_cycle_product', 'allocated_qty', 'confirmed_qty', 'dispatched_qty'])->groupBy(fn ($a) => (int) $a->fk_id_cycle_product);
        $grnLines = DB::table('t_src_grn_lines as l')->join('t_src_grn as g', 'g.id', '=', 'l.fk_id_grn')->where('g.fk_id_cycle', $cycleId)->where('g.f_active', 1)->get(['l.fk_id_product', 'l.expected_qty', 'l.received_qty', 'l.accepted_qty', 'l.damaged_qty', 'l.missing_qty'])->groupBy(fn ($x) => (int) $x->fk_id_product);
        foreach (DB::table('t_src_cycle_products as cp')->leftJoin('t_product_vendors as p', 'p.id', '=', 'cp.fk_id_product')->where('cp.fk_id_cycle', $cycleId)->get(['cp.id', 'cp.fk_id_product', 'p.SKU_VENDOR as sku', 'cp.product_name']) as $cp) {
            /* live 26-11 'slow': the cycle's PO lines, allocations and GRN lines read once (above), summed here per product – was 3 queries per product */
            $ord = (float) $poLines->filter(fn ($l) => (int) $l->fk_id_cycle_product === (int) $cp->id || ($l->fk_id_cycle_product === null && (int) $l->fk_id_product === (int) $cp->fk_id_product))->sum(fn ($l) => (float) $l->qty_ordered);
            $al = (object) ['planned' => 0.0, 'confirmed' => 0.0, 'dispatched' => 0.0]; foreach ($allocs->get((int) $cp->id, collect()) as $a) { $al->planned += (float) $a->allocated_qty; $al->confirmed += (float) $a->confirmed_qty; $al->dispatched += (float) $a->dispatched_qty; }
            $g = (object) ['expected' => 0.0, 'received' => 0.0, 'accepted' => 0.0, 'damaged' => 0.0, 'missing' => 0.0]; foreach ($grnLines->get((int) $cp->fk_id_product, collect()) as $x) foreach (['expected', 'received', 'accepted', 'damaged', 'missing'] as $k) $g->$k += (float) $x->{$k . '_qty'};
            $planned = (float) $al->planned; $ok = fn ($a, $b) => abs($a - $b) < 0.001;
            if ($ord > 0 && ! $ok($planned, $ord)) $chip = $planned < $ord ? ['amber', ($ord - $planned) . ' unallocated'] : ['amber', ($planned - $ord) . ' OVER'];
            elseif ((float) $g->received > 0 || (float) $g->expected > 0) $chip = $ok((float) $g->expected, (float) $g->received + (float) $g->missing) && $ok((float) $g->received, (float) $g->accepted + (float) $g->damaged) ? ['teal', 'Balanced'] : ['red', 'Mismatch'];
            elseif ((float) $al->dispatched > 0) $chip = ['grey', 'In transit'];
            elseif ($ord > 0) $chip = ['teal', 'OK'];
            else $chip = ['grey', '—'];
            $out[] = ['cycle_product' => (int) $cp->id, 'product' => (int) $cp->fk_id_product, 'sku' => $cp->sku, 'name' => $cp->product_name, 'ordered' => $ord, 'planned' => $planned, 'confirmed' => (float) $al->confirmed, 'dispatched' => (float) $al->dispatched,
                'expected' => (float) $g->expected, 'received' => (float) $g->received, 'accepted' => (float) $g->accepted, 'damaged' => (float) $g->damaged, 'missing' => (float) $g->missing, 'chip' => $chip[1], 'tone' => $chip[0],
                'claim' => $chip[1] === 'Balanced' && (float) $g->missing > 0 ? (float) $g->missing : 0.0,      /* "Balanced · claim m" – opens the claim of the GRN */
                'claim_grn' => (float) $g->missing > 0 ? (int) DB::table('t_src_grn_lines as l')->join('t_src_grn as g', 'g.id', '=', 'l.fk_id_grn')->where('g.fk_id_cycle', $cycleId)->where('l.fk_id_product', $cp->fk_id_product)->where('l.missing_qty', '>', 0)->orderByDesc('g.id')->value('g.id') : null,
                'produced' => $prodBy[(int) $cp->fk_id_product] ?? null, 'qc_passed' => $qcBy[(int) $cp->fk_id_product] ?? null, 'in_stock' => (float) ($stock[(int) $cp->fk_id_product] ?? 0)];
        }
        return $out;
    }

    /** R-F_SETUP-24 what blocks Completed / Closed: Mismatch always; unallocated / OVER only on a cycle that plans with allocations (Old entry and Direct PO cycles have none). */
    public static function unbalanced(int $cycleId): array
    {
        $alloc = DB::table('t_src_allocations')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->exists(); $out = [];
        $ok = fn ($a, $b) => abs($a - $b) < 0.001;
        foreach (self::quantities($cycleId) as $q) {
            $grnBad = ($q['received'] > 0 || $q['expected'] > 0) && ! ($ok($q['expected'], $q['received'] + $q['missing']) && $ok($q['received'], $q['accepted'] + $q['damaged']));
            if ($q['chip'] === 'Mismatch' || (! $alloc && $grnBad)) $out[] = ($q['sku'] ?: $q['name']) . ': Mismatch';      /* without allocations the GRN quantities are still checked */
            elseif ($alloc && (str_ends_with($q['chip'], 'unallocated') || str_ends_with($q['chip'], 'OVER'))) $out[] = ($q['sku'] ?: $q['name']) . ': ' . $q['chip'];
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
            ->where(fn ($q) => $q->whereNull('t.due_date')->orWhere('t.due_date', '<=', now()->addDays($days)->toDateString()))->when($cycleId, fn ($q) => $q->where('p.fk_id_cycle', $cycleId))->get(['t.id', 't.term_no', 't.milestone', 't.planned_amount', 't.remaining_amount', 't.due_date', 'p.id as po', 'p.po_number', 'p.fk_id_cycle', 'p.exchange_rate', 'p.created_by', 'p.fk_id_currency']) as $t)
            $add('SRC_PAY_DUE', [$t->po_number, 'T' . $t->term_no], (int) $t->fk_id_cycle, 'P1', 'Pay Term ' . (int) $t->term_no . " · {$t->milestone}", 'Remaining ' . number_format((float) $t->remaining_amount, 2) . ' of ' . number_format((float) $t->planned_amount, 2) . ' ' . Fx::code($t->fk_id_currency, $ent) . " ({$t->po_number})", 'Payment Schedule', $t->due_date,
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
        foreach (DB::table('t_src_grn')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('fk_id_cycle', $ids ?: [0])->where(fn ($q) => $q->where('missing_units', '>', 0)->orWhere('damaged_units', '>', 0))->where(fn ($q) => $q->whereNull('claim_status')->orWhereNotIn('claim_status', ['SETTLED', 'NO_CLAIM', 'REJECTED']))->get() as $g)
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
        $safe = function (string $part, callable $fn) use ($ent) { try { return $fn(); } catch (\Throwable $e) { report($e); self::healthAlert($ent, $part, $e); return ['error' => DashCards::MSG] + (($line = DashCards::adminLine($part, $e)) ? ['admin' => $line] : []); } };
        $pipe = $safe('pipeline', fn () => self::pipeline($c));
        $needs = $safe('needs', function () use ($ent, $cycleId) {
            $rows = array_map(fn ($r) => ['code' => $r['code'], 'p' => $r['p'], 'title' => $r['title'], 'detail' => $r['detail'], 'chip' => $r['chip'], 'due' => $r['due'], 'fix' => $r['opt']['fix_url'] ?? null], self::detect($ent, $cycleId));
            usort($rows, fn ($a, $b) => [$a['p'], $a['due'] ?? '9999'] <=> [$b['p'], $b['due'] ?? '9999']); return $rows; });
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code, 'type' => $c->cycle_type], 'pipeline' => $pipe, 'needs' => $needs, 'next_action' => is_array($needs) && ! isset($needs['error']) && $needs ? $needs[0]['title'] : '—',
            'quantities' => $safe('quantities', fn () => self::quantities($cycleId)), 'contacts' => $safe('contacts', fn () => self::contacts($ent, $cycleId)),
            'lots' => $safe('lots', fn () => DashCards::lots($ent, $u ? Perm::has($u, 'buying.MONEY') : false, $cycleId))];      /* R-B_SOURCING-40 this cycle's lots, chip → landed-cost run */
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
        $local = function (?string $tz): ?string { try { return $tz ? (new \DateTime('now', new \DateTimeZone($tz)))->format('H:i') : null; } catch (\Throwable $e) { return null; } };      /* R-B_SOURCING-66 the supplier's local time next to the message buttons */
        $sup = DB::table('t_src_suppliers')->whereIn('id', array_merge(DB::table('t_src_po')->whereIn('id', $po ?: [0])->pluck('fk_id_supplier')->all(), DB::table('t_src_rfq')->where('fk_id_cycle', $cycleId)->where('status', 'ACCEPTED')->pluck('fk_id_supplier')->all(), DB::table('t_src_shipments')->whereIn('fk_id_po', $po ?: [0])->pluck('fk_id_supplier')->all()) ?: [0])->get();
        foreach ($sup as $s) $put(stripos((string) $s->supplier_type, 'agent') !== false ? 'Agent' : 'Supplier', 'S', (int) $s->id, $s->supplier_name, $s->phone_whatsapp);
        foreach ($sup as $s) if (isset($p['S:' . $s->id]) && ($lt = $local($s->timezone ?? null))) $p['S:' . $s->id]['local_time'] = $lt;      // a sourcing agent is a supplier of type Agent
        foreach (DB::table('t_src_forwarders')->whereIn('id', array_merge(DB::table('t_src_freight')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->pluck('fk_id_forwarder')->all(), DB::table('t_src_shipments')->whereIn('fk_id_po', $po ?: [0])->pluck('fk_id_forwarder')->all()) ?: [0])->get() as $f) $put('Forwarder', 'F', (int) $f->id, $f->forwarder_name, $f->phone);
        foreach (DB::table('t_src_qc')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->whereNotNull('inspector')->where('inspector', '<>', '')->distinct()->pluck('inspector') as $i) $put('Inspector', 'I', null, $i, null);
        foreach (DB::table('t_src_investors as i')->join('t_src_investments as v', 'v.fk_id_investor', '=', 'i.id')->where('v.fk_id_cycle', $cycleId)->where('v.f_active', 1)->distinct()->get(['i.id', 'i.investor_name', 'i.phone']) as $i) $put('Investor', 'V', (int) $i->id, $i->investor_name, $i->phone);
        $c = DB::table('t_src_cycles')->where('id', $cycleId)->first(['fk_id_owner', 'created_by']);
        if ($c && $c->fk_id_owner && ($a = DB::table('t_account')->where('id', $c->fk_id_owner)->first(['id', 'first_name', 'sur_name', 'whatsapp_number']))) $put('Cycle owner', 'A', (int) $a->id, trim($a->first_name . ' ' . $a->sur_name), $a->whatsapp_number, $a->whatsapp_number);      // the WhatsApp number chosen from the person's own mobiles (R-C-48)
        elseif ($c && $c->created_by && ($u = DB::table('t_users')->where('id', $c->created_by)->first(['id', 'display_name']))) $put('Cycle owner', 'U', (int) $u->id, $u->display_name, null);
        foreach ($p as $k => $row) $p[$k]['tasks'] = self::partyTasks($ent, $cycleId, $k, $po);      /* R-B_SOURCING-28 their open tasks on this cycle */
        return array_values($p);
    }

    /** Open tasks on this cycle that belong to one party: the tasks linked to its POs / terms / quotes / shipments / freight / investments here; for the cycle owner the tasks he holds on the cycle. */
    public static function partyTasks(int $ent, int $cycleId, string $key, array $po): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, ''); $id = (int) $id; $links = [];
        if ($kind === 'S') { $mine = DB::table('t_src_po')->whereIn('id', $po ?: [0])->where('fk_id_supplier', $id)->pluck('id')->all();
            $links = ['t_src_po' => $mine, 't_src_payment_terms' => DB::table('t_src_payment_terms')->whereIn('fk_id_po', $mine ?: [0])->pluck('id')->all(), 't_src_shipments' => DB::table('t_src_shipments')->whereIn('fk_id_po', $mine ?: [0])->where(fn ($w) => $w->whereNull('fk_id_forwarder')->orWhere('fk_id_forwarder', 0))->pluck('id')->all(),      /* a shipment with a forwarder is the forwarder's */
                't_src_rfq' => DB::table('t_src_rfq')->where('fk_id_cycle', $cycleId)->where('fk_id_supplier', $id)->pluck('id')->all()]; }
        elseif ($kind === 'F') $links = ['t_src_shipments' => DB::table('t_src_shipments')->whereIn('fk_id_po', $po ?: [0])->where('fk_id_forwarder', $id)->pluck('id')->all(), 't_src_freight' => DB::table('t_src_freight')->where('fk_id_cycle', $cycleId)->where('fk_id_forwarder', $id)->pluck('id')->all()];
        elseif ($kind === 'V') $links = ['t_src_investments' => DB::table('t_src_investments')->where('fk_id_cycle', $cycleId)->where('fk_id_investor', $id)->pluck('id')->all()];
        $q = DB::table('tasks')->where('fk_id_ent', $ent)->whereIn('status', ['TODO', 'DOING', 'STUCK', 'REWORK']);
        if ($kind === 'A' || $kind === 'U') { $users = $kind === 'U' ? [$id] : DB::table('t_users')->where('fk_id_account', $id)->pluck('id')->all(); if (! $users) return [];
            $q->whereIn('owner_user_id', $users)->where(fn ($w) => $w->where(fn ($x) => $x->where('linked_module', 't_src_cycles')->where('linked_record_id', $cycleId))->orWhere(fn ($x) => $x->where('linked_module', 't_src_po')->whereIn('linked_record_id', $po ?: [0]))); }
        else { $links = array_filter($links); if (! $links) return []; $q->where(function ($w) use ($links) { foreach ($links as $m => $ids) $w->orWhere(fn ($x) => $x->where('linked_module', $m)->whereIn('linked_record_id', $ids)); }); }
        return $q->orderBy('due_at')->limit(10)->get(['id', 'title', 'priority', 'due_at'])->map(fn ($t) => ['id' => (int) $t->id, 'title' => $t->title, 'priority' => $t->priority, 'due' => $t->due_at ? substr((string) $t->due_at, 0, 10) : null])->all();
    }

    /** The WhatsApp button: the chat link, and the message logged on the cycle. */
    public static function whatsapp(int $ent, int $cycleId, string $key, string $text): array
    {
        $party = collect(self::contacts($ent, $cycleId))->firstWhere('key', $key) ?? throw new \InvalidArgumentException('This person is not on the cycle.');
        if (! $party['whatsapp']) throw new \InvalidArgumentException($party['name'] . ' has no WhatsApp number. Add the phone (or choose the WhatsApp number from the person\'s mobiles) first.');
        Audit::log('t_src_cycles', $cycleId, 'UPDATE', ['whatsapp to' => $party['name'] . ' (' . implode(', ', $party['roles']) . ')', 'message' => mb_substr($text, 0, 500)], null, $ent);
        return ['url' => 'https://wa.me/' . $party['whatsapp'] . ($text !== '' ? '?text=' . rawurlencode($text) : ''), 'message' => 'Logged on the cycle.'];
    }

    /** GRN claim status (R-B_SOURCING-56 – the full claim section is SupplierClaims). */
    public static function claim(int $ent, int $grnId, string $status, string $reason, array $d = []): array
    {
        return SupplierClaims::status($ent, $grnId, $status, $d + ['reason' => $reason]);
    }
}
