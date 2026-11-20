<?php

namespace App\Services;

/**
 * Filter builder of every list. A filter is a tree:
 *   { "op": "AND" | "OR", "rules": [ { "f": column, "o": operator, "v": value(s) }  or another group ] }
 * Only columns of the form the person may see can be used; operators depend on the field type; values are always bound.
 */
class ListFilter
{
    public const TEXT = ['text', 'textarea', 'email', 'phone', 'link', 'file'];
    public const NUM = ['number', 'money'];
    /** type group → operators [code => label]. */
    public const OPS = [
        'text' => ['contains' => 'contains', 'starts' => 'starts with', 'is' => 'is exactly', 'not_contains' => 'does not contain', 'in' => 'is any of', 'empty' => 'is empty', 'filled' => 'is filled'],
        'number' => ['eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'between' => 'between', 'empty' => 'is empty', 'filled' => 'is filled'],
        'date' => ['preset' => 'is in', 'on' => 'is on', 'before' => 'is before', 'after' => 'is after', 'between' => 'between', 'empty' => 'is empty', 'filled' => 'is filled'],
        'select' => ['in' => 'is any of', 'not_in' => 'is none of', 'empty' => 'is empty', 'filled' => 'is filled'],
        'check' => ['yes' => 'is Yes', 'no' => 'is No'],
    ];
    public const PRESETS = ['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week', 'last_7' => 'Last 7 days', 'last_30' => 'Last 30 days', 'last_90' => 'Last 90 days',
        'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'this_year' => 'This year', 'last_year' => 'Last year', 'next_7' => 'Next 7 days', 'next_30' => 'Next 30 days', 'overdue' => 'Before today'];

    public static function group(string $type): string
    {
        return in_array($type, self::TEXT, true) ? 'text' : (in_array($type, self::NUM, true) ? 'number' : ($type === 'date' ? 'date' : ($type === 'check' ? 'check' : 'select')));
    }

    /** [from, to] (Y-m-d) of a date preset, counted from $today. */
    public static function preset(string $p, ?string $today = null): ?array
    {
        $t = strtotime($today ?: date('Y-m-d')); $d = fn (string $x) => date('Y-m-d', strtotime($x, $t)); $q = (int) ceil(date('n', $t) / 3);
        return match ($p) {
            'today' => [$d('today'), $d('today')], 'yesterday' => [$d('-1 day'), $d('-1 day')],
            'this_week' => [$d('monday this week'), $d('sunday this week')], 'last_week' => [$d('monday last week'), $d('sunday last week')],
            'last_7' => [$d('-6 day'), $d('today')], 'last_30' => [$d('-29 day'), $d('today')], 'last_90' => [$d('-89 day'), $d('today')],
            'this_month' => [date('Y-m-01', $t), date('Y-m-t', $t)], 'last_month' => [date('Y-m-01', strtotime('first day of last month', $t)), date('Y-m-t', strtotime('first day of last month', $t))],
            'this_quarter' => [date('Y-', $t) . sprintf('%02d-01', ($q - 1) * 3 + 1), date('Y-m-t', strtotime(date('Y-', $t) . sprintf('%02d-01', $q * 3)))],
            'this_year' => [date('Y-01-01', $t), date('Y-12-31', $t)], 'last_year' => [(date('Y', $t) - 1) . '-01-01', (date('Y', $t) - 1) . '-12-31'],
            'next_7' => [$d('today'), $d('+6 day')], 'next_30' => [$d('today'), $d('+29 day')], 'overdue' => ['1900-01-01', $d('-1 day')],
            default => null,
        };
    }

    /** Make a tree safe: unknown columns, operators and empty rules are dropped; at most 3 levels and 40 rules. Returns null when nothing is left. */
    public static function clean(mixed $tree, array $fieldsByCol, int $depth = 0, int &$n = 0): ?array
    {
        if (is_string($tree)) $tree = json_decode($tree, true);
        if (! is_array($tree) || $depth > 2) return null;
        $out = ['op' => strtoupper((string) ($tree['op'] ?? 'AND')) === 'OR' ? 'OR' : 'AND', 'rules' => []];
        foreach ((array) ($tree['rules'] ?? []) as $r) {
            if (! is_array($r) || $n >= 40) continue;
            if (isset($r['rules'])) { if ($g = self::clean($r, $fieldsByCol, $depth + 1, $n)) $out['rules'][] = $g; continue; }
            $col = (string) ($r['f'] ?? ''); $o = (string) ($r['o'] ?? '');
            if (! isset($fieldsByCol[$col])) continue;
            $grp = self::group($fieldsByCol[$col]['type']);
            if (! isset(self::OPS[$grp][$o])) continue;
            $v = $r['v'] ?? null;
            if (in_array($o, ['in', 'not_in'], true)) { $v = array_values(array_unique(array_map('strval', array_filter((array) $v, 'is_scalar')))); if (! $v) continue; $v = array_slice($v, 0, 200); }
            elseif ($o === 'between') { $v = array_values((array) $v); if (count($v) < 2 || $v[0] === '' || $v[1] === '' || ! is_scalar($v[0]) || ! is_scalar($v[1])) continue; $v = [(string) $v[0], (string) $v[1]]; }
            elseif (in_array($o, ['empty', 'filled', 'yes', 'no'], true)) $v = null;
            elseif ($o === 'preset') { if (! isset(self::PRESETS[(string) $v])) continue; $v = (string) $v; }
            else { if (! is_scalar($v) || trim((string) $v) === '') continue; $v = mb_substr(trim((string) $v), 0, 200); }
            if ($grp === 'number' && ! in_array($o, ['empty', 'filled'], true)) foreach ((array) $v as $x) if (! is_numeric(str_replace(',', '', $x))) continue 2;
            if ($grp === 'date' && in_array($o, ['on', 'before', 'after', 'between'], true)) foreach ((array) $v as $x) if (! strtotime($x)) continue 2;
            $out['rules'][] = ['f' => $col, 'o' => $o, 'v' => $v]; $n++;
        }
        return $out['rules'] ? $out : null;
    }

    public static function apply($q, ?array $tree, array $fieldsByCol): void
    {
        if (! $tree) return;
        $q->where(function ($w) use ($tree, $fieldsByCol) { self::walk($w, $tree, $fieldsByCol); });
    }

    private static function walk($w, array $g, array $fields): void
    {
        $how = $g['op'] === 'OR' ? 'orWhere' : 'where';
        foreach ($g['rules'] as $r) {
            if (isset($r['rules'])) { $w->$how(function ($x) use ($r, $fields) { self::walk($x, $r, $fields); }); continue; }
            $w->$how(function ($x) use ($r, $fields) { self::rule($x, $r, self::group($fields[$r['f']]['type'])); });
        }
    }

    private static function rule($x, array $r, string $grp): void
    {
        $c = $r['f']; $v = $r['v']; $num = fn ($s) => (float) str_replace(',', '', (string) $s); $like = fn ($s) => addcslashes((string) $s, '%_\\');
        $empty = fn ($y) => $y->whereNull($c)->orWhere($c, '');
        switch ($r['o']) {
            case 'contains': $x->where($c, 'like', '%' . $like($v) . '%'); break;
            case 'starts': $x->where($c, 'like', $like($v) . '%'); break;
            case 'is': $x->where($c, $v); break;
            case 'not_contains': $x->where(fn ($y) => $y->whereNull($c)->orWhere($c, 'not like', '%' . $like($v) . '%')); break;
            case 'in': $blank = in_array('', $v, true); $vals = array_values(array_filter($v, fn ($s) => $s !== ''));
                $x->where(function ($y) use ($c, $vals, $blank, $empty) { if ($vals) $y->whereIn($c, $vals); if ($blank) $y->orWhere($empty); }); break;
            case 'not_in': $x->where(fn ($y) => $y->whereNull($c)->orWhereNotIn($c, $v)); break;
            case 'empty': $grp === 'number' || $grp === 'date' ? $x->whereNull($c) : $x->where($empty); break;
            case 'filled': $grp === 'number' || $grp === 'date' ? $x->whereNotNull($c) : $x->whereNotNull($c)->where($c, '<>', ''); break;
            case 'eq': $x->where($c, $num($v)); break;
            case 'ne': $x->where(fn ($y) => $y->whereNull($c)->orWhere($c, '<>', $num($v))); break;
            case 'gt': $x->where($c, '>', $num($v)); break;
            case 'gte': $x->where($c, '>=', $num($v)); break;
            case 'lt': $x->where($c, '<', $num($v)); break;
            case 'lte': $x->where($c, '<=', $num($v)); break;
            case 'between': $grp === 'date' ? $x->whereBetween($c, [date('Y-m-d 00:00:00', strtotime(min($v))), date('Y-m-d 23:59:59', strtotime(max($v)))]) : $x->whereBetween($c, [min($num($v[0]), $num($v[1])), max($num($v[0]), $num($v[1]))]); break;
            case 'on': $x->whereBetween($c, [date('Y-m-d 00:00:00', strtotime($v)), date('Y-m-d 23:59:59', strtotime($v))]); break;
            case 'before': $x->where($c, '<', date('Y-m-d 00:00:00', strtotime($v))); break;
            case 'after': $x->where($c, '>', date('Y-m-d 23:59:59', strtotime($v))); break;
            case 'preset': [$a, $b] = self::preset($v); $x->whereBetween($c, [$a . ' 00:00:00', $b . ' 23:59:59']); break;
            case 'yes': $x->where($c, 1); break;
            case 'no': $x->where(fn ($y) => $y->whereNull($c)->orWhere($c, 0)); break;
        }
    }

    /** A filter in words, for chips and export headers: "Status is any of Open, Late AND Amount ≥ 100". */
    public static function words(?array $tree, array $fieldsByCol, array $labels = []): string
    {
        if (! $tree) return '';
        $parts = [];
        foreach ($tree['rules'] as $r) {
            if (isset($r['rules'])) { $parts[] = '(' . self::words($r, $fieldsByCol, $labels) . ')'; continue; }
            $f = $fieldsByCol[$r['f']]; $grp = self::group($f['type']); $v = $r['v'];
            $show = fn ($x) => $x === '' ? '(empty)' : ($labels[$r['f']][(string) $x] ?? $x);
            $val = $r['o'] === 'preset' ? self::PRESETS[$v] : (is_array($v) ? implode($r['o'] === 'between' ? ' and ' : ', ', array_map($show, $v)) : ($v === null ? '' : $show($v)));
            $parts[] = trim($f['label'] . ' ' . self::OPS[$grp][$r['o']] . ' ' . $val);
        }
        return implode($tree['op'] === 'OR' ? ' OR ' : ' AND ', $parts);
    }
}
