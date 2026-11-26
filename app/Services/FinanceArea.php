<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The finance pieces of batch 7e-220, each with its rule:
 * R-FIN-64 Bank & cash page, R-FIN-65 Owner finance home extras (as-of date, forecast 30 / 60 / 90, free cash, profit by marketplace, partners, slow + dead stock, needs action),
 * R-FIN-44 product × period figures with spreads, MR-1264 ad spend per product, R-FIN-15 PO-scoped agreement ends when the PO is sold out,
 * R-FIN-10 partner messages with opt-out (STATEMENT_READY mandatory), R-FIN-73 old category codes re-coded once, R-FIN-5 payout bank of the shop's company,
 * MR-0079 live profit per sale line (provisional → final at close), MR-0080 decisions with £ impact and one-click action, MR-0081 cash scenario,
 * MR-1271 the end audit of any account (its stock, its money, its figures per role), R-FIN-12 data-source health.
 */
class FinanceArea
{
    private static function m($v): float { return round((float) $v, 2); }

    // ───────── R-FIN-64 Bank & cash ─────────
    public static function bankCash(int $ent, string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) throw new \InvalidArgumentException('Month must be YYYY-MM.');
        $from = "$month-01"; $to = date('Y-m-t', strtotime($from)); [$y, $mo] = array_map('intval', explode('-', $month));
        $act = DB::table('fin_bank_transactions')->where('company_id', $ent)->whereBetween('txn_date', [$from, $to])->where(fn ($w) => $w->whereNull('match_status')->orWhere('match_status', '<>', 'IGNORED'))
            ->selectRaw("COALESCE(NULLIF(category_code, ''), 'UNCATEGORISED') c, SUM(amount_gbp) s")->groupBy('c')->pluck('s', 'c');
        $bud = DB::table('fin_budgets')->where('company_id', $ent)->where('year', $y)->where('month', $mo)->pluck('amount', 'category_code');
        $rows = [];
        foreach (collect(array_keys($act->all()))->merge(array_keys($bud->all()))->unique()->sort()->values() as $c) {
            $a = self::m($act[$c] ?? 0); $b = self::m($bud[$c] ?? 0); $diff = self::m($a + $b);      // money out is negative, the budget positive: actual + budget
            $rows[] = ['category' => $c, 'actual' => $a, 'budget' => $b, 'difference' => $diff, 'over' => $b > 0 && $diff < 0];
        }
        $owe = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)
            ->groupBy('s.id', 's.supplier_name')->selectRaw('s.id, s.supplier_name supplier, SUM(t.planned_amount) scheduled, SUM(t.paid_amount) paid, SUM(t.remaining_amount) owed')->havingRaw('SUM(t.remaining_amount) > 0')->get();
        $due30 = DB::table('t_src_payment_terms')->where('fk_id_ent', $ent)->where('f_active', 1)->where('remaining_amount', '>', 0)->whereBetween('due_date', [date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))])->orderBy('due_date')->get(['id', 'fk_id_po', 'milestone', 'due_date', 'remaining_amount']);
        return ['month' => $month, 'base' => Fx::base($ent), 'accounts' => MoneyService::banks($ent), 'categories' => $rows, 'owe_suppliers' => $owe, 'loans' => FinanceExtras::loansPage($ent)['loans'] ?? [], 'supplier_due_30' => $due30];
    }

    // ───────── R-FIN-65 Owner finance home ─────────
    public static function slowDead(int $ent): array
    {
        $slow = FinanceSettings::int($ent, 'slow_days') ?: 90; $dead = FinanceSettings::int($ent, 'dead_days') ?: 180; $today = date('Y-m-d');
        $last = DB::table('t_sales as s')->join('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.status', '<>', 'Cancelled')->groupBy('p.SKU_VENDOR')->selectRaw('p.SKU_VENDOR sku, MAX(s.d_order) d')->pluck('d', 'sku');
        $out = ['slow' => 0.0, 'dead' => 0.0, 'skus' => []];
        foreach (DB::table('fin_lots')->where('company_id', $ent)->where('qty_left', '>', 0)->groupBy('sku')->selectRaw('sku, SUM(qty_left * COALESCE(unit_cost_gbp, 0)) v, MIN(received_date) first')->get() as $s) {
            if ((float) $s->v <= 0) continue;
            $from = substr((string) ($last[$s->sku] ?? $s->first), 0, 10); if (! $from) continue;
            $d = (int) floor((strtotime($today) - strtotime($from)) / 86400); if ($d < $slow) continue;
            $k = $d >= $dead ? 'dead' : 'slow'; $out[$k] = self::m($out[$k] + $s->v); $out['skus'][] = ['sku' => $s->sku, 'days' => $d, 'value' => self::m($s->v), 'state' => strtoupper($k)];
        }
        return $out;
    }

    public static function home(int $ent, ?string $asOf = null): array
    {
        $asOf = $asOf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
        $bankAsOf = self::m(DB::table('fin_bank_accounts as a')->where('a.company_id', $ent)->where('a.is_active', 1)->sum('a.opening_balance')
            + (float) DB::table('fin_bank_transactions')->where('company_id', $ent)->where('txn_date', '<=', $asOf)->sum('amount_gbp'));      // bank lines up to the as-of date
        $fc = CashForecast::build($ent, 13); $bal = fn (int $days) => (float) (collect($fc['rows'])->first(fn ($r) => $r['week'] >= (int) ceil($days / 7))['balance'] ?? $fc['bank_now']);
        $free = FinanceAlerts::freeCash($ent);
        $prof = ProfitService::view($ent, 'shop', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')));
        $partners = array_values(array_filter(PartnerService::partners($ent), fn ($p) => abs((float) ($p['capital'] ?? 0)) > 0.004 || abs((float) ($p['balance'] ?? 0)) > 0.004));
        $wd = DB::table('fin_withdrawals')->where('company_id', $ent)->whereIn('status', ['REQUESTED', 'APPROVED'])->count();
        $sd = self::slowDead($ent);
        $needs = DB::table('fin_alerts')->where('company_id', $ent)->where('status', 'OPEN')->count() + DB::table('fin_withdrawals')->where('company_id', $ent)->where('status', 'REQUESTED')->count()
            + DB::table('fin_statement_queries')->whereNull('answered_at')->count();
        return ['as_of' => $asOf, 'bank_as_of' => $bankAsOf, 'reserve' => self::m(Ledger::balance($ent, '1120')), 'committed' => $free['committed'], 'free_cash' => $free['free'],
            'forecast' => ['d30' => self::m($bal(30)), 'd60' => self::m($bal(60)), 'd90' => self::m($bal(90))], 'profit_by_marketplace' => $prof['rows'] ?? [], 'profit_month' => date('Y-m', strtotime('first day of last month')),
            'partners' => $partners, 'open_withdrawals' => $wd, 'slow_stock' => $sd['slow'], 'dead_stock' => $sd['dead'], 'needs_action' => $needs,
            'links' => ['bank' => '#/bank', 'forecast' => '#/forecast', 'withdrawals' => '#/partners', 'stock' => '#/stock/health', 'alerts' => '#/fin-alerts', 'profit' => '#/profit']];
    }

    // ───────── MR-1264 ad spend per product, R-FIN-44 product × period ─────────
    /** Spend of a month per SKU: ADS lines with the SKU, lines without one spread by sales share of the month. */
    public static function adSpend(int $ent, string $month): array
    {
        $from = "$month-01"; $to = date('Y-m-t', strtotime($from));
        $ads = DB::table('fin_ads_lines as a')->join('t_branches as b', 'b.id', '=', 'a.branch_id')->where('b.fk_id_ent', $ent)->whereBetween('a.ad_date', [$from, $to])->get(['a.sku', 'a.spend']);
        $sales = self::salesBySku($ent, $from, $to); $tot = array_sum(array_map(fn ($v) => max(0, $v), $sales));
        $direct = []; $loose = 0.0;
        foreach ($ads as $a) { $s = trim((string) $a->sku); if ($s === '') $loose += (float) $a->spend; else $direct[$s] = ($direct[$s] ?? 0) + (float) $a->spend; }
        $out = [];
        foreach (array_unique(array_merge(array_keys($direct), array_keys($sales))) as $sku) {
            $share = $tot > 0 ? max(0, $sales[$sku] ?? 0) / $tot : 0;
            $d = self::m($direct[$sku] ?? 0); $sp = self::m($loose * $share);
            if ($d == 0.0 && $sp == 0.0) continue;
            $out[] = ['sku' => $sku, 'direct' => $d, 'spread' => $sp, 'total' => self::m($d + $sp), 'sales' => self::m($sales[$sku] ?? 0)];
        }
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['month' => $month, 'rows' => $out, 'total' => self::m(array_sum(array_column($out, 'total'))), 'unassigned' => self::m($loose)];
    }

    private static function salesBySku(int $ent, string $from, string $to): array
    {
        return DB::table('t_sales')->where('fk_id_ent', $ent)->whereBetween('d_order', [$from, $to . ' 23:59:59'])->whereNotIn('status', ['Cancelled', 'Pending'])
            ->groupBy('sku')->selectRaw('sku, SUM(COALESCE(total_amount_gbp, total_amount)) s')->pluck('s', 'sku')->map(fn ($v) => (float) $v)->all();
    }

    /** R-FIN-44: per SKU sales, refunds, fees, PPC, COGS, profit; amounts without a SKU spread by sales share (PPC by ADS spend share when the SKU has ADS rows). */
    public static function productPeriod(int $ent, string $from, string $to, ?array $unassigned = null): array
    {
        $rows = DB::table('t_sales')->where('fk_id_ent', $ent)->whereBetween('d_order', [$from, $to . ' 23:59:59'])->whereNotIn('status', ['Cancelled', 'Pending'])->groupBy('sku')
            ->selectRaw('sku, SUM(qty) units, SUM(COALESCE(total_amount_gbp, total_amount)) sales, SUM(COALESCE(refund_loss_amount, 0)) refunds, SUM(COALESCE(cost_gbp, buy_amount, 0)) cogs')->get()->keyBy('sku');
        $tot = $rows->sum(fn ($r) => max(0, (float) $r->sales));
        $settle = DB::table('fin_settlement_lines')->where('company_id', $ent)->whereBetween('posted_date', [$from, $to])->get(['sku', 'amount', 'amount_type', 'transaction_type']);
        $fees = []; $looseFees = 0.0;
        foreach ($settle as $l) { if ((float) $l->amount >= 0 || stripos((string) $l->transaction_type . $l->amount_type, 'refund') !== false) continue; $s = trim((string) $l->sku); if ($s === '') $looseFees += (float) $l->amount; else $fees[$s] = ($fees[$s] ?? 0) + (float) $l->amount; }
        $u = $unassigned ?? ['fees' => $looseFees, 'other' => -(float) DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereBetween('d_expense', [$from, $to])->sum('amount_gbp')];
        $ad = []; foreach (DB::table('fin_ads_lines as a')->join('t_branches as b', 'b.id', '=', 'a.branch_id')->where('b.fk_id_ent', $ent)->whereBetween('a.ad_date', [$from, $to])->get(['a.sku', 'a.spend']) as $a) $ad[trim((string) $a->sku)] = ($ad[trim((string) $a->sku)] ?? 0) + (float) $a->spend;
        $looseAd = $ad[''] ?? 0.0; unset($ad['']); $adTot = array_sum($ad);
        $out = [];
        foreach ($rows as $sku => $r) {
            $share = $tot > 0 ? max(0, (float) $r->sales) / $tot : 0;
            $ppc = -(($ad[$sku] ?? 0) + ($adTot > 0 && isset($ad[$sku]) ? $looseAd * $ad[$sku] / $adTot : ($adTot > 0 ? 0 : $looseAd * $share)));
            $fee = ($fees[$sku] ?? 0) + (float) ($u['fees'] ?? 0) * $share; $other = (float) ($u['other'] ?? 0) * $share;
            $profit = (float) $r->sales - (float) $r->refunds + $fee + $ppc + $other - (float) $r->cogs;
            $out[] = ['sku' => $sku, 'units_sold' => (int) $r->units, 'sales' => self::m($r->sales), 'sales_share' => round($share * 100, 2), 'refunds' => self::m(-$r->refunds), 'fees' => self::m($fee), 'ppc' => self::m($ppc), 'other' => self::m($other),
                'cogs' => self::m($r->cogs), 'net_profit' => self::m($profit), 'margin_pct' => (float) $r->sales != 0.0 ? round($profit / (float) $r->sales * 100, 2) : 0.0, 'roi_pct' => (float) $r->cogs != 0.0 ? round($profit / (float) $r->cogs * 100, 2) : 0.0,
                'refund_rate_pct' => (float) $r->sales != 0.0 ? round(abs((float) $r->refunds) / (float) $r->sales * 100, 2) : 0.0];
        }
        usort($out, fn ($a, $b) => $b['net_profit'] <=> $a['net_profit']);
        return ['from' => $from, 'to' => $to, 'rows' => $out];
    }

    // ───────── R-FIN-15 PO sold out ends its agreements ─────────
    public static function poSoldOut(int $ent, ?int $poId = null): int
    {
        $n = 0;
        $q = DB::table('fin_investor_agreements')->where('company_id', $ent)->where('scope', 'PO')->where('status', 'ACTIVE')->whereNotNull('po_id')->when($poId, fn ($x) => $x->where('po_id', $poId));
        foreach ($q->get(['id', 'po_id', 'investor_id']) as $a) {
            $lots = DB::table('fin_lots')->where('company_id', $ent)->where('po_id', $a->po_id);
            if (! (clone $lots)->exists() || (int) (clone $lots)->sum('qty_left') > 0) continue;
            DB::table('fin_investor_agreements')->where('id', $a->id)->update(['status' => 'ENDED', 'effective_to' => date('Y-m-d'), 'ended_reason' => 'PO sold out']);
            Audit::log('fin_investor_agreements', (int) $a->id, 'UPDATE', ['status' => ['ACTIVE', 'ENDED']], 'PO sold out', $ent);
            self::partnerMessage($ent, (int) $a->investor_id, 'AGREEMENT_ENDED', 'Your agreement on purchase order ' . (DB::table('t_src_po')->where('id', $a->po_id)->value('po_number') ?: '#' . $a->po_id) . ' ended: the goods are sold out.', 'agr-end:' . $a->id);
            $n++;
        }
        return $n;
    }

    // ───────── R-FIN-10 partner messages ─────────
    public const MANDATORY = ['STATEMENT_READY'];

    /** One message per event key + channel; WhatsApp / e-mail switched off by the partner are skipped, except a mandatory kind; text ≤ 600. */
    public static function partnerMessage(int $ent, int $partner, string $kind, string $text, string $eventKey): array
    {
        $must = in_array($kind, self::MANDATORY, true); $text = mb_substr($text, 0, 600); $sent = [];
        if ($p = Connect\Messenger::whatsappOf($partner, ! $must)) { Connect\Messenger::queueWa($ent, $partner, $p, 'PARTNER_NOTICE', ['text' => $text], 't_account', $partner, mb_substr("PM:$kind:$eventKey", 0, 110)); $sent[] = 'WHATSAPP'; }
        if ($e = Connect\Messenger::emailOf($partner, ! $must)) { Connect\Messenger::queueEmail($ent, $partner, $e, ucfirst(strtolower(str_replace('_', ' ', $kind))), $text, null, 'PARTNER_NOTICE', 't_account', $partner, mb_substr("PM:$kind:$eventKey", 0, 110)); $sent[] = 'EMAIL'; }
        return $sent;
    }

    // ───────── R-FIN-73 one code per category ─────────
    public const RECODE = ['MARKETPLACE_PAYOUT' => 'SALES_PAYOUT', 'PARTNER_CAPITAL' => 'CAPITAL_IN', 'PARTNER_WITHDRAWAL' => 'WITHDRAWAL'];

    /** Old bank lines with the old codes get the seeded code once; the compare report = sums per category before and after (equal in total). */
    public static function recode(?int $ent = null): array
    {
        $sum = fn () => DB::table('fin_bank_transactions')->when($ent, fn ($q) => $q->where('company_id', $ent))->selectRaw('COALESCE(category_code, "") c, ROUND(SUM(amount_gbp), 2) s')->groupBy('c')->pluck('s', 'c')->map(fn ($v) => (float) $v)->all();
        $before = $sum(); $n = 0;
        foreach (self::RECODE as $old => $new) $n += DB::table('fin_bank_transactions')->when($ent, fn ($q) => $q->where('company_id', $ent))->where('category_code', $old)->update(['category_code' => $new]);
        $after = $sum();
        $check = []; foreach (self::RECODE as $old => $new) $check[$new] = ['before' => self::m(($before[$old] ?? 0) + ($before[$new] ?? 0)), 'after' => self::m($after[$new] ?? 0)];
        return ['lines' => $n, 'compare' => $check, 'equal' => self::m(array_sum($before)) === self::m(array_sum($after))];
    }

    // ───────── R-FIN-5 payout bank of the shop's company ─────────
    public static function payoutCheck(array &$data, array $old): array
    {
        $v = fn ($c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        if (array_key_exists('seller_id', $data) && is_string($data['seller_id'])) $data['seller_id'] = trim($data['seller_id']) ?: null;
        $bank = (int) $v('fk_id_payout_bank'); if (! $bank) return [];
        $ent = (int) ($v('fk_id_ent') ?: Context::ent());
        return DB::table('fin_bank_accounts')->where('id', $bank)->where('company_id', $ent)->exists() ? [] : ['fk_id_payout_bank' => 'Choose a bank account of this shop\'s company.'];
    }

    // ───────── MR-0079 live profit per sale line ─────────
    public static function lineProfit(int $saleId): ?array
    {
        $s = DB::table('t_sales')->where('id', $saleId)->first(); if (! $s) return null;
        $rev = (float) ($s->total_amount_gbp ?? $s->total_amount); $missing = [];
        $cost = (float) ($s->cost_gbp ?: $s->buy_amount); if ($cost <= 0 && (int) $s->qty > 0) $missing[] = 'cost';
        $fl = DB::table('fin_settlement_lines')->where('order_id', $s->order_no)->when($s->fk_id_branch, fn ($q) => $q->where('branch_id', $s->fk_id_branch))->get(['amount', 'sku', 'transaction_type', 'amount_type']);
        $mine = $fl->filter(fn ($l) => ! $l->sku || $l->sku === $s->sku);
        $fees = -(float) $mine->filter(fn ($l) => (float) $l->amount < 0 && stripos($l->transaction_type . $l->amount_type, 'refund') === false)->sum('amount');
        if ($fl->isEmpty()) $missing[] = 'fees';
        $post = (float) $s->carrier_rate + (float) $s->packing_rate; $refund = (float) $s->refund_loss_amount;
        $month = substr((string) $s->d_order, 0, 7); $ads = 0.0;
        if ($month) { $sp = collect(self::adSpend((int) $s->fk_id_ent, $month)['rows'])->firstWhere('sku', $s->sku);
            if ($sp) { $q = (int) DB::table('t_sales')->where('fk_id_ent', $s->fk_id_ent)->where('sku', $s->sku)->where('d_order', 'like', "$month%")->whereNotIn('status', ['Cancelled', 'Pending'])->sum('qty'); $ads = $q > 0 ? $sp['total'] * (int) $s->qty / $q : 0.0; } }
        $profit = $rev - $cost - $fees - $post - $refund - $ads;
        $row = ['fk_id_sale' => $saleId, 'computed_at' => now(), 'revenue_gbp' => self::m($rev), 'cost_gbp' => self::m($cost), 'fees_gbp' => self::m($fees), 'ads_gbp' => self::m($ads), 'postage_gbp' => self::m($post), 'refund_gbp' => self::m($refund),
            'va_share_gbp' => 0, 'handler_gbp' => 0, 'profit_gbp' => self::m($profit), 'f_final' => (int) $s->f_locked === 1 ? 1 : 0, 'missing_json' => json_encode($missing)];
        $was = DB::table('fin_sale_profit')->where('fk_id_sale', $saleId)->first(['f_final']);
        if ($was && (int) $was->f_final === 1) return (array) DB::table('fin_sale_profit')->where('fk_id_sale', $saleId)->first() + ['state' => 'FINAL', 'missing' => []];      // final is frozen
        DB::table('fin_sale_profit')->updateOrInsert(['fk_id_sale' => $saleId], $row);
        return $row + ['state' => $row['f_final'] ? 'FINAL' : 'PROVISIONAL', 'missing' => $missing];
    }

    /** Nightly: open lines of the last 120 days; at close the locked lines become final. */
    public static function refreshProfit(int $ent, int $days = 120): int
    {
        $n = 0;
        foreach (DB::table('t_sales')->where('fk_id_ent', $ent)->where('d_order', '>=', date('Y-m-d', strtotime("-$days days")))->whereNotIn('status', ['Cancelled'])->orderBy('id')->pluck('id') as $id) { self::lineProfit((int) $id); $n++; }
        return $n;
    }

    // ───────── MR-0080 decisions ─────────
    public static function decisions(int $ent): array
    {
        $out = []; $today = date('Y-m-d');
        $daily = DB::table('t_sales')->where('fk_id_ent', $ent)->where('d_order', '>=', date('Y-m-d', strtotime('-30 days')))->whereNotIn('status', ['Cancelled', 'Pending'])->groupBy('fk_product_id')->selectRaw('fk_product_id p, SUM(profit) / 30 d')->pluck('d', 'p');
        foreach (StockAlerts::rows($ent) as $r) {
            $d = (float) ($daily[$r['id']] ?? 0); if ($d <= 0) continue;
            $out[] = ['kind' => 'REORDER', 'title' => "Reorder {$r['sku']} ({$r['status']})", 'impact' => self::m($d * 14), 'impact_text' => 'profit lost in 2 weeks without stock', 'action' => '#/quick-buy?product=' . $r['id'], 'action_label' => 'Buy more'];
        }
        foreach (self::slowDead($ent)['skus'] as $s) if ($s['state'] === 'DEAD')
            $out[] = ['kind' => 'DEAD_STOCK', 'title' => "Clear dead stock {$s['sku']} – no sale for {$s['days']} days", 'impact' => $s['value'], 'impact_text' => 'cash tied up', 'action' => '#/stock/health', 'action_label' => 'Open stock'];
        $old = (int) (FinanceSettings::int($ent, 'bank_overdue_days') ?: 7);
        $un = DB::table('fin_bank_transactions')->where('company_id', $ent)->where('match_status', 'UNMATCHED')->where('txn_date', '<=', date('Y-m-d', strtotime("-$old days")))->selectRaw('COUNT(*) n, SUM(ABS(amount_gbp)) v')->first();
        if ((int) $un->n) $out[] = ['kind' => 'UNMATCHED', 'title' => "{$un->n} bank line(s) older than $old days are not matched", 'impact' => self::m($un->v), 'impact_text' => 'not in the profit yet', 'action' => '#/bank', 'action_label' => 'Match now'];
        $late = DB::table('t_src_payment_terms')->where('fk_id_ent', $ent)->where('f_active', 1)->where('remaining_amount', '>', 0)->where('due_date', '<', $today)->selectRaw('COUNT(*) n, SUM(remaining_amount) v')->first();
        if ((int) $late->n) $out[] = ['kind' => 'SUPPLIER_LATE', 'title' => "{$late->n} supplier payment(s) are late", 'impact' => self::m($late->v), 'impact_text' => 'owed and overdue', 'action' => '#/supplier-schedule', 'action_label' => 'Pay or plan'];
        $free = FinanceAlerts::freeCash($ent); $lim = FinanceSettings::num($ent, 'free_cash_alert_limit');
        if ($lim > 0 && $free['free'] < $lim) $out[] = ['kind' => 'CASH', 'title' => 'Free cash is under the limit', 'impact' => self::m($lim - $free['free']), 'impact_text' => 'short of the limit', 'action' => '#/forecast', 'action_label' => 'Open forecast'];
        usort($out, fn ($a, $b) => $b['impact'] <=> $a['impact']);
        return ['rows' => $out, 'base' => Fx::base($ent)];
    }

    // ───────── MR-0081 scenario ─────────
    public static function scenario(int $ent, string $title, array $items, bool $save = false): array
    {
        $clean = [];
        foreach ($items as $i) {
            $amt = (float) ($i['amount'] ?? 0); if ($amt <= 0) continue;
            $d = (string) ($i['date'] ?? date('Y-m-d')); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new \InvalidArgumentException('Dates as YYYY-MM-DD.');
            $clean[] = ['dir' => ($i['dir'] ?? 'out') === 'in' ? 'in' : 'out', 'date' => $d, 'amount' => round($amt, 2), 'what' => mb_substr((string) ($i['what'] ?? 'Item'), 0, 80)];
        }
        if (! $clean) throw new \InvalidArgumentException('Add at least one amount (for example the POs you plan to place).');
        $base = CashForecast::build($ent, 13); $with = CashForecast::build($ent, 13, $clean);
        $low = collect($with['rows'])->sortBy('balance')->first();
        $res = ['title' => $title ?: 'Scenario', 'items' => $clean, 'rows' => array_map(fn ($a, $b) => ['label' => $a['label'], 'now' => $a['balance'], 'with' => $b['balance'], 'change' => self::m($b['balance'] - $a['balance'])], $base['rows'], $with['rows']),
            'lowest' => ['label' => $low['label'], 'balance' => $low['balance']], 'goes_below_limit' => $with['first_low'], 'base' => $with['base']];
        if ($save) $res['id'] = (int) DB::table('fin_scenarios')->insertGetId(['fk_id_enterprise' => $ent, 'title' => mb_substr($res['title'], 0, 150), 'created_by' => Context::userId(), 'created_at' => now(), 'assumptions_json' => json_encode($clean), 'result_json' => json_encode($res['rows'])]);
        return $res;
    }

    // ───────── MR-1271 end audit of an account ─────────
    public static function accountAudit(int $ent, int $acc, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?: date('Y-01-01'); $to = $to ?: date('Y-m-d');
        $name = trim((string) DB::table('t_account')->where('id', $acc)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n'));
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('fk_id_seller', $acc)->orWhere('fk_id_owner', $acc)->orWhere('fk_id_handler_party', $acc))->pluck('id')->all();
        $sales = $shops ? DB::table('t_sales')->whereIn('fk_id_branch', $shops)->whereBetween('d_order', [$from, $to . ' 23:59:59'])->whereNotIn('status', ['Cancelled'])->selectRaw('COUNT(*) n, SUM(COALESCE(total_amount_gbp, total_amount)) sales, SUM(profit) profit')->first() : null;
        $sup = DB::table('t_src_suppliers')->where('fk_id_account', $acc)->value('id');
        $buy = $sup ? DB::table('t_src_po')->where('fk_id_supplier', $sup)->where('f_active', 1)->selectRaw('COUNT(*) n, SUM(po_total) total')->first() : null;
        $owed = $sup ? (float) DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('p.fk_id_supplier', $sup)->where('t.f_active', 1)->sum('t.remaining_amount') : 0.0;
        $lots = DB::table('fin_lots')->where('company_id', $ent)->where('fk_id_owner', $acc)->where('qty_left', '>', 0)->selectRaw('COALESCE(SUM(qty_left), 0) units, COALESCE(SUM(qty_left * unit_cost_gbp), 0) value')->first();
        $partner = DB::table('fin_investor_ledger')->where('investor_id', $acc)->exists() ? PartnerService::account($ent, $acc, $to) : null;
        $ledger = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->where('l.ref_type', 'PARTY')->where('l.ref_id', $acc)
            ->groupBy('a.code', 'a.name')->selectRaw('a.code, a.name, ROUND(SUM(l.debit - l.credit), 2) balance')->get();
        $va = DB::table('t_account_job_history')->where('fk_id_account', $acc)->where('f_active', 1)->whereNull('d_end_date')->get(['fk_id_marketplace', 'fk_id_branch', 'share_percentage', 'salary']);
        return ['account' => $acc, 'name' => $name, 'from' => $from, 'to' => $to,
            'as_seller_owner' => ['shops' => count($shops), 'lines' => (int) ($sales->n ?? 0), 'sales' => self::m($sales->sales ?? 0), 'profit' => self::m($sales->profit ?? 0)],
            'as_va' => ['jobs' => $va->count(), 'share_pct' => $va->pluck('share_percentage')->filter()->values()->all()],
            'as_supplier' => $sup ? ['orders' => (int) $buy->n, 'bought' => self::m($buy->total), 'owed' => self::m($owed)] : null,
            'as_partner' => $partner, 'inventory' => ['units' => (int) $lots->units, 'value' => self::m($lots->value)], 'money' => $ledger];
    }

    // ───────── R-INV-13 month status side panel on Sales ─────────
    public static function monthStatus(int $ent, int $shop, string $from, string $to): array
    {
        $out = []; $m = substr($from, 0, 7); $last = substr($to, 0, 7);
        while ($m <= $last && count($out) < 36) {
            [$y, $mo] = array_map('intval', explode('-', $m));
            $imp = DB::table('t_sale_imports')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop)->where('period_year', $y)->where('period_month', $mo)->orderByDesc('id')->first(['id', 'status', 'confirmed_at']);
            $per = DB::table('t_inv_periods')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop)->where('d_from', '<=', date('Y-m-t', strtotime("$m-01")))->where('d_to', '>=', "$m-01")->orderByDesc('id')->first(['id', 'status']);
            $inv = $per ? DB::table('t_invoices')->where('fk_id_period', $per->id)->count() : 0;
            $confirmed = $imp && ($imp->confirmed_at || strtoupper((string) $imp->status) === 'CONFIRMED');
            $out[] = ['month' => $m, 'import' => $imp ? ($confirmed ? 'Confirmed' : ucfirst(strtolower((string) $imp->status ?: 'Imported'))) : 'Not imported', 'settlement' => $per ? ucfirst(strtolower((string) $per->status)) : 'Not settled',
                'invoices' => $inv, 'can_settle' => $confirmed && (! $per || $per->status !== 'CONFIRMED'), 'settle_url' => $imp ? '#/invoices?settle=' . $imp->id : null];
            $m = date('Y-m', strtotime("$m-01 +1 month"));
        }
        return ['shop' => $shop, 'rows' => $out];
    }

    // ───────── R-FIN-12 where the data comes from ─────────
    public static function sources(int $ent): array
    {
        $n = fn (string $t, ?\Closure $w = null) => \Illuminate\Support\Facades\Schema::hasTable($t) ? (int) ($w ? $w(DB::table($t)) : DB::table($t))->count() : 0;
        $rows = [['source' => 'Companies', 'n' => $n('t_enterprise')], ['source' => 'Stores (shops)', 'n' => $n('t_branches', fn ($q) => $q->where('fk_id_ent', $ent))], ['source' => 'Investors / partners', 'n' => $n('fin_investor_agreements', fn ($q) => $q->where('company_id', $ent))],
            ['source' => 'Finance roles', 'n' => $n('t_users', fn ($q) => $q->whereIn('role_code', ['OWNER', 'PARTNER', 'FINANCE', 'ACCOUNTANT'])->where('f_active', 1))], ['source' => 'GRN lots', 'n' => $n('fin_lots', fn ($q) => $q->where('company_id', $ent))],
            ['source' => 'Supplier schedule', 'n' => $n('t_src_payment_terms', fn ($q) => $q->where('fk_id_ent', $ent))], ['source' => 'Damaged stock', 'n' => $n('cat_inventory_locations', fn ($q) => $q->where('fk_id_ent', $ent)->where('kind', 'DAMAGED'))],
            ['source' => 'Dropship', 'n' => $n('drp_items')], ['source' => 'Tasks', 'n' => $n('tasks', fn ($q) => $q->where('fk_id_ent', $ent))]];
        return array_map(fn ($r) => $r + ['state' => $r['n'] > 0 ? 'connected' : 'fallback'], $rows);
    }
}
