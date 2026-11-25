<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-INV-15 investor portal dashboard and "my sales" (the portal party of the login, R-G_CORE-86; documents in #/my-invoices).
 * Dashboard: stock value (stock × raw price), units in stock, unconfirmed sales count, capital recovered, profit share, revenue, units sold (settled months),
 * billed / paid / owed (own documents) and the latest 5 invoices. "Actual" (× landing price) is internal – shown only to roles in HIDDEN_MARGIN_ROLES (default Owner).
 * My sales: lines of my products (own link, else the parent's) in a date range and shop; my share = 1 ÷ investors on the product; my amount = raw price × qty × share;
 * Confirmed and Pending apart. CSV columns: Date, Marketplace, Shop, Order no, SKU, Product, Qty, Sale rate, Total, Raw price, My share, My amount, Status (UTF-8 BOM).
 */
class InvestorPortal
{
    public const SALES_CSV = ['Date', 'Marketplace', 'Shop', 'Order no', 'SKU', 'Product', 'Qty', 'Sale rate', 'Total', 'Raw price', 'My share', 'My amount', 'Status'];

    /** Product id => my share (1 ÷ investors), own links first, children of a linked parent with no own link too. */
    public static function products(int $ent, int $acc): array
    {
        $own = DB::table('t_product_investors')->where('fk_id_account', $acc)->pluck('fk_id_product')->map(fn ($x) => (int) $x)->unique()->all();
        $kids = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->whereIn('f_id_parent', $own ?: [0])->whereNotIn('id', DB::table('t_product_investors')->select('fk_id_product'))->pluck('id')->map(fn ($x) => (int) $x)->all();
        $out = [];
        foreach (array_unique(array_merge($own, $kids)) as $p) { $n = count(InvestorSettle::investors($p)); if ($n && in_array($acc, InvestorSettle::investors($p), true)) $out[$p] = 1 / $n; }
        return $out;
    }

    public static function dashboard(int $ent, object $u): array
    {
        $party = InvestorDocs::portalParty($ent, $u); $acc = (int) $party->fk_id_account; $mine = self::products($ent, $acc);
        $stock = DB::table('t_product_vendors')->whereIn('id', array_keys($mine) ?: [0])->get(['id', 'stock', 'raw_price', 'landing_price']);
        $units = 0; $value = 0.0; $actual = 0.0;
        foreach ($stock as $p) { $q = max(0, (int) $p->stock) * $mine[(int) $p->id]; $units += $q; $value += $q * (float) $p->raw_price; $actual += $q * (float) $p->landing_price; }
        $per = DB::table('t_inv_period_investors as x')->join('t_inv_periods as p', 'p.id', '=', 'x.fk_id_period')->where('p.fk_id_ent', $ent)->where('p.status', 'CONFIRMED')->where('x.fk_id_investor_account', $acc)
            ->selectRaw('COALESCE(SUM(x.capital),0) cap, COALESCE(SUM(x.share_amount),0) sh, COALESCE(SUM(x.revenue),0) rev, COALESCE(SUM(x.units),0) un')->first();
        $docs = collect(InvestorDocs::portalList($ent, $u)['docs']); $money = $docs->whereIn('status', ['UNPAID', 'PART_PAID', 'PAID']);
        $out = ['party' => $party->display_name, 'stock_units' => (int) round($units), 'stock_value' => round($value, 2),
            'unconfirmed' => DB::table('t_sales')->where('fk_id_ent', $ent)->whereIn('fk_product_id', array_keys($mine) ?: [0])->where('status', 'Pending')->count(),
            'capital_recovered' => round((float) $per->cap, 2), 'share' => round((float) $per->sh, 2), 'revenue' => round((float) $per->rev, 2), 'units_sold' => (int) $per->un,
            'billed' => round($money->sum('total'), 2), 'paid' => round($money->sum('paid'), 2), 'owed' => round($docs->whereIn('status', ['UNPAID', 'PART_PAID'])->sum('balance'), 2),
            'latest' => $docs->take(5)->values()->all()];
        if (InvoiceReports::canMargin($ent, $u)) $out['stock_actual'] = round($actual, 2);      /* R-INV-4 internal – never to partners */
        return $out;
    }

    public static function mySales(int $ent, object $u, array $f = []): array
    {
        $acc = (int) InvestorDocs::portalParty($ent, $u)->fk_id_account; $mine = self::products($ent, $acc);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) ? $f['from'] : date('Y-m-01'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) ? $f['to'] : date('Y-m-d');
        $rows = DB::table('t_sales as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('cat_marketplaces as m', 'm.id', '=', 's.fk_id_marketplace')->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')
            ->where('s.fk_id_ent', $ent)->whereIn('s.fk_product_id', array_keys($mine) ?: [0])->whereIn('s.status', ['Confirmed', 'Pending'])->whereBetween('s.d_order', [$from, $to . ' 23:59:59'])
            ->when(! empty($f['shop']), fn ($w) => $w->where('s.fk_id_branch', (int) $f['shop']))->orderBy('s.d_order')->orderBy('s.id')->limit(5000)
            ->get(['s.d_order', 'm.marketplace_short', 'b.shop_name', 's.order_no', 's.sku', 'p.product_name_vendor', 's.qty', 's.sale_rate', 's.total_amount', 's.raw_price', 's.status', 's.fk_product_id'])
            ->map(function ($s) use ($mine) { $sh = $mine[(int) $s->fk_product_id] ?? 0;
                return ['date' => substr((string) $s->d_order, 0, 10), 'marketplace' => $s->marketplace_short, 'shop' => $s->shop_name, 'order_no' => $s->order_no, 'sku' => $s->sku, 'product' => $s->product_name_vendor,
                    'qty' => (float) $s->qty, 'sale_rate' => round((float) $s->sale_rate, 2), 'total' => round((float) $s->total_amount, 2), 'raw_price' => round((float) $s->raw_price, 2), 'share' => round($sh, 4),
                    'amount' => round((float) $s->raw_price * (float) $s->qty * $sh, 2), 'status' => $s->status]; });
        $sum = fn ($st) => ['lines' => $rows->where('status', $st)->count(), 'qty' => (float) $rows->where('status', $st)->sum('qty'), 'amount' => round($rows->where('status', $st)->sum('amount'), 2)];
        return ['from' => $from, 'to' => $to, 'rows' => $rows->all(), 'confirmed' => $sum('Confirmed'), 'pending' => $sum('Pending'), 'file' => "my_sales_{$from}_{$to}.csv"];
    }

    public static function csv(array $r): string
    {
        $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, self::SALES_CSV);
        foreach ($r['rows'] as $x) fputcsv($o, [$x['date'], $x['marketplace'], $x['shop'], $x['order_no'], $x['sku'], $x['product'], $x['qty'] + 0, $x['sale_rate'], $x['total'], $x['raw_price'], $x['share'], $x['amount'], $x['status']]);
        rewind($o); return stream_get_contents($o);
    }
}
