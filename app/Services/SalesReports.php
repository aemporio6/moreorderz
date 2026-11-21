<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sales reports (RULES_A_SALES R-A_SALES-50, 51) and the saved VA share of an import batch (R-A_SALES-31).
 * Cost basis of both reports: expected cost (price period). The VA share is read from the batch – a report never works it out again.
 */
class SalesReports
{
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    public const BASIS = 'expected cost (price period)';

    // ---------- the saved VA share of a batch ----------
    /** Pending batches follow their lines; a confirmed batch keeps the amount it had at Confirm (changed only by "Refresh VA %"). */
    public static function syncShares(?int $batchId = null): void
    {
        SalesImport::applyReportFees();      // a typed fee gives way to the money report once it is loaded
        $q = DB::table('t_sale_imports')->where('fk_id_ent', Context::ent())->where('status', 'Pending'); if ($batchId) $q->where('id', $batchId);
        foreach ($q->get(['id', 'marketplace_fee', 'va_share_percentage', 'va_share_amount']) as $b) {
            $amt = round(((float) DB::table('t_sales')->where('fk_id_import', $b->id)->sum('profit') - (float) $b->marketplace_fee) * (float) $b->va_share_percentage / 100, 2);
            if (abs($amt - (float) $b->va_share_amount) >= 0.005) DB::table('t_sale_imports')->where('id', $b->id)->update(['va_share_amount' => $amt, 'va_share_basis' => 'after_fee']);
        }
    }

