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

    public static function template(): string { return implode(',', self::columns()) . "\n"; }
}
