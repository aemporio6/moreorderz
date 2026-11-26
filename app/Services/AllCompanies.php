<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-102 "All companies (added up)" for the Owner (and roles with right company.all).
 * A screen that works out its figures for one company is run once per company (Context set to that company)
 * and the answers are added up: rows of every company with the company as the first column, a total per company
 * and the grand total. Money is shown in the home company's base currency; a company with another base currency
 * is converted at the rate of the date (latest on or before it) – a missing rate shows "rate missing", never 1.0.
 * Writes in this mode first ask which company (header X-Company, checked in SetContext).
 */
class AllCompanies
{
    /** Never added: ids, paging, dates and ratios (a sum of percentages means nothing). */
    private const KEEP = '/(^|_)(id|ids|page|per_page|ent|year|month|days|day|pct|percent|rate|rates|avg|ratio|score|limit|months|step|level|version|sort|dir)$/i';
    private const MONEY = '/(value|amount|total|sale|sales|buy|profit|fee|fees|cost|cash|balance|net|gbp|base|paid|due|price|payable|payables|advance|advances|share|loss|spend|revenue|owed)/i';
    /** Personal writes that do not belong to a company: allowed without choosing one. */
    private const FREE = ['api/company', 'logout', 'api/me/*', 'api/nav', 'api/bell/*', 'api/find/opened', 'api/prefs/*', 'api/permissions/view-as', 'api/views', 'api/views/*', 'api/products/view', 'api/sales/view-columns', 'api/tasks/board/view'];

    public static function may(object $u): bool { return Perm::has($u, 'company.all'); }

    /** The companies the person may see (active ones). */
    public static function companies(object $u)
    {
        $q = DB::table('t_enterprise')->where('f_active', 1)->when(\Illuminate\Support\Facades\Schema::hasColumn('t_enterprise', 'f_sandbox'), fn ($w) => $w->where('f_sandbox', 0))->orderBy('id');      // MR-1541 the practice company is never added up
        if (! Perm::has($u, 'app.company_owner')) $q->where('id', (int) $u->fk_id_ent);
        return $q->get(['id', 'ent_name as name', 'ent_short as short'])->map(function ($c) { $c->base = Fx::base((int) $c->id); return $c; });
    }

    public static function freeWrite(\Illuminate\Http\Request $r): bool { foreach (self::FREE as $p) if ($r->is($p)) return true; return false; }

    /** Factor that turns money of $ent into the home base currency on $date; null = rate missing. */
    public static function factor(int $ent, int $home, string $date): ?float
    {
        $from = Fx::base($ent); $to = Fx::base($home);
        if ($from === $to) return 1.0;
        $r = fn (string $c) => $c === 'GBP' ? 1.0 : (float) DB::table('fin_fx_rates')->where('currency', $c)->where('rate_date', '<=', $date)->orderByDesc('rate_date')->value('rate_to_gbp');
        $a = $r($from); $b = $r($to);
        return $a > 0 && $b > 0 ? $a / $b : null;
    }

    /** Run $fn once per company with Context set to it; returns [[company, answer], …]. */
    public static function each(object $u, callable $fn): array
    {
        $home = (int) Context::ent(); $ids = Context::ents(); $out = [];
        Context::setAll(false);
        try {
            foreach (self::companies($u)->whereIn('id', $ids) as $c) { Context::set((int) $c->id, (int) $u->id); $out[] = [$c, $fn((int) $c->id)]; }
        } finally { Context::set($home, (int) $u->id); Context::setAll(true, $ids); }
        return $out;
    }

    /** Add up the answers of every company. */
    public static function merge(array $parts, ?string $date = null): array
    {
        $home = (int) Context::ent(); $date = $date ?: date('Y-m-d'); $sum = null; $per = []; $missing = [];
        foreach ($parts as [$c, $d]) {
            $d = (array) json_decode(json_encode($d), true);
            $f = self::factor((int) $c->id, $home, $date);
            if ($f === null) $missing[] = $c->name;
            if ($f !== 1.0) $d = self::money($d, $f);
            $d = self::tag($d, $c);
            $per[] = ['company_id' => (int) $c->id, 'company' => $c->name, 'base' => $c->base, 'rate_missing' => $f === null, 'figures' => self::headline($d)];
            $sum = $sum === null ? $d : self::add($sum, $d, '');
        }
        $sum = $sum ?? [];
        if (isset($sum['columns']) && is_array($sum['columns']) && array_is_list($sum['columns'])) array_unshift($sum['columns'], ['name' => 'company', 'key' => 'company', 'label' => 'Company', 'type' => 'text', 'group' => 'text', 'computed' => true]);
        $sum['all_companies'] = ['companies' => $per, 'base' => Fx::base($home), 'rate_missing' => $missing,
            'note' => $missing ? 'Rate missing for ' . implode(', ', $missing) . ' – its money is not in the total. Add the rate under Money › FX rates.' : null];
        return $sum;
    }

