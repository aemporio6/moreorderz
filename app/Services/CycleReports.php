<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Per-cycle sourcing reports on the one stage engine (CycleAlerts::pipeline, R-F_SETUP-19):
 *  - Cycle Journey (R-F_SETUP-2): header code · current stage · progress %; seven blocks in timeline order (Products, RFQs, Purchase Orders, Payments,
 *    Destination Allocations, Shipments, Landings / GRN), active rows only, oldest first, a first "Date" column, a link per row, an empty block says
 *    "No <block> yet" or "Skipped" when its step is in skipped_steps. PDF CycleJourney_<code>_<yyyymmdd>.pdf.
 *  - Cycle Summary (R-F_SETUP-12): meta (status, priority, stage, progress, products, suppliers of POs else RFQs else "-"), Money | Quantities grid
 *    in the base currency (FX of each PO / payment / freight), footnote with the import VAT. PDF CycleSummary_<code>_<yyyymmdd>.pdf (A4, cycle_summary_a4).
 */
class CycleReports
{
    private static function cycle(int $ent, int $id): object
    {
        return DB::table('t_src_cycles')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Cycle not found.');
    }

    private static function d($v): ?string { return $v ? date('d-m-Y', strtotime((string) $v)) : null; }
    private static function loc($id): ?string { return $id ? (DB::table('cat_inventory_locations')->where('id', $id)->value('location_name') ?? ('#' . $id)) : null; }

    /** R-F_SETUP-2 */
    public const MONEY_COLS = ['Target unit cost', 'Final unit price', 'Total', 'Deposit', 'Balance', 'Amount', 'Base amount'];

