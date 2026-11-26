<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * R-FIN-1 the finance role of a login (one per user, first match wins: OWNER → PARTNER (staff) → FINANCE_VA → ACCOUNTANT → PARTNER_ACCOUNT → NONE);
 * the matrix itself is data: the fin.* action names are rights (Perm aliases onto the screen rights, editable on the Rights screen, history kept).
 * R-FIN-2 a PARTNER_ACCOUNT login (a partner login linked to its own partner record) may use only its own pages: any other API answers 403
 * "Partner Account: this page is not available." and the screen goes back to My Partner Account; each role lands on its own first page.
 */
class FinanceRoles
{
    public const WHITELIST = ['api/boot', 'api/me', 'api/me/*', 'api/nav', 'api/bell', 'api/bell/*', 'api/prefs/*', 'api/home', 'api/my-account', 'api/my-account/*', 'api/statements/*/pdf', 'api/statements/*/answer',
        'api/capital-calls', 'api/capital-calls/answer/*', 'api/portal/*', 'api/my-invoices', 'api/my-invoices/*', 'api/find/opened', 'logout'];

    public static function partnerOf(object $u): ?int { return ($p = DB::table('fin_partner_users')->where('user_id', $u->id)->value('investor_id')) ? (int) $p : null; }

    public static function role(object $u): string
    {
        $r = (string) $u->role_code;
        if ($r === 'OWNER') return 'OWNER';
        if ($r === 'PARTNER') return self::partnerOf($u) ? 'PARTNER_ACCOUNT' : 'PARTNER';
        if ($r === 'FINANCE') return 'FINANCE_VA';
        if ($r === 'ACCOUNTANT') return 'ACCOUNTANT';
        return 'NONE';
    }

    public static function locked(object $u): bool { return self::role($u) === 'PARTNER_ACCOUNT'; }

    public static function allowed(Request $r, object $u): bool
    {
        foreach (self::WHITELIST as $p) if ($r->is($p)) return true;
        $own = self::partnerOf($u);
        return $r->is('api/partners') || ($own && ($r->is("api/partners/$own") || $r->is("api/partners/$own/withdraw") || $r->is("api/partners/$own/reinvest")));      // the partner list answers only himself; his own page, withdrawal and reinvest
    }

    /** First page after login per finance role. */
    public static function landing(object $u): ?string
    {
        return match (self::role($u)) { 'PARTNER_ACCOUNT' => '#/my-account', 'ACCOUNTANT' => '#/ledger', 'FINANCE_VA' => '#/bank', default => null };
    }
}
