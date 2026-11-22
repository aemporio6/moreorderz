<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-19 a bundle listing (f_bundle) is made of several SKUs (t_listing_components: product, qty per listing unit).
 * Reservation and Month Confirm expand the sale line per component: units = order qty × qty per unit; cost at Confirm = Σ components' lot cost.
 * (A pack listing – pack_qty – is already expanded at intake: the sale line carries qty × pack of the one product.)
 */
class Bundles
{
    /** [[product, qty per unit]] of the sale's bundle listing, or [] for a normal line. */
    public static function of(object $sale): array
    {
        if (! $sale->fk_id_listing) return [];
        $l = DB::table('t_product_listings')->where('id', $sale->fk_id_listing)->first(['f_bundle']); if (! $l || ! (int) $l->f_bundle) return [];
        return DB::table('t_listing_components')->where('fk_id_listing', $sale->fk_id_listing)->where('f_active', 1)->orderBy('id')->get(['fk_id_product', 'qty_per_unit'])
            ->map(fn ($c) => [(int) $c->fk_id_product, max(1, (int) $c->qty_per_unit)])->all();
    }

    /** One reservation per component (short per component); the line is SHORT when any component is short. */
    public static function reserve(object $s, array $comps): ?int
    {
        $ent = (int) $s->fk_id_ent; $owner = DispatchService::ownerRule($s); $shortAll = 0; $first = null; $msg = [];
        foreach ($comps as [$pid, $per]) {
            DB::table('t_product_vendors')->where('id', $pid)->lockForUpdate()->first();
            $r = DB::table('wh_reservations')->where('fk_id_sale', $s->id)->where('fk_id_product', $pid)->whereNotIn('status', ['RELEASED', 'SHIPPED'])->first();
            $have = $r ? (int) $r->qty : 0; $need = (int) $s->qty * $per;
            $qty = $need > $have ? $have + min($need - $have, StockService::available($pid)) : $need; $short = $need - $qty;
            if ($r) DB::table('wh_reservations')->where('id', $r->id)->update(['qty' => $qty, 'qty_short' => $short, 'updated_at' => now()]);
            else $r = (object) ['id' => DB::table('wh_reservations')->insertGetId(['fk_id_ent' => $ent, 'fk_id_branch' => $s->fk_id_branch, 'fk_id_site' => (int) ($s->fk_id_sell_location ?: 0), 'fk_id_sale' => $s->id, 'fk_id_product' => $pid,
                'owner_rule' => $owner ? 'PRIVATE' : 'POOL', 'fk_id_owner_seg' => $owner ?? 0, 'qty' => $qty, 'qty_short' => $short, 'qty_picked' => 0, 'status' => 'OPEN', 'reason' => 'Bundle component', 'dispatch_by' => $s->dispatch_by, 'created_by' => Context::userId(), 'created_at' => now(), 'updated_at' => now()])];
            $first ??= (int) $r->id; $shortAll += $short; if ($short) $msg[] = "$short × " . DB::table('t_product_vendors')->where('id', $pid)->value('SKU_VENDOR');
        }
        DB::table('t_sales')->where('id', $s->id)->update(['wh_status' => $shortAll > 0 ? 'SHORT' : 'RESERVED']);
        if ($shortAll > 0) TaskEngine::open('WH_ORDER_SHORT', [$s->id], "Order {$s->order_no} (bundle {$s->sku}): " . implode(', ', $msg) . ' not in stock', ['ent' => $ent, 'module' => 't_sales', 'record_id' => (int) $s->id]);
        else TaskEngine::close('WH_ORDER_SHORT', [$s->id], 'Stock reserved');
        EventBus::fire('STOCK_RESERVED', 't_sales', (int) $s->id, ['bundle' => true, 'short' => $shortAll], $ent);
        return $first;
    }

    /** Month Confirm of a bundle line: each component leaves stock (from the shelf when not shipped through the tool); returns the summed lot cost. */
    public static function confirm(int $ent, object $s, array $comps, int $shipped, ?int $owner, string $postDate, callable $shelf): float
    {
        $cost = 0.0;
        foreach ($comps as [$pid, $per]) {
            $units = (int) $s->qty * $per;
            $r = DB::table('wh_reservations')->where('fk_id_sale', $s->id)->where('fk_id_product', $pid)->orderByDesc('id')->first();
            $have = $r && $r->status === 'SHIPPED' ? $units : 0;
            if ($units - $have > 0) {
                DB::table('wh_reservations')->where('fk_id_sale', $s->id)->where('fk_id_product', $pid)->whereIn('status', ['OPEN', 'ALLOCATED', 'PICKED'])->update(['status' => 'SHIPPED', 'release_reason' => 'Booked as shipped at Month Confirm', 'updated_at' => now()]);
                foreach ($shelf($pid, $units - $have) as [$loc, $q]) StockService::transfer($ent, $pid, $q, $loc, $shipped, 'Backfill at Month Confirm (bundle)', ['rule' => $owner ? 'PRIVATE' : 'POOL', 'owner' => $owner, 'source_type' => 'SHIP', 'source_id' => $s->id]);
            }
            $c = StockService::consumeForSale($ent, (int) $s->id, $pid, $units, $shipped, $owner, ['date' => $postDate]); $cost += (float) $c['cost'];
        }
        return round($cost, 2);
    }
}
