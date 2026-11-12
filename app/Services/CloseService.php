<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Month close (R-FIN-45 … 47). The month is shop × calendar month. 17 checks in a fixed order; each is GREEN, RED, WAIVED or N/A.
 * OPEN → CHECKED (nothing RED) → LOCKED (Owner, with a one-line note). A LOCKED month is never reopened: corrections go forward.
 * When every shop that has activity in a month is LOCKED, the ledger is locked up to that month too (GL_LOCKED_UPTO).
 */
class CloseService
{
    /** code → [title, waivable] in run order */
    public const CHECKS = [
        'FILES_PRESENT' => ['The marketplace money file for the month is loaded', false], 'SETTLEMENT_COMPLETE' => ['Every statement of the month has all its rows', false],
        'ORDERS_VS_TXN' => ['Every order has a money line and every money line an order', true], 'HUB_TOTALS' => ['Statements together = the marketplace\'s own period total', true],
        'PAYOUT_VS_BANK' => ['Every payout of the month is matched to a bank line', true], 'BANK_ALL_CATEGORISED' => ['No bank line of the month is left unmatched', false],
        'PURCHASE_VS_SUPPLIER' => ['Purchases match supplier proof', true], 'SALES_CONFIRMED' => ['All sale lines of the month are confirmed', false],
        'COST_PRESENT' => ['Every sold unit has a cost', false], 'FX_PRESENT' => ['Every amount has an exchange rate', false], 'EXPENSES' => ['Every expense is given to a shop or a shared rule', true],
        'STOCK_ROLL' => ['Stock adds up: products = lots = movements, and stock value = the ledger', false], 'SHARES_LE_100' => ['Partner shares are not over 100 %', false],
        'SPLIT_RULES' => ['A profit split rule is valid for the month', false], 'OPENING_LOCKED' => ['Opening balances are locked', false], 'PREVIOUS_LOCKED' => ['Earlier months of the shop are locked', false],
        'ONE_PROFIT_ROUTE' => ['The shop-month has exactly one profit route', false],
    ];

    private static function shop(int $ent, int $branchId): object
    {
        return DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose a shop of this company.');
    }

