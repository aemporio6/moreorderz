<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gen-1 sale ↔ buy match summary and lists (R-A_SALES-57), the legacy compare kept read-only until cut-over.
 * Header = t_sale_buy_main (shop + month). Summary: each sale of the header joins the buy lines of the same header where customer_name is equal and
 * the buy address (spaces removed, upper case) contains the sale address (same treatment). Columns: shop, customer, address ("Sale: …" and "Buy: …"),
 * Qty (Sale) / Qty (Buy) (flagged when different), Sale price, Buy amount, Profit (sums), Audit (diff text). Lists: Gen-1 Sale list, Gen-1 Buy list.
 * Money columns need orders.MONEY; scope = the person's shops.
 */
class Gen1Compare
{
    public static function norm(?string $s): string { return mb_strtoupper(preg_replace('/\s+/u', '', (string) $s)); }

    public static function header(int $id): object
    {
        $h = DB::table('t_sale_buy_main as m')->leftJoin('t_branches as b', 'b.id', '=', 'm.fk_id_branch')->where('m.id', $id)
            ->first(['m.*', DB::raw("TRIM(CONCAT_WS(' ', b.shop_no, b.shop_name)) as shop")]) ?? throw new \InvalidArgumentException('Gen-1 month not found.');
        $mine = Perm::shops(\Illuminate\Support\Facades\Auth::user());
        if ($mine !== null && ! in_array((int) $h->fk_id_branch, $mine, true)) throw new \InvalidArgumentException('Gen-1 month not found.');
        return $h;
    }

    public static function info(int $id): array { $h = self::header($id); return ['id' => (int) $h->id, 'shop' => $h->shop, 'month' => sprintf('%02d/%04d', $h->mon, $h->yr)]; }

    public static function headers(): array
    {
        return Perm::mine(DB::table('t_sale_buy_main as m')->leftJoin('t_branches as b', 'b.id', '=', 'm.fk_id_branch'), 'm.fk_id_branch')->orderByDesc('m.yr')->orderByDesc('m.mon')->limit(300)
            ->get(['m.id', 'm.mon', 'm.yr', 'm.f_sale_loaded', 'm.f_summary_generated', DB::raw("TRIM(CONCAT_WS(' ', b.shop_no, b.shop_name)) as shop"),
                DB::raw('(SELECT COUNT(*) FROM t_sale s WHERE s.fk_id_main = m.id) as sales'), DB::raw('(SELECT COUNT(*) FROM t_buy y WHERE y.fk_id_main = m.id) as buys')])->map(fn ($r) => (array) $r)->all();
    }

    /** The match summary: one row per sale customer + address. */
    public static function summary(int $id, bool $money): array
    {
        $h = self::header($id);
        $sales = DB::table('t_sale')->where('fk_id_main', $id)->orderBy('customer_name')->orderBy('id')->get(['id', 'customer_name', 'cust_address', 'no_of_items', 'price']);
        $buys = DB::table('t_buy')->where('fk_id_main', $id)->get(['id', 'customer_name', 'cust_address', 'no_of_items', 'price']);
        $groups = [];
        foreach ($sales as $s) { $k = mb_strtoupper(trim((string) $s->customer_name)) . '|' . self::norm($s->cust_address);
            $groups[$k] ??= ['customer' => trim((string) $s->customer_name), 'sale_address' => (string) $s->cust_address, 'qty_sale' => 0, 'sale' => 0.0, 'buy_ids' => []];
            $groups[$k]['qty_sale'] += (int) $s->no_of_items; $groups[$k]['sale'] += (float) $s->price; }
        $rows = []; $tot = ['qty_sale' => 0, 'qty_buy' => 0, 'sale' => 0.0, 'buy' => 0.0, 'profit' => 0.0, 'matched' => 0, 'differences' => 0];
        foreach ($groups as $g) {
            $sa = self::norm($g['sale_address']); $cust = mb_strtoupper($g['customer']);
            $hit = $buys->filter(fn ($b) => mb_strtoupper(trim((string) $b->customer_name)) === $cust && $sa !== '' && str_contains(self::norm($b->cust_address), $sa));
            $qb = (int) $hit->sum('no_of_items'); $buy = round((float) $hit->sum('price'), 2); $sale = round($g['sale'], 2);
            $diff = ! $hit->count() ? 'No buy found for this customer and address' : ($qb !== $g['qty_sale'] ? "Qty differs: sale {$g['qty_sale']}, buy $qb" : '');
            $code = ! $hit->count() ? 'BUYING_MISSING' : ($qb !== $g['qty_sale'] ? 'QTY_DIFF' : 'OK');      /* R-G_CORE-80 summary check (old v_sale_summary_diff) */
            $row = ['code' => $code, 'result' => self::CODES[$code], 'shop' => $h->shop, 'customer' => $g['customer'], 'sale_address' => $g['sale_address'], 'buy_address' => $hit->pluck('cust_address')->unique()->implode(' / '),
                'qty_sale' => $g['qty_sale'], 'qty_buy' => $qb, 'qty_differs' => $qb !== $g['qty_sale'], 'audit' => $diff, 'buy_lines' => $hit->count()];
            if ($money) $row += ['sale' => $sale, 'buy' => $buy, 'profit' => round($sale - $buy, 2)];
            $rows[] = $row;
            $tot['qty_sale'] += $g['qty_sale']; $tot['qty_buy'] += $qb; $tot['sale'] += $sale; $tot['buy'] += $buy; $tot['matched'] += $hit->count() ? 1 : 0; $tot['differences'] += $diff !== '' ? 1 : 0;
        }
        $tot['profit'] = round($tot['sale'] - $tot['buy'], 2); $tot['sale'] = round($tot['sale'], 2); $tot['buy'] = round($tot['buy'], 2);
        if (! $money) unset($tot['sale'], $tot['buy'], $tot['profit']);
        return ['header' => ['id' => (int) $h->id, 'shop' => $h->shop, 'month' => sprintf('%02d/%04d', $h->mon, $h->yr)], 'rows' => $rows, 'totals' => $tot, 'money' => $money];
    }

