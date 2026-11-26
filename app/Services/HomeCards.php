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

    /** $company = true: the card is about one company's figures (added up per company in All companies mode, R-G_CORE-102). */
    public static function register(array $roles, callable $fn, bool $company = false): void
    {
        self::$cards[] = ['roles' => $roles, 'fn' => $fn, 'company' => $company];
    }

    public static function for(object $user, int $ent): array
    {
        self::core();
        $out = [];
        $mine = array_values(array_filter(self::$cards, fn ($c) => $c['roles'] === ['*'] || in_array($user->role_code, $c['roles'], true)));
        $one = function ($c, int $e) use ($user) { try { return ($c['fn'])($e, $user) ?: null; } catch (\Throwable $x) { report($x); return null; } };
        if (! Context::all()) { foreach ($mine as $c) if ($card = $one($c, $ent)) $out[] = $card; return $out; }
        /* R-G_CORE-102 All companies: a company card is worked out per company – a line per company and the sum; a card about the person (tasks, approvals, health) once */
        $per = AllCompanies::each($user, fn ($e) => array_map(fn ($c) => $c['company'] ? $one($c, $e) : null, $mine));
        foreach ($mine as $i => $c) {
            if (! $c['company']) { if ($card = $one($c, $ent)) $out[] = $card; continue; }
            $lines = []; $sum = 0.0; $num = true; $dec = false; $first = null; $miss = [];
            foreach ($per as [$co, $cards]) { $k = $cards[$i] ?? null; if (! $k) continue; $first ??= $k;
                $raw = str_replace(',', '', (string) $k['value']); $isNum = is_numeric($raw); $num = $num && $isNum; $dec = $dec || str_contains((string) $k['value'], '.');
                $f = $isNum && str_contains((string) $k['value'], '.') ? AllCompanies::factor((int) $co->id, $ent, date('Y-m-d')) : 1.0;      // money cards (2 decimals) in the home base currency
                if ($f === null) { $miss[] = $co->name; $lines[] = ['company' => $co->name, 'value' => 'rate missing', 'foot' => $k['foot'] ?? '']; continue; }
                if ($isNum) $sum += (float) $raw * $f;
                $lines[] = ['company' => $co->name, 'value' => $k['value'], 'foot' => $k['foot'] ?? '', 'go' => $k['go'] ?? null]; }
            if (! $first) continue;
            $vals = array_values(array_unique(array_column($lines, 'value')));
            $first['value'] = $num ? ($dec ? number_format($sum, 2) : (string) (int) round($sum)) : (count($vals) === 1 ? $vals[0] : 'See each company');
            foreach ($per as [, $cards]) if (($cards[$i]['tone'] ?? '') === 'err') $first['tone'] = 'err'; elseif (($cards[$i]['tone'] ?? '') === 'warn' && ($first['tone'] ?? '') !== 'err') $first['tone'] = 'warn';
            $first['foot'] = count($lines) . ' companies added up' . ($miss ? ' – rate missing for ' . implode(', ', $miss) . ' (not in the total)' : '');
            $first['lines'] = $lines; $out[] = $first;
        }
        return $out;
    }

    private static bool $coreDone = false;

    private static function core(): void
    {
        if (self::$coreDone) return;
        self::$coreDone = true;
        $open = fn () => DB::table('tasks')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED']);
        self::register(['OWNER', 'MANAGER', 'FINANCE', 'PARTNER', 'TL'], function ($ent, $u) {      // R-G_CORE-102 sales of the month = the Sales list over every marketplace
            if (! Perm::has($u, 'orders.MONEY')) return null;
            $q = SaleLines::query(['any_marketplace' => 1, 'from' => date('Y-m-01'), 'to' => date('Y-m-t')], $u)->whereNotIn('s.status', ['Cancelled']);
            $v = (float) (clone $q)->sum('s.total_amount'); $n = (clone $q)->count();
            return ['label' => 'Sales this month', 'value' => number_format($v, 2), 'foot' => number_format($n) . ' sale line(s) in ' . date('F Y'), 'tone' => '', 'go' => '#/sales?from=' . date('Y-m-01') . '&to=' . date('Y-m-t')];
        }, true);
        self::register(['*'], function ($ent, $u) {      // R-G_CORE-36: the same groups as My tasks feed Home "Needs action"
            $d = TaskLists::mine($u)['tiles']; $need = $d['late'] + $d['due_today'] + $d['waiting_on_me'];
            return ['label' => 'My tasks', 'value' => $d['open'], 'foot' => $need ? "needs action: {$d['late']} late · {$d['due_today']} due today · {$d['waiting_on_me']} waiting on me" : 'nothing needs action',
                'tone' => $d['late'] ? 'err' : ($need ? 'warn' : 'done'), 'go' => '#/tasks'];
        });
        self::register(['OWNER', 'PARTNER', 'MANAGER', 'TL'], function ($ent) {      // R-G_CORE-16: who is away today
            $today = date('Y-m-d');
            $who = DB::table('user_absence as a')->leftJoin('t_account as p', 'p.id', '=', 'a.user_id')->leftJoin('t_account as c', 'c.id', '=', 'a.cover_user_id')->where('a.from_date', '<=', $today)
                ->where(fn ($w) => $w->whereNull('a.to_date')->orWhere('a.to_date', '>=', $today))->get([DB::raw("TRIM(CONCAT_WS(' ', p.first_name, p.sur_name)) as who"), DB::raw("TRIM(CONCAT_WS(' ', c.first_name, c.sur_name)) as cover")]);
            return ['label' => 'Who is away today', 'value' => $who->count(), 'foot' => $who->count() ? $who->map(fn ($x) => $x->who . ($x->cover ? ' (cover ' . $x->cover . ')' : ''))->implode(', ') : 'everyone is in', 'tone' => $who->count() ? 'warn' : 'done', 'go' => '#/tasks/calendar'];      // the HR calendar (R-G_CORE-16)
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
        self::register(['OWNER', 'MANAGER', 'SOURCING', 'FINANCE'], function ($ent) {
            $d = BuyingService::dashboard($ent);
            return ['label' => 'Supplier payments due', 'value' => $d['pay_due_count'], 'foot' => number_format($d['pay_due_base'], 2) . ' open on confirmed POs', 'tone' => $d['pay_due_count'] ? 'err' : 'done', 'go' => '#/buying'];
        }, true);
        self::register(['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE'], function ($ent) {
            $d = BuyingService::dashboard($ent);
            return ['label' => 'Shipments on the way', 'value' => $d['in_transit'], 'foot' => $d['overdue'] ? "{$d['overdue']} overdue" : ($d['grn_to_post'] ? "{$d['grn_to_post']} GRN to post" : 'none overdue'), 'tone' => $d['overdue'] ? 'err' : ($d['in_transit'] ? 'prog' : 'done'), 'go' => '#/buying'];
        }, true);
        self::register(['OWNER', 'MANAGER', 'FINANCE', 'PARTNER'], function ($ent) {
            $v = StockService::value($ent);
            $units = (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->sum('b.qty');
            return ['label' => 'Stock value (at cost)', 'value' => number_format($v, 2), 'foot' => number_format($units) . ' units in stock', 'tone' => '', 'go' => '#/stock'];
        }, true);
        self::register(['OWNER', 'MANAGER', 'WAREHOUSE', 'SOURCING'], function ($ent) {
            $n = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_alert_min_level', 1)->where('min_level', '>', 0)->whereColumn('stock', '<=', 'min_level')->count();
            return ['label' => 'Low stock', 'value' => $n, 'foot' => $n ? 'products at or under their minimum' : 'every product above its minimum', 'tone' => $n ? 'warn' : 'done', 'go' => '#/stock?low=1'];
        }, true);
        self::register(['OWNER', 'MANAGER', 'SOURCING', 'STOCK', 'PARTNER'], function ($ent, $u) {      // MR-0031 ICC on Home: products to order now / this week
            if (! Perm::has($u, 'stock.SEE') || ! Features::on('ICC', $ent)) return null;
            $c = Icc::board($ent)['counts']; $n = $c['order_now'] + $c['this_week'];
            return ['label' => 'Stock cover (ICC)', 'value' => $n, 'foot' => "order now {$c['order_now']} · this week {$c['this_week']} · covered {$c['covered']}", 'tone' => $c['order_now'] ? 'err' : ($n ? 'warn' : 'done'), 'go' => $c['order_now'] ? '#/icc?status=RED' : '#/icc'];
        }, true);
        self::register(['OWNER', 'MANAGER', 'FINANCE'], function ($ent) {      // MR-1538 data health score per company
            if (! Features::on('DATA_HEALTH', $ent)) return null;
            $h = DataHealth::score($ent); $w = $h['parts'][0];
            return ['label' => 'Data health', 'value' => $h['score'] . ' %', 'foot' => $w['score'] < 100 ? 'weakest: ' . $w['text'] : 'costs, bank, imports, duplicates and records all clean', 'tone' => $h['tone'], 'go' => $w['score'] < 100 ? $w['go'] : '#/data-quality'];
        }, true);
        self::register(['OWNER', 'FINANCE', 'ACCOUNTANT'], function ($ent) {
            $tb = Ledger::trialBalance($ent);
            return ['label' => 'Ledger', 'value' => $tb['balanced'] ? 'Balanced' : 'NOT balanced', 'foot' => 'debits ' . number_format($tb['debit'], 2) . ' = credits ' . number_format($tb['credit'], 2), 'tone' => $tb['balanced'] ? 'done' : 'err', 'go' => '#/ledger'];
        }, true);
    }

    public static function reset(): void
    {
        self::$cards = [];
        self::$coreDone = false;
    }
}
