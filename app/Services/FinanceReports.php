<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-22 the Amazon side reports beside SETTLEMENT / ORDERS / BANK (MarketplaceFiles, MoneyService): ADS, FBA_STOCK, RETURNS, REIMB, STORAGE.
 * One batch per file (fin_import_batches with the file hash); rows go in with insert-ignore on each type's dedupe key (unique index), so the same file twice gives
 * 0 new rows, all duplicates. Required columns are marked *; header aliases per type. They feed the report status board (FILES_PRESENT).
 */
class FinanceReports
{
    /** type => [table, label, [field => [required, aliases…]]] – dedupe key = the table's unique index. */
    public const TYPES = [
        'ADS' => ['fin_ads_lines', 'Advertising (sponsored products)', ['ad_date' => [1, 'date', 'start date', 'day'], 'campaign_id' => [1, 'campaign id', 'campaign', 'campaign name'], 'campaign_name' => [0, 'campaign name', 'campaign'],
            'sku' => [0, 'advertised sku', 'sku'], 'spend' => [1, 'spend', 'cost', 'total spend'], 'sales' => [0, '7 day total sales', '14 day total sales', 'sales', 'total sales']]],
        'FBA_STOCK' => ['fin_fba_stock_snapshots', 'FBA inventory snapshot', ['snapshot_date' => [0, 'snapshot date', 'date'], 'sku' => [1, 'sku', 'seller sku'], 'fulfillable' => [0, 'afn fulfillable quantity', 'fulfillable quantity', 'fulfillable'],
            'reserved' => [0, 'afn reserved quantity', 'reserved quantity', 'reserved'], 'inbound_working' => [0, 'afn inbound working quantity', 'inbound working'], 'inbound_shipped' => [0, 'afn inbound shipped quantity', 'inbound shipped'],
            'inbound_receiving' => [0, 'afn inbound receiving quantity', 'inbound receiving'], 'unfulfillable' => [0, 'afn unsellable quantity', 'unfulfillable quantity', 'unsellable']]],
        'RETURNS' => ['fin_returns_lines', 'FBA customer returns', ['order_id' => [1, 'order id'], 'sku' => [1, 'sku'], 'return_date' => [1, 'return date'], 'quantity' => [0, 'quantity'], 'disposition' => [0, 'detailed disposition', 'disposition'], 'reason' => [0, 'reason']]],
        'REIMB' => ['fin_reimbursement_lines', 'Reimbursements', ['reimbursement_id' => [1, 'reimbursement id'], 'approval_date' => [0, 'approval date'], 'sku' => [0, 'sku'], 'reason' => [0, 'reason'], 'amount_total' => [1, 'amount total', 'amount'],
            'quantity' => [0, 'quantity reimbursed total', 'quantity reimbursed cash', 'quantity'], 'case_status' => [0, 'case id', 'case status']]],
        'STORAGE' => ['fin_storage_lines', 'Monthly storage fees', ['month' => [1, 'month of charge', 'month'], 'asin' => [1, 'asin'], 'sku' => [0, 'sku', 'fnsku'], 'fee' => [1, 'estimated monthly storage fee', 'monthly storage fee', 'fee']]],
    ];

