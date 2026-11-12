<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Who is signed in, from where: sessions (one row per sign-in, can be ended by the Owner / Manager), sign-in tries, and the allowed-address list.
 * Allowed addresses: when at least one active rule covers a user (everyone, his role, or him), he can only sign in and work from an address inside one of the rules that cover him.
 * No rule covering him = no limit. A rule that would lock out the person adding it is refused.
 */
class Access
{
    public static function hash(Request $r): string { return hash('sha256', (string) $r->session()->getId()); }

    /** Rules covering this user; empty = no limit. */
    public static function rulesFor(int $ent, int $userId, ?string $role): array
    {
        return DB::table('sys_ip_allowlist')->where('f_active', 1)->whereIn('fk_id_ent', [0, $ent])
            ->where(fn ($w) => $w->where('scope', 'ALL')->orWhere(fn ($x) => $x->where('scope', 'ROLE')->where('role_code', $role))->orWhere(fn ($x) => $x->where('scope', 'USER')->where('user_id', $userId)))->pluck('ip_cidr')->all();
    }

    public static function ipAllowed(int $ent, int $userId, ?string $role, ?string $ip): bool
    {
        $rules = self::rulesFor($ent, $userId, $role);
        return ! $rules || ($ip !== null && IpUtils::checkIp($ip, $rules));
    }

    public static function attempt(string $login, ?string $ip, bool $ok): void
    {
        DB::table('sys_login_attempts')->insert(['login_id' => mb_substr($login, 0, 150), 'ip' => (string) $ip, 'f_success' => $ok ? 1 : 0, 'created_at' => now()]);
    }

    public static function start(Request $r, object $u): void
    {
        $ua = (string) $r->userAgent();
        $dev = (preg_match('/iPhone|iPad|Android/i', $ua, $m) ? $m[0] : (preg_match('/Windows|Macintosh|Linux/i', $ua, $m) ? $m[0] : 'Device')) . ' · ' . (preg_match('/Edg|OPR|Chrome|Firefox|Safari/i', $ua, $b) ? ['Edg' => 'Edge', 'OPR' => 'Opera'][$b[0]] ?? $b[0] : 'browser');
        DB::table('sys_user_sessions')->updateOrInsert(['session_hash' => self::hash($r)], ['user_id' => $u->id, 'fk_id_ent' => $u->fk_id_ent, 'ip' => $r->ip(), 'user_agent' => mb_substr($ua, 0, 255), 'device_label' => mb_substr($dev, 0, 120), 'started_at' => now(), 'last_seen_at' => now(), 'ended_at' => null, 'end_reason' => null, 'ended_by' => null]);
    }

    /** Called on every signed-in request. False = this session was ended (or its address is no longer allowed) and must sign in again. */
    public static function touch(Request $r, object $u): bool
    {
        $h = self::hash($r); $s = DB::table('sys_user_sessions')->where('session_hash', $h)->first(['id', 'ended_at', 'last_seen_at']);
        if (! $s) { self::start($r, $u); return true; }                      // signed in before this screen existed, or "remember me"
        if ($s->ended_at) return false;
        if (! self::ipAllowed((int) $u->fk_id_ent, (int) $u->id, $u->role_code, $r->ip())) { DB::table('sys_user_sessions')->where('id', $s->id)->update(['ended_at' => now(), 'end_reason' => 'IP']); return false; }
        if (! $s->last_seen_at || strtotime($s->last_seen_at) < time() - 60) DB::table('sys_user_sessions')->where('id', $s->id)->update(['last_seen_at' => now(), 'ip' => $r->ip()]);
        return true;
    }

