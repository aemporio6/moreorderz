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

    /**
     * R-H-20 "at once": the product reached 0 → every active listing that uses it (own listing or bundle component) of a shop whose profile syncs stock gets a push row (qty 0, ZERO or END)
     * right away. API shops: the row waits for the channel connector (none pushes yet – said in the note); FILE shops: task LIST_OOS_PUSH asks to upload the 0-quantity file now.
     */
    public static function pushZero(int $productId): int
    {
        $ent = (int) DB::table('t_product_vendors')->where('id', $productId)->value('fk_id_ent'); $n = 0;
        $bundles = DB::table('t_listing_components')->where('fk_id_product', $productId)->where('f_active', 1)->pluck('fk_id_listing')->all();
        foreach (DB::table('t_product_listings')->where('f_active', 1)->where('status', 'ACTIVE')->where(fn ($w) => $w->where('fk_id_product', $productId)->orWhereIn('id', $bundles ?: [0]))->get() as $l) {
            $p = self::profile((int) $l->fk_id_branch); $mode = $p->stock_sync_mode ?? 'OFF'; if ($mode === 'OFF') continue;
            if (self::qty($l, $p) > 0) continue;      /* a bundle may still be sellable from other stock rules – only a real 0 is pushed */
            if (DB::table('stock_push_queue')->where('fk_id_listing', $l->id)->where('status', 'PENDING')->where('qty', 0)->exists()) continue;
            DB::table('stock_push_queue')->insert(['fk_id_ent' => $ent ?: (int) $l->fk_id_ent, 'fk_id_branch' => $l->fk_id_branch, 'fk_id_listing' => $l->id, 'qty' => 0, 'action' => ($p->oos_action ?? 'ZERO') === 'END' ? 'END' : 'ZERO', 'via' => $mode, 'status' => 'PENDING', 'reason' => 'PRODUCT_OOS',
                'note' => $mode === 'API' ? 'Waiting for the channel connector – no connector pushes quantities yet; it is in the 0-quantity file meanwhile.' : null, 'created_at' => now()]);
            $n++;
            $shop = DB::table('t_branches')->where('id', $l->fk_id_branch)->value('shop_name');
            TaskEngine::open('LIST_OOS_PUSH', [(int) $l->fk_id_branch], "$shop: a product is out of stock – upload the 0-quantity file now", ['ent' => $ent ?: (int) $l->fk_id_ent, 'module' => 't_branches', 'record_id' => (int) $l->fk_id_branch, 'fix_url' => '#/listings-import']);
        }
        return $n;
    }

    /** The urgent file of a shop: the waiting 0-quantity rows; downloading marks them EXPORTED and closes LIST_OOS_PUSH. A preview (?dry=1) never writes. */
    public static function zeroCsv(int $ent, int $shopId, bool $dry = false): string
    {
        $rows = DB::table('stock_push_queue as q')->join('t_product_listings as l', 'l.id', '=', 'q.fk_id_listing')->where('q.fk_id_ent', $ent)->where('q.fk_id_branch', $shopId)->where('q.status', 'PENDING')->orderBy('q.id')->get(['q.id', 'l.marketplace_sku', 'l.item_id', 'q.qty', 'q.action']);
        $out = "sku,item_id,quantity,action\n";
        foreach ($rows as $x) $out .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$x->marketplace_sku, $x->item_id, $x->qty, $x->action])) . "\n";
        if (! $dry && $rows->isNotEmpty()) { DB::table('stock_push_queue')->whereIn('id', $rows->pluck('id'))->update(['status' => 'EXPORTED', 'done_at' => now()]); TaskEngine::close('LIST_OOS_PUSH', [$shopId], 'File downloaded'); }
        return $out;
    }

    /** The listing-quantity export (sync mode FILE): channel SKU, item id, quantity, action. */
    public static function csv(int $ent, int $shopId): string
    {
        $r = self::rows($ent, $shopId); $out = "sku,item_id,quantity,action\n";
        foreach ($r['rows'] as $x) $out .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$x['sku'], $x['item_id'], $x['qty'], $x['action']])) . "\n";
        return $out;
    }
}