    /**
     * R-G_CORE-80 audit check SALE_BUY_MATCH (old v_sale_diff): each sale line joined to its buying line on the header + order_id_buy_in_sale.
     * Codes: BUYING_MISSING · QTY_CUSTOMER · CUSTOMER · QTY · OK, plus the price difference (sale − buy). Reads only.
     */
    public const CODES = ['BUYING_MISSING' => 'Buying Missing', 'QTY_CUSTOMER' => 'Qty/Customer', 'CUSTOMER' => 'Customer', 'QTY' => 'Qty', 'QTY_DIFF' => 'Qty Diff', 'OK' => 'OK'];

    public static function lineCode(?object $buy, object $sale): string
    {
        if (! $buy) return 'BUYING_MISSING';
        $cust = mb_strtoupper(trim((string) $buy->customer_name)) !== mb_strtoupper(trim((string) $sale->customer_name));      /* as the old view compared: case does not count */ $qty = (int) $buy->no_of_items !== (int) $sale->no_of_items;
        return $cust && $qty ? 'QTY_CUSTOMER' : ($cust ? 'CUSTOMER' : ($qty ? 'QTY' : 'OK'));
    }

    public static function saleBuyMatch(int $id, bool $money): array
    {
        $h = self::header($id);
        $buys = DB::table('t_buy')->where('fk_id_main', $id)->orderBy('id')->get(['id', 'order_id', 'customer_name', 'no_of_items', 'price'])->groupBy(fn ($b) => trim((string) $b->order_id));
        $rows = []; $count = array_fill_keys(['BUYING_MISSING', 'QTY_CUSTOMER', 'CUSTOMER', 'QTY', 'OK'], 0);
        foreach (DB::table('t_sale')->where('fk_id_main', $id)->orderBy('id')->get(['id', 'order_id', 'order_id_buy_in_sale', 'sku', 'item_title', 'customer_name', 'no_of_items', 'price']) as $s) {
            $key = trim((string) $s->order_id_buy_in_sale); $b = $key !== '' ? ($buys[$key][0] ?? null) : null; $code = self::lineCode($b, $s); $count[$code]++;
            $row = ['sale_id' => (int) $s->id, 'order' => $s->order_id, 'buy_order' => $key ?: null, 'item' => trim($s->sku . ' ' . $s->item_title), 'customer' => $s->customer_name, 'buy_customer' => $b->customer_name ?? null,
                'qty_sale' => (int) $s->no_of_items, 'qty_buy' => $b ? (int) $b->no_of_items : null, 'code' => $code, 'result' => self::CODES[$code], 'fix' => $code === 'OK' ? null : "#/gen1/$id/" . ($code === 'BUYING_MISSING' ? 'buys' : 'sales')];
            if ($money) $row += ['sale' => round((float) $s->price, 2), 'buy' => $b ? round((float) $b->price, 2) : null, 'price_diff' => round((float) $s->price - (float) ($b->price ?? 0), 2)];
            $rows[] = $row;
        }
        return ['header' => ['id' => (int) $h->id, 'shop' => $h->shop, 'month' => sprintf('%02d/%04d', $h->mon, $h->yr)], 'rows' => $rows, 'counts' => $count, 'findings' => count($rows) - $count['OK'], 'money' => $money];
    }