    private static function month(string $m): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new \InvalidArgumentException('Choose a month.');
        return [$m . '-01', date('Y-m-t', strtotime($m . '-01'))];
    }

    public static function run(int $ent, int $branchId, string $month): ?object
    {
        return DB::table('aud_runs')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('period_month', $month)->where('status', '<>', 'SUPERSEDED')->orderByDesc('version')->first();
    }

    /** Has this shop anything in this month (sale lines or statements)? */
    public static function hasActivity(int $ent, int $branchId, string $from, string $to): bool
    {
        return DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->whereBetween('d_order', [$from, $to])->exists()
            || DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereBetween('statement_date', [$from, $to])->exists();
    }

    /** Work out the 17 checks now. Nothing is saved. Each: code, title, status, detail, open_items, fix_url, waivable. */
    public static function evaluate(int $ent, int $branchId, string $month): array
    {
        $shop = self::shop($ent, $branchId); [$from, $to] = self::month($month); $out = [];
        $set = function (string $code, string $status, string $detail, int $open = 0, ?string $fix = null) use (&$out) { $out[$code] = ['code' => $code, 'title' => self::CHECKS[$code][0], 'waivable' => self::CHECKS[$code][1], 'status' => $status, 'detail' => mb_substr($detail, 0, 480), 'open_items' => $open, 'fix_url' => $fix]; };
        $later = 'Not checked yet – this part of the tool is built in a later step.';
        $hasFiles = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->exists();
        $sts = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereBetween('statement_date', [$from, $to])->get();
        $periods = DB::table('mo_settlement_periods')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('period_from', '<=', $to)->where('period_to', '>=', $from)->get();

        // 1 – 5: the marketplace's money file
        if (! $hasFiles) {
            foreach (['FILES_PRESENT', 'SETTLEMENT_COMPLETE', 'ORDERS_VS_TXN', 'HUB_TOTALS', 'PAYOUT_VS_BANK'] as $c) $set($c, 'NA', 'No money file has ever been loaded for this shop, so this check does not apply.');
        } else {
            // the shop's very first money file may start inside a month: that month counts from the file's first day
            $first = DB::table('mo_settlement_periods')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->min('period_from'); $start = Choices::get('CLOSE_FIRST_FILE_PARTIAL', $ent) === '1' && $first && $first > $from && $first <= $to ? $first : $from;
            $covered = $periods->contains(fn ($p) => $p->period_from <= $start && $p->period_to >= min($to, date('Y-m-d')));
            $set('FILES_PRESENT', $covered ? 'GREEN' : 'RED', $covered ? ($start > $from ? "A money file covers the month from $start, the day this shop's first file starts." : 'A money file covering the whole month is loaded.') : 'No loaded money file covers the whole month (' . ($periods->isEmpty() ? 'none touches it' : $periods->map(fn ($p) => "{$p->period_from} – {$p->period_to}")->implode(', ')) . ').', $covered ? 0 : 1, '#/settlements?shop=' . $branchId);
            $part = $sts->where('f_rows_complete', 0)->count(); $unbooked = $sts->where('status', 'LOADED')->count();
            $set('SETTLEMENT_COMPLETE', $part || $unbooked ? 'RED' : 'GREEN', $part || $unbooked ? trim(($part ? "$part statement(s) without all their rows. " : '') . ($unbooked ? "$unbooked statement(s) loaded but not booked." : '')) : $sts->count() . ' statement(s), all complete and booked.', $part + $unbooked, '#/settlements?shop=' . $branchId);
            $orders = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->whereBetween('d_order', [$from, $to])->distinct()->pluck('order_no');
            $moneyOrders = DB::table('fin_settlement_lines')->where('company_id', $ent)->where('branch_id', $branchId)->whereIn('settlement_id', $sts->pluck('statement_id'))->where('transaction_type', 'Order')->distinct()->pluck('order_id');
            $allMoney = DB::table('fin_settlement_lines')->where('company_id', $ent)->where('branch_id', $branchId)->whereIn('order_id', $orders)->distinct()->pluck('order_id');
            $noMoney = $orders->diff($allMoney)->count(); $noOrder = $moneyOrders->diff(DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereIn('order_no', $moneyOrders)->distinct()->pluck('order_no'))->count();
            $set('ORDERS_VS_TXN', $noMoney || $noOrder ? 'RED' : 'GREEN', $noMoney || $noOrder ? trim(($noMoney ? "$noMoney order(s) of the month have no line in any money file. " : '') . ($noOrder ? "$noOrder order(s) in the money file are not in the tool." : '')) : 'Orders and money lines agree.', $noMoney + $noOrder, '#/settlements?shop=' . $branchId);
            $hubBad = $periods->where('hub_check', '<>', 'OK')->count();
            $set('HUB_TOTALS', $periods->isEmpty() ? 'RED' : ($hubBad ? 'RED' : 'GREEN'), $periods->isEmpty() ? 'No money file touches this month.' : ($hubBad ? "$hubBad file(s): the statements do not add up to the marketplace's period total (statements missing)." : 'Statements add up to the marketplace\'s period total.'), $periods->isEmpty() ? 1 : $hubBad, '#/settlements?shop=' . $branchId);
            $unpaid = $sts->filter(fn ($s) => $s->status !== 'PAID' && (float) $s->payable != 0.0)->count();
            $set('PAYOUT_VS_BANK', $unpaid ? 'RED' : 'GREEN', $unpaid ? "$unpaid statement(s) with a payout not matched to a bank line yet." : 'Every payout is matched to the bank.', $unpaid, '#/bank');
        }
        // 6
        $un = DB::table('fin_bank_transactions')->where('company_id', $ent)->where('match_status', 'UNMATCHED')->whereBetween('txn_date', [$from, $to])->count();
        $set('BANK_ALL_CATEGORISED', $un ? 'RED' : 'GREEN', $un ? "$un bank line(s) dated in the month are not matched." : 'No unmatched bank line in the month.', $un, '#/bank');
        $set('PURCHASE_VS_SUPPLIER', 'NA', $later);
        // 8 – 10
        $pend = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->where('status', 'Pending')->whereBetween('d_order', [$from, $to])->count();
        $unm = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'unmatched')->where('status', 'Pending')->whereBetween('d_order', [$from, $to])->count();
        $set('SALES_CONFIRMED', $pend || $unm ? 'RED' : 'GREEN', $pend || $unm ? trim(($pend ? "$pend sale line(s) not confirmed. " : '') . ($unm ? "$unm line(s) with a SKU the tool does not know." : '')) : 'All sale lines are confirmed.', $pend + $unm, '#/confirm');
        $sold = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', 'Confirmed')->whereBetween('d_order', [$from, $to])->pluck('id');
        $noCost = DB::table('fin_lot_consumption')->whereIn('fk_id_sale', $sold)->where('qty', '>', 0)->where(fn ($w) => $w->whereNull('unit_cost_gbp')->orWhere('unit_cost_gbp', '<=', 0))->count()
            + DB::table('t_sales as s')->whereIn('s.id', $sold)->where('s.fulfilment_channel', 'SELF')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_lot_consumption as c')->whereColumn('c.fk_id_sale', 's.id'))->count();
        $set('COST_PRESENT', $noCost ? 'RED' : 'GREEN', $noCost ? "$noCost sold line(s) without a cost (lot with no cost yet, or no lot used)." : 'Every sold unit has a cost.', $noCost, '#/stock/lots');
        $noFx = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereIn('status', ['Pending', 'Confirmed'])->whereBetween('d_order', [$from, $to])->whereNull('total_amount_gbp')->count();
        $set('FX_PRESENT', $noFx ? 'RED' : 'GREEN', $noFx ? "$noFx sale line(s) without an amount in the base currency." : 'Every amount has a rate.', $noFx, '#/fx');
        $noShop = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('fk_id_branch')->whereBetween('d_expense', [$from, $to])->selectRaw('COUNT(*) n, COALESCE(SUM(amount_gbp),0) v')->first();
        $set('EXPENSES', 'GREEN', (int) $noShop->n ? "{$noShop->n} expense(s) without a shop (" . number_format((float) $noShop->v, 2) . ') are shared between the shops by their confirmed sales of the month.' : 'Every expense of the month belongs to a shop.');
        // 12 stock (company-wide)
        $bad = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->selectRaw('p.id, p.stock cached, (SELECT COALESCE(SUM(b.qty),0) FROM fin_lot_balances b JOIN fin_lots l ON l.id=b.fk_id_lot WHERE l.fk_id_product=p.id) units')->havingRaw('cached <> units')->get()->count();
        $value = StockService::value($ent); $gl = Ledger::balance($ent, '1200'); $tb = Ledger::trialBalance($ent); $vOk = abs($value - $gl) < 0.005; $tOk = abs($tb['debit'] - $tb['credit']) < 0.005;
        $set('STOCK_ROLL', $bad || ! $vOk || ! $tOk ? 'RED' : 'GREEN', $bad || ! $vOk || ! $tOk ? trim(($bad ? "$bad product(s) where the stock figure differs from its lots. " : '') . ($vOk ? '' : 'Stock value ' . number_format($value, 2) . ' ≠ ledger ' . number_format($gl, 2) . '. ') . ($tOk ? '' : 'The trial balance does not balance.'))
            : 'Products = lots, stock value ' . number_format($value, 2) . ' = ledger, trial balance balanced.', $bad + ($vOk ? 0 : 1) + ($tOk ? 0 : 1), '#/stock');
        $over = array_filter(PartnerService::scopeTotals($ent, $from), fn ($t) => $t > 100.0001);
        $set('SHARES_LE_100', $over ? 'RED' : 'GREEN', $over ? implode(' · ', array_map(fn ($k, $t) => PartnerService::scopeName($ent, $k) . ' = ' . rtrim(rtrim(number_format($t, 3), '0'), '.') . '%', array_keys($over), $over)) . '. End or change an agreement.' : 'No scope is over 100 %.', count($over), '#/partners');
        $everAg = DB::table('fin_investor_agreements')->where('company_id', $ent)->exists(); $sh = $everAg ? PartnerService::monthShares($ent, $branchId, $month) : null;
        $inv = $sh && $sh['route'] === 'INVESTOR_INVOICING' && $sh['split'];
        if ($inv) $set('SPLIT_RULES', 'GREEN', 'Route "investor invoicing": the four-way split rule of this shop is used.', 0, '#/waterfall');
        else $set('SPLIT_RULES', $everAg && ! $sh['lines'] ? 'RED' : 'GREEN', ! $everAg ? 'The company has no partner agreement: all profit stays with the company.' : ($sh['lines'] ? count($sh['lines']) . ' agreement(s) share this month.' : 'The company has partner agreements but none covers this shop on the first day of the month.'), $everAg && ! $sh['lines'] ? 1 : 0, '#/partners');
        $set('OPENING_LOCKED', 'NA', 'Opening balances come with the old-data import (Step 7).');
        // 16 earlier months of this shop
        $first = min(array_filter([DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->min('d_order'), DB::table('mo_settlements')->where('fk_id_branch', $branchId)->min('statement_date'), $from]));
        $open = [];
        for ($m = substr($first, 0, 7); $m < $month; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            if (! self::hasActivity($ent, $branchId, $m . '-01', date('Y-m-t', strtotime($m . '-01')))) continue;
            if ((self::run($ent, $branchId, $m)->status ?? 'OPEN') !== 'LOCKED') $open[] = date('M Y', strtotime($m . '-01'));
        }
        $set('PREVIOUS_LOCKED', $open ? 'RED' : 'GREEN', $open ? 'Not locked yet: ' . implode(', ', array_slice($open, 0, 6)) . (count($open) > 6 ? ' …' : '') . '. Lock the earlier months first.' : 'No earlier month of this shop is open.', count($open), '#/close?shop=' . $branchId);
        // 17
        $routes = DB::table('t_branch_profit_routes')->where('fk_id_company', $ent)->where('fk_id_branch', $branchId)->where('d_start', '<=', $to)->where(fn ($w) => $w->whereNull('d_end')->orWhere('d_end', '>=', $from))->pluck('profit_route');
        $def = (string) Settings::get('DEFAULT_PROFIT_ROUTE', $ent, 'LOT_PROFIT');
        $set('ONE_PROFIT_ROUTE', $routes->count() > 1 ? 'RED' : 'GREEN', $routes->count() > 1 ? 'More than one profit route is valid in this month: ' . $routes->implode(', ') . '.' : ($routes->count() === 1 ? "Route {$routes[0]}." : "No route row for this shop – the company default $def applies."), $routes->count() > 1 ? 1 : 0);
        $ordered = []; foreach (array_keys(self::CHECKS) as $c) $ordered[] = $out[$c];
        return ['checks' => $ordered, 'route' => $routes->count() === 1 ? $routes[0] : $def, 'shop' => $shop->shop_name, 'from' => $from, 'to' => $to];
    }

    /** The month's figures (from the same profit view the screens use). */
    public static function figures(int $ent, int $branchId, string $month): array
    {
        [$from, $to] = self::month($month);
        $v = ProfitService::view($ent, 'shop', $from, $to); $r = collect($v['rows'])->firstWhere('key', 'S' . $branchId) ?? ['units' => 0, 'sales' => 0.0, 'cost' => 0.0, 'mp_costs' => 0.0, 'expenses' => 0.0, 'gross' => 0.0, 'profit' => 0.0];
        $st = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereBetween('statement_date', [$from, $to])->selectRaw('COALESCE(SUM(net_sales),0) n, COALESCE(SUM(fees),0) f, COALESCE(SUM(shipping),0) s, COALESCE(SUM(adjustments),0) a, COALESCE(SUM(payable),0) p, COUNT(*) c')->first();
        // overheads (expenses not given to a shop) are shared between the shops by their confirmed sales of the month (R-FIN-40 "fixed share")
        $over = (float) (collect($v['rows'])->firstWhere('key', 'ZZ')['expenses'] ?? 0); $all = (float) $v['total']['sales'];
        $share = $over > 0 && $all > 0 ? round($over * (float) $r['sales'] / $all, 2) : 0.0; $after = Choices::get('PROFIT_SHARE_AFTER_OVERHEADS', $ent) === '1';
        return ['units' => (int) $r['units'], 'sales' => (float) $r['sales'], 'cost_of_goods' => (float) $r['cost'], 'gross' => (float) $r['gross'], 'marketplace_net' => (float) $r['mp_costs'], 'shop_expenses' => (float) $r['expenses'], 'profit' => (float) $r['profit'],
            'overheads_company' => round($over, 2), 'overhead_share' => $share, 'sales_share_pct' => $all > 0 ? round((float) $r['sales'] / $all * 100, 2) : 0.0, 'profit_after_overheads' => round((float) $r['profit'] - $share, 2), 'profit_to_share' => round((float) $r['profit'] - ($after ? $share : 0), 2), 'share_after_overheads' => $after,
            'statements' => (int) $st->c, 'settled_net_sales' => round((float) $st->n, 2), 'settled_fees' => round((float) $st->f, 2), 'settled_shipping' => round((float) $st->s, 2), 'settled_adjustments' => round((float) $st->a, 2), 'payouts' => round((float) $st->p, 2), 'base' => Fx::base($ent)];
    }

    /** Run the checks and save the result on the month. Returns the page data. */
    public static function check(int $ent, int $branchId, string $month): array
    {
        return DB::transaction(function () use ($ent, $branchId, $month) {
            $run = self::run($ent, $branchId, $month);
            if ($run && $run->status === 'LOCKED') return self::page($ent, $branchId, $month);
            $e = self::evaluate($ent, $branchId, $month); $fig = self::figures($ent, $branchId, $month);
            if (! $run) {
                $id = DB::table('aud_runs')->insertGetId(RecordService::fillNotNull('aud_runs', ['run_code' => Numbering::next('CLS', $ent), 'fk_id_ent' => $ent, 'fk_id_branch' => $branchId, 'period_month' => $month, 'version' => 1, 'status' => 'OPEN', 'created_at' => now()]));
                $run = DB::table('aud_runs')->where('id', $id)->first();
            }
            $waived = DB::table('aud_check_waivers')->where('fk_id_run', $run->id)->get()->keyBy('check_code'); $red = 0; $n = 0;
            foreach ($e['checks'] as $c) {
                $status = $c['status']; $w = $waived[$c['code']] ?? null;
                if ($status === 'RED' && $w && $c['waivable']) $status = 'WAIVED';
                if ($status === 'RED') $red++; if ($status !== 'NA') $n++;
                DB::table('aud_checks')->updateOrInsert(['fk_id_run' => $run->id, 'check_code' => $c['code']], ['status' => $status, 'detail' => $c['detail'], 'open_items' => $c['open_items'], 'fix_url' => $c['fix_url'], 'waived_by' => $status === 'WAIVED' ? $w->waived_by : null, 'waive_reason' => $status === 'WAIVED' ? mb_substr($w->reason, 0, 255) : null]);
            }
            $new = $red ? 'OPEN' : 'CHECKED';
            DB::table('aud_runs')->where('id', $run->id)->update(['status' => $new, 'score' => $n ? (int) round(($n - $red) / $n * 100) : 100, 'profit_route' => $e['route'], 'gross_sales' => $fig['sales'], 'cost_of_goods' => $fig['cost_of_goods'], 'fees' => $fig['marketplace_net'], 'direct_expenses' => $fig['shop_expenses'], 'net_profit' => $fig['profit'], 'checked_at' => now()]);
            if ($new === 'CHECKED' && $run->status !== 'CHECKED') EventBus::fire('MONTH_CHECKED', 'aud_runs', $run->id, ['shop' => $branchId, 'month' => $month], $ent);
            if ($red) TaskEngine::open('FIN_CLOSE_CHECK', [$run->id], "$red close check(s) are red for {$e['shop']} " . date('M Y', strtotime($month . '-01')), ['ent' => $ent, 'module' => 'aud_runs', 'record_id' => $run->id, 'fix_url' => "#/close?shop=$branchId&month=$month"]);
            else TaskEngine::close('FIN_CLOSE_CHECK', [$run->id], 'All checks pass');
            return self::page($ent, $branchId, $month);
        });
    }

    /** Owner only: accept a red check for this month with a reason (only the checks marked as waivable). */
    public static function waive(int $ent, int $branchId, string $month, string $code, string $reason): void
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner can waive a check.');
        if (! (self::CHECKS[$code][1] ?? false)) throw new \InvalidArgumentException('This check cannot be waived – it has to be fixed.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for the waiver (it is shown on the month and its statements).');
        $run = self::run($ent, $branchId, $month) ?? throw new \InvalidArgumentException('Run the checks first.');
        if ($run->status === 'LOCKED') throw new \InvalidArgumentException('This month is already LOCKED.');
        if (DB::table('aud_checks')->where('fk_id_run', $run->id)->where('check_code', $code)->value('status') !== 'RED') throw new \InvalidArgumentException('Only a red check can be waived.');
        DB::table('aud_check_waivers')->updateOrInsert(['fk_id_run' => $run->id, 'check_code' => $code], ['reason' => $reason, 'waived_by' => Context::userId(), 'waived_at' => now()]);
        Audit::log('aud_runs', $run->id, 'APPROVE', ['waived' => $code], $reason, $ent);
        self::check($ent, $branchId, $month);
    }

    /** Lock the month. Owner only, note mandatory, nothing RED. The figures are frozen; when all shops of the month are locked the ledger locks too. */
    public static function lock(int $ent, int $branchId, string $month, string $note): array
    {
        return DB::transaction(function () use ($ent, $branchId, $month, $note) {
            if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Refused: only the Owner closes a period.');
            [$from, $to] = self::month($month);
            if ($to >= date('Y-m-d')) throw new \InvalidArgumentException('This month has not ended yet.');
            $old = self::run($ent, $branchId, $month);
            if ($old && $old->status === 'LOCKED') throw new \InvalidArgumentException('This period is already LOCKED.');
            if (trim($note) === '') throw new \InvalidArgumentException('Write the one-line owner note first (it is printed on every statement).');
            $page = self::check($ent, $branchId, $month); $red = array_values(array_filter($page['checks'], fn ($c) => $c['status'] === 'RED'));
            if ($red) throw new \InvalidArgumentException('Cannot close: ' . count($red) . ' check(s) fail - ' . implode(' | ', array_map(fn ($c) => $c['title'] . ': ' . $c['detail'], array_slice($red, 0, 4))));
            $run = self::run($ent, $branchId, $month); $fig = self::figures($ent, $branchId, $month);
            $snap = ['figures' => $fig, 'checks' => array_map(fn ($c) => ['code' => $c['code'], 'status' => $c['status'], 'detail' => $c['detail'], 'waive_reason' => $c['waive_reason'] ?? null], $page['checks']), 'locked_at' => now()->toDateTimeString(), 'route' => $run->profit_route];
            DB::table('aud_runs')->where('id', $run->id)->update(['status' => 'LOCKED', 'locked_by' => Context::userId(), 'locked_at' => now(), 'owner_note' => mb_substr(trim($note), 0, 255), 'snapshot_json' => json_encode($snap)]);
            $shares = PartnerService::postMonth($ent, $branchId, $month, $run);
            DB::table('aud_runs')->where('id', $run->id)->update(['snapshot_json' => json_encode($snap + ['shares' => $shares])]);
            WaterfallService::makeStatements($ent, (int) $run->id);
            \App\Services\Connect\Messenger::statementsOfRun($ent, (int) $run->id);   // choice STATEMENT_SEND: queued to the partners (sent when a channel exists)
            Audit::log('aud_runs', $run->id, 'LOCK', ['shop' => $branchId, 'month' => $month, 'profit' => $fig['profit']], $note, $ent);
            EventBus::fire('MONTH_LOCKED', 'aud_runs', $run->id, ['shop' => $branchId, 'month' => $month], $ent);
            TaskEngine::close('FIN_PERIOD_OPEN', [$branchId . '-' . $month], 'Month locked');
            return ['run' => $run->id, 'partner_rows' => $shares['rows'], 'to_partners' => $shares['to_partners'], 'ledger_locked_up_to' => self::syncLedgerLock($ent)];
        });
    }

    /** The ledger is locked up to the last month in which every shop with activity is LOCKED (and no month with activity before it is open). */
    public static function syncLedgerLock(int $ent): string
    {
        $first = min(array_filter([DB::table('t_sales')->where('fk_id_ent', $ent)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->min('d_order'), DB::table('mo_settlements')->where('fk_id_ent', $ent)->min('statement_date'), date('Y-m-d')]));
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('id'); $upTo = (string) Settings::get('GL_LOCKED_UPTO', $ent, ''); $any = false;
        for ($m = substr($first, 0, 7); $m < date('Y-m'); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $from = $m . '-01'; $to = date('Y-m-t', strtotime($from)); $active = $shops->filter(fn ($s) => self::hasActivity($ent, (int) $s, $from, $to));
            if ($active->isEmpty()) continue;   // an empty month is locked only when a later month is locked (so last month's costs can still be booked)
            if ($active->contains(fn ($s) => (self::run($ent, (int) $s, $m)->status ?? 'OPEN') !== 'LOCKED')) break;
            $any = true; $upTo = max($upTo, $m);
        }
        if ($upTo !== (string) Settings::get('GL_LOCKED_UPTO', $ent, '')) Settings::set('GL_LOCKED_UPTO', $upTo, $ent, Context::userId());
        return $upTo;
    }

    /** Everything the Month close screen shows for one shop × month. */
    public static function page(int $ent, int $branchId, string $month): array
    {
        $shop = self::shop($ent, $branchId); [$from, $to] = self::month($month); $run = self::run($ent, $branchId, $month);
        $locked = $run && $run->status === 'LOCKED'; $snap = $locked ? json_decode($run->snapshot_json ?: '{}', true) : null;
        $checks = $run ? DB::table('aud_checks')->where('fk_id_run', $run->id)->get()->keyBy('check_code') : collect(); $rows = [];
        foreach (self::CHECKS as $code => [$title, $waivable]) {
            $c = $checks[$code] ?? null;
            $rows[] = ['code' => $code, 'title' => $title, 'waivable' => $waivable, 'status' => $c->status ?? 'PENDING', 'detail' => $c->detail ?? 'Not run yet.', 'open_items' => (int) ($c->open_items ?? 0), 'fix_url' => $c->fix_url ?? null, 'waive_reason' => $c->waive_reason ?? null];
        }
        return ['shop' => ['id' => (int) $shop->id, 'name' => $shop->shop_name], 'month' => $month, 'month_name' => date('F Y', strtotime($from)), 'status' => $run->status ?? 'OPEN', 'run_code' => $run->run_code ?? null, 'checked_at' => $run->checked_at ?? null,
            'checks' => $rows, 'red' => count(array_filter($rows, fn ($r) => $r['status'] === 'RED')), 'figures' => $locked ? ($snap['figures'] ?? []) : self::figures($ent, $branchId, $month), 'frozen' => $locked,
            'owner_note' => $run->owner_note ?? null, 'locked_at' => $run->locked_at ?? null, 'locked_by' => $run && $run->locked_by ? DB::table('t_users')->where('id', $run->locked_by)->value('display_name') : null,
            'run_id' => $run->id ?? null, 'statements' => $locked ? WaterfallService::statementFiles($ent, null, (int) $run->id) : [],
            'shares' => $locked ? ($snap['shares'] ?? null) : PartnerService::monthShares($ent, $branchId, $month),
            'ended' => $to < date('Y-m-d'), 'has_activity' => self::hasActivity($ent, $branchId, $from, $to), 'ledger_locked_up_to' => (string) Settings::get('GL_LOCKED_UPTO', $ent, '')];
    }

    /** Overview: the last $months months × shops with their state. */
    public static function overview(int $ent, int $months = 6): array
    {
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']); $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $from = $m . '-01'; $to = date('Y-m-t', strtotime($from)); $cells = [];
            foreach ($shops as $s) { $run = self::run($ent, (int) $s->id, $m); $act = self::hasActivity($ent, (int) $s->id, $from, $to);
                $cells[] = ['shop' => (int) $s->id, 'status' => $run->status ?? ($act ? 'OPEN' : 'EMPTY'), 'score' => $run->score ?? null, 'profit' => $run && $run->status === 'LOCKED' ? (float) $run->net_profit : null]; }
            $out[] = ['month' => $m, 'name' => date('M Y', strtotime($from)), 'ended' => $to < date('Y-m-d'), 'cells' => $cells];
        }
        return ['shops' => $shops, 'months' => $out, 'ledger_locked_up_to' => (string) Settings::get('GL_LOCKED_UPTO', $ent, '')];
    }

    /** Nightly: a shop × month that ended more than period_open_days ago and is not LOCKED. */
    public static function openTasks(int $ent): int
    {
        $days = (int) (Settings::get('period_open_days', $ent, 15) ?: 15); $n = 0; $ov = self::overview($ent, 12); $names = collect($ov['shops'])->pluck('name', 'id');
        foreach ($ov['months'] as $m) {
            if (date('Y-m-t', strtotime($m['month'] . '-01')) >= date('Y-m-d', strtotime("-$days day"))) continue;
            foreach ($m['cells'] as $c) if (in_array($c['status'], ['OPEN', 'CHECKED'], true)) { TaskEngine::open('FIN_PERIOD_OPEN', [$c['shop'] . '-' . $m['month']], "{$names[$c['shop']]} {$m['name']} is not locked yet", ['ent' => $ent, 'module' => 't_branches', 'record_id' => $c['shop'], 'fix_url' => "#/close?shop={$c['shop']}&month={$m['month']}"]); $n++; }
        }
        return $n;
    }
}