    /** "Refresh VA %" (Owner only, reason, logged): reads the job-history row again and saves the new share. Refused once the month is closed. */
    public static function refreshVa(int $batchId, string $reason, ?object $u): array
    {
        Perm::need($u, 'orders.saleUnlock'); if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Write the reason.');
        return DB::transaction(function () use ($batchId, $reason) {
            $b = DB::table('t_sale_imports')->where('id', $batchId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Import not found.');
            $period = sprintf('%04d-%02d', $b->period_year, $b->period_month);
            if (Ledger::periodLocked((int) $b->fk_id_ent, $period) || ((CloseService::run((int) $b->fk_id_ent, (int) $b->fk_id_branch, $period)->status ?? '') === 'LOCKED')) throw new \InvalidArgumentException('This month is settled/locked – use a correction in the next open month.');
            $shop = DB::table('t_branches')->where('id', $b->fk_id_branch)->first(); $va = SalesImport::vaOf($shop, (int) $b->period_month, (int) $b->period_year);
            $amt = round(((float) DB::table('t_sales')->where('fk_id_import', $b->id)->sum('profit') - (float) $b->marketplace_fee) * (float) $va['va_share_percentage'] / 100, 2);
            DB::table('t_sale_imports')->where('id', $b->id)->update($va + ['va_share_amount' => $amt, 'va_share_basis' => 'after_fee', 'va_refreshed_at' => now(), 'updated_at' => now()]);
            Audit::log('t_sale_imports', $b->id, 'UPDATE', ['va_share_percentage' => [(float) $b->va_share_percentage, (float) $va['va_share_percentage']], 'va_share_amount' => [(float) $b->va_share_amount, $amt]], 'Refresh VA %: ' . trim($reason), (int) $b->fk_id_ent);
            return ['message' => 'VA % is now ' . rtrim(rtrim(number_format((float) $va['va_share_percentage'], 2, '.', ''), '0'), '.') . ' %, VA share ' . number_format($amt, 2) . '.', 'pct' => (float) $va['va_share_percentage'], 'amount' => $amt];
        });
    }

    // ---------- shared: the lines of a year under the report filters ----------
    private static function lines(int $year, array $f, ?object $u, bool $summary = false)
    {
        $q = DB::table('t_sales as s')->where('s.fk_id_ent', Context::ent())->whereBetween('s.d_order', ["$year-01-01", "$year-12-31"])->where('s.sale_source', $summary ? '=' : '<>', 'summary'); Perm::mine($q, 's.fk_id_branch');
        if (($mine = Perm::vendors($u)) !== null) $q->whereIn('s.fk_product_id', DB::table('t_product_vendors')->whereIn('fk_id_vendor', $mine)->select('id'));      // a person limited to vendors sees only their inventory, in the reports too
        if (! empty($f['marketplace'])) $q->where('s.fk_id_marketplace', (int) $f['marketplace']);
        if (! empty($f['shop'])) $q->where('s.fk_id_branch', (int) $f['shop']);
        if (! empty($f['vendor'])) $q->where('s.fk_id_vendor', (int) $f['vendor']);                                                                                                        // project / vendor of the line
        if (! empty($f['vendor_inventory'])) $q->whereIn('s.fk_product_id', DB::table('t_product_vendors')->where('fk_id_vendor', (int) $f['vendor_inventory'])->select('id'));           // products of that vendor
        if (! empty($f['company'])) { $c = (int) $f['company']; $q->where(fn ($w) => $w->where('s.fk_id_company', $c)->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('s.fk_id_company')->orWhere('s.fk_id_company', 0))->whereIn('s.fk_id_branch', DB::table('t_branches')->where('fk_id_company', $c)->select('id')))); }
        if (! empty($f['owner'])) $q->whereIn('s.fk_id_branch', DB::table('t_branches')->where('fk_id_owner', (int) $f['owner'])->select('id'));
        if (! empty($f['va'])) $q->whereIn('s.fk_id_branch', SalesImport::vaShops((int) $f['va'], date('Y-m-d')) ?: [0]);      // the VA = holder of the shop assignment role (seller field as fallback)
        return $q;
    }

    public static function year(mixed $y): int { $y = (int) $y; return $y >= 2020 && $y <= 2030 ? $y : (int) date('Y'); }

    /** VAs for the filter: holders of a running VA / SELLER shop assignment, plus the sellers named on a shop. */
    private static function vas(): array
    {
        $n = "TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.middle_name,''), NULLIF(a.sur_name,'')))"; $shops = Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', Context::ent()); $day = date('Y-m-d');
        $ids = (clone $shops)->whereNotNull('fk_id_seller')->pluck('fk_id_seller')->merge(DB::table('t_account_branches')->whereIn('fk_id_branch', (clone $shops)->select('id'))->whereIn('role_code', ['VA', 'SELLER'])->where(fn ($w) => $w->whereNull('d_to')->orWhere('d_to', '>=', $day))->pluck('fk_id_account'))->unique()->filter()->all();
        return DB::table('t_account as a')->whereIn('a.id', $ids ?: [0])->orderByRaw($n)->get(['a.id', DB::raw("CONCAT_WS(' - ', NULLIF(a.account_code,''), $n) as name")])->all();
    }

    private static function people(string $col): array
    {
        $n = "TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.middle_name,''), NULLIF(a.sur_name,'')))";
        return DB::table('t_account as a')->whereIn('a.id', Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', Context::ent())->whereNotNull($col)->distinct()->select($col))->orderByRaw($n)->get(['a.id', DB::raw("CONCAT_WS(' - ', NULLIF(a.account_code,''), $n) as name")])->all();
    }

    public static function filterLists(?object $u): array
    {
        $ent = (int) Context::ent(); $n = "TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.middle_name,''), NULLIF(a.sur_name,'')))"; $acc = fn ($sub) => DB::table('t_account as a')->whereIn('a.id', $sub)->orderByRaw($n)->limit(500)->get(['a.id', DB::raw("CONCAT_WS(' - ', NULLIF(a.account_code,''), $n) as name")])->all();
        return ['years' => range(2020, 2030), 'shops' => ShopLabel::list($u), 'marketplaces' => DB::table('cat_marketplaces')->whereIn('id', Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->select('fk_id_marketplace'))->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name")])->all(),
            'vendors' => $acc(DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_vendor', '>', 0)->distinct()->select('fk_id_vendor')), 'vendor_inventory' => $acc(DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('fk_id_vendor', '>', 0)->distinct()->select('fk_id_vendor')),
            'companies' => DB::table('t_enterprise')->whereIn('id', DB::table('t_branches')->where('fk_id_ent', $ent)->whereNotNull('fk_id_company')->distinct()->select('fk_id_company'))->orWhere('id', $ent)->orderBy('ent_name')->get(['id', 'ent_name as name'])->all(),
            'owners' => self::people('fk_id_owner'), 'vas' => self::vas()];
    }

    // ---------- R-A_SALES-50: Annual Sale Report ----------
    public static function annual(array $f, ?object $u): array
    {
        $year = self::year($f['year'] ?? null); self::syncShares(); $money = $u && Perm::has($u, 'orders.MONEY');
        $by = self::lines($year, $f, $u)->groupByRaw('MONTH(s.d_order)')->selectRaw('MONTH(s.d_order) m, COALESCE(SUM(s.total_amount),0) sale, COALESCE(SUM(s.buy_amount),0) buy, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.profit),0) profit, COALESCE(SUM(CASE WHEN s.profit < 0 THEN -s.profit ELSE 0 END),0) loss')->get()->keyBy('m');
        // fee and saved VA share: the batches of each month that hold lines of the filter (each batch once)
        $bq = DB::table('t_sale_imports')->where('fk_id_ent', Context::ent())->where('period_year', $year)->whereIn('id', self::lines($year, $f, $u)->where('s.fk_id_import', '>', 0)->distinct()->select('s.fk_id_import'));
        $bat = $bq->groupBy('period_month')->selectRaw('period_month m, COALESCE(SUM(marketplace_fee),0) fee, COALESCE(SUM(va_share_amount),0) va, COUNT(DISTINCT va_share_percentage) kinds, MAX(va_share_percentage) pct')->get()->keyBy('m');
        // daily totals of shops without order files are never mixed into the order lines: shown apart
        $sum = self::lines($year, $f, $u, true)->groupByRaw('MONTH(s.d_order)')->selectRaw('MONTH(s.d_order) m, COALESCE(SUM(s.total_amount),0) sale, COALESCE(SUM(s.buy_amount),0) buy, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.profit),0) profit')->get()->keyBy('m'); $summary = ['rows' => [], 'total' => ['sale' => 0.0, 'buy' => 0.0, 'qty' => 0, 'profit' => 0.0]];
        for ($m = 1; $m <= 12; $m++) { $x = $sum[$m] ?? null; $r = ['m' => $m, 'month' => self::MONTHS[$m - 1], 'sale' => round((float) ($x->sale ?? 0), 2), 'buy' => round((float) ($x->buy ?? 0), 2), 'qty' => (int) ($x->qty ?? 0), 'profit' => round((float) ($x->profit ?? 0), 2)]; if (! $money) unset($r['buy'], $r['profit']); $summary['rows'][] = $r;
            foreach (['sale', 'buy', 'qty', 'profit'] as $k) $summary['total'][$k] = $k === 'qty' ? $summary['total'][$k] + (int) ($x->qty ?? 0) : round($summary['total'][$k] + (float) ($x->$k ?? 0), 2); }
        if (! $money) unset($summary['total']['buy'], $summary['total']['profit']); $summary['any'] = $summary['total']['sale'] != 0 || $summary['total']['qty'] != 0; $summary['note'] = 'Daily totals of shops without order files ("summary" lines). They are not part of the figures above.';
        $rows = []; $tot = ['month' => 'Year Total', 'sale' => 0.0, 'buy' => 0.0, 'qty' => 0, 'fee' => 0.0, 'profit' => 0.0, 'after_fee' => 0.0, 'va_share' => 0.0, 'store_share' => 0.0, 'net' => 0.0, 'loss' => 0.0]; $pcts = [];
        for ($m = 1; $m <= 12; $m++) {
            $l = $by[$m] ?? null; $b = $bat[$m] ?? null; $profit = round((float) ($l->profit ?? 0), 2); $fee = round((float) ($b->fee ?? 0), 2); $after = round($profit - $fee, 2); $va = round((float) ($b->va ?? 0), 2);
            $r = ['m' => $m, 'month' => self::MONTHS[$m - 1], 'sale' => round((float) ($l->sale ?? 0), 2), 'buy' => round((float) ($l->buy ?? 0), 2), 'qty' => (int) ($l->qty ?? 0), 'fee' => $fee, 'profit' => $profit, 'after_fee' => $after,
                'va_pct' => $b ? ((int) $b->kinds > 1 ? 'mixed' : (float) $b->pct) : null, 'va_share' => $va, 'store_share' => round($after - $va, 2), 'net' => round($after - $va, 2), 'loss' => round((float) ($l->loss ?? 0), 2)];
            if ($b) $pcts[(string) $r['va_pct']] = 1;
            foreach (['sale', 'buy', 'qty', 'fee', 'profit', 'after_fee', 'va_share', 'store_share', 'net', 'loss'] as $k) $tot[$k] = $k === 'qty' ? $tot[$k] + $r[$k] : round($tot[$k] + $r[$k], 2);
            $rows[] = $r;
        }
        $shop = ! empty($f['shop']) ? collect(ShopLabel::list($u))->firstWhere('id', (int) $f['shop']) : null;
        $out = ['year' => $year, 'rows' => $rows, 'total' => $tot, 'basis' => self::BASIS, 'va_label' => count($pcts) === 1 && array_key_first($pcts) !== 'mixed' ? 'VA Share [' . rtrim(rtrim(number_format((float) array_key_first($pcts), 2, '.', ''), '0'), '.') . ' %]' : 'VA Share',
            'summary' => $summary, 'shop' => $shop, 'va' => $shop ? ['name' => $shop['va'], 'pct' => $shop['va_pct'], 'store_pct' => $shop['va_pct'] === null ? null : round(100 - $shop['va_pct'], 2)] : null, 'filter' => array_filter($f), 'see_cost' => $money];
        if (! $money) { foreach ($out['rows'] as &$r) $r = array_intersect_key($r, array_flip(['m', 'month', 'sale', 'qty'])); $out['total'] = array_intersect_key($tot, array_flip(['month', 'sale', 'qty'])); }
        return $out;
    }

    public const ANNUAL_COLS = ['month' => 'Month', 'sale' => 'Sales Total', 'buy' => 'Buy Amount', 'qty' => 'Qty', 'fee' => 'Marketplace Fee', 'profit' => 'Profit', 'store_share' => 'Store Share', 'va_share' => 'VA Share', 'net' => 'Net', 'loss' => 'Loss'];

    // ---------- R-A_SALES-51: Detailed Sale Report (shops × months) ----------
    public static function detailed(array $f, ?object $u): array
    {
        $year = self::year($f['year'] ?? null); $money = $u && Perm::has($u, 'orders.MONEY');
        $data = self::lines($year, $f, $u)->groupBy('s.fk_id_branch')->groupByRaw('MONTH(s.d_order)')->selectRaw('s.fk_id_branch b, MONTH(s.d_order) m, COALESCE(SUM(s.total_amount),0) total, COALESCE(SUM(s.buy_amount),0) purchase, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.profit),0) profit')->get()->groupBy('b');
        $rows = []; $zero = ['total' => 0.0, 'purchase' => 0.0, 'qty' => 0, 'profit' => 0.0]; $grand = array_fill(1, 12, $zero) + ['annual' => $zero]; $max = 0.0;
        foreach (ShopLabel::list($u) as $s) {      // already sorted by marketplace, then shop no
            if (! empty($f['owner']) && $s['owner_id'] !== (int) $f['owner']) continue; if (! empty($f['va']) && $s['va_id'] !== (int) $f['va']) continue;
            if (! empty($f['marketplace']) && $s['marketplace'] !== (int) $f['marketplace']) continue; if (! empty($f['shop']) && $s['id'] !== (int) $f['shop']) continue;
            $mine = ($data[$s['id']] ?? collect())->keyBy('m'); if ($mine->isEmpty() && ! $s['active']) continue;
            $cells = []; $ann = $zero;
            for ($m = 1; $m <= 12; $m++) { $c = $mine[$m] ?? null; $cell = ['total' => round((float) ($c->total ?? 0), 2), 'purchase' => round((float) ($c->purchase ?? 0), 2), 'qty' => (int) ($c->qty ?? 0), 'profit' => round((float) ($c->profit ?? 0), 2)];
                foreach ($cell as $k => $v) { $ann[$k] = $k === 'qty' ? $ann[$k] + $v : round($ann[$k] + $v, 2); $grand[$m][$k] = $k === 'qty' ? $grand[$m][$k] + $v : round($grand[$m][$k] + $v, 2); $grand['annual'][$k] = $k === 'qty' ? $grand['annual'][$k] + $v : round($grand['annual'][$k] + $v, 2); }
                $max = max($max, abs($cell['profit'])); if (! $money) unset($cell['purchase'], $cell['profit']); $cells[$m] = $cell; }
            if (! $money) unset($ann['purchase'], $ann['profit']);
            $rows[] = ['shop' => $s['id'], 'marketplace' => $s['marketplace'], 'label' => $s['label_mp'],
                'owner' => $s['owner'], 'va' => $s['va'], 'cells' => $cells, 'annual' => $ann];
        }
        if (! $money) foreach ($grand as &$g) unset($g['purchase'], $g['profit']);
        return ['year' => $year, 'months' => array_map(fn ($m) => substr($m, 0, 3), self::MONTHS), 'rows' => $rows, 'total' => $grand, 'max_profit' => $max, 'basis' => self::BASIS, 'see_cost' => $money, 'filter' => array_filter($f)];
    }

    // ---------- downloads: exactly the screen figures ----------
    public static function download(string $report, string $format, array $f, ?object $u, ?string $now = null): array
    {
        if (! in_array($report, ['annual', 'detailed'], true) || ! in_array($format, ['csv', 'xlsx', 'pdf'], true)) throw new \InvalidArgumentException('Unknown download.');
        Perm::need($u, 'orders.saleExport'); $stamp = date('YmdHis', $now ? strtotime($now) : time()); $m = fn ($v) => number_format((float) $v, 2, '.', '');
        if ($report === 'annual') {
            $d = self::annual($f, $u); $cols = $d['see_cost'] ? self::ANNUAL_COLS : array_intersect_key(self::ANNUAL_COLS, array_flip(['month', 'sale', 'qty'])); $head = array_values($cols);
            $grid = array_map(fn ($r) => array_map(fn ($k) => in_array($k, ['month', 'qty'], true) ? (string) $r[$k] : $m($r[$k]), array_keys($cols)), array_merge($d['rows'], [$d['total']]));
            $name = ($format === 'pdf' ? 'sale_export_for_' : 'sale_export_of_') . $d['year'] . '_' . $stamp . '.' . $format; $title = 'Annual Sale Report ' . $d['year']; $sub = ($d['shop']['label'] ?? 'All shops') . ' · cost basis: ' . self::BASIS; $paper = [210, 297]; $colour = ['profit' => array_search('Profit', $head), 'loss' => array_search('Loss', $head)];
        } else {
            $d = self::detailed($f, $u); $keys = $d['see_cost'] ? ['total' => 'Total', 'purchase' => 'Purchase', 'qty' => 'Qty', 'profit' => 'Profit'] : ['total' => 'Total', 'qty' => 'Qty']; $head = ['Shop', 'Owner', 'VA'];
            foreach (array_merge($d['months'], ['Annual']) as $mn) foreach ($keys as $l) $head[] = "$mn $l"; $grid = [];
            $line = fn (array $first, array $cells, array $ann) => array_merge($first, ...array_map(fn ($c) => array_map(fn ($k) => $k === 'qty' ? (string) $c[$k] : $m($c[$k]), array_keys($keys)), array_merge(array_values($cells), [$ann])));
            foreach ($d['rows'] as $r) $grid[] = $line([$r['label'], (string) $r['owner'], (string) $r['va']], $r['cells'], $r['annual']);
            $t = $d['total']; $ann = $t['annual']; unset($t['annual']); $grid[] = $line(['Total', '', ''], $t, $ann);
            $name = 'sale_detail_export_for_' . $d['year'] . '_' . $stamp . '.' . $format; $title = 'Detailed Sale Report ' . $d['year']; $sub = 'Shops × months · cost basis: ' . self::BASIS; $paper = [628, 628]; $colour = [];      // 1780 pt square, landscape
        }
        Audit::log('t_sales', null, 'EXPORT', ['report' => $report, 'format' => $format, 'year' => $d['year']]);
        if ($format === 'csv') { $h = fopen('php://temp', 'w+'); fputcsv($h, $head, ',', '"', '\\'); foreach ($grid as $g) fputcsv($h, array_map(fn ($v) => DsMatch::guard((string) $v), $g), ',', '"', '\\'); rewind($h); return ['name' => $name, 'type' => 'text/csv; charset=UTF-8', 'body' => "\xEF\xBB\xBF" . stream_get_contents($h)]; }
        if ($format === 'xlsx') { $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $x->getActiveSheet(); $sh->setCellValue('A1', $title); $sh->setCellValue('A2', $sub); foreach ($head as $i => $hh) $sh->setCellValue([$i + 1, 4], $hh); $sh->getStyle([1, 4, count($head), 4])->getFont()->setBold(true);
            foreach ($grid as $rn => $g) foreach ($g as $i => $v) is_numeric($v) ? $sh->setCellValue([$i + 1, $rn + 5], (float) $v) : $sh->setCellValueExplicit([$i + 1, $rn + 5], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $tmp = tempnam(sys_get_temp_dir(), 'sr'); \App\Services\Brand::xlsx($x); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($tmp); $body = file_get_contents($tmp); @unlink($tmp); return ['name' => $name, 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => $body]; }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $last = count($grid) - 1;
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:26px}body{font-family:"DejaVu Sans";font-size:' . ($report === 'annual' ? 9 : 7) . 'px}h1{font-size:14px;margin:0}.sub{color:#555;margin:2px 0 8px}table{width:100%;border-collapse:collapse}th{background:#d9e8f5}th,td{border:0.5px solid #b8c4cf;padding:3px 4px;text-align:right}th:first-child,td:first-child{text-align:left}tr{page-break-inside:avoid}thead{display:table-header-group}.g{color:#1e7e34}.r{color:#c0392b}.t td{font-weight:bold;background:#f1f3f5}</style></head><body><h1>' . $e($title) . '</h1><div class="sub">' . $e($sub) . '</div><table><thead><tr>' . implode('', array_map(fn ($hh) => '<th>' . $e($hh) . '</th>', $head)) . '</tr></thead><tbody>';
        foreach ($grid as $rn => $g) $html .= '<tr' . ($rn === $last ? ' class="t"' : '') . '>' . implode('', array_map(fn ($v, $i) => '<td' . (($i === ($colour['profit'] ?? -1) || str_ends_with($head[$i], ' Profit')) && is_numeric($v) && (float) $v != 0.0 ? ' class="' . ((float) $v < 0 ? 'r' : 'g') . '"' : ($i === ($colour['loss'] ?? -1) && (float) $v > 0 ? ' class="r"' : '')) . '>' . $e($v) . '</td>', $g, array_keys($g))) . '</tr>';
        $html .= '</tbody></table></body></html>';
        return ['name' => $name, 'type' => 'application/pdf', 'body' => PrintService::pdf($html, $paper[0], $paper[1]), 'html' => $html];
    }
}
