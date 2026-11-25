<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-55 anomaly alerts – one sweep (mo:dispatch-watch, every 15 minutes) that opens and closes the warehouse task codes:
 * - WH_RECON_MISMATCH: a negative bin balance, or a lot whose units left ≠ the units in its bins;
 * - WH_ADJUST_UNUSUAL: more than WH_ADJUST_ALERT_PER_WEEK adjustments in 7 days by one user or on one SKU;
 * - WH_DISPATCH_DUE: open order lines due today (dispatch-by) that are not packed yet, per shop;
 * - WH_UNASSIGNED_STOCK: units in the unassigned bin longer than WH_UNASSIGNED_DAYS;
 * - WH_HOLD_AGED: units in DAMAGED / QUARANTINE / RETURNS longer than WH_HOLD_AGED_DAYS.
 * The other codes of the rule are raised where they happen (WH_ORDER_SHORT at intake, WH_PUTAWAY_WAITING, WH_NOT_HANDED_OVER in TrackingPush, WH_PRINT_FAILED, SYS_API_SYNC, SAL_UNMATCHED).
 * A problem that is gone closes its task on the next sweep.
 */
class WarehouseAlerts
{
    public const HOLD = ['DAMAGED', 'QUARANTINE', 'RETURNS'];

    public static function run(int $ent, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s'); $out = [];
        $out['recon'] = TaskEngine::sync('WH_RECON_MISMATCH', self::recon($ent), null, $ent);
        $out['adjust'] = TaskEngine::sync('WH_ADJUST_UNUSUAL', self::unusual($ent, $now), null, $ent);
        $out['dispatch'] = TaskEngine::sync('WH_DISPATCH_DUE', self::dispatchDue($ent, $now), null, $ent);
        $out['unassigned'] = TaskEngine::sync('WH_UNASSIGNED_STOCK', self::aged($ent, ['UNASSIGNED'], (int) Settings::get('WH_UNASSIGNED_DAYS', $ent, 14), $now, 'not put in a bin'), null, $ent);
        $out['hold'] = TaskEngine::sync('WH_HOLD_AGED', self::aged($ent, self::HOLD, (int) Settings::get('WH_HOLD_AGED_DAYS', $ent, 14), $now, 'held'), null, $ent);
        return $out;
    }

    private static function sku(int $pid): string { return (string) (DB::table('t_product_vendors')->where('id', $pid)->value('SKU_VENDOR') ?? $pid); }

