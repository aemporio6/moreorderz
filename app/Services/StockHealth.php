<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-28 Inventory health card "Inventory Management": source = product stock (t_product_vendors.stock, active products).
 * Rows: Units on hand (Private / Pool) when the split gives any units – a product is Private when it is private on any marketplace × shop (K5),
 * Pool when it has a pool row and no private one – else "Total units on hand"; Below reorder level; Out of stock; No stock location;
 * Landing price (WAC) empty; Stock mismatches (stock vs locations: product stock ≠ Σ location qty, also stock > 0 with no location rows).
 * Each row links to the product list filtered by that condition (#/stock/health?c=<key>). "No location", "no cost" and "mismatch" are also
 * Month-Close checks with their own tasks STK_NO_LOCATION / STK_NO_COST / STK_LEDGER_MISMATCH (CycleAlerts::sweep, StockService::selfCheck).
 */
class StockHealth
{
    /** key => [label, "items" word] (old labels) */
    public const ROWS = [
        'low' => ['Below reorder level', true], 'oos' => ['Out of stock', true], 'noloc' => ['No stock location', true],
        'nocost' => ['Landing price (WAC) empty', false], 'mismatch' => ['Stock mismatches (stock vs locations)', false],
    ];

    /** Σ location qty per product (lots with a location). */
    private static function locSql(): string
    {
        return '(SELECT COALESCE(SUM(b.qty), 0) FROM fin_lot_balances b JOIN fin_lots l ON l.id = b.fk_id_lot WHERE l.fk_id_product = p.id AND b.fk_id_location IS NOT NULL)';
    }

    private static function base(int $ent)
    {
        return DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where('p.f_active', 1);
    }

    /** The condition of one row as a where on the products query. */
    private static function where($q, string $key)
    {
        $loc = self::locSql();
        return match ($key) {
            'low' => $q->where('p.min_level', '>', 0)->whereColumn('p.stock', '<=', 'p.min_level'),
            'oos' => $q->where(fn ($w) => $w->where('p.stock', '<=', 0)->orWhereNull('p.stock')->orWhere('p.f_out_of_stock', 1)),
            'noloc' => $q->where('p.stock', '>', 0)->whereRaw("NOT EXISTS (SELECT 1 FROM fin_lot_balances b JOIN fin_lots l ON l.id = b.fk_id_lot WHERE l.fk_id_product = p.id AND b.qty > 0 AND b.fk_id_location IS NOT NULL)"),
            'nocost' => $q->where(fn ($w) => $w->whereNull('p.landing_price')->orWhere('p.landing_price', '<=', 0)),
            'mismatch' => $q->whereRaw("COALESCE(p.stock, 0) <> $loc")->where(fn ($w) => $w->where('p.stock', '>', 0)->orWhereRaw("$loc <> 0")),
            default => throw new \InvalidArgumentException('Unknown condition.'),
        };
    }

    public static function count(int $ent, string $key): int { return (int) self::where(self::base($ent), $key)->count(); }

    /** Private / Pool units (K5: private on any marketplace × shop = Private). */
    public static function split(int $ent): array
    {
        $priv = "EXISTS (SELECT 1 FROM t_product_visibility v WHERE v.fk_id_product = p.id AND UPPER(v.visibility_mode) = 'PRIVATE')";
        $pool = "EXISTS (SELECT 1 FROM t_product_visibility v WHERE v.fk_id_product = p.id AND UPPER(v.visibility_mode) = 'POOL')";
        $sum = fn ($w) => (int) self::base($ent)->where('p.stock', '>', 0)->whereRaw($w)->sum('p.stock');
        return ['private' => $sum($priv), 'pool' => $sum("$pool AND NOT $priv"), 'total' => (int) self::base($ent)->where('p.stock', '>', 0)->sum('p.stock')];
    }

    public static function card(int $ent): array
    {
        $s = self::split($ent); $rows = [];
        if ($s['private'] + $s['pool'] > 0) { $rows[] = ['key' => 'private', 'label' => 'Units on hand (Private)', 'figure' => number_format($s['private']), 'link' => '#/stock']; $rows[] = ['key' => 'pool', 'label' => 'Units on hand (Pool)', 'figure' => number_format($s['pool']), 'link' => '#/stock']; }
        else $rows[] = ['key' => 'total', 'label' => 'Total units on hand', 'figure' => number_format($s['total']), 'link' => '#/stock'];
        foreach (self::ROWS as $k => [$label, $items]) { $n = self::count($ent, $k); $rows[] = ['key' => $k, 'label' => $label, 'figure' => $items ? "$n items" : (string) $n, 'count' => $n, 'link' => "#/stock/health?c=$k"]; }
        return ['title' => 'Inventory Management', 'rows' => $rows, 'link' => '#/stock'];
    }

    /** The product list behind one row. */
    public static function list(int $ent, string $key, int $limit = 500): array
    {
        if (! isset(self::ROWS[$key])) throw new \InvalidArgumentException('Unknown condition.');
        $loc = self::locSql(); $q = self::where(self::base($ent), $key);
        $total = (clone $q)->count();
        $rows = $q->orderBy('p.SKU_VENDOR')->limit($limit)->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.stock', 'p.min_level', 'p.f_out_of_stock', DB::raw("$loc as at_locations"), DB::raw('CASE WHEN p.landing_price IS NULL OR p.landing_price <= 0 THEN 0 ELSE 1 END as has_cost')])
            ->map(fn ($r) => ['id' => (int) $r->id, 'sku' => $r->sku, 'name' => $r->name, 'stock' => (float) $r->stock, 'at_locations' => (float) $r->at_locations, 'min_level' => (float) $r->min_level, 'out_of_stock_flag' => (bool) $r->f_out_of_stock, 'cost_set' => (bool) $r->has_cost, 'link' => "#/stock/product/{$r->id}"])->all();
        return ['key' => $key, 'title' => self::ROWS[$key][0], 'rows' => $rows, 'total' => $total, 'conditions' => array_map(fn ($r) => $r[0], self::ROWS)];
    }
}