    public static function journey(int $ent, int $id, bool $money = true): array
    {
        $c = self::cycle($ent, $id); $pl = CycleAlerts::pipeline($c);
        $skip = array_filter(array_map('trim', explode(',', (string) $c->skipped_steps)));
        $po = DB::table('t_src_po')->where('fk_id_cycle', $id)->where('f_active', 1)->pluck('id')->all();
        $sup = fn ($sid) => $sid ? DB::table('t_src_suppliers')->where('id', $sid)->value('supplier_name') : null;
        $cur = fn ($cid) => $cid ? DB::table('cat_currency')->where('id', $cid)->value('currency_code') : null;
        $link = "#/cycle/$id";
        $blocks = [
            ['key' => 'products', 'step' => null, 'title' => 'Products', 'empty' => 'No products yet', 'cols' => ['Date', 'Brand', 'Product', 'SKU', 'Target qty', 'Target unit cost'],
                'rows' => DB::table('t_src_cycle_products')->where('fk_id_cycle', $id)->where('f_active', 1)->orderBy('created_at')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->created_at), $r->fk_id_brand ? (DB::table('cat_brands')->where('id', $r->fk_id_brand)->value('brand_name') ?? (string) $r->fk_id_brand) : null, $r->product_name, $r->sku, (float) $r->target_qty, $r->target_unit_cost !== null ? (float) $r->target_unit_cost : null]])->all()],
            ['key' => 'rfq', 'step' => 'rfq', 'title' => 'RFQs', 'empty' => 'No RFQs yet', 'cols' => ['Date', 'RFQ code', 'Supplier', 'Qty', 'Final unit price', 'Status'],
                'rows' => DB::table('t_src_rfq')->where('fk_id_cycle', $id)->where('f_active', 1)->orderByRaw('COALESCE(rfq_date, created_at)')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->rfq_date ?? $r->created_at), $r->rfq_code, $sup($r->fk_id_supplier), (float) $r->qty, $r->final_unit_price !== null ? (float) $r->final_unit_price : null, $r->status]])->all()],
            ['key' => 'po', 'step' => 'po', 'title' => 'Purchase Orders', 'empty' => 'No purchase orders yet', 'cols' => ['Date', 'PO code', 'Supplier', 'Total', 'Deposit', 'Balance', 'Status'],
                'rows' => DB::table('t_src_po')->where('fk_id_cycle', $id)->where('f_active', 1)->orderByRaw('COALESCE(po_date, created_at)')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => "#/po/{$r->id}",
                    'cells' => [self::d($r->po_date ?? $r->created_at), $r->po_number ?: $r->po_code, $sup($r->fk_id_supplier), (float) $r->po_total, (float) $r->deposit_amount, (float) $r->balance_amount, $r->status]])->all()],
            ['key' => 'payments', 'step' => 'pay', 'title' => 'Payments', 'empty' => 'No payments yet', 'cols' => ['Date', 'Payment code', 'Type', 'Amount', 'Base amount', 'Status'],
                'rows' => DB::table('t_src_payments')->where('fk_id_cycle', $id)->where('f_active', 1)->orderByRaw('COALESCE(payment_date, created_at)')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->payment_date ?? $r->created_at), $r->payment_code, $r->payment_type, trim(number_format((float) $r->payment_amount, 2, '.', '') . ' ' . $cur($r->fk_id_currency)), (float) $r->base_amount, $r->status]])->all()],
            ['key' => 'allocation', 'step' => 'allocation', 'title' => 'Destination Allocations', 'empty' => 'No destination allocations yet', 'cols' => ['Date', 'Allocation code', 'Country', 'Warehouse', 'Qty', 'Status'],
                'rows' => DB::table('t_src_allocations')->where('fk_id_cycle', $id)->where('f_active', 1)->orderBy('created_at')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->created_at), $r->alloc_code, $r->fk_id_country ? DB::table('cat_country')->where('id', $r->fk_id_country)->value('country_name') : null, self::loc($r->fk_id_warehouse), (float) $r->allocated_qty, $r->status]])->all()],
            ['key' => 'shipment', 'step' => 'shipment', 'title' => 'Shipments', 'empty' => 'No shipments yet', 'cols' => ['Date', 'Shipment code', 'Mode', 'Warehouse', 'ETD', 'ETA', 'Status'],
                'rows' => DB::table('t_src_shipments')->where('f_active', 1)->where(fn ($q) => $q->where('fk_id_cycle', $id)->orWhereIn('fk_id_po', $po ?: [0]))->orderByRaw('COALESCE(etd, created_at)')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->created_at), $r->shipment_code, $r->shipping_mode, self::loc($r->fk_id_warehouse), self::d($r->etd), self::d($r->eta), $r->status]])->all()],
            ['key' => 'grn', 'step' => 'grn', 'title' => 'Landings / GRN', 'empty' => 'No GRN yet', 'cols' => ['Date', 'GRN code', 'Received', 'Damaged', 'Missing', 'Status'],
                'rows' => DB::table('t_src_grn')->where('fk_id_cycle', $id)->where('f_active', 1)->orderByRaw('COALESCE(grn_date, arrival_date, created_at)')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'link' => $link,
                    'cells' => [self::d($r->grn_date ?? $r->arrival_date ?? $r->created_at), $r->grn_number ?: $r->grn_code, (float) $r->units_received, (float) $r->damaged_units, (float) $r->missing_units, $r->posting_status ?: $r->status]])->all()],
        ];
        foreach ($blocks as &$b) $b['empty_text'] = ! $b['rows'] && $b['step'] && in_array($b['step'], $skip, true) ? 'Skipped' : $b['empty']; unset($b);
        if (! $money) foreach ($blocks as &$b) foreach (array_keys(array_intersect($b['cols'], self::MONEY_COLS)) as $i) foreach ($b['rows'] as &$r) $r['cells'][$i] = '•••'; unset($b, $r);      // money only with buying.MONEY
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code], 'header' => "Cycle: {$c->cycle_code} | Stage: {$pl['current_name']} | Progress: {$pl['progress_pct']}%",
            'stage' => $pl['current_name'], 'progress_pct' => $pl['progress_pct'], 'steps' => $pl['steps'], 'blocks' => $blocks, 'file' => 'CycleJourney_' . $c->cycle_code . '_' . date('Ymd') . '.pdf'];
    }

    /** R-F_SETUP-12: the figures (also used by the cycle header). */
    public static function summary(int $ent, int $id): array
    {
        $c = self::cycle($ent, $id); $pl = CycleAlerts::pipeline($c);
        $poRows = DB::table('t_src_po')->where('fk_id_cycle', $id)->where('f_active', 1)->get(['id', 'fk_id_supplier', 'exchange_rate']); $po = $poRows->pluck('id')->all();
        $rate = $poRows->pluck('exchange_rate', 'id')->map(fn ($x) => (float) ($x ?: 1));
        $lines = DB::table('t_src_po_lines')->whereIn('fk_id_po', $po ?: [0])->where('f_active', 1)->get(['fk_id_po', 'qty_ordered', 'line_total']);
        $purchase = round($lines->sum(fn ($l) => (float) $l->line_total * ($rate[$l->fk_id_po] ?? 1)), 2);
        $paid = round((float) DB::table('t_src_payments')->where('fk_id_cycle', $id)->where('f_active', 1)->sum('base_amount'), 2);
        $freight = round((float) DB::table('t_src_freight')->where('fk_id_cycle', $id)->where('f_active', 1)->sum(DB::raw('total_cost * COALESCE(NULLIF(exchange_rate, 0), 1)')), 2);
        $cust = DB::table('t_src_customs')->where('fk_id_cycle', $id)->where('f_active', 1)->selectRaw('COALESCE(SUM(duty_amount),0) duty, COALESCE(SUM(import_vat),0) vat')->first();
        $lc = DB::table('t_src_landed_cost')->where(fn ($q) => $q->where('fk_id_cycle', $id)->orWhereIn('fk_id_po', $po ?: [0]))->where('f_active', 1)->whereNull('superseded_by')->selectRaw('COALESCE(SUM(total_cost),0) t, COALESCE(SUM(accepted_qty),0) q')->first();
        $ordered = (float) $lines->sum('qty_ordered');
        $produced = (float) DB::table('t_src_production')->where('fk_id_cycle', $id)->where('f_active', 1)->sum('produced_qty');
        $grn = DB::table('t_src_grn')->where('fk_id_cycle', $id)->where('f_active', 1)->selectRaw('COALESCE(SUM(units_received),0) r, COALESCE(SUM(damaged_units),0) d, COALESCE(SUM(missing_units),0) m')->first();
        $alloc = (float) DB::table('t_src_allocations')->where('fk_id_cycle', $id)->where('f_active', 1)->sum('allocated_qty');
        $qc = DB::table('t_src_qc')->where('fk_id_cycle', $id)->where('f_active', 1)->selectRaw('COALESCE(SUM(qty_passed),0) p, COALESCE(SUM(qty_inspected),0) i')->first();
        $sups = $poRows->pluck('fk_id_supplier')->filter()->unique()->values()->all();
        if (! $sups) $sups = DB::table('t_src_rfq')->where('fk_id_cycle', $id)->where('f_active', 1)->pluck('fk_id_supplier')->filter()->unique()->values()->all();
        $supNames = $sups ? DB::table('t_src_suppliers')->whereIn('id', $sups)->orderBy('supplier_name')->pluck('supplier_name')->all() : [];
        $num = fn ($x) => rtrim(rtrim(number_format((float) $x, 3, '.', ''), '0'), '.');
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code], 'title' => 'SOURCING CYCLE SUMMARY — ' . $c->cycle_code, 'base' => Fx::base($ent),
            'status' => $c->status, 'priority' => $c->priority, 'stage' => $pl['current_name'], 'progress_pct' => $pl['progress_pct'],
            'products' => DB::table('t_src_cycle_products')->where('fk_id_cycle', $id)->where('f_active', 1)->orderBy('id')->pluck('product_name')->filter()->implode(', ') ?: '-',
            'suppliers' => $supNames ? implode(', ', $supNames) : '-',
            'money' => ['purchase_cost' => $purchase, 'paid' => $paid, 'outstanding' => round($purchase - $paid, 2), 'freight' => $freight, 'customs_duty' => round((float) $cust->duty, 2), 'landed_per_unit' => (float) $lc->q > 0 ? round((float) $lc->t / (float) $lc->q, 4) : 0.0],
            'qty' => ['ordered' => $ordered, 'produced' => $produced, 'production_pct' => $ordered > 0 ? round($produced / $ordered * 100, 1) : 0.0, 'received' => (float) $grn->r, 'allocated' => $alloc, 'unallocated' => $ordered - $alloc,
                'damaged' => (float) $grn->d, 'missing' => (float) $grn->m, 'qc_pass_rate' => (float) $qc->i > 0 ? round((float) $qc->p / (float) $qc->i * 100, 1) : 0.0],
            'qty_text' => ['produced' => $num($produced) . ' (' . number_format($ordered > 0 ? $produced / $ordered * 100 : 0, 1) . '%)'],
            'import_vat' => round((float) $cust->vat, 2), 'footnote' => 'Import VAT (' . number_format((float) $cust->vat, 2) . ') tracked separately — reclaimable, not in landed cost. Generated ' . date('d-m-Y H:i'),
            'file' => 'CycleSummary_' . $c->cycle_code . '_' . date('Ymd') . '.pdf'];
    }

    private const CSS = 'body{font-family:"DejaVu Sans",sans-serif;font-size:10px;color:#16181D}h1{font-size:15px;margin:0 0 6px}h2{font-size:12px;margin:12px 0 4px;color:#0F5E63}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cfd4d0;padding:3px 5px;text-align:left}th{background:#eef1ee}.num{text-align:right}.muted{color:#777}.foot{font-size:11px;color:#888;margin-top:10px}';

    private static function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES); }

    public static function journeyPdf(int $ent, int $id, bool $money = true): array
    {
        $j = self::journey($ent, $id, $money);
        $h = '<html><head><meta charset="utf-8"><style>' . self::CSS . '</style></head><body><h1>' . self::e($j['header']) . '</h1>';
        foreach ($j['blocks'] as $b) {
            $h .= '<h2>' . self::e($b['title']) . '</h2>';
            if (! $b['rows']) { $h .= '<div class="muted">' . self::e($b['empty_text']) . '</div>'; continue; }
            $h .= '<table><thead><tr>' . implode('', array_map(fn ($c) => '<th>' . self::e($c) . '</th>', $b['cols'])) . '</tr></thead><tbody>';
            foreach ($b['rows'] as $r) $h .= '<tr>' . implode('', array_map(fn ($v) => '<td' . (is_float($v) ? ' class="num"' : '') . '>' . self::e(is_float($v) ? number_format($v, 2) : $v) . '</td>', $r['cells'])) . '</tr>';
            $h .= '</tbody></table>';
        }
        $h .= '</body></html>';
        return ['name' => $j['file'], 'type' => 'application/pdf', 'body' => PrintService::pdf($h, 210, 297), 'html' => $h];
    }

    /** Template cycle_summary_a4. */
    public static function summaryPdf(int $ent, int $id): array
    {
        $s = self::summary($ent, $id); $m = $s['money']; $q = $s['qty']; $b = ' ' . $s['base']; $f2 = fn ($x) => number_format($x, 2) . $b; $n = fn ($x) => rtrim(rtrim(number_format((float) $x, 3, '.', ','), '0'), '.');
        $rows = [['Purchase Cost', $f2($m['purchase_cost']), 'Ordered', $n($q['ordered'])], ['Paid', $f2($m['paid']), 'Produced', $s['qty_text']['produced']], ['Outstanding', $f2($m['outstanding']), 'Received', $n($q['received'])],
            ['Freight', $f2($m['freight']), 'Allocated / Unallocated', $n($q['allocated']) . ' / ' . $n($q['unallocated'])], ['Customs Duty', $f2($m['customs_duty']), 'Damaged / Missing', $n($q['damaged']) . ' / ' . $n($q['missing'])],
            ['<b>Landed Cost / Unit</b>', number_format($m['landed_per_unit'], 4) . $b, '<b>QC Pass Rate</b>', number_format($q['qc_pass_rate'], 1) . ' %']];
        $h = '<html><head><meta charset="utf-8"><style>' . self::CSS . '</style></head><body><h1>' . self::e($s['title']) . '</h1>'
            . '<div>Status: <b>' . self::e($s['status']) . '</b> · Priority: <b>' . self::e($s['priority'] ?: '-') . '</b> · Stage: <b>' . self::e($s['stage']) . '</b> · Progress: <b>' . $s['progress_pct'] . '%</b></div>'
            . '<div style="margin:4px 0 10px">Products: ' . self::e($s['products']) . ' · Suppliers: ' . self::e($s['suppliers']) . '</div>'
            . '<table><thead><tr><th>Money</th><th class="num"></th><th>Quantities</th><th class="num"></th></tr></thead><tbody>'
            . implode('', array_map(fn ($r) => "<tr><td>{$r[0]}</td><td class=\"num\">" . self::e($r[1]) . "</td><td>{$r[2]}</td><td class=\"num\">" . self::e($r[3]) . '</td></tr>', $rows)) . '</tbody></table>'
            . '<div class="foot">' . self::e($s['footnote']) . '</div></body></html>';
        return ['name' => $s['file'], 'type' => 'application/pdf', 'body' => PrintService::pdf($h, 210, 297), 'html' => $h];
    }
}
