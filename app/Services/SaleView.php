<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The sales list as a view (RULES_A_SALES R-A_SALES-44 … 46, the strip of 31): columns per person, extra marketplace columns read from each line's CSV row,
 * the totals strip with fee / VA share / net, and the export of the list as PDF, Word, Excel or CSV with the same filters and columns.
 */
class SaleView
{
    public const FIXED = ['order_no' => 'Order No', 'd_order' => 'Order Date', 'cust_name' => 'Cust. Name', 'cust_contacts' => 'Cust. Contacts', 'sku' => 'SKU', 'product_info' => 'Product Info', 'qty' => 'Qty', 'sale_rate' => 'Sale Rate', 'total_amount' => 'Total Amt'];
    public const OPTIONAL = ['visibility' => 'Visibility', 'investor' => 'Investor', 'company' => 'Owner/Vendor', 'raw_price' => 'Raw Price', 'carrier' => 'Carrier', 'packing' => 'Packing', 'buy_rate' => 'Buy Rate', 'buy_amount' => 'Buy Amount', 'profit' => 'Profit', 'd_buy' => 'Buy Date', 'url' => 'URL'];
    public const MONEY = ['raw_price', 'carrier', 'packing', 'buy_rate', 'buy_amount', 'profit', 'd_buy', 'url'];      // only for people who may see cost
    public const NUMERIC = ['qty', 'sale_rate', 'total_amount', 'raw_price', 'carrier', 'packing', 'buy_rate', 'buy_amount', 'profit'];
    public const LIMITS = ['pdf' => 1500, 'docx' => 5000, 'xlsx' => 20000, 'csv' => 20000];
    public const NAMES = ['pdf' => 'PDF', 'docx' => 'Word', 'xlsx' => 'Excel', 'csv' => 'CSV'];
    public const PAGE = 'sales_listing';

    // ---------- R-A_SALES-45: extra marketplace columns ----------
    public static function key(string $label): string { return 'xc_' . substr(md5(mb_strtolower(trim($label))), 0, 10); }

    /** The setup rows of one marketplace, in their order. */
    public static function extraSetup(int $mp): array
    {
        return DB::table('marketplace_sale_custom_columns')->where('fk_marketplace_id', $mp)->whereIn('fk_id_ent', [0, (int) Context::ent()])->orderBy('sort_order')->orderBy('id')->get(['id', 'col_label as label', 'csv_columns as csv'])->map(fn ($r) => (array) $r)->all();
    }

    /** Save the rows of one marketplace: kept rows updated, new inserted, removed deleted, sort_order = row order. Messages are the rule's texts. */
    public static function saveExtra(int $mp, array $rows): array
    {
        if (! DB::table('cat_marketplaces')->where('id', $mp)->exists()) throw new \InvalidArgumentException('Marketplace not found.');
        $ent = (int) Context::ent(); $clean = []; $seen = []; $n = 0; $taken = array_map('mb_strtolower', array_merge(array_values(SalesImport::FIELDS), array_values(self::FIXED)));
        foreach ($rows as $r) {
            $label = trim((string) ($r['label'] ?? '')); $csv = Parse::headerList($r['csv'] ?? '');
            if ($label === '' && $csv === '') continue; $n++;      // an empty row is ignored
            if ($label === '') throw new \InvalidArgumentException("Extra column $n: enter a column name.");
            if ($csv === '') throw new \InvalidArgumentException("Extra column \"$label\": enter the CSV column name(s).");
            if (mb_strlen($label) > 60) throw new \InvalidArgumentException("Extra column \"$label\": the name can have up to 60 characters.");
            if (mb_strlen($csv) > 500) throw new \InvalidArgumentException("Extra column \"$label\": too many CSV columns (500 characters at most).");
            if (in_array(mb_strtolower($label), $taken, true)) throw new \InvalidArgumentException("Extra column \"$label\": this name is already a Sale Column, choose another name.");
            if (isset($seen[mb_strtolower($label)])) throw new \InvalidArgumentException('Extra column "' . $seen[mb_strtolower($label)] . '" is entered twice.');
            $seen[mb_strtolower($label)] = $label; $clean[] = ['id' => (int) ($r['id'] ?? 0), 'label' => $label, 'csv' => $csv];
        }
        return DB::transaction(function () use ($mp, $ent, $clean) {
            $have = DB::table('marketplace_sale_custom_columns')->where('fk_marketplace_id', $mp)->where('fk_id_ent', $ent)->pluck('id')->all(); $keep = [];
            foreach ($clean as $i => $c) {
                $d = ['col_label' => $c['label'], 'csv_columns' => $c['csv'], 'sort_order' => $i + 1, 'updated_at' => now()];
                if ($c['id'] && in_array($c['id'], $have)) { DB::table('marketplace_sale_custom_columns')->where('id', $c['id'])->update($d); $keep[] = $c['id']; }
                else $keep[] = DB::table('marketplace_sale_custom_columns')->insertGetId($d + ['fk_id_ent' => $ent, 'fk_marketplace_id' => $mp, 'created_at' => now()]);
            }
            DB::table('marketplace_sale_custom_columns')->where('fk_marketplace_id', $mp)->where('fk_id_ent', $ent)->whereNotIn('id', $keep ?: [0])->delete();
            Audit::log('marketplace_sale_custom_columns', $mp, 'UPDATE', ['extra columns' => count($clean)]); self::forget();
            return self::extraSetup($mp);
        });
    }