    /** The headline figures of one company: its totals block, else its top-level numbers. */
    private static function headline(array $d): array
    {
        $t = [];
        foreach (['totals', 'strip', 'kpi'] as $k) if (isset($d[$k]) && is_array($d[$k])) foreach ($d[$k] as $n => $v) if (is_int($v) || is_float($v) || $v === null) $t[$n] = $v;
        foreach ($d as $n => $v) if ((is_int($v) || is_float($v)) && ! preg_match(self::KEEP, (string) $n)) $t[$n] ??= $v;
        if (isset($d['rows']) && is_array($d['rows'])) $t['rows'] ??= count($d['rows']);
        return $t;
    }

    private static function money(array $d, ?float $f, string $key = ''): array
    {
        if (isset($d['series'], $d['unit']) && is_array($d['series']) && preg_match('/^[A-Z]{3}$/', (string) $d['unit'])) {      // a money chart: every bar and the line
            $conv = fn ($vals) => array_map(fn ($v) => $v === null || $f === null ? null : round($v * $f, 2), (array) $vals);
            foreach ($d['series'] as $i => $sr) $d['series'][$i]['values'] = $conv($sr['values'] ?? []);
            if (! empty($d['line']['values'])) $d['line']['values'] = $conv($d['line']['values']);
            if (isset($d['limit']) && is_numeric($d['limit'])) $d['limit'] = $f === null ? null : round($d['limit'] * $f, 2);
            return $d;
        }
        foreach ($d as $k => $v) {
            $name = is_string($k) ? $k : $key;
            if (is_array($v)) $d[$k] = self::money($v, $f, $name);
            elseif ((is_int($v) || is_float($v)) && preg_match(self::MONEY, $name) && ! preg_match(self::KEEP, $name)) $d[$k] = $f === null ? null : round($v * $f, 2);
        }
        return $d;
    }

    /** Rows (items with an id) get the company as their first field. */
    private static function tag(array $d, object $c): array
    {
        foreach ($d as $k => $v) {
            if (! is_array($v)) continue;
            if (array_is_list($v) && $v && is_array($v[0]) && ! array_is_list($v[0]) && (array_key_exists('id', $v[0]) || $k === 'rows')) $d[$k] = array_map(fn ($r) => ['company' => $c->name, 'company_id' => (int) $c->id] + (is_array($r) ? self::tag($r, $c) : []), $v);
            else $d[$k] = self::tag($v, $c);
        }
        return $d;
    }

    private static function idKey(array $item): ?string
    {
        if (array_key_exists('id', $item) || array_key_exists('company_id', $item)) return null;
        if (isset($item['key']) && is_string($item['key'])) return 'key';
        foreach (['stage', 'code', 'kind', 'label', 'name', 'status', 'category', 'month', 'bucket', 'type'] as $k) if (isset($item[$k]) && is_scalar($item[$k])) return $k;
        return null;
    }

    private static function add($a, $b, string $key)
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            if (preg_match('/^(pages|last_page|max_page)$/', $key)) return max($a, $b);      // paging: page n of every company
            if (preg_match(self::KEEP, $key)) return $a == $b ? $a : null;
            return is_float($a) || is_float($b) ? round($a + $b, 2) : $a + $b;
        }
        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) && array_is_list($b)) {
                if (! $a) return $b; if (! $b) return $a;
                if (! is_array($a[0]) || ! is_array($b[0])) return $a == $b ? $a : array_values(array_unique(array_merge($a, $b), SORT_REGULAR));      // labels: the same months for every company
                if (isset($a[0]['values']) && isset($a[0]['name'])) {      // chart series: added by name, value by value
                    $by = []; foreach ($a as $s) $by[(string) $s['name']] = $s;
                    foreach ($b as $s) { $n = (string) $s['name']; if (! isset($by[$n])) { $by[$n] = $s; continue; }
                        foreach ($s['values'] as $i => $v) $by[$n]['values'][$i] = ($by[$n]['values'][$i] ?? null) === null || $v === null ? (($by[$n]['values'][$i] ?? null) ?? $v) : round($by[$n]['values'][$i] + $v, 2); }
                    return array_values($by);
                }
                $k = self::idKey($a[0]);
                if ($k !== null && self::idKey($b[0]) === $k) {      // tiles / buckets / charts: one entry per key, added
                    $by = []; foreach ($a as $x) $by[(string) ($x[$k] ?? '')] = $x;
                    foreach ($b as $x) { $n = (string) ($x[$k] ?? ''); $by[$n] = isset($by[$n]) ? self::add($by[$n], $x, $k) : $x; }
                    return array_values($by);
                }
                return array_merge($a, $b);      // rows: every company's rows, company first
            }
            if (isset($a['values'], $b['values']) && is_array($a['values']) && array_is_list($a['values'])) {      // a chart line (profit of locked months): empty when one company has none
                foreach ($b['values'] as $i => $v) $a['values'][$i] = ($a['values'][$i] ?? null) === null || $v === null ? null : round($a['values'][$i] + $v, 2);
                return $a;
            }
            foreach ($b as $k => $v) $a[$k] = array_key_exists($k, $a) ? self::add($a[$k], $v, (string) $k) : $v;
            return $a;
        }
        if (is_bool($a) && is_bool($b)) return $a || $b;
        return $a;
    }
}
