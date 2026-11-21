<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * R-FIN-24 Upload → layout → check → apply, for bank files (the same reading is used when the file is loaded, so the check and the load agree).
 * - CSV / TXT / TSV (Excel is turned into CSV first – improved), at most 25 MB; BOM removed, blank lines skipped;
 *   delimiter from the header line: tab when it has more tabs than commas, else ";" when more semicolons than commas, else ",".
 * - Numbers: £ $ € spaces and commas removed, "(x)" = −x, empty / "-" / not a number = 0.
 * - Dates: a trailing " UTC" removed; the layout's own format first, then Y-m-d, d/m/Y, d.m.Y, d-m-Y, Y/m/d, m/d/Y, d M Y, d-M-Y, j M Y, d/m/y –
 *   the first giving a year after 2000 wins (03/04/2026 = 3 April); last fallback strtotime; an Excel day number is read too.
 * - Dry run: new / duplicate / error rows (first 50 errors), a sample of 8 rows and "This exact file was imported on <date> by <user>"
 *   (SHA of the rows). The file waits under a token for the uploader only, deleted after a load and after 24 h
 *   ("Upload the file again (the preview expired)."). Improved: the same file is refused unless the Owner loads it again on purpose.
 */
class BankImportCheck
{
    public const MAX_MB = 25;
    public const FORMATS = ['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'Y/m/d', 'm/d/Y', 'd M Y', 'd-M-Y', 'j M Y', 'd/m/y'];
    public const EXPIRED = 'Upload the file again (the preview expired).';
    private const DIR = 'import_tmp';

    public static function checkFile(string $name, int $bytes): void
    {
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv', 'xlsx', 'xls'], true)) throw new \InvalidArgumentException('Upload the report as CSV / TXT (Excel: File > Save as > CSV UTF-8).');
        if ($bytes > self::MAX_MB * 1024 * 1024) throw new \InvalidArgumentException('File larger than 25 MB.');
    }

    public static function delimiter(string $headerLine): string
    {
        $c = substr_count($headerLine, ','); $t = substr_count($headerLine, "\t"); $s = substr_count($headerLine, ';');
        return $t > $c ? "\t" : ($s > $c ? ';' : ',');
    }

    /** Rows of a file: BOM removed, delimiter from the first line, blank lines skipped. */
    public static function rows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $d = self::delimiter((string) ($lines[0] ?? ''));
        return array_values(array_filter(array_map(fn ($l) => str_getcsv($l, $d), $lines), fn ($r) => $r && trim(implode('', $r)) !== ''));
    }

    public static function num(mixed $v): float
    {
        $s = trim(str_replace(['£', '$', '€', ' ', "\u{00A0}", ','], '', (string) $v));
        if ($s === '' || $s === '-') return 0.0;
        $neg = (bool) preg_match('/^\((.*)\)$/', $s, $m); if ($neg) $s = $m[1];
        if (! is_numeric($s)) return 0.0;
        return $neg ? -abs((float) $s) : (float) $s;
    }

