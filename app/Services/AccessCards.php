<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 21:31 – access the MY TOOL way, made easier: one card per person, eight plain ticks (each a bundle of the exact rights
 * underneath), the role fills them, any tick changed for that one person, "approve up to £", "access until" (after it: look only),
 * the shops / companies seen, and a plain sentence "Omer can … · cannot …". A suggestion per person from what the role normally has
 * and what the person really uses. Every change goes through Perm (history, owner-only rights stay with the Owner).
 */
class AccessCards
{
    /** tick => [label, pattern over the right codes] */
    public const TICKS = [
        'view' => ['See the screens and reports of their work', '/(\.SEE$|^report:|^work\.TASK|:VIEW$)/'],
        'create' => ['Create new records', '/(\.(ENTER|BUY)$|Add$|\.create$|\.build$|Import$|:ADD$)/'],
        'edit' => ['Edit / correct records', '/(\.(EDIT|CHANGE)$|Edit$|\.edit(_|$)|setPrice|saleCarrier|:EDIT$)/'],
        'delete' => ['Delete (goes to the recycle bin)', '/(delete|Delete|void$|deactivate|:DELETE$)/'],
        'restore' => ['Bring back / undo', '/(Undo$|undo|reopen|Unlock$)/'],
        'export' => ['Download / export', '/(export|Export|EXPORT)/'],
        'money' => ['See money (costs, profit, landed price)', '/(\.MONEY$|^money\.SEE$|\.COST$|LANDED|lotCost|waterfall)/'],
        'bank' => ['See bank details and personal data', '/(personal_data|SeeContact|SeeInvestor)/'],
        'approve' => ['Approve payments, counts and changes', '/(approve$|Approve$|APPROVE|Decide$|\.CONFIRM$)/'],
        'setup' => ['Change settings, lists and rights', '/(^admin\.|^setup\.|settings|\.SETUP$|Templates$|mapping\.manage|csvFormats|alertRules)/'],
    ];

    /**
     * Next #4 – access by AREA (the menu groups): Edit / View only / Hidden for one person. area => [label, codes of the area, view codes, main right, menu groups]
     * View = the area's looking rights (.SEE, its reports, the task dashboard); Edit = every right of the area; Hidden = none. Owner-only rights never move.
     */
    public const AREAS = [
        'buying' => ['Buying & sourcing', '/^(buying\.|report:(purchases|suppliers|research|landed|in_transit|supplier_perf|po_payments)$)/', 'buying.SEE', ['Buy & Receive']],
        'stock' => ['Inventory & warehouse', '/^(stock\.|wh\.|report:(stock|stock_location|wh_\w+|valuation|slow_dead)$)/', 'stock.SEE', ['Inventory']],
        'selling' => ['Products & selling', '/^(orders\.|products\.|print\.|report:(sales_\w+|price_log)$)/', 'orders.SEE', ['Products & Selling']],
        'money' => ['Money', '/^(money\.|finance\.|close\.|tax\.|report:(cash|vat|ledger|expenses|close)$)/', 'money.SEE', ['Money']],
        'partners' => ['Partners & investors', '/^(partners\.|report:partners$)/', 'partners.SEE', ['Partners']],
        'work' => ['Tasks & team', '/^(work\.|people\.|report:(tasks_week|team_engagements|users)$)/', 'work.TASK_DASH', ['Tasks', 'Team & Documents']],
        'setup' => ['Setup', '/^(admin\.|setup\.|connect\.|company\.|import\.|app\.|report:menu$)/', 'admin.audit', ['Setup']],
    ];
    private const VIEW = '/(\.SEE$|^report:|^work\.TASK_DASH$|^work\.tasks_may$|^admin\.audit$)/';

    /** [all codes, view codes] of an area, without owner-only rights */
    public static function areaCodes(string $area): array
    {
        $re = self::AREAS[$area][1]; $all = [];
        foreach (array_keys(Perm::catalogue()) as $c) if (preg_match($re, $c) && ! in_array($c, Perm::OWNER_ONLY, true) && ! preg_match('/_owner$/', $c)) $all[] = $c;
        return [$all, array_values(array_filter($all, fn ($c) => preg_match(self::VIEW, $c) || $c === self::AREAS[$area][2]))];
    }

