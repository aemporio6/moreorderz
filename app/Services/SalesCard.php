<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-26 Sales card on the dashboard. Filters: Marketplace (All + active marketplaces A-Z) and the shared period (R-F_SETUP-27, default
 * This Month). Orders leave out status Cancelled and Refunded; an order = one order number (a line without one counts alone); units = Σ qty.
 * Rows: Sales today, Sales this week (rolling ≥ today − 7), Orders (period) "n · u units"; Sales value and Profit (see money only, 2 dp,
 * company currency); Imports waiting "<unconfirmed import batches> imports · <Pending orders> orders" (all time, marketplace applied, link to
 * the import screen); Last sale (dd Mon yyyy, period ignored); By channel and By VA (top 6 by orders, "n · u units"; unknown marketplace
 * "Channel #<id>"); each channel / VA row drills into the sales list filtered the same way. Source t_sales.
 */
class SalesCard
{
    public const TOP = 6;

    private static function base(int $ent, ?int $mp)
    {
        return DB::table('t_sales as s')->where('s.fk_id_ent', $ent)->whereNotIn('s.status', ['Cancelled', 'Refunded'])->where(fn ($w) => $w->whereNull('s.sale_source')->orWhereNotIn('s.sale_source', ['history', 'summary']))
            ->when($mp, fn ($q) => $q->where('s.fk_id_marketplace', $mp))->tap(fn ($q) => Perm::mine($q, 's.fk_id_branch'));
    }

    private const ORDERS = "COUNT(DISTINCT COALESCE(NULLIF(s.order_no, ''), CONCAT('#', s.id)))";

    /** [orders, units] */
    private static function nu($q): array { $r = $q->selectRaw(self::ORDERS . ' n, COALESCE(SUM(s.qty), 0) u')->first(); return [(int) $r->n, (float) $r->u]; }
    public static function text(array $nu): string { return $nu[0] . ' · ' . rtrim(rtrim(number_format($nu[1], 2, '.', ''), '0'), '.') . ' units'; }

    public static function card(int $ent, ?int $mp, string $from, string $to, bool $money, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $week = date('Y-m-d', strtotime("$today -7 days"));
        $per = fn () => self::base($ent, $mp)->whereBetween(DB::raw('DATE(s.d_order)'), [$from, $to]);
        $rows = [
            ['Sales today', self::text(self::nu(self::base($ent, $mp)->whereDate('s.d_order', $today)))],
            ['Sales this week', self::text(self::nu(self::base($ent, $mp)->whereDate('s.d_order', '>=', $week)->whereDate('s.d_order', '<=', $today)))],
            ['Orders (period)', self::text(self::nu($per()))],
        ];
        if ($money) {
            $m = $per()->selectRaw('COALESCE(SUM(COALESCE(s.total_amount_gbp, s.total_amount)), 0) v, COALESCE(SUM(s.profit), 0) p')->first();
            $rows[] = ['Sales value', number_format((float) $m->v, 2)]; $rows[] = ['Profit', number_format((float) $m->p, 2)];
        }
        $batches = (int) DB::table('t_sale_imports')->where('fk_id_ent', $ent)->where('status', 'Pending')->when($mp, fn ($q) => $q->where('fk_id_marketplace', $mp))->count();
        $pending = (int) self::base($ent, $mp)->where('s.status', 'Pending')->selectRaw(self::ORDERS . ' n')->value('n');
        $rows[] = ['Imports waiting', "$batches imports · $pending orders", '#/sales-import'];
        $last = self::base($ent, $mp)->max('s.d_order');
        $rows[] = ['Last sale', $last ? date('d M Y', strtotime($last)) : '—'];

        $mpName = DB::table('cat_marketplaces')->pluck(DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''))"), 'id');
        $range = "from=$from&to=$to&status=open";
        $channels = $per()->groupBy('s.fk_id_marketplace')->selectRaw('s.fk_id_marketplace mp, ' . self::ORDERS . ' n, COALESCE(SUM(s.qty), 0) u')->orderByDesc('n')->orderBy('s.fk_id_marketplace')->limit(self::TOP)->get()
            ->map(fn ($r) => ['name' => ($mpName[$r->mp] ?? null) ?: 'Channel #' . (int) $r->mp, 'figure' => self::text([(int) $r->n, (float) $r->u]), 'link' => "#/sales?marketplace={$r->mp}&$range"])->all();
        $names = DB::table('t_users')->pluck('display_name', 'id');
        $vas = $per()->groupBy('s.created_by')->selectRaw('s.created_by uid, ' . self::ORDERS . ' n, COALESCE(SUM(s.qty), 0) u')->orderByDesc('n')->orderBy('s.created_by')->limit(self::TOP)->get()
            ->map(fn ($r) => ['name' => ($names[$r->uid] ?? null) ?: ($r->uid ? 'User #' . (int) $r->uid : 'Not known'), 'figure' => self::text([(int) $r->n, (float) $r->u]), 'link' => '#/sales?' . ($mp ? "marketplace=$mp&" : '') . 'created_by=' . (int) $r->uid . "&$range"])->all();

        $mps = DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderByRaw("COALESCE(NULLIF(marketplace_short,''), marketplace_descr)")->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,'')) as name")])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) ($m->name ?: 'Channel #' . $m->id)])->all();
        return ['title' => 'Sales', 'marketplace' => $mp, 'marketplaces' => $mps, 'rows' => array_map(fn ($r) => ['label' => $r[0], 'figure' => $r[1], 'link' => $r[2] ?? null], $rows),
            'by_channel' => $channels, 'by_va' => $vas, 'empty_channel' => 'No sales in period', 'empty_va' => 'No entries in period'];
    }
}
