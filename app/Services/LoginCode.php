<?php

namespace App\Services;

use App\Services\Connect\Messenger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A10 the second sign-in step by WhatsApp code for the roles of Choice LOGIN_CODE_ROLES (Owner, Finance, Partner) that have no authenticator app:
 * a 6-digit code, kept only as a hash for 5 minutes, sent as the approved WhatsApp template LOGIN_CODE to the person's WhatsApp number.
 * Asked only when it can really arrive – the person has a WhatsApp number, the company has a WhatsApp connection and the template is approved –
 * so nobody is ever locked out by a code that cannot be sent. The authenticator app (TOTP) wins when it is set up.
 */
class LoginCode
{
    public const MINUTES = 5;

    private static function key(int $userId): string { return 'login_wa:' . $userId; }

    /** The masked number the code goes to, or null when the WhatsApp step does not apply. */
    public static function target(object $u): ?string
    {
        $ent = (int) ($u->fk_id_ent ?? 0);
        $roles = Choices::list('LOGIN_CODE_ROLES', $ent ?: null) ?: ['OWNER', 'FINANCE', 'PARTNER'];
        if (! empty($u->totp_secret) || ! in_array((string) $u->role_code, $roles, true) || ! $u->fk_id_account) return null;
        $n = (string) DB::table('t_account')->where('id', $u->fk_id_account)->value('whatsapp_number');
        if (strlen(preg_replace('/\D/', '', $n)) < 8 || ! Messenger::waConnection($ent)) return null;
        if (! DB::table('wa_templates')->where('code', 'LOGIN_CODE')->where('f_active', 1)->where('approval_status', 'APPROVED')->exists()) return null;
        return $n;
    }

    /** Make and send a code; returns the masked number. */
    public static function send(object $u, string $number): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::key((int) $u->id), hash('sha256', $u->id . '|' . $code), now()->addMinutes(self::MINUTES));
        Messenger::queueWaNow((int) ($u->fk_id_ent ?? 0), (int) $u->fk_id_account, PersonChannels::intl($number), 'LOGIN_CODE', ['code' => $code], 't_users', (int) $u->id);
        Audit::log('t_users', (int) $u->id, 'LOGIN_CODE_SENT', ['to' => PersonChannels::mask($number)], null, (int) ($u->fk_id_ent ?? 0));
        return PersonChannels::mask($number);
    }

    public static function check(object $u, string $code): bool
    {
        $h = Cache::get(self::key((int) $u->id));
        $ok = $h && preg_match('/^\d{6}$/', $code) && hash_equals($h, hash('sha256', $u->id . '|' . $code));
        if ($ok) Cache::forget(self::key((int) $u->id));      // a code works once
        return (bool) $ok;
    }
}
