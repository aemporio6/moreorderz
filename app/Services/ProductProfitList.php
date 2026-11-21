<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-54 Products & stock profit list. Range default 1 Jan this year → today (sale lines by order date, the same figures as
 * "Profit by SKU", so the totals agree with every profit view). Per SKU: units, sales, fees + PPC (marketplace costs), COGS, profit,
 * margin % (1 dp), ROI % = profit ÷ COGS (1 dp), refund % = refunded units ÷ units (1 dp), on hand = warehouse + Amazon of the stock
 * snapshot on the To date (R-FIN-51), stock days = on hand ÷ units sold per day of the range, SLOW / DEAD flag of the snapshot, cost missing
 * = confirmed units sold without a lot cost. Filters: SKU search, losing money (on the SUMMED SKU profit – the old code filtered rows
 * before summing), cost missing. Sort profit ↑↓ / sales / units / SKU. Totals row.
 */
class ProductProfitList
{
    public const SORTS = ['profit_asc', 'profit_desc', 'sales', 'units', 'sku'];

    public static function list(int $ent, array $f = []): array
    {
        $from = $f['from'] ?? date('Y-01-01'); $to = $f['to'] ?? date('Y-m-d'); if ($from > $to) [$from, $to] = [$to, $from];
        $sort = in_array($f['sort'] ?? '', self::SORTS, true) ? $f['sort'] : 'profit_desc'; $q = trim((string) ($f['q'] ?? ''));
        $v = ProfitService::view($ent, 'product', $from, $to);
        $over = array_values(array_filter($v['rows'], fn ($r) => ! str_starts_with((string) $r['key'], 'P')));      // costs not of one SKU (overheads) stay out of the SKU rows and filters
        $v['rows'] = array_values(array_filter($v['rows'], fn ($r) => str_starts_with((string) $r['key'], 'P')));
        $ids = array_map(fn ($r) => (int) substr($r['key'], 1), $v['rows']);
        $sku = DB::table('t_product_vendors')->whereIn('id', $ids ?: [0])->pluck('SKU_VENDOR', 'id');
        $sales = DB::table('t_sales')->where('fk_id_ent', $ent)->whereIn('fk_product_id', $ids ?: [0])->whereBetween(DB::raw('DATE(d_order)'), [$from, $to])->where('status', '<>', 'Cancelled')
            ->groupBy('fk_product_id')->selectRaw("fk_product_id p, SUM(qty) u, SUM(CASE WHEN status = 'Refunded' THEN qty ELSE 0 END) r")->get()->keyBy('p');
        $noCost = DB::table('t_sales as s')->where('s.fk_id_ent', $ent)->where('s.status', 'Confirmed')->where('s.sale_source', 'active')->whereBetween(DB::raw('DATE(s.d_order)'), [$from, $to])
            ->whereNotExists(fn ($x) => $x->from('fin_lot_consumption as c')->whereColumn('c.fk_id_sale', 's.id'))->groupBy('s.fk_product_id')->selectRaw('s.fk_product_id p, SUM(s.qty) u')->pluck('u', 'p');
        $slow = (int) (Settings::get('slow_days', $ent, 90) ?: 90); $dead = (int) (Settings::get('dead_days', $ent, 180) ?: 180);
        $snap = collect(StockSnapshot::rows($ent, $to))->keyBy('sku');
        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $rows = [];
        foreach ($v['rows'] as $r) {
            $id = (int) substr($r['key'], 1); $s = $id ? ($sku[$id] ?? null) : null; $sn = $s !== null ? $snap->get($s) : null;
            $onHand = $sn ? (int) $sn['units_on_hand_warehouse'] + (int) $sn['units_at_amazon'] : 0;
            $u = (float) ($sales[$id]->u ?? 0); $perDay = $r['units'] / $days;
            $rows[] = ['product_id' => $id, 'sku' => $s ?? '', 'name' => $r['name'], 'units' => $r['units'], 'sales' => $r['sales'], 'fees' => $r['mp_costs'], 'cogs' => $r['cost'], 'expenses' => $r['expenses'], 'profit' => $r['profit'],
                'margin_pct' => $r['margin_pct'], 'roi_pct' => $r['cost'] > 0 ? round($r['profit'] / $r['cost'] * 100, 1) : null, 'refund_pct' => $u > 0 ? round((float) $sales[$id]->r / $u * 100, 1) : null,
                'on_hand' => $onHand, 'stock_days' => $perDay > 0 ? (int) round($onHand / $perDay) : null, 'flag' => $sn ? StockSnapshot::flag($sn, $slow, $dead) : null,
                'cost_missing' => (int) ($noCost[$id] ?? 0), 'estimate' => $r['estimate'] ?? false];
        }
        // SKUs in stock that sold nothing in the range still belong in a stock list
        $have = array_flip(array_filter(array_column($rows, 'sku')));
        $pid = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->pluck('id', 'SKU_VENDOR'); $pname = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->pluck('product_name_vendor', 'SKU_VENDOR');
        foreach ($snap as $s => $sn) if (! isset($have[$s]) && ((int) $sn['units_on_hand_warehouse'] + (int) $sn['units_at_amazon']) > 0)
            $rows[] = ['product_id' => (int) ($pid[$s] ?? 0), 'sku' => $s, 'name' => trim($s . ' · ' . ($pname[$s] ?? ''), ' ·'), 'units' => 0, 'sales' => 0.0, 'fees' => 0.0, 'cogs' => 0.0, 'expenses' => 0.0, 'profit' => 0.0, 'margin_pct' => null, 'roi_pct' => null, 'refund_pct' => null,
                'on_hand' => (int) $sn['units_on_hand_warehouse'] + (int) $sn['units_at_amazon'], 'stock_days' => null, 'flag' => StockSnapshot::flag($sn, $slow, $dead), 'cost_missing' => 0, 'estimate' => false];
        if ($q !== '') $rows = array_filter($rows, fn ($r) => stripos($r['sku'] . ' ' . $r['name'], $q) !== false);
        if (! empty($f['losing'])) $rows = array_filter($rows, fn ($r) => $r['profit'] < 0);      // on the summed SKU profit
        if (! empty($f['cost_missing'])) $rows = array_filter($rows, fn ($r) => $r['cost_missing'] > 0);
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => match ($sort) { 'profit_asc' => [$a['profit'], $a['sku']] <=> [$b['profit'], $b['sku']], 'sales' => [$b['sales'], $a['sku']] <=> [$a['sales'], $b['sku']],
            'units' => [$b['units'], $a['sku']] <=> [$a['units'], $b['sku']], 'sku' => strcmp($a['sku'] ?: $a['name'], $b['sku'] ?: $b['name']), default => [$b['profit'], $a['sku']] <=> [$a['profit'], $b['sku']] });
        $t = ['units' => 0, 'sales' => 0.0, 'fees' => 0.0, 'cogs' => 0.0, 'expenses' => 0.0, 'profit' => 0.0, 'on_hand' => 0, 'cost_missing' => 0];
        foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
        foreach (['sales', 'fees', 'cogs', 'expenses', 'profit'] as $k) $t[$k] = round($t[$k], 2);
        $t['margin_pct'] = $t['sales'] > 0 ? round($t['profit'] / $t['sales'] * 100, 1) : null; $t['roi_pct'] = $t['cogs'] > 0 ? round($t['profit'] / $t['cogs'] * 100, 1) : null;
        return ['from' => $from, 'to' => $to, 'sort' => $sort, 'q' => $q, 'losing' => ! empty($f['losing']), 'cost_missing' => ! empty($f['cost_missing']), 'rows' => $rows, 'total' => $t, 'base' => Fx::base($ent),
            'slow_days' => $slow, 'dead_days' => $dead,
            'overheads' => round(array_sum(array_map(fn ($r) => (float) $r['profit'], $over)), 2)];
    }
}
