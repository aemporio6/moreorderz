<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Drop shipping orders (the supplier's side): list with filters, sorts and a totals bar, the item page, the export, delete of one row (RULES_D_PRODUCTS R-D-85).
 * Buyer privacy (R-D-79): address, phone and the original file row are shown in full only with the right orders.dsSeeContact – on the list, the item, the export and the API alike.
 */
class DsOrders
{
    public const SORTS = ['newest' => 'Newest order', 'oldest' => 'Oldest order', 'cost' => 'Highest cost', 'uploaded' => 'Last uploaded'];
    public const SIGN = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'CNY' => '¥', 'CAD' => 'CA$', 'AUD' => 'AU$', 'HKD' => 'HK$', 'NZD' => 'NZ$', 'SGD' => 'SG$'];

    public static function sees(?object $u): bool { return (bool) ($u && Perm::has($u, 'orders.dsSeeContact')); }
    public static function amount(float $v, ?string $cur): string { $c = strtoupper((string) $cur) ?: Fx::base(); return isset(self::SIGN[$c]) ? self::SIGN[$c] . number_format($v, 2) : number_format($v, 2) . ' ' . $c; }
    public static function maskAddress(?string $a): string { return trim((string) $a) === '' ? '' : '•••'; }

    private static function day(?string $v): ?string { $d = $v === null || trim($v) === '' ? '' : Parse::date($v); return $d ? $d : null; }

    /** The filtered query. Filters: q, platform, marketplace, shop, upload, from, to, range (7 | 30 | month), linked (yes | no). */
    public static function query(array $f, ?object $u)
    {
        $q = DB::table('drp_items as i')->where('i.fk_id_ent', Context::ent()); $shops = Perm::shops($u); if ($shops !== null) $q->whereIn('i.fk_id_branch', $shops);
        if (! empty($f['platform'])) $q->where('i.fk_platform', (int) $f['platform']); if (! empty($f['marketplace'])) $q->where('i.fk_id_marketplace', (int) $f['marketplace']); if (! empty($f['shop'])) $q->where('i.fk_id_branch', (int) $f['shop']);
        if (! empty($f['upload'])) $q->whereIn('i.id', DB::table('drp_item_imports')->where('fk_import', (int) $f['upload'])->select('fk_item'));
        $from = self::day($f['from'] ?? null); $to = self::day($f['to'] ?? null); $r = (string) ($f['range'] ?? '');
        if ($r === '7' || $r === '30') { $from = date('Y-m-d', strtotime('-' . ((int) $r - 1) . ' days')); $to = date('Y-m-d'); } elseif ($r === 'month') { $from = date('Y-m-01'); $to = date('Y-m-t'); }
        if ($from) $q->where('i.d_order', '>=', $from); if ($to) $q->where('i.d_order', '<=', $to);
        if (($f['linked'] ?? '') === 'yes') $q->whereExists(fn ($s) => $s->from('t_sales as s')->whereColumn('s.fk_drp_item', 'i.id')); elseif (($f['linked'] ?? '') === 'no') $q->whereNotExists(fn ($s) => $s->from('t_sales as s')->whereColumn('s.fk_drp_item', 'i.id'));
        $t = trim((string) ($f['q'] ?? ''));
        if ($t !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%'; $q->where(fn ($w) => $w->where('i.order_no', 'like', $like)->orWhere('i.item_title', 'like', $like)->orWhere('i.item_ref', 'like', $like)->orWhere('i.ship_name', 'like', $like)->orWhere('i.ship_postcode', 'like', $like)->orWhere('i.tracking_no', 'like', $like)); }
        return $q;
    }

    private static function sorted($q, string $sort)
    {
        return match ($sort) { 'oldest' => $q->orderBy('i.d_order')->orderBy('i.id'), 'cost' => $q->orderByDesc('i.total_cost')->orderByDesc('i.id'), 'uploaded' => $q->orderByDesc('i.fk_import_last')->orderByDesc('i.id'), default => $q->orderByDesc('i.d_order')->orderByDesc('i.id') };
    }

    private static function joined($q)
    {
        return $q->leftJoin('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'i.fk_id_marketplace')->leftJoin('drp_platforms as p', 'p.id', '=', 'i.fk_platform')
            ->select(['i.*', 'b.shop_no', 'b.shop_name', 'p.platform_name', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), NULLIF(m.marketplace_descr,''), CONCAT('Marketplace ', m.id)) as marketplace_name")]);
    }

    /** "3 rows · 3 items · £15.00 · $8.00". */
    public static function totals(array $f, ?object $u): array
    {
        $per = self::query($f, $u)->groupBy('i.currency')->get(['i.currency as cur', DB::raw('COUNT(*) as n'), DB::raw('SUM(i.qty) as qty'), DB::raw('SUM(i.total_cost) as total')]);
        $rows = (int) $per->sum('n'); $items = (int) $per->sum('qty'); $base = Fx::base(); $sums = []; foreach ($per as $p) { $c = strtoupper(trim((string) $p->cur)) ?: $base; $sums[$c] = ($sums[$c] ?? 0) + (float) $p->total; }
        uksort($sums, fn ($a, $b) => ($a === $base ? 0 : 1) <=> ($b === $base ? 0 : 1) ?: strcmp($a, $b)); $parts = []; foreach ($sums as $c => $v) $parts[] = self::amount($v, $c);
        return ['rows' => $rows, 'items' => $items, 'per_currency' => $sums, 'text' => number_format($rows) . ' row' . ($rows === 1 ? '' : 's') . ' · ' . number_format($items) . ' item' . ($items === 1 ? '' : 's') . ($parts ? ' · ' . implode(' · ', $parts) : '')];
    }

    private static function row(object $i, bool $see, array $sale = []): array
    {
        return ['id' => (int) $i->id, 'date' => date('d-m-Y', strtotime((string) $i->d_order)), 'shop' => $i->shop_no ? trim($i->shop_no) . ' (' . trim((string) $i->shop_name) . ')' : 'No shop', 'marketplace' => (string) ($i->marketplace_name ?? ''), 'platform' => (string) ($i->platform_name ?? ''),
            'order_no' => $i->order_no, 'replaced' => (int) $i->fk_import_last !== (int) $i->fk_import, 'title' => $i->item_title, 'ref' => $i->item_ref, 'variant' => $i->variant, 'qty' => (int) $i->qty,
            'total' => self::amount((float) $i->total_cost, $i->currency), 'incl_ship' => (float) $i->shipping_cost > 0, 'buyer' => $i->ship_name, 'postcode' => $i->ship_postcode, 'city' => $i->ship_city, 'country' => $i->ship_country,
            'address' => $see ? $i->ship_address : self::maskAddress($i->ship_address), 'phone' => $see ? $i->ship_phone : DsMatch::maskPhone($i->ship_phone), 'status' => $i->order_status, 'tracking' => $i->tracking_no, 'carrier' => $i->carrier, 'upload' => (int) $i->fk_import_last,
            'sale' => $sale ? ['id' => (int) $sale[0]->id, 'order_no' => $sale[0]->order_no, 'more' => count($sale) - 1, 'batch' => (int) $sale[0]->fk_id_import] : null];
    }

    public static function list(array $f, ?object $u): array
    {
        Perm::need($u, 'orders.SEE'); $see = self::sees($u); $per = min(200, max(10, (int) ($f['per_page'] ?? 50))); $page = max(1, (int) ($f['page'] ?? 1)); $sort = isset(self::SORTS[$f['sort'] ?? '']) ? $f['sort'] : 'newest';
        $tot = self::totals($f, $u); $rows = self::sorted(self::joined(self::query($f, $u)), $sort)->offset(($page - 1) * $per)->limit($per)->get();
        $sales = DB::table('t_sales')->whereIn('fk_drp_item', $rows->pluck('id')->all() ?: [0])->orderBy('id')->get(['id', 'order_no', 'fk_drp_item', 'fk_id_import'])->groupBy('fk_drp_item');
        $shops = ShopLabel::list($u); $up = ! empty($f['upload']) ? DB::table('drp_imports')->where('id', (int) $f['upload'])->where('fk_id_ent', Context::ent())->first() : null;
        return ['rows' => $rows->map(fn ($i) => self::row($i, $see, isset($sales[$i->id]) ? $sales[$i->id]->all() : []))->all(), 'totals' => $tot, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($tot['rows'] / $per)), 'sort' => $sort, 'sorts' => self::SORTS, 'masked' => ! $see,
            'upload' => $up ? ['id' => (int) $up->id, 'label' => 'Upload #' . $up->id, 'file' => $up->file_name] : null,
            'lists' => ['platforms' => DB::table('drp_platforms')->orderBy('platform_name')->get(['id', 'platform_name as name']), 'shops' => array_map(fn ($s) => ['id' => $s['id'], 'marketplace' => $s['marketplace'], 'name' => $s['label']], $shops),
                'marketplaces' => DB::table('cat_marketplaces')->whereIn('id', array_unique(array_column($shops, 'marketplace')) ?: [0])->orderBy('marketplace_descr')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name")])],
            'rights' => ['upload' => Perm::has($u, 'orders.dsUpload'), 'delete' => Perm::has($u, 'orders.dsUndo'), 'rematch' => Perm::has($u, 'orders.dsRematch'), 'link' => Perm::has($u, 'orders.dsLink'), 'export' => Perm::has($u, 'orders.saleExport')]];
    }

    private static function find(int $id, ?object $u): object
    {
        $i = self::joined(DB::table('drp_items as i')->where('i.fk_id_ent', Context::ent())->where('i.id', $id))->first() ?? abort(404, 'Drop shipping order not found.');
        if (! Perm::shopAllowed($u, (int) $i->fk_id_branch)) abort(403, "You don't have rights for this shop."); return $i;
    }

    public static function item(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.SEE'); $i = self::find($id, $u); $see = self::sees($u); $raw = (array) json_decode((string) $i->raw_json, true); $joins = isset($raw['_merged_lines']) ? count($raw['_merged_lines']) + 1 : 1;
        $first = DB::table('drp_imports')->where('id', $i->fk_import)->first(); $last = (int) $i->fk_import_last !== (int) $i->fk_import ? DB::table('drp_imports')->where('id', $i->fk_import_last)->first() : null; $when = fn ($d) => $d ? date('d-m-Y H:i', strtotime((string) $d)) : '';
        $lines = DB::table('t_sales as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_drp_item', $id)->orderBy('s.id')->get(['s.*', 'b.shop_no', 'b.shop_name'])->map(function ($s) use ($i) {
            $cost = (float) $i->total_cost; $profit = $s->profit !== null ? (float) $s->profit : round((float) $s->total_amount - $cost, 2);
            return ['id' => (int) $s->id, 'order_no' => $s->order_no, 'date' => date('d-m-Y', strtotime((string) $s->d_order)), 'shop' => trim((string) $s->shop_no) . ' (' . trim((string) $s->shop_name) . ')', 'buyer' => $s->cust_name, 'total' => number_format((float) $s->total_amount, 2), 'cost' => number_format((float) ($s->buy_amount ?? $cost), 2), 'profit' => number_format($profit, 2), 'profit_colour' => $profit < 0 ? 'red' : 'green',
                'matched' => (int) $s->ds_match_count . ' / 5', 'status' => $s->status, 'batch' => (int) $s->fk_id_import, 'month' => date('F Y', strtotime((string) $s->d_order))]; })->all();
        return ['id' => (int) $i->id, 'title' => $i->item_title ?: $i->item_ref, 'masked' => ! $see,
            'kpis' => ['Qty' => (string) (int) $i->qty, 'Unit price' => self::amount((float) $i->unit_price, $i->currency), 'Shipping' => self::amount((float) $i->shipping_cost, $i->currency), 'Total cost (what we paid)' => self::amount((float) $i->total_cost, $i->currency)],
            'cards' => ['Product' => ['Title' => $i->item_title, 'Supplier SKU / item id' => $i->item_ref, 'Variant' => $i->variant, 'Platform' => (string) $i->platform_name],
                'Shipped to' => ['Buyer' => $i->ship_name, 'Address' => $see ? $i->ship_address : self::maskAddress($i->ship_address), 'City' => $i->ship_city, 'County' => $i->ship_county, 'Postcode' => $i->ship_postcode, 'Country' => $i->ship_country, 'Phone' => $see ? $i->ship_phone : DsMatch::maskPhone($i->ship_phone)],
                'Order and delivery' => ['Order number' => $i->order_no, 'Order date' => date('d-m-Y', strtotime((string) $i->d_order)), 'Shop' => $i->shop_no ? trim($i->shop_no) . ' (' . trim((string) $i->shop_name) . ')' : 'No shop', 'Marketplace' => (string) $i->marketplace_name, 'Status' => $i->order_status, 'Tracking' => $i->tracking_no, 'Carrier' => $i->carrier],
                'Upload' => ['File' => $first->file_name ?? '', 'Uploaded by' => $first->uploaded_by_name ?? '', 'Uploaded at' => $when($first->uploaded_at ?? null), 'Last replaced' => $last ? $when($last->uploaded_at) . ' by ' . $last->uploaded_by_name . ' (' . $last->file_name . ')' : '', 'Saved in tool' => $when($i->created_at), 'Joins' => $joins > 1 ? "joins $joins lines of the file" : '',
                    'Month' => $i->period_month ? SalesImport::monthName((int) $i->period_month, (int) $i->period_year) : '', 'Updated at' => $when($i->updated_at)]],
            'raw' => $see ? $raw : null, 'raw_note' => $see ? null : 'The original file row is shown to managers only.', 'lines' => $lines, 'empty_lines' => 'Linked when a Sales import matches ' . DsMatch::minMatch() . ' of the 5 buyer columns (name, address, city, postcode, phone).',
            'rights' => ['rematch' => Perm::has($u, 'orders.dsRematch') && $i->fk_id_branch && $i->period_month, 'delete' => Perm::has($u, 'orders.dsUndo'), 'link' => Perm::has($u, 'orders.dsLink')], 'shop' => (int) $i->fk_id_branch, 'period' => [(int) $i->period_month, (int) $i->period_year]];
    }

    /** Re-match from the item page: the not-matched Pending lines of the item's shop and month are compared again. */
    public static function rematch(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.dsRematch'); $i = self::find($id, $u); if (! $i->fk_id_branch || ! $i->period_month) throw new \InvalidArgumentException('This order has no shop or month. Upload its file again with the shop and month.');
        $r = DsMatch::afterDsBatch((int) $i->fk_id_branch, (int) $i->period_month, (int) $i->period_year); $n = DB::table('t_sales')->where('fk_drp_item', $id)->count();
        return ['message' => $n ? "Re-match done: this order is linked to $n sale line(s)." : 'Re-match done: no sale line of ' . SalesImport::monthName((int) $i->period_month, (int) $i->period_year) . ' matches this order yet.', 'linked' => $n, 'result' => $r];
    }

    /** Sale lines that can be linked by hand to this order: not matched, Pending, same shop, order date within 45 days. */
    public static function saleChoices(int $id, string $q, ?object $u): array
    {
        Perm::need($u, 'orders.dsLink'); $i = self::find($id, $u);
        $s = DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $i->fk_id_branch)->where('status', 'Pending')->where('sale_source', 'unmatched')->whereBetween('d_order', [date('Y-m-d', strtotime($i->d_order . ' -45 days')), date('Y-m-d', strtotime($i->d_order . ' +45 days'))]);
        if (trim($q) !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%'; $s->where(fn ($w) => $w->where('order_no', 'like', $like)->orWhere('cust_name', 'like', $like)->orWhere('product_info', 'like', $like)); }
        return ['rows' => $s->orderByRaw('ABS(DATEDIFF(d_order, ?))', [$i->d_order])->limit(30)->get(['id', 'order_no', 'd_order', 'cust_name', 'product_info', 'total_amount'])->map(fn ($x) => ['id' => (int) $x->id, 'label' => $x->order_no . ' · ' . date('d-m-Y', strtotime((string) $x->d_order)) . ' · ' . $x->cust_name . ' · ' . number_format((float) $x->total_amount, 2) . ' · ' . mb_substr((string) $x->product_info, 0, 50)])->all()];
    }

    /** Delete one row (right orders.dsUndo): refused while a sale line is linked to it. */
    public static function delete(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.dsUndo'); $i = self::find($id, $u);
        if ($s = DB::table('t_sales')->where('fk_drp_item', $id)->first()) throw new \InvalidArgumentException("This order is linked to sale line {$s->order_no}. Unlink it on the Sales page first.");
        DB::transaction(function () use ($i) { DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) $i->fk_id_ent, 'table_name' => 'drp_items', 'row_id' => (int) $i->id, 'label' => mb_substr('Drop shipping order ' . $i->order_no, 0, 255), 'group_key' => 'drp-' . $i->id . '-' . time(), 'row_json' => json_encode((array) DB::table('drp_items')->where('id', $i->id)->first()), 'deleted_by' => \Illuminate\Support\Facades\Auth::id(), 'deleted_at' => now(), 'note' => 'Deleted on the drop shipping list']); DB::table('drp_item_imports')->where('fk_item', $i->id)->delete(); DB::table('drp_items')->where('id', $i->id)->delete(); Audit::log('drp_items', (int) $i->id, 'DELETE', ['order_no' => $i->order_no, 'total_cost' => $i->total_cost], null, (int) $i->fk_id_ent); });
        return ['message' => "Drop shipping order {$i->order_no} deleted."];
    }

    /** CSV of the filtered list (masked like the screen): order date, shop, platform, order no, title, SKU, variant, qty, unit, shipping, total, currency, status, tracking, matched sale, profit. */
    public static function csv(array $f, ?object $u): array
    {
        Perm::need($u, 'orders.saleExport'); $see = self::sees($u); $max = (int) Settings::get('SALES_EXPORT_MAX_LINES', Context::ent(), 50000); $n = self::totals($f, $u)['rows'];
        if ($n > $max) throw new \InvalidArgumentException("The list has $n rows; at most $max can be exported at once. Narrow the filters.");
        $h = fopen('php://temp', 'w+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, ['Order date', 'Shop', 'Platform', 'Order no', 'Title', 'SKU', 'Variant', 'Qty', 'Unit price', 'Shipping', 'Total', 'Currency', 'Status', 'Tracking', 'Buyer', 'Address', 'City', 'Postcode', 'Country', 'Phone', 'Matched sale', 'Profit'], ',', '"', '');
        self::sorted(self::joined(self::query($f, $u)), isset(self::SORTS[$f['sort'] ?? '']) ? $f['sort'] : 'newest')->chunk(1000, function ($rows) use ($h, $see) {
            $sales = DB::table('t_sales')->whereIn('fk_drp_item', $rows->pluck('id')->all())->orderBy('id')->get(['order_no', 'fk_drp_item', 'profit', 'total_amount'])->groupBy('fk_drp_item');
            foreach ($rows as $i) { $s = $sales[$i->id] ?? null; $profit = $s ? $s->sum(fn ($x) => $x->profit !== null ? (float) $x->profit : (float) $x->total_amount) : null;
                fputcsv($h, array_map(fn ($v) => DsMatch::guard((string) $v), [date('d-m-Y', strtotime((string) $i->d_order)), $i->shop_no ? trim($i->shop_no) . ' (' . trim((string) $i->shop_name) . ')' : 'No shop', $i->platform_name, $i->order_no, $i->item_title, $i->item_ref, $i->variant, $i->qty, number_format((float) $i->unit_price, 2, '.', ''), number_format((float) $i->shipping_cost, 2, '.', ''), number_format((float) $i->total_cost, 2, '.', ''), strtoupper((string) $i->currency) ?: Fx::base(), $i->order_status, $i->tracking_no,
                    $i->ship_name, $see ? $i->ship_address : self::maskAddress($i->ship_address), $i->ship_city, $i->ship_postcode, $i->ship_country, $see ? $i->ship_phone : DsMatch::maskPhone($i->ship_phone), $s ? $s->pluck('order_no')->implode(' / ') : '', $profit === null ? '' : number_format($profit, 2, '.', '')]), ',', '"', ''); } });
        rewind($h); $body = stream_get_contents($h); fclose($h); Audit::log('drp_items', null, 'EXPORT', ['rows' => $n, 'masked' => ! $see, 'filters' => array_filter($f)]);
        return ['name' => 'drop_shipping_orders_' . date('Y-m-d') . '.csv', 'body' => $body];
    }
}