    /** edit / view / hidden for a person (or his role alone), and whether it is only part of it */
    public static function areaLevel(object $u, string $area, bool $roleOnly = false): array
    {
        [$all, $view] = self::areaCodes($area); [$base, $key] = Perm::rolesOf($u);
        $has = fn ($c) => $roleOnly ? Perm::roleCan($base, $key, $c) : Perm::has($u, $c);
        $v = count(array_filter($view, $has)); $e = count(array_filter(array_diff($all, $view), $has)); $ne = count($all) - count($view);
        $level = $v === 0 && $e === 0 ? 'hidden' : ($e > 0 ? 'edit' : 'view');
        return ['level' => $level, 'part' => $level === 'edit' ? $e < $ne : ($level === 'view' ? $v < count($view) : false)];
    }

    /** The areas a person cannot open (for Home: "Ask the Owner"), with the main right to ask for and whether he asked already. */
    public static function blocked(object $u): array
    {
        if (($u->role_code ?? '') === 'OWNER' && empty($u->custom_role)) return [];
        $out = [];
        foreach (self::AREAS as $k => [$label, , $main]) if (Perm::known($main) && ! Perm::has($u, $main))
            $out[] = ['area' => $k, 'label' => $label, 'right' => $main, 'asked' => DB::table('mo_access_requests')->where(['user_id' => $u->id, 'perm_code' => $main, 'status' => 'OPEN'])->exists()];
        return $out;
    }

    private static function codes(string $tick): array
    {
        $re = self::TICKS[$tick][1]; $out = [];
        foreach (array_keys(Perm::catalogue()) as $c) if (preg_match($re, $c) && ! in_array($c, Perm::OWNER_ONLY, true)) $out[] = $c;
        return $out;
    }

    /** on (all), off (none) or part (some) – for a person, or for a role alone */
    private static function state(object $u, string $tick, bool $roleOnly = false): string
    {
        $codes = self::codes($tick); if (! $codes) return 'off'; $yes = 0;
        [$base, $key] = Perm::rolesOf($u);
        foreach ($codes as $c) if ($roleOnly ? Perm::roleCan($base, $key, $c) : Perm::has($u, $c)) $yes++;
        return $yes === 0 ? 'off' : ($yes === count($codes) ? 'on' : 'part');
    }

    public static function cards(int $ent): array
    {
        $users = DB::table('t_users')->where('f_active', 1)->orderByRaw("role_code = 'OWNER' DESC")->orderBy('display_name')->get();
        $roles = collect(Perm::roles())->map(fn ($r) => ['code' => $r['code'] ?? $r->code ?? null, 'label' => $r['label'] ?? $r->label ?? null])->filter(fn ($r) => $r['code'])->values();
        $cards = [];
        foreach ($users as $u) {
            $owner = $u->role_code === 'OWNER' && ! $u->custom_role;
            $ticks = [];
            foreach (self::TICKS as $k => [$label]) { $mine = $owner ? 'on' : self::state($u, $k); $role = $owner ? 'on' : self::state($u, $k, true); $ticks[] = ['key' => $k, 'label' => $label, 'state' => $mine, 'role' => $role, 'changed' => $mine !== $role]; }
            $scope = Perm::shops($u);
            $cards[] = ['id' => (int) $u->id, 'name' => $u->display_name ?: $u->login_id, 'login' => $u->login_id, 'role' => $u->custom_role ?: $u->role_code, 'owner' => $owner,
                'until' => $u->access_until ?? null, 'expired' => ! empty($u->access_until) && $u->access_until < date('Y-m-d'), 'approve_limit' => $u->approve_limit ?? null,
                'shops' => $scope === null ? null : DB::table('t_branches')->whereIn('id', $scope ?: [0])->orderBy('shop_name')->pluck('shop_name')->all(),
                'ticks' => $ticks, 'areas' => $owner ? [] : array_map(fn ($k) => ['key' => $k, 'label' => self::AREAS[$k][0]] + self::areaLevel($u, $k) + ['role' => self::areaLevel($u, $k, true)['level']], array_keys(self::AREAS)), 'last_login' => $u->last_login_at, 'requests' => DB::table('mo_access_requests')->where('user_id', $u->id)->where('status', 'OPEN')->count(),
                'suggest' => $owner ? null : self::suggest($u, $ticks)];
        }
        return ['cards' => $cards, 'roles' => $roles, 'ticks' => array_map(fn ($k, $t) => ['key' => $k, 'label' => $t[0]], array_keys(self::TICKS), self::TICKS)];
    }

