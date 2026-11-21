<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * PO line types (R-B_SOURCING-6): PRODUCT (stock), FEE (agent fee, no stock), COST (packing, labels, inspection …, no stock), SAMPLE (never sellable stock, no lot).
 * - Empty type → PRODUCT; unknown → PRODUCT + "WARN: line '<name>' unknown line_type '<x>' treated as PRODUCT".
 * - Amount = qty > 0 ? round(qty × unit price, 2) : round(unit price, 2) (lump-sum FEE / COST). Every type counts in the PO total.
 * - FEE / COST → one freight-cost row (code FRT, order currency, order rate, base = round(amount × rate, 2), date = order date, notes "<TYPE>: <name>",
 *   cost type "Agent Fee" / "Other", source_po_line_id) – the only way that amount enters landed cost (split by value).
 * - SAMPLE with an amount and no sample row → a sample row is made (same currency and date); the sample rule books it once.
 * - A PRODUCT line needs a product-master link. Only PRODUCT lines are shipped and received into stock.
 */
class PoLines
{
    public const TYPES = ['PRODUCT' => 'Product (stock)', 'FEE' => 'Agent fee (no stock)', 'COST' => 'Other cost – packing, labels, inspection (no stock)', 'SAMPLE' => 'Sample (never stock)'];
    public const HINT = 'FEE/COST: put the amount in Unit Price (Qty empty or 1). They never create stock.';
    public const COST_TYPE = ['FEE' => 'Agent Fee', 'COST' => 'Other'];

    /** The type to keep, and a warning line when it was unknown. */
    public static function type(?string $type, string $name, array &$log = []): string
    {
        $t = strtoupper(trim((string) $type));
        if ($t === '') return 'PRODUCT';
        if (! isset(self::TYPES[$t])) { $log[] = "WARN: line '$name' unknown line_type '" . trim((string) $type) . "' treated as PRODUCT"; return 'PRODUCT'; }
        return $t;
    }

    public static function amount($qty, $price): float { return (float) $qty > 0 ? round((float) $qty * (float) $price, 2) : round((float) $price, 2); }

    public static function isStock(?string $type): bool { return in_array(strtoupper((string) $type), ['', 'PRODUCT'], true); }

    /** After a line is written: its freight-cost row / sample row, and the PO total. Returns notes for the log. */
    public static function sync(int $lineId): array
    {
        $l = DB::table('t_src_po_lines')->where('id', $lineId)->first(); if (! $l) return [];
        $po = DB::table('t_src_po')->where('id', $l->fk_id_po)->first(); if (! $po) return [];
        $ent = (int) $po->fk_id_ent; $type = self::type($l->line_type, (string) ($l->line_notes ?: $l->sku)); $notes = [];
        $amount = self::amount($l->qty_ordered, $l->unit_price) + (float) ($l->other_charges ?? 0);
        if ((float) $l->line_total !== $amount || $l->line_type !== $type) DB::table('t_src_po_lines')->where('id', $l->id)->update(['line_total' => $amount, 'line_type' => $type]);
        $rate = fn () => (float) ($po->exchange_rate ?: 0) ?: (function () use ($po, $ent) { try { return Fx::rate(Fx::code($po->fk_id_currency, $ent) ?: Fx::base($ent), (string) $po->po_date, $ent); } catch (\RuntimeException) { return 0.0; } })();      // no rate yet → worked out again when the PO is confirmed
        $fc = DB::table('t_src_freight_costs')->where('source_po_line_id', $l->id)->first();
        if (isset(self::COST_TYPE[$type]) && (int) $l->f_active === 1 && $amount > 0) {
            $r = $rate();
            $row = ['fk_id_ent' => $ent, 'fk_id_cycle' => $po->fk_id_cycle, 'cost_type' => self::COST_TYPE[$type], 'amount' => $amount, 'fk_id_currency' => $po->fk_id_currency, 'exchange_rate' => $r,
                'base_amount' => round($amount * $r, 2), 'cost_date' => $po->po_date, 'notes' => mb_substr("$type: " . ($l->line_notes ?: $l->sku ?: 'line ' . $l->id), 0, 500), 'f_active' => 1, 'updated_at' => now(), 'updated_by' => Context::userId()];
            if ($fc) { if ($fc->pay_status !== 'PAID') DB::table('t_src_freight_costs')->where('id', $fc->id)->update($row); }
            else DB::table('t_src_freight_costs')->insert($row + ['freight_code' => Numbering::next('FRT', $ent, (string) $po->po_date), 'source_po_line_id' => $l->id, 'created_by' => Context::userId(), 'created_at' => now()]);
        } elseif ($fc && $fc->pay_status !== 'PAID') DB::table('t_src_freight_costs')->where('id', $fc->id)->update(['f_active' => 0, 'updated_at' => now()]);      // type changed away / line removed
        if ($type === 'SAMPLE' && ! $l->fk_id_sample && $amount > 0 && $po->fk_id_cycle && ($r = $rate()) > 0) {
            $sid = SourcingExtras::saveSample($ent, ['cycle' => $po->fk_id_cycle, 'supplier' => $po->fk_id_supplier, 'sample_cost' => $amount, 'sample_qty' => (float) $l->qty_ordered > 0 ? (float) $l->qty_ordered : 1,
                'sample_date' => $po->po_date, 'currency' => Fx::code($po->fk_id_currency, $ent) ?: Fx::base($ent), 'exchange_rate' => $r, 'notes' => 'From PO ' . $po->po_number . ' line ' . ($l->line_notes ?: $l->sku)]);
            DB::table('t_src_po_lines')->where('id', $l->id)->update(['fk_id_sample' => $sid]); $notes[] = "Sample row made for line '" . ($l->line_notes ?: $l->sku) . "'";
        }
        if ($l->fk_id_cycle_product && ! self::isStock($type)) DB::table('t_src_cycle_products')->where('id', $l->fk_id_cycle_product)->update(['row_status' => $type]);
        self::total((int) $po->id);
        return $notes;
    }

    /** On confirm the order rate is fixed: FEE / COST / SAMPLE lines are worked out again at that rate. */
    public static function resyncPo(int $poId): void
    {
        foreach (DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('f_active', 1)->whereIn('line_type', ['FEE', 'COST', 'SAMPLE'])->pluck('id') as $id) self::sync((int) $id);
    }

    public static function total(int $poId): float
    {
        $t = round((float) DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('f_active', 1)->sum('line_total'), 2);
        DB::table('t_src_po')->where('id', $poId)->update(['po_total' => $t]);
        return $t;
    }

    /** "NOTE: no PRODUCT lines - nothing will go to stock" when a PO has no product value and quantity. */
    public static function stockNote(int $poId): ?string
    {
        $has = DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhereIn('line_type', ['', 'PRODUCT']))->where('qty_ordered', '>', 0)->exists();
        return $has ? null : 'NOTE: no PRODUCT lines - nothing will go to stock';
    }
}
