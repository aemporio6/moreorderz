<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-103 charts on dashboards. Every chart is built from the SAME calculation as the figure it stands next to – never a second one:
 * sales = SaleLines::query (the Sales list and its month total), stock = StockService::value's formula per location kind, cash = CashForecast::build,
 * ICC = the products' ICC status, buying = CycleAlerts::dashboard tiles, payouts = the settlements list, tasks = TaskLists' visible tasks.
 * A chart is: title, unit, type (bar | stacked | donut), labels, series [name, values, links], an optional line, and the rows of its "View data" table.
 * Each bar / slice has the link of the filtered list it counts.
 */
class Charts
{
    private static function months(int $n): array { $out = []; for ($i = $n - 1; $i >= 0; $i--) $out[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); return $out; }

    /** Sales last N months: bars per month (stacked per marketplace), line = profit of locked months (only with the cost right). */
    public static function sales(int $ent, object $u, int $months = 12, ?int $shop = null): array
    {
        $ms = self::months(max(3, min(24, $months))); $from = $ms[0] . '-01'; $to = date('Y-m-t', strtotime(end($ms) . '-01')); $money = Perm::has($u, 'orders.MONEY');
        $series = []; $profit = array_fill_keys($ms, 0.0); $lockedAll = array_fill_keys($ms, true); $any = array_fill_keys($ms, false);
        foreach (DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as marketplace_short")]) as $mp) {      /* the same name as the Sales list */
            $q = SaleLines::query(array_filter(['marketplace' => (int) $mp->id, 'from' => $from, 'to' => $to, 'shop' => $shop]), $u);
            $g = $q->groupBy(DB::raw("DATE_FORMAT(s.d_order, '%Y-%m')"))->selectRaw("DATE_FORMAT(s.d_order, '%Y-%m') m, COALESCE(SUM(s.total_amount),0) sale, COALESCE(SUM(s.profit),0) profit, MIN(COALESCE(s.f_locked,0)) locked, COUNT(*) n")->get()->keyBy('m');
            if ($g->isEmpty()) continue;
            $vals = []; $links = [];
            foreach ($ms as $m) { $r = $g[$m] ?? null; $vals[] = $r ? round((float) $r->sale, 2) : 0.0;
                $links[] = '#/sales?' . http_build_query(array_filter(['marketplace' => $mp->id, 'shop' => $shop, 'from' => "$m-01", 'to' => date('Y-m-t', strtotime("$m-01"))]));
                if ($r) { $any[$m] = true; $profit[$m] += (float) $r->profit; if (! (int) $r->locked) $lockedAll[$m] = false; } }
            $series[] = ['name' => (string) $mp->marketplace_short, 'values' => $vals, 'links' => $links];
        }
        $line = $money ? ['name' => 'Profit (locked months)', 'values' => array_map(fn ($m) => $any[$m] && $lockedAll[$m] ? round($profit[$m], 2) : null, $ms)] : null;
        return ['key' => 'sales', 'title' => 'Sales last ' . count($ms) . ' months', 'unit' => Fx::base($ent), 'type' => 'stacked', 'labels' => array_map(fn ($m) => date('M y', strtotime("$m-01")), $ms), 'series' => $series, 'line' => $line,
            'empty' => 'No sales in these months.', 'note' => 'Each bar is the total of the Sales list for that month; a click opens it.'];
    }

