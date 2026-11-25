<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-A_SALES-56 supplier orders file (BUY), report type SUPPLIER_ORDERS:<supplier>, loaded into the Gen-1 month (t_sale_buy_main → t_buy).
 * - File name <shop>_BUY_<VENDORISO>_….csv: the shop = the header's shop, the 2nd token BUY, the 3rd an existing supplier ISO code.
 * - Generic positional layout: column 1 (shop name) dropped, then d_buy (d.m.y), order_id (text before "/"), item_title, customer_name, cust_address, no_of_items, price.
 * - AliExpress layout: headers DATE, ORDER ID, SKU, ITEM TITLE, CUSTOMER, ADDRESS, QUANTITY, BUY PRICE; empty title → "[EMPTY]", empty qty → 1; a bad date stops the import.
 * - The layout is kept per supplier (t_vendors.buy_layout), found from the header row the first time.
 * - Rows without order / date / title / customer / qty / price are skipped and counted; a duplicate order id is skipped in both layouts (the old AliExpress bug is not kept).
 * - The earlier lines of that header + supplier are replaced. A check (apply = false) writes nothing.
 */
class BuyFile
{
    public const ALI = ['DATE', 'ORDER ID', 'SKU', 'ITEM TITLE', 'CUSTOMER', 'ADDRESS', 'QUANTITY', 'BUY PRICE'];

    public static function date(string $v): ?string
    {
        if (! preg_match('/^\s*(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\s*$/', $v, $m)) return null;
        $y = (int) $m[3]; if ($y < 100) $y += 2000;
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
    }

    private static function money(string $v): ?float { $v = preg_replace('/[^\d.,\-]/', '', $v); if ($v === '' || $v === null) return null; if (str_contains($v, ',') && ! str_contains($v, '.')) $v = str_replace(',', '.', $v); $v = str_replace(',', '', $v); return is_numeric($v) ? round((float) $v, 2) : null; }

