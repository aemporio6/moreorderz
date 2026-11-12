<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Home screen cards. Each module registers its cards with the roles that see them; a card is a number, a short
 * line and where a click goes. Colour comes from the data: err (act now), warn (soon), done (all good).
 */
class HomeCards
{
    /** list of ['roles' => [...], 'fn' => callable(int $ent, object $user): ?array] */
    private static array $cards = [];

    public static function register(array $roles, callable $fn): void
    {
        self::$cards[] = ['roles' => $roles, 'fn' => $fn];
    }

    public static function for(object $user, int $ent): array
    {
        self::core();
        $out = [];
        foreach (self::$cards as $c) {
            if ($c['roles'] !== ['*'] && ! in_array($user->role_code, $c['roles'], true)) continue;
            try { if ($card = ($c['fn'])($ent, $user)) $out[] = $card; } catch (\Throwable $e) { report($e); }
        }
        return $out;
    }

    private static bool $coreDone = false;

    private static function core(): void
    {
        if (self::$coreDone) return;
        self::$coreDone = true;
        $open = fn () => DB::table('tasks')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED']);
        self::register(['*'], function ($ent, $u) use ($open) {
            $n = $open()->where('owner_user_id', $u->id)->count();
            $late = $open()->where('owner_user_id', $u->id)->where('due_at', '<', date('Y-m-d'))->count();
            return ['label' => 'My tasks', 'value' => $n, 'foot' => $late ? "$late late" : 'none late', 'tone' => $late ? 'err' : ($n ? 'warn' : 'done'), 'go' => '#/tasks'];
        });
        self::register(['OWNER', 'MANAGER', 'TL'], function ($ent) use ($open) {
            $q = fn () => $open()->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0));
            $n = $q()->count(); $urgent = $q()->where('priority', 'URGENT')->count();
            return ['label' => 'Needs action (everyone)', 'value' => $n, 'foot' => $urgent ? "$urgent urgent" : 'no urgent task', 'tone' => $urgent ? 'err' : ($n ? 'warn' : 'done'), 'go' => '#/tasks?scope=all'];
        });
        self::register(['OWNER', 'MANAGER', 'FINANCE'], function ($ent) {
            $n = DB::table('sys_approvals')->where('status', 'PENDING')->count();
            return ['label' => 'Waiting for approval', 'value' => $n, 'foot' => 'money and stock changes above the limit', 'tone' => $n ? 'warn' : 'done', 'go' => '#/list/sys_approvals'];
        });
        self::register(['OWNER'], function ($ent) {
            $n = DB::table('sys_events')->where('status', 'FAILED')->count();
            return ['label' => 'System health', 'value' => $n ? "$n failed" : 'OK', 'foot' => $n ? 'events waiting for a retry' : 'all events processed', 'tone' => $n ? 'err' : 'done', 'go' => '#/health'];
        });
        self::register(['OWNER', 'MANAGER', 'FINANCE', 'PARTNER'], function ($ent) {
            $v = StockService::value($ent);
            $units = (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->sum('b.qty');
            return ['label' => 'Stock value (at cost)', 'value' => number_format($v, 2), 'foot' => number_format($units) . ' units in stock', 'tone' => '', 'go' => '#/stock'];
        });
        self::register(['OWNER', 'MANAGER', 'WAREHOUSE', 'SOURCING'], function ($ent) {
            $n = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_alert_min_level', 1)->where('min_level', '>', 0)->whereColumn('stock', '<=', 'min_level')->count();
            return ['label' => 'Low stock', 'value' => $n, 'foot' => $n ? 'products at or under their minimum' : 'every product above its minimum', 'tone' => $n ? 'warn' : 'done', 'go' => '#/stock?low=1'];
        });
        self::register(['OWNER', 'FINANCE', 'ACCOUNTANT'], function ($ent) {
            $tb = Ledger::trialBalance($ent);
            return ['label' => 'Ledger', 'value' => $tb['balanced'] ? 'Balanced' : 'NOT balanced', 'foot' => 'debits ' . number_format($tb['debit'], 2) . ' = credits ' . number_format($tb['credit'], 2), 'tone' => $tb['balanced'] ? 'done' : 'err', 'go' => '#/ledger'];
        });
    }

    public static function reset(): void
    {
        self::$cards = [];
        self::$coreDone = false;
    }
}
