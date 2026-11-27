<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Upload of drop-shipping supplier orders (RULES_D_PRODUCTS R-D-73 … 78): file → held under a token → columns mapped (saved per company + platform, else guessed)
 * → rows checked, repeated lines ignored, lines of one item joined, rows already saved ignored or replaced → one transaction with its bookkeeping → history → undo.
 * After an upload the not-matched sale lines of that shop and month are matched again by themselves (event DS_BATCH_APPLIED).
 */
class DsUpload
{
    /** field => [label, group, aliases] – 22 target fields. */
    public const FIELDS = [
        'order_no' => ['Order number', 'Order', ['order number', 'order no', 'order id', 'order #', 'order']], 'd_order' => ['Order date', 'Order', ['order date', 'date ordered', 'purchase date', 'paid date', 'date', 'order time', 'created']],
        'item_title' => ['Product title', 'Item', ['item title', 'product title', 'product name', 'item name', 'title', 'description', 'product']], 'item_ref' => ['Supplier SKU / item id', 'Item', ['item id', 'sku', 'asin', 'supplier sku', 'product id', 'item number', 'item ref']],
        'variant' => ['Variant', 'Item', ['variant', 'variation', 'option', 'colour', 'color', 'size']], 'qty' => ['Quantity', 'Item', ['quantity', 'qty', 'units', 'pieces', 'item quantity']],
        'unit_price' => ['Unit price', 'Cost', ['unit price', 'item price', 'price each', 'price']], 'shipping_cost' => ['Shipping cost', 'Cost', ['shipping cost', 'shipping', 'postage', 'delivery cost', 'freight']], 'total_cost' => ['Total paid', 'Cost', ['total paid', 'order total', 'total cost', 'total amount', 'grand total', 'total', 'amount']], 'currency' => ['Currency', 'Cost', ['currency', 'currency code']],
        'order_status' => ['Order status', 'Delivery', ['order status', 'status']], 'tracking_no' => ['Tracking number', 'Delivery', ['tracking number', 'tracking no', 'tracking']], 'carrier' => ['Carrier', 'Delivery', ['carrier', 'courier', 'logistics', 'shipping company']],
        'ship_name' => ['Buyer name', 'Buyer', ['buyer name', 'recipient name', 'recipient', 'ship to name', 'contact name', 'customer name', 'receiver', 'consignee', 'delivered to']], 'ship_address1' => ['Address line 1', 'Buyer', ['address line 1', 'address 1', 'street address', 'street']], 'ship_address2' => ['Address line 2', 'Buyer', ['address line 2', 'address 2']],
        'ship_full_address' => ['Full address', 'Buyer', ['full address', 'shipping address', 'delivery address', 'address']], 'ship_city' => ['City', 'Buyer', ['city', 'town']], 'ship_county' => ['County / state', 'Buyer', ['county', 'state', 'province', 'region']], 'ship_postcode' => ['Postcode', 'Buyer', ['postcode', 'post code', 'zip code', 'zip', 'postal code']],
        'ship_country' => ['Country', 'Buyer', ['country']], 'ship_phone' => ['Phone', 'Buyer', ['phone number', 'phone', 'mobile', 'telephone', 'tel']],
    ];
    private const MONEY = ['unit_price', 'shipping_cost', 'total_cost']; private const ADDRESSY = ['address', 'name', 'city', 'zip', 'postcode', 'county', 'state', 'country', 'phone', 'street'];
    private const NOT_BUYER = ['store', 'shop', 'seller', 'supplier', 'product', 'carrier', 'item', 'company'];
    private const CUT = ['order_no' => 100, 'item_title' => 500, 'item_ref' => 150, 'variant' => 255, 'order_status' => 100, 'tracking_no' => 255, 'carrier' => 100, 'ship_name' => 255, 'ship_address' => 500, 'ship_city' => 150, 'ship_county' => 150, 'ship_postcode' => 30, 'ship_country' => 100, 'ship_phone' => 60];
    public const CURRENCIES = ['GBP', 'USD', 'EUR', 'CNY']; public const DATE_ORDERS = ['AUTO' => 'Auto (UK if unsure)', 'DMY' => 'Day-month-year (UK)', 'MDY' => 'Month-day-year (US)'];

    private static function dir(): string { $d = storage_path('app/ds_tmp'); if (! is_dir($d)) mkdir($d, 0775, true); return $d; }
    public static function maxMb(): int { return max(1, (int) Settings::get('DS_UPLOAD_MAX_MB', Context::ent(), 10)); }
    public static function maxRows(): int { return max(1, (int) Settings::get('DS_UPLOAD_MAX_ROWS', Context::ent(), 10000)); }