    /** One plain suggestion per person: a right the role has but the person was refused, a person who never signed in, a past "until". */
    private static function suggest(object $u, array $ticks): ?string
    {
        if (! empty($u->access_until) && $u->access_until < date('Y-m-d')) return 'Access ended on ' . $u->access_until . ' – set a new date or switch the person off.';
        if (! $u->last_login_at) return 'Has never signed in – send the sign-in link, or switch off if not needed.';
        $off = array_filter($ticks, fn ($t) => $t['changed'] && $t['state'] !== 'on' && $t['role'] === 'on');
        if ($off) return 'Has less than the role: ' . implode(', ', array_map(fn ($t) => mb_strtolower($t['label']), $off)) . '. "Back to the role" gives it again.';
        $more = array_filter($ticks, fn ($t) => $t['changed'] && $t['state'] === 'on' && $t['role'] !== 'on');
        if ($more) return 'Has more than the role: ' . implode(', ', array_map(fn ($t) => mb_strtolower($t['label']), $more)) . '. Check it is still needed.';
        return null;
    }

    public static function save(int $userId, array $in, object $by): array
    {
        $u = DB::table('t_users')->where('id', $userId)->first() ?? throw new \InvalidArgumentException('Person not found.');
        if ($u->role_code === 'OWNER' && ! $u->custom_role) throw new \InvalidArgumentException('An Owner account can do everything and can never be locked out.');
        $upd = [];
        if (array_key_exists('until', $in)) $upd['access_until'] = $in['until'] ?: null;
        if (array_key_exists('approve_limit', $in)) $upd['approve_limit'] = $in['approve_limit'] === '' || $in['approve_limit'] === null ? null : max(0, (float) $in['approve_limit']);
        if (! empty($in['role']) && $in['role'] !== ($u->custom_role ?: $u->role_code)) {
            $known = collect(Perm::roles())->map(fn ($r) => $r['code'] ?? $r->code ?? null)->filter()->all(); if (! in_array($in['role'], $known, true)) throw new \InvalidArgumentException('Unknown role.');
            $sys = in_array($in['role'], ['OWNER', 'PARTNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'TL', 'VA', 'WAREHOUSE', 'SOURCING'], true);
            $upd['role_code'] = $sys ? $in['role'] : (string) (DB::table('mo_roles')->where('role_code', $in['role'])->value('like_role') ?? 'VA'); $upd['custom_role'] = $sys ? null : $in['role'];
            DB::table('mo_user_perms')->where('user_id', $userId)->delete();      // a new role: the ticks come from the role again
        }
        if ($upd) { DB::table('t_users')->where('id', $userId)->update($upd); Audit::log('t_users', $userId, 'UPDATE', array_map(fn ($v) => [null, $v], $upd), 'Access card'); Perm::flush(); }
        if (! empty($in['reset'])) { DB::table('mo_user_perms')->where('user_id', $userId)->delete(); Perm::flush(); Audit::log('t_users', $userId, 'UPDATE', ['access' => [null, 'back to the role']], 'Access card'); }
        foreach ((array) ($in['ticks'] ?? []) as $k => $on) {
            if (! isset(self::TICKS[$k])) continue; $fresh = DB::table('t_users')->where('id', $userId)->first(); [$base, $key] = Perm::rolesOf($fresh);
            foreach (self::codes($k) as $c) { $role = Perm::roleCan($base, $key, $c); Perm::setUser($userId, $c, $role === (bool) $on ? null : ($on ? 'GRANTED' : 'REVOKED'), 'Access card: ' . self::TICKS[$k][0] . ($on ? ' on' : ' off'), null, (int) $by->id); }
        }
        foreach ((array) ($in['areas'] ?? []) as $k => $lvl) {
            if (! isset(self::AREAS[$k]) || ! in_array($lvl, ['edit', 'view', 'hidden'], true)) continue;
            $fresh = DB::table('t_users')->where('id', $userId)->first(); [$base, $key] = Perm::rolesOf($fresh); [$all, $view] = self::areaCodes($k);
            foreach ($all as $c) { $on = $lvl === 'edit' || ($lvl === 'view' && in_array($c, $view, true)); $role = Perm::roleCan($base, $key, $c);
                Perm::setUser($userId, $c, $role === $on ? null : ($on ? 'GRANTED' : 'REVOKED'), 'Access card: ' . self::AREAS[$k][0] . ' – ' . $lvl, null, (int) $by->id); }
        }
        return collect(self::cards((int) Context::ent())['cards'])->firstWhere('id', $userId) ?? [];
    }
}
