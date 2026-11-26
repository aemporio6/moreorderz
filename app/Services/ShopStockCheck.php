<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Inventory step (d), the rest: each shop's quantity against what we can really sell.
 *   should = ListingQty::qty – available in the sellable warehouse bins (less reservations), less the buffer, ÷ pack, bundles by their parts
 *   shown  = the quantity the marketplace showed in the shop's last listings report (t_product_listings.listed_qty, from the listings import)
 * The lines: OVERSELL (the shop shows more than we have – orders we cannot send), SHORT (we have more than it shows – sales lost),
 * MATCH, NOT REPORTED (no report with a quantity yet). FBA listings are not merchant stock – they are checked in FBA check instead.
 * The fix is offered on the line: the shop's quantity file (sync FILE) or the 0-quantity file – one press, from the same rules.
 */
class ShopStockCheck
{
    public static function rows(int $ent, object $u, ?int $shopOnly = null, string $state = ''): array
    {
        $shops = Perm::shops($u);
        $q = DB::table('t_product_listings as l')->join('t_branches as b', 'b.id', '=', 'l.fk_id_branch')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')
            ->where('l.fk_id_ent', $ent)->where('l.f_active', 1)->where('l.status', 'ACTIVE')->where('b.f_active', 1)
            ->where(fn ($w) => $w->whereNull('l.fulfilment')->orWhere('l.fulfilment', '<>', 'FBA'))->where(fn ($w) => $w->whereNull('l.fulfilment_channel')->orWhereNotIn('l.fulfilment_channel', ['FBA', 'AFN', 'AMAZON']));
        if ($shops !== null) $q->whereIn('l.fk_id_branch', $shops ?: [0]);
        if ($shopOnly) $q->where('l.fk_id_branch', $shopOnly);
        $list = $q->orderBy('b.shop_name')->orderBy('l.marketplace_sku')->limit(5000)
            ->get(['l.*', 'b.shop_name', 'p.SKU_VENDOR as our_sku', 'p.product_name_vendor as pname', 'p.product_img']);
        $prof = []; $rows = []; $tot = ['OVERSELL' => 0, 'SHORT' => 0, 'MATCH' => 0, 'NOT_REPORTED' => 0]; $byShop = [];
        foreach ($list as $l) {
            $p = $prof[$l->fk_id_branch] ??= (ListingQty::profile((int) $l->fk_id_branch) ?? (object) ['stock_sync_mode' => 'OFF', 'stock_buffer_units' => 0, 'oos_action' => 'ZERO']);
            $should = $l->fk_id_product || (int) $l->f_bundle ? ListingQty::qty($l, $p) : 0;
            $shown = $l->listed_qty !== null ? (int) $l->listed_qty : null;
            $st = $shown === null ? 'NOT_REPORTED' : ($shown > $should ? 'OVERSELL' : ($shown < $should ? 'SHORT' : 'MATCH'));
            $tot[$st]++; $s = &$byShop[(int) $l->fk_id_branch]; $s ??= ['shop' => (int) $l->fk_id_branch, 'name' => $l->shop_name, 'mode' => $p->stock_sync_mode ?? 'OFF', 'OVERSELL' => 0, 'SHORT' => 0, 'MATCH' => 0, 'NOT_REPORTED' => 0, 'report_at' => null];
            $s[$st]++; if ($l->listed_qty_at && (! $s['report_at'] || $l->listed_qty_at > $s['report_at'])) $s['report_at'] = substr((string) $l->listed_qty_at, 0, 16); unset($s);
            if ($state !== '' && $state !== $st) continue;
            $rows[] = ['listing' => (int) $l->id, 'shop' => (int) $l->fk_id_branch, 'shop_name' => $l->shop_name, 'sku' => $l->marketplace_sku, 'item_id' => $l->item_id, 'product' => $l->fk_id_product ? (int) $l->fk_id_product : null,
                'our_sku' => $l->our_sku, 'name' => $l->pname ?: $l->listing_title, 'img' => $l->product_img, 'pack' => max(1, (int) $l->pack_qty), 'bundle' => (bool) $l->f_bundle,
                'should' => $should, 'shown' => $shown, 'diff' => $shown === null ? null : $shown - $should, 'state' => $st, 'at' => $l->listed_qty_at ? substr((string) $l->listed_qty_at, 0, 16) : null,
                'why' => match ($st) {
                    'OVERSELL' => "the shop shows $shown, we can send $should – an order above $should cannot be sent",
                    'SHORT' => "we can sell $should, the shop shows only $shown – sales are being lost",
                    'MATCH' => 'the shop shows what we have',
                    default => 'no listings report with a quantity yet – load the shop\'s active listings file (Products › Listings import)' }];
        }
        $order = ['OVERSELL' => 0, 'SHORT' => 1, 'NOT_REPORTED' => 2, 'MATCH' => 3];
        usort($rows, fn ($a, $b) => [$order[$a['state']], -abs((int) $a['diff'])] <=> [$order[$b['state']], -abs((int) $b['diff'])]);
        return ['rows' => array_slice($rows, 0, 1500), 'count' => count($rows), 'totals' => $tot, 'shops' => array_values($byShop),
            'note' => 'Should show = available in sellable bins less reservations and the shop\'s buffer, ÷ pack (bundles: their scarcest part). FBA listings are checked in FBA check.'];
    }
}
