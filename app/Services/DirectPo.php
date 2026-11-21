<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Direct PO save in one transaction (R-B_SOURCING-2).
 * Header: supplier (required), Repeat from PO (default = the supplier's last PO: copies lines, currency, incoterm, payment term, units per carton, last unit price),
 * currency (default the supplier's), exchange rate (default today's rate, editable, stored on the PO), incoterm, payment terms, expected ready, required delivery,
 * deposit % or "single 100% term (paid in full)", notes. Lines: product (required – the product master link), qty > 0, unit price (default the last price paid
 * to this supplier for this product), units / carton.
 * One transaction: cycle DIRECT (approved, steps rfq / samples / production / qc skipped) → PO confirmed (terms, rate) → per line cycle product + PO line
 * (+ carton row when units / carton > 0) → history DIRECT_PO_CREATED → event PO_CONFIRMED. Any failure → nothing saved ("Save failed - nothing was created").
 */
class DirectPo
{
    /** The last price paid to this supplier for this product (latest PO line). */
    public static function lastPrice(int $ent, int $supplier, int $product): ?float
    {
        $v = DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->where('p.fk_id_ent', $ent)->where('p.fk_id_supplier', $supplier)->where('l.fk_id_product', $product)->where('l.f_active', 1)
            ->whereNotIn('p.status', ['CANCELLED'])->orderByDesc('p.po_date')->orderByDesc('l.id')->value('l.unit_price');
        return $v === null ? null : (float) $v;
    }

    /** What the Direct PO form opens with for a supplier (and the PO to repeat, default the last one). */
    public static function prefill(int $ent, int $supplier, ?int $repeat = null): array
    {
        $s = DB::table('t_src_suppliers')->where('id', $supplier)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Select the Supplier');
        $pos = DB::table('t_src_po')->where('fk_id_ent', $ent)->where('fk_id_supplier', $supplier)->where('f_active', 1)->whereNotIn('status', ['CANCELLED'])->orderByDesc('po_date')->orderByDesc('id')->limit(20)->get(['id', 'po_number', 'po_date', 'po_total']);
        $from = $repeat ? DB::table('t_src_po')->where('id', $repeat)->where('fk_id_ent', $ent)->where('fk_id_supplier', $supplier)->first() : ($pos->isNotEmpty() ? DB::table('t_src_po')->where('id', $pos[0]->id)->first() : null);
        $cur = $from ? Fx::code($from->fk_id_currency, $ent) : ($s->fk_id_currency ? Fx::code($s->fk_id_currency, $ent) : Fx::base($ent));
        $lines = [];
        if ($from) foreach (DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_po', $from->id)->where('l.f_active', 1)->where(fn ($w) => $w->whereNull('l.line_type')->orWhereIn('l.line_type', ['', 'PRODUCT']))->orderBy('l.id')
            ->get(['l.fk_id_product', 'l.qty_ordered', 'l.unit_price', 'p.product_name_vendor', 'p.SKU_VENDOR']) as $l)
            $lines[] = ['product' => (int) $l->fk_id_product, 'name' => $l->product_name_vendor, 'sku' => $l->SKU_VENDOR, 'qty' => (float) $l->qty_ordered, 'unit_price' => self::lastPrice($ent, $supplier, (int) $l->fk_id_product) ?? (float) $l->unit_price,
                'units_per_carton' => (int) (DB::table('t_src_cartons')->where('fk_id_po', $from->id)->where('sku', $l->SKU_VENDOR)->value('units_per_carton') ?? 0)];
        return ['supplier' => (int) $s->id, 'currency' => $cur, 'rate' => Fx::rate($cur, date('Y-m-d'), $ent), 'incoterm' => $from->fk_id_incoterm ?? $s->fk_id_incoterm, 'payment_term' => $from->fk_id_payment_term ?? $s->fk_id_payment_term,
            'deposit_pct' => $from ? (float) $from->deposit_pct : 30, 'repeat_from' => $from->id ?? null, 'pos' => $pos, 'lines' => $lines,
            'incoterms' => DB::table('cat_incoterm')->where('f_active', 1)->orderBy('incoterm_code')->get(['id', DB::raw("CONCAT_WS(' ', incoterm_code, incoterm_name) as name")]),
            'payment_terms' => DB::table('cat_payment_terms')->where('f_active', 1)->orderBy('term_short')->get(['id', DB::raw('COALESCE(term_short, term_descr) as name')])];
    }

    /** Save everything in one transaction. Returns ['cycle', 'po', 'total', 'message']. */
    public static function save(int $ent, array $d): array
    {
        if (empty($d['supplier'])) throw new \InvalidArgumentException('Select the Supplier');
        $lines = array_values(array_filter((array) ($d['lines'] ?? []), fn ($l) => ! empty($l['product']) || trim((string) ($l['name'] ?? '')) !== '' || (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) throw new \InvalidArgumentException('Add at least one product line (name + qty)');
        foreach ($lines as $i => $l) {
            if ((float) ($l['qty'] ?? 0) <= 0) throw new \InvalidArgumentException('Line ' . ($i + 1) . ': the quantity must be more than zero.');
            if (empty($l['product'])) throw new \InvalidArgumentException('Line ' . ($i + 1) . ': choose the product (or add it) – a product line needs the product master.');
        }
        try {
            return DB::transaction(function () use ($ent, $d, $lines) {
                $s = DB::table('t_src_suppliers')->where('id', (int) $d['supplier'])->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Select the Supplier');
                $cur = strtoupper((string) ($d['currency'] ?? '')) ?: ($s->fk_id_currency ? Fx::code($s->fk_id_currency, $ent) : Fx::base($ent));
                $full = ! empty($d['paid_in_full']); $dep = $full ? 100 : max(0, min(100, (float) ($d['deposit_pct'] ?? 30)));
                $first = DB::table('t_product_vendors')->where('id', (int) $lines[0]['product'])->first();
                $cycle = (int) DB::table('t_src_cycles')->insertGetId(['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('CYC', $ent), 'sourcing_date' => date('Y-m-d'), 'requested_by' => Context::userId(), 'product_name' => mb_substr((string) ($first->product_name_vendor ?? 'Direct PO'), 0, 200),
                    'target_qty' => array_sum(array_map(fn ($l) => (float) $l['qty'], $lines)), 'required_delivery_date' => $d['required_delivery'] ?? null, 'priority' => 'NORMAL', 'status' => 'OPEN', 'notes' => $d['notes'] ?? null,
                    'cycle_type' => 'DIRECT', 'skipped_steps' => 'rfq,samples,production,qc', 'approval_status' => 'APPROVED', 'approved_by' => Context::userId(), 'approved_at' => now(), 'order_ref' => ! empty($d['order_ref']) ? mb_substr(SrcDuplicate::clean($d['order_ref']), 0, 100) : null,
                    'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
                $poLines = [];
                foreach ($lines as $l) {
                    $p = DB::table('t_product_vendors')->where('id', (int) $l['product'])->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('A product line needs a product of this company.');
                    $price = isset($l['unit_price']) && $l['unit_price'] !== '' && $l['unit_price'] !== null ? (float) $l['unit_price'] : (self::lastPrice($ent, (int) $s->id, (int) $p->id) ?? 0.0);
                    $cp = (int) DB::table('t_src_cycle_products')->insertGetId(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $s->id, 'fk_id_product' => $p->id, 'product_name' => $l['name'] ?? $p->product_name_vendor, 'sku' => $p->SKU_VENDOR,
                        'variation' => $l['variation'] ?? null, 'target_qty' => (float) $l['qty'], 'target_unit_cost' => $price, 'row_status' => 'CONFIRMED', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
                    $poLines[] = ['product' => (int) $p->id, 'qty' => (float) $l['qty'], 'unit_price' => $price, '_cp' => $cp, '_upc' => (int) ($l['units_per_carton'] ?? 0), '_sku' => $p->SKU_VENDOR];
                }
                $po = BuyingService::createPo(['cycle' => $cycle, 'supplier' => $s->id, 'currency' => $cur, 'date' => date('Y-m-d'), 'deposit_pct' => $dep, 'repeat_from' => $d['repeat_from'] ?? null, 'fk_id_payment_term' => $d['payment_term'] ?? null,
                    'lines' => array_map(fn ($x) => array_intersect_key($x, array_flip(['product', 'qty', 'unit_price'])), $poLines), 'order_ref' => $d['order_ref'] ?? null, 'dup_reason' => $d['dup_reason'] ?? null, 'dup_checked' => $d['dup_checked'] ?? null]);
                DB::table('t_src_po')->where('id', $po)->update(['fk_id_incoterm' => $d['incoterm'] ?? null, 'expected_production_date' => $d['expected_ready'] ?? null, 'required_ship_date' => $d['required_delivery'] ?? null, 'notes' => $d['notes'] ?? null, 'f_paid_in_full' => $full ? 1 : 0]);
                foreach ($poLines as $x) if ($x['_upc'] > 0)
                    DB::table('t_src_cartons')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_po' => $po, 'fk_id_cycle_product' => $x['_cp'], 'sku' => $x['_sku'], 'units_per_carton' => $x['_upc'], 'total_planned_units' => (int) $x['qty'],
                        'full_cartons' => intdiv((int) $x['qty'], $x['_upc']), 'loose_units' => (int) $x['qty'] % $x['_upc'], 'carton_qty' => (int) ceil($x['qty'] / $x['_upc']), 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
                BuyingService::confirmPo($po);      // terms (deposit % or one 100 % term) and PO_CONFIRMED
                if (isset($d['rate']) && (float) $d['rate'] > 0) { DB::table('t_src_po')->where('id', $po)->update(['exchange_rate' => round((float) $d['rate'], 6)]); PoLines::resyncPo($po); }      // the rate typed on the form is kept
                $p = DB::table('t_src_po')->where('id', $po)->first(['po_number', 'po_total']);
                DB::table('t_src_po')->where('id', $po)->update(['balance_amount' => DB::raw('po_total')]);      // the rule: po_total = balance_amount = Σ line_total (nothing paid yet)
                Audit::log('t_src_cycles', $cycle, 'INSERT', ['DIRECT_PO_CREATED' => "PO {$p->po_number} · " . count($poLines) . ' lines · total ' . number_format((float) $p->po_total, 2)], null, $ent);
                return ['cycle' => $cycle, 'po' => $po, 'total' => (float) $p->po_total, 'message' => 'Created. Opening Payments...'];
            });
        } catch (SrcDuplicateException $e) { throw $e; }
        catch (\Illuminate\Database\QueryException $e) { report($e); throw new \RuntimeException('Save failed - nothing was created'); }
    }
}
