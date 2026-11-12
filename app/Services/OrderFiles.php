<?php

namespace App\Services;

/**
 * Order lists of the marketplaces loaded as order lines (through SalesService::intake, so stock is reserved and nothing is loaded twice).
 * The format is found from the file itself:
 *  - EBAY_ORDERS  eBay Seller Hub › Orders › Download (the "Orders report" CSV): built on the header of the owner's real reports.
 *  - TEMU_ORDERS  Temu Seller Center › Orders › Export: built on the header of the owner's real file.
 *  - MAPPED       any other table with an order number, a SKU and a quantity column, read by the column names.
 * Buyer name, phone and address are kept on the order line only because the parcel needs them.
 */
class OrderFiles
{
    public const FORMATS = ['EBAY_ORDERS' => 'eBay orders report', 'TEMU_ORDERS' => 'Temu order list', 'MAPPED' => 'Order table read by its column names'];

    private static function key(mixed $h): string { return MarketplaceFiles::keyOf($h); }

    public static function detect(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $h = array_map(fn ($x) => self::key($x), $r);
            if (in_array('sales record number', $h, true) && in_array('order number', $h, true)) return ['EBAY_ORDERS', $i];
            if (in_array('order item id', $h, true) && in_array('order settlement status', $h, true)) return ['TEMU_ORDERS', $i];
        }
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            $h = array_map(fn ($x) => self::key($x), $r);
            if (array_intersect($h, ['order id', 'order number', 'order no', 'order-id', 'order reference']) && array_intersect($h, ['sku', 'seller sku', 'custom label', 'contribution sku', 'merchant sku'])) return ['MAPPED', $i];
        }
        throw new \InvalidArgumentException('This file is not an order list the tool knows: no header line with an order number and a SKU column was found.');
    }

    /** Read the file into order lines. Nothing is saved. */
    public static function read(string $path, string $ext, array $opt = []): array
    {
        $rows = MarketplaceFiles::rows($path, $ext); [$fmt, $hi] = self::detect($rows);
        $h = array_map(fn ($x) => self::key($x), $rows[$hi]); $ix = []; foreach ($h as $i => $n) if ($n !== '' && ! isset($ix[$n])) $ix[$n] = $i;
        $g = function (array $r, string ...$names) use ($ix) { foreach ($names as $n) if (isset($ix[$n])) { $v = trim((string) ($r[$ix[$n]] ?? '')); if ($v !== '' && $v !== '--') return $v; } return ''; };
        $lines = []; $skipped = []; $cur = strtoupper((string) ($opt['currency'] ?? ''));
        $data = array_slice($rows, $hi + 1);
        if ($fmt === 'EBAY_ORDERS') {
            $order = null;
            foreach ($data as $n => $r) {
                $no = $g($r, 'order number'); $item = $g($r, 'item number'); $sku = $g($r, 'custom label') ?: $item;
                if ($no === '' && $item === '') continue;                                        // blank lines and the "n record(s) downloaded" footer
                // item lines of an order with several items repeat the order number but leave the buyer, address and dates empty: they keep their head line
                if ($no !== '' && (! $order || $order['no'] !== $no || $g($r, 'sale date', 'paid on date') !== '')) $order = ['no' => $no, 'date' => MarketplaceFiles::day($g($r, 'sale date', 'paid on date')), 'r' => $r];
                if ($sku === '') continue;                                                        // head line of an order with several items: the items follow
                $o = $order ?? ['no' => $no, 'date' => null, 'r' => $r]; $head = $o['r'];
                $qty = (int) MarketplaceFiles::num($g($r, 'quantity')); $unit = MarketplaceFiles::num($g($r, 'sold for'));
                if ($qty <= 0) { $skipped[] = "Order {$o['no']} ($sku): quantity 0"; continue; }
                $lines[] = ['order_no' => $o['no'], 'd_order' => $o['date'] ?? date('Y-m-d'), 'sku' => $sku, 'qty' => $qty, 'sale_rate' => $unit, 'product_info' => mb_substr($g($r, 'item title'), 0, 250),
                    'cust_name' => $g($head, 'buyer name'), 'ship_to_name' => $g($head, 'post to name') ?: $g($head, 'buyer name'), 'ship_to_phone' => $g($head, 'post to phone'),
                    'ship_to_address' => trim($g($head, 'post to address 1') . ', ' . $g($head, 'post to address 2'), ', '), 'ship_to_city' => $g($head, 'post to city'), 'ship_to_postcode' => $g($head, 'post to postcode'),
                    'ship_to_country_code' => self::country($g($head, 'post to country')), 'tracking_no' => $g($r, 'tracking number') ?: null, 'dispatch_by' => MarketplaceFiles::day($g($head, 'post by date')), 'postage' => MarketplaceFiles::num($g($r, 'postage and packaging'))] + ($cur ? ['currency' => $cur] : []);
            }
        } elseif ($fmt === 'TEMU_ORDERS') {
            $value = (string) ($opt['temu_value'] ?? 'BASE');
            foreach ($data as $r) {
                $no = $g($r, 'order id'); if ($no === '') continue;
                $status = mb_strtolower($g($r, 'order item status', 'order status'));
                $qty = (int) MarketplaceFiles::num($g($r, 'quantity purchased')) - (int) MarketplaceFiles::num($g($r, 'quantity canceled'));
                $sku = $g($r, 'contribution sku') ?: $g($r, 'sku id');
                if (str_contains($status, 'cancel') || $qty <= 0) { $skipped[] = "Order $no ($sku): cancelled"; continue; }
                if ($sku === '') { $skipped[] = "Order $no: no SKU"; continue; }
                // what the seller is paid: Temu's base price for the item (the default) – or what the buyer paid (retail price total)
                $unit = $value === 'RETAIL' ? round(MarketplaceFiles::num($g($r, 'retail price total')) / max(1, $qty), 2) : MarketplaceFiles::num($g($r, 'activity goods base price') ?: $g($r, 'goods base price'));
                $lines[] = ['order_no' => $no, 'd_order' => MarketplaceFiles::day($g($r, 'purchase date')) ?? date('Y-m-d'), 'sku' => $sku, 'qty' => $qty, 'sale_rate' => $unit, 'product_info' => mb_substr($g($r, 'product name', 'product name by customer order'), 0, 250),
                    'cust_name' => $g($r, 'recipient name'), 'ship_to_name' => $g($r, 'recipient name'), 'ship_to_phone' => $g($r, 'recipient phone number'), 'ship_to_address' => trim(implode(', ', array_filter([$g($r, 'ship address 1'), $g($r, 'ship address 2'), $g($r, 'ship address 3')])), ', '),
                    'ship_to_city' => $g($r, 'ship city'), 'ship_to_postcode' => $g($r, 'ship postal code (must be shipped to the following zip code.)', 'ship postal code'), 'ship_to_country_code' => self::country($g($r, 'ship country')),
                    'tracking_no' => $g($r, 'tracking number') ?: null, 'dispatch_by' => MarketplaceFiles::day($g($r, 'latest shipping time'))] + ($cur ? ['currency' => $cur] : []);
            }
        } else {
            foreach ($data as $r) {
                $no = $g($r, 'order id', 'order number', 'order no', 'order-id', 'order reference'); $sku = $g($r, 'sku', 'seller sku', 'custom label', 'contribution sku', 'merchant sku');
                if ($no === '' || $sku === '') continue;
                $qty = (int) MarketplaceFiles::num($g($r, 'quantity', 'qty', 'quantity purchased', 'units') ?: '1');
                $unit = MarketplaceFiles::num($g($r, 'unit price', 'item price', 'price', 'sold for', 'selling price'));
                if ($unit == 0.0 && ($t = MarketplaceFiles::num($g($r, 'total', 'item total', 'order total', 'line total')))) $unit = round($t / max(1, $qty), 2);
                if ($qty <= 0) { $skipped[] = "Order $no ($sku): quantity 0"; continue; }
                $lines[] = ['order_no' => $no, 'd_order' => MarketplaceFiles::day($g($r, 'order date', 'purchase date', 'date', 'sale date', 'created date')) ?? date('Y-m-d'), 'sku' => $sku, 'qty' => $qty, 'sale_rate' => $unit, 'product_info' => mb_substr($g($r, 'title', 'product name', 'item title', 'product'), 0, 250),
                    'cust_name' => $g($r, 'buyer name', 'customer name', 'recipient name', 'name'), 'ship_to_postcode' => $g($r, 'postcode', 'post code', 'zip', 'postal code'), 'ship_to_country_code' => self::country($g($r, 'country', 'ship country'))] + ($cur ? ['currency' => $cur] : []);
            }
        }
        if (! $lines && ! $skipped) throw new \InvalidArgumentException('No order line was found under the header of this file.');
        return ['format' => $fmt, 'format_name' => self::FORMATS[$fmt], 'lines' => $lines, 'skipped' => $skipped];
    }

    private static function country(string $c): ?string
    {
        $c = trim($c); if ($c === '') return null; if (strlen($c) === 2) return strtoupper($c);
        $map = ['united kingdom' => 'GB', 'great britain' => 'GB', 'england' => 'GB', 'uk' => 'GB', 'ireland' => 'IE', 'germany' => 'DE', 'france' => 'FR', 'spain' => 'ES', 'italy' => 'IT', 'netherlands' => 'NL', 'united states' => 'US', 'usa' => 'US'];
        return $map[mb_strtolower($c)] ?? strtoupper(substr($c, 0, 2));
    }

    /** Read and load. Lines of an order already confirmed or locked are never changed (intake skips them). */
    public static function import(int $ent, int $branchId, string $path, string $fileName, array $opt = []): array
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER', 'TL', 'VA'], true)) throw new \InvalidArgumentException('Your role cannot load orders.');
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'csv';
        $x = self::read($path, $ext, $opt + ['temu_value' => Choices::get('TEMU_SALE_VALUE', $ent, $branchId)]);
        if (! $x['lines']) return ['format' => $x['format'], 'format_name' => $x['format_name'], 'new' => 0, 'updated' => 0, 'unmatched' => 0, 'short' => 0, 'locked' => 0, 'skipped' => $x['skipped'], 'orders' => 0, 'lines_read' => 0];
        $r = SalesService::intake($branchId, $x['lines']);
        Audit::log('t_sales', 0, 'INSERT', ['format' => $x['format'], 'file' => mb_substr($fileName, 0, 120), 'lines' => count($x['lines']), 'new' => $r['new']], null, $ent);
        return $r + ['locked' => count($x['lines']) - $r['new'] - $r['updated'], 'format' => $x['format'], 'format_name' => $x['format_name'], 'skipped' => $x['skipped'], 'orders' => count(array_unique(array_column($x['lines'], 'order_no'))), 'lines_read' => count($x['lines'])];
    }
}
