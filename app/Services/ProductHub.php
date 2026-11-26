<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-14 / R-D-42 the product page, as in the old tool's edit page but connected: its purchases (Logistics) – every PO line and every lot that came in
 * (opening, quick buy, GRN), with the supplier, quantity, unit cost and where it stands – read from the same ledger and POs as everywhere else.
 */
class ProductHub
{
    public static function purchases(int $ent, int $pid): array
    {
        $po = DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')
            ->where('l.fk_id_product', $pid)->where('l.f_active', 1)->where('p.f_active', 1)->where('p.fk_id_ent', $ent)->orderByDesc('p.po_date')->limit(100)
            ->get(['p.id', 'p.po_number', 'p.po_code', 'p.po_date', 'p.status', 's.supplier_name', 'c.currency_code', 'l.qty_ordered', 'l.qty_confirmed', 'l.unit_price', 'l.line_total']);
        $lots = DB::table('fin_lots as x')->leftJoin('t_account as o', 'o.id', '=', 'x.fk_id_owner')->where('x.fk_id_product', $pid)->where('x.company_id', $ent)->orderByDesc('x.received_date')->orderByDesc('x.id')->limit(100)
            ->get(['x.id', 'x.lot_code', 'x.source', 'x.received_date', 'x.qty', 'x.qty_left', 'x.unit_cost_gbp', 'x.landed_status', 'x.batch_no', 'x.lot_status', DB::raw("TRIM(CONCAT_WS(' ', o.first_name, o.sur_name)) as owner")]);
        $units = (float) $lots->where('lot_status', '<>', 'VOID')->sum('qty'); $cost = (float) $lots->where('lot_status', '<>', 'VOID')->sum(fn ($l) => (float) $l->qty * (float) $l->unit_cost_gbp);
        return ['pos' => $po->map(fn ($r) => ['po' => (int) $r->id, 'no' => $r->po_number ?: $r->po_code, 'date' => $r->po_date, 'status' => $r->status, 'supplier' => $r->supplier_name, 'currency' => $r->currency_code,
                'qty' => (float) ($r->qty_confirmed > 0 ? $r->qty_confirmed : $r->qty_ordered), 'unit_price' => (float) $r->unit_price, 'total' => (float) $r->line_total])->all(),
            'lots' => $lots->map(fn ($l) => ['id' => (int) $l->id, 'lot' => $l->lot_code, 'source' => $l->source, 'date' => $l->received_date, 'qty' => (float) $l->qty, 'left' => (float) $l->qty_left, 'unit_cost' => (float) $l->unit_cost_gbp,
                'landed' => $l->landed_status, 'batch' => $l->batch_no, 'status' => $l->lot_status, 'owner' => $l->owner ?: 'Company'])->all(),
            'summary' => ['units_in' => $units, 'avg_cost' => $units > 0 ? round($cost / $units, 4) : null, 'pos' => $po->count(), 'last_price' => $po->first()?->unit_price !== null ? (float) $po->first()->unit_price : null, 'base' => Fx::base($ent)]];
    }
}
