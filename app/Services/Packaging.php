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

    // ---------- the supplier's packing list → the product's packaging ("shall I put it in?") ----------

    /**
     * Read a packing list (text from a PDF / Word, or a table: tab or comma separated): per product found by its SKU – units per carton, carton size, gross
     * and net weight per carton. A table is read by its headers (SKU / Item, PCS/CTN, Carton size / Meas., G.W., N.W., L / W / H); a text line by its
     * patterns (60x50x40 cm, 100 pcs/ctn, G.W. 25 kg). Sizes over 200 are taken as mm. Only what is in the list is returned.
     */
    public static function readList(int $ent, string $text): array
    {
        $skus = []; foreach (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereRaw('CHAR_LENGTH(SKU_VENDOR) >= 3')->limit(20000)->pluck('SKU_VENDOR', 'id') as $id => $sku) $skus[mb_strtoupper((string) $sku)] = (int) $id;
        if (! $skus) return [];
        $num = fn ($v) => is_numeric($v = str_replace(',', '.', preg_replace('/[^\d.,]/', '', (string) $v))) ? (float) $v : null;
        $dims = function (string $v): ?array { if (! preg_match('/(\d+(?:[.,]\d+)?)\s*[x×*X]\s*(\d+(?:[.,]\d+)?)\s*[x×*X]\s*(\d+(?:[.,]\d+)?)/u', $v, $m)) return null;
            $d = array_map(fn ($x) => (float) str_replace(',', '.', $x), [$m[1], $m[2], $m[3]]); if (max($d) > 200) $d = array_map(fn ($x) => round($x / 10, 1), $d); return $d; };
        $skuIn = function (string $line) use ($skus): ?string { $u = mb_strtoupper($line); $best = null;
            foreach ($skus as $s => $id) if (mb_strlen($s) > mb_strlen((string) $best) && preg_match('~(?<![A-Z0-9])' . preg_quote($s, '~') . '(?![A-Z0-9])~u', $u)) $best = $s; return $best; };
        $out = []; $head = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (trim($line) === '') continue; $cells = str_contains($line, "\t") ? explode("\t", $line) : (substr_count($line, ',') >= 3 ? str_getcsv($line) : null);
            if ($cells) { $h = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $cells);
                if (count(array_filter($h, fn ($c) => preg_match('/pcs ?\/ ?ctn|qty ?\/ ?ctn|per carton|carton size|meas|g\.?\s?w|gross|n\.?\s?w|net|sku|item|model/u', $c))) >= 2) { $head = $h; continue; } }
            $sku = $skuIn($line); if (! $sku) continue; $id = $skus[$sku]; $v = $out[$id] ?? ['product' => $id, 'sku' => $sku];
            if ($cells && $head) foreach ($cells as $i => $c) { $hh = $head[$i] ?? '';
                if (preg_match('/pcs ?\/ ?ctn|qty ?\/ ?ctn|per carton|units ?\/ ?ctn|inner/u', $hh) && $num($c)) $v['units_per_carton'] = (int) $num($c);
                elseif (preg_match('/carton size|meas|dimension|ctn size/u', $hh) && ($d = $dims((string) $c))) [$v['carton_l'], $v['carton_w'], $v['carton_h']] = $d;
                elseif (preg_match('/g\.?\s?w|gross/u', $hh) && $num($c)) $v['carton_gross_weight'] = $num($c);
                elseif (preg_match('/^l(ength)?\b|^ctn ?l/u', $hh) && $num($c)) $v['carton_l'] = $num($c);
                elseif (preg_match('/^w(idth)?\b|^ctn ?w/u', $hh) && $num($c)) $v['carton_w'] = $num($c);
                elseif (preg_match('/^h(eight)?\b|^ctn ?h/u', $hh) && $num($c)) $v['carton_h'] = $num($c); }
            if (! isset($v['carton_l']) && ($d = $dims($line))) [$v['carton_l'], $v['carton_w'], $v['carton_h']] = $d;
            if (! isset($v['units_per_carton']) && preg_match('/(\d+)\s*(?:pcs|pc|units|sets)\s*\/\s*(?:ctn|carton|box)/iu', $line, $m)) $v['units_per_carton'] = (int) $m[1];
            if (! isset($v['carton_gross_weight']) && preg_match('/g\.?\s?w\.?\s*[:\-]?\s*(\d+(?:[.,]\d+)?)\s*kg/iu', $line, $m)) $v['carton_gross_weight'] = (float) str_replace(',', '.', $m[1]);
            if (count($v) > 2) $out[$id] = $v;
        }
        return array_values($out);
    }

    /** For each product read from the list: what would change (only real differences). */
    public static function proposal(int $ent, string $text): array
    {
        $rows = [];
        foreach (self::readList($ent, $text) as $x) {
            $cur = (array) DB::table('t_product_vendors')->where('id', $x['product'])->first(['units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_gross_weight']); $ch = [];
            foreach (array_diff_key($x, ['product' => 1, 'sku' => 1]) as $k => $v) if ((float) ($cur[$k] ?? 0) != (float) $v) $ch[$k] = ['from' => $cur[$k] ?? null, 'to' => $v];
            if ($ch) $rows[] = ['product' => $x['product'], 'sku' => $x['sku'], 'changes' => $ch];
        }
        return $rows;
    }

    /** Put the packing list's figures in the products (the CBM follows by itself); every change is in the product's history. */
    public static function apply(int $ent, array $rows, ?object $u): array
    {
        Perm::need($u, 'form:' . (RecordService::formOfTable('t_product_vendors') ?: 't_product_vendor') . ':EDIT'); $n = 0;
        foreach ($rows as $r) {
            $pid = (int) ($r['product'] ?? 0); if (! DB::table('t_product_vendors')->where('id', $pid)->where('fk_id_ent', $ent)->exists()) continue;
            $set = []; foreach ((array) ($r['changes'] ?? []) as $k => $c) if (in_array($k, ['units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_gross_weight'], true) && is_numeric($c['to'] ?? null)) $set[$k] = $c['to'];
            if (! $set) continue;
            $old = (array) DB::table('t_product_vendors')->where('id', $pid)->first(array_keys($set));
            $all = array_merge((array) DB::table('t_product_vendors')->where('id', $pid)->first(['carton_l', 'carton_w', 'carton_h']), $set);
            if ((float) $all['carton_l'] && (float) $all['carton_w'] && (float) $all['carton_h']) $set['carton_cbm'] = round($all['carton_l'] * $all['carton_w'] * $all['carton_h'] / 1e6, 4);
            DB::table('t_product_vendors')->where('id', $pid)->update($set);
            Audit::log('t_product_vendors', $pid, 'UPDATE', array_map(fn ($k) => [$old[$k] ?? null, $set[$k]], array_combine(array_keys($set), array_keys($set))), 'From the supplier\'s packing list', $ent);
            $n++;
        }
        return ['message' => $n ? "$n product(s) have the packing list's carton figures now." : 'Nothing to change.', 'updated' => $n];
    }

    /** A shipment of a PO: empty planned cartons / CBM / gross weight are filled from the PO's packing (never over what was typed). */
    public static function fillShipment(array &$data, array $old): void
    {
        $po = (int) ($data['fk_id_po'] ?? $old['fk_id_po'] ?? 0); if (! $po) return; $ent = (int) (DB::table('t_src_po')->where('id', $po)->value('fk_id_ent') ?? 0); if (! $ent) return;
        try { $t = self::po($ent, $po)['totals']; } catch (\Throwable $e) { return; } if (! $t['cartons']) return;
        $empty = fn ($k) => ! is_numeric($data[$k] ?? $old[$k] ?? null) || (float) ($data[$k] ?? $old[$k] ?? 0) == 0.0;
        foreach (['planned_cartons' => $t['cartons'], 'total_cbm' => $t['cbm'], 'total_gross_weight' => $t['kg']] as $k => $v) if (Registry::hasColumn('t_src_shipments', $k) && $empty($k)) $data[$k] = $v;
    }
}
