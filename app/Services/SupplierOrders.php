<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-A_SALES-80 supplier order history (form supplier_order_history; layout AMAZON_BUSINESS_ORDERS = the Amazon Business order report of a buying supplier).
 * Shop = the file name before "." and "-" (1.csv, 1-july.csv), else "Kindly set valid File Name e.g. for Shop No. 1 set file name to [1.csv]".
 * Exactly 68 positional columns (the table t_amazon_orders has them after the shop id), else "No. of Columns not matched, see template"; a header line is skipped.
 * Upsert key shop + order id + ASIN (delete, then insert). Summary: rows, found / not found (ASIN on a listing of the shop's products), the input-VAT evidence
 * (VAT invoice no, seller VAT reg no, item VAT) kept on every row. Check (dry run) writes nothing.
 */
class SupplierOrders
{
    public static function columns(): array { return array_values(array_diff(Schema::getColumnListing('t_amazon_orders'), ['id', 'fk_id_branch'])); }

    private static function lengths(): array
    {
        $out = []; foreach (DB::select('SHOW COLUMNS FROM t_amazon_orders') as $c) if (preg_match('/char\((\d+)\)/', $c->Type, $m)) $out[$c->Field] = (int) $m[1];
        return $out;
    }

    public static function shopOf(int $ent, string $fileName): object
    {
        $no = preg_split('/[.\-]/', basename($fileName))[0] ?? '';
        return ($no !== '' ? DB::table('t_branches')->where('fk_id_ent', $ent)->where('shop_no', $no)->first() : null) ?? throw new \InvalidArgumentException('Kindly set valid File Name e.g. for Shop No. 1 set file name to [1.csv]');
    }

    public static function parse(string $text): array
    {
        $rows = PurchaseAudit::csv($text); $cols = self::columns(); $out = [];
        foreach ($rows as $i => $r) {
            if (! array_filter($r, fn ($x) => trim((string) $x) !== '')) continue;
            if (count($r) !== count($cols)) throw new \InvalidArgumentException('No. of Columns not matched, see template');
            if ($i === 0 && ! preg_match('/\d/', (string) ($r[0] ?? ''))) continue;      // the header line
            $out[] = array_combine($cols, array_map(fn ($x) => trim((string) $x), $r));
        }
        return $out;
    }

    public static function import(int $ent, string $fileName, string $text, object $u, bool $apply = true): array
    {
        Perm::need($u, 'buying.BUY');
        $shop = self::shopOf($ent, $fileName); $rows = self::parse($text); $len = self::lengths();
        $asins = array_values(array_unique(array_filter(array_column($rows, 'ASIN'))));
        $known = DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereIn('asin', $asins ?: [''])->pluck('fk_id_product', 'asin')->all();
        $found = count(array_filter($rows, fn ($r) => isset($known[$r['ASIN']])));
        $sum = ['shop' => trim($shop->shop_no . ' ' . $shop->shop_name), 'rows' => count($rows), 'found' => $found, 'not_found' => count($rows) - $found,
            'vat_evidence' => count(array_filter($rows, fn ($r) => $r['vat_inv_no'] !== '' || $r['seller_vat_reg_no'] !== '')), 'columns' => count(self::columns()) + 2];
        if (! $apply) return $sum + ['message' => "Check: {$sum['rows']} line(s), {$sum['found']} found, {$sum['not_found']} not found. Nothing was saved."];
        DB::transaction(function () use ($rows, $shop, $len) {
            foreach ($rows as $r) {
                DB::table('t_amazon_orders')->where('fk_id_branch', $shop->id)->where('order_id', $r['order_id'])->where('ASIN', $r['ASIN'])->delete();
                $row = ['fk_id_branch' => $shop->id]; foreach ($r as $k => $v) $row[$k] = isset($len[$k]) ? mb_substr($v, 0, $len[$k]) : $v;
                DB::table('t_amazon_orders')->insert($row);
            }
        });
        Audit::log('t_amazon_orders', (int) $shop->id, 'INSERT', ['file' => $fileName, 'rows' => count($rows)], 'supplier order history', $ent);
        return $sum + ['message' => "Done: {$sum['rows']} line(s) – {$sum['found']} found, {$sum['not_found']} not found."];
    }

    private static function money(?string $v): float { return (float) (Parse::money($v) ?: 0); }
    private static function day(?string $v): ?string { $d = Parse::date((string) $v); return $d === false || $d === '' ? null : $d; }

    /**
     * R-A_SALES-80 (improved): the order lines against the sales. A sale of the shop whose buy note names this order id (e.g. "AMZ : 204-1 : …") and whose
     * product is the ASIN's product is the drop-ship sale it was bought for: its buy rate / amount / date come from the order line (base currency at the order
     * date rate; a Pending sale only). The rest are stock buys – they become Quick buys (below). Check (apply false) writes nothing.
     */
    public static function match(int $ent, int $shopId, object $u, bool $apply = false): array
    {
        Perm::need($u, 'buying.BUY');
        $rows = DB::table('t_amazon_orders')->where('fk_id_branch', $shopId)->get();
        $known = DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereIn('asin', $rows->pluck('ASIN')->filter()->unique()->all() ?: [''])->pluck('fk_id_product', 'asin')->all();
        $out = ['matched' => [], 'stock' => [], 'unknown' => []];
        foreach ($rows as $r) {
            $pid = $known[$r->ASIN] ?? null; if (! $pid) { $out['unknown'][] = ['order' => $r->order_id, 'asin' => $r->ASIN, 'title' => mb_substr((string) $r->Item_title, 0, 60)]; continue; }
            $sale = DB::table('t_sales')->where('fk_id_branch', $shopId)->where('fk_product_id', $pid)->where('ds_note', 'like', '%' . $r->order_id . '%')->where(fn ($w) => $w->whereNull('line_status')->orWhere('line_status', '<>', 'DELETED'))->orderBy('id')->first();
            $qty = max(1, (int) self::money($r->item_qty)); $date = self::day($r->order_date) ?? date('Y-m-d');
            $total = Fx::toBase(self::money($r->item_net_total), strtoupper((string) $r->currency) ?: Fx::base($ent), $date, $ent);
            $line = ['order' => $r->order_id, 'asin' => $r->ASIN, 'product' => (int) $pid, 'qty' => $qty, 'total' => round($total, 2), 'date' => $date];
            if (! $sale) { $out['stock'][] = $line; continue; }
            $line['sale'] = (int) $sale->id; $line['sale_order'] = $sale->order_no; $line['status'] = $sale->status;
            if ($apply && $sale->status === 'Pending') {
                DB::table('t_sales')->where('id', $sale->id)->update(['buy_amount' => round($total, 2), 'buy_rate' => round($total / $qty, 2), 'd_buy' => $date, 'cost_source' => 'supplier_order', 'updated_at' => now()]);
                Audit::log('t_sales', (int) $sale->id, 'UPDATE', ['buy_amount' => [(float) $sale->buy_amount, round($total, 2)], 'from' => 'supplier order ' . $r->order_id], 'supplier order history', $ent);
            }
            $out['matched'][] = $line;
        }
        return $out + ['counts' => ['matched' => count($out['matched']), 'stock' => count($out['stock']), 'unknown' => count($out['unknown'])], 'message' => ($apply ? 'Done: ' : 'Check: ') . count($out['matched']) . ' line(s) matched to sales, ' . count($out['stock']) . ' stock buy(s), ' . count($out['unknown']) . ' ASIN(s) not on a listing.'];
    }

    /** The stock buys of one order as a Quick buy (cycle QUICK → PO → GRN → lots at the order's cost); the supplier + order id guard stops a second one. */
    public static function toQuickBuy(int $ent, int $shopId, string $orderId, int $supplier, int $location, object $u): array
    {
        $lines = array_values(array_filter(self::match($ent, $shopId, $u)['stock'], fn ($l) => $l['order'] === $orderId));
        if (! $lines) throw new \InvalidArgumentException("Order $orderId has no stock line (its lines are matched to sales or their ASIN is on no listing).");
        return SourcingExtras::quickBuy($ent, ['supplier' => $supplier, 'location' => $location, 'date' => $lines[0]['date'], 'reference' => $orderId, 'currency' => Fx::base($ent),
            'note' => 'Supplier order history ' . $orderId, 'lines' => array_map(fn ($l) => ['product' => $l['product'], 'qty' => $l['qty'], 'unit_price' => round($l['total'] / $l['qty'], 4)], $lines)]);
    }

    public static function template(): string { return implode(',', self::columns()) . "\n"; }
}
