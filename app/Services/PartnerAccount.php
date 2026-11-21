<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * My Partner Account (R-FIN-19): what one partner sees about himself – and the list of what he never sees.
 * - As-of date + "Compare with" date (default one month back): Capital + Profit − Withdrawn = Balance on both dates.
 * - Return % per year after the tax reserve; statements to accept; stock at cost now vs then × my POOL % (damaged / returned / written off apart, never in the balance);
 *   profit by product (closed periods ≤ as-of, top 50 SKUs, my share = profit × POOL % × (1 − (VAT + corp tax) ÷ 100)); capital calls; my agreements; notification toggles.
 * - Never shows: bank balances, other partners, supplier names, unit costs, company ledger (NEVER keys are stripped from every answer).
 * - A staff login has no view of its own (explanation page); the Owner may "view as partner" (?partner=ID), read-only and audited.
 */
class PartnerAccount
{
    public const NEVER = ['supplier_name', 'supplier', 'unit_cost_gbp', 'unit_cost', 'bank_balance', 'balance_bank', 'ledger', 'investor_ids', 'other_partners'];

    public static function reserve(int $ent): float
    {
        $s = DB::table('fin_company_settings')->where('company_id', $ent)->first(['vat_rate', 'corp_tax_rate']);
        return round((float) ($s->vat_rate ?? 0) + (float) ($s->corp_tax_rate ?? 0), 2);
    }

