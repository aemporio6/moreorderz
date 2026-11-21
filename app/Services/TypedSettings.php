<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The checks and the history of typed setting pages (Settings › Sourcing R-B_SOURCING-51, Settings › Warehouse R-H-57).
 * A definition is [label, type, default, help, extra]; extra: min, max, optional, options (value => label), pattern.
 * Types: yn, int, decimal, pct, enum, text, weekdays (e.g. "6,7" = Sat, Sun), currency, choice (Choices::LIST), people (active person ids), role (roles master).
 * Saving writes the company row (fk_id_ent = company) and one history row (who, when, old → new); existing data is never changed.
 */
class TypedSettings
{
    /** [error or null, the value to store] */
    public static function check(int $ent, string $key, array $d, $value): array
    {
        $v = trim((string) $value); $x = $d[4] ?? []; $t = $d[1];
        if ($v === '' && ! empty($x['optional'])) return [null, ''];
        $err = match ($t) {
            'yn' => in_array(strtoupper($v), ['Y', 'N'], true) ? null : 'Choose Yes or No',
            'int' => preg_match('/^-?\d+$/', $v) && (int) $v >= ($x['min'] ?? PHP_INT_MIN) && (int) $v <= ($x['max'] ?? PHP_INT_MAX) ? null : 'Enter a whole number from ' . ($x['min'] ?? 0) . ' to ' . ($x['max'] ?? '…'),
            'decimal' => is_numeric($v) && (float) $v >= ($x['min'] ?? 0) && (float) $v <= ($x['max'] ?? PHP_FLOAT_MAX) ? null : 'Enter a number from ' . ($x['min'] ?? 0) . ' to ' . ($x['max'] ?? '…'),
            'pct' => is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? null : 'Enter a percentage from 0 to 100',
            'enum' => isset(($x['options'] ?? [])[strtoupper($v)]) ? null : 'Choose one of the options shown',
            'text' => $v !== '' && mb_strlen($v) <= ($x['max'] ?? 60) && (empty($x['pattern']) || preg_match($x['pattern'], $v)) ? null : ($x['hint'] ?? 'Enter a value (up to ' . ($x['max'] ?? 60) . ' characters)'),
            'weekdays' => preg_match('/^\s*[1-7](\s*,\s*[1-7])*\s*$/', $v) ? null : 'Enter day numbers 1 (Mon) to 7 (Sun), comma separated',
            'currency' => DB::table('cat_currency')->where('f_active', 1)->where('currency_code', strtoupper($v))->exists() ? null : 'Choose an active currency',
            'choice' => isset(Choices::LIST[$key][3][strtoupper($v)]) ? null : 'Choose one of the options shown',
            'role' => $v === '' || $v === '0' || DB::table('cat_account_types')->where('f_active', 1)->where('role_code', $v)->exists() ? null : 'Choose a role of the roles master',
            'people' => self::people($ent, $v) === null ? 'Choose active people only' : null,
            default => 'Unknown type',
        };
        if ($err) return [$err, $v];
        if (in_array($t, ['yn', 'enum', 'currency', 'choice'], true)) $v = strtoupper($v);
        if ($t === 'people') $v = implode(',', self::people($ent, $v));
        if ($t === 'weekdays') $v = implode(',', array_values(array_unique(array_map('intval', explode(',', $v)))));
        return [null, $v];
    }

    /** Save when changed; one history row tagged $tag. */
    public static function store(int $ent, string $key, string $label, string $old, string $new, string $tag): array
    {
        if ($old === $new) return ['message' => 'No change.', 'value' => $new];
        Settings::set($key, $new, $ent, Context::userId());
        Audit::log('sys_settings', null, 'UPDATE', ['key' => $key, 'from' => $old, 'to' => $new], $tag, $ent);
        return ['message' => $label . ' saved.', 'value' => $new];
    }

    public static function history(int $ent, array $keys, string $tag, int $limit = 50): array
    {
        return DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.table_name', 'sys_settings')->where('a.reason', $tag)
            ->where(fn ($w) => $w->whereNull('a.fk_id_ent')->orWhere('a.fk_id_ent', $ent))->orderByDesc('a.id')->limit(500)->get(['a.id', 'a.changes_json', 'a.created_at', 'u.display_name'])
            ->map(function ($r) { $c = json_decode((string) $r->changes_json, true) ?: []; return ['key' => $c['key'] ?? null, 'from' => $c['from'] ?? null, 'to' => $c['to'] ?? null, 'by' => $r->display_name, 'at' => (string) $r->created_at]; })
            ->filter(fn ($r) => in_array($r['key'], $keys, true))->take($limit)->values()->all();
    }

    /** "1, 4,9" → [1, 4, 9] when every id is an active person of the company; null when one is not. */
    public static function people(int $ent, string $v): ?array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter(preg_split('/[\s,;]+/', $v), fn ($x) => $x !== ''))));
        if (! $ids) return [];
        return DB::table('t_account')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('id', $ids)->count() === count($ids) ? $ids : null;
    }
}
