<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 02:31: packaging and cartons in one place, worked out by themselves and used everywhere (MY TOOL's packing + the old tool's carton form + more).
 * Three levels on the product: the item itself (size mm, weight kg), the retail pack it is sold in (box type, size mm, packed weight g) and the master carton
 * (size cm, units per carton, gross kg). A child takes from its parent what it does not have (one change on the parent reaches every child).
 * From that: CBM, air (÷ 6000) and courier (÷ 5000) volumetric weights, net / gross per carton (gross estimated when not weighed), CBM per unit, the
 * smallest carrier package the pack fits in (rotations tried, weight checked), and for any quantity: full cartons + the tail carton, CBM and kg.
 * A PO, shipment or listing asks here – nothing is typed twice. What is missing is listed with the field to fill.
 */
class Packaging
{
    public const AIR = 6000; public const COURIER = 5000;
    public const FIELDS = ['item_l_mm', 'item_w_mm', 'item_h_mm', 'weight', 'pack_l_mm', 'pack_w_mm', 'pack_h_mm', 'pack_weight_g', 'fk_id_pack_box', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_gross_weight', 'material', 'hs_code'];
    private const LABEL = ['item_l_mm' => 'Item length (mm)', 'item_w_mm' => 'Item width (mm)', 'item_h_mm' => 'Item height (mm)', 'weight' => 'Item weight (kg)', 'pack_l_mm' => 'Packed length (mm)',
        'pack_w_mm' => 'Packed width (mm)', 'pack_h_mm' => 'Packed height (mm)', 'pack_weight_g' => 'Packed weight (g)', 'units_per_carton' => 'Units per carton', 'carton_l' => 'Carton length (cm)',
        'carton_w' => 'Carton width (cm)', 'carton_h' => 'Carton height (cm)', 'carton_gross_weight' => 'Carton gross weight (kg)'];

    /** The packaging values of a product, the parent's where the child has none; [values, which came from the parent]. */
    public static function values(int $pid): array
    {
        $cols = array_values(array_filter(self::FIELDS, fn ($c) => Registry::hasColumn('t_product_vendors', $c)));
        $p = DB::table('t_product_vendors')->where('id', $pid)->first(array_merge(['id', 'SKU_VENDOR', 'product_name_vendor', 'f_id_parent'], $cols)) ?? throw new \InvalidArgumentException('Product not found.');
        $par = (int) $p->f_id_parent && (int) $p->f_id_parent !== $pid ? DB::table('t_product_vendors')->where('id', $p->f_id_parent)->first($cols) : null;
        $v = []; $from = [];
        foreach ($cols as $c) { $own = $p->$c ?? null; $empty = $own === null || $own === '' || (is_numeric($own) && (float) $own == 0.0);
            if ($empty && $par && ($pv = $par->$c ?? null) !== null && $pv !== '' && ! (is_numeric($pv) && (float) $pv == 0.0)) { $v[$c] = $pv; $from[] = $c; } else $v[$c] = $own; }
        return [$v + ['sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor], $from];
    }

    /** Everything worked out for one product (and a quantity, when given). */
    public static function calc(array $v, ?float $qty = null): array
    {
        $n = fn ($k) => is_numeric($v[$k] ?? null) ? (float) $v[$k] : 0.0;
        $item = ['l' => $n('item_l_mm'), 'w' => $n('item_w_mm'), 'h' => $n('item_h_mm'), 'kg' => $n('weight')];
        $pack = ['l' => $n('pack_l_mm'), 'w' => $n('pack_w_mm'), 'h' => $n('pack_h_mm'), 'kg' => $n('pack_weight_g') / 1000];
        if (! $pack['kg'] && $item['kg']) $pack['kg'] = $item['kg'];      // no pack weight: the item weight is the best we know
        $per = (int) $n('units_per_carton'); $c = ['l' => $n('carton_l'), 'w' => $n('carton_w'), 'h' => $n('carton_h')];
        $cbm = $c['l'] && $c['w'] && $c['h'] ? round($c['l'] * $c['w'] * $c['h'] / 1e6, 4) : 0.0;
        $net = $per && $pack['kg'] ? round($per * $pack['kg'], 3) : 0.0;
        $grossGiven = $n('carton_gross_weight') > 0; $gross = $grossGiven ? $n('carton_gross_weight') : ($net ? round($net * 1.05 + 0.6, 2) : 0.0);      // MY TOOL's estimate: 5 % + empty carton 0.6 kg
        $tare = $grossGiven && $net ? max(0, round($gross - $net, 3)) : ($net ? round($net * 0.05 + 0.6, 3) : 0.0);
        $packCbm = $pack['l'] && $pack['w'] && $pack['h'] ? $pack['l'] * $pack['w'] * $pack['h'] / 1e9 : 0.0;
        $out = ['item' => $item, 'pack' => $pack + ['cbm' => round($packCbm, 6), 'courier_kg' => round($packCbm * 1e6 / self::COURIER, 3), 'chargeable_kg' => round(max($pack['kg'], $packCbm * 1e6 / self::COURIER), 3)],
            'carton' => $c + ['per' => $per, 'cbm' => $cbm, 'net' => $net, 'gross' => $gross, 'gross_estimated' => ! $grossGiven && $gross > 0, 'tare' => $tare,
                'air_kg' => round($cbm * 1e6 / self::AIR, 2), 'courier_kg' => round($cbm * 1e6 / self::COURIER, 2), 'cbm_per_unit' => $per && $cbm ? round($cbm / $per, 6) : 0.0,
                'fill_pct' => $per && $cbm && $packCbm ? round($per * $packCbm / $cbm * 100) : null],
            'material' => $v['material'] ?? null, 'hs_code' => $v['hs_code'] ?? null];
        $miss = [];
        foreach (['pack_l_mm', 'pack_w_mm', 'pack_h_mm', 'pack_weight_g', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h'] as $k) if (! $n($k) && ! ($k === 'pack_weight_g' && $item['kg'])) $miss[] = ['field' => $k, 'label' => self::LABEL[$k]];
        $out['missing'] = $miss;
        if ($out['carton']['fill_pct'] !== null && $out['carton']['fill_pct'] > 100) $out['warnings'][] = "The packs ({$per} × pack size) do not fit in this carton – check the sizes.";
        if ($qty !== null) $out['plan'] = self::plan($out, $qty);
        return $out;
    }

    /** Full cartons + the tail carton (the last one that is not full), CBM and kg of a quantity. */
    public static function plan(array $k, float $qty): array
    {
        $per = (int) $k['carton']['per']; if ($per <= 0 || $qty <= 0) return ['qty' => $qty, 'cartons' => null, 'full' => null, 'tail' => null, 'cbm' => null, 'kg' => null];
        $full = (int) floor($qty / $per); $tail = (int) round($qty - $full * $per); $n = $full + ($tail > 0 ? 1 : 0);
        $tailKg = $tail > 0 ? $tail * $k['pack']['kg'] + $k['carton']['tare'] : 0.0;
        return ['qty' => $qty, 'full' => $full, 'tail' => $tail, 'cartons' => $n, 'cbm' => round($k['carton']['cbm'] * $n, 4), 'kg' => round($full * $k['carton']['gross'] + $tailKg, 2)];
    }

    /** The smallest active carrier package the retail pack fits in (any rotation; package weight limit checked when it has one). */
    public static function bestBox(array $k, int $limit = 3): array
    {
        $p = $k['pack']; if (! $p['l'] || ! $p['w'] || ! $p['h']) return [];
        $mine = [$p['l'] / 10, $p['w'] / 10, $p['h'] / 10]; rsort($mine); $out = [];      // the pack in cm, largest side first
        foreach (DB::table('t_carrier_packages as k')->leftJoin('t_carriers as c', 'c.id', '=', 'k.fk_id_carrier')->where('k.f_active', 1)->where('k.dim_length', '>', 0)->where('k.dim_width', '>', 0)->where('k.dim_height', '>', 0)
            ->get(['k.id', 'k.package_name', 'k.weight', 'k.dim_length', 'k.dim_width', 'k.dim_height', 'c.carrier_name']) as $b) {
            $box = [(float) $b->dim_length, (float) $b->dim_width, (float) $b->dim_height]; rsort($box);
            if ($mine[0] > $box[0] || $mine[1] > $box[1] || $mine[2] > $box[2]) continue;
            if ((float) $b->weight > 0 && $p['kg'] > (float) $b->weight) continue;
            $out[] = ['id' => (int) $b->id, 'name' => trim($b->carrier_name . ' – ' . $b->package_name), 'size' => "{$b->dim_length}×{$b->dim_width}×{$b->dim_height} cm", 'max_kg' => (float) $b->weight ?: null, 'volume' => $box[0] * $box[1] * $box[2]];
        }
        usort($out, fn ($a, $b) => $a['volume'] <=> $b['volume']);
        return array_map(fn ($x) => array_diff_key($x, ['volume' => 1]), array_slice($out, 0, $limit));
    }

    public static function product(int $pid, ?float $qty = null): array
    {
        [$v, $from] = self::values($pid); $k = self::calc($v, $qty);
        return ['product' => $pid, 'sku' => $v['sku'], 'name' => $v['name'], 'values' => array_diff_key($v, ['sku' => 1, 'name' => 1]), 'from_parent' => $from] + $k + ['best_box' => self::bestBox($k)];
    }

    /** Packing of a PO: every line with its cartons, CBM, kg; totals; the products whose sizes are missing (with the field to fill). */
    public static function po(int $ent, int $poId): array
    {
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('PO not found.');
        $rows = []; $t = ['cartons' => 0, 'cbm' => 0.0, 'kg' => 0.0, 'tails' => 0, 'units' => 0.0]; $miss = [];
        foreach (DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('f_active', 1)->get(['fk_id_product', 'sku', 'qty_ordered', 'qty_confirmed']) as $l) {
            $q = (float) $l->qty_confirmed > 0 ? (float) $l->qty_confirmed : (float) $l->qty_ordered; if ($q <= 0) continue; $t['units'] += $q;
            $pid = (int) $l->fk_id_product ?: (int) DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $l->sku)->value('id');
            if (! $pid) { $rows[] = ['sku' => $l->sku, 'qty' => $q, 'missing' => ['the line is not linked to a product']]; $miss[] = $l->sku; continue; }
            $x = self::product($pid, $q);
            if ($x['plan']['cartons'] === null || ! $x['carton']['cbm']) { $rows[] = ['product' => $pid, 'sku' => $x['sku'], 'name' => $x['name'], 'qty' => $q, 'missing' => array_column($x['missing'], 'label')]; $miss[] = $x['sku']; continue; }
            $rows[] = ['product' => $pid, 'sku' => $x['sku'], 'name' => $x['name'], 'qty' => $q, 'per' => $x['carton']['per'], 'size' => "{$x['carton']['l']}×{$x['carton']['w']}×{$x['carton']['h']} cm",
                'gross' => $x['carton']['gross'], 'gross_estimated' => $x['carton']['gross_estimated'], 'missing' => []] + $x['plan'];
            $t['cartons'] += $x['plan']['cartons']; $t['cbm'] += $x['plan']['cbm']; $t['kg'] += $x['plan']['kg']; if ($x['plan']['tail']) $t['tails']++;
        }
        $t['cbm'] = round($t['cbm'], 3); $t['kg'] = round($t['kg'], 1); $t['air_kg'] = round($t['cbm'] * 1e6 / self::AIR, 1);
        return ['po' => $poId, 'rows' => $rows, 'totals' => $t, 'missing' => array_values(array_unique($miss)),
            'note' => 'Tail carton = the last carton that is not full (950 pcs at 100 per carton = 9 full + 1 tail of 50); its weight = pcs × pack weight + empty carton.'];
    }
}
