<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-87 marketplace fulfilment centres and the fulfilment channel.
 * - Per shop one FC parent (kind MARKETPLACE_FULFILMENT, "<shop no> FC") made from the shop profile's fulfilment programme, with destination children (the centres named on inbound shipments);
 *   stock is held on the children, the parent sums them.
 * - The channel of a sale line picks its default selling location: SELF → MAIN_WAREHOUSE (else the shop's main location), MARKETPLACE_FC → the shop's FC parent, THREE_PL → the shop's THREE_PL location, DROPSHIP → none.
 * - Month Confirm takes MARKETPLACE_FC / THREE_PL lines from those locations (FC: its children, oldest lot first); DROPSHIP never takes stock.
 */
class FcLocations
{
    public const CHANNELS = ['SELF', 'MARKETPLACE_FC', 'THREE_PL', 'DROPSHIP', 'MIXED'];

    public static function parent(int $shopId, bool $create = true): ?int
    {
        $s = DB::table('t_branches')->where('id', $shopId)->first(['id', 'fk_id_ent', 'shop_no', 'shop_name', 'fk_id_mp_profile']); if (! $s) return null;
        $id = DB::table('cat_inventory_locations')->where('fk_id_branch', $shopId)->where('kind', 'MARKETPLACE_FULFILMENT')->where(fn ($w) => $w->whereNull('fk_id_parent')->orWhere('fk_id_parent', 0))->value('id');
        if ($id || ! $create) return $id ? (int) $id : null;
        $prog = $s->fk_id_mp_profile ? DB::table('mp_profiles')->where('id', $s->fk_id_mp_profile)->value('fulfilment_programme') : null;
        return (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['fk_id_ent' => $s->fk_id_ent, 'location_name' => trim(($s->shop_no ?: $s->shop_name) . ' FC'), 'location_code' => mb_substr(($s->shop_no ?: 'S' . $s->id) . '-FC', 0, 30),
            'kind' => 'MARKETPLACE_FULFILMENT', 'fk_id_branch' => $shopId, 'f_sellable' => 1, 'f_active' => 1, 'address_text' => $prog ? "Fulfilment programme $prog" : null, 'created_at' => now()]));
    }

    /** The destination centre (child of the shop's FC parent) of that name; made on first use. */
    public static function child(int $shopId, string $centre): int
    {
        $centre = mb_strtoupper(trim($centre)); if ($centre === '') throw new \InvalidArgumentException('Name the destination centre.');
        $p = self::parent($shopId); $ent = (int) DB::table('t_branches')->where('id', $shopId)->value('fk_id_ent');
        $id = DB::table('cat_inventory_locations')->where('fk_id_parent', $p)->where('location_name', $centre)->value('id');
        return $id ? (int) $id : (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['fk_id_ent' => $ent, 'location_name' => $centre, 'location_code' => mb_substr($centre, 0, 30), 'kind' => 'MARKETPLACE_FULFILMENT',
            'fk_id_parent' => $p, 'fk_id_branch' => $shopId, 'f_sellable' => 1, 'f_active' => 1, 'created_at' => now()]));
    }

    /** Locations that hold the stock of a channel for a shop (FC: the parent's children, else the parent itself). */
    public static function holding(int $shopId, string $channel): array
    {
        if ($channel === 'MARKETPLACE_FC') { $p = self::parent($shopId, false); if (! $p) return []; $kids = DB::table('cat_inventory_locations')->where('fk_id_parent', $p)->where('f_active', 1)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all(); return $kids ?: [$p]; }
        if ($channel === 'THREE_PL') return DB::table('cat_inventory_locations')->where('fk_id_branch', $shopId)->where('kind', 'THREE_PL')->where('f_active', 1)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
        return [];
    }

    /** The default selling location of a sale line's channel. */
    public static function sellLocation(object $shop, string $channel): ?int
    {
        return match ($channel) {
            'MARKETPLACE_FC' => self::parent((int) $shop->id),
            'THREE_PL' => self::holding((int) $shop->id, 'THREE_PL')[0] ?? null,
            'DROPSHIP' => null,
            default => (int) (Settings::get('MAIN_WAREHOUSE', (int) $shop->fk_id_ent, '0') ?: 0) ?: ($shop->fk_id_main_location ? (int) $shop->fk_id_main_location : null),
        };
    }

    /** Units of a product at the channel's locations. */
    public static function units(int $shopId, string $channel, int $productId): int
    {
        $n = 0; foreach (self::holding($shopId, $channel) as $loc) $n += StockService::balance($productId, $loc); return $n;
    }

    /** Month Confirm of an FC / 3PL line: oldest lot first across the holding locations; returns the cost. */
    public static function consume(int $ent, object $s, int $qty, ?int $owner, string $date): float
    {
        $locs = self::holding((int) $s->fk_id_branch, (string) $s->fulfilment_channel); $left = $qty; $cost = 0.0;
        $oldest = fn ($loc) => (string) (DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $loc)->where('l.fk_id_product', $s->fk_product_id)->where('b.qty', '>', 0)->min('l.received_date') ?? '9999');
        usort($locs, fn ($a, $b) => strcmp($oldest($a), $oldest($b)));
        foreach ($locs as $loc) {
            if ($left <= 0) break; $have = StockService::balance((int) $s->fk_product_id, $loc); if ($have <= 0) continue;
            $take = min($left, $have); $c = StockService::consumeForSale($ent, (int) $s->id, (int) $s->fk_product_id, $take, $loc, $owner, ['date' => $date]); $cost += (float) $c['cost']; $left -= $take;
        }
        if ($left > 0) throw new \RuntimeException("Order {$s->order_no}: {$left} × {$s->sku} not at the fulfilment location of this shop – book the stock there first (inbound shipment) or correct the channel.");
        return round($cost, 2);
    }
}
