<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-4 Repeat from cycle ("Repeat this cycle" on the cycle record and the cycle list): a new cycle (new code, sourcing date today,
 * status Draft; required delivery, requested by, priority, notes, type and skipped steps copied), source_cycle_id stored; every ACTIVE cycle
 * product copied with target unit cost = last agreed price (the product's latest PO line, else the source RFQ final price of its supplier,
 * else the old target); their carton configs copied (no quantities); every ACTIVE RFQ copied (new code, date today, status Sent, note
 * "copied from <old RFQ code> – re-confirm price"). Opens on the Details of the new cycle.
 */
class CycleRepeat
{
    public const COPY_CYCLE = ['required_delivery_date', 'priority', 'notes', 'brand', 'product_name', 'sku', 'variation', 'target_qty', 'product_spec', 'cycle_type', 'skipped_steps', 'fk_id_owner', 'fk_id_main_branch'];
    public const CARTON_CONFIG = ['sku', 'config_name', 'unit_l', 'unit_w', 'unit_h', 'unit_cbm', 'volumetric_weight', 'unit_net_weight', 'unit_gross_weight', 'carton_tare_weight', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_net_weight', 'carton_gross_weight', 'carton_cbm', 'hs_code', 'fk_id_country'];

    /** Last agreed price of a cycle product: its latest PO line, else the RFQ final price of its supplier in the source cycle, else its target. */
    public static function lastPrice(object $cp, ?int $ent = null): array
    {
        $mode = Choices::get('CYCLE_REPEAT_PRICE', $ent ?? (int) (DB::table('t_src_cycles')->where('id', $cp->fk_id_cycle)->value('fk_id_ent') ?: 0) ?: null);      // Settings › Choices
        $target = [$cp->target_unit_cost === null ? null : (float) $cp->target_unit_cost, 'target of the old cycle'];
        if ($mode === 'TARGET') return $target;
        $line = DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->where('l.f_active', 1)->where('p.f_active', 1)->where('l.unit_price', '>', 0)
            ->where(fn ($w) => $w->where('l.fk_id_cycle_product', $cp->id)->when($cp->fk_id_product, fn ($x) => $x->orWhere('l.fk_id_product', $cp->fk_id_product)))
            ->orderByDesc('p.po_date')->orderByDesc('l.id')->first(['l.unit_price', 'p.po_number']);
        $fromLine = $line ? [(float) $line->unit_price, 'last PO line (' . $line->po_number . ')'] : null;
        if ($fromLine && $mode !== 'RFQ_FIRST') return $fromLine;
        $rfq = $cp->fk_id_supplier ? DB::table('t_src_rfq')->where('fk_id_cycle', $cp->fk_id_cycle)->where('f_active', 1)->where('final_unit_price', '>', 0)->where('fk_id_supplier', $cp->fk_id_supplier)
            ->orderByDesc('rfq_date')->orderByDesc('id')->first(['final_unit_price', 'rfq_code']) : null;      // an RFQ is per supplier: a product without supplier keeps its target
        if ($rfq) return [(float) $rfq->final_unit_price, 'RFQ final price (' . $rfq->rfq_code . ')'];
        return $fromLine ?? $target;
    }

    public static function repeat(int $ent, int $sourceId, int $userId): array
    {
        $src = DB::table('t_src_cycles')->where('id', $sourceId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Not found.');
        return DB::transaction(function () use ($ent, $src, $userId) {
            $today = date('Y-m-d'); $now = now();
            $row = ['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('CYC', $ent), 'sourcing_date' => $today, 'status' => 'Draft', 'source_cycle_id' => (int) $src->id, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now, 'f_active' => 1];
            foreach (self::COPY_CYCLE as $c) if (property_exists($src, $c)) $row[$c] = $src->$c; $row['requested_by'] = Context::userId();      /* R-B_SOURCING-62 the person who repeats it */
            $new = (int) DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', $row));
            $map = []; $prices = [];
            foreach (DB::table('t_src_cycle_products')->where('fk_id_cycle', $src->id)->where('f_active', 1)->orderBy('id')->get() as $cp) {
                [$price, $from] = self::lastPrice($cp, $ent);
                $p = (array) $cp; unset($p['id']);
                $p = array_merge($p, ['fk_id_cycle' => $new, 'target_unit_cost' => $price, 'created_by' => $userId, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
                $map[$cp->id] = (int) DB::table('t_src_cycle_products')->insertGetId($p);
                $prices[] = ['product' => $cp->product_name, 'price' => $price, 'from' => $from];
            }
            $cartons = 0; $seen = [];
            foreach (DB::table('t_src_cartons')->where('fk_id_cycle', $src->id)->where('f_active', 1)->whereIn('fk_id_cycle_product', array_keys($map) ?: [0])->orderByDesc('id')->get() as $c) {
                $k = $c->fk_id_cycle_product . '|' . mb_strtolower((string) $c->config_name); if (isset($seen[$k])) continue; $seen[$k] = 1;      // the newest config per product and name
                $x = ['fk_id_ent' => $ent, 'fk_id_cycle' => $new, 'fk_id_cycle_product' => $map[$c->fk_id_cycle_product], 'created_by' => $userId, 'created_at' => $now, 'f_active' => 1];
                foreach (self::CARTON_CONFIG as $f) $x[$f] = $c->$f;
                DB::table('t_src_cartons')->insert(RecordService::fillNotNull('t_src_cartons', $x)); $cartons++;
            }
            $rfqs = [];
            foreach (DB::table('t_src_rfq')->where('fk_id_cycle', $src->id)->where('f_active', 1)->orderBy('id')->get() as $q) {
                $r = (array) $q; unset($r['id']);
                $note = 'copied from ' . $q->rfq_code . ' – re-confirm price';
                $r = array_merge($r, ['fk_id_cycle' => $new, 'rfq_code' => Numbering::next('RFQ', $ent), 'rfq_date' => $today, 'status' => 'Sent', 'notes' => trim($note . ($q->notes ? "\n" . $q->notes : '')), 'created_by' => $userId, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now]);
                $rid = (int) DB::table('t_src_rfq')->insertGetId($r); $rfqs[] = $r['rfq_code'];
            }
            $code = $row['cycle_code'];
            Audit::log('t_src_cycles', $new, 'INSERT', ['repeat_of' => $src->cycle_code, 'products' => count($map), 'rfqs' => count($rfqs), 'cartons' => $cartons], "Repeat of {$src->cycle_code}", $ent);
            Audit::log('t_src_cycles', (int) $src->id, 'UPDATE', ['repeated_as' => $code], "Repeated as $code", $ent);
            return ['id' => $new, 'code' => $code, 'products' => count($map), 'rfqs' => $rfqs, 'cartons' => $cartons, 'prices' => $prices, 'open' => "#/cycle/$new?step=details",
                'message' => "Cycle $code made from {$src->cycle_code}: " . count($map) . ' product(s), ' . count($rfqs) . ' RFQ(s) copied – re-confirm the prices.'];
        });
    }
}
