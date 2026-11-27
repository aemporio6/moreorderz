<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1408 / MR-0287 one product master (old: Cat_src_products / t_src_products was a second product list beside the products).
 * A sourcing product is linked to its product (by SKU the first time, or chosen) and the two share what one knows and the other does not:
 * HS code, material, weights, carton size and units per carton, brand, origin country. Only empty fields are filled – a value typed on either side is never overwritten.
 * Runs after every save of either side, so packaging, cartons, customs and the product page read one set of figures.
 */
class SourcingProducts
{
    /** sourcing column => product column */
    public const SHARED = ['hs_code' => 'hs_code', 'material' => 'material', 'unit_net_weight' => 'unit_net_weight', 'unit_gross_weight' => 'unit_gross_weight', 'units_per_carton' => 'units_per_carton',
        'carton_l' => 'carton_l', 'carton_w' => 'carton_w', 'carton_h' => 'carton_h', 'carton_gross_weight' => 'carton_gross_weight', 'carton_cbm' => 'carton_cbm', 'fk_id_brand' => 'fk_id_brand', 'fk_id_country' => 'fk_id_origin_country'];

    private static function blank($v): bool { return $v === null || $v === '' || (is_numeric($v) && (float) $v == 0.0); }

    /** The product of a sourcing product: its own link, else the one product of the company with the same SKU. */
    public static function productOf(object $s): ?int
    {
        if (! empty($s->fk_id_product) && DB::table('t_product_vendors')->where('id', $s->fk_id_product)->exists()) return (int) $s->fk_id_product;
        $sku = trim((string) ($s->sku ?? '')); if ($sku === '') return null;
        $ids = DB::table('t_product_vendors')->where('fk_id_ent', $s->fk_id_ent)->whereRaw('UPPER(TRIM(SKU_VENDOR)) = ?', [mb_strtoupper($sku)])->limit(2)->pluck('id');
        return $ids->count() === 1 ? (int) $ids[0] : null;      // none or unclear: chosen on the sourcing product
    }

    /** Link one sourcing product and share the empty fields both ways. Returns what happened. */
    public static function sync(int $id): array
    {
        $s = DB::table('t_src_products')->where('id', $id)->first(); if (! $s) return ['linked' => null, 'to_product' => [], 'to_sourcing' => []];
        $pid = self::productOf($s); if (! $pid) return ['linked' => null, 'to_product' => [], 'to_sourcing' => []];
        $p = DB::table('t_product_vendors')->where('id', $pid)->first(); $toP = []; $toS = [];
        foreach (self::SHARED as $sc => $pc) {
            if (! Registry::hasColumn('t_src_products', $sc) || ! Registry::hasColumn('t_product_vendors', $pc)) continue;
            $sv = $s->$sc ?? null; $pv = $p->$pc ?? null;
            if (self::blank($pv) && ! self::blank($sv)) $toP[$pc] = $sv; elseif (self::blank($sv) && ! self::blank($pv)) $toS[$sc] = $pv;
        }
        if ((int) ($s->fk_id_product ?? 0) !== $pid) $toS['fk_id_product'] = $pid;
        if ($toP) { DB::table('t_product_vendors')->where('id', $pid)->update($toP); Audit::log('t_product_vendors', $pid, 'UPDATE', array_map(fn ($v) => [null, $v], $toP), 'From sourcing product #' . $id, (int) $s->fk_id_ent); }
        if ($toS) { DB::table('t_src_products')->where('id', $id)->update($toS); Audit::log('t_src_products', $id, 'UPDATE', array_map(fn ($v) => [null, $v], $toS), 'From product #' . $pid, (int) $s->fk_id_ent); }
        return ['linked' => $pid, 'to_product' => array_keys($toP), 'to_sourcing' => array_keys(array_diff_key($toS, ['fk_id_product' => 1]))];
    }

    /** After a product is saved: its sourcing products (linked, or with its SKU) are brought up to date. */
    public static function forProduct(int $pid): int
    {
        $p = DB::table('t_product_vendors')->where('id', $pid)->first(['fk_id_ent', 'SKU_VENDOR']); if (! $p) return 0;
        $ids = DB::table('t_src_products')->where('fk_id_ent', $p->fk_id_ent)->where(fn ($w) => $w->where('fk_id_product', $pid)->orWhere(fn ($x) => $x->whereNull('fk_id_product')->whereRaw('UPPER(TRIM(sku)) = ?', [mb_strtoupper(trim((string) $p->SKU_VENDOR))])))->pluck('id');
        foreach ($ids as $i) self::sync((int) $i);
        return $ids->count();
    }

    public static function syncAll(int $ent): array
    {
        $n = ['linked' => 0, 'not linked' => 0];
        foreach (DB::table('t_src_products')->where('fk_id_ent', $ent)->pluck('id') as $i) $n[self::sync((int) $i)['linked'] ? 'linked' : 'not linked']++;
        return $n;
    }
}