    // ---------- R-D-74: the forgiving reader and the value parsers ----------
    /** ['headers' => [...], 'rows' => [[line no, [header => value]], …], 'separator' => ',']. */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path); if ($raw === false || $raw === '') throw new \InvalidArgumentException('The file is empty or could not be opened.');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); if (! mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $head = substr($raw, 0, 5000); $sep = ','; $best = -1; foreach ([',', ';', "\t"] as $s) { $n = substr_count($head, $s); if ($n > $best) { $best = $n; $sep = $s; } }
        $h = fopen('php://temp', 'w+'); fwrite($h, $raw); rewind($h); $headers = null; $rows = []; $ln = 0;
        while (($c = fgetcsv($h, null, $sep, '"', '')) !== false) { $ln++; $c = array_map(fn ($v) => trim((string) $v), $c);
            if ($headers === null) { if (count(array_filter($c, fn ($v) => $v !== '')) < 3) continue;      // title lines above the header are skipped
                $seen = []; $headers = []; foreach ($c as $i => $v) { $name = $v === '' ? 'Column ' . ($i + 1) : $v; $k = mb_strtolower($name); $seen[$k] = ($seen[$k] ?? 0) + 1; $headers[] = $seen[$k] > 1 ? "$name ({$seen[$k]})" : $name; } continue; }
            if (! array_filter($c, fn ($v) => $v !== '')) continue; $row = []; foreach ($headers as $i => $name) $row[$name] = $c[$i] ?? ''; $rows[] = [$ln, $row]; }
        if ($headers === null) throw new \InvalidArgumentException('No header row was found (a row with at least 3 filled cells).');
        return ['headers' => $headers, 'rows' => $rows, 'separator' => $sep];
    }

    /** Money: currency codes and signs dropped, "(5.00)" = −5.00, "1.234,56" = 1234.56; mixed text like "2-pack £3" is refused (false). '' → null. */
    public static function money(mixed $v): float|null|false
    {
        $s = trim((string) $v); if ($s === '') return null;
        $t = trim(preg_replace('/\b(GBP|USD|EUR|CNY|RMB|CAD|AUD|HKD|NZD|SGD)\b|CA\$|AU\$|HK\$|NZ\$|SG\$|US\$|[£€¥$]/iu', '', $s));
        if (! preg_match('/^\(?\s*[+\-]?\s*[\d.,\s]+\)?$/', $t) || ! preg_match('/\d/', $t)) return false;
        return Parse::money($t);
    }

    public static function currencyOf(mixed $v): ?string
    {
        $s = (string) $v;
        foreach (['CA$' => 'CAD', 'AU$' => 'AUD', 'HK$' => 'HKD', 'NZ$' => 'NZD', 'SG$' => 'SGD', 'US$' => 'USD', '£' => 'GBP', '€' => 'EUR', '¥' => 'CNY', 'RMB' => 'CNY', 'CNY' => 'CNY', 'GBP' => 'GBP', 'USD' => 'USD', 'EUR' => 'EUR', '$' => 'USD'] as $sign => $code) if (mb_stripos($s, $sign) !== false) return $code;
        return null;
    }

    /** dmy / mdy / unclear / none over the date texts of a file. */
    public static function detectDateOrder(array $values): string
    {
        $any = false; $dmy = false; $mdy = false;
        foreach ($values as $v) if (preg_match('#^\s*(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})\b#', (string) $v, $m)) { $any = true; if ((int) $m[1] > 12) $dmy = true; if ((int) $m[2] > 12) $mdy = true; }
        return ! $any ? 'none' : ($dmy && ! $mdy ? 'dmy' : ($mdy && ! $dmy ? 'mdy' : 'unclear'));
    }

    public static function date(mixed $v, string $order = 'dmy'): string|false
    {
        $s = trim((string) $v);
        if ($order === 'mdy' && preg_match('#^(\d{1,2})([/\-.])(\d{1,2})\2(\d{2}|\d{4})(.*)$#', $s, $m)) $s = $m[3] . $m[2] . $m[1] . $m[2] . $m[4] . $m[5];      // month first → day first for the shared parser
        return Parse::date($s);
    }

    // ---------- R-D-75: guess and saved mapping ----------
    private static function norm(string $h): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9#]+/', ' ', mb_strtolower($h)))); }

    /** field => header (or null). Each column is used once; the best score wins. */
    /** MR-0987: column names this company has matched by hand before (any platform) – learned on every saved upload, used by the next guess. */
    public static function learned(?int $ent = null): array
    {
        $j = json_decode((string) Settings::get('DS_LEARNED_ALIASES', $ent ?? Context::ent(), '{}'), true); return is_array($j) ? $j : [];
    }

    /** Remember the headers of a saved mapping that the built-in names would not have found (one name per field, at most 30 per field). */
    public static function learn(int $ent, array $map): array
    {
        $have = self::learned($ent); $new = [];
        foreach ($map as $f => $h) { if (! $h || ! isset(self::FIELDS[$f])) continue; $n = self::norm((string) $h); if ($n === '') continue;
            if ($n === self::norm(self::FIELDS[$f][0]) || in_array($n, self::FIELDS[$f][2], true) || in_array($n, $have[$f] ?? [], true)) continue;
            $have[$f] = array_slice(array_merge($have[$f] ?? [], [$n]), -30); $new[$f] = $n; }
        if ($new) Settings::set('DS_LEARNED_ALIASES', json_encode($have), $ent, Context::userId());
        return $new;
    }

    public static function guess(array $headers): array
    {
        $cand = []; $learned = self::learned();
        foreach ($headers as $h) { $n = self::norm($h); $words = $n === '' ? [] : explode(' ', $n);
            foreach (self::FIELDS as $f => [$label, , $aliases]) {
                if ($n !== '' && in_array($n, $learned[$f] ?? [], true)) { $cand[] = [2000 + strlen($n), $f, $h]; continue; }      // matched by hand before: wins over a name guess
                if (in_array($f, self::MONEY, true) && array_intersect($words, self::ADDRESSY)) continue;                         // a money field never takes an address-type header
                if ($f === 'ship_name' && array_intersect($words, self::NOT_BUYER)) continue;                                      // the buyer name never comes from a store / seller / product column
                $score = 0; if ($n === self::norm($label)) $score = 1000 + strlen($n);
                foreach ($aliases as $a) { if ($n === $a) $score = max($score, 1000 + strlen($a)); elseif (preg_match('/(^| )' . preg_quote($a, '/') . '( |$)/', $n)) $score = max($score, 100 + 3 * strlen($a) - 2 * (count($words) - count(explode(' ', $a)))); }
                if ($score > 0) $cand[] = [$score, $f, $h];
            } }
        usort($cand, fn ($a, $b) => $b[0] <=> $a[0]); $map = array_fill_keys(array_keys(self::FIELDS), null); $used = [];
        foreach ($cand as [, $f, $h]) { if ($map[$f] !== null || isset($used[$h])) continue; $map[$f] = $h; $used[$h] = 1; }
        return $map;
    }

    /** The mapping to show: the one saved for company + platform when all its columns are in the file, else a fresh guess (and which saved columns are missing). */
    public static function mappingFor(int $platform, array $headers, int $mp = 0): array
    {
        $fromMp = fn (array $map) => $map;
        if ($mp) $fromMp = function (array $map) use ($mp, $headers) {      // the marketplace's own DS Columns and 5 Matching Rule Columns (R-A_SALES-66) come before a guess from the header names
            $low = []; foreach ($headers as $h) $low[mb_strtolower($h)] = $h; $used = []; $out = array_fill_keys(array_keys(self::FIELDS), null);
            $pairs = [['DS', ['order_no' => 'order_no', 'order_date' => 'd_order', 'cust_name' => 'ship_name', 'sku' => 'item_ref', 'product_info' => 'item_title', 'qty' => 'qty', 'sale_rate' => 'unit_price', 'total_amount' => 'total_cost']], ['MATCH', ['ship_name' => 'ship_name', 'ship_address' => 'ship_full_address', 'ship_city' => 'ship_city', 'ship_postcode' => 'ship_postcode', 'ship_phone' => 'ship_phone']]];
            foreach ($pairs as [$set, $to]) foreach (SalesImport::columns($mp, $set) as $f => $names) foreach ($names as $n) { $h = $low[mb_strtolower($n)] ?? null; $t = $to[$f] ?? null; if ($h && $t && $out[$t] === null && ! isset($used[$h])) { $out[$t] = $h; $used[$h] = 1; break; } }
            foreach ($map as $f => $h) if ($out[$f] === null && $h !== null && ! isset($used[$h])) { $out[$f] = $h; $used[$h] = 1; }
            return $out;
        };
        $saved = DB::table('drp_platform_mappings')->where('fk_id_ent', Context::ent())->where('fk_platform', $platform)->first(); $map = $saved ? (array) json_decode((string) $saved->mapping_json, true) : [];
        if ($map) { $missing = array_values(array_diff(array_filter($map), $headers));
            if (! $missing) return ['mapping' => $map + array_fill_keys(array_keys(self::FIELDS), null), 'source' => 'saved', 'note' => 'Columns filled in from the last upload – check them.', 'missing' => [], 'date_order' => $saved->date_order ?: null];
            return ['mapping' => $fromMp(self::guess($headers)), 'source' => 'guess', 'note' => 'Columns used last time are missing: ' . implode(', ', $missing) . '. Every column was guessed again – check them.', 'missing' => $missing, 'date_order' => null]; }
        return ['mapping' => $fromMp(self::guess($headers)), 'source' => 'guess', 'note' => 'The columns were guessed from their names and from the DS Columns of the marketplace – check them.', 'missing' => [], 'date_order' => null];
    }

    /** Problems block the upload; warnings do not. */
    public static function checkMapping(array $map): array
    {
        $p = []; $w = []; $used = array_filter($map); if (count($used) !== count(array_unique($used))) $p[] = 'A column is used for two fields. Each column can be used once.';
        if (empty($map['order_no']) || empty($map['d_order'])) $p[] = 'Order number and Order date are required.';
        if (empty($map['item_title']) && empty($map['item_ref'])) $p[] = 'Product title or Supplier SKU / item id is required.';
        if (empty($map['total_cost']) && empty($map['unit_price'])) $p[] = 'Total paid or Unit price is required.';
        if (! array_filter(array_intersect_key($map, array_flip(['ship_name', 'ship_address1', 'ship_full_address', 'ship_city', 'ship_postcode', 'ship_phone'])))) $w[] = 'No buyer details are mapped: these rows cannot link to sales.';
        if (empty($map['qty'])) $w[] = 'Quantity is not mapped: every row counts as 1.';
        return ['problems' => $p, 'warnings' => $w];
    }

    // ---------- R-D-73: step 1 – the file is held under a token ----------
    public static function shopOf(array $d, ?object $u): object
    {
        Perm::need($u, 'orders.dsUpload');
        if (empty($d['marketplace'])) throw new \InvalidArgumentException('Select a marketplace.'); if (empty($d['shop'])) throw new \InvalidArgumentException('Select a shop.');
        $m = (int) ($d['month'] ?? 0); $y = (int) ($d['year'] ?? 0); if ($m < 1 || $m > 12 || $y < (int) date('Y') - 3 || $y > (int) date('Y') + 1) throw new \InvalidArgumentException('Select the month and year. The Sales import of the same shop and month is matched with them.');
        if (empty($d['platform']) || ! DB::table('drp_platforms')->where('id', (int) $d['platform'])->where('f_active', 1)->exists()) throw new \InvalidArgumentException('Select the platform the orders were bought on.');
        $shop = DB::table('t_branches')->where('id', (int) $d['shop'])->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Shop not found.');
        if (! (int) $shop->f_active) throw new \InvalidArgumentException('Shop ' . SalesImport::label($shop) . ' is inactive. Choose an active shop.');
        if ((int) $shop->fk_id_marketplace !== (int) $d['marketplace']) throw new \InvalidArgumentException('Shop ' . SalesImport::label($shop) . ' belongs to a different marketplace.');
        if (! Perm::shopAllowed($u, (int) $shop->id)) throw new \InvalidArgumentException("You don't have rights for shop " . SalesImport::label($shop) . '.');
        return $shop;
    }

    public static function sizeCheck(string $name, int $size): void
    {
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) throw new \InvalidArgumentException('The file must be a .csv or .txt file.');
        if ($size <= 0) throw new \InvalidArgumentException('The file is empty.');
        if ($size > self::maxMb() * 1024 * 1024) throw new \InvalidArgumentException('The file is larger than ' . self::maxMb() . ' MB. Split it and upload the parts.');
    }

    public static function stage(string $path, string $name, int $size, array $d, ?object $u): array
    {
        self::shopOf($d, $u); self::sizeCheck($name, $size); $csv = self::read($path);
        if (count($csv['rows']) > self::maxRows()) throw new \InvalidArgumentException('The file has ' . count($csv['rows']) . ' rows; at most ' . self::maxRows() . ' rows can be uploaded at once. Split it and upload the parts.');
        if (! $csv['rows']) throw new \InvalidArgumentException('The file has a header row but no order rows.');
        // held files are removed by the nightly job mo:cleanup-temp (R-G_CORE-75), never on a page action
        $token = bin2hex(random_bytes(16)); copy($path, self::dir() . "/$token.csv");
        file_put_contents(self::dir() . "/$token.json", json_encode(['user' => $u->id, 'ent' => Context::ent(), 'name' => $name, 'at' => time()] + array_intersect_key($d, array_flip(['marketplace', 'shop', 'month', 'year', 'platform', 'mode']))));
        $samples = []; foreach ($csv['headers'] as $h) { $samples[$h] = ''; foreach ($csv['rows'] as [, $r]) if (($r[$h] ?? '') !== '') { $samples[$h] = mb_substr($r[$h], 0, 60); break; } }
        $mp = self::mappingFor((int) $d['platform'], $csv['headers'], (int) $d['marketplace']); $chk = self::checkMapping($mp['mapping']);
        return ['token' => $token, 'file' => $name, 'rows' => count($csv['rows']), 'headers' => $csv['headers'], 'samples' => $samples, 'fields' => array_map(fn ($f) => ['label' => $f[0], 'group' => $f[1]], self::FIELDS)] + $mp + ['warnings' => $chk['warnings'], 'problems' => $chk['problems'], 'currencies' => self::CURRENCIES, 'date_orders' => self::DATE_ORDERS];
    }

    private static function held(string $token, ?object $u): array
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $token) || ! is_file(self::dir() . "/$token.json")) throw new \InvalidArgumentException('The uploaded file is no longer held (held files are removed by the nightly clean-up). Upload it again.');
        $meta = json_decode((string) file_get_contents(self::dir() . "/$token.json"), true);
        if ((int) $meta['user'] !== (int) ($u->id ?? 0) || (int) $meta['ent'] !== (int) Context::ent()) throw new \InvalidArgumentException('This uploaded file belongs to another person.');
        return $meta;
    }

    // ---------- R-D-76 / 77 / 78: check (nothing saved) or apply ----------
    /** $o: mapping, default_currency, date_order (AUTO | DMY | MDY), mode (skip | replace), confirm_replace. */
    public static function run(string $token, array $o, ?object $u, bool $apply): array
    {
        $meta = self::held($token, $u); $shop = self::shopOf($meta, $u); $ent = (int) Context::ent(); $csv = self::read(self::dir() . "/$token.csv");
        $map = array_intersect_key((array) ($o['mapping'] ?? []), self::FIELDS) + array_fill_keys(array_keys(self::FIELDS), null); foreach ($map as $f => $h) if ($h !== null && $h !== '' && ! in_array($h, $csv['headers'], true)) throw new \InvalidArgumentException("Column \"$h\" is not in the file."); $map = array_map(fn ($h) => $h === '' ? null : $h, $map);
        $chk = self::checkMapping($map); if ($chk['problems']) throw new \InvalidArgumentException(implode(' ', $chk['problems']));
        $mode = ($o['mode'] ?? $meta['mode'] ?? 'skip') === 'replace' ? 'replace' : 'skip'; $defCur = in_array($o['default_currency'] ?? 'GBP', self::CURRENCIES, true) ? ($o['default_currency'] ?? 'GBP') : 'GBP';
        $detected = self::detectDateOrder(array_map(fn ($r) => $r[1][$map['d_order']] ?? '', $csv['rows'])); $choice = strtoupper((string) ($o['date_order'] ?? ''));
        if ($detected === 'unclear' && ! isset(self::DATE_ORDERS[$choice])) return ['ask_date_order' => true, 'message' => 'The dates can be read as UK (day first) or US (month first). Which one is it?', 'options' => self::DATE_ORDERS];      // unclear → stop and ask
        $order = $detected === 'mdy' || ($detected === 'unclear' && $choice === 'MDY') ? 'mdy' : 'dmy'; if ($detected === 'dmy') $order = 'dmy';
        $platform = (int) $meta['platform']; $m = (int) $meta['month']; $y = (int) $meta['year']; $val = fn (array $r, string $f) => $map[$f] ? trim((string) ($r[$map[$f]] ?? '')) : '';
        $st = ['rows' => count($csv['rows']), 'new' => 0, 'replaced' => 0, 'ignored' => 0, 'repeated' => 0, 'joined' => 0, 'errors' => 0, 'outside_period' => 0]; $errors = []; $items = []; $seenFull = []; $dates = [];
        $q = fn (string $v) => '"' . mb_substr($v, 0, 40) . '"';
        foreach ($csv['rows'] as [$ln, $r]) {
            $bad = function (string $why) use (&$errors, &$st, $ln, $r) { $st['errors']++; $errors[] = ['row' => $ln, 'reason' => $why, 'raw' => $r]; };
            $full = md5(json_encode($r)); if (isset($seenFull[$full])) { $st['repeated']++; continue; } $seenFull[$full] = 1;
            $no = trim($val($r, 'order_no'), " \t\"'#"); if ($no === '') { $bad('Order number is empty.'); continue; }
            if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $no)) { $bad("Order number {$q($no)} was rounded by Excel. Export the file again and upload it without opening it in Excel."); continue; }
            $dt = self::date($val($r, 'd_order'), $order); if ($dt === false || $dt === '') { $bad('Order date ' . $q($val($r, 'd_order')) . ' is empty or not a date the tool can read.'); continue; }
            if ($dt < '2000-01-01' || $dt > date('Y-m-d', strtotime('+2 days'))) { $bad("Order date {$q($val($r, 'd_order'))} must be between 01-01-2000 and today."); continue; }
            $title = $val($r, 'item_title'); $ref = $val($r, 'item_ref'); if ($title === '' && $ref === '') { $bad('Product title and item id are both empty.'); continue; }
            $qtyRaw = $val($r, 'qty'); $qty = 1; if ($map['qty'] && $qtyRaw !== '') { $qn = trim(preg_replace('/\s*(pcs|pc|x|units|items)\.?$/i', '', $qtyRaw)); if (! preg_match('/^\d+$/', $qn) || (int) $qn < 1) { $bad("Quantity {$q($qtyRaw)} is not a whole number of 1 or more."); continue; } $qty = (int) $qn; if ($qty > 100000) { $bad("Quantity {$q($qtyRaw)} is more than 100,000."); continue; } }
            $mon = []; $stop = false; foreach (self::MONEY as $f) { $x = self::money($val($r, $f)); if ($x === false) { $bad(self::FIELDS[$f][0] . ' ' . $q($val($r, $f)) . ' is not an amount.'); $stop = true; break; } if ($x !== null && $x < 0) { $bad(self::FIELDS[$f][0] . ' ' . $q($val($r, $f)) . ' is below zero: a refund line is not a purchase.'); $stop = true; break; } if ($x !== null && $x > 9999999999) { $bad(self::FIELDS[$f][0] . ' ' . $q($val($r, $f)) . ' is too large.'); $stop = true; break; } $mon[$f] = $x; } if ($stop) continue;
            $ship = (float) ($mon['shipping_cost'] ?? 0); $unit = $mon['unit_price']; $total = $mon['total_cost'];
            if ($total === null && $unit === null) { $bad('Total paid and Unit price are both empty.'); continue; }
            if ($total === null) $total = round($unit * $qty + $ship, 2); if ($unit === null) $unit = round(($total - $ship) / $qty, 2);
            $cur = strtoupper($val($r, 'currency')); if (! preg_match('/^[A-Z]{3}$/', $cur)) $cur = self::currencyOf($val($r, 'total_cost') . ' ' . $val($r, 'unit_price')) ?? $defCur;
            $addr = $val($r, 'ship_full_address') !== '' ? $val($r, 'ship_full_address') : trim($val($r, 'ship_address1') . ', ' . $val($r, 'ship_address2'), ', ');
            $variant = $val($r, 'variant'); $key = sha1($ent . '|' . $platform . '|' . mb_strtolower($no) . '|' . ($ref !== '' ? 'ref:' . mb_strtolower($ref) : 'title:' . self::norm($title)) . '|' . mb_strtolower($variant) . '|' . $dt);
            $it = ['order_no' => $no, 'd_order' => $dt, 'item_title' => $title, 'item_ref' => $ref, 'variant' => $variant, 'qty' => $qty, 'unit_price' => $unit, 'shipping_cost' => $ship, 'total_cost' => $total, 'currency' => $cur, 'order_status' => $val($r, 'order_status'), 'tracking_no' => $val($r, 'tracking_no'), 'carrier' => $val($r, 'carrier'),
                'ship_name' => $val($r, 'ship_name'), 'ship_address' => $addr, 'ship_city' => $val($r, 'ship_city'), 'ship_county' => $val($r, 'ship_county'), 'ship_postcode' => mb_strtoupper($val($r, 'ship_postcode')), 'ship_country' => $val($r, 'ship_country'), 'ship_phone' => $val($r, 'ship_phone')];
            foreach (self::CUT as $f => $len) $it[$f] = mb_substr((string) $it[$f], 0, $len);      // cut to the column length – never refused
            if (isset($items[$key])) { $a = &$items[$key]; $a['qty'] += $qty; $a['shipping_cost'] = round($a['shipping_cost'] + $ship, 2); $a['total_cost'] = round($a['total_cost'] + $total, 2); $a['unit_price'] = round(($a['total_cost'] - $a['shipping_cost']) / $a['qty'], 2);      // separate lines of one item are joined
                if ($it['tracking_no'] !== '' && ! str_contains($a['tracking_no'], $it['tracking_no'])) $a['tracking_no'] = mb_substr(trim($a['tracking_no'] . ', ' . $it['tracking_no'], ', '), 0, 255); $a['_raw']['_merged_lines'][] = $r; $a['_joined'] = ($a['_joined'] ?? 1) + 1; $st['joined']++; unset($a); continue; }
            $items[$key] = $it + ['_raw' => $r, '_line' => $ln]; $dates[] = $dt;
        }
        $from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime($from)); $have = DB::table('drp_items')->whereIn('dup_key', array_keys($items) ?: ['-'])->get()->keyBy('dup_key');
        foreach ($items as $key => $it) { if ($it['d_order'] < $from || $it['d_order'] > $to) $st['outside_period']++; if (isset($have[$key])) $st[$mode === 'replace' ? 'replaced' : 'ignored']++; else $st['new']++; }
        $mn = SalesImport::monthName($m, $y); $warnings = $chk['warnings']; if ($st['outside_period']) $warnings[] = "{$st['outside_period']} row(s) have an order date outside $mn; they are saved under $mn.";
        $msg = "$mn: {$st['new']} new row(s) added, " . ($mode === 'replace' ? "{$st['replaced']} replaced" : "{$st['ignored']} already saved and ignored") . ", {$st['repeated']} repeated, {$st['joined']} joined, {$st['errors']} row(s) with errors not saved.";
        $res = ['message' => $msg, 'applied' => $apply, 'stats' => $st, 'mode' => $mode, 'dates_read_as' => $detected === 'none' ? 'date text' : ($order === 'mdy' ? 'month-day-year (US)' : 'day-month-year (UK)'), 'date_range' => $dates ? [min($dates), max($dates)] : null, 'warnings' => $warnings,
            'errors' => array_map(fn ($e) => ['row' => $e['row'], 'reason' => $e['reason']], array_slice($errors, 0, 100)), 'errors_more' => max(0, count($errors) - 100), 'advice' => $errors ? 'Fix these rows in the file and upload it again with "Ignore it" – the rows already saved are skipped.' : null,
            'chips' => ['New' => $st['new'], 'Already in' => $st['replaced'] + $st['ignored'], 'Joined' => $st['joined'], 'Repeated' => $st['repeated'], 'Outside month' => $st['outside_period']]];
        if (! $apply) return $res;
        if ($mode === 'replace' && $st['replaced'] && empty($o['confirm_replace'])) return ['confirm_replace' => true, 'message' => "{$st['replaced']} row(s) already saved will be OVERWRITTEN with this file and moved to " . SalesImport::label($shop) . ", $mn. Continue?"] + $res;
        return DB::transaction(function () use ($items, $have, $mode, $meta, $shop, $platform, $m, $y, $ent, $u, $st, $errors, $dates, $map, $order, $res, $token) {
            $imp = (int) DB::table('drp_imports')->insertGetId(RecordService::fillNotNull('drp_imports', ['fk_id_ent' => $ent, 'fk_platform' => $platform, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'fk_id_branch' => (int) $shop->id, 'file_name' => mb_substr((string) $meta['name'], 0, 255), 'dup_mode' => $mode, 'total_rows' => $st['rows'], 'inserted_rows' => $st['new'], 'replaced_rows' => $st['replaced'],
                'skipped_rows' => $st['ignored'] + $st['repeated'], 'merged_rows' => $st['joined'], 'error_rows' => $st['errors'], 'd_from' => $dates ? min($dates) : null, 'd_to' => $dates ? max($dates) : null, 'uploaded_by' => $u->id, 'uploaded_by_name' => (string) ($u->display_name ?: $u->login_id), 'uploaded_at' => now(), 'period_month' => $m, 'period_year' => $y]));
            foreach ($items as $key => $it) { $raw = json_encode($it['_raw'], JSON_UNESCAPED_UNICODE); unset($it['_raw'], $it['_line'], $it['_joined']);
                $row = $it + ['fk_id_ent' => $ent, 'fk_platform' => $platform, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'fk_id_branch' => (int) $shop->id, 'raw_json' => $raw, 'period_month' => $m, 'period_year' => $y];
                if (isset($have[$key])) { $old = $have[$key];
                    if ($mode === 'replace') { DB::table('imp_batch_effects')->insert(['fk_id_batch' => $imp, 'table_name' => 'drp_items', 'record_id' => $old->id, 'effect' => 'UPDATE', 'before_json' => json_encode((array) $old)]);      // what it was, so an undo can put it back
                        DB::table('drp_items')->where('id', $old->id)->update($row + ['fk_import_last' => $imp, 'updated_by' => $u->id, 'updated_at' => now()]); DB::table('drp_item_imports')->insert(['fk_item' => $old->id, 'fk_import' => $imp, 'action' => 'replaced']); }
                    else DB::table('drp_item_imports')->insert(['fk_item' => $old->id, 'fk_import' => $imp, 'action' => 'skipped']);
                } else { $id = (int) DB::table('drp_items')->insertGetId(RecordService::fillNotNull('drp_items', $row + ['dup_key' => $key, 'fk_import' => $imp, 'fk_import_last' => $imp, 'created_by' => $u->id, 'created_at' => now()])); DB::table('drp_item_imports')->insert(['fk_item' => $id, 'fk_import' => $imp, 'action' => 'added']); }
            }
            foreach ($errors as $e) DB::table('drp_import_errors')->insert(['fk_import' => $imp, 'row_no' => $e['row'], 'reason' => mb_substr($e['reason'], 0, 255), 'raw_json' => json_encode($e['raw'], JSON_UNESCAPED_UNICODE)]);
            DB::table('drp_platform_mappings')->updateOrInsert(['fk_id_ent' => $ent, 'fk_platform' => $platform], ['mapping_json' => json_encode($map), 'date_order' => strtoupper($order), 'updated_by' => $u->id, 'updated_at' => now()]);      // the mapping and the date order are remembered
            self::learn($ent, $map);      // MR-0987 the next file of any platform finds these columns by itself
            if ($errors) TaskEngine::open('IMP_PROBLEM', ['drp', $imp], count($errors) . ' row(s) of the drop shipping upload ' . mb_substr((string) $meta['name'], 0, 80) . ' were not saved', ['ent' => $ent, 'module' => 'drp_imports', 'record_id' => $imp, 'fix_url' => '#/ds-upload']);
            Audit::log('drp_imports', $imp, 'INSERT', ['file' => $meta['name'], 'new' => $st['new'], 'replaced' => $st['replaced'], 'errors' => $st['errors']], null, $ent);
            EventBus::fire('DS_BATCH_APPLIED', 'drp_imports', $imp, ['shop' => (int) $shop->id, 'month' => $m, 'year' => $y, 'new' => $st['new'], 'replaced' => $st['replaced']], $ent);
            foreach (['csv', 'json'] as $x) @unlink(self::dir() . "/$token.$x");
            return ['import' => $imp] + $res;
        });
    }

    // ---------- R-D-78: history, errors, undo ----------
    public static function history(?object $u): array
    {
        return Perm::mine(DB::table('drp_imports as i'), 'i.fk_id_branch')->leftJoin('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->leftJoin('drp_platforms as p', 'p.id', '=', 'i.fk_platform')->where('i.fk_id_ent', Context::ent())->orderByDesc('i.id')->limit(50)
            ->get(['i.*', 'b.shop_no', 'b.shop_name', 'p.platform_name'])->map(fn ($i) => ['id' => (int) $i->id, 'at' => $i->uploaded_at ? date('d-m-Y H:i', strtotime((string) $i->uploaded_at)) : '', 'by' => $i->uploaded_by_name, 'shop' => $i->shop_no ? $i->shop_no . ' (' . $i->shop_name . ')' : 'No shop', 'platform' => $i->platform_name, 'file' => $i->file_name,
                'period' => $i->period_month ? SalesImport::monthName((int) $i->period_month, (int) $i->period_year) : '', 'range' => $i->d_from ? date('d-m-Y', strtotime((string) $i->d_from)) . ' – ' . date('d-m-Y', strtotime((string) $i->d_to)) : '', 'mode' => $i->dup_mode === 'replace' ? 'Replace' : 'Ignore',
                'rows' => (int) $i->total_rows, 'new' => (int) $i->inserted_rows, 'replaced' => (int) $i->replaced_rows, 'ignored' => (int) $i->skipped_rows, 'joined' => (int) $i->merged_rows, 'errors' => (int) $i->error_rows])->all();
    }

    public static function errors(int $importId, ?object $u): array
    {
        $i = DB::table('drp_imports')->where('id', $importId)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Upload not found.'); if (! Perm::shopAllowed($u, (int) $i->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        $see = $u && Perm::has($u, 'orders.dsSeeContact');
        return ['rows' => DB::table('drp_import_errors')->where('fk_import', $importId)->orderBy('row_no')->limit(1000)->get(['row_no as row', 'reason', 'raw_json'])->map(fn ($e) => ['row' => (int) $e->row, 'reason' => $e->reason, 'raw' => $see ? json_decode((string) $e->raw_json, true) : null])->all(), 'raw_hidden' => ! $see];
    }

    /** Undo an upload: rows only in this upload are deleted; rows also in other uploads are kept (their first / last upload pointers move); replaced rows get their previous values back. */
    public static function undo(int $importId, ?object $u): array
    {
        Perm::need($u, 'orders.dsUndo');
        return DsMatch::locked(fn () => DB::transaction(function () use ($importId) {
            $i = DB::table('drp_imports')->where('id', $importId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Upload not found.');
            $deleted = 0; $restored = 0; $kept = 0; $blocked = [];
            foreach (DB::table('imp_batch_effects')->where('fk_id_batch', $importId)->where('table_name', 'drp_items')->orderByDesc('id')->get() as $e) { $before = (array) json_decode((string) $e->before_json, true); unset($before['id']); DB::table('drp_items')->where('id', $e->record_id)->update($before); $restored++; }
            foreach (DB::table('drp_item_imports')->where('fk_import', $importId)->get() as $l) {
                $others = DB::table('drp_item_imports')->where('fk_item', $l->fk_item)->where('fk_import', '<>', $importId)->pluck('fk_import');
                if ($others->isEmpty()) { if ($s = DB::table('t_sales')->where('fk_drp_item', $l->fk_item)->first()) { $blocked[] = $s->order_no; continue; } DB::table('drp_items')->where('id', $l->fk_item)->delete(); $deleted++; }
                else { DB::table('drp_items')->where('id', $l->fk_item)->update(['fk_import' => $others->min(), 'fk_import_last' => $others->max()]); $kept++; }
            }
            if ($blocked) throw new \InvalidArgumentException(count($blocked) . ' row(s) of this upload are linked to sale lines (orders ' . implode(', ', array_slice($blocked, 0, 8)) . '). Unlink them on the Sales page first, then undo the upload.');
            DB::table('imp_batch_effects')->where('fk_id_batch', $importId)->where('table_name', 'drp_items')->delete(); DB::table('drp_item_imports')->where('fk_import', $importId)->delete(); DB::table('drp_import_errors')->where('fk_import', $importId)->delete(); DB::table('drp_imports')->where('id', $importId)->delete();
            TaskEngine::close('IMP_PROBLEM', ['drp', $importId], 'Upload undone'); Audit::log('drp_imports', $importId, 'DELETE', ['file' => $i->file_name, 'deleted' => $deleted, 'restored' => $restored, 'kept' => $kept], 'Drop shipping upload undone', (int) $i->fk_id_ent);
            return ['message' => "Upload undone: $deleted row(s) deleted, $restored restored to what they were, $kept kept because another upload holds them too.", 'deleted' => $deleted, 'restored' => $restored, 'kept' => $kept];
        }));
    }

    // ---------- R-D-86: supplier platforms ----------
    public static function addPlatform(string $name, ?object $u): array
    {
        Perm::need($u, 'orders.dsUpload'); $name = trim(preg_replace('/\s+/', ' ', $name)); if ($name === '' || mb_strlen($name) > 100) throw new \InvalidArgumentException('The platform name must be 1 to 100 characters.');
        if ($p = DB::table('drp_platforms')->whereRaw('LOWER(platform_name) = ?', [mb_strtolower($name)])->first()) { DB::table('drp_platforms')->where('id', $p->id)->update(['f_active' => 1]); return ['id' => (int) $p->id, 'name' => $p->platform_name, 'message' => $p->platform_name . ' is already in the list' . ((int) $p->f_active ? '.' : ' – it is active again.')]; }
        if (! Perm::has($u, 'orders.dsPlatformAdd')) { $id = Approvals::request((int) Context::ent(), 'DS_PLATFORM_ADD', 'drp_platforms', 0, 0.0, ['name' => $name, 'by' => (int) $u->id], 'New supplier platform "' . $name . '"'); return ['pending' => true, 'approval' => $id, 'message' => 'Platform "' . $name . '" was proposed. It can be used after a manager approves it.']; }
        $id = (int) DB::table('drp_platforms')->insertGetId(['platform_name' => $name, 'f_active' => 1, 'created_by' => $u->id, 'created_at' => now()]); Audit::log('drp_platforms', $id, 'INSERT', ['platform_name' => $name]);
        return ['id' => $id, 'name' => $name, 'message' => 'Platform "' . $name . '" added.'];
    }

    public static function home(?object $u): array
    {
        Perm::need($u, 'orders.dsUpload'); $ent = Context::ent(); $shops = array_values(array_filter(ShopLabel::list($u), fn ($s) => (int) $s['active']));
        return ['shops' => array_map(fn ($s) => ['id' => $s['id'], 'marketplace' => $s['marketplace'], 'name' => $s['label']], $shops),
            'marketplaces' => DB::table('cat_marketplaces')->whereIn('id', array_unique(array_column($shops, 'marketplace')) ?: [0])->orderBy('marketplace_descr')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name")]),
            'platforms' => DB::table('drp_platforms')->where('f_active', 1)->orderBy('platform_name')->get(['id', 'platform_name as name']), 'years' => range((int) date('Y') + 1, (int) date('Y') - 3), 'max_mb' => self::maxMb(), 'max_rows' => self::maxRows(),
            'can_undo' => Perm::has($u, 'orders.dsUndo'), 'history' => self::history($u)];
    }
}