    /** Stock value at cost by location kind (stacked bar = one bar, a part per kind). */
    public static function stock(int $ent, object $u): ?array
    {
        if (! Perm::has($u, 'stock.MONEY')) return null;
        $rows = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)
            ->groupBy(DB::raw("COALESCE(c.kind, 'NONE')"))->selectRaw("COALESCE(c.kind, 'NONE') k, SUM(b.qty * l.unit_cost_gbp) v")->having('v', '<>', 0)->orderByDesc('v')->get();
        $name = fn ($k) => $k === 'NONE' ? 'No location' : ucfirst(strtolower(str_replace('_', ' ', $k)));
        return ['key' => 'stock', 'title' => 'Stock value at cost', 'unit' => Fx::base($ent), 'type' => 'stacked', 'labels' => ['Stock'],
            'series' => $rows->map(fn ($r) => ['name' => $name($r->k), 'values' => [round((float) $r->v, 2)], 'links' => ['#/stock/lots?kind=' . $r->k]])->values()->all(), 'line' => null,
            'total' => StockService::value($ent), 'empty' => 'No stock.', 'note' => 'The same value as "Stock at cost" (landed cost of what is on the shelf), split by the kind of location.'];
    }

    /** Free cash today and the next 13 weeks (the cash forecast's running balance). */
    public static function cash(int $ent, object $u): ?array
    {
        if (! Perm::has($u, 'money.SEE')) return null;
        $f = CashForecast::build($ent, 13);
        return ['key' => 'cash', 'title' => 'Free cash – next 90 days', 'unit' => $f['base'], 'type' => 'bar', 'labels' => array_map(fn ($r) => $r['label'], $f['rows']),
            'series' => [['name' => 'Free cash after that week', 'values' => array_map(fn ($r) => $r['balance'], $f['rows']), 'links' => array_map(fn () => '#/forecast', $f['rows'])]], 'line' => null,
            'limit' => $f['alert_limit'] ?: null, 'empty' => 'Nothing planned.', 'note' => 'Bank today plus money coming in, less money committed, week by week (the cash forecast).'];
    }

    /** ICC status split of the products (RED / YELLOW / OK). */
    public static function icc(int $ent, object $u): array
    {
        $rows = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotNull('icc_run_at')->whereIn('icc_status', ['RED', 'YELLOW', 'GREEN'])->groupBy('icc_status')->selectRaw('icc_status s, COUNT(*) n')->pluck('n', 's');
        $order = ['RED', 'YELLOW', 'OK'];
        return ['key' => 'icc', 'title' => 'Stock cover (ICC) of the products', 'unit' => 'products', 'type' => 'donut', 'labels' => $order,
            'series' => [['name' => 'Products', 'values' => array_map(fn ($s) => (int) ($rows[$s === 'OK' ? 'GREEN' : $s] ?? 0), $order), 'links' => array_map(fn ($s) => '#/icc?status=' . ($s === 'OK' ? 'GREEN' : $s), $order)]], 'line' => null,
            'empty' => 'The stock cover (ICC) has not been worked out yet for any product.', 'note' => 'RED = runs out before new stock can arrive; YELLOW = close; OK = enough.'];
    }

    /** Finance: marketplace payouts vs the money that reached the bank, per statement month. */
    public static function payouts(int $ent, object $u, int $months = 12, ?int $shop = null): ?array
    {
        if (! Perm::has($u, 'money.SEE')) return null; $ms = self::months(max(3, min(24, $months)));
        $q = Perm::mine(DB::table('mo_settlements as s'), 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('s.statement_date', '>=', $ms[0] . '-01')->when($shop, fn ($x) => $x->where('s.fk_id_branch', $shop));
        $g = $q->groupBy(DB::raw("DATE_FORMAT(s.statement_date, '%Y-%m')"))->selectRaw("DATE_FORMAT(s.statement_date, '%Y-%m') m, SUM(s.payable) due, SUM(CASE WHEN s.fk_id_bank_line IS NOT NULL THEN s.payable ELSE 0 END) banked")->get()->keyBy('m');
        $link = fn ($m) => '#/settlements?' . http_build_query(array_filter(['month' => $m, 'shop' => $shop]));
        return ['key' => 'payouts', 'title' => 'Payouts vs bank by month', 'unit' => Fx::base($ent), 'type' => 'bar', 'labels' => array_map(fn ($m) => date('M y', strtotime("$m-01")), $ms),
            'series' => [['name' => 'Payouts due', 'values' => array_map(fn ($m) => round((float) ($g[$m]->due ?? 0), 2), $ms), 'links' => array_map($link, $ms)],
                ['name' => 'Reached the bank', 'values' => array_map(fn ($m) => round((float) ($g[$m]->banked ?? 0), 2), $ms), 'links' => array_map($link, $ms)]], 'line' => null,
            'empty' => 'No marketplace statements in these months.', 'note' => 'From the marketplace statements; "reached the bank" = matched to a bank line.'];
    }

    /** Tasks: open tasks per category, the late ones as their own part. */
    public static function tasks(int $ent, object $u, array $f = []): array
    {
        $q = TaskLists::visible(TaskLists::base(), $u)->whereIn('t.status', TaskLists::OPEN); $today = TaskHealth::today();
        if (! empty($f['person'])) $q->where('t.owner_user_id', (int) $f['person']);
        $g = $q->groupBy('t.category_id', 'c.name')->selectRaw("t.category_id cat, COALESCE(c.name, 'No category') name, SUM(t.due_at IS NOT NULL AND DATE(t.due_at) < ?) late, SUM(t.due_at IS NULL OR DATE(t.due_at) >= ?) ontime", [$today, $today])->orderBy('name')->get();
        $link = fn ($chip, $cat) => '#/tasks?scope=all&' . http_build_query(array_filter(['chip' => $chip, 'category' => $cat, 'person' => $f['person'] ?? null]));
        return ['key' => 'tasks', 'title' => 'Open tasks by category', 'unit' => 'tasks', 'type' => 'stacked', 'labels' => $g->pluck('name')->all(),
            'series' => [['name' => 'On time', 'values' => $g->map(fn ($r) => (int) $r->ontime)->all(), 'links' => $g->map(fn ($r) => $link('open', $r->cat))->all()],
                ['name' => 'Late', 'values' => $g->map(fn ($r) => (int) $r->late)->all(), 'links' => $g->map(fn ($r) => $link('late', $r->cat))->all()]], 'line' => null, 'empty' => 'No open task.', 'note' => 'Open = to do, in progress, stuck or sent back for rework.'];
    }

    /** Sourcing: open cycles by step (the same stage engine as the tiles of the Buying page). */
    public static function cycles(int $ent, object $u, array $f = []): array
    {
        $d = CycleAlerts::dashboard($ent, 1, 60, $f);
        return ['key' => 'cycles', 'title' => 'Open cycles by step', 'unit' => 'cycles', 'type' => 'bar', 'labels' => array_column($d['tiles'], 'name'),
            'series' => [['name' => 'Open cycles', 'values' => array_map(fn ($t) => (int) $t['open'], $d['tiles']), 'links' => array_map(fn ($t) => '#/buying?stage=' . $t['key'], $d['tiles'])]], 'line' => null, 'empty' => 'No open cycle.', 'note' => 'The same count as the step tiles of the Buying page.'];
    }

    public static function home(int $ent, object $u, array $f): array
    {
        $m = (int) ($f['months'] ?? 12) ?: 12; $shop = (int) ($f['shop'] ?? 0) ?: null; $out = [];
        if (Perm::has($u, 'orders.SEE')) $out[] = DashCards::run($ent, 'chart_sales', 'Sales chart', fn () => self::sales($ent, $u, $m, $shop));
        foreach ([['chart_stock', 'Stock chart', fn () => self::stock($ent, $u)], ['chart_cash', 'Cash chart', fn () => self::cash($ent, $u)], ['chart_icc', 'ICC chart', fn () => Perm::has($u, 'stock.SEE') ? self::icc($ent, $u) : null]] as [$k, $l, $fn]) $out[] = DashCards::run($ent, $k, $l, $fn);
        return ['charts' => array_values(array_filter(array_map(fn ($c) => $c['ok'] ? $c['data'] : ['key' => $c['card'], 'title' => $c['label'], 'error' => $c['error']], $out))), 'months' => $m, 'shop' => $shop,
            'shops' => array_map(fn ($s) => ['id' => (int) $s['id'], 'label' => $s['label']], ShopLabel::list($u, ['active_only' => true]))];
    }
}
