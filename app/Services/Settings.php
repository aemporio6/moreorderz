<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Settings: a company value wins over the global value (fk_id_ent 0 / null). */
class Settings
{
    private static array $cache = [];

    public static function get(string $key, ?int $ent = null, mixed $default = null): mixed
    {
        $ck = $key . '|' . ($ent ?? 0);
        if (array_key_exists($ck, self::$cache)) return self::$cache[$ck];
        $rows = DB::table('sys_settings')->where('setting_key', $key)->get(['fk_id_ent', 'setting_value']);
        $val = $default;
        foreach ($rows as $r) if (! $r->fk_id_ent) $val = $r->setting_value;
        foreach ($rows as $r) if ($ent && (int) $r->fk_id_ent === $ent) $val = $r->setting_value;
        return self::$cache[$ck] = $val;
    }

    public static function set(string $key, mixed $value, ?int $ent = null, ?int $user = null): void
    {
        DB::table('sys_settings')->updateOrInsert(['setting_key' => $key, 'fk_id_ent' => $ent ?? 0], ['setting_value' => (string) $value, 'updated_by' => $user, 'updated_at' => now()]);
        self::$cache = [];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
