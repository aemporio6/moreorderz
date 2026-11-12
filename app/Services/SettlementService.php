<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Marketplace money files. Built now: the TikTok settlement workbook (R-A_SALES-92).
 * Sheets: Order details (one row per order / adjustment), Statements, Reports (the marketplace's own period totals), Payments.
 * Checks, all to the penny: (1) each row: total = net sales + shipping + fees + adjustment, each group = sum of its columns;
 * (2) rows of a statement = its Statements row; (3) payable = total − reserve = the Payments amount; (4) statements of the period = Reports totals.
 * Ledger: statement → Cr 1100 (shop) net sales, fees / shipping / adjustments to their cost accounts, Dr 1110 payout on the way (+ 1120 reserve);
 * bank line matched → Dr 1000 / Cr 1110.
 */
class SettlementService
{
    private const NET = ['gross sales', 'gross sales refund', 'seller discount', 'seller discount refund'];
    /** bucket → [ledger account, fin_settlement_lines.bucket] */
    public const BUCKETS = [
        'TT_COMMISSION' => ['5100', 'FEE'], 'TT_AFFILIATE' => ['5300', 'PPC'], 'TT_SHIPPING_SERVICE' => ['5200', 'FEE'], 'TT_PROMOTION' => ['5300', 'PPC'], 'TT_MANAGED_SERVICE' => ['5100', 'FEE'], 'TT_VAT' => ['5100', 'TAX'],
        'TT_OTHER_FEE' => ['5100', 'FEE'], 'TT_SHIPPING_NET' => ['5200', 'SHIPPING'], 'TT_CHARGEBACK' => ['5400', 'OTHER'], 'TT_FULFIL_STORAGE' => ['5200', 'FEE'], 'TT_ADJUSTMENT' => ['5100', 'OTHER'],
    ];
    public const BUCKET_NAMES = ['TT_COMMISSION' => 'Commission', 'TT_AFFILIATE' => 'Affiliate / creator commission', 'TT_SHIPPING_SERVICE' => 'Shipping service fee', 'TT_PROMOTION' => 'Promotion and ad fees', 'TT_MANAGED_SERVICE' => 'Managed service plan',
        'TT_VAT' => 'VAT on fees', 'TT_OTHER_FEE' => 'Other fee (new column)', 'TT_SHIPPING_NET' => 'Shipping (net)', 'TT_CHARGEBACK' => 'Chargebacks', 'TT_FULFIL_STORAGE' => 'Fulfilment warehouse fee', 'TT_ADJUSTMENT' => 'Other adjustments'];

    public static function feeBucket(string $col): string
    {
        $c = strtolower(trim($col));
        return match (true) {
            $c === 'tiktok shop commission fee' => 'TT_COMMISSION',
            str_starts_with($c, 'affiliate') => 'TT_AFFILIATE',
            $c === 'shipping service fee' => 'TT_SHIPPING_SERVICE',
            in_array($c, ['smart promotion fee', 'campaign resource fee', 'campaign service fee', 'co-funded promotion (seller-funded)', 'gmv max ad fee', 'auto-post shoppable video commission fee'], true) => 'TT_PROMOTION',
            str_starts_with($c, 'managed service plan') => 'TT_MANAGED_SERVICE',
            $c === 'vat' => 'TT_VAT',
            default => 'TT_OTHER_FEE',
        };
    }

    public static function adjustmentBucket(string $reason): string
    {
        $r = strtolower($reason);
        return str_contains($r, 'chargeback') ? 'TT_CHARGEBACK' : (str_contains($r, 'warehouse service fee') ? 'TT_FULFIL_STORAGE' : 'TT_ADJUSTMENT');
    }

    private static function money(mixed $v): float { $s = trim((string) $v); return $s === '' || $s === '/' ? 0.0 : round((float) str_replace(',', '', $s), 2); }
    private static function day(mixed $v): ?string { $s = trim((string) $v); if ($s === '' || $s === '/') return null; $d = \DateTime::createFromFormat('!Y/m/d', substr($s, 0, 10)); return $d ? $d->format('Y-m-d') : null; }

    /** Read the workbook and run every check. Nothing is saved. */
    public static function readTikTok(string $path, float $tol = 0.0): array
    {
        $wb = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        $sheet = function (string $name) use ($wb) { $s = $wb->getSheetByName($name) ?? throw new \InvalidArgumentException("This is not a TikTok settlement file: the sheet \"$name\" is missing."); return $s->toArray(null, false, false, false); };
        $eq = fn (float $a, float $b) => abs(round($a - $b, 2)) <= $tol + 0.00001;

        // Order details: groups are found by the position of their head columns, so a new column lands in its group by itself
        $od = $sheet('Order details'); $head = array_map(fn ($h) => strtolower(trim((string) $h)), $od[0] ?? []); $ix = array_flip(array_filter($head, fn ($h) => $h !== ''));
        foreach (['statement id', 'type', 'order/adjustment id', 'total settlement amount', 'net sales', 'shipping', 'fees', 'adjustment amount'] as $must) if (! isset($ix[$must])) throw new \InvalidArgumentException("Column \"$must\" is missing on the sheet Order details.");
        $shipCols = array_slice($head, $ix['shipping'] + 1, $ix['fees'] - $ix['shipping'] - 1, true); $feeCols = array_slice($head, $ix['fees'] + 1, $ix['adjustment amount'] - $ix['fees'] - 1, true);
        $newCols = array_values(array_filter($feeCols, fn ($c) => self::feeBucket($c) === 'TT_OTHER_FEE'));
        $rows = []; $bad = [];
        foreach (array_slice($od, 1) as $n => $r) {
            if (trim((string) ($r[$ix['statement id']] ?? '')) === '') continue;
            $g = fn (string $c) => self::money($r[$ix[$c]] ?? 0);
            $row = ['line' => $n + 2, 'statement_id' => trim((string) $r[$ix['statement id']]), 'payout_id' => trim((string) ($r[$ix['payment id']] ?? '')), 'statement_date' => self::day($r[$ix['statement date']] ?? ''), 'type' => trim((string) $r[$ix['type']]),
                'order_id' => trim((string) $r[$ix['order/adjustment id']]), 'sku_id' => trim((string) ($r[$ix['sku id']] ?? '')), 'qty' => (int) self::money($r[$ix['quantity']] ?? 0), 'order_date' => self::day($r[$ix['order created date']] ?? ''),
                'total' => $g('total settlement amount'), 'net' => $g('net sales'), 'shipping' => $g('shipping'), 'fees' => $g('fees'), 'adjustment' => $g('adjustment amount'), 'reason' => trim((string) ($r[$ix['adjustment reason']] ?? '')),
                'related_order' => trim((string) ($r[$ix['related order id']] ?? '')), 'gross' => $g('gross sales'), 'gross_refund' => $g('gross sales refund'), 'parts' => []];
            if ($row['reason'] === '/') $row['reason'] = '';
            $netSum = 0; foreach (self::NET as $c) { $v = isset($ix[$c]) ? $g($c) : 0; $netSum += $v; if ($v != 0.0) $row['parts'][] = ['NET_SALES', $c, $v, str_contains($c, 'refund') ? 'REFUND' : 'SALE']; }
            $shipSum = 0; foreach ($shipCols as $i => $c) { $v = self::money($r[$i] ?? 0); $shipSum += $v; if ($v != 0.0) $row['parts'][] = ['TT_SHIPPING_NET', $c, $v, 'SHIPPING']; }
            $feeSum = 0; foreach ($feeCols as $i => $c) { $v = self::money($r[$i] ?? 0); $feeSum += $v; if ($v != 0.0) { $b = self::feeBucket($c); $row['parts'][] = [$b, $c, $v, self::BUCKETS[$b][1]]; } }
            if ($row['adjustment'] != 0.0) { $b = self::adjustmentBucket($row['reason'] ?: $row['type']); $row['parts'][] = [$b, $row['reason'] ?: $row['type'], $row['adjustment'], str_contains(strtolower($row['reason']), 'reimbursement') ? 'REIMBURSEMENT' : self::BUCKETS[$b][1]]; }
            $why = [];
            if (! $eq($row['total'], $row['net'] + $row['shipping'] + $row['fees'] + $row['adjustment'])) $why[] = 'total ≠ net sales + shipping + fees + adjustment';
            if (! $eq($row['net'], $netSum)) $why[] = 'net sales ≠ its columns';
            if (! $eq($row['shipping'], $shipSum)) $why[] = 'shipping ≠ its columns';
            if (! $eq($row['fees'], $feeSum)) $why[] = 'fees ≠ its columns';
            if ($why) $bad[] = "Row {$row['line']} (order {$row['order_id']}): " . implode('; ', $why);
            $row['full_refund'] = $row['gross'] > 0 && $row['net'] == 0.0 && $row['type'] === 'Order';
            $rows[] = $row;
        }

        // Payments
        $pays = []; $pm = $sheet('Payments'); $ph = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $pm[0] ?? []));
        foreach (array_slice($pm, 1) as $r) { $id = trim((string) ($r[$ph['payment id']] ?? '')); if ($id !== '') $pays[$id] = ['amount' => self::money($r[$ph['payment amount']] ?? 0), 'date' => self::day($r[$ph['payment completion date']] ?? '') ?: self::day($r[$ph['payment initiation date']] ?? ''), 'status' => trim((string) ($r[$ph['status']] ?? '')), 'bank' => trim((string) ($r[$ph['bank account']] ?? ''))]; }

        // Statements
        $statements = []; $sm = $sheet('Statements'); $sh = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $sm[0] ?? [])); $stBad = [];
        foreach (array_slice($sm, 1) as $r) {
            $id = trim((string) ($r[$sh['statement id']] ?? '')); if ($id === '') continue;
            $s = ['statement_id' => $id, 'payout_id' => trim((string) ($r[$sh['payment id']] ?? '')), 'statement_date' => self::day($r[$sh['statement date']] ?? ''), 'status' => trim((string) ($r[$sh['status']] ?? ''))];
            foreach (['total' => 'total settlement amount', 'net_sales' => 'net sales', 'shipping' => 'shipping', 'fees' => 'fees', 'adjustments' => 'adjustments', 'reserve' => 'reserve amount', 'payable' => 'payable amount'] as $k => $c) $s[$k] = self::money($r[$sh[$c]] ?? 0);
            $mine = array_filter($rows, fn ($x) => $x['statement_id'] === $id);
            $s['rows_count'] = count($mine); $s['rows_total'] = round(array_sum(array_column($mine, 'total')), 2); $s['rows_complete'] = $s['rows_count'] > 0 && $eq($s['rows_total'], $s['total']);
            $s['payment'] = $pays[$s['payout_id']] ?? null;
            if (! $eq($s['total'], $s['net_sales'] + $s['shipping'] + $s['fees'] + $s['adjustments'])) $stBad[] = "Statement $id: total ≠ net sales + shipping + fees + adjustments";
            if (! $eq($s['payable'], $s['total'] - $s['reserve'])) $stBad[] = "Statement $id: payable ≠ total − reserve";
            $statements[$id] = $s;
        }
        // one payment can pay several statements: the payable amounts of its statements together = the payment
        $byPay = []; foreach ($statements as $s) if ($s['payment']) $byPay[$s['payout_id']] = round(($byPay[$s['payout_id']] ?? 0) + $s['payable'], 2);
        foreach ($byPay as $pid => $sum) if (! $eq($sum, $pays[$pid]['amount'])) $stBad[] = "Payment $pid: its statements pay " . number_format($sum, 2) . ' but the payment is ' . number_format($pays[$pid]['amount'], 2);

        // Reports: label in the first text cell of the row, value in the last cell; the indent (cell position) is the level
        $rep = ['period_from' => null, 'period_to' => null, 'currency' => 'GBP', 'items' => []]; $stack = [];
        foreach ($sheet('Reports') as $r) {
            $cells = array_filter($r, fn ($c) => $c !== null && trim((string) $c) !== ''); if (count($cells) < 2) continue;
            $pos = array_key_first($cells); $label = trim((string) $cells[$pos]); $val = trim((string) end($cells));
            if ($label === 'Time period') { [$a, $b] = array_pad(explode('-', $val), 2, null); $rep['period_from'] = self::day($a); $rep['period_to'] = self::day($b); continue; }
            if ($label === 'Currency') { $rep['currency'] = strtoupper($val); continue; }
            if (! is_numeric(str_replace(',', '', $val))) continue;
            $stack = array_filter($stack, fn ($p) => $p < $pos, ARRAY_FILTER_USE_KEY); $parent = $stack ? end($stack) : null; $stack[$pos] = $label;
            $rep['items'][] = ['label' => $label, 'level' => $pos, 'parent' => $parent, 'value' => self::money($val)];
        }
        $top = []; foreach ($rep['items'] as $it) if (in_array($it['label'], ['Total settlement amount', 'Net sales', 'Shipping', 'Fees', 'Adjustments'], true) && ! isset($top[$it['label']])) $top[$it['label']] = $it['value'];
        $rep['total'] = $top['Total settlement amount'] ?? 0; $rep['net_sales'] = $top['Net sales'] ?? 0; $rep['shipping'] = $top['Shipping'] ?? 0; $rep['fees'] = $top['Fees'] ?? 0; $rep['adjustments'] = $top['Adjustments'] ?? 0;
        $groups = [];
        foreach (['Net sales', 'Shipping', 'Fees'] as $gname) { $kids = array_filter($rep['items'], fn ($it) => $it['parent'] === $gname); if ($kids) $groups[$gname] = ['total' => $top[$gname] ?? 0, 'parts' => round(array_sum(array_column($kids, 'value')), 2)]; }
        $stSum = round(array_sum(array_column($statements, 'total')), 2);
        $checks = [
            'rows' => ['ok' => ! $bad, 'text' => 'Every row: total = net sales + shipping + fees + adjustment, and each group = its columns', 'detail' => count($rows) . ' rows' . ($bad ? ', ' . count($bad) . ' wrong' : '')],
            'statements' => ['ok' => ! $stBad, 'text' => 'Every statement: total = its groups, payable = total − reserve; the statements of a payment together = the payment', 'detail' => count($statements) . ' statement(s), ' . count($byPay) . ' payment(s)'],
            'reports' => ['ok' => $eq($rep['total'], $rep['net_sales'] + $rep['shipping'] + $rep['fees'] + $rep['adjustments']), 'text' => 'Period totals of the marketplace: total = net sales + shipping + fees + adjustments',
                'detail' => number_format($rep['total'], 2) . ' = ' . number_format($rep['net_sales'], 2) . ' + ' . number_format($rep['shipping'], 2) . ' + ' . number_format($rep['fees'], 2) . ' + ' . number_format($rep['adjustments'], 2)],
            'report_groups' => ['ok' => ! array_filter($groups, fn ($g) => ! $eq($g['total'], $g['parts'])), 'text' => 'Period totals: each group = the sum of its lines', 'detail' => implode(' · ', array_map(fn ($k, $g) => "$k " . number_format($g['parts'], 2), array_keys($groups), $groups))],
        ];
        $complete = array_filter($statements, fn ($s) => $s['rows_complete']);
        $hub = $eq($stSum, $rep['total']) ? 'OK' : (abs($stSum) < abs($rep['total']) ? 'INCOMPLETE' : 'DIFF');
        return ['rows' => $rows, 'statements' => $statements, 'payments' => $pays, 'report' => $rep, 'checks' => $checks, 'row_errors' => $bad, 'statement_errors' => $stBad, 'new_columns' => $newCols,
            'statements_total' => $stSum, 'statements_with_all_rows' => count($complete), 'hub_check' => $hub, 'full_refunds' => count(array_filter($rows, fn ($r) => $r['full_refund']))];
    }

    /** Load the workbook for one shop. Safe to load the same file again: statements and lines are never doubled. */
    public static function importTikTok(int $ent, int $branchId, string $path, string $fileName): array
    {
        $shop = DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the shop this file belongs to.');
        $x = self::readTikTok($path, (float) Settings::get('TT_SETTLEMENT_TOLERANCE', $ent, 0));
        if ($x['row_errors'] || $x['statement_errors']) {
            throw new \InvalidArgumentException('The file does not add up, nothing was loaded. ' . implode(' | ', array_slice(array_merge($x['row_errors'], $x['statement_errors']), 0, 5)));
        }
        if (! $x['checks']['reports']['ok']) throw new \InvalidArgumentException('The period totals on the sheet Reports do not add up, nothing was loaded: ' . $x['checks']['reports']['detail']);
        return DB::transaction(function () use ($ent, $shop, $x, $path, $fileName) {
            $rep = $x['report'];
            $batch = DB::table('fin_import_batches')->insertGetId(['report_type' => 'TIKTOK_SETTLEMENT', 'company_id' => $ent, 'branch_id' => $shop->id, 'file_name' => mb_substr($fileName, 0, 200), 'file_hash' => sha1_file($path), 'period_from' => $rep['period_from'], 'period_to' => $rep['period_to'], 'imported_by' => Context::userId(), 'imported_at' => now()]);
            $newSt = 0; $oldSt = 0;
            foreach ($x['statements'] as $s) {
                $have = DB::table('mo_settlements')->where('fk_id_branch', $shop->id)->where('statement_id', $s['statement_id'])->first();
                $data = ['fk_id_ent' => $ent, 'fk_id_branch' => $shop->id, 'fk_id_batch' => $batch, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => $s['statement_id'], 'payout_id' => $s['payout_id'] ?: null, 'statement_date' => $s['statement_date'], 'payout_date' => $s['payment']['date'] ?? null,
                    'currency' => $rep['currency'], 'file_status' => $s['status'], 'total' => $s['total'], 'net_sales' => $s['net_sales'], 'shipping' => $s['shipping'], 'fees' => $s['fees'], 'adjustments' => $s['adjustments'], 'reserve' => $s['reserve'], 'payable' => $s['payable'],
                    'rows_total' => $s['rows_total'], 'rows_count' => $s['rows_count'], 'f_rows_complete' => $s['rows_complete'] ? 1 : 0, 'bank_mask' => $s['payment']['bank'] ?? null, 'updated_at' => now()];
                if (! $have) { DB::table('mo_settlements')->insert($data + ['status' => 'LOADED', 'created_by' => Context::userId(), 'created_at' => now()]); $newSt++; }
                else { $oldSt++; if ($have->status === 'LOADED') DB::table('mo_settlements')->where('id', $have->id)->update($data); }
            }
            $newLines = 0; $dupLines = 0; $seen = []; $orphans = []; $proposals = 0; $noSale = 0;
            foreach ($x['rows'] as $r) {
                $s = $x['statements'][$r['statement_id']] ?? null; if (! $s) $orphans[$r['statement_id']] = true;
                foreach ($r['parts'] as [$type, $col, $amount, $bucket]) {
                    $k = implode('|', [$r['statement_id'], $r['order_id'], $r['sku_id'], $type, $col, $amount]); $seen[$k] = ($seen[$k] ?? 0) + 1; $hash = sha1($k . '|' . $seen[$k]);
                    if (DB::table('fin_settlement_lines')->where('branch_id', $shop->id)->where('settlement_id', $r['statement_id'])->where('row_hash', $hash)->exists()) { $dupLines++; continue; }
                    DB::table('fin_settlement_lines')->insert(['batch_id' => $batch, 'company_id' => $ent, 'branch_id' => $shop->id, 'settlement_id' => $r['statement_id'], 'settlement_start' => $rep['period_from'], 'settlement_end' => $rep['period_to'],
                        'deposit_date' => $s['payment']['date'] ?? null, 'total_amount' => $s['total'] ?? null, 'transaction_type' => mb_substr($r['type'], 0, 60), 'order_id' => $r['order_id'], 'sku' => $r['sku_id'] ?: null, 'quantity' => $r['qty'],
                        'amount_type' => $type, 'amount_description' => mb_substr($col, 0, 120), 'amount' => $amount, 'currency' => $rep['currency'], 'posted_date' => $r['statement_date'], 'bucket' => $bucket, 'row_hash' => $hash]);
                    $newLines++;
                }
                if ($r['full_refund']) {   // gross > 0 and net sales 0 = the whole order was refunded (owner rule R7): a proposal a person confirms, never an automatic refund
                    $sale = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $r['order_id'])->orderBy('id')->value('id');
                    if (! $sale) { $noSale++; continue; }
                    if (! DB::table('t_sale_refund_proposals')->where('fk_id_sale', $sale)->where('refund_id', $r['statement_id'] . ':' . $r['order_id'])->exists()) {
                        DB::table('t_sale_refund_proposals')->insert(RecordService::fillNotNull('t_sale_refund_proposals', ['fk_id_sale' => $sale, 'fk_id_batch' => $batch, 'refund_id' => mb_substr($r['statement_id'] . ':' . $r['order_id'], 0, 60), 'refund_kind' => 'FULL', 'amount' => abs($r['gross_refund']), 'status' => 'PROPOSED', 'created_at' => now()]));
                        $proposals++;
                    }
                }
            }
            DB::table('fin_import_batches')->where('id', $batch)->update(['rows_total' => $newLines + $dupLines, 'rows_new' => $newLines, 'rows_duplicate' => $dupLines, 'rows_error' => 0]);
            // hub totals: against every statement of this shop inside the period, not only this file
            $inPeriod = (float) DB::table('mo_settlements')->where('fk_id_branch', $shop->id)->where('report_type', 'TIKTOK_SETTLEMENT')->whereBetween('statement_date', [$rep['period_from'], $rep['period_to']])->sum('total');
            $hub = abs(round($inPeriod - $rep['total'], 2)) < 0.005 ? 'OK' : (abs($inPeriod) < abs($rep['total']) ? 'INCOMPLETE' : 'DIFF');
            DB::table('mo_settlement_periods')->updateOrInsert(['fk_id_branch' => $shop->id, 'report_type' => 'TIKTOK_SETTLEMENT', 'period_from' => $rep['period_from'], 'period_to' => $rep['period_to']],
                ['fk_id_ent' => $ent, 'fk_id_batch' => $batch, 'currency' => $rep['currency'], 'total' => $rep['total'], 'net_sales' => $rep['net_sales'], 'shipping' => $rep['shipping'], 'fees' => $rep['fees'], 'adjustments' => $rep['adjustments'],
                    'totals_json' => json_encode($rep['items']), 'checks_json' => json_encode($x['checks']), 'hub_check' => $hub, 'updated_at' => now(), 'created_at' => now()]);
            TaskEngine::close('FIN_SETTLEMENT_OVERDUE', [$shop->id], 'Money file loaded');
            Audit::log('fin_import_batches', $batch, 'INSERT', ['type' => 'TIKTOK_SETTLEMENT', 'shop' => $shop->shop_name, 'statements' => $newSt, 'lines' => $newLines], null, $ent);
            return ['batch' => $batch, 'statements_new' => $newSt, 'statements_already' => $oldSt, 'lines_new' => $newLines, 'lines_already' => $dupLines, 'rows_of_statements_not_in_file' => count($orphans), 'refund_proposals' => $proposals,
                'full_refunds' => $x['full_refunds'], 'full_refunds_without_order' => $noSale, 'hub_check' => $hub, 'period' => [$rep['period_from'], $rep['period_to']], 'report_total' => $rep['total'], 'statements_in_period' => round($inPeriod, 2),
                'checks' => $x['checks'], 'new_columns' => $x['new_columns'], 'statements_with_all_rows' => $x['statements_with_all_rows']];
        });
    }

    /** Book one statement. With all its rows in the file the costs go to their own buckets; without them, to the group totals of the statement. */
    public static function post(int $ent, int $id): int
    {
        return DB::transaction(function () use ($ent, $id) {
            $s = DB::table('mo_settlements')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Statement not found.');
            if ($s->status !== 'LOADED') throw new \InvalidArgumentException('This statement is already booked.');
            $rate = Fx::rate($s->currency, $s->statement_date, $ent); $b = fn ($v) => round((float) $v * $rate, 2); $shop = (int) $s->fk_id_branch; $lines = [];
            // $v > 0 = money for us (credit), $v < 0 = cost (debit)
            $add = function (string $acc, float $v, string $memo, bool $shopRef) use (&$lines, $b, $shop) { if (round($v, 2) == 0.0) return; $lines[] = ['account' => $acc, $v > 0 ? 'credit' : 'debit' => abs($b($v)), 'ref_id' => $shopRef ? $shop : null, 'memo' => $memo, 'amount_cur' => $v]; };
            $sub = fn (string $acc) => in_array($acc, ['5100', '5300', '5400'], true);
            $add('1100', (float) $s->net_sales, 'Net sales settled', true);
            if ($s->f_rows_complete) {
                $by = DB::table('fin_settlement_lines')->where('branch_id', $shop)->where('settlement_id', $s->statement_id)->where('amount_type', '<>', 'NET_SALES')->groupBy('amount_type')->selectRaw('amount_type, SUM(amount) v')->pluck('v', 'amount_type');
                $vat = Choices::get('VAT_REGISTERED', $ent) === '1';
                foreach ($by as $bucket => $v) { $acc = $bucket === 'TT_VAT' && $vat ? '1400' : (self::BUCKETS[$bucket][0] ?? '5100'); $add($acc, (float) $v, self::BUCKET_NAMES[$bucket] ?? $bucket, $sub($acc)); }
            } else {
                $add('5200', (float) $s->shipping, 'Shipping (net)', false); $add('5100', (float) $s->fees, 'Marketplace fees (statement total)', true); $add('5100', (float) $s->adjustments, 'Adjustments (statement total)', true);
            }
            // the other side: what is on the way to the bank and what the marketplace holds back
            $out = function (string $acc, float $v, string $memo) use (&$lines, $b, $shop) { if (round($v, 2) == 0.0) return; $lines[] = ['account' => $acc, $v > 0 ? 'debit' : 'credit' => abs($b($v)), 'ref_id' => $shop, 'memo' => $memo, 'amount_cur' => $v]; };
            $out('1110', (float) $s->payable, 'Payout on the way'); $out('1120', (float) $s->reserve, 'Held back by the marketplace');
            if (! $lines) {   // a statement of zero (for example only fully refunded orders): nothing to book, but it is done
                DB::table('mo_settlements')->where('id', $s->id)->update(['status' => 'POSTED', 'journal_id' => null, 'updated_at' => now()]);
                return 0;
            }
            $dr = array_sum(array_column($lines, 'debit')); $cr = array_sum(array_column($lines, 'credit'));
            if (abs(round($dr - $cr, 2)) >= 0.005) {   // rounding of a foreign currency only; a real difference never gets here because the file was checked on load
                if (abs(round($dr - $cr, 2)) > 0.05) throw new \RuntimeException('The statement does not balance: ' . number_format($dr, 2) . ' against ' . number_format($cr, 2) . '.');
                $lines[] = ['account' => '6200', $dr > $cr ? 'credit' : 'debit' => abs(round($dr - $cr, 2)), 'memo' => 'Rounding'];
            }
            $jid = Ledger::post($ent, $s->statement_date, 'SETTLEMENT', (int) $s->id, $lines, ['memo' => "Marketplace statement {$s->statement_id}", 'currency' => $s->currency, 'fx_rate' => $rate]);
            DB::table('mo_settlements')->where('id', $s->id)->update(['status' => 'POSTED', 'journal_id' => $jid, 'updated_at' => now()]);
            EventBus::fire('SETTLEMENT_CONFIRMED', 'mo_settlements', $s->id, ['shop' => $shop, 'payable' => (float) $s->payable], $ent);
            return $jid;
        });
    }

    /** Take a booked statement back (only while its payout is not matched to the bank). */
    public static function unpost(int $ent, int $id): void
    {
        DB::transaction(function () use ($ent, $id) {
            $s = DB::table('mo_settlements')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Statement not found.');
            if ($s->status === 'PAID') throw new \InvalidArgumentException('The payout of this statement is matched to a bank line. Undo that match first.');
            if ($s->status !== 'POSTED') throw new \InvalidArgumentException('This statement is not booked.');
            if ($s->journal_id) Ledger::reverse((int) $s->journal_id, null, 'Statement taken back');
            DB::table('mo_settlements')->where('id', $id)->update(['status' => 'LOADED', 'journal_id' => null, 'updated_at' => now()]);
        });
    }

    /** Book every loaded statement of a shop (oldest first). */
    public static function postAll(int $ent, int $branchId): int
    {
        $n = 0;
        foreach (DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', 'LOADED')->orderBy('statement_date')->orderBy('id')->pluck('id') as $id) { self::post($ent, (int) $id); $n++; }
        return $n;
    }

    /** Booked payouts not yet seen on the bank whose statements together are exactly this amount, near this date. One payment can pay several statements. */
    public static function payoutCandidates(int $ent, float $amount, string $currency, string $date, int $days): array
    {
        return DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('status', 'POSTED')->whereNull('fk_id_bank_line')->where('currency', $currency)
            ->groupBy('fk_id_branch', DB::raw('COALESCE(payout_id, statement_id)'))->havingRaw('ROUND(SUM(payable),2) = ?', [round($amount, 2)])
            ->havingRaw('MAX(COALESCE(payout_date, statement_date)) BETWEEN ? AND ?', [date('Y-m-d', strtotime("$date -$days day")), date('Y-m-d', strtotime("$date +$days day"))])
            ->selectRaw('fk_id_branch, COALESCE(payout_id, statement_id) pay_key, MIN(id) first_id, COUNT(*) n, MAX(COALESCE(payout_date, statement_date)) pay_date')->get()->all();
    }

    /** The payout arrived on the bank: Dr bank / Cr payout on the way, for all statements of that payment. Called by the bank matching. Returns the first statement id. */
    public static function paid(int $ent, int $branchId, string $payKey, object $bankLine): int
    {
        $sts = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', 'POSTED')->whereRaw('COALESCE(payout_id, statement_id) = ?', [$payKey])->orderBy('id')->lockForUpdate()->get();
        $base = 0.0; foreach ($sts as $s) $base += round((float) $s->payable * Fx::rate($s->currency, $s->statement_date, $ent), 2);
        $base = round($base, 2); $got = round((float) $bankLine->amount_gbp, 2); $first = $sts[0];
        $lines = [['account' => '1000', 'debit' => $got, 'ref_id' => (int) $bankLine->bank_account_id, 'amount_cur' => (float) $bankLine->amount], ['account' => '1110', 'credit' => $base, 'ref_id' => $branchId]];
        if (abs($got - $base) >= 0.005) $lines[] = ['account' => '6200', $got < $base ? 'debit' : 'credit' => abs(round($got - $base, 2)), 'memo' => 'Exchange difference on the payout'];
        $jid = Ledger::post($ent, $bankLine->txn_date, 'PAYOUT', (int) $first->id, $lines, ['memo' => "Payout $payKey received", 'currency' => $first->currency]);
        DB::table('mo_settlements')->whereIn('id', $sts->pluck('id'))->update(['status' => 'PAID', 'payout_journal_id' => $jid, 'fk_id_bank_line' => $bankLine->id, 'payout_date' => $bankLine->txn_date, 'updated_at' => now()]);
        return (int) $first->id;
    }

    public static function unpaid(int $firstId): void
    {
        $s = DB::table('mo_settlements')->where('id', $firstId)->first(); if (! $s || $s->status !== 'PAID') return;
        Ledger::reverse((int) $s->payout_journal_id, null, 'Payout match undone');
        DB::table('mo_settlements')->where('payout_journal_id', $s->payout_journal_id)->update(['status' => 'POSTED', 'payout_journal_id' => null, 'fk_id_bank_line' => null, 'updated_at' => now()]);
    }

    /**
     * A person decides a refund proposal. Accept (confirmed sale): the booked sale is taken out of what the shop owes us – Dr 5400 refunds / Cr 1100.
     * The goods coming back are a separate step (Returns). Reject needs a reason.
     */
    public static function decideRefund(int $ent, int $proposalId, bool $accept, ?string $reason = null): void
    {
        DB::transaction(function () use ($ent, $proposalId, $accept, $reason) {
            $p = DB::table('t_sale_refund_proposals')->where('id', $proposalId)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Refund proposal not found.');
            $s = DB::table('t_sales')->where('id', $p->fk_id_sale)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Refund proposal not found.');
            if ($p->status !== 'PROPOSED') throw new \InvalidArgumentException('This proposal was already decided.');
            if (! $accept) {
                if (! trim((string) $reason)) throw new \InvalidArgumentException('Give the reason for rejecting.');
                DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['status' => 'REJECTED', 'reject_reason' => $reason, 'decided_by' => Context::userId(), 'decided_at' => now()]);
                return;
            }
            $amount = $p->refund_kind === 'FULL' ? null : (float) $p->amount;
            $x = self::refund($ent, (int) $s->id, $amount, 'Marketplace money file', (int) $p->id);
            DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['status' => 'ACCEPTED', 'decided_by' => Context::userId(), 'decided_at' => now()]);
        });
    }

    /**
     * Refund a sale line, fully ($amount null) or in part. Several part refunds may follow each other, never more than the line's value.
     * Confirmed line: Dr 5400 refunds / Cr 1100 (the shop owes us less). A line not confirmed and not shipped: a full refund cancels the line and frees its stock;
     * a part refund of such a line is refused (change its price instead); a shipped, unconfirmed line must be confirmed first.
     * The goods coming back are a separate step (Returns).
     */
    public static function refund(int $ent, int $saleId, ?float $amount, string $reason, ?int $proposalId = null): array
    {
        return DB::transaction(function () use ($ent, $saleId, $amount, $reason, $proposalId) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', $ent)->where('sale_source', 'active')->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Order line not found.');
            if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for the refund.');
            $total = round((float) $s->total_amount_gbp, 2); $done = round((float) $s->refund_loss_amount, 2); $left = round($total - $done, 2);
            if ($s->status === 'Pending') {
                if ($amount !== null && round($amount, 2) < $total) throw new \InvalidArgumentException("Order {$s->order_no} is not confirmed yet: a part refund is not booked – change the line's price instead, or refund it in full.");
                if ((int) $s->qty_shipped > 0) throw new \InvalidArgumentException("Order {$s->order_no} is shipped but its month is not confirmed yet. Confirm the month first (Selling › Month confirm), then refund.");
                DispatchService::release($saleId, 'Refunded in full: ' . $reason);
                DB::table('t_sales')->where('id', $saleId)->update(['status' => 'Cancelled', 'refund_reason' => mb_substr($reason, 0, 250), 'refunded_at' => now(), 'refunded_by' => Context::userId(), 'refund_loss_nature' => 'FULL', 'fk_id_refund_proposal' => $proposalId, 'updated_at' => now()]);
                Audit::log('t_sales', $saleId, 'UPDATE', ['status' => ['Pending', 'Cancelled'], 'refund' => 'FULL before confirm'], $reason, $ent);
                return ['kind' => 'CANCELLED', 'amount' => 0.0, 'left' => 0.0];
            }
            if ($s->status !== 'Confirmed') throw new \InvalidArgumentException('This order line is ' . strtolower($s->status) . ' – it cannot be refunded.');
            if ($left <= 0) throw new \InvalidArgumentException('This order line is already refunded in full.');
            $amt = $amount === null ? $left : round($amount, 2);
            if ($amt <= 0) throw new \InvalidArgumentException('The refund must be more than zero.');
            if ($amt > $left + 0.005) throw new \InvalidArgumentException('This is more than the ' . number_format($left, 2) . ' still left on this line.');
            Ledger::post($ent, date('Y-m-d'), 'REFUND', $saleId, [['account' => '5400', 'debit' => $amt, 'ref_id' => (int) $s->fk_id_branch], ['account' => '1100', 'credit' => $amt, 'ref_id' => (int) $s->fk_id_branch]], ['memo' => ($amt >= $left && $done == 0.0 ? 'Full' : 'Part') . " refund of order {$s->order_no}"]);
            $full = $amt >= $left - 0.005;
            DB::table('t_sales')->where('id', $saleId)->update(['refund_loss_amount' => round($done + $amt, 2), 'refund_loss_nature' => $full ? 'FULL' : 'PARTIAL', 'refund_reason' => mb_substr($reason, 0, 250), 'refunded_at' => now(), 'refunded_by' => Context::userId(),
                'fk_id_refund_proposal' => $proposalId ?? $s->fk_id_refund_proposal, 'updated_at' => now()]);
            Audit::log('t_sales', $saleId, 'UPDATE', ['refund' => $full ? 'FULL' : 'PARTIAL', 'amount' => $amt, 'refunded_so_far' => round($done + $amt, 2)], $reason, $ent);
            return ['kind' => $full ? 'FULL' : 'PARTIAL', 'amount' => $amt, 'left' => round($left - $amt, 2)];
        });
    }

    /** Refund an order by its number: all its lines in full, or an amount spread over its lines in order. */
    public static function refundOrder(int $ent, int $branchId, string $orderNo, ?float $amount, string $reason): array
    {
        return DB::transaction(function () use ($ent, $branchId, $orderNo, $amount, $reason) {
            $lines = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('order_no', $orderNo)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->orderBy('id')->get();
            if ($lines->isEmpty()) throw new \InvalidArgumentException("No open or confirmed line of order $orderNo in this shop.");
            $out = ['lines' => 0, 'amount' => 0.0, 'cancelled' => 0]; $rest = $amount;
            foreach ($lines as $l) {
                $left = round((float) $l->total_amount_gbp - (float) $l->refund_loss_amount, 2);
                if ($amount === null) { if ($l->status === 'Confirmed' && $left <= 0) continue; $x = self::refund($ent, (int) $l->id, null, $reason); }
                else { if ($rest <= 0.004) break; if ($l->status !== 'Confirmed' || $left <= 0) continue; $x = self::refund($ent, (int) $l->id, min($rest, $left), $reason); $rest = round($rest - $x['amount'], 2); }
                $out['lines']++; $out['amount'] = round($out['amount'] + $x['amount'], 2); if ($x['kind'] === 'CANCELLED') $out['cancelled']++;
            }
            if ($amount !== null && $rest > 0.004) throw new \InvalidArgumentException('This is ' . number_format($rest, 2) . ' more than what is left to refund on the confirmed lines of this order.');
            if (! $out['lines']) throw new \InvalidArgumentException('This order is already refunded in full.');
            return $out;
        });
    }

    /** Per shop: sales booked but not settled (1100), payouts on the way (1110), held back (1120). */
    public static function shopBalances(int $ent): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->whereIn('a.code', ['1100', '1110', '1120'])->whereNotNull('l.ref_id')
            ->groupBy('l.ref_id', 'a.code')->selectRaw('l.ref_id shop, a.code, SUM(l.debit - l.credit) v')->get();
        $names = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id'); $out = [];
        foreach ($rows as $r) { $out[$r->shop] ??= ['shop_id' => (int) $r->shop, 'shop' => $names[$r->shop] ?? ('Shop ' . $r->shop), 'unsettled' => 0.0, 'on_the_way' => 0.0, 'held_back' => 0.0]; $out[$r->shop][['1100' => 'unsettled', '1110' => 'on_the_way', '1120' => 'held_back'][$r->code]] = round((float) $r->v, 2); }
        return array_values($out);
    }
}
