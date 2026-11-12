<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Price periods (RULES_D §3): the price of a product on a marketplace from a date, kept in t_product_inventory_change_log
 * (change_type price_change). A new price closes the period before it; a sale is matched to the period of its sale date.
 * The current price is also kept on t_product_marketplace_prices (one row per product × marketplace).
 */
class PriceService
{
    public static function set(int $productId, int $marketplaceId, float $rawPrice, float $salePrice, string $fromDate, ?int $reasonId = null, array $extra = []): int
    {
        if ($salePrice < 0 || $rawPrice < 0) throw new \InvalidArgumentException('A price cannot be negative.');
        return DB::transaction(function () use ($productId, $marketplaceId, $rawPrice, $salePrice, $fromDate, $reasonId, $extra) {
            $q = fn () => DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $productId, 'fk_id_marketplace' => $marketplaceId, 'change_type' => 'price_change']);
            if ($q()->where('from_date', $fromDate)->exists()) throw new \RuntimeException("A price already starts on $fromDate for this marketplace. Change that one or pick another date.");
            $prev = $q()->where('from_date', '<', $fromDate)->orderByDesc('from_date')->lockForUpdate()->first();
            $next = $q()->where('from_date', '>', $fromDate)->orderBy('from_date')->first();
            if ($prev) DB::table('t_product_inventory_change_log')->where('id', $prev->id)->update(['to_date' => date('Y-m-d', strtotime($fromDate . ' -1 day'))]);
            $id = (int) DB::table('t_product_inventory_change_log')->insertGetId(['fk_user_id' => Context::userId(), 'changed_by' => (string) (Context::userId() ?? 'system'), 'changed_fields' => json_encode(['sale_price', 'raw_price']),
                'old_price' => $prev->new_price ?? 0, 'new_price' => round($salePrice, 2), 'old_raw_price' => $prev->new_raw_price ?? 0, 'new_raw_price' => round($rawPrice, 2), 'change_type' => 'price_change',
                'fk_id_reason' => $reasonId, 'from_date' => $fromDate, 'to_date' => $next ? date('Y-m-d', strtotime($next->from_date . ' -1 day')) : null, 'fk_product_id' => $productId, 'fk_id_marketplace' => $marketplaceId, 'created_at' => now(),
                'carrier_rate' => $extra['carrier_rate'] ?? null, 'packing_rate' => $extra['packing_rate'] ?? null]);
            if (! $next) {   // the newest period is the current price
                DB::table('t_product_marketplace_prices')->updateOrInsert(['fk_id_product' => $productId, 'fk_id_marketplace' => $marketplaceId],
                    ['raw_price' => round($rawPrice, 2), 'sale_price' => round($salePrice, 2), 'list_price' => $extra['list_price'] ?? round($salePrice, 2), 'marketplace_fee' => $extra['marketplace_fee'] ?? 0, 'created_by' => Context::userId(), 'updated_at' => now()]);
            }
            Audit::log('t_product_vendors', $productId, 'UPDATE', ['price' => [$prev->new_price ?? null, round($salePrice, 2)], 'from' => $fromDate, 'marketplace' => $marketplaceId]);
            EventBus::fire('PRICE_CHANGED', 't_product_vendors', $productId, ['marketplace' => $marketplaceId, 'from' => $fromDate, 'sale_price' => round($salePrice, 2)]);
            return $id;
        });
    }

    /** The price period that covers a date (for matching a sale to its price). Null when no price was set on or before that date. */
    public static function on(int $productId, int $marketplaceId, string $date): ?object
    {
        return DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $productId, 'fk_id_marketplace' => $marketplaceId, 'change_type' => 'price_change'])
            ->where('from_date', '<=', $date)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $date))->orderByDesc('from_date')->first(['id', 'new_price as sale_price', 'new_raw_price as raw_price', 'from_date', 'to_date']);
    }

    public static function timeline(int $productId): array
    {
        return DB::table('t_product_inventory_change_log as c')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'c.fk_id_marketplace')->leftJoin('cat_price_change_reasons as r', 'r.id', '=', 'c.fk_id_reason')
            ->where('c.fk_product_id', $productId)->where('c.change_type', 'price_change')->orderBy('c.fk_id_marketplace')->orderByDesc('c.from_date')
            ->get(['c.id', 'c.fk_id_marketplace', DB::raw('COALESCE(m.' . (Registry::labelColumn('cat_marketplaces') ?: 'id') . ', c.fk_id_marketplace) as marketplace'), 'c.new_raw_price as raw_price', 'c.new_price as sale_price', 'c.from_date', 'c.to_date', 'r.reason_name as reason'])->all();
    }
}
