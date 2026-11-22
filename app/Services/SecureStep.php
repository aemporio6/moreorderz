<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-59 secure pages / Reveal need 2FA once per session, R-G_CORE-98 asked again after secure_reask_minutes (default 60) without activity.
 * Activity = any request of that user in that session (SetContext calls seen()). 5 wrong codes lock secure pages for 15 minutes (logged RIGHTS_DENIED).
 * Settings › Choices: SECURE_2FA (who must have two-step sign-in to open secrets) and SECURE_REASK_MINUTES (owner rule: never locked).
 */
class SecureStep
{
    public const FAILS = 5;
    public const LOCK_MIN = 15;

    /** Every signed-in request: remember the previous activity time (for this request's check), then mark now. */
    public static function seen(Request $r): void
    {
        if (! $r->hasSession()) return;
        $r->attributes->set('secure_prev_seen', $r->session()->get('secure_seen'));
        $r->session()->put('secure_seen', time());
    }

    public static function minutes(?int $ent): int { return max(1, (int) (Choices::get('SECURE_REASK_MINUTES', $ent) ?: 60)); }

    /**
     * null = may go on; else ['need' => CODE | SETUP | LOCKED, 'message' => …].
     * SECURE_2FA = REQUIRE: a login without two-step sign-in must set it up first; IF_SET_UP: only logins that have it are asked.
     */
    public static function needed(Request $r, object $u): ?array
    {
        $ent = (int) (Context::ent() ?: ($u->fk_id_ent ?? 0)) ?: null;
        if (Cache::get(self::lockKey($u))) return ['need' => 'LOCKED', 'message' => 'Too many wrong codes – secure pages are locked for ' . self::LOCK_MIN . ' minutes.'];
        if (empty($u->totp_secret)) return Choices::get('SECURE_2FA', $ent) === 'REQUIRE'
            ? ['need' => 'SETUP', 'message' => 'Secrets need two-step sign-in. Set it up under My profile › Two-step sign-in, then open this again.'] : null;
        $ok = (int) $r->session()->get('secure_ok_at', 0);
        $prev = $r->attributes->get('secure_prev_seen') ?? $r->session()->get('secure_seen');
        $idle = $prev ? time() - (int) $prev : 0;
        if (! $ok || $idle > self::minutes($ent) * 60) return ['need' => 'CODE', 'message' => 'Type the 6-digit code of your phone app to open secrets.'];
        return null;
    }

    /** Check the code of the phone app; 5 wrong codes lock secure pages for 15 minutes. */
    public static function verify(Request $r, object $u, string $code): array
    {
        if (Cache::get(self::lockKey($u))) throw new \InvalidArgumentException('Too many wrong codes – secure pages are locked for ' . self::LOCK_MIN . ' minutes.');
        if (empty($u->totp_secret)) throw new \InvalidArgumentException('Set up two-step sign-in first (My profile).');
        $code = preg_replace('/\s+/', '', $code);
        $good = preg_match('/^\d{6}$/', $code) && (new \PragmaRX\Google2FA\Google2FA())->verifyKey(decrypt($u->totp_secret), $code, 1);
        if (! $good) {
            $n = (int) Cache::get(self::failKey($u), 0) + 1; Cache::put(self::failKey($u), $n, now()->addMinutes(self::LOCK_MIN));
            Audit::log('t_users', (int) $u->id, 'SECURE_CODE_FAIL', ['tries' => $n], null, (int) ($u->fk_id_ent ?? 0));
            if ($n >= self::FAILS) {
                Cache::put(self::lockKey($u), 1, now()->addMinutes(self::LOCK_MIN)); Cache::forget(self::failKey($u));
                EventBus::fire('RIGHTS_DENIED', 't_users', (int) $u->id, ['why' => 'secure code failed ' . self::FAILS . ' times'], (int) ($u->fk_id_ent ?? 0) ?: null);
                throw new \InvalidArgumentException('Too many wrong codes – secure pages are locked for ' . self::LOCK_MIN . ' minutes.');
            }
            throw new \InvalidArgumentException('That code is not right. Try the next one.');
        }
        Cache::forget(self::failKey($u));
        $r->session()->put('secure_ok_at', time()); $r->session()->put('secure_seen', time()); $r->attributes->set('secure_prev_seen', time());
        Audit::log('t_users', (int) $u->id, 'SECURE_CODE_OK', [], null, (int) ($u->fk_id_ent ?? 0));
        return ['ok' => true, 'message' => 'Secrets are open for this session (asked again after ' . self::minutes((int) (Context::ent() ?: 0) ?: null) . ' minutes without activity).'];
    }

    /** R-G_CORE-59: a secure page (Settings › Choices SECURE_FORMS) asks the code before it opens; every open is logged (REVEAL, page). */
    public static function page(Request $r, string $formKey): ?array
    {
        $u = $r->user(); if (! $u) return null; $ent = (int) (Context::ent() ?: ($u->fk_id_ent ?? 0)) ?: null;
        if (! in_array($formKey, Choices::list('SECURE_FORMS', $ent), true)) return null;
        if ($need = self::needed($r, $u)) return $need + ['secure_page' => $formKey];
        if (! $r->session()->get("secure_page_logged.$formKey")) { Audit::log($formKey, null, 'REVEAL', ['page' => $formKey, 'result' => 'OPENED'], 'secure page', $ent); $r->session()->put("secure_page_logged.$formKey", 1); }
        return null;
    }

    private static function lockKey(object $u): string { return 'secure_lock:' . $u->id; }
    private static function failKey(object $u): string { return 'secure_fail:' . $u->id; }
}
