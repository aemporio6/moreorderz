<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

/** Who is working and in which company. Set from the session for web requests; set by hand in jobs and tests. */
class Context
{
    private static ?int $ent = null;
    private static ?int $user = null;

    public static function set(?int $ent, ?int $user = null): void
    {
        self::$ent = $ent;
        self::$user = $user;
    }

    public static function ent(): ?int
    {
        return self::$ent ?? (session()->isStarted() ? session('ent') : null) ?? Auth::user()?->fk_id_ent;
    }

    public static function userId(): ?int
    {
        return self::$user ?? Auth::id();
    }

    public static function role(): ?string
    {
        return Auth::user()?->role_code;
    }
}
