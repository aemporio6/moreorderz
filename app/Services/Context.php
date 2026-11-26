<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

/** Who is working and in which company. Set from the session for web requests; set by hand in jobs and tests. */
class Context
{
    private static ?int $ent = null;
    private static ?int $user = null;
    private static bool $all = false;      // R-G_CORE-102 "All companies (added up)"
    private static array $allIds = [];

    public static function set(?int $ent, ?int $user = null): void
    {
        self::$ent = $ent;
        self::$user = $user;
    }

    public static function ent(): ?int
    {
        return self::$ent ?? (session()->isStarted() ? session('ent') : null) ?? Auth::user()?->fk_id_ent;
    }

    /** R-G_CORE-102: the Owner's "All companies (added up)" mode – reads cover every company he may see; ent() stays his home company. */
    public static function setAll(bool $on, array $ids = []): void { self::$all = $on && $ids; self::$allIds = $on ? array_values(array_map('intval', $ids)) : []; }
    public static function all(): bool { return self::$all; }
    /** The companies a read covers: every allowed one in All companies mode, else the one company. */
    public static function ents(): array { return self::$all ? self::$allIds : array_values(array_filter([(int) self::ent()])); }

    public static function userId(): ?int
    {
        return self::$user ?? Auth::id();
    }

    public static function role(): ?string
    {
        return Auth::user()?->role_code;
    }
}
