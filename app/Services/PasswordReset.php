<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * RULES_G_CORE R-G_CORE-65 forgot password: login name AND registered e-mail must match the same active login; the answer is always the same.
 * A one-time link valid RESET_LINK_MINUTES (Settings › Choices, default 30) goes by e-mail (and WhatsApp when a number is on file) through the one message door;
 * the new password follows the password rule (at least 10 characters), every other session of the login ends, everything is logged (never the token).
 */
class PasswordReset
{
    public const ANSWER = 'If the details match, we have sent a link.';

    public static function minutes(?int $ent): int { return max(5, (int) (Choices::get('RESET_LINK_MINUTES', $ent) ?: 30)); }

    /** R-C-45 / R-C-44 "Reset password" by an administrator: the same one-time link, sent to the person's own e-mail on record. Nothing secret is returned. */
    public static function byAdmin(int $userId, object $admin, ?string $ip, ?string &$token = null): array
    {
        $u = DB::table('t_users')->where('id', $userId)->first() ?? throw new \InvalidArgumentException('User not found.');
        if (! (int) $u->f_active) throw new \InvalidArgumentException('This person cannot sign in – switch "Can sign in" on first.');
        if ($u->role_code === 'OWNER' && ($admin->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner resets the Owner\'s password.');
        $emails = array_values(array_filter(array_merge($u->fk_id_account ? DB::table('t_account_emails')->where('fk_id_account', $u->fk_id_account)->where('f_active', 1)->pluck('email')->all() : [], filter_var($u->login_id, FILTER_VALIDATE_EMAIL) ? [$u->login_id] : [])));
        if (! $emails) throw new \InvalidArgumentException('This person has no e-mail on record – add one to the person first.');
        $before = (int) DB::table('sys_password_resets')->where('user_id', $u->id)->max('id');
        self::request((string) $u->login_id, (string) $emails[0], $ip, $token);
        if ((int) DB::table('sys_password_resets')->where('user_id', $u->id)->max('id') === $before) throw new \InvalidArgumentException('The link could not be made – try again.');
        Audit::log('t_users', (int) $u->id, 'RESET_REQUEST', ['result' => 'link sent by an administrator', 'by' => (int) $admin->id], null, (int) ($u->fk_id_ent ?? 0));
        $m = $emails[0]; $at = strpos($m, '@'); $shown = substr($m, 0, 1) . '•••' . ($at !== false ? substr($m, $at) : '');
        return ['message' => 'A link to choose a new password was sent to ' . $shown . '. It works once, for ' . self::minutes((int) ($u->fk_id_ent ?? 0) ?: null) . ' minutes.'];
    }

    /** Step 1. Returns the same answer in every case; $token is filled only for a match (tests / the mailer). */
    public static function request(string $login, string $email, ?string $ip, ?string &$token = null): string
    {
        $login = trim($login); $email = mb_strtolower(trim($email)); $token = null;
        $u = $login !== '' ? DB::table('t_users')->where('login_id', $login)->where('f_active', 1)->first() : null;
        $emails = $u ? array_map('mb_strtolower', array_filter(array_merge($u->fk_id_account ? DB::table('t_account_emails')->where('fk_id_account', $u->fk_id_account)->where('f_active', 1)->pluck('email')->all() : [],
            filter_var($u->login_id, FILTER_VALIDATE_EMAIL) ? [$u->login_id] : []))) : [];
        if (! $u || $email === '' || ! in_array($email, $emails, true)) {
            Audit::log('t_users', $u->id ?? null, 'RESET_REQUEST', ['result' => 'no match', 'ip' => $ip], 'reset: no match', (int) ($u->fk_id_ent ?? 0));
            return self::ANSWER;
        }
        $token = bin2hex(random_bytes(24)); $ent = (int) ($u->fk_id_ent ?? 0) ?: null;
        DB::table('sys_password_resets')->where('user_id', $u->id)->whereNull('used_at')->update(['used_at' => now()]);      // older links stop working
        DB::table('sys_password_resets')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $token), 'created_at' => now(), 'expires_at' => now()->addMinutes(self::minutes($ent)), 'ip' => $ip]);
        $link = rtrim((string) config('app.url'), '/') . '/reset/' . $token; $min = self::minutes($ent);
        $body = "Hello, a new password was asked for your login {$u->login_id}. Open this link within $min minutes to choose it (it works once): $link — If you did not ask for this, do nothing; your password stays as it is.";
        Connect\Messenger::queueEmail((int) ($u->fk_id_ent ?? 0), $u->fk_id_account ? (int) $u->fk_id_account : null, $email, 'Your new password', $body, null, 'PASSWORD_RESET', 't_users', (int) $u->id);
        if ($u->fk_id_account && ($wa = Connect\Messenger::whatsappOf((int) $u->fk_id_account))) Connect\Messenger::queueText((int) ($u->fk_id_ent ?? 0), (int) $u->fk_id_account, $wa, $body, 't_users', (int) $u->id);
        Audit::log('t_users', (int) $u->id, 'RESET_REQUEST', ['result' => 'link sent', 'ip' => $ip], null, (int) ($u->fk_id_ent ?? 0));
        return self::ANSWER;
    }

    /** The open link: the reset row when it is valid, else null. */
    public static function valid(string $token): ?object
    {
        if (! preg_match('/^[0-9a-f]{48}$/', $token)) return null;
        return DB::table('sys_password_resets')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /** Step 2: set the new password; the link works once; every other session of the login ends. */
    public static function reset(string $token, string $new, string $again): string
    {
        $row = self::valid($token) ?? throw new \InvalidArgumentException('This link has expired or was used already. Ask for a new one.');
        if (mb_strlen($new) < 10) throw new \InvalidArgumentException('The new password needs at least 10 characters.');
        if ($new !== $again) throw new \InvalidArgumentException('The two passwords are not the same.');
        $u = DB::table('t_users')->where('id', $row->user_id)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('This link has expired or was used already. Ask for a new one.');
        DB::transaction(function () use ($row, $u, $new) {
            DB::table('sys_password_resets')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('t_users')->where('id', $u->id)->update(['password_hash' => Hash::make($new), 'remember_token' => null]);
            DB::table('sys_user_sessions')->where('user_id', $u->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'PWD_RESET']);      // every other session ends (the reset is no sign-in)
        });
        Audit::log('t_users', (int) $u->id, 'UPDATE', ['password' => ['***', '***'], 'by' => 'reset link'], 'password reset', (int) ($u->fk_id_ent ?? 0));
        return 'Your new password is saved. Sign in with it.';
    }
}