    public static function end(Request $r, string $reason = 'LOGOUT'): void
    {
        DB::table('sys_user_sessions')->where('session_hash', self::hash($r))->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => $reason]);
    }

    private static function admin(): void { if (! in_array(Context::role(), ['OWNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner or a Manager manages sign-ins.'); }

    /** End one session, or all open sessions of a user. A Manager cannot end an Owner's session. */
    public static function endSessions(int $ent, ?int $sessionId, ?int $userId, ?string $keepHash = null): int
    {
        self::admin();
        $q = DB::table('sys_user_sessions as s')->join('t_users as u', 'u.id', '=', 's.user_id')->where('u.fk_id_ent', $ent)->whereNull('s.ended_at')->when($sessionId, fn ($x) => $x->where('s.id', $sessionId))->when($userId, fn ($x) => $x->where('s.user_id', $userId))->when($keepHash, fn ($x) => $x->where('s.session_hash', '<>', $keepHash));
        if (! $sessionId && ! $userId) throw new \InvalidArgumentException('Choose a session or a user.');
        if (Context::role() !== 'OWNER' && (clone $q)->where('u.role_code', 'OWNER')->exists()) throw new \InvalidArgumentException('Only the Owner can end an Owner\'s session.');
        $ids = $q->pluck('s.id'); if ($ids->isEmpty()) throw new \InvalidArgumentException('No open session found.');
        DB::table('sys_user_sessions')->whereIn('id', $ids)->update(['ended_at' => now(), 'end_reason' => 'ADMIN', 'ended_by' => Context::userId()]);
        Audit::log('sys_user_sessions', (int) $ids[0], 'UPDATE', ['ended' => $ids->count(), 'user' => $userId], 'Session(s) ended by an admin', $ent);
        return $ids->count();
    }

    public static function addRule(int $ent, array $d, ?string $myIp): int
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner changes the allowed addresses.');
        $scope = $d['scope'] ?? 'ALL'; $cidr = trim((string) ($d['ip_cidr'] ?? ''));
        if (! in_array($scope, ['ALL', 'ROLE', 'USER'], true)) throw new \InvalidArgumentException('Choose who the rule is for.');
        [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (! ctype_digit($bits) || (int) $bits > (str_contains($ip, ':') ? 128 : 32)))) throw new \InvalidArgumentException('Write one address (for example 203.0.113.7) or a range (for example 203.0.113.0/24).');
        if ($scope === 'ROLE' && empty($d['role_code'])) throw new \InvalidArgumentException('Choose the role.');
        if ($scope === 'USER' && ! DB::table('t_users')->where('id', $d['user_id'] ?? 0)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the user.');
        // would this rule lock out the person adding it?
        $me = DB::table('t_users')->where('id', Context::userId())->first(['id', 'role_code']);
        $covers = $scope === 'ALL' || ($scope === 'ROLE' && $d['role_code'] === $me->role_code) || ($scope === 'USER' && (int) $d['user_id'] === (int) $me->id);
        if ($covers && $myIp !== null && ! IpUtils::checkIp($myIp, array_merge(self::rulesFor($ent, (int) $me->id, $me->role_code), [$cidr]))) throw new \InvalidArgumentException("Refused: this rule would lock you out – you are on $myIp, which is not inside $cidr. Add your own address first.");
        $id = DB::table('sys_ip_allowlist')->insertGetId(['fk_id_ent' => $ent, 'scope' => $scope, 'role_code' => $scope === 'ROLE' ? $d['role_code'] : null, 'user_id' => $scope === 'USER' ? (int) $d['user_id'] : null, 'ip_cidr' => $cidr, 'source_note' => mb_substr((string) ($d['note'] ?? ''), 0, 150) ?: null, 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now()]);
        Audit::log('sys_ip_allowlist', $id, 'INSERT', ['scope' => $scope, 'cidr' => $cidr], $d['note'] ?? null, $ent);
        return $id;
    }

    public static function removeRule(int $ent, int $id): void
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner changes the allowed addresses.');
        if (! DB::table('sys_ip_allowlist')->where('id', $id)->where('fk_id_ent', $ent)->where('f_active', 1)->update(['f_active' => 0])) throw new \InvalidArgumentException('Rule not found.');
        Audit::log('sys_ip_allowlist', $id, 'UPDATE', ['f_active' => [1, 0]], 'Allowed address removed', $ent);
    }

    public static function page(int $ent, string $myHash): array
    {
        return ['sessions' => DB::table('sys_user_sessions as s')->join('t_users as u', 'u.id', '=', 's.user_id')->where('u.fk_id_ent', $ent)->orderByRaw('s.ended_at IS NOT NULL')->orderByDesc('s.last_seen_at')->limit(200)
                ->get(['s.id', 's.user_id', 'u.login_id', 'u.role_code', 's.ip', 's.device_label', 's.started_at', 's.last_seen_at', 's.ended_at', 's.end_reason', 's.session_hash'])->map(function ($s) use ($myHash) { $s->mine = $s->session_hash === $myHash; unset($s->session_hash); return $s; }),
            'attempts' => DB::table('sys_login_attempts')->orderByDesc('id')->limit(100)->get(['login_id', 'ip', 'f_success', 'created_at']),
            'rules' => DB::table('sys_ip_allowlist as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.fk_id_ent', $ent)->where('a.f_active', 1)->orderBy('a.id')->get(['a.id', 'a.scope', 'a.role_code', 'u.login_id', 'a.ip_cidr', 'a.source_note', 'a.created_at']),
            'users' => DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('login_id')->get(['id', 'login_id', 'role_code']), 'roles' => ['OWNER', 'MANAGER', 'PARTNER', 'FINANCE', 'ACCOUNTANT', 'SOURCING', 'WAREHOUSE', 'TL', 'VA']];
    }
}
