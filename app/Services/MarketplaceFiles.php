<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Money files of the marketplaces, next to the TikTok workbook (SettlementService).
 * The format is found from the file itself:
 *  - TIKTOK      the TikTok settlement workbook (handed to SettlementService::importTikTok)
 *  - AMAZON_V2   Amazon "Flat File V2 Settlement Report" (tab separated, one amount per line) – the current Amazon settlement format
 *  - EBAY        eBay Seller Hub › Payments › Reports › Transaction report (CSV with a notes block on top)
 *  - MAPPED      any other table (OnBuy, Temu, Amazon date-range report, …): columns are recognised by their names
 * Every reader gives the same shape: statements (one per payout / settlement) and amount lines, each line with a code.
 * A code decides the ledger account (BUCKETS). A type or column the reader does not know is never dropped: it is booked as "other" and named in the result.
 * Sales lines (code NET_SALES) settle what the shop owes (1100); a refund in the file makes a refund proposal a person decides.
 */
class MarketplaceFiles
{
    /** code → [ledger account, fin_settlement_lines.bucket, name on screen] */
    public const BUCKETS = [
        'MP_COMMISSION' => ['5100', 'FEE', 'Commission / selling fees'],
        'MP_REGULATORY' => ['5100', 'FEE', 'Regulatory operating fee'],
        'MP_INTERNATIONAL' => ['5100', 'FEE', 'International fee'],
        'MP_PENALTY' => ['5100', 'FEE', 'Performance / "not as described" fees'],
        'MP_LISTING' => ['5100', 'FEE', 'Listing and upgrade fees'],
        'MP_SUBSCRIPTION' => ['5100', 'FEE', 'Subscription'],
        'MP_OTHER_FEE' => ['5100', 'FEE', 'Other fee'],
        'MP_FULFILMENT' => ['5200', 'FEE', 'Fulfilment by the marketplace'],
        'MP_STORAGE' => ['5200', 'FEE', 'Storage, removal, inbound'],
        'MP_SHIPPING_LABEL' => ['5200', 'SHIPPING', 'Postage labels / shipping charged by the marketplace'],
        'MP_ADS' => ['5300', 'PPC', 'Advertising'],
        'MP_VAT_ON_FEES' => ['5100', 'TAX', 'VAT on fees'],
        'MP_TAX_WITHHELD' => ['5100', 'TAX', 'Tax withheld by the marketplace'],
        'MP_CHARGEBACK' => ['5400', 'OTHER', 'Claims, disputes, chargebacks'],
        'MP_REIMBURSEMENT' => ['4100', 'REIMBURSEMENT', 'Reimbursements from the marketplace'],
        'MP_HOLD' => ['1120', 'RESERVE', 'Held back / released by the marketplace'],
        'MP_ADJUSTMENT' => ['5100', 'OTHER', 'Adjustments'],
        'MP_FX' => ['6200', 'OTHER', 'Exchange difference'],
        'MP_OTHER' => ['5100', 'OTHER', 'Not recognised (booked as other)'],
    ];

    public const FORMATS = ['TIKTOK' => 'TikTok Shop settlement workbook', 'AMAZON_TXN' => 'Amazon date range report (transactions)', 'AMAZON_V2' => 'Amazon settlement report (flat file V2)', 'EBAY' => 'eBay transaction report', 'MAPPED' => 'Table read by its column names'];
    public const PLATFORMS = ['ONBUY' => 'OnBuy', 'TEMU' => 'Temu', 'AMAZON' => 'Amazon', 'EBAY' => 'eBay', 'OTHER' => 'Other marketplace'];