    public static function import(int $headerId, string $fileName, string $text, bool $apply): array
    {
        $h = Gen1Compare::header($headerId);
        $shopNo = trim((string) DB::table('t_branches')->where('id', $h->fk_id_branch)->value('shop_no'));
        $tok = explode('_', pathinfo($fileName, PATHINFO_FILENAME));
        if (count($tok) < 3 || strcasecmp(trim($tok[0]), $shopNo) !== 0) throw new \InvalidArgumentException("The file name must start with the shop of this month ($shopNo): <shop>_BUY_<supplier code>_….csv");
        if (strtoupper(trim($tok[1])) !== 'BUY') throw new \InvalidArgumentException('The second part of the file name must be BUY: <shop>_BUY_<supplier code>_….csv');
        $vendor = DB::table('t_vendors')->whereRaw('UPPER(iso_vendor) = ?', [strtoupper(trim($tok[2]))])->when(Context::ent(), fn ($q) => $q->where(fn ($w) => $w->where('fk_id_ent', Context::ent())->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0)))->orderByDesc('f_active')->first();
        if (! $vendor) throw new \InvalidArgumentException('Valid Vendor Code should be at proper place');

        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $text)), fn ($l) => trim($l) !== ''));
        if (! $lines) throw new \InvalidArgumentException('The file has no rows.');
        $first = array_map(fn ($x) => strtoupper(trim((string) $x)), str_getcsv($lines[0]));
        $isAli = ! array_diff(self::ALI, $first);
        $layout = $isAli ? 'ALIEXPRESS' : ((string) ($vendor->buy_layout ?? '') === 'ALIEXPRESS' ? 'ALIEXPRESS' : 'GENERIC');
        if ($layout === 'ALIEXPRESS' && ! $isAli) throw new \InvalidArgumentException('This supplier uses the AliExpress layout – the first row must have: ' . implode(', ', self::ALI) . '.');
        $rows = []; $skip = ['date' => 0, 'order' => 0, 'title' => 0, 'customer' => 0, 'qty' => 0, 'price' => 0, 'dup' => 0]; $seen = [];
        if ($layout === 'ALIEXPRESS') { $ix = array_flip($first); array_shift($lines); }
        elseif (self::date((string) (str_getcsv($lines[0])[1] ?? '')) === null && count($lines) > 1 && self::date((string) (str_getcsv($lines[1])[1] ?? '')) !== null) array_shift($lines);      /* a heading row of the generic layout */
        foreach ($lines as $n => $line) {
            $v = array_map(fn ($x) => trim((string) $x), str_getcsv($line));
            if ($layout === 'ALIEXPRESS') { $g = fn ($k) => $v[$ix[$k]] ?? ''; $r = ['d' => $g('DATE'), 'order' => $g('ORDER ID'), 'sku' => $g('SKU'), 'title' => $g('ITEM TITLE') === '' ? '[EMPTY]' : $g('ITEM TITLE'), 'customer' => $g('CUSTOMER'), 'address' => $g('ADDRESS'), 'qty' => $g('QUANTITY') === '' ? '1' : $g('QUANTITY'), 'price' => $g('BUY PRICE')]; }
            else $r = ['d' => $v[1] ?? '', 'order' => trim(explode('/', $v[2] ?? '')[0]), 'sku' => null, 'title' => $v[3] ?? '', 'customer' => $v[4] ?? '', 'address' => $v[5] ?? '', 'qty' => $v[6] ?? '', 'price' => $v[7] ?? ''];
            $d = self::date($r['d']);
            if ($d === null) { if ($layout === 'ALIEXPRESS') throw new \InvalidArgumentException('Invalid Date Formt of Buy Date. i.e [31.01.23]'); $skip['date']++; continue; }
            if ($r['order'] === '') { $skip['order']++; continue; }
            if ($r['title'] === '') { $skip['title']++; continue; }
            if ($r['customer'] === '') { $skip['customer']++; continue; }
            if (! ctype_digit($r['qty']) || (int) $r['qty'] < 1) { $skip['qty']++; continue; }
            $price = self::money($r['price']); if ($price === null) { $skip['price']++; continue; }
            if (isset($seen[$r['order']])) { $skip['dup']++; continue; }
            $seen[$r['order']] = true;
            $rows[] = ['fk_id_main' => $headerId, 'fk_id_vendor' => (int) $vendor->id, 'd_buy' => $d, 'order_id' => mb_substr($r['order'], 0, 40), 'sku' => $r['sku'] !== null && $r['sku'] !== '' ? mb_substr($r['sku'], 0, 100) : null,
                'item_title' => mb_substr($r['title'], 0, 1000), 'customer_name' => mb_substr($r['customer'], 0, 250), 'cust_address' => mb_substr($r['address'], 0, 800), 'no_of_items' => (int) $r['qty'], 'price' => $price];
        }
        $notes = [];
        if ($skip['date']) $notes[] = "Date should be in format i.e [01.12.2023], issue in {$skip['date']} records....";
        foreach (['order' => 'Order ID', 'title' => 'Item title', 'customer' => 'Customer', 'qty' => 'Quantity', 'price' => 'Price'] as $k => $label) if ($skip[$k]) $notes[] = "Empty [$label] in {$skip[$k]} records....";
        if ($skip['dup']) $notes[] = "{$skip['dup']} duplicates Order ID Found, skiiped...";
        $old = DB::table('t_buy')->where('fk_id_main', $headerId)->where('fk_id_vendor', $vendor->id)->count();
        $out = ['supplier' => $vendor->vendor_name, 'supplier_code' => strtoupper((string) $vendor->iso_vendor), 'report_type' => 'SUPPLIER_ORDERS:' . strtoupper((string) $vendor->iso_vendor), 'layout' => $layout, 'rows' => count($rows), 'skipped' => array_sum($skip), 'replaces' => $old, 'notes' => $notes, 'applied' => false,
            'message' => 'Check: ' . count($rows) . " buy line(s) would be loaded for {$vendor->vendor_name}" . ($old ? ", replacing the $old loaded before" : '') . '. Nothing was saved.'];
        if (! $apply) return $out;
        if (! $rows) throw new \InvalidArgumentException('No line of the file can be loaded. ' . implode(' ', $notes));
        DB::transaction(function () use ($rows, $headerId, $vendor, $layout, $fileName, $old) {
            DB::table('t_buy')->where('fk_id_main', $headerId)->where('fk_id_vendor', $vendor->id)->delete();
            foreach (array_chunk($rows, 500) as $c) DB::table('t_buy')->insert($c);
            if ((string) ($vendor->buy_layout ?? '') !== $layout) DB::table('t_vendors')->where('id', $vendor->id)->update(['buy_layout' => $layout]);      /* one mapping per supplier */
            Audit::log('t_sale_buy_main', $headerId, 'UPDATE', ['buy_file' => [$old . ' line(s)', count($rows) . ' line(s)']], 'Supplier orders file ' . $fileName . ' (' . $vendor->vendor_name . ', ' . $layout . ')', Context::ent());
        });
        return ['applied' => true, 'message' => 'Loaded: ' . count($rows) . " buy line(s) for {$vendor->vendor_name}" . ($old ? " (replaced $old)" : '') . ($notes ? ' · ' . count($notes) . ' note(s)' : '') . '.'] + $out;
    }
}
