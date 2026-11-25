<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-42 locked system rows: every master has f_system. A system row cannot be deleted (made inactive) and its code cannot be renamed;
 * its other fields may be changed by the Owner only. The list shows a lock on such rows.
 */
class SystemRows
{
    public const DELETE = 'System row – cannot be deleted';
    public const CODE = 'System row – its code cannot be changed';
    public const OWNER = 'System row – only the Owner changes it';

    /** the code columns of a master (a system row keeps them) */
    public static function codeColumns(string $table): array
    {
        return array_values(array_filter(Registry::columns($table), fn ($c) => $c === 'code' || $c === 'role_code' || str_ends_with($c, '_code') || str_starts_with($c, 'iso_')));
    }

    /** Errors of an edit of a system row [field => message]; [] when the row is not a system row or the edit is allowed. */
    public static function guard(string $table, array $data, array $old, ?int $id): array
    {
        if (! $id || ! Registry::hasColumn($table, 'f_system') || ! (int) ($old['f_system'] ?? 0)) return [];
        if (array_key_exists('f_active', $data) && (int) ($old['f_active'] ?? 1) && ! (int) $data['f_active']) return ['f_active' => self::DELETE];
        foreach (self::codeColumns($table) as $c) if (array_key_exists($c, $data) && (string) ($data[$c] ?? '') !== (string) ($old[$c] ?? '')) return [$c => self::CODE];
        $changed = array_filter(array_keys($data), fn ($k) => $k !== 'f_system' && (string) ($data[$k] ?? '') !== (string) ($old[$k] ?? ''));
        if ($changed && Context::role() !== 'OWNER') return [reset($changed) => self::OWNER];
        return [];
    }

    /** Ids of the locked rows among $ids (for the lock in the list). */
    public static function locked(string $table, array $ids): array
    {
        if (! $ids || ! Registry::hasColumn($table, 'f_system')) return [];
        return DB::table($table)->whereIn(Registry::pk($table), $ids)->where('f_system', 1)->pluck(Registry::pk($table))->map(fn ($x) => (int) $x)->all();
    }
}