    public static function recon(int $ent): array
    {
        $p = [];
        foreach (DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->where('b.qty', '<', 0)
            ->get(['l.fk_id_product', 'b.qty', 'c.location_name', 'c.id as loc', 'c.fk_id_site']) as $r)
            $p[] = ['key' => [(int) $r->fk_id_site, self::sku((int) $r->fk_id_product), 'NEGATIVE'], 'title' => self::sku((int) $r->fk_id_product) . " shows {$r->qty} in {$r->location_name} – count the bin", 'opt' => ['module' => 'cat_inventory_locations', 'record_id' => (int) $r->loc, 'fix_url' => '#/stock/counts']];
        foreach (DB::table('fin_lots as l')->leftJoin('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('l.company_id', $ent)->groupBy('l.id', 'l.fk_id_product', 'l.qty_left')
            ->havingRaw('l.qty_left <> COALESCE(SUM(b.qty), 0)')->get(['l.id', 'l.fk_id_product', 'l.qty_left', DB::raw('COALESCE(SUM(b.qty), 0) as in_bins')]) as $r)
            $p[] = ['key' => [0, self::sku((int) $r->fk_id_product), 'LOT' . $r->id], 'title' => self::sku((int) $r->fk_id_product) . ": lot {$r->id} has {$r->qty_left} units left but {$r->in_bins} in its bins", 'opt' => ['module' => 'fin_lots', 'record_id' => (int) $r->id]];
        return $p;
    }

    public static function unusual(int $ent, string $now): array
    {
        $limit = (int) Settings::get('WH_ADJUST_ALERT_PER_WEEK', $ent, 3); $from = date('Y-m-d H:i:s', strtotime($now) - 7 * 86400); $wk = date('o-W', strtotime($now)); $p = [];
        $q = fn () => DB::table('t_stock_movements')->where('fk_id_ent', $ent)->whereIn('movement_type', ['adjustment', 'count_adjust'])->where('created_at', '>=', $from)->where('created_at', '<=', $now);
        foreach ($q()->where('created_by', '>', 0)->groupBy('created_by')->havingRaw('COUNT(*) > ?', [$limit])->get(['created_by', DB::raw('COUNT(*) n')]) as $r) {
            $who = DB::table('t_users')->where('id', $r->created_by)->value('display_name') ?? "user {$r->created_by}";
            $p[] = ['key' => ['U' . $r->created_by, $wk], 'title' => "$who made {$r->n} stock adjustments in 7 days (more than $limit) – check them", 'opt' => ['module' => 't_users', 'record_id' => (int) $r->created_by]];
        }
        foreach ($q()->groupBy('fk_id_product')->havingRaw('COUNT(*) > ?', [$limit])->get(['fk_id_product', DB::raw('COUNT(*) n')]) as $r)
            $p[] = ['key' => [self::sku((int) $r->fk_id_product), $wk], 'title' => self::sku((int) $r->fk_id_product) . " was adjusted {$r->n} times in 7 days (more than $limit) – check it", 'opt' => ['module' => 't_product_vendors', 'record_id' => (int) $r->fk_id_product]];
        return $p;
    }

    public static function dispatchDue(int $ent, string $now): array
    {
        $day = substr($now, 0, 10); $p = [];
        foreach (DB::table('t_sales as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('s.status', 'Pending')->where('s.sale_source', 'active')
            ->whereNotNull('s.dispatch_by')->where('s.dispatch_by', '<=', $day . ' 23:59:59')->whereRaw("COALESCE(s.wh_status,'') NOT IN ('PACKED','SHIPPED','RELEASED','PUTBACK')")->where(fn ($w) => $w->whereNull('s.fulfilment_channel')->orWhere('s.fulfilment_channel', 'SELF'))
            ->groupBy('s.fk_id_branch', 'b.shop_name')->get(['s.fk_id_branch', 'b.shop_name', DB::raw('COUNT(*) n'), DB::raw('MIN(s.dispatch_by) first_due')]) as $r)
            $p[] = ['key' => [(int) $r->fk_id_branch, $day], 'title' => ($r->shop_name ?: 'Shop ' . $r->fk_id_branch) . ": {$r->n} order line(s) due by today not packed yet (first due {$r->first_due})", 'opt' => ['module' => 't_branches', 'record_id' => (int) $r->fk_id_branch, 'fix_url' => '#/orders']];
        return $p;
    }

    public static function aged(int $ent, array $kinds, int $days, string $now, string $word): array
    {
        $before = date('Y-m-d H:i:s', strtotime($now) - $days * 86400); $p = [];
        foreach (DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->whereIn('c.kind', $kinds)
            ->where('b.qty', '>', 0)->where('b.updated_at', '<=', $before)->groupBy('c.id', 'c.location_name', 'c.fk_id_site', 'l.fk_id_product')->get(['c.id as loc', 'c.location_name', 'c.fk_id_site', 'l.fk_id_product', DB::raw('SUM(b.qty) q')]) as $r) {
            $sku = self::sku((int) $r->fk_id_product);
            $p[] = ['key' => count($kinds) > 1 ? [(int) $r->fk_id_site, (int) $r->loc, $sku] : [(int) $r->fk_id_site, $sku], 'title' => "{$r->q} × $sku $word in {$r->location_name} for more than $days days", 'opt' => ['module' => 'cat_inventory_locations', 'record_id' => (int) $r->loc]];
        }
        return $p;
    }
}