    /** Y-m-d or null. */
    public static function date(mixed $v, ?string $preferred = null): ?string
    {
        $raw = trim(preg_replace('/\s+UTC$/i', '', trim((string) $v)));
        if ($raw === '') return null;
        if (is_numeric($raw) && (float) $raw > 30000 && (float) $raw < 80000) return date('Y-m-d', (int) round(((float) $raw - 25569) * 86400));      // an Excel day number
        foreach (array_unique(array_filter([$preferred, ...self::FORMATS])) as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $raw); $e = \DateTime::getLastErrors();
            if ($d && (! $e || (! $e['warning_count'] && ! $e['error_count'])) && (int) $d->format('Y') > 2000) return $d->format('Y-m-d');
        }
        $t = strtotime($raw);
        return $t && (int) date('Y', $t) > 2000 ? date('Y-m-d', $t) : null;
    }

    /** SHA of the data rows (header and spacing do not change it). */
    public static function hash(array $rows): string { return sha1(implode("\n", array_map(fn ($r) => implode("\x1f", array_map('trim', $r)), $rows))); }

    /** The earlier load of exactly this file into this account, or null. */
    public static function sameFile(int $bankId, string $hash): ?array
    {
        $b = DB::table('fin_import_batches as b')->leftJoin('t_users as u', 'u.id', '=', 'b.imported_by')->where('b.bank_account_id', $bankId)->where('b.file_hash', $hash)->orderBy('b.id')->first(['b.imported_at', 'u.display_name']);
        return $b ? ['at' => substr((string) $b->imported_at, 0, 16), 'by' => $b->display_name ?: 'someone', 'text' => 'This exact file was imported on ' . substr((string) $b->imported_at, 0, 16) . ' by ' . ($b->display_name ?: 'someone') . '.'] : null;
    }

    /**
     * Read every data row of a bank file with a layout: [items, head, hash]; item = [line, status NEW / DUPLICATE / ERROR, message, row data].
     * Nothing is written. Throws for a layout that does not fit the file.
     */
    public static function classify(int $ent, object $bank, object $p, string $csv): array
    {
        $rows = self::rows($csv); $hi = max(1, (int) $p->header_row) - 1;
        if (! isset($rows[$hi])) throw new \InvalidArgumentException('The file is empty.');
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[$hi]);
        $two = $p->amount_rule !== 'ONE_SIGNED'; $missing = [];
        $col = function (?string $name, bool $must = false) use ($head, &$missing) {
            if ($name === null || $name === '') return null;
            $i = array_search(strtolower(trim($name)), $head, true);
            if ($i === false && $must) $missing[] = $name;
            return $i === false ? null : $i;
        };
        $cDate = $col($p->date_column, true); $cDesc = $col($p->description_column, true); $cAmt = $two ? null : $col($p->amount_column, true);
        $cOut = $two ? $col($p->debit_column, true) : null; $cIn = $two ? $col($p->credit_column, true) : null;
        $cRef = $col($p->reference_column); $cParty = $col($p->counterparty_column); $cId = $col($p->txn_id_column); $cCur = $col($p->currency_column);
        if ($missing) throw new \InvalidArgumentException('The saved layout does not fit this file. Missing: ' . implode(', ', $missing) . '. Columns found: ' . implode(', ', $head) . '. Choose the right layout for this bank.');
        $cur = $bank->currency ?: Fx::base($ent); $seen = []; $items = []; $data = array_slice($rows, $hi + 1);
        $have = DB::table('fin_bank_transactions')->where('bank_account_id', $bank->id)->pluck('bank_txn_id')->flip();
        foreach ($data as $n => $r) {
            $line = $hi + 2 + $n; $raw = (string) ($r[$cDate] ?? '');
            $date = self::date($raw, $p->date_format);
            if (! $date) { $items[] = [$line, 'ERROR', "Row $line: date \"$raw\" could not be read", null]; continue; }
            $amt = round($two ? self::num($r[$cIn] ?? 0) - abs(self::num($r[$cOut] ?? 0)) : self::num($r[$cAmt] ?? 0), 2);
            if ($amt == 0.0) { $items[] = [$line, 'ERROR', "Row $line: no amount", null]; continue; }
            if ($cCur !== null && ($rc = strtoupper(trim((string) ($r[$cCur] ?? '')))) && $rc !== strtoupper($cur)) { $items[] = [$line, 'ERROR', "Row $line: currency $rc but the account is $cur", null]; continue; }
            $desc = trim((string) ($r[$cDesc] ?? ''));
            $key = $cId !== null && trim((string) ($r[$cId] ?? '')) !== '' ? trim((string) $r[$cId]) : null;
            if ($key === null) { $k = "$date|$amt|$desc"; $seen[$k] = ($seen[$k] ?? 0) + 1; $key = 'H' . substr(sha1($k . '|' . $seen[$k]), 0, 30); }
            $key = mb_substr($key, 0, 80);
            $row = ['txn_date' => $date, 'amount' => $amt, 'currency' => $cur, 'description' => mb_substr($desc, 0, 250), 'bank_txn_id' => $key,
                'counterparty' => $cParty !== null ? mb_substr(trim((string) ($r[$cParty] ?? '')), 0, 120) : null, 'reference_no' => $cRef !== null ? mb_substr(trim((string) ($r[$cRef] ?? '')), 0, 60) : null];
            if (isset($have[$key])) { $items[] = [$line, 'DUPLICATE', "Row $line: already loaded", $row]; continue; }
            $have[$key] = true; $items[] = [$line, 'NEW', '', $row];
        }
        return [$items, $head, self::hash($data)];
    }

    /** The dry run: counts, first 50 errors, 8 sample rows, the same-file note and a token to load it. */
    public static function dryRun(int $ent, int $bankId, int $profileId, string $csv, string $fileName, int $userId): array
    {
        self::checkFile($fileName, strlen($csv));
        $bank = DB::table('fin_bank_accounts')->where('id', $bankId)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Bank account not found.');
        $p = DB::table('bank_statement_profiles')->where('id', $profileId)->whereIn('fk_id_ent', [0, $ent])->first() ?? throw new \InvalidArgumentException('Choose the layout of this bank file.');
        [$items, $head, $hash] = self::classify($ent, $bank, $p, $csv);
        $by = fn ($s) => array_values(array_filter($items, fn ($i) => $i[1] === $s));
        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put(self::DIR . "/$token.json", json_encode(['user' => $userId, 'ent' => $ent, 'bank' => $bankId, 'profile' => $profileId, 'file_name' => $fileName, 'csv' => $csv, 'at' => time()]));
        return ['token' => $token, 'file_name' => $fileName, 'columns' => $head, 'new' => count($by('NEW')), 'duplicates' => count($by('DUPLICATE')), 'errors' => array_map(fn ($i) => $i[2], array_slice($by('ERROR'), 0, 50)),
            'error_count' => count($by('ERROR')), 'sample' => array_map(fn ($i) => ['line' => $i[0], 'status' => $i[1]] + $i[3], array_slice(array_values(array_filter($items, fn ($i) => $i[3] !== null)), 0, 8)),
            'same_file' => self::sameFile($bankId, $hash), 'may_override' => Context::role() === 'OWNER'];
    }

    /** Load a checked file by its token (the uploader only). The same file again only by the Owner, on purpose. */
    public static function apply(int $ent, string $token, int $userId, bool $again = false): array
    {
        $path = self::DIR . '/' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
        $m = Storage::disk('local')->exists($path) ? json_decode((string) Storage::disk('local')->get($path), true) : null;
        if (! $m || (int) $m['user'] !== $userId || (int) $m['ent'] !== $ent || time() - (int) $m['at'] > 86400) throw new \InvalidArgumentException(self::EXPIRED);
        $same = self::sameFile((int) $m['bank'], self::hash(array_slice(self::rows($m['csv']), max(1, (int) DB::table('bank_statement_profiles')->where('id', $m['profile'])->value('header_row')))));
        if ($same && ! ($again && Context::role() === 'OWNER')) throw new \InvalidArgumentException($same['text'] . ' Only the Owner can load it again, on purpose.');
        $r = MoneyService::importStatement($ent, (int) $m['bank'], (int) $m['profile'], $m['csv'], $m['file_name']);
        Storage::disk('local')->delete($path);
        return $r;
    }

    /** Temp files older than 24 h go (nightly clean-up). */
    public static function sweep(): int
    {
        $n = 0;
        foreach (Storage::disk('local')->files(self::DIR) as $f) if (Storage::disk('local')->lastModified($f) < time() - 86400) { Storage::disk('local')->delete($f); $n++; }
        return $n;
    }
}
