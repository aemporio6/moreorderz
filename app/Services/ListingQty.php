<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-20 stock quantity per listing (outbound). Quantity = floor((available of the product − buffer) ÷ pack qty); a bundle = the smallest
 * floor((available − buffer) ÷ component qty) over its components; never below 0. Buffer: the listing's own override, else the shop's marketplace
 * profile (stock_buffer_units); the profile also says how the figure leaves (stock_sync_mode OFF / FILE / API) and what 0 means (oos_action ZERO / END).
 * FILE (and API until a channel connector pushes it) = the listing-quantity export of the shop.
 */
class ListingQty
{
    public static function profile(int $shopId): ?object
    {
        $pid = DB::table('t_branches')->where('id', $shopId)->value('fk_id_mp_profile');
        return $pid ? DB::table('mp_profiles')->where('id', $pid)->first(['stock_sync_mode', 'stock_buffer_units', 'oos_action']) : null;
    }

    public static function qty(object $l, ?object $profile = null): int
    {
        $buffer = $l->stock_buffer_override !== null ? (int) $l->stock_buffer_override : (int) ($profile->stock_buffer_units ?? 0);
        if ((int) $l->f_bundle) {
            $comps = DB::table('t_listing_components')->where('fk_id_listing', $l->id)->where('f_active', 1)->get(['fk_id_product', 'qty_per_unit']);
            if ($comps->isEmpty()) return 0;
            return max(0, (int) $comps->map(fn ($c) => intdiv(max(0, StockService::available((int) $c->fk_id_product) - $buffer), max(1, (int) $c->qty_per_unit)))->min());
        }
        return max(0, intdiv(max(0, StockService::available((int) $l->fk_id_product) - $buffer), max(1, (int) $l->pack_qty)));
    }

    /** The shop's listings with the quantity to push and what a 0 does. */
    public static function rows(int $ent, int $shopId): array
    {
        $p = self::profile($shopId); $mode = $p->stock_sync_mode ?? 'OFF'; $oos = $p->oos_action ?? 'ZERO';
        return ['mode' => $mode, 'oos_action' => $oos, 'buffer' => (int) ($p->stock_buffer_units ?? 0), 'rows' => DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where('f_active', 1)->where('status', 'ACTIVE')->orderBy('marketplace_sku')->get()
            ->map(function ($l) use ($p, $oos) { $q = self::qty($l, $p); return ['id' => (int) $l->id, 'sku' => $l->marketplace_sku, 'item_id' => $l->item_id, 'pack' => (int) $l->pack_qty, 'bundle' => (bool) $l->f_bundle, 'qty' => $q, 'action' => $q === 0 && $oos === 'END' ? 'END' : 'SET']; })->all()];
    }

    /** The listing-quantity export (sync mode FILE): channel SKU, item id, quantity, action. */
    public static function csv(int $ent, int $shopId): string
    {
        $r = self::rows($ent, $shopId); $out = "sku,item_id,quantity,action\n";
        foreach ($r['rows'] as $x) $out .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$x['sku'], $x['item_id'], $x['qty'], $x['action']])) . "\n";
        return $out;
    }
}