    /** Highest POOL % over the partner's agreements active on the date. */
    public static function poolPct(int $ent, int $partner, string $date): float
    {
        return (float) DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $partner)->where('scope', 'POOL')->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->max('profit_share_pct');
    }

    /** Stock value at cost on a date: lots received by then less units that left by then (FIFO cost of their own lot). */
    public static function stockValue(int $ent, string $date): float
    {
        $in = (float) DB::table('fin_lots')->where('company_id', $ent)->where('received_date', '<=', $date)->sum(DB::raw('qty * unit_cost_gbp'));
        $out = (float) DB::table('fin_lot_consumption as c')->join('fin_lots as l', 'l.id', '=', 'c.lot_id')->where('l.company_id', $ent)->where('c.consumed_on', '<=', $date)->sum(DB::raw('c.qty * c.unit_cost_gbp'));
        return round($in - $out, 2);
    }

    /** Value now in a system location (damaged, returns). */
    private static function valueAt(int $ent, string $kind): float
    {
        $loc = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', $kind)->orderBy('id')->value('id'); if (! $loc) return 0.0;
        return round((float) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $loc)->where('l.company_id', $ent)->sum(DB::raw('b.qty * l.unit_cost_gbp')), 2);
    }

    public static function page(int $ent, int $partner, ?string $asOf = null, ?string $compare = null): array
    {
        $asOf = $asOf ?: date('Y-m-d'); $compare = $compare ?: date('Y-m-d', strtotime($asOf . ' -1 month'));
        if ($compare > $asOf) throw new \InvalidArgumentException('The compare date must be before the as-of date.');
        $now = PartnerService::account($ent, $partner, $asOf); $then = PartnerService::account($ent, $partner, $compare);
        $card = fn ($a) => ['capital' => $a['capital'], 'profit' => $a['profit_to_date'], 'withdrawn' => $a['withdrawn'], 'balance' => $a['balance']];
        $reserve = self::reserve($ent); $pct = self::poolPct($ent, $partner, $asOf);
        $yearProfit = (float) DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $partner)->whereIn('type', ['PROFIT', 'MANAGEMENT_FEE'])->whereBetween('entry_date', [date('Y-m-d', strtotime($asOf . ' -1 year')), $asOf])->sum('amount');
        $return = $now['capital'] > 0 ? round($yearProfit * (1 - $reserve / 100) / $now['capital'] * 100, 2) : null;
        // profit by product: closed (locked) months ≤ as-of, top 50 SKUs
        $locked = DB::table('aud_runs')->where('fk_id_ent', $ent)->where('status', 'LOCKED')->where('period_month', '<=', substr($asOf, 0, 7))->get(['fk_id_branch', 'period_month']);
        $prod = [];
        if ($locked->isNotEmpty()) {
            $q = DB::table('t_sales as s')->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.status', 'Confirmed')->whereNotNull('s.fk_product_id')
                ->where(function ($w) use ($locked) { foreach ($locked as $l) $w->orWhere(fn ($x) => $x->where('s.fk_id_branch', $l->fk_id_branch)->whereRaw("DATE_FORMAT(s.d_order, '%Y-%m') = ?", [$l->period_month])); });
            $prod = $q->groupBy('s.fk_product_id', 'p.SKU_VENDOR', 'p.product_name_vendor')->orderByDesc(DB::raw('SUM(s.profit)'))->limit(50)
                ->get([DB::raw('p.SKU_VENDOR as sku'), DB::raw('p.product_name_vendor as product'), DB::raw('SUM(s.qty) as units'), DB::raw('SUM(s.profit) as profit')])
                ->map(fn ($r) => ['sku' => $r->sku, 'product' => $r->product, 'units' => (int) $r->units, 'profit' => round((float) $r->profit, 2), 'my_share' => round((float) $r->profit * $pct / 100 * (1 - $reserve / 100), 2)])->all();
        }
        $stockNow = self::stockValue($ent, $asOf); $stockThen = self::stockValue($ent, $compare);
        $writtenOff = round(abs((float) DB::table('gl_lines as j')->join('gl_journals as h', 'h.id', '=', 'j.journal_id')->join('gl_accounts as a', 'a.id', '=', 'j.account_id')->where('h.fk_id_ent', $ent)->where('a.code', '5500')->where('h.journal_date', '<=', $asOf)->sum(DB::raw('j.debit - j.credit'))), 2);
        $p = DB::table('t_account')->where('id', $partner)->first(['wa_enabled', 'email_enabled']);
        $out = ['as_of' => $asOf, 'compare' => $compare, 'base' => Fx::base($ent), 'name' => PartnerService::name($partner), 'now' => $card($now), 'then' => $card($then),
            'return_pct' => $return, 'reserve_pct' => $reserve, 'pool_pct' => $pct,
            'stock' => ['now' => round($stockNow * $pct / 100, 2), 'then' => round($stockThen * $pct / 100, 2), 'damaged' => round(self::valueAt($ent, 'DAMAGED') * $pct / 100, 2), 'returned' => round(self::valueAt($ent, 'RETURNS') * $pct / 100, 2), 'written_off' => round($writtenOff * $pct / 100, 2),
                'note' => 'Your POOL % of the stock at cost. Damaged, returned and written-off stock is shown apart and is never in your balance.'],
            'statements_to_accept' => DB::table('fin_statements as s')->join('aud_runs as r', 'r.id', '=', 's.period_id')->where('r.fk_id_ent', $ent)->where('s.investor_id', $partner)->where('s.superseded', 0)
                ->where(fn ($w) => $w->whereNull('s.partner_status')->orWhereNotIn('s.partner_status', ['ACCEPTED']))->orderByDesc('r.period_month')->get(['s.id', 'r.period_month', 's.partner_profit', 's.partner_status'])->map(fn ($s) => (array) $s)->all(),
            'profit_by_product' => $prod,
            'capital_calls' => DB::table('fin_capital_call_responses as c')->join('fin_capital_calls as k', 'k.id', '=', 'c.call_id')->where('k.company_id', $ent)->where('c.investor_id', $partner)->orderByDesc('k.id')
                ->get(['k.id', 'k.reason', 'k.due_date', 'k.status as call_status', 'c.amount_asked', 'c.amount_accepted', 'c.status'])->map(fn ($c) => (array) $c)->all(),
            'agreements' => array_map(fn ($a) => array_intersect_key($a, array_flip(['scope_name', 'type', 'pct', 'fee_pct', 'from', 'to', 'live'])), PartnerService::partners($ent, $partner)[0]['agreements'] ?? []),
            'notify' => ['whatsapp' => (bool) ($p->wa_enabled ?? 1), 'email' => (bool) ($p->email_enabled ?? 1), 'note' => 'Statements are always sent; the other messages follow these switches.']];
        return self::strip($out);
    }

    /** The "never shows" list: removed at any depth. */
    public static function strip(array $a): array
    {
        foreach ($a as $k => $v) { if (is_string($k) && in_array($k, self::NEVER, true)) { unset($a[$k]); continue; } if (is_array($v)) $a[$k] = self::strip($v); }
        return $a;
    }

    public static function setNotify(int $partner, array $d): array
    {
        $set = [];
        if (array_key_exists('whatsapp', $d)) $set['wa_enabled'] = (int) (bool) $d['whatsapp'];
        if (array_key_exists('email', $d)) $set['email_enabled'] = (int) (bool) $d['email'];
        if ($set) DB::table('t_account')->where('id', $partner)->update($set);
        return ['message' => 'Saved. Statements are always sent.'];
    }

    /**
     * R-FIN-18 how much may be reinvested: profit + adjustments − withdrawn (= the balance; old REINVEST rows already taken off),
     * less withdrawals still waiting (improved: money asked for cannot be reinvested too).
     */
    public static function reinvestable(int $ent, int $partner): float
    {
        $waiting = (float) DB::table('fin_withdrawals')->where('company_id', $ent)->where('investor_id', $partner)->whereIn('status', ['REQUESTED', 'APPROVED'])->sum('amount');
        return round(PartnerService::account($ent, $partner)['balance'] - $waiting, 2);
    }

    /**
     * R-FIN-18 Reinvest profit as capital. Partner (own) or Owner (the controllers check who). Amount > allowed + 0.001 → refused
     * "Refused: only <x> of accumulated profit is available to reinvest." Writes PROFIT −x and CAPITAL_IN +x (reference REINVEST) dated
     * today, posts payable 2100 → capital 2150. Current POOL agreement: started before today → ended yesterday and copied from today
     * with capital_amount + x; started today → capital updated in place. The Owners are told (bell, type FINANCE).
     */
    public static function reinvest(int $ent, int $partner, float $amount, ?string $note = null): array
    {
        if ($amount <= 0) throw new \InvalidArgumentException('Amount must be more than 0.');
        return DB::transaction(function () use ($ent, $partner, $amount, $note) {
            DB::table('t_account')->where('id', $partner)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Choose the partner.');
            $x = round($amount, 2); $allowed = self::reinvestable($ent, $partner);
            if ($x > $allowed + 0.001) throw new \InvalidArgumentException('Refused: only ' . number_format(max(0, $allowed), 2) . ' of accumulated profit is available to reinvest.');
            $today = date('Y-m-d'); $uid = (int) Context::userId(); $who = Context::role() === 'OWNER' ? 'by the Owner' : 'by the partner';
            $row = ['investor_id' => $partner, 'company_id' => $ent, 'entry_date' => $today, 'reference_no' => 'REINVEST', 'note' => mb_substr("Reinvested $who" . ($note ? ': ' . $note : ''), 0, 255), 'created_by' => $uid, 'created_at' => now()];
            $p = (int) DB::table('fin_investor_ledger')->insertGetId($row + ['type' => 'PROFIT', 'amount' => -$x]);
            $c = (int) DB::table('fin_investor_ledger')->insertGetId($row + ['type' => 'CAPITAL_IN', 'amount' => $x]);
            Ledger::post($ent, $today, 'PARTNER_REINVEST', $c, [['account' => '2100', 'debit' => $x, 'ref_id' => $partner], ['account' => '2150', 'credit' => $x, 'ref_id' => $partner]], ['memo' => 'Reinvest · ' . PartnerService::name($partner)]);
            $agr = null;
            $a = DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $partner)->where('scope', 'POOL')->where('status', 'ACTIVE')
                ->where('effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->orderByDesc('effective_from')->orderByDesc('id')->lockForUpdate()->first();
            if ($a) {
                $cap = round((float) $a->capital_amount + $x, 2);
                if ($a->effective_from >= $today) { DB::table('fin_investor_agreements')->where('id', $a->id)->update(['capital_amount' => $cap]); $agr = (int) $a->id; }
                else {
                    DB::table('fin_investor_agreements')->where('id', $a->id)->update(['effective_to' => date('Y-m-d', strtotime("$today -1 day")), 'status' => 'ENDED', 'ended_reason' => 'Reinvest: new capital ' . number_format($cap, 2)]);
                    $copy = (array) $a; unset($copy['id']);
                    $agr = (int) DB::table('fin_investor_agreements')->insertGetId(array_merge($copy, ['effective_from' => $today, 'capital_amount' => $cap, 'status' => 'ACTIVE', 'ended_reason' => null, 'created_by' => $uid, 'created_at' => now()]));
                }
                Audit::log('fin_investor_agreements', $agr, $agr === (int) $a->id ? 'UPDATE' : 'INSERT', ['capital_amount' => $cap, 'from' => $a->id], 'Reinvest', $ent);
            }
            Audit::log('fin_investor_ledger', $c, 'INSERT', ['type' => 'REINVEST', 'amount' => $x, 'profit_row' => $p, 'agreement' => $agr], 'Partner reinvest', $ent);
            $name = PartnerService::name($partner);
            foreach (DB::table('t_users')->where('role_code', 'OWNER')->where('f_active', 1)->pluck('id') as $o)
                if ((int) $o !== $uid) Notify::push((int) $o, 'FINANCE', "REINVEST: $name reinvested " . number_format($x, 2) . ' of profit as capital.', "#/partners/$partner");
            $capital = PartnerService::account($ent, $partner)['capital'];
            return ['id' => $c, 'agreement' => $agr, 'capital' => $capital, 'message' => 'Reinvested ' . number_format($x, 2) . ' – capital is now ' . number_format($capital, 2) . '.'];
        });
    }

    /** May this partner get a non-mandatory message on this channel? */
    public static function wants(int $partner, string $channel): bool
    {
        $c = $channel === 'WHATSAPP' ? 'wa_enabled' : 'email_enabled';
        return (bool) (DB::table('t_account')->where('id', $partner)->value($c) ?? 1);
    }
}