    private static ?array $extras = null;
    public static function forget(): void { self::$extras = null; }

    /** Every extra column of the company: key => [label, marketplaces (names), by (marketplace id => [normalised headers])]. The same label on two marketplaces is one column. */
    public static function extras(): array
    {
        if (self::$extras !== null) return self::$extras;
        $out = [];
        foreach (DB::table('marketplace_sale_custom_columns as x')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'x.fk_marketplace_id')->whereIn('x.fk_id_ent', [0, (int) Context::ent()])->orderBy('x.sort_order')->orderBy('x.id')
            ->get(['x.col_label', 'x.csv_columns', 'x.fk_marketplace_id', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), NULLIF(m.marketplace_descr,''), CONCAT('Marketplace ', x.fk_marketplace_id)) as mp")]) as $r) {
            $k = self::key($r->col_label); $out[$k] ??= ['key' => $k, 'label' => $r->col_label, 'marketplaces' => [], 'by' => []];
            $out[$k]['marketplaces'][] = $r->mp; $out[$k]['by'][(int) $r->fk_marketplace_id] = array_map(fn ($h) => Parse::header($h), array_filter(array_map('trim', explode(',', (string) $r->csv_columns)), fn ($h) => $h !== ''));
        }
        return self::$extras = $out;
    }

    /** The values of the extra columns of one line, read from its stored CSV row (so months imported before the column existed work too). */
    public static function extraValues(?string $rawCsv, int $mp): array
    {
        $ex = self::extras(); if (! $ex) return [];
        $raw = []; foreach ((array) json_decode((string) $rawCsv, true) as $h => $v) if (is_scalar($v)) $raw[Parse::header($h)] = trim((string) $v);
        $out = [];
        foreach ($ex as $k => $c) { $vals = []; foreach ($c['by'][$mp] ?? [] as $h) if (($raw[$h] ?? '') !== '') $vals[] = $raw[$h]; $out[$k] = implode(', ', $vals); }
        return $out;
    }

    // ---------- R-A_SALES-44: columns per person ----------
    /** Hidden column keys of the person; unknown keys are dropped. */
    public static function hidden(?object $u): array
    {
        if (! $u) return [];
        $h = (array) (ListExports::prefs($u, self::PAGE)['list']['hidden'] ?? []); $known = array_merge(array_keys(self::OPTIONAL), array_keys(self::extras()));
        return array_values(array_intersect($h, $known));
    }

    public static function saveHidden(object $u, array $keys): array
    {
        $known = array_merge(array_keys(self::OPTIONAL), array_keys(self::extras())); $keys = array_values(array_unique(array_intersect(array_map('strval', $keys), $known)));
        ListExports::savePrefs($u, self::PAGE, ['hidden' => $keys]);
        return ['message' => 'Saved for your account', 'hidden' => $keys];
    }

    /** What the screen needs for the column panel and the table: fixed, optional (by right), extra, hidden, and the count text. */
    public static function columns(?object $u): array
    {
        $money = $u && Perm::has($u, 'orders.MONEY'); $opt = [];
        foreach (self::OPTIONAL as $k => $l) if ($money || ! in_array($k, self::MONEY, true)) $opt[] = ['key' => $k, 'label' => $l];
        $extra = array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'marketplaces' => array_values(array_unique($c['marketplaces']))], array_values(self::extras()));
        $hidden = array_values(array_intersect(self::hidden($u), array_merge(array_column($opt, 'key'), array_column($extra, 'key')))); $all = count($opt) + count($extra);
        return ['fixed' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::FIXED), self::FIXED), 'optional' => $opt, 'extra' => $extra, 'hidden' => $hidden, 'count' => $hidden ? '(' . ($all - count($hidden)) . '/' . $all . ')' : '',
            'note' => 'Tick the columns to show. Order No to Total Amt always show.'];
    }

    /** Export / table columns in order: fixed, extra (after Total Amt), then the optional ones – without what the person hid. */
    public static function visible(?object $u): array
    {
        $c = self::columns($u); $out = $c['fixed'];
        foreach (array_merge($c['extra'], $c['optional']) as $x) if (! in_array($x['key'], $c['hidden'], true)) $out[] = ['key' => $x['key'], 'label' => $x['label']];
        return $out;
    }

    // ---------- R-A_SALES-31: the strip ----------
    /** $q = the filtered query of the list (alias s). Fee and VA % come from the import batches of the lines (each batch counted once). */
    public static function strip($q, bool $money): array
    {
        $t = (clone $q)->selectRaw("COUNT(*) n, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.total_amount),0) sale, COALESCE(SUM(s.buy_amount),0) buy, COALESCE(SUM(s.profit),0) profit, COALESCE(SUM(CASE WHEN s.profit < 0 THEN -s.profit ELSE 0 END),0) loss")->first();
        $out = ['records' => (int) $t->n, 'qty' => (int) $t->qty, 'sale' => round((float) $t->sale, 2)];
        if (! $money) return $out;
        $per = (clone $q)->where('s.fk_id_import', '>', 0)->groupBy('s.fk_id_import')->selectRaw('s.fk_id_import b, COALESCE(SUM(s.profit),0) profit')->pluck('profit', 'b'); $fee = 0.0; $va = 0.0; $pcts = [];
        foreach (DB::table('t_sale_imports')->whereIn('id', $per->keys())->get(['id', 'marketplace_fee', 'va_share_percentage']) as $b) {
            $fee += (float) $b->marketplace_fee; $pcts[(string) (float) $b->va_share_percentage] = 1; $va += round(((float) $per[$b->id] - (float) $b->marketplace_fee) * (float) $b->va_share_percentage / 100, 2);
        }
        $pct = count($pcts) === 1 ? (float) array_key_first($pcts) : null; $profit = round((float) $t->profit, 2);
        return $out + ['buy' => round((float) $t->buy, 2), 'profit' => $profit, 'fee' => round($fee, 2), 'va_share' => round($va, 2), 'va_label' => count($pcts) > 1 ? 'VA share (mixed %)' : 'VA share (' . rtrim(rtrim(number_format((float) $pct, 2, '.', ''), '0'), '.') . '%)',
            'net' => round($profit - $fee - $va, 2), 'loss' => round((float) $t->loss, 2)];
    }

    // ---------- R-A_SALES-46: export ----------
    /** The filter in words: "Ebay | Shop 101A (Pets) | 01-07-2026 to 31-07-2026 | Refunded …" or "All sales". */
    public static function subtitle(array $f, int $lines, ?string $now = null): string
    {
        $p = []; $d = fn ($x) => date('d-m-Y', strtotime((string) $x));
        if (! empty($f['marketplace'])) $p[] = (string) DB::table('cat_marketplaces')->where('id', (int) $f['marketplace'])->selectRaw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) n")->value('n');
        if (! empty($f['shop']) && ($s = DB::table('t_branches')->where('id', (int) $f['shop'])->first())) $p[] = 'Shop ' . SalesImport::label($s);
        if (! empty($f['batch']) && ($b = DB::table('t_sale_imports')->where('id', (int) $f['batch'])->first())) $p[] = 'Import ' . (string) DB::table('t_branches')->where('id', $b->fk_id_branch)->value('shop_no') . ' - ' . SalesImport::monthName((int) $b->period_month, (int) $b->period_year);
        if (! empty($f['from']) || ! empty($f['to'])) $p[] = trim((! empty($f['from']) ? $d($f['from']) : '…') . ' to ' . (! empty($f['to']) ? $d($f['to']) : '…'));
        if (! empty($f['status'])) $p[] = (string) $f['status'];
        if (! empty($f['type'])) $p[] = ['inventory' => 'Inventory', 'ds' => 'Dropshipping', 'nm' => 'Not matched'][$f['type']] ?? '';
        if (! empty($f['show'])) $p[] = ['loss' => 'Loss lines', 'out' => 'Out of period', 'nocost' => 'No price history'][$f['show']] ?? '';
        if (! empty($f['visibility'])) $p[] = $f['visibility'] === 'POOL' ? 'Central (Pool)' : 'Private';
        if (! empty($f['company'])) $p[] = 'Company ' . (string) DB::table('t_enterprise')->where('id', (int) $f['company'])->value('ent_name');
        if (! empty($f['vendor'])) $p[] = 'Vendor ' . trim((string) DB::table('t_account')->where('id', (int) $f['vendor'])->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n'));
        if (! empty($f['carrier'])) { [$k, $v] = array_pad(explode(':', (string) $f['carrier'], 2), 2, ''); $p[] = 'Carrier ' . (string) ($k === 'car' ? DB::table('t_carriers')->where('id', (int) $v)->value('carrier_name') : DB::table('t_carrier_pkg_svc')->where('id', (int) $v)->value('pkg_svc_name')); }
        if (trim((string) ($f['q'] ?? '')) !== '') $p[] = '"' . trim((string) $f['q']) . '"';
        $p = array_values(array_filter($p, fn ($x) => trim((string) $x) !== ''));
        return ($p ? implode(' | ', $p) : 'All sales') . " | $lines line(s) | Exported " . date('d-m-Y H:i', $now ? strtotime($now) : time());
    }

    /** How many lines the filters match and whether this format may take them (the screen asks before the download). */
    public static function exportCheck(array $f, string $format, ?object $u): array
    {
        if (! isset(self::LIMITS[$format])) throw new \InvalidArgumentException('Unknown export format.');
        if (empty($f['marketplace']) && empty($f['batch'])) throw new \InvalidArgumentException('Choose a marketplace first.');
        $n = (int) SaleLines::list($f + ['per_page' => 20, 'page' => 1], $u)['total']; $name = self::NAMES[$format]; $max = self::LIMITS[$format];
        if ($n === 0) throw new \InvalidArgumentException('There are no sales to export with these filters.');
        if ($n > $max) throw new \InvalidArgumentException("The $name export takes up to $max lines; these filters match $n. Narrow the filters (e.g. dates or shop) and try again.");
        return ['lines' => $n, 'message' => "Preparing your $name ($n lines). The download starts in a moment."];
    }

    /** One cell as text. */
    public static function cell(array $r, string $key): string
    {
        $m = fn ($v) => number_format((float) $v, 2, '.', '');
        return match ($key) {
            'order_no' => (string) $r['order_no'] . ($r['status'] !== 'Pending' ? "\n(" . $r['status'] . ')' : ''),
            'd_order' => $r['d_order'] ? date('d-m-Y', strtotime((string) $r['d_order'])) : '', 'd_buy' => ! empty($r['d_buy']) ? date('d-m-Y', strtotime((string) $r['d_buy'])) : '',
            'qty' => (string) (int) $r['qty'], 'sale_rate', 'total_amount', 'raw_price', 'buy_rate', 'buy_amount', 'profit' => $m($r[$key] ?? 0),
            'carrier' => $m($r['carrier_rate'] ?? 0) . (! empty($r['carrier_name']) ? "\n" . $r['carrier_name'] : ''), 'packing' => $m($r['packing_rate'] ?? 0) . (! empty($r['packing_name']) ? "\n" . $r['packing_name'] : ''),
            'visibility' => $r['kind'] === 'DS' ? 'Drop shipping' . (! empty($r['ds_platform']) ? " ({$r['ds_platform']})" : '') : (string) $r['visibility'],
            'investor' => (string) ($r['investor'] ?? 'None'), 'url' => (string) ($r['product_url'] ?? ''),
            default => str_starts_with($key, 'xc_') ? (string) ($r['xc'][$key] ?? '') : (string) ($r[$key] ?? ''),
        };
    }

    private static function rowColour(array $r): string
    {
        return $r['status'] === 'Refunded' ? '#f5b7b1' : (! empty($r['loss']) ? '#fdecea' : ($r['kind'] === 'DS' ? '#e8f4f0' : ($r['kind'] === 'NM' ? '#fff3cd' : '')));      // the PDF order of R-A_SALES-41
    }

    /** Build the export. Returns ['name' => file name, 'type' => mime, 'body' => bytes, 'lines' => n]. */
    public static function export(array $f, string $format, ?object $u, ?string $now = null): array
    {
        if (! isset(self::LIMITS[$format])) throw new \InvalidArgumentException('Unknown export format.');
        if (empty($f['marketplace']) && empty($f['batch'])) throw new \InvalidArgumentException('Choose a marketplace first.');
        $max = self::LIMITS[$format]; $name = self::NAMES[$format];
        $d = SaleLines::list(array_diff_key($f, ['page' => 1, 'per_page' => 1]) + ['_limit' => $max + 1], $u); $n = (int) $d['total'];
        if ($n > $max) throw new \InvalidArgumentException("Too many lines to export ($n). The $name export takes up to $max lines: narrow the filters and try again.");
        $cols = self::visible($u); $rows = $d['rows']; $title = 'Sales'; $sub = self::subtitle($d['filter'] ?? $f, $n, $now); $s = $d['totals']; $m = fn ($v) => number_format((float) $v, 2);
        $sum = [['Total qty', (string) $s['qty']], ['Total sale', $m($s['sale'])]];
        if (isset($s['profit'])) $sum = array_merge($sum, [['Total buy', $m($s['buy'])], ['Profit before fee', $m($s['profit'])], ['Marketplace fee', $m($s['fee'])], [$s['va_label'], $m($s['va_share'])], ['Net profit', $m($s['net'])], ['Total loss', $m($s['loss'])]]);
        $file = 'sales_' . date('Y-m-d_His', $now ? strtotime($now) : time()) . '.' . $format;
        Audit::log('t_sales', null, 'EXPORT', ['format' => $format, 'lines' => $n, 'filters' => $sub]);
        $grid = array_map(fn ($r) => array_map(fn ($c) => self::cell($r, $c['key']), $cols), $rows);
        if ($format === 'csv') {
            $h = fopen('php://temp', 'w+'); $safe = fn ($v) => preg_match('/^[=+\-@]/', $v) && ! is_numeric($v) ? "'" . $v : $v;
            fputcsv($h, array_column($cols, 'label'), ',', '"', '\\'); foreach ($grid as $g) fputcsv($h, array_map(fn ($v) => $safe(str_replace("\n", ' ', $v)), $g), ',', '"', '\\');
            fputcsv($h, [], ',', '"', '\\'); foreach ($sum as $x) fputcsv($h, $x, ',', '"', '\\'); rewind($h);
            return ['name' => $file, 'type' => 'text/csv; charset=UTF-8', 'body' => "\xEF\xBB\xBF" . stream_get_contents($h), 'lines' => $n];
        }
        if ($format === 'xlsx') {
            $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $x->getActiveSheet(); $sh->setTitle('Sales'); $sh->setCellValue('A1', $title); $sh->setCellValue('A2', $sub); $rn = 4;
            foreach ($cols as $i => $c) $sh->setCellValue([$i + 1, $rn], $c['label']); $sh->getStyle([1, $rn, count($cols), $rn])->getFont()->setBold(true);
            foreach ($grid as $g) { $rn++; foreach ($g as $i => $v) { $k = $cols[$i]['key']; $v = str_replace("\n", ' ', $v);
                if (in_array($k, ['qty', 'sale_rate', 'total_amount', 'raw_price', 'buy_rate', 'buy_amount', 'profit'], true)) $sh->setCellValue([$i + 1, $rn], (float) $v); else $sh->setCellValueExplicit([$i + 1, $rn], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); } }
            $rn += 2; foreach ($sum as $x2) { $sh->setCellValue([1, $rn], $x2[0]); $sh->setCellValueExplicit([2, $rn], $x2[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); $rn++; }
            $tmp = tempnam(sys_get_temp_dir(), 'sx'); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($tmp); $body = file_get_contents($tmp); @unlink($tmp);
            return ['name' => $file, 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => $body, 'lines' => $n];
        }
        if ($format === 'docx') return ['name' => $file, 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'body' => self::docx($title, $sub, $sum, $cols, $grid), 'lines' => $n];
        // PDF: A4 landscape, DejaVu Sans 7 px, header repeats, no break inside a row, row colours
        $e = fn ($v) => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')); $num = fn ($k) => in_array($k, self::NUMERIC, true) ? ' class="n"' : '';
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:28px 22px 34px 22px}body{font-family:"DejaVu Sans";font-size:7px;color:#111}h1{font-size:13px;margin:0}.sub{margin:2px 0 6px;color:#444}table{width:100%;border-collapse:collapse}th{background:#d9e8f5;text-align:left}th,td{border:0.5px solid #b8c4cf;padding:2px 3px;vertical-align:top}tr{page-break-inside:avoid}thead{display:table-header-group}.n{text-align:right}.sum td{border:0;padding:1px 10px 1px 0}.foot{position:fixed;bottom:-24px;left:0;right:0;font-size:6px;color:#666}</style></head><body>'
            . '<div class="foot">' . $e("$title - $sub") . '</div><h1>' . $e($title) . '</h1><div class="sub">' . $e($sub) . '</div><table class="sum"><tr>' . implode('', array_map(fn ($x) => '<td>' . $e($x[0]) . ': <b>' . $e($x[1]) . '</b></td>', $sum)) . '</tr></table><br>'
            . '<table><thead><tr>' . implode('', array_map(fn ($c) => '<th' . $num($c['key']) . '>' . $e($c['label']) . '</th>', $cols)) . '</tr></thead><tbody>';
        foreach ($grid as $i => $g) { $bg = self::rowColour($rows[$i]); $html .= '<tr' . ($bg ? ' style="background:' . $bg . '"' : '') . '>' . implode('', array_map(fn ($v, $c) => '<td' . $num($c['key']) . '>' . $e($v) . '</td>', $g, $cols)) . '</tr>'; }
        if (! $grid) $html .= '<tr><td colspan="' . count($cols) . '">No sales match these filters.</td></tr>';
        $html .= '</tbody></table></body></html>';
        return ['name' => $file, 'type' => 'application/pdf', 'body' => PrintService::pdf($html, 297, 210), 'lines' => $n, 'html' => $html];
    }

    /** A small Word file (title, subtitle, summary, one table) written by hand: no library needed. */
    public static function docx(string $title, string $sub, array $sum, array $cols, array $grid): string
    {
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $run = fn ($t, $b = false, $sz = 14) => '<w:r><w:rPr>' . ($b ? '<w:b/>' : '') . "<w:sz w:val=\"$sz\"/></w:rPr>" . implode('<w:br/>', array_map(fn ($l) => '<w:t xml:space="preserve">' . $x($l) . '</w:t>', explode("\n", (string) $t))) . '</w:r>';
        $p = fn ($t, $b = false, $sz = 14, $right = false) => '<w:p><w:pPr>' . ($right ? '<w:jc w:val="right"/>' : '') . '<w:spacing w:after="40"/></w:pPr>' . $run($t, $b, $sz) . '</w:p>';
        $cell = fn ($t, $b = false, $right = false, $fill = null) => '<w:tc><w:tcPr>' . ($fill ? "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"$fill\"/>" : '') . '</w:tcPr>' . $p($t, $b, 12, $right) . '</w:tc>';
        $body = $p($title, true, 28) . $p($sub, false, 14) . $p(implode('   |   ', array_map(fn ($s) => $s[0] . ': ' . $s[1], $sum)), true, 14);
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . implode('', array_map(fn ($b) => "<w:$b w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"B8C4CF\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders></w:tblPr>';
        $body .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . implode('', array_map(fn ($c) => $cell($c['label'], true, in_array($c['key'], self::NUMERIC, true), 'D9E8F5'), $cols)) . '</w:tr>';
        foreach ($grid as $g) $body .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . implode('', array_map(fn ($v, $c) => $cell($v, false, in_array($c['key'], self::NUMERIC, true)), $g, $cols)) . '</w:tr>';
        if (! $grid) $body .= '<w:tr>' . $cell('No sales match these filters.') . '</w:tr>';
        $body .= '</w:tbl>';
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
            . '<w:sectPr><w:footerReference w:type="default" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';
        $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $p("$title - $sub", false, 10) . '</w:ftr>';
        $tmp = tempnam(sys_get_temp_dir(), 'dx'); $z = new \ZipArchive(); $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>');
        $z->addFromString('word/document.xml', $doc); $z->addFromString('word/footer1.xml', $footer); $z->close();
        $out = file_get_contents($tmp); @unlink($tmp); return $out;
    }
}
