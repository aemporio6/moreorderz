<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 23:14, inventory steps (c) + (d): Amazon FBA is a real place in the stock ledger – one location per Amazon shop (= per country:
 * UK, France, Canada, USA …), kind FBA. Units there are sold only by that shop (FBA is not merchant stock – it never counts as "available"
 * for other shops). The daily reconciliation compares the last FBA inventory report of each shop with the ledger, SKU by SKU:
 * the difference is listed with its reason, and "Book it" brings the ledger to the report (an adjustment with the report date as reason –
 * found stock at the product's cost, or a loss), or the units can be moved in from a warehouse instead (they were sent and not booked).
 */
class FbaLedger
{
    /** The FBA location of every shop that has FBA reports or is an Amazon shop; made the first time. [shop id => location id] */
    public static function locations(int $ent): array
    {
        $shops = DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->where('b.fk_id_ent', $ent)->where('b.f_active', 1)
            ->where(fn ($w) => $w->where('m.marketplace_short', 'like', 'AMAZON%')->orWhereIn('b.id', DB::table('fin_fba_stock_snapshots')->select('branch_id')))->get(['b.id', 'b.shop_name']);
        $out = [];
        foreach ($shops as $s) {
            $id = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', 'FBA')->where('fk_id_branch', $s->id)->value('id');
            $out[(int) $s->id] = (int) ($id ?: DB::table('cat_inventory_locations')->insertGetId(['location_name' => 'Amazon FBA · ' . $s->shop_name, 'fk_id_location_type' => 0, 'f_active' => 1, 'created_at' => now(),
                'fk_id_ent' => $ent, 'location_code' => 'FBA-' . $s->id, 'kind' => 'FBA', 'f_sellable' => 0, 'fk_id_branch' => $s->id]));
        }
        return $out;
    }

    /** The product of an FBA SKU of a shop: the shop's listing SKU first, then our own SKU. */
    private static function productOf(int $ent, int $shop, string $sku): ?int
    {
        $id = DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop)->where('marketplace_sku', $sku)->value('fk_id_product')
            ?: DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->value('id');
        return $id ? (int) $id : null;
    }

    /** Report against ledger, every SKU of every FBA shop (or one shop). */
    public static function recon(int $ent, ?int $shopOnly = null, ?array $shops = null): array
    {
        $locs = self::locations($ent); $rows = []; $tot = ['report' => 0, 'ledger' => 0, 'diff' => 0, 'unknown' => 0];
        foreach ($locs as $shop => $loc) {
            if ($shopOnly && $shop !== $shopOnly) continue; if ($shops !== null && ! in_array($shop, $shops, true)) continue;
            $last = DB::table('fin_fba_stock_snapshots')->where('branch_id', $shop)->max('snapshot_date'); if (! $last) continue;
            $rep = DB::table('fin_fba_stock_snapshots')->where('branch_id', $shop)->where('snapshot_date', $last)->get(['sku', 'fulfillable', 'reserved', 'inbound_working', 'inbound_shipped', 'inbound_receiving', 'unfulfillable']);
            $ledger = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $loc)->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(b.qty) q')->pluck('q', 'p');
            $seen = []; $shopName = (string) DB::table('t_branches')->where('id', $shop)->value('shop_name');
            foreach ($rep as $x) {
                $pid = self::productOf($ent, $shop, (string) $x->sku); $units = (int) $x->fulfillable + (int) $x->reserved;      // reserved at Amazon = still Amazon's stock of ours (pending orders, FC transfers)
                $have = $pid ? (int) ($ledger[$pid] ?? 0) : 0; if ($pid) $seen[$pid] = 1;
                $rows[] = ['shop' => $shop, 'shop_name' => $shopName, 'location' => $loc, 'sku' => $x->sku, 'product' => $pid, 'name' => $pid ? DB::table('t_product_vendors')->where('id', $pid)->value('product_name_vendor') : null,
                    'report' => $units, 'ledger' => $have, 'diff' => $units - $have, 'inbound' => (int) $x->inbound_working + (int) $x->inbound_shipped + (int) $x->inbound_receiving, 'unsellable' => (int) $x->unfulfillable, 'date' => $last,
                    'why' => ! $pid ? 'This SKU is not a product or a listing of the shop – link it first' : ($units === $have ? 'matches' : ($have === 0 ? 'never booked into FBA in the ledger (opening, or sent without the two-step transfer)' : ($units > $have ? 'Amazon has more than the ledger (found / returns / not booked)' : 'Amazon has less than the ledger (sold, lost or damaged – not booked yet)')))];
                $tot['report'] += $units; $tot['ledger'] += $have; if (! $pid) $tot['unknown']++;
            }
            foreach ($ledger as $pid => $q) if (! isset($seen[$pid]) && (int) $q !== 0) $rows[] = ['shop' => $shop, 'shop_name' => $shopName, 'location' => $loc, 'sku' => DB::table('t_product_vendors')->where('id', $pid)->value('SKU_VENDOR'), 'product' => (int) $pid,
                'name' => DB::table('t_product_vendors')->where('id', $pid)->value('product_name_vendor'), 'report' => 0, 'ledger' => (int) $q, 'diff' => -(int) $q, 'inbound' => 0, 'unsellable' => 0, 'date' => $last, 'why' => 'not in the last FBA report – sold or lost, not booked'];
        }
        foreach ($rows as $r) $tot['diff'] += abs($r['diff']);
        usort($rows, fn ($a, $b) => [$a['diff'] === 0, -abs($a['diff'])] <=> [$b['diff'] === 0, -abs($b['diff'])]);
        return ['rows' => $rows, 'totals' => $tot, 'matched' => count(array_filter($rows, fn ($r) => $r['diff'] === 0)), 'to_check' => count(array_filter($rows, fn ($r) => $r['diff'] !== 0 && $r['product']))];
    }

    /** Bring the ledger to the report for the chosen lines: from = 'adjust' (found / lost) or a warehouse location id (units sent and not booked). */
    public static function book(int $ent, object $u, array $lines, $from = 'adjust'): array
    {
        Perm::need($u, 'stock.CHANGE'); $done = 0; $skipped = []; $value = 0.0;
        $byKey = []; foreach (self::recon($ent)['rows'] as $r) $byKey[$r['shop'] . '|' . $r['sku']] = $r;
        foreach ($lines as $k) {
            $r = $byKey[$k] ?? null; if (! $r || ! $r['product'] || $r['diff'] === 0) { $skipped[] = $k; continue; }
            $reason = 'FBA report ' . $r['date'] . ' · ' . $r['shop_name'];
            try {
                if ($r['diff'] > 0 && is_numeric($from)) StockService::transfer($ent, $r['product'], $r['diff'], (int) $from, $r['location'], $reason . ' – sent, not booked', ['source_type' => 'FBA_RECON']);
                else { $cost = (float) (DB::table('t_product_vendors')->where('id', $r['product'])->value(DB::raw('COALESCE(NULLIF(landing_price,0), raw_price, 0)')) ?? 0);
                    $x = StockService::adjust($ent, $r['product'], $r['location'], $r['diff'], $reason, ['unit_cost' => $cost, 'source_type' => 'FBA_RECON']); $value += (float) ($x['value'] ?? 0); }
                $done++;
            } catch (\Throwable $e) { $skipped[] = $k . ': ' . $e->getMessage(); }
        }
        Audit::log('cat_inventory_locations', 0, 'UPDATE', ['fba_booked' => [null, $done]], 'FBA reconciliation', $ent);
        return ['booked' => $done, 'skipped' => $skipped, 'value' => round($value, 2), 'message' => "$done line(s) booked into the ledger" . ($skipped ? ', ' . count($skipped) . ' skipped' : '') . '.'];
    }
}
