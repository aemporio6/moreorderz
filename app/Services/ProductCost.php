<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-0940 / R-D-11 the landing price of a product, one rule for every screen (old: a typed field on the product, children empty):
 *   1. the product's own landing price (set by hand, by the GRN and by a landed-cost version);
 *   2. else the cost of its latest lot that came in (what one unit really cost);
 *   3. else the parent's landing price (a child of a family without its own cost);
 *   4. else the raw price (what the supplier charges, before freight and duty).
 * Each answer says where it came from, so a figure is never a silent guess.
 */
class ProductCost
{
    public const FROM = ['own' => 'its landing price', 'lot' => 'its latest lot', 'parent' => 'the parent product', 'raw' => 'the raw price (no landing cost yet)', 'none' => 'no cost yet'];

    /** [product id => ['value' => float, 'from' => own|lot|parent|raw|none]] */
    public static function many(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (! $ids) return [];
        $p = DB::table('t_product_vendors')->whereIn('id', $ids)->get(['id', 'landing_price', 'raw_price', 'f_id_parent'])->keyBy('id');
        $lot = DB::table('fin_lots')->whereIn('id', DB::table('fin_lots')->whereIn('fk_id_product', $ids)->where('unit_cost_gbp', '>', 0)->whereNull('voided_at')->selectRaw('MAX(id)')->groupBy('fk_id_product'))
            ->pluck('unit_cost_gbp', 'fk_id_product');
        $par = $p->pluck('f_id_parent')->filter()->unique()->values()->all();
        $parent = $par ? DB::table('t_product_vendors')->whereIn('id', $par)->pluck('landing_price', 'id') : collect();
        $out = [];
        foreach ($ids as $id) { $r = $p[$id] ?? null;
            $out[$id] = match (true) {
                $r && (float) $r->landing_price > 0 => ['value' => round((float) $r->landing_price, 4), 'from' => 'own'],
                isset($lot[$id]) => ['value' => round((float) $lot[$id], 4), 'from' => 'lot'],
                $r && $r->f_id_parent && (float) ($parent[$r->f_id_parent] ?? 0) > 0 => ['value' => round((float) $parent[$r->f_id_parent], 4), 'from' => 'parent'],
                $r && (float) $r->raw_price > 0 => ['value' => round((float) $r->raw_price, 4), 'from' => 'raw'],
                default => ['value' => 0.0, 'from' => 'none'] };
        }
        return $out;
    }

    public static function of(int $id): array { return self::many([$id])[$id] ?? ['value' => 0.0, 'from' => 'none']; }
    public static function value(int $id): float { return (float) self::of($id)['value']; }
}