    /** Each non-OK row of both checks = one audit finding with a fix link. */
    public static function findings(int $id): array
    {
        $out = [];
        foreach (self::saleBuyMatch($id, false)['rows'] as $r) if ($r['code'] !== 'OK') $out[] = ['check' => 'SALE_BUY_MATCH', 'code' => $r['code'], 'text' => "Order {$r['order']}: {$r['result']}", 'fix' => $r['fix']];
        foreach (self::summary($id, false)['rows'] as $r) if ($r['code'] !== 'OK') $out[] = ['check' => 'SALE_BUY_SUMMARY', 'code' => $r['code'], 'text' => "{$r['customer']}: " . self::CODES[$r['code']], 'fix' => "#/gen1/$id/summary"];
        return $out;
    }

    /** Month Close (PURCHASE_VS_SUPPLIER): the findings of the shop-month's Gen-1 header; null when there is none with both sale and buy lines. */
    public static function closeCount(int $branch, string $monthStart): ?array
    {
        $h = DB::table('t_sale_buy_main')->where('fk_id_branch', $branch)->where('d_yr_mon', $monthStart)->orderByDesc('id')->first(['id']);
        if (! $h || ! DB::table('t_sale')->where('fk_id_main', $h->id)->exists() || ! DB::table('t_buy')->where('fk_id_main', $h->id)->exists()) return null;
        $m = self::saleBuyMatch((int) $h->id, false); $sum = count(array_filter(self::summary((int) $h->id, false)['rows'], fn ($r) => $r['code'] !== 'OK'));
        return ['id' => (int) $h->id, 'lines' => $m['findings'], 'summary' => $sum];
    }

    public static function sales(int $id, bool $money): array
    {
        $h = self::header($id);
        return DB::table('t_sale')->where('fk_id_main', $id)->groupBy('order_id', 'd_sale', 'sku', 'item_title', 'customer_name', 'cust_address')->orderBy('d_sale')->orderBy('order_id')
            ->get(['order_id', 'd_sale', 'sku', 'item_title', 'customer_name', 'cust_address', DB::raw('SUM(no_of_items) as qty'), DB::raw('SUM(price) as price')])
            ->map(fn ($r) => ['shop' => $h->shop, 'order' => $r->order_id, 'date' => $r->d_sale, 'item' => trim($r->sku . ' ' . $r->item_title), 'customer' => $r->customer_name, 'address' => $r->cust_address, 'qty' => (int) $r->qty] + ($money ? ['price' => round((float) $r->price, 2)] : []))->all();
    }

    public static function buys(int $id, bool $money): array
    {
        $h = self::header($id);
        return DB::table('t_buy as y')->leftJoin('t_account as v', 'v.id', '=', 'y.fk_id_vendor')->leftJoin('t_vendors as tv', 'tv.id', '=', 'y.fk_id_vendor')->where('y.fk_id_main', $id)->orderBy('y.d_buy')->orderBy('y.order_id')
            ->get(['y.order_id', 'y.d_buy', 'y.sku', 'y.item_title', 'y.customer_name', 'y.cust_address', 'y.no_of_items', 'y.price', DB::raw("COALESCE(NULLIF(tv.vendor_name, ''), TRIM(CONCAT_WS(' ', v.first_name, v.sur_name))) as vendor")])
            ->map(fn ($r) => ['shop' => $h->shop, 'buy_from' => $r->vendor ?: null, 'order' => $r->order_id, 'date' => $r->d_buy, 'item' => trim($r->sku . ' ' . $r->item_title), 'customer' => $r->customer_name, 'address' => $r->cust_address, 'items' => (int) $r->no_of_items] + ($money ? ['total' => round((float) $r->price, 2)] : []))->all();
    }
}
