<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * R-C-44 the login lives on the person (Access section): login id (≤ 15, unique), "Can log in", 2-step sign-in,
 * devices, Reset password (one-time link), last login. No password field anywhere – a new login chooses its own
 * password through the one-time link. Turning "Can log in" off ends every open sign-in of that login.
 */
class PersonAccess
{
    public static function get(int $personId, object $me): array
    {
        $p = DB::table('t_account')->where('id', $personId)->first(['id', 'fk_id_ent', 'first_name', 'sur_name']) ?? throw new \InvalidArgumentException('Person not found.');
        $u = DB::table('t_users')->where('fk_id_account', $personId)->orderBy('id')->first();
        $dev = $u ? DB::table('sys_user_sessions')->where('user_id', $u->id)->orderByRaw('ended_at IS NOT NULL')->orderByDesc('last_seen_at')->limit(8)
            ->get(['id', 'device_label', 'ip', 'started_at', 'last_seen_at', 'ended_at', 'end_reason'])->map(function ($s) { $s->open = ! $s->ended_at; return $s; }) : collect();
        return ['user' => $u ? ['id' => (int) $u->id, 'login_id' => $u->login_id, 'can_login' => (bool) $u->f_active, 'role' => $u->custom_role ?: $u->role_code, 'two_step' => (bool) $u->totp_secret,
            'has_password' => (bool) $u->password_hash, 'last_login' => $u->last_login_at] : null, 'devices' => $dev->values(), 'roles' => array_keys(Perm::roles()),
            'may_edit' => Perm::has($me, 'admin.users'), 'may_end' => Perm::has($me, 'admin.endSession'), 'email' => (bool) DB::table('t_account_emails')->where('fk_id_account', $personId)->where('f_active', 1)->exists()];
    }

    /** Create or change the login of a person. */
    public static function save(int $personId, array $in, object $me, ?string $ip): array
    {
        Perm::need($me, 'admin.users');
        $p = DB::table('t_account')->where('id', $personId)->first() ?? throw new \InvalidArgumentException('Person not found.');
        $u = DB::table('t_users')->where('fk_id_account', $personId)->orderBy('id')->first();
        $ent = (int) ($p->fk_id_ent ?: Context::ent());
        $in['login_id'] = trim((string) ($in['login_id'] ?? ''));
        $v = Validator::make($in, ['login_id' => 'required|string|max:15', 'can_login' => 'boolean', 'role_code' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(Perm::roles()))]],
            ['login_id.required' => 'Login id is required.', 'login_id.max' => 'Login id can be at most 15 characters.']);
        $v->after(function ($v) use ($in, $u, $ent) {      // unique per company (and never two logins with the same id – sign-in is by the id alone)
            if (DB::table('t_users')->where('login_id', $in['login_id'])->when($u, fn ($q) => $q->where('id', '<>', $u->id))->exists()) $v->errors()->add('login_id', 'This login id is already used.');
        });
        if (! $u && empty($in['role_code'])) $v->after(fn ($v) => $v->errors()->add('role_code', 'Choose the role of the new login.'));
        $v->validate();
        $can = (int) ($in['can_login'] ?? ($u ? $u->f_active : 1));
        if ($u && (int) $u->id === (int) $me->id && ! $can) throw new \InvalidArgumentException('You cannot switch off your own login.');
        $made = ! empty($in['role_code']) ? Perm::roles()[$in['role_code']] : null;
        if ($u) {
            $ch = []; $new = ['login_id' => $in['login_id'], 'f_active' => $can] + ($made ? ['role_code' => $made['like'], 'custom_role' => $made['system'] ? null : $made['code']] : []);
            if ($u->role_code === 'OWNER' && $made && $made['like'] !== 'OWNER' && (int) $u->id === (int) $me->id) throw new \InvalidArgumentException('You cannot remove your own Owner role.');
            foreach ($new as $k => $x) if ((string) $u->$k !== (string) $x) $ch[$k] = [$u->$k, $x];
            DB::table('t_users')->where('id', $u->id)->update($new);
            if ($ch) Audit::log('t_users', (int) $u->id, 'UPDATE', $ch, 'Access section of the person', $ent);
            $ended = 0; if (! $can && (int) $u->f_active) { try { $ended = Access::endSessions($ent, null, (int) $u->id); } catch (\InvalidArgumentException $e) { $ended = 0; } }      // the next request of that login is signed out (SetContext) – open sessions end now
            return ['id' => (int) $u->id, 'message' => $ch ? 'Login updated' . ($ended ? " – $ended sign-in(s) ended" : '') : 'Nothing changed'];
        }
        $id = (int) DB::table('t_users')->insertGetId(['login_id' => $in['login_id'], 'fk_id_account' => $personId, 'f_active' => $can, 'password_hash' => null, 'role_code' => $made['like'], 'custom_role' => $made['system'] ? null : $made['code'],
            'display_name' => trim(($p->first_name ?? '') . ' ' . ($p->sur_name ?? '')) ?: $in['login_id'], 'fk_id_ent' => $ent]);
        Audit::log('t_users', $id, 'INSERT', ['login_id' => [null, $in['login_id']], 'person' => $personId, 'role' => $in['role_code']], 'Access section of the person', $ent);
        $msg = 'Login made.';
        if ($can) { try { $msg .= ' ' . PasswordReset::byAdmin($id, $me, $ip)['message']; } catch (\InvalidArgumentException $e) { $msg .= ' No password yet: ' . $e->getMessage() . ' Then press "Reset password".'; } }
        return ['id' => $id, 'message' => $msg];
    }
}
