<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stock alerts page (R-D-46, old getStockAlerts). Rows: active products that are not a child (variation) where available stock ≤ 0 OR manual
 * "out of stock" OR (Min alert = Yes AND available ≤ min level); sorted by stock ascending; badge "Out of stock" (≤ 0 or manual) else "Near min level".
 * "Stock" here is available stock (R-D-19). Update = the counted qty at a location (absolute): the difference posts as an adjustment of
 * R-D-47 (Stock_service::adjust) with a required reason from Adjustment reasons; equal → "No change."; above ADJUST_APPROVAL_QTY it waits for approval.
 * STK_LOW per product (dedupe) opens for every min-level row and closes when available is above the minimum.
 */
class StockAlerts
{
    public const NOTE = 'Stock update from alerts page';

    private static function base(int $ent)
    {
        return DB::table('t_product_vendors as p')->leftJoin('t_account as v', 'v.id', '=', 'p.fk_id_vendor')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)
            ->where(fn ($w) => $w->whereNull('p.f_id_parent')->orWhere('p.f_id_parent', 0));
    }

    /** The rows of the page (and of the nightly STK_LOW sync). */
    public static function rows(int $ent): array
    {
        $out = [];
        foreach (self::base($ent)->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.f_out_of_stock', 'p.min_level', 'p.f_alert_min_level', DB::raw("TRIM(CONCAT_WS(' ', v.first_name, v.sur_name)) as vendor")]) as $p) {
            $avail = StockService::available((int) $p->id); $min = (int) $p->min_level; $oos = (int) $p->f_out_of_stock === 1;
            $near = (int) $p->f_alert_min_level === 1 && $min > 0 && $avail <= $min;
            if ($avail > 0 && ! $oos && ! $near) continue;
            $out[] = ['id' => (int) $p->id, 'sku' => $p->sku, 'name' => $p->name, 'vendor' => $p->vendor ?: null, 'stock' => $avail, 'min' => $min, 'status' => $avail <= 0 || $oos ? 'Out of stock' : 'Near min level',
                'min_alert' => $near, 'location' => self::mainLocation($ent, (int) $p->id), 'on_hand' => StockService::balance((int) $p->id)];
        }
        usort($out, fn ($a, $b) => [$a['stock'], $a['sku']] <=> [$b['stock'], $b['sku']]);
        return $out;
    }

    /** Default location of the update: where most units of the product are, else the first warehouse / bin. */
    public static function mainLocation(int $ent, int $product): ?int
    {
        $l = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.fk_id_product', $product)->where('b.qty', '>', 0)
            ->where(fn ($w) => $w->whereNull('c.f_sellable')->orWhere('c.f_sellable', 1))->groupBy('b.fk_id_location')->orderByRaw('SUM(b.qty) DESC')->value('b.fk_id_location');
        return $l ? (int) $l : (($x = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('kind', ['WAREHOUSE', 'BIN', 'SHELF'])->orderByRaw("kind = 'WAREHOUSE' DESC")->orderBy('id')->value('id')) ? (int) $x : null);
    }

    public static function page(int $ent): array
    {
        return ['rows' => self::rows($ent), 'reasons' => DB::table('cat_adjustment_reasons')->where('f_active', 1)->orderBy('reason_name')->pluck('reason_name')->all(), 'note' => self::NOTE,
            'locations' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('kind', ['WAREHOUSE', 'BIN', 'SHELF'])->orderBy('location_name')->get(['id', 'location_name as name']),
            'icc' => (int) DB::table('tasks')->where('rule_code', 'like', 'ICC%')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count(), 'approval_qty' => (int) Settings::get('ADJUST_APPROVAL_QTY', $ent, 50)];
    }

    /** One Update: the counted qty at a location. $bigOk = the user may post a big change himself. */
    public static function update(int $ent, int $product, ?int $location, $newQty, string $reason, ?string $notes, bool $bigOk): array
    {
        $err = [];
        if ($newQty === null || $newQty === '' || ! is_numeric($newQty) || (int) $newQty != $newQty || (int) $newQty < 0) $err['new_qty'] = 'Enter the counted quantity (a whole number, 0 or more)';
        $needReason = Choices::get('STOCK_UPDATE_REASON', $ent) === '1';      // Settings › Choices (default: required)
        if (trim($reason) === '') { if ($needReason) $err['reason'] = 'Choose the reason'; else $reason = 'no reason given'; }
        elseif (! DB::table('cat_adjustment_reasons')->where('f_active', 1)->where('reason_name', $reason)->exists()) $err['reason'] = 'Choose a reason from the list';
        if (! $location || ! DB::table('cat_inventory_locations')->where('id', $location)->where('fk_id_ent', $ent)->exists()) $err['location'] = 'Choose the location';
        $p = DB::table('t_product_vendors')->where('id', $product)->where('fk_id_ent', $ent)->first();
        if (! $p) $err['product'] = 'Product not found';
        if ($err) throw new ValidationErrors($err);
        $have = StockService::balance($product, $location); $diff = (int) $newQty - $have;
        if ($diff === 0) return ['changed' => false, 'message' => 'No change.', 'stock' => StockService::available($product)];
        $why = trim($reason . ' – ' . (trim((string) $notes) ?: self::NOTE));
        $limit = (int) Settings::get('ADJUST_APPROVAL_QTY', $ent, 50);
        if (! $bigOk && abs($diff) > $limit) {
            Approvals::request($ent, 'STOCK_ADJUST', 't_product_vendors', $product, 0, ['category' => "Stock adjustment {$p->SKU_VENDOR}: " . ($diff > 0 ? '+' : '') . $diff . ' units', 'note' => $why,
                'adjust' => ['product' => $product, 'location' => $location, 'qty' => $diff, 'reason' => $why]], "Approve stock adjustment {$p->SKU_VENDOR}: $diff units");
            return ['changed' => false, 'waiting' => true, 'message' => "More than $limit units – the adjustment waits for approval and stock has not changed yet.", 'stock' => StockService::available($product)];
        }
        $opt = ['source_type' => 'STOCK_ALERTS'];
        if ($diff > 0 && ! DB::table('fin_lots')->where('fk_id_product', $product)->where('company_id', $ent)->exists()) $opt['unit_cost'] = (float) ($p->landing_price ?? 0);
        StockService::adjust($ent, $product, $location, $diff, $why, $opt);
        self::syncLow($ent, $product);
        $now = StockService::available($product);
        return ['changed' => true, 'diff' => $diff, 'stock' => $now, 'message' => 'Stock ' . ($diff > 0 ? '+' : '') . $diff . " – available now $now."];
    }

    /** STK_LOW: open for each min-level row, closed when available is above the minimum (one product, or all). */
    public static function syncLow(int $ent, ?int $only = null): array
    {
        $rows = []; $n = 0;
        foreach (self::base($ent)->where('p.f_alert_min_level', 1)->where('p.min_level', '>', 0)->when($only, fn ($q) => $q->where('p.id', $only))->get(['p.id', 'p.SKU_VENDOR as sku', 'p.min_level']) as $p) {
            $a = StockService::available((int) $p->id);
            if ($a <= (int) $p->min_level) { $rows[] = ['key' => [(int) $p->id], 'title' => "Stock low: {$p->sku} has $a (minimum {$p->min_level})", 'opt' => ['ent' => $ent, 'module' => 't_product_vendors', 'record_id' => (int) $p->id, 'fix_url' => '#/stock-alerts']]; $n++; }
            elseif ($only) TaskEngine::close('STK_LOW', [(int) $p->id], 'Stock above the minimum');
        }
        if ($only) { foreach ($rows as $r) TaskEngine::open('STK_LOW', $r['key'], $r['title'], $r['opt']); return ['open' => $n]; }
        return TaskEngine::sync('STK_LOW', $rows, null, $ent) + ['open' => $n];
    }
}