    // ---------- small helpers ----------
    public static function num(mixed $v): float
    {
        $s = trim((string) $v); if ($s === '' || $s === '--' || $s === '/' || $s === '-') return 0.0;
        $neg = str_starts_with($s, '(') && str_ends_with($s, ')'); $s = preg_replace('/[^0-9,.\-]/u', '', $s);
        $c = strrpos($s, ','); $d = strrpos($s, '.');
        if ($c !== false && $d !== false) $s = $c > $d ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);   // 1.234,56 or 1,234.56
        elseif ($c !== false) $s = preg_match('/,\d{3}$/', $s) && substr_count($s, ',') >= 1 && ! preg_match('/^-?\d{1,3},\d{3}$/', $s) ? str_replace(',', '', $s) : (preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s));
        $f = round((float) $s, 2);
        return $neg ? -abs($f) : $f;
    }

    public static function day(mixed $v): ?string
    {
        $s = trim((string) $v); if ($s === '' || $s === '--') return null;
        if (is_numeric($s) && (float) $s > 30000 && (float) $s < 80000) return date('Y-m-d', (int) round(((float) $s - 25569) * 86400));   // Excel day number
        $s = preg_replace('/\s+(UTC|GMT|BST|PST|PDT|PKT.*|[A-Z]{2,4}\(UTC[^)]*\))$/', '', $s); $s = preg_replace('/\bSept\b/', 'Sep', $s); $s = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $s);
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:sP', '!Y-m-d', '!d.m.Y H:i:s', '!d.m.Y', '!d/m/Y H:i:s', '!d/m/Y', '!j M Y', '!j M Y H:i:s', '!M j, Y', '!M j, Y, g:i a', '!M j, Y g:i:s A', '!Y/m/d', '!j F Y', '!d-m-Y', '!d-M-Y', '!d M Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $s); $e = \DateTime::getLastErrors();
            if ($d && (! $e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) return $d->format('Y-m-d');
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    private static function key(mixed $h): string { return trim(preg_replace('/\s+/', ' ', str_replace(['–', '—', '_', "\u{FEFF}", '’', "\u{00A0}"], ['-', '-', ' ', '', "'", ' '], mb_strtolower((string) $h)))); }

    /** Rows of a text file (comma, tab or semicolon) or of the first sheet of a workbook. */
    public static function rows(string $path, string $ext): array
    {
        if (in_array($ext, ['xlsx', 'xls'], true)) { $wb = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path); return $wb->getSheet(0)->toArray(null, false, false, false); }
        $txt = file_get_contents($path); if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
        if (! mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        $head = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $txt), 0, 40));
        $sep = substr_count($head, "\t") > substr_count($head, ',') && substr_count($head, "\t") > substr_count($head, ';') ? "\t" : (substr_count($head, ';') > substr_count($head, ',') ? ';' : ',');
        $h = fopen('php://memory', 'r+'); fwrite($h, $txt); rewind($h); $out = [];
        while (($r = fgetcsv($h, null, $sep, '"', '')) !== false) $out[] = $r;
        fclose($h);
        return $out;
    }

    public static function detect(string $path, string $ext): string
    {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $names = IOFactory::createReaderForFile($path)->listWorksheetNames($path);
            return in_array('Order details', $names, true) && in_array('Statements', $names, true) ? 'TIKTOK' : 'MAPPED';
        }
        $head = mb_strtolower(substr((string) file_get_contents($path, false, null, 0, 6000), 0, 6000));
        if (str_contains($head, "settlement-id\t") && str_contains($head, 'amount-description')) return 'AMAZON_V2';
        if (str_contains($head, 'transaction creation date') && str_contains($head, 'payout id')) return 'EBAY';
        if (preg_match('/^"?date\/time"?,"?settlement id"?,/m', $head) && str_contains($head, 'selling fees')) return 'AMAZON_TXN';
        return 'MAPPED';
    }

    // ---------- Amazon: flat file V2 ----------
    public static function amazonCode(string $txn, string $type, string $desc): string
    {
        $t = mb_strtolower($type); $d = mb_strtolower($desc); $x = mb_strtolower($txn); $has = fn (string ...$w) => (bool) array_filter($w, fn ($k) => str_contains($d, $k));
        if (in_array($t, ['itemprice', 'promotion', 'itemwithheldtax', 'shipmentprice', 'directpayment'], true)) return 'NET_SALES';
        if (str_contains($t, 'advertising') || $has('advertis', 'sponsored')) return 'MP_ADS';
        if ($has('reserve')) return 'MP_HOLD';
        if ($has('reimburs', 'warehouse_damage', 'warehouse damage', 'warehouse_lost', 'warehouse lost', 'missing_from_inbound', 'compensated', 'free_replacement', 'safe-t', 'safet')) return 'MP_REIMBURSEMENT';
        if ($has('storage', 'removal', 'disposal', 'inbound', 'liquidation', 'placement')) return 'MP_STORAGE';
        if ($has('fba', 'fulfil', 'fulfill', 'pick & pack', 'weight')) return 'MP_FULFILMENT';
        if ($has('subscription')) return 'MP_SUBSCRIPTION';
        if ($has('chargeback refund', 'a-to-z', 'guarantee', 'dispute') || str_contains($x, 'chargeback') || str_contains($x, 'guarantee')) return 'MP_CHARGEBACK';
        if ($has('label', 'postage', 'shippingservices', 'shipping services') || str_contains($x, 'shipping services')) return 'MP_SHIPPING_LABEL';
        if ($has('tax', 'vat') && $t !== 'itemfees') return 'MP_TAX_WITHHELD';
        if ($t === 'itemfees' || $t === 'shipment-fees' || $t === 'shipmentfees' || $has('commission', 'closing', 'chargeback', 'digitalservices', 'digital services', 'referral', 'refundcommission', 'holdback', 'hb')) return 'MP_COMMISSION';
        if ($has('adjust', 'balance', 'miscellaneous')) return 'MP_ADJUSTMENT';
        return 'MP_OTHER';
    }

    public static function readAmazon(string $path): array
    {
        $rows = self::rows($path, 'txt'); $h = array_map(fn ($x) => self::key($x), $rows[0] ?? []); $ix = array_flip($h);
        foreach (['settlement-id', 'transaction-type', 'amount-type', 'amount-description', 'amount', 'total-amount'] as $must) if (! isset($ix[$must])) throw new \InvalidArgumentException("This is not an Amazon settlement report (flat file V2): the column \"$must\" is missing.");
        $g = fn (array $r, string $k) => trim((string) ($r[$ix[$k] ?? -1] ?? ''));
        $st = []; $lines = []; $refund = []; $unknown = []; $cur = 'GBP';
        foreach (array_slice($rows, 1) as $r) {
            $sid = $g($r, 'settlement-id'); if ($sid === '') continue;
            if ($g($r, 'transaction-type') === '' && $g($r, 'amount-type') === '') {   // the summary line of the settlement
                $cur = strtoupper($g($r, 'currency')) ?: $cur;
                $st[$sid] = ['statement_id' => $sid, 'payout_id' => null, 'date' => self::day($g($r, 'settlement-end-date')) ?? self::day($g($r, 'deposit-date')), 'payout_date' => self::day($g($r, 'deposit-date')), 'from' => self::day($g($r, 'settlement-start-date')), 'file_total' => self::num($g($r, 'total-amount')), 'currency' => $cur, 'bank' => null];
                continue;
            }
            $amt = self::num($g($r, 'amount')); if ($amt == 0.0) continue;
            $txn = $g($r, 'transaction-type'); $code = self::amazonCode($txn, $g($r, 'amount-type'), $g($r, 'amount-description'));
            if ($code === 'MP_OTHER') $unknown[$g($r, 'amount-type') . ' / ' . $g($r, 'amount-description')] = true;
            $order = $g($r, 'order-id');
            $lines[] = ['statement_id' => $sid, 'order_id' => $order, 'sku' => $g($r, 'sku'), 'qty' => (int) self::num($g($r, 'quantity-purchased')), 'txn' => $txn, 'code' => $code, 'desc' => trim($g($r, 'amount-type') . ' / ' . $g($r, 'amount-description'), ' /'), 'amount' => $amt,
                'date' => self::day($g($r, 'posted-date-time')) ?? self::day($g($r, 'posted-date'))];
            if ($code === 'NET_SALES' && $order !== '' && str_contains(mb_strtolower($txn), 'refund')) $refund[$sid . '|' . $order] = round(($refund[$sid . '|' . $order] ?? 0) + $amt, 2);
        }
        if (! $st) throw new \InvalidArgumentException('The file has no settlement summary line (the first data line with the total and the deposit date).');
        $sum = []; foreach ($lines as $l) $sum[$l['statement_id']] = round(($sum[$l['statement_id']] ?? 0) + $l['amount'], 2);
        $errors = []; foreach ($st as $sid => $s) if (abs(round(($sum[$sid] ?? 0) - $s['file_total'], 2)) >= 0.005) $errors[] = "Settlement $sid: its lines add up to " . number_format($sum[$sid] ?? 0, 2) . ', Amazon\'s total is ' . number_format($s['file_total'], 2) . '.';
        foreach ($lines as $l) if (! isset($st[$l['statement_id']])) { $errors[] = "Lines of settlement {$l['statement_id']} have no summary line."; break; }
        $first = reset($st);
        return ['format' => 'AMAZON_V2', 'report_type' => 'AMAZON_SETTLEMENT', 'currency' => $cur, 'period' => [min(array_filter(array_column($st, 'from')) ?: [$first['date']]), max(array_column($st, 'date'))], 'statements' => $st, 'lines' => $lines, 'errors' => $errors,
            'refunds' => $refund, 'unknown' => array_keys($unknown), 'skipped' => ['rows' => 0, 'amount' => 0.0], 'mapping' => [],
            'checks' => [['ok' => ! $errors, 'text' => 'The lines of each settlement add up to Amazon\'s total, to the penny', 'detail' => count($st) . ' settlement(s), ' . count($lines) . ' amount line(s)'],
                ['ok' => ! $unknown, 'text' => 'Every amount type is known', 'detail' => $unknown ? 'Booked as "other": ' . implode('; ', array_slice(array_keys($unknown), 0, 8)) : '']]];
    }

    // ---------- Amazon: date range report (Payments › Reports repository › Transaction) ----------
    /**
     * Built on a real report of the owner (Jul–Sep 2026, 4,632 lines). One line per transaction, one column per amount group, a notes block on top.
     * A statement = one settlement id. The line of type "Transfer" is the payout of the settlement BEFORE it (it stands among the lines of the next settlement),
     * so a payout is tied to the settlement whose lines add up to exactly that amount. A settlement cut by the start or the end of the report has no payout in the file.
     */
    public static function readAmazonTxn(string $path): array
    {
        $rows = self::rows($path, 'csv'); $hi = null;
        foreach (array_slice($rows, 0, 40, true) as $i => $r) if (self::key($r[0] ?? '') === 'date/time' && self::key($r[1] ?? '') === 'settlement id') { $hi = $i; break; }
        if ($hi === null) throw new \InvalidArgumentException('This is not an Amazon date range report: the line starting with "date/time, settlement id" is missing.');
        $h = array_map(fn ($x) => self::key($x), $rows[$hi]); $ix = array_flip($h);
        foreach (['type', 'product sales', 'selling fees', 'total'] as $must) if (! isset($ix[$must])) throw new \InvalidArgumentException("The column \"$must\" is missing in the Amazon report.");
        $g = fn (array $r, string $k) => trim((string) ($r[$ix[$k] ?? -1] ?? '')); $cur = preg_match('/All amounts in ([A-Z]{3})/', implode(' ', array_map(fn ($r) => (string) ($r[0] ?? ''), array_slice($rows, 0, $hi))), $m) ? $m[1] : 'GBP';
        // every amount column before "selling fees" is money from / to the buyer (sales, credits, rebates and their taxes, tax withheld by the marketplace)
        $colCode = []; for ($i = $ix['product sales']; $i < $ix['total']; $i++) { $n = $h[$i];
            $colCode[$i] = $i < $ix['selling fees'] ? 'NET_SALES' : match (true) { $n === 'selling fees' => 'MP_COMMISSION', str_contains($n, 'fba') || str_contains($n, 'fulfil') => 'MP_FULFILMENT', $n === 'other transaction fees' => 'MP_OTHER_FEE', default => 'MP_OTHER' }; }
        $typeCode = function (string $type, string $desc): ?string { $t = mb_strtolower($type); $d = mb_strtolower($desc);
            return match (true) {
                in_array($t, ['order', 'refund', 'order_retrocharge', 'refund_retrocharge', 'liquidations', 'liquidations adjustments'], true) => null,     // by column
                $t === 'adjustment' => str_contains($d, 'reimburs') ? 'MP_REIMBURSEMENT' : 'MP_ADJUSTMENT',
                str_contains($t, 'inventory fee') || str_contains($d, 'storage') || str_contains($d, 'removal') || str_contains($d, 'disposal') || str_contains($d, 'inbound') || str_contains($d, 'carrier shipment') || str_contains($d, 'return fee') => 'MP_STORAGE',
                str_contains($d, 'deal') || str_contains($d, 'coupon') || str_contains($d, 'advertis') || str_contains($d, 'sponsored') || str_contains($d, 'vine') || str_contains($t, 'advertis') => 'MP_ADS',
                str_contains($d, 'subscription') => 'MP_SUBSCRIPTION',
                str_contains($t, 'chargeback') || str_contains($t, 'guarantee') || str_contains($t, 'claim') => 'MP_CHARGEBACK',
                str_contains($t, 'fba') || str_contains($t, 'fulfil') => 'MP_STORAGE',
                $t === 'amazon fees' || $t === 'service fee' || str_contains($t, 'fee') => 'MP_OTHER_FEE',
                default => 'MP_OTHER',
            }; };
        $st = []; $lines = []; $refund = []; $unknown = []; $transfers = []; $bad = 0; $dates = []; $last = []; $sum = [];
        foreach (array_slice($rows, $hi + 1) as $r) {
            $type = $g($r, 'type'); $sid = $g($r, 'settlement id'); if ($type === '' && $sid === '') continue;
            $date = self::day($g($r, 'date/time')); $total = self::num($g($r, 'total')); if ($date) $dates[] = $date;
            if (mb_strtolower($type) === 'transfer') { $transfers[] = ['date' => $date, 'amount' => round(-$total, 2), 'bank' => preg_match('/(\d{3,4})\s*$/', $g($r, 'description'), $bm) ? '*' . $bm[1] : null, 'used' => false]; continue; }
            if ($sid === '') $sid = 'NO-SETTLEMENT';
            $st[$sid] ??= ['statement_id' => $sid, 'payout_id' => null, 'date' => $date, 'payout_date' => null, 'from' => $date, 'file_total' => null, 'currency' => $cur, 'bank' => null];
            if ($date && $date > $st[$sid]['date']) $st[$sid]['date'] = $date; if ($date && $date < $st[$sid]['from']) $st[$sid]['from'] = $date;
            $code0 = $typeCode($type, $g($r, 'description')); $order = $g($r, 'order id'); $parts = 0.0; $isRefund = mb_strtolower($type) === 'refund';
            for ($i = $ix['product sales']; $i < $ix['total']; $i++) {
                $v = self::num($r[$i] ?? ''); if ($v == 0.0) continue; $parts = round($parts + $v, 2);
                $code = $code0 ?? $colCode[$i]; if ($code === 'MP_OTHER') $unknown[$type . ' / ' . $rows[$hi][$i] . ($g($r, 'description') !== '' ? ' / ' . mb_substr($g($r, 'description'), 0, 40) : '')] = true;
                $lines[] = ['statement_id' => $sid, 'order_id' => $order, 'sku' => $g($r, 'sku'), 'qty' => (int) self::num($g($r, 'quantity')), 'txn' => $type, 'code' => $code, 'desc' => trim((string) $rows[$hi][$i] . ($code0 ? ' – ' . $g($r, 'description') : '')), 'amount' => $v, 'date' => $date];
                if ($isRefund && $code === 'NET_SALES' && $order !== '') $refund[$sid . '|' . $order] = round(($refund[$sid . '|' . $order] ?? 0) + $v, 2);
            }
            if (abs(round($parts - $total, 2)) >= 0.005) $bad++;
            $sum[$sid] = round(($sum[$sid] ?? 0) + $parts, 2);
        }
        if (! $st) throw new \InvalidArgumentException('The Amazon report has no transaction lines.');
        $refund = array_filter($refund, fn ($v) => $v < 0);
        // tie each payout to the settlement whose lines add up to exactly its amount and that ended before it
        $paid = 0;
        foreach ($st as $sid => &$s) { foreach ($transfers as &$t) { if (! $t['used'] && abs($t['amount'] - ($sum[$sid] ?? 0)) < 0.005 && $t['date'] >= $s['date']) { $t['used'] = true; $s['payout_date'] = $t['date']; $s['bank'] = $t['bank']; $paid++; break; } } unset($t); } unset($s);
        $open = array_keys(array_filter($st, fn ($s) => ! $s['payout_date'])); $loose = count(array_filter($transfers, fn ($t) => ! $t['used']));
        return ['format' => 'AMAZON_TXN', 'report_type' => 'AMAZON_TRANSACTION', 'currency' => $cur, 'period' => [min($dates), max($dates)], 'statements' => $st, 'lines' => $lines, 'errors' => $bad ? ["$bad line(s) of the report do not add up to their own total column."] : [], 'refunds' => $refund,
            'unknown' => array_keys($unknown), 'skipped' => ['rows' => count($transfers), 'amount' => round(-array_sum(array_column($transfers, 'amount')), 2)], 'mapping' => [],
            'checks' => [['ok' => $bad === 0, 'text' => 'Every line adds up to its own total, to the penny', 'detail' => count($lines) . ' amount line(s) in ' . count($st) . ' settlement(s)'],
                ['ok' => ! $open, 'text' => 'Every settlement has its payout in the file', 'detail' => "$paid of " . count($st) . ' settlement(s) are tied to a payout of exactly their amount.' . ($open ? ' Without a payout here (cut by the start or the end of the report, or not paid yet): ' . implode(', ', array_slice($open, 0, 8)) . '. Load the report of the period before / after as well, then book them.' : '') . ($loose ? " $loose payout(s) in the file belong to settlements that are not whole in this file." : '')],
                ['ok' => ! $unknown, 'text' => 'Every transaction type is known', 'detail' => $unknown ? 'Booked as "other": ' . implode('; ', array_slice(array_keys($unknown), 0, 8)) : '']]];
    }

    // ---------- eBay: transaction report ----------
    public static function readEbay(string $path): array
    {
        $rows = self::rows($path, 'csv'); $hi = null; $seller = null;
        foreach ($rows as $i => $r) { if (self::key($r[0] ?? '') === 'seller') $seller = trim((string) ($r[1] ?? '')); if (self::key($r[0] ?? '') === 'transaction creation date') { $hi = $i; break; } }
        if ($hi === null) throw new \InvalidArgumentException('This is not an eBay transaction report: the line starting with "Transaction creation date" is missing.');
        $h = array_map(fn ($x) => self::key($x), $rows[$hi]); $ix = array_flip($h);
        foreach (['type', 'net amount', 'payout id', 'gross transaction amount'] as $must) if (! isset($ix[$must])) throw new \InvalidArgumentException("The column \"$must\" is missing in the eBay report. Tick all columns when you make the report.");
        $g = function (array $r, string $k) use ($ix) { $v = trim((string) ($r[$ix[$k] ?? -1] ?? '')); return $v === '--' ? '' : $v; };
        $fees = ['final value fee - fixed' => 'MP_COMMISSION', 'final value fee - variable' => 'MP_COMMISSION', 'regulatory operating fee' => 'MP_REGULATORY', "very high 'item not as described' fee" => 'MP_PENALTY', 'below standard performance fee' => 'MP_PENALTY', 'international fee' => 'MP_INTERNATIONAL',
            'charity donation' => 'MP_OTHER_FEE', 'deposit processing fee' => 'MP_OTHER_FEE'];
        foreach ($h as $col) if ($col !== '' && str_contains($col, ' fee') && ! isset($fees[$col]) && ! in_array($col, ['regulatory operating fee'], true)) $fees[$col] = 'MP_OTHER_FEE';   // a fee column eBay adds later
        $st = []; $lines = []; $refund = []; $unknown = []; $payoutRows = []; $skip = ['rows' => 0, 'amount' => 0.0]; $bad = 0; $cur = 'GBP'; $dates = [];
        foreach (array_slice($rows, $hi + 1) as $r) {
            $type = self::key($g($r, 'type')); if ($type === '') continue;
            $net = self::num($g($r, 'net amount')); $pid = $g($r, 'payout id'); $date = self::day($g($r, 'transaction creation date')); if ($date) $dates[] = $date;
            if (in_array($type, ['payout', 'secondary payout'], true)) { if ($pid !== '') $payoutRows[$pid] = round(($payoutRows[$pid] ?? 0) + abs($net), 2); continue; }
            if ($pid === '') { $skip['rows']++; $skip['amount'] = round($skip['amount'] + $net, 2); continue; }   // not paid out yet: it comes with the payout in a later file
            $pc = strtoupper($g($r, 'payout currency')) ?: $cur; $cur = $pc; $tc = strtoupper($g($r, 'transaction currency')) ?: $pc; $rate = $tc !== $pc ? (self::fx($g($r, 'exchange rate')) ?: 1.0) : 1.0;
            $st[$pid] ??= ['statement_id' => 'EBAY-' . $pid, 'payout_id' => $pid, 'date' => self::day($g($r, 'payout date')) ?? $date, 'payout_date' => self::day($g($r, 'payout date')), 'from' => $date, 'file_total' => null, 'currency' => $pc, 'bank' => (preg_match('/\*\w+\s*$/', $g($r, 'payout method'), $bm) ? trim($bm[0]) : mb_substr($g($r, 'payout method'), -20)) ?: null];
            $order = $g($r, 'order number'); $desc = $g($r, 'description'); $d = mb_strtolower($desc);
            $base = ['statement_id' => 'EBAY-' . $pid, 'order_id' => $order, 'sku' => $g($r, 'custom label'), 'qty' => (int) self::num($g($r, 'quantity')), 'txn' => $g($r, 'type'), 'date' => $date];
            $add = function (string $code, float $amt, string $what) use (&$lines, $base) { if (round($amt, 2) != 0.0) $lines[] = $base + ['code' => $code, 'desc' => $what, 'amount' => round($amt, 2)]; };
            if (in_array($type, ['order', 'refund'], true)) {
                $gross = round(self::num($g($r, 'gross transaction amount')) * $rate, 2); $sum = $gross; $add('NET_SALES', $gross, $type === 'refund' ? 'Refund to the buyer' : 'Paid by the buyer');
                foreach ($fees as $col => $code) { $v = round(self::num($g($r, $col)) * $rate, 2); if ($v != 0.0) { $add($code, $v, ucfirst($col)); $sum = round($sum + $v, 2); } }
                $rest = round($net - $sum, 2);
                if ($rest != 0.0) { if ($tc !== $pc && abs($rest) <= 0.05) $add('MP_FX', $rest, 'Rounding of the exchange rate'); else { $add('MP_OTHER', $rest, 'Difference between the net amount and gross + fees'); $bad++; } }
                if ($type === 'refund' && $order !== '' && $gross < 0) $refund['EBAY-' . $pid . '|' . $order] = round(($refund['EBAY-' . $pid . '|' . $order] ?? 0) + $gross, 2);
                continue;
            }
            $code = match (true) {
                $type === 'other fee' => match (true) { str_contains($d, 'promoted') || str_contains($d, 'advert') || str_contains($d, 'ad fee') => 'MP_ADS', str_contains($d, 'subscription') || str_contains($d, 'shop') || str_contains($d, 'store') => 'MP_SUBSCRIPTION',
                    str_contains($d, 'insertion') || str_contains($d, 'listing') || str_contains($d, 'upgrade') || str_contains($d, 'subtitle') || str_contains($d, 'gallery') => 'MP_LISTING', str_contains($d, 'final value') => 'MP_COMMISSION', default => 'MP_OTHER_FEE' },
                in_array($type, ['postage label', 'shipping label'], true) => 'MP_SHIPPING_LABEL',
                in_array($type, ['claim', 'payment dispute'], true) => 'MP_CHARGEBACK',
                in_array($type, ['hold', 'reserve'], true) => 'MP_HOLD',
                $type === 'adjustment' => 'MP_ADJUSTMENT',
                $type === 'withheld tax' => 'MP_TAX_WITHHELD',
                default => 'MP_OTHER',
            };
            if ($code === 'MP_OTHER') $unknown[$g($r, 'type')] = true;
            $add($code, $net, $desc !== '' ? $desc : $g($r, 'type'));
        }
        if (! $st && ! $skip['rows']) throw new \InvalidArgumentException('The eBay report has no transaction lines.');
        $sum = []; foreach ($lines as $l) $sum[$l['statement_id']] = round(($sum[$l['statement_id']] ?? 0) + $l['amount'], 2);
        $cmp = 0; $diff = [];
        foreach ($payoutRows as $pid => $v) if (isset($st[$pid])) { $cmp++; if (abs(round(abs($sum['EBAY-' . $pid] ?? 0) - $v, 2)) >= 0.005) $diff[] = "$pid: lines " . number_format($sum['EBAY-' . $pid] ?? 0, 2) . ' / payout ' . number_format($v, 2); }
        return ['format' => 'EBAY', 'report_type' => 'EBAY_TRANSACTION', 'currency' => $cur, 'period' => [$dates ? min($dates) : date('Y-m-d'), $dates ? max($dates) : date('Y-m-d')], 'statements' => $st, 'lines' => $lines, 'errors' => [], 'refunds' => $refund,
            'unknown' => array_keys($unknown), 'skipped' => $skip, 'mapping' => [], 'seller' => $seller,
            'checks' => [['ok' => $bad === 0, 'text' => 'Every order and refund line: net amount = gross amount + fees', 'detail' => $bad ? "$bad line(s) differ – the difference is booked as \"other\" so the payout still agrees" : count($lines) . ' amount line(s) in ' . count($st) . ' payout(s)'],
                ['ok' => ! $diff, 'text' => 'Payout lines in the file = the lines paid with that payout', 'detail' => $diff ? 'A payout can run over two monthly files; load the other month too. ' . implode(' | ', array_slice($diff, 0, 4)) : ($cmp ? "$cmp payout(s) compared" : 'The file has no payout lines to compare with (the bank matching checks the amount later)')],
                ['ok' => ! $unknown, 'text' => 'Every transaction type is known', 'detail' => $unknown ? 'Booked as "other": ' . implode(', ', array_keys($unknown)) : ''],
                ['ok' => true, 'text' => 'Lines without a payout yet are left out', 'detail' => $skip['rows'] ? "{$skip['rows']} line(s), " . number_format($skip['amount'], 2) . ' – they come with their payout in the next file' : 'none']]];
    }

    private static function fx(string $v): float { $s = trim($v); return $s === '' ? 0.0 : (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $s)); }

    // ---------- any other table: read by column names ----------
    private const COLS = [
        'order' => ['order id', 'order number', 'order no', 'order no.', 'order #', 'order reference', 'order ref', 'po number', 'parent order id', 'onbuy order id', 'order'],
        'statement' => ['settlement id', 'payout id', 'payment id', 'remittance id', 'statement id', 'payment reference', 'remittance reference', 'remittance number', 'batch id', 'settlement batch', 'bill id', 'settlement no'],
        'date' => ['date/time', 'date', 'transaction date', 'posted date', 'order date', 'created date', 'purchase date', 'transaction time', 'settlement time', 'time'],
        'payout_date' => ['payout date', 'payment date', 'deposit date', 'paid date', 'remittance date', 'settlement date', 'payout time'],
        'type' => ['type', 'transaction type', 'fee type', 'bill type', 'record type', 'category'],
        'desc' => ['description', 'details', 'fee name', 'remark', 'remarks', 'note'],
        'sku' => ['sku', 'seller sku', 'custom label', 'contribution sku', 'merchant sku', 'sku id'],
        'qty' => ['quantity', 'qty', 'quantity purchased', 'units'],
        'currency' => ['currency', 'payout currency', 'settlement currency'],
        'net' => ['total', 'net amount', 'net', 'net payout', 'payout amount', 'amount paid', 'total settlement amount', 'net proceeds', 'payable', 'seller payout', 'settlement amount', 'net total', 'amount due', 'you receive', 'seller receives'],
        'amount' => ['amount', 'transaction amount', 'value', 'bill amount'],
    ];
    /** header contains … → code; the first match wins, so the special ones stand before the general ones */
    private const WIDE = [
        ['MP_VAT_ON_FEES', ['vat on fee', 'fee vat', 'vat on commission', 'tax on fee', 'vat on onbuy', 'commission vat']],
        ['NET_SALES', ['sales tax collected', 'marketplace withheld tax', 'marketplace facilitator', 'product sales tax', 'shipping credits tax', 'giftwrap credits tax', 'promotional rebates tax']],
        ['MP_FULFILMENT', ['fba fee', 'fulfilment fee', 'fulfillment fee', 'warehouse fee']],
        ['REFUND', ['refund']],
        ['MP_ADS', ['advertis', 'boost', 'promoted', 'sponsored', 'marketing fee', 'ppc', 'ad fee', 'ads fee']],
        ['MP_SHIPPING_LABEL', ['shipping label', 'postage label', 'label fee', 'shipping fee', 'logistics fee', 'delivery fee', 'carrier fee', 'postage cost']],
        ['MP_CHARGEBACK', ['chargeback', 'dispute', 'claim', 'penalty', 'compensation to buyer']],
        ['MP_SUBSCRIPTION', ['subscription', 'monthly fee', 'membership']],
        ['MP_HOLD', ['reserve', 'held back', 'hold amount', 'withheld amount', 'deposit']],
        ['MP_REIMBURSEMENT', ['reimburs', 'compensation']],
        ['NET_SALES', ['product sales', 'item price', 'item subtotal', 'item total', 'order total', 'order value', 'sale price', 'selling price', 'gross sales', 'sales amount', 'product price', 'retail price', 'goods price', 'goods amount', 'shipping credits', 'postage credits', 'gift wrap credits',
            'promotional rebates', 'seller discount', 'discount from seller', 'delivery charge', 'shipping charged', 'postage and packaging', 'shipping income', 'shipping paid by buyer', 'sales', 'principal']],
        ['MP_OTHER_FEE', ['other transaction fee', 'other fee']],
        ['MP_COMMISSION', ['commission', 'selling fee', 'referral', 'final value', 'marketplace fee', 'onbuy fee', 'platform fee', 'service fee', 'transaction fee', 'category fee', 'sales fee']],
        ['MP_ADJUSTMENT', ['adjustment', 'correction']],
        ['MP_OTHER', ['other']],
    ];
    /** long format: the type / description text contains … → code */
    private const LONG = [
        ['REFUND', ['refund', 'return', 'cancel']], ['MP_VAT_ON_FEES', ['vat', 'tax on']], ['MP_ADS', ['advert', 'boost', 'promot', 'sponsor', 'marketing', 'ppc']], ['MP_FULFILMENT', ['fulfil', 'fulfill', 'warehouse']], ['MP_STORAGE', ['storage']],
        ['MP_SHIPPING_LABEL', ['shipping', 'postage', 'label', 'logistic', 'delivery', 'carrier']], ['MP_CHARGEBACK', ['chargeback', 'dispute', 'claim', 'penalty']], ['MP_SUBSCRIPTION', ['subscription', 'membership']], ['MP_HOLD', ['reserve', 'hold', 'deposit']],
        ['MP_REIMBURSEMENT', ['reimburs', 'compensat']], ['MP_COMMISSION', ['commission', 'fee', 'referral']], ['MP_ADJUSTMENT', ['adjust', 'correction']], ['NET_SALES', ['sale', 'order', 'payment', 'purchase', 'settle', 'income', 'principal']],
    ];

    public static function readMapped(string $path, string $ext, array $opt = []): array
    {
        $hi = null; $rows = []; $find = fn (array $h, string $what) => current(array_filter(array_keys($h), fn ($i) => in_array($h[$i], self::COLS[$what], true))) ;
        // a workbook (Temu gives its statement as .xlsx) can hold several sheets: the first sheet with a usable header line is read
        $sets = in_array($ext, ['xlsx', 'xls'], true) ? array_map(fn ($s) => $s->toArray(null, false, false, false), IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path)->getAllSheets()) : [self::rows($path, $ext)];
        foreach ($sets as $try) { foreach (array_slice($try, 0, 40, true) as $i => $r) { $h = array_map(fn ($x) => self::key($x), $r); if (count(array_filter($h, fn ($x) => $x !== '')) >= 3 && $find($h, 'order') !== false) { $hi = $i; $rows = $try; break 2; } } }
        if ($hi === null) throw new \InvalidArgumentException('No header line with an order number column was found in this file. The first lines must hold the column names (for example "Order ID", "Commission", "Total").');
        $h = array_map(fn ($x) => self::key($x), $rows[$hi]);
        if (in_array('order item id', $h, true) && in_array('order settlement status', $h, true)) throw new \InvalidArgumentException('This is Temu\'s order list (Orders › Export), not its money file: it has no fees and no payout. Load Temu\'s settlement / funds export here. Loading order lists of marketplaces as orders is not built yet.');
        $col = []; foreach (array_keys(self::COLS) as $what) { $i = $find($h, $what); $col[$what] = $i === false ? null : $i; }
        $data = array_values(array_filter(array_slice($rows, $hi + 1), fn ($r) => count(array_filter($r, fn ($x) => trim((string) $x) !== '')) > 0));
        $numeric = function (int $i) use ($data) { $n = 0; $t = 0; foreach (array_slice($data, 0, 200) as $r) { $v = trim((string) ($r[$i] ?? '')); if ($v === '' || $v === '--') continue; $t++; if (preg_match('/^\(?[£$€]?\s*-?[£$€]?\s*[\d.,]+\s*\)?$/u', $v)) $n++; } return $t > 0 && $n / $t >= 0.9; };
        $long = $col['amount'] !== null && ($col['type'] !== null || $col['desc'] !== null);
        $wide = []; $unused = []; $used = array_filter(array_values($col), fn ($x) => $x !== null);
        if (! $long) foreach ($h as $i => $name) {
            if ($name === '' || in_array($i, $used, true) || ! $numeric($i)) continue;
            if (preg_match('/\b(id|no|number|qty|quantity|rate|%|percent|postcode|zip|phone|weight|stock)\b/', $name) || str_ends_with($name, ' id')) { $unused[] = $rows[$hi][$i]; continue; }
            $code = null; foreach (self::WIDE as [$c, $keys]) { foreach ($keys as $k) if (str_contains($name, $k)) { $code = $c; break 2; } }
            if ($code === null) { $unused[] = $rows[$hi][$i]; continue; }
            $wide[$i] = $code;
        }
        if (! $long && ! $wide) throw new \InvalidArgumentException('No amount column was recognised in this file (for example "Selling price", "Commission", "Total").');
        // costs written as plus figures: find the sign that makes the rows add up to the net column
        $flip = [];
        if (! $long) {
            $costCols = array_keys(array_filter($wide, fn ($c) => $c !== 'NET_SALES'));
            if ($col['net'] !== null) {
                $asIs = 0; $turned = 0;
                foreach (array_slice($data, 0, 300) as $r) { $net = self::num($r[$col['net']] ?? ''); $s = 0; $c = 0; foreach ($wide as $i => $code) { $v = self::num($r[$i] ?? ''); if ($code === 'NET_SALES') $s += $v; else $c += $v; }
                    if ($c == 0.0) continue; if (abs(round($s + $c - $net, 2)) < 0.006) $asIs++; elseif (abs(round($s - $c - $net, 2)) < 0.006) $turned++; }
                if ($turned > $asIs) $flip = $costCols;
            } else foreach ($costCols as $i) { $sum = 0; foreach ($data as $r) $sum += self::num($r[$i] ?? ''); if ($sum > 0 && $wide[$i] !== 'MP_REIMBURSEMENT' && $wide[$i] !== 'MP_HOLD') $flip[] = $i; }
        }
        $ref = trim((string) ($opt['reference'] ?? '')); $payDate = self::day($opt['payout_date'] ?? ''); $curDefault = strtoupper(trim((string) ($opt['currency'] ?? ''))) ?: 'GBP'; $platform = strtoupper((string) ($opt['platform'] ?? 'OTHER')); if (! isset(self::PLATFORMS[$platform])) $platform = 'OTHER';
        if ($col['statement'] === null && $ref === '') throw new \InvalidArgumentException('This file has no payout / settlement number column. Type the payout reference in the form (for example the remittance number or the payout date) so the lines can be kept together.');
        $st = []; $lines = []; $refund = []; $unknown = []; $bad = 0; $dates = []; $cur = $curDefault; $prefix = substr($platform, 0, 5) . '-';
        foreach ($data as $r) {
            $g = fn (string $k) => $col[$k] === null ? '' : trim((string) ($r[$col[$k]] ?? ''));
            $order = $g('order'); $date = self::day($g('date')); $sid = $prefix . mb_substr($col['statement'] !== null && $g('statement') !== '' ? $g('statement') : $ref, 0, 34);
            if ($sid === $prefix) continue;
            $parts = [];
            if ($long) {
                $amt = self::num($g('amount')); if ($amt == 0.0) continue; $text = mb_strtolower($g('type') . ' ' . $g('desc')); $code = null;
                foreach (self::LONG as [$c, $keys]) { foreach ($keys as $k) if (str_contains($text, $k)) { $code = $c; break 2; } }
                if ($code === null) { $code = 'MP_OTHER'; $unknown[trim($g('type') . ' ' . $g('desc'))] = true; }
                $parts[] = [$code, $amt, trim($g('type') . ($g('desc') !== '' ? ' – ' . $g('desc') : ''))];
            } else {
                $sum = 0.0;
                foreach ($wide as $i => $code) { $v = self::num($r[$i] ?? ''); if ($v == 0.0) continue; if (in_array($i, $flip, true) || ($code === 'REFUND' && $v > 0)) $v = -$v; $parts[] = [$code, $v, (string) $rows[$hi][$i]]; $sum = round($sum + $v, 2); }
                if (! $parts) continue;
                if ($col['net'] !== null) { $rest = round(self::num($r[$col['net']] ?? '') - $sum, 2); if ($rest != 0.0) { $parts[] = ['MP_OTHER', $rest, 'Difference between the net column and the other columns']; $bad++; } }
            }
            if ($date) $dates[] = $date; if ($g('currency') !== '') $cur = strtoupper(substr($g('currency'), 0, 3));
            $pd = self::day($g('payout_date')) ?? $payDate;
            $st[$sid] ??= ['statement_id' => $sid, 'payout_id' => null, 'date' => $pd ?? $date ?? date('Y-m-d'), 'payout_date' => $pd, 'from' => $date, 'file_total' => null, 'currency' => $cur, 'bank' => null];
            if (! $pd && $date && $date > $st[$sid]['date']) $st[$sid]['date'] = $date;
            foreach ($parts as [$code, $amt, $what]) {
                $isRefund = $code === 'REFUND'; if ($isRefund) { $code = 'NET_SALES'; if ($order !== '') $refund[$sid . '|' . $order] = round(($refund[$sid . '|' . $order] ?? 0) - abs($amt), 2); $amt = -abs($amt); }
                $lines[] = ['statement_id' => $sid, 'order_id' => $order, 'sku' => $g('sku'), 'qty' => (int) self::num($g('qty')), 'txn' => $isRefund ? 'Refund' : ($long ? mb_substr($g('type'), 0, 40) : 'Order'), 'code' => $code, 'desc' => $what, 'amount' => round($amt, 2), 'date' => $date];
            }
        }
        if (! $lines) throw new \InvalidArgumentException('No amount line was found under the header of this file.');
        $map = []; foreach ($col as $what => $i) if ($i !== null) $map[] = [(string) $rows[$hi][$i], ['order' => 'Order number', 'statement' => 'Payout / settlement number', 'date' => 'Date', 'payout_date' => 'Payout date', 'type' => 'Type', 'desc' => 'Description', 'sku' => 'SKU', 'qty' => 'Quantity', 'currency' => 'Currency', 'net' => 'Net amount (used as the check)', 'amount' => 'Amount'][$what]];
        foreach ($wide as $i => $code) $map[] = [(string) $rows[$hi][$i], ($code === 'NET_SALES' ? 'Sales (settles what the shop owes)' : ($code === 'REFUND' ? 'Refund' : self::BUCKETS[$code][2])) . (in_array($i, $flip, true) ? ' – read as a cost (minus)' : '')];
        return ['format' => 'MAPPED', 'report_type' => $platform . '_FILE', 'currency' => $cur, 'period' => [$dates ? min($dates) : min(array_column($st, 'date')), $dates ? max($dates) : max(array_column($st, 'date'))], 'statements' => $st, 'lines' => $lines, 'errors' => [], 'refunds' => $refund,
            'unknown' => array_keys($unknown), 'skipped' => ['rows' => 0, 'amount' => 0.0], 'mapping' => $map, 'unused' => array_values(array_filter(array_map('strval', $unused), fn ($x) => trim($x) !== '')),
            'checks' => [['ok' => $col['net'] !== null && $bad === 0, 'text' => 'Every line adds up to the file\'s net column', 'detail' => $col['net'] === null ? 'The file has no net / total column, so this could not be checked – compare the payout with the bank' : ($bad ? "$bad line(s) differ – the difference is booked as \"other\"" : count($lines) . ' amount line(s)')],
                ['ok' => ! $unknown, 'text' => 'Every type is known', 'detail' => $unknown ? 'Booked as "other": ' . implode('; ', array_slice(array_keys($unknown), 0, 8)) : ''],
                ['ok' => true, 'text' => 'Columns were recognised by their names', 'detail' => 'Check the list of columns shown with this result once: this reader was not built from a published format of this marketplace']]];
    }

    // ---------- load ----------
    public static function read(string $path, string $ext, array $opt = []): array
    {
        $fmt = self::detect($path, $ext);
        return match ($fmt) { 'AMAZON_V2' => self::readAmazon($path), 'AMAZON_TXN' => self::readAmazonTxn($path), 'EBAY' => self::readEbay($path), 'TIKTOK' => ['format' => 'TIKTOK'], default => self::readMapped($path, $ext, $opt) };
    }

    public static function import(int $ent, int $branchId, string $path, string $fileName, array $opt = []): array
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'csv';
        $x = self::read($path, $ext, $opt);
        if ($x['format'] === 'TIKTOK') return SettlementService::importTikTok($ent, $branchId, $path, $fileName) + ['format' => 'TIKTOK', 'format_name' => self::FORMATS['TIKTOK']];
        $shop = DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the shop this file belongs to.');
        if ($x['errors']) throw new \InvalidArgumentException('The file does not add up, nothing was loaded. ' . implode(' | ', array_slice($x['errors'], 0, 5)));
        return DB::transaction(function () use ($ent, $shop, $x, $path, $fileName) {
            $type = $x['report_type'];
            $batch = DB::table('fin_import_batches')->insertGetId(['report_type' => $type, 'company_id' => $ent, 'branch_id' => $shop->id, 'file_name' => mb_substr($fileName, 0, 200), 'file_hash' => sha1_file($path), 'period_from' => $x['period'][0], 'period_to' => $x['period'][1], 'imported_by' => Context::userId(), 'imported_at' => now()]);
            $have = DB::table('mo_settlements')->where('fk_id_branch', $shop->id)->whereIn('statement_id', array_column($x['statements'], 'statement_id'))->get()->keyBy('statement_id');
            foreach ($have as $sid => $old) if ($old->report_type !== $type) throw new \InvalidArgumentException("Settlement $sid of this shop is already loaded from another kind of report ({$old->report_type}). One settlement is loaded from one kind of report only, otherwise it would count twice. Nothing was loaded.");
            $new = 0; $dup = 0; $seen = []; $touched = []; $latePaid = []; $byId = array_column($x['statements'], null, 'statement_id');
            foreach ($x['lines'] as $l) {
                $sid = $l['statement_id'];
                $k = implode('|', [$sid, $l['order_id'], $l['sku'], $l['txn'], $l['code'], $l['desc'], $l['amount'], $l['date']]); $seen[$k] = ($seen[$k] ?? 0) + 1; $hash = sha1($k . '|' . $seen[$k]);
                if (DB::table('fin_settlement_lines')->where('branch_id', $shop->id)->where('settlement_id', $sid)->where('row_hash', $hash)->exists()) { $dup++; continue; }
                if (isset($have[$sid]) && $have[$sid]->status === 'PAID') { $latePaid[$sid] = ($latePaid[$sid] ?? 0) + 1; continue; }   // its payout is already matched to the bank: never changed behind the user's back
                $s = $byId[$sid];
                DB::table('fin_settlement_lines')->insert(['batch_id' => $batch, 'company_id' => $ent, 'branch_id' => $shop->id, 'settlement_id' => $sid, 'settlement_start' => $s['from'] ?? null, 'settlement_end' => $s['date'], 'deposit_date' => $s['payout_date'],
                    'transaction_type' => mb_substr($l['txn'], 0, 40), 'order_id' => mb_substr($l['order_id'], 0, 40), 'sku' => mb_substr($l['sku'], 0, 80), 'quantity' => $l['qty'], 'amount_type' => $l['code'], 'amount_description' => mb_substr($l['desc'], 0, 120), 'amount' => $l['amount'],
                    'currency' => $s['currency'], 'posted_date' => $l['date'], 'bucket' => $l['code'] === 'NET_SALES' ? ($l['amount'] < 0 ? 'REFUND' : 'SALE') : self::BUCKETS[$l['code']][1], 'row_hash' => $hash]);
                $new++; $touched[$sid] = true;
            }
            $newSt = 0; $oldSt = 0; $reopened = 0;
            foreach ($x['statements'] as $s) {
                $sid = $s['statement_id']; $old = $have[$sid] ?? null;
                if ($old) $oldSt++;
                if ($old && ! isset($touched[$sid])) continue;
                if (! $old && ! isset($touched[$sid])) continue;
                if ($old && $old->status === 'POSTED') { SettlementService::unpost($ent, (int) $old->id); $reopened++; }   // more lines of this payout arrived (a payout can run over two monthly files): booked again with all its lines
                $sum = DB::table('fin_settlement_lines')->where('branch_id', $shop->id)->where('settlement_id', $sid)->selectRaw("COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN amount_type='NET_SALES' THEN amount ELSE 0 END),0) n, COALESCE(SUM(CASE WHEN bucket='SHIPPING' THEN amount ELSE 0 END),0) sh, COUNT(*) c")->first();
                $data = ['fk_id_ent' => $ent, 'fk_id_branch' => $shop->id, 'fk_id_batch' => $batch, 'report_type' => $type, 'statement_id' => $sid, 'payout_id' => $s['payout_id'], 'statement_date' => $s['date'], 'payout_date' => $s['payout_date'], 'currency' => $s['currency'], 'file_status' => null,
                    'total' => $sum->t, 'net_sales' => $sum->n, 'shipping' => $sum->sh, 'fees' => round($sum->t - $sum->n - $sum->sh, 2), 'adjustments' => 0, 'reserve' => 0, 'payable' => $sum->t, 'rows_total' => $sum->t, 'rows_count' => $sum->c, 'f_rows_complete' => 1, 'bank_mask' => $s['bank'], 'status' => 'LOADED', 'updated_at' => now()];
                if ($old) DB::table('mo_settlements')->where('id', $old->id)->update($data); else { DB::table('mo_settlements')->insert($data + ['created_by' => Context::userId(), 'created_at' => now()]); $newSt++; }
            }
            // refunds in the file → proposals a person decides (never an automatic refund)
            $proposals = 0; $noSale = 0;
            foreach ($x['refunds'] as $key => $amt) {
                [$sid, $order] = explode('|', $key, 2); if (isset($latePaid[$sid])) continue;
                $sale = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $order)->where('sale_source', 'active')->orderBy('id')->first();
                if (! $sale) { $noSale++; continue; }
                $rid = mb_substr($sid . ':' . $order, 0, 80);
                if (DB::table('t_sale_refund_proposals')->where('fk_id_sale', $sale->id)->where('refund_id', $rid)->exists()) continue;
                $orderTotal = (float) DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $order)->where('sale_source', 'active')->sum('total_amount_gbp');
                DB::table('t_sale_refund_proposals')->insert(RecordService::fillNotNull('t_sale_refund_proposals', ['fk_id_sale' => $sale->id, 'fk_id_batch' => $batch, 'refund_id' => $rid, 'refund_kind' => abs($amt) >= $orderTotal - 0.005 ? 'FULL' : 'PARTIAL', 'amount' => abs($amt), 'status' => 'PROPOSED', 'created_at' => now()]));
                $proposals++;
            }
            DB::table('fin_import_batches')->where('id', $batch)->update(['rows_total' => $new + $dup, 'rows_new' => $new, 'rows_duplicate' => $dup, 'rows_error' => array_sum($latePaid)]);
            $checks = $x['checks'];
            if ($latePaid) $checks[] = ['ok' => false, 'text' => 'No new line for a payout that is already matched to the bank', 'detail' => array_sum($latePaid) . ' line(s) of payout(s) ' . implode(', ', array_keys($latePaid)) . ' were NOT loaded. Undo the bank match of that payout, then load the file again.'];
            $ok = ! array_filter($checks, fn ($c) => ! $c['ok']); $fileTotal = round(array_sum(array_column($x['lines'], 'amount')), 2);
            $tot = DB::table('mo_settlements')->where('fk_id_branch', $shop->id)->whereIn('statement_id', array_column($x['statements'], 'statement_id'))->selectRaw('COALESCE(SUM(total),0) t, COALESCE(SUM(net_sales),0) n, COALESCE(SUM(shipping),0) sh, COALESCE(SUM(fees),0) f')->first();
            DB::table('mo_settlement_periods')->updateOrInsert(['fk_id_branch' => $shop->id, 'report_type' => $type, 'period_from' => $x['period'][0], 'period_to' => $x['period'][1]],
                ['fk_id_ent' => $ent, 'fk_id_batch' => $batch, 'currency' => $x['currency'], 'total' => $tot->t, 'net_sales' => $tot->n, 'shipping' => $tot->sh, 'fees' => $tot->f, 'adjustments' => 0, 'totals_json' => json_encode(['mapping' => $x['mapping'], 'unknown' => $x['unknown']]), 'checks_json' => json_encode($checks),
                    'hub_check' => $ok ? 'OK' : 'DIFF', 'updated_at' => now(), 'created_at' => now()]);
            TaskEngine::close('FIN_SETTLEMENT_OVERDUE', [$shop->id], 'Money file loaded');
            Audit::log('fin_import_batches', $batch, 'INSERT', ['type' => $type, 'shop' => $shop->shop_name, 'statements' => $newSt, 'lines' => $new], null, $ent);
            return ['batch' => $batch, 'format' => $x['format'], 'format_name' => self::FORMATS[$x['format']], 'statements_new' => $newSt, 'statements_already' => $oldSt, 'statements_reopened' => $reopened, 'lines_new' => $new, 'lines_already' => $dup, 'refund_proposals' => $proposals,
                'full_refunds' => count($x['refunds']), 'full_refunds_without_order' => $noSale, 'hub_check' => $ok ? 'OK' : 'DIFF', 'period' => $x['period'], 'report_total' => $fileTotal, 'statements_in_period' => $fileTotal, 'checks' => $checks,
                'new_columns' => $x['unknown'], 'mapping' => $x['mapping'], 'unused' => $x['unused'] ?? [], 'skipped' => $x['skipped'], 'all_ok' => $ok];
        });
    }
}
