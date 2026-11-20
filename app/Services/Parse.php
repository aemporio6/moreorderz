<?php

namespace App\Services;

/** Shared parsers of every importer: money, dates, and a robust CSV reader (RULES_A_SALES R-A_SALES-5, 7, 8). */
class Parse
{
    /** '' → null · not a number → false · "£1.234,56" → 1234.56 · "(12.50)" → −12.5 · "1,234" → 1234 · "12,5" → 12.5 */
    public static function money(mixed $v): float|null|false
    {
        $s = trim((string) $v); if ($s === '') return null;
        $neg = str_contains($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
        $n = preg_replace('/[^0-9.,]/', '', $s); if ($n === '' || ! preg_match('/\d/', $n)) return false;
        $c = strrpos($n, ','); $d = strrpos($n, '.');
        if ($c !== false && $d !== false) { $dec = max($c, $d); $n = preg_replace('/[.,]/', '', substr($n, 0, $dec)) . '.' . preg_replace('/[.,]/', '', substr($n, $dec + 1)); }
        elseif ($c !== false) $n = preg_match('/,\d{1,2}$/', $n) ? str_replace(',', '', substr($n, 0, $c)) . '.' . substr($n, $c + 1) : str_replace(',', '', $n);
        elseif (substr_count($n, '.') > 1) return false;
        if (! is_numeric($n)) return false;
        return round(($neg ? -1 : 1) * (float) $n, 2);
    }

    private const FORMATS = ['Y-m-d', 'Y/m/d', 'j-M-y', 'j-M-Y', 'M-j-y', 'M-j-Y', 'j M Y', 'j M y', 'M j Y', 'M j, Y', 'j F Y', 'F j, Y', 'F j Y', 'D, j M Y', 'D j M Y'];

    /** A date as Y-m-d · '' when the cell is empty ("null", "0", "-") · false when the format is not known. UK day-first for a/b/y. */
    public static function date(mixed $v): string|false
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v)); if ($s === '' || in_array(strtolower($s), ['null', '0', '-'], true)) return '';
        if (preg_match('/^\d{5}(\.\d+)?$/', $s) && (float) $s >= 30000 && (float) $s <= 70000) return gmdate('Y-m-d', (int) round(((float) $s - 25569) * 86400));     // Excel serial
        $s = preg_replace('/^(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)[a-z]*[,\s]+/i', '', $s);                 // weekday prefix
        $s = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $s);                                                 // ordinals
        $s = preg_replace('/[T\s]+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*(am|pm)?\s*([A-Za-z]{1,5}|[+\-]\d{2}:?\d{2}|Z)?\s*$/i', '', $s);   // trailing time / zone
        $s = trim(preg_replace('/\bsept\b/i', 'Sep', $s), " ,");
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})$#', $s, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]]; if ($y < 100) $y += 2000;
            [$day, $mon] = $b > 12 ? [$b, $a] : [$a, $b];
            return checkdate($mon, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $mon, $day) : false;
        }
        $t = str_replace('.', '-', $s);
        foreach (self::FORMATS as $f) { $d = \DateTime::createFromFormat('!' . $f, $t); $e = \DateTime::getLastErrors(); if ($d && (! $e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) return $d->format('Y-m-d'); }
        if (preg_match('/\b(20\d{2})\b/', $s, $m) && ($ts = strtotime($s)) !== false && date('Y', $ts) === $m[1]) return date('Y-m-d', $ts);
        return false;
    }

    public static function header(mixed $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', trim($h, " \t\n\r\0\x0B\"'"))));
    }

    private static function utf8(string $v): string { return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252'); }

    /**
     * Read a CSV: delimiter counted on the first non-empty line (comma wins a tie), the header row = the first of the first 15 rows that
     * holds ANY of $known headers, cells converted from Windows-1252 when they are not UTF-8.
     * Returns ['delimiter', 'delimiter_name', 'header_line', 'encoding', 'headers' => [...], 'rows' => [[line no, {header: value}], …]] or throws with the rule's message.
     * $force = ['delimiter' => ';', 'header_line' => 4] overrides the detection.
     */
    public static function csv(string $path, array $known, string $what = 'Sale Columns', array $force = []): array
    {
        $raw = @file_get_contents($path); if ($raw === false) throw new \InvalidArgumentException('The uploaded file could not be opened.');
        $cp = ! mb_check_encoding($raw, 'UTF-8'); $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = preg_split('/\r\n|\n|\r/', $raw); $first = ''; foreach ($lines as $l) if (trim($l) !== '') { $first = $l; break; }
        $names = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab']; $del = $force['delimiter'] ?? null;
        if (! isset($names[(string) $del])) { $best = 0; $del = ','; foreach ([',', ';', "\t"] as $d) { $n = substr_count($first, $d); if ($n > $best) { $best = $n; $del = $d; } } }
        $fh = fopen('php://temp', 'w+'); fwrite($fh, $raw); rewind($fh); $all = []; $no = 0;
        while (($r = fgetcsv($fh, 0, $del, '"', '\\')) !== false) { $no++; $all[] = [$no, array_map(fn ($c) => self::utf8((string) $c), $r)]; }
        fclose($fh);
        $known = array_flip(array_map([self::class, 'header'], $known)); $hi = null;
        if (! empty($force['header_line'])) { foreach ($all as $i => [$ln]) if ($ln === (int) $force['header_line']) $hi = $i; }
        else foreach (array_slice($all, 0, 15, true) as $i => [, $cells]) { foreach ($cells as $c) if (isset($known[self::header($c)])) { $hi = $i; break 2; } }
        if ($hi === null) throw new \InvalidArgumentException("No header row with the $what names was found in the first 15 lines. Check the file is the right marketplace export, or update $what.");
        $headers = []; foreach ($all[$hi][1] as $i => $h) { $h = self::header($h); if ($h === '') $h = 'col_' . ($i + 1); elseif (in_array($h, $headers, true)) $h .= '_' . ($i + 1); $headers[] = $h; }
        $rows = [];
        foreach (array_slice($all, $hi + 1) as [$ln, $cells]) { $row = []; foreach ($headers as $i => $h) $row[$h] = trim($cells[$i] ?? ''); $rows[] = [$ln, $row]; }
        return ['delimiter' => $del, 'delimiter_name' => $names[$del], 'header_line' => $all[$hi][0], 'encoding' => $cp ? 'Windows-1252' : 'UTF-8', 'headers' => $headers, 'rows' => $rows,
            'detected' => 'Detected: ' . $names[$del] . ', header on line ' . $all[$hi][0] . ', ' . ($cp ? 'Windows-1252' : 'UTF-8')];
    }

    /** "Buyer name\nbuyer name, Post to name" → "Buyer name, Post to name" (newlines are commas, trimmed, empties dropped, no repeats whatever the case). */
    public static function headerList(mixed $v): string
    {
        $seen = []; $out = [];
        foreach (preg_split('/[\r\n,]+/', (string) $v) as $p) { $p = trim($p); $k = mb_strtolower($p); if ($p === '' || isset($seen[$k])) continue; $seen[$k] = 1; $out[] = $p; }
        return implode(', ', $out);
    }
}