    private static function key(string $h): string { return trim(preg_replace('/[\s_\-]+/', ' ', mb_strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $h)))); }

    /** "Mon YYYY", "YYYY-MM" or a date → YYYY-MM. */
    public static function month(string $v): ?string
    {
        $v = trim($v); if (preg_match('/^(\d{4})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]";
        $d = \DateTime::createFromFormat('!M Y', $v) ?: \DateTime::createFromFormat('!F Y', $v); return $d ? $d->format('Y-m') : null;
    }

    public static function import(int $ent, int $branchId, string $type, string $fileName, string $text, object $u): array
    {
        Perm::need($u, 'money.EDIT');
        $t = self::TYPES[$type] ?? throw new \InvalidArgumentException('Choose the report type.');
        if (! DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the shop.');
        $sep = substr_count(strtok($text, "\n") ?: '', "\t") > substr_count(strtok($text, "\n") ?: '', ',') ? "\t" : ',';      /* Amazon reports are often tab separated */
        $rows = []; $fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh); while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) if (array_filter($r, fn ($x) => trim((string) $x) !== '')) $rows[] = array_map('trim', $r); fclose($fh);
        if (! $rows) throw new \InvalidArgumentException('Empty file.');
        $head = array_map([self::class, 'key'], $rows[0]); $map = []; $missing = [];
        foreach ($t[2] as $f => $def) { [$req] = $def; $i = false; foreach (array_slice($def, 1) as $a) if (($i = array_search($a, $head, true)) !== false) break; if ($i !== false) $map[$f] = $i; elseif ($req) $missing[] = $f; }
        if ($missing) throw new \InvalidArgumentException('These required columns are not mapped: ' . implode(', ', $missing) . '.');
        $hash = sha1($text); $before = DB::table('fin_import_batches')->where('company_id', $ent)->where('file_hash', $hash)->where('report_type', $type)->first();
        $batch = (int) DB::table('fin_import_batches')->insertGetId(['report_type' => $type, 'company_id' => $ent, 'branch_id' => $branchId, 'file_name' => mb_substr(basename($fileName), 0, 255), 'file_hash' => $hash, 'rows_total' => 0, 'rows_new' => 0, 'rows_duplicate' => 0, 'rows_error' => 0, 'imported_by' => $u->id, 'imported_at' => now()]);
        $new = 0; $dup = 0; $err = []; $dates = [];
        foreach (array_slice($rows, 1) as $n => $r) {
            $v = fn ($f) => isset($map[$f]) ? (string) ($r[$map[$f]] ?? '') : '';
            $row = ['batch_id' => $batch, 'branch_id' => $branchId]; $e = null;
            switch ($type) {
                case 'ADS': $row += ['ad_date' => MarketplaceFiles::day($v('ad_date')), 'campaign_id' => mb_substr($v('campaign_id'), 0, 60), 'campaign_name' => mb_substr($v('campaign_name'), 0, 190) ?: null, 'sku' => mb_substr($v('sku'), 0, 80) ?: null, 'spend' => MarketplaceFiles::num($v('spend')), 'sales' => $v('sales') !== '' ? MarketplaceFiles::num($v('sales')) : null];
                    if (! $row['ad_date'] || $row['campaign_id'] === '') $e = 'date or campaign missing'; $d = $row['ad_date']; break;
                case 'FBA_STOCK': $row += ['snapshot_date' => MarketplaceFiles::day($v('snapshot_date')) ?: date('Y-m-d'), 'sku' => mb_substr($v('sku'), 0, 80)];
                    foreach (['fulfillable', 'reserved', 'inbound_working', 'inbound_shipped', 'inbound_receiving', 'unfulfillable'] as $q) $row[$q] = (int) MarketplaceFiles::num($v($q));
                    if ($row['sku'] === '') $e = 'SKU missing'; $d = $row['snapshot_date']; break;
                case 'RETURNS': $row += ['order_id' => mb_substr($v('order_id'), 0, 40), 'sku' => mb_substr($v('sku'), 0, 80), 'return_date' => MarketplaceFiles::day($v('return_date')), 'quantity' => max(1, (int) MarketplaceFiles::num($v('quantity'))), 'disposition' => mb_substr($v('disposition'), 0, 40) ?: null, 'reason' => mb_substr($v('reason'), 0, 120) ?: null];
                    if ($row['order_id'] === '' || $row['sku'] === '' || ! $row['return_date']) $e = 'order, SKU or return date missing'; $d = $row['return_date']; break;
                case 'REIMB': $row += ['reimbursement_id' => mb_substr($v('reimbursement_id'), 0, 40), 'approval_date' => MarketplaceFiles::day($v('approval_date')), 'sku' => mb_substr($v('sku'), 0, 80) ?: '', 'reason' => mb_substr($v('reason'), 0, 120) ?: null, 'amount_total' => MarketplaceFiles::num($v('amount_total')), 'quantity' => (int) MarketplaceFiles::num($v('quantity')), 'case_status' => mb_substr($v('case_status'), 0, 30) ?: null];
                    if ($row['reimbursement_id'] === '') $e = 'reimbursement id missing'; $d = $row['approval_date']; break;
                default: $row += ['month' => self::month($v('month')), 'asin' => mb_substr($v('asin'), 0, 20), 'sku' => mb_substr($v('sku'), 0, 80) ?: null, 'fee' => MarketplaceFiles::num($v('fee'))];
                    if (! $row['month'] || $row['asin'] === '') $e = 'month or ASIN missing'; $d = $row['month'] ? $row['month'] . '-01' : null;
            }
            if ($e) { $err[] = ['row' => $n + 2, 'error' => $e]; continue; }
            if (DB::table($t[0])->insertOrIgnore($row)) { $new++; if ($d) $dates[] = $d; } else $dup++;
        }
        DB::table('fin_import_batches')->where('id', $batch)->update(['rows_total' => count($rows) - 1, 'rows_new' => $new, 'rows_duplicate' => $dup, 'rows_error' => count($err), 'period_from' => $dates ? min($dates) : null, 'period_to' => $dates ? max($dates) : null]);
        return ['batch' => $batch, 'new' => $new, 'duplicates' => $dup, 'errors' => array_slice($err, 0, 50), 'same_file' => $before ? 'This exact file was imported on ' . substr((string) $before->imported_at, 0, 16) . '.' : null,
            'message' => "$new new row(s), $dup duplicate(s), " . count($err) . ' error(s).'];
    }

    /** Report status rows (R-FIN-25) of these types per shop: last data date and last import. */
    public static function boardRows(int $ent): array
    {
        $out = [];
        foreach (DB::table('fin_import_batches as b')->join('t_branches as s', 's.id', '=', 'b.branch_id')->where('b.company_id', $ent)->whereIn('b.report_type', array_keys(self::TYPES))
            ->groupBy('b.branch_id', 's.shop_no', 's.shop_name', 'b.report_type')->selectRaw('b.branch_id, s.shop_no, s.shop_name, b.report_type, MAX(b.period_to) d, MAX(b.imported_at) at')->get() as $r)
            $out[] = ['shop' => trim($r->shop_no . ' ' . $r->shop_name), 'shop_id' => (int) $r->branch_id, 'type' => $r->report_type, 'data_until' => $r->d, 'last_import' => substr((string) $r->at, 0, 16)];
        return $out;
    }
}
