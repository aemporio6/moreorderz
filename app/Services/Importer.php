<?php

namespace App\Services;

/**
 * MR-1262 / MR-1258 one importer that recommends: a dropped file is read (nothing is saved) and every place in the tool that takes files is scored
 * by the file's own headers / sheets / type – "this goes to X (best), it could also go to Y" with the columns that made the score; the best one opens
 * with one click on the screen of that work (each screen keeps its own checks, mapping and undo).
 */
class Importer
{
    /** target => [title, screen, [[any of these headers], …] – every group must match one header] */
    public const TARGETS = [
        'BANK' => ['Bank statement', '#/bank', [['date', 'transaction date', 'posting date', 'value date'], ['amount', 'debit', 'paid out', 'money out', 'credit', 'paid in'], ['description', 'details', 'narrative', 'reference', 'transaction description', 'payee']]],
        'SALES' => ['Monthly sales file (shop × month)', '#/sales-import', [['order id', 'order number', 'order no', 'order-id', 'sales record number'], ['sku', 'seller sku', 'custom label', 'merchant sku'], ['quantity', 'qty', 'quantity purchased'], ['price', 'sale price', 'total', 'item price', 'sold for', 'total price']]],
        'DS' => ['Drop-shipping supplier orders', '#/ds-upload', [['order id', 'order number', 'order no', 'supplier order'], ['tracking', 'tracking number', 'tracking no'], ['ship to name', 'recipient', 'buyer name', 'name', 'customer name'], ['postcode', 'zip', 'post code', 'zip code', 'postal code']]],
        'SUPPLIER_ORDERS' => ['Supplier order history (Amazon Business)', '#/supplier-orders', [['asin'], ['seller name', 'seller'], ['order id', 'order number'], ['order date']]],
        'PRODUCTS' => ['Products (bulk upload)', '#/product-imports', [['sku', 'product sku', 'sku vendor'], ['product name', 'title', 'name', 'product name vendor'], ['raw price', 'landing price', 'weight', 'sale price', 'min level']]],
        'LISTINGS' => ['Active listings (eBay)', '#/listings-import', [['item number', 'item id'], ['custom label', 'custom label (sku)', 'sku'], ['available quantity', 'quantity']]],
        'TASKS' => ['Tasks (CSV)', '#/tasks?scope=all&import=1', [['title', 'task'], ['due', 'due date', 'due at'], ['owner', 'assigned to', 'owner login']]],
        'WH' => ['Warehouse locations / SKU homes', '#/wh-csv', [['bin', 'bin code', 'location code', 'location'], ['site', 'warehouse', 'zone']]],
        'HISTORICAL' => ['Old purchases (historical CSV)', '#/old-entry', [['order ref', 'order reference', 'po reference'], ['supplier'], ['sku'], ['unit cost', 'unit price', 'cost']]],
    ];

    private static function key(string $h): string { return trim(preg_replace('/\s+/', ' ', str_replace(['_', "\u{FEFF}", "\u{00A0}", '"'], [' ', '', ' ', ''], mb_strtolower($h)))); }

    /** The first rows of a CSV / TSV / Excel file. */
    public static function head(string $path, string $ext): array
    {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path); $rows = [];
            foreach ($book->getActiveSheet()->toArray(null, true, true, false) as $i => $r) { if ($i >= 15) break; $rows[] = array_map(fn ($v) => (string) $v, $r); }
            return $rows;
        }
        $fh = fopen($path, 'r'); $first = (string) fgets($fh); rewind($fh);
        $sep = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
        $rows = []; while (count($rows) < 15 && ($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) $rows[] = $r; fclose($fh);
        return $rows;
    }

    /** Ranked places for this file: [target, title, screen, score 0-100, why]. */
    public static function recommend(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $out = [];
        if ($ext === 'pdf') return ['best' => ['target' => 'LABELS', 'title' => 'Carrier label PDF', 'screen' => '#/labels', 'score' => 95, 'why' => 'a PDF file – labels are read page by page'], 'others' => []];
        if (! in_array($ext, ['csv', 'txt', 'tsv', 'xlsx', 'xls'], true)) throw new \InvalidArgumentException('The tool reads CSV, TSV, Excel and label PDF files.');
        try { $fmt = MarketplaceFiles::detect($path, $ext); } catch (\Throwable $e) { $fmt = 'MAPPED'; }
        if ($fmt !== 'MAPPED') $out[] = ['target' => 'MONEY', 'title' => 'Marketplace money file (' . $fmt . ')', 'screen' => '#/settlements', 'score' => 98, 'why' => 'the headers / sheets of the ' . $fmt . ' layout'];
        $rows = self::head($path, $ext);
        try { [$ord] = OrderFiles::detect($rows); $out[] = ['target' => 'ORDERS', 'title' => 'Order file' . ($ord !== 'MAPPED' ? " ($ord)" : ''), 'screen' => '#/orders', 'score' => $ord !== 'MAPPED' ? 95 : 60, 'why' => $ord !== 'MAPPED' ? "the $ord header line" : 'an order number and a SKU column']; }
        catch (\Throwable $e) {}
        foreach (self::TARGETS as $t => [$title, $screen, $groups]) {
            $best = 0; $why = [];
            foreach ($rows as $r) {
                $h = array_map(fn ($x) => self::key((string) $x), $r); $hit = []; 
                foreach ($groups as $g) foreach ($g as $alt) if (in_array($alt, $h, true)) { $hit[] = $alt; break; }
                $sc = (int) round(count($hit) / count($groups) * 90);
                if ($sc > $best) { $best = $sc; $why = $hit; }
            }
            if ($best >= 50) $out[] = ['target' => $t, 'title' => $title, 'screen' => $screen, 'score' => $best, 'why' => 'columns found: ' . implode(', ', $why)];
        }
        if (! $out) throw new \InvalidArgumentException('No place in the tool recognises this file\'s columns. Open the screen of the work (Import a file) – most screens let you map the columns once and remember them.');
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return ['best' => $out[0], 'others' => array_slice($out, 1, 4)];
    }
}
