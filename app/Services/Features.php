<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1542 feature flags + release notes. A new part of the tool has a flag; the Owner switches it on or off per company on "What's new"
 * (sys_feature_flags; no row = the default). Release notes come from config/releases.php; each person sees "new" until he opens the page.
 */
class Features
{
    public const LIST = [
        'ICC' => ['Inventory cycle (ICC): nightly run, reorder tasks, Home card', true],
        'DATA_HEALTH' => ['Data health score on Home', true],
        'RETURN_ABUSE' => ['Repeated returns: nightly tasks and order warnings', true],
        'FEE_CHECK' => ['Marketplace fee check: nightly overcharge tasks', true],
    ];

    private static array $cache = [];

    public static function on(string $code, int $ent): bool
    {
        $k = "$ent:$code";
        if (! array_key_exists($k, self::$cache)) {
            $v = \Illuminate\Support\Facades\Schema::hasTable('sys_feature_flags') ? DB::table('sys_feature_flags')->where('fk_id_ent', $ent)->where('code', $code)->value('f_on') : null;
            self::$cache[$k] = $v === null ? (bool) (self::LIST[$code][1] ?? true) : (bool) $v;
        }
        return self::$cache[$k];
    }

    public static function set(int $ent, string $code, bool $on, object $u): void
    {
        if (! isset(self::LIST[$code])) throw new \InvalidArgumentException('Unknown feature.');
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner switches parts of the tool on or off.');
        $old = self::on($code, $ent);
        DB::table('sys_feature_flags')->updateOrInsert(['fk_id_ent' => $ent, 'code' => $code], ['f_on' => $on ? 1 : 0, 'updated_by' => $u->id, 'updated_at' => now()]);
        self::$cache = [];
        if ($old !== $on) Audit::log('sys_feature_flags', null, 'UPDATE', [$code => [$old ? 'on' : 'off', $on ? 'on' : 'off']], 'Feature switch', $ent);
    }

    public static function page(int $ent, object $u): array
    {
        $rel = config('releases', []); $latest = $rel[0]['version'] ?? null;
        $seen = DB::table('sys_release_seen')->where('user_id', $u->id)->value('version');
        if ($latest && $seen !== $latest) DB::table('sys_release_seen')->updateOrInsert(['user_id' => $u->id], ['version' => $latest, 'seen_at' => now()]);
        $flags = []; foreach (self::LIST as $c => [$label, $def]) $flags[] = ['code' => $c, 'label' => $label, 'on' => self::on($c, $ent), 'default' => $def];
        return ['releases' => $rel, 'flags' => $flags, 'can_switch' => ($u->role_code ?? '') === 'OWNER', 'was_new' => $latest && $seen !== $latest, 'practice' => Sandbox::id()];
    }

    /** For the top bar: is there a release this person has not opened yet? */
    public static function unseen(object $u): ?string
    {
        $latest = config('releases.0.version'); if (! $latest || ! \Illuminate\Support\Facades\Schema::hasTable('sys_release_seen')) return null;
        return DB::table('sys_release_seen')->where('user_id', $u->id)->value('version') === $latest ? null : $latest;
    }

    public static function forget(): void { self::$cache = []; }
}
