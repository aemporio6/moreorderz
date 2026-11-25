<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-24 the primary marketplace of a company and the read view over its prices.
 * Setting PRIMARY_MARKETPLACE per company (default: the first active marketplace by id). The view v_product_primary_price gives raw_price,
 * sale_price, list_price, ebay_fee (= marketplace fee), fk_id_car_pkg_svc and fk_id_packing of that marketplace for each product – read from the
 * marketplace rows, so a price change shows at once without touching the product row.
 */
class ProductPrimary
{
    public static function marketplace(int $ent): ?int
    {
        $v = Settings::get('PRIMARY_MARKETPLACE', $ent, null);
        if ($v !== null && ctype_digit((string) $v) && DB::table('cat_marketplaces')->where('id', (int) $v)->exists()) return (int) $v;
        $id = DB::table('cat_marketplaces')->where('f_active', 1)->min('id'); return $id ? (int) $id : null;
    }

    public static function set(int $ent, int $marketplace, object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner sets the primary marketplace.');
        $m = DB::table('cat_marketplaces')->where('id', $marketplace)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Choose an active marketplace.');
        $old = self::marketplace($ent); Settings::set('PRIMARY_MARKETPLACE', (string) $marketplace, $ent);
        Audit::log('sys_settings', $ent, 'UPDATE', ['PRIMARY_MARKETPLACE' => [$old, $marketplace]], null, $ent);
        return ['marketplace' => $marketplace, 'message' => 'Primary marketplace: ' . ($m->marketplace_short ?: $m->marketplace_descr ?: '#' . $m->id) . '. Lists and exports that show "the" price read this marketplace.'];
    }

    /** One product's primary prices (null values when the product has no row on the primary marketplace). */
    public static function of(int $productId): ?array { $r = DB::table('v_product_primary_price')->where('fk_id_product', $productId)->first(); return $r ? (array) $r : null; }

    /** Many products at once, keyed by product id (for grids and exports). */
    public static function many(array $ids): array { return $ids ? DB::table('v_product_primary_price')->whereIn('fk_id_product', $ids)->get()->keyBy('fk_id_product')->map(fn ($r) => (array) $r)->all() : []; }
}
