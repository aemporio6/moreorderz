<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Supplier and SKU inherit in the cycle step forms (R-B_SOURCING-21).
 * Supplier (RFQ, Sample, PO, QC, Shipment, Payment) = linked:cycle.supplier – the supplier of the latest active PO of the cycle, else of the latest active RFQ;
 * filled only when empty. A cycle with more than one supplier offers each as a chip. A Payment takes the chosen PO's supplier.
 * SKU (Allocation, Carton, Ownership transfer, Platform allocation, PO line, Transfer) is read-only "Auto from Product": from the chosen cycle product
 * (its SKU, else its product's SKU), cleared when the cycle product is cleared. Never the product-master supplier.
 */
class SourcingInherit
{
    public const SUPPLIER_TABLES = ['t_src_rfq', 't_src_samples', 't_src_po', 't_src_qc', 't_src_shipments', 't_src_payments'];
    public const SKU_TABLES = ['t_src_allocations', 't_src_cartons', 't_src_ownership_transfers', 't_src_platform_alloc', 't_src_po_lines', 't_src_transfers'];

    /** Suppliers of a cycle: latest active PO first, then RFQs (no repeats). */
    public static function suppliers(int $cycleId): array
    {
        $po = DB::table('t_src_po')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->whereNotNull('fk_id_supplier')->whereNotIn('status', ['CANCELLED'])->orderByDesc('id')->pluck('fk_id_supplier')->all();
        $rfq = DB::table('t_src_rfq')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->whereNotNull('fk_id_supplier')->orderByDesc('id')->pluck('fk_id_supplier')->all();
        return array_values(array_unique(array_map('intval', array_merge($po, $rfq))));
    }

    public static function cycleSupplier(int $cycleId): ?int { return self::suppliers($cycleId)[0] ?? null; }

    public static function skuOf(?int $cycleProduct, ?int $product = null): ?string
    {
        if ($cycleProduct && ($cp = DB::table('t_src_cycle_products')->where('id', $cycleProduct)->first(['sku', 'fk_id_product'])))
            return $cp->sku ?: DB::table('t_product_vendors')->where('id', $cp->fk_id_product)->value('SKU_VENDOR');
        return $product ? DB::table('t_product_vendors')->where('id', $product)->value('SKU_VENDOR') : null;
    }

    /** Save hook of the step forms (column keys); returns field errors (none). */
    public static function check(string $table, array &$data, array $old): array
    {
        $v = fn ($c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        if (in_array($table, self::SUPPLIER_TABLES, true) && empty($v('fk_id_supplier'))) {
            $sup = $table === 't_src_payments' && $v('fk_id_po') ? DB::table('t_src_po')->where('id', $v('fk_id_po'))->value('fk_id_supplier') : null;
            $sup ??= $v('fk_id_cycle') ? self::cycleSupplier((int) $v('fk_id_cycle')) : null;
            if ($sup) $data['fk_id_supplier'] = (int) $sup;
        }
        if (in_array($table, self::SKU_TABLES, true) && (array_key_exists('fk_id_cycle_product', $data) || array_key_exists('fk_id_product', $data) || ! $old)) {
            $data['sku'] = self::skuOf($v('fk_id_cycle_product') ? (int) $v('fk_id_cycle_product') : null, $table === 't_src_po_lines' && $v('fk_id_product') ? (int) $v('fk_id_product') : null);
        }
        return [];
    }

    /** Chips for a supplier field: every supplier of the cycle (a Payment: its PO's supplier first). */
    public static function chips(string $table, array $form): array
    {
        if (! in_array($table, self::SUPPLIER_TABLES, true)) return [];
        $out = [];
        if ($table === 't_src_payments' && ! empty($form['fk_id_po']) && ($s = DB::table('t_src_po')->where('id', (int) $form['fk_id_po'])->value('fk_id_supplier'))) $out[(int) $s] = 'the supplier of the chosen PO';
        if (! empty($form['fk_id_cycle'])) foreach (self::suppliers((int) $form['fk_id_cycle']) as $i => $s) $out[$s] ??= $i === 0 ? 'the supplier of the cycle (latest PO, else RFQ)' : 'also a supplier of this cycle';
        $names = DB::table('t_src_suppliers')->whereIn('id', array_keys($out) ?: [0])->pluck('supplier_name', 'id');
        return array_map(fn ($id, $why) => ['value' => (string) $id, 'label' => (string) ($names[$id] ?? $id), 'why' => $why], array_keys($out), $out);
    }
}
