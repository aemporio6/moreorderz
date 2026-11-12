<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Partners and investors (R-FIN-13, 41, 43, 46).
 * Agreements give a partner a % of a scope: the whole POOL, one shop (MARKETPLACE) or the goods of one PO.
 * When a shop × month is locked: distributable = profit of the shop-month − tax reserve; each agreement that was active on the first day of the month
 * gets base × %; a WORKING partner also gets his management fee. The rows go to the partner ledger and the books (Dr 7000 / Cr 2100).
 * A loss pays nothing. Capital in: Dr bank / Cr 2150. Withdrawal (after approval): Dr 2100 / Cr bank.
 */
class PartnerService
{
    public static function name(int $id): string
    {
        $a = DB::table('t_account')->where('id', $id)->first(['first_name', 'sur_name', 'account_code']);
        return $a ? (trim(($a->first_name ?? '') . ' ' . ($a->sur_name ?? '')) ?: (string) $a->account_code) : 'Partner ' . $id;
    }

    /** Which partner is this login? (role PARTNER sees only his own figures) */
    public static function partnerOfUser(int $userId): ?int
    {
        $p = DB::table('fin_partner_users')->where('user_id', $userId)->value('investor_id');
        return $p ? (int) $p : null;
    }

    // ───────────── agreements ─────────────

    private static function activeOn(int $ent, string $date)
    {
        return DB::table('fin_investor_agreements')->where('company_id', $ent)->where('status', 'ACTIVE')->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderBy('id')->get();
    }

    /** Σ share % per scope on a date: POOL, MARKETPLACE:<shop>, PO:<po>. */
    public static function scopeTotals(int $ent, string $date): array
    {
        $out = [];
        foreach (self::activeOn($ent, $date) as $a) { $k = $a->scope === 'POOL' ? 'POOL' : ($a->scope === 'MARKETPLACE' ? 'MARKETPLACE:' . $a->branch_id : 'PO:' . $a->po_id); $out[$k] = round(($out[$k] ?? 0) + (float) $a->profit_share_pct, 3); }
        return $out;
    }

    public static function scopeName(int $ent, string $key): string
    {
        if ($key === 'POOL') return 'Pool (all shops)';
        [$t, $id] = explode(':', $key);
        return $t === 'MARKETPLACE' ? 'Shop ' . (DB::table('t_branches')->where('id', $id)->value('shop_name') ?? $id) : 'PO ' . (DB::table('t_src_po')->where('id', $id)->value('po_number') ?? $id);
    }

    /** Save an agreement. Returns ['id', 'warning'] – a total over 100 % is saved with a warning and blocks the month close. */
    public static function saveAgreement(int $ent, array $d): array
    {
        $pct = (float) ($d['profit_share_pct'] ?? 0); $fee = (float) ($d['management_fee_pct'] ?? 0); $scope = $d['scope'] ?? 'POOL'; $type = $d['partner_type'] ?? 'SLEEPING';
        if (! DB::table('t_account')->where('id', $d['investor_id'] ?? 0)->exists()) throw new \InvalidArgumentException('Choose the partner.');
        if ($pct <= 0 || $pct > 100) throw new \InvalidArgumentException('The share must be more than 0 and at most 100 %.');
        if ($fee < 0 || $fee > 100) throw new \InvalidArgumentException('The management fee must be between 0 and 100 %.');
        if ($fee > 0 && $type !== 'WORKING') throw new \InvalidArgumentException('Only a working partner has a management fee.');
        if ($scope === 'MARKETPLACE' && ! DB::table('t_branches')->where('id', $d['branch_id'] ?? 0)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the shop for this agreement.');
        if ($scope === 'PO' && ! DB::table('t_src_po')->where('id', $d['po_id'] ?? 0)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the purchase order for this agreement.');
        if (! empty($d['effective_to']) && $d['effective_to'] < $d['effective_from']) throw new \InvalidArgumentException('The end date is before the start date.');
        $stack = $scope === 'PO' ? ($d['stacking_rule'] ?? 'SPLIT_UNITS') : 'SPLIT_UNITS'; $cap = $stack === 'CAPPED' ? (float) ($d['stack_cap_pct'] ?? 0) : null;
        if (! isset(WaterfallService::STACKING[$stack])) throw new \InvalidArgumentException('Choose how a share and own lots of the same PO go together.');
        if ($stack === 'CAPPED' && ($cap <= 0 || $cap > 100)) throw new \InvalidArgumentException('Give the cap: the most the share may be, as a % of the PO profit.');
        if ($stack === 'FULL_STACK' && Context::role() !== 'OWNER') throw new \InvalidArgumentException('Paying both in full needs the Owner.');
        $row = ['investor_id' => (int) $d['investor_id'], 'company_id' => $ent, 'partner_type' => $type, 'scope' => $scope, 'branch_id' => $scope === 'MARKETPLACE' ? (int) $d['branch_id'] : null, 'po_id' => $scope === 'PO' ? (int) $d['po_id'] : null,
            'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null, 'capital_amount' => $d['capital_amount'] ?? 0, 'profit_share_pct' => $pct, 'management_fee_pct' => $fee, 'status' => 'ACTIVE', 'stacking_rule' => $stack, 'stack_cap_pct' => $cap];
        if (! empty($d['id'])) {
            $old = DB::table('fin_investor_agreements')->where('id', $d['id'])->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Agreement not found.');
            if (DB::table('fin_investor_ledger')->where('investor_id', $old->investor_id)->where('company_id', $ent)->where('type', 'PROFIT')->where('note', 'like', '%#' . $old->id . ' %')->exists()) throw new \InvalidArgumentException('A locked month already paid on this agreement. End it and make a new one from the date of the change.');
            DB::table('fin_investor_agreements')->where('id', $old->id)->update($row); $id = (int) $old->id;
        } else $id = (int) DB::table('fin_investor_agreements')->insertGetId($row + ['created_by' => Context::userId(), 'created_at' => now()]);
        Audit::log('fin_investor_agreements', $id, empty($d['id']) ? 'INSERT' : 'UPDATE', ['partner' => self::name((int) $d['investor_id']), 'scope' => $scope, 'pct' => $pct], null, $ent);
        $warn = null; $on = max($d['effective_from'], date('Y-m-d'));
        foreach (self::scopeTotals($ent, $on) as $k => $t) if ($t > 100.0001) $warn = 'WARNING: total share is over 100% in ' . self::scopeName($ent, $k) . ' (' . rtrim(rtrim(number_format($t, 3), '0'), '.') . '%). The period cannot close until this is fixed.';
        return ['id' => $id, 'warning' => $warn];
    }

    public static function endAgreement(int $ent, int $id, string $date, string $reason): void
    {
        $a = DB::table('fin_investor_agreements')->where('id', $id)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Agreement not found.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for ending the agreement.');
        if ($date < $a->effective_from) throw new \InvalidArgumentException('The end date is before the start date.');
        DB::table('fin_investor_agreements')->where('id', $id)->update(['effective_to' => $date, 'status' => $date < date('Y-m-d') ? 'ENDED' : 'ACTIVE', 'ended_reason' => mb_substr($reason, 0, 190)]);
        Audit::log('fin_investor_agreements', $id, 'UPDATE', ['ended' => $date], $reason, $ent);
    }

    // ───────────── shares ─────────────

    /** Tax reserve before sharing (R-FIN-41): max(0, net) × (VAT rate + corporation tax rate) %. */
    public static function distributable(int $ent, float $net): array
    {
        $s = DB::table('fin_company_settings')->where('company_id', $ent)->first(['vat_rate', 'corp_tax_rate']); $rate = (float) ($s->vat_rate ?? 0) + (float) ($s->corp_tax_rate ?? 0);
        $reserve = round(max(0, $net) * $rate / 100, 2);
        return ['net' => round($net, 2), 'rate' => $rate, 'tax_reserve' => $reserve, 'distributable' => round($net - $reserve, 2)];
    }

    /** Pure sum: base × % (and the fee for a working partner). $bases: scope key → base. Nothing is paid on a base that is not above zero. */
    public static function shareLines(iterable $agreements, array $bases): array
    {
        $out = [];
        foreach ($agreements as $a) {
            $k = $a->scope === 'POOL' ? 'POOL' : ($a->scope === 'MARKETPLACE' ? 'MARKETPLACE:' . $a->branch_id : 'PO:' . $a->po_id);
            if (! array_key_exists($k, $bases)) continue; $base = (float) $bases[$k];
            $profit = $base > 0 ? round($base * (float) $a->profit_share_pct / 100, 2) : 0.0; $fee = $base > 0 && $a->partner_type === 'WORKING' ? round($base * (float) $a->management_fee_pct / 100, 2) : 0.0;
            $out[] = ['agreement' => (int) $a->id, 'partner' => (int) $a->investor_id, 'scope' => $k, 'pct' => (float) $a->profit_share_pct, 'fee_pct' => (float) $a->management_fee_pct, 'type' => $a->partner_type, 'base' => round($base, 2), 'profit' => $profit, 'fee' => $fee];
        }
        return $out;
    }

    /**
     * The waterfall and the shares of one shop × month (order and rules: WaterfallService). Agreements and the split rule are the ones valid for that month, never today's.
     * Without a split rule, a lot owner or a loss to carry this is exactly "distributable × agreement %".
     */
    public static function monthShares(int $ent, int $branchId, string $month): array
    {
        $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); $r2 = fn ($v) => round((float) $v, 2);
        $fig = CloseService::figures($ent, $branchId, $month); $net = (float) $fig['profit_to_share']; $ags = self::activeOn($ent, $from);
        $split = WaterfallService::split($ent, $branchId, $to); $route = WaterfallService::route($ent, $branchId, $month);
        $vaSalary = $split && $split->va_mode !== 'SHARE' ? (float) $split->va_salary_override : 0.0; $ownerFixed = $split && $split->owner_mode !== 'SHARE' ? (float) $split->owner_fixed : 0.0; $handlerFixed = $split && $split->handler_mode !== 'SHARE' ? (float) $split->handler_fixed : 0.0;
        $final = $r2($net - $vaSalary - $ownerFixed - $handlerFixed); $lossBf = WaterfallService::lossBroughtForward($ent, $branchId, $month); $afterLoss = $r2($final - $lossBf);
        $d = self::distributable($ent, $afterLoss) + ['shop_profit' => (float) $fig['profit'], 'overhead_share' => $fig['share_after_overheads'] ? (float) $fig['overhead_share'] : 0.0];
        $D = (float) $d['distributable']; $pos = $D > 0; $lossCf = $pos ? 0.0 : $r2(-$D);
        $vaShare = $pos && $split && $split->va_mode !== 'SALARY' ? $r2($D * (float) $split->va_pct / 100) : 0.0; $handlerShare = $pos && $split && $split->handler_mode !== 'FIXED' ? $r2($D * (float) $split->handler_pct / 100) : 0.0;
        $ownerShare = $pos && $split && $split->owner_mode !== 'FIXED' ? $r2($D * (float) $split->owner_pct / 100) : 0.0;
        $w = [];
        if ($vaSalary) $w[] = ['label' => 'VA salary', 'amount' => -$vaSalary]; if ($ownerFixed) $w[] = ['label' => 'Account owner fixed fee', 'amount' => -$ownerFixed]; if ($handlerFixed) $w[] = ['label' => 'Handler fixed fee', 'amount' => -$handlerFixed];
        if ($vaSalary || $ownerFixed || $handlerFixed) $w[] = ['label' => 'Final net profit', 'amount' => $final, 'total' => true];
        if ($lossBf) $w[] = ['label' => 'Loss brought forward from earlier months', 'amount' => -$lossBf];
        if ($d['tax_reserve']) $w[] = ['label' => 'Tax reserve (' . rtrim(rtrim(number_format($d['rate'], 2), '0'), '.') . '%)', 'amount' => -$d['tax_reserve']];
        $w[] = ['label' => $pos ? 'Distributable' : 'Nothing to share this month', 'amount' => $D, 'total' => true];
        if ($lossCf) $w[] = ['label' => 'Loss carried forward to the next month', 'amount' => $lossCf];
        if ($vaShare) $w[] = ['label' => 'VA share ' . (float) $split->va_pct . '%', 'amount' => -$vaShare]; if ($handlerShare) $w[] = ['label' => 'Handler share ' . (float) $split->handler_pct . '%', 'amount' => -$handlerShare];

        // profit of the units sold in the month, by PO and by lot owner (sale profit × units from that lot group ÷ units of the line), after the tax reserve
        $raw = []; $costBy = [];
        if ($pos) {
            $sales = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->where('status', 'Confirmed')->whereBetween('d_order', [$from, $to])->get(['id', 'qty', 'total_amount_gbp', 'order_no'])->keyBy('id');
            $cons = DB::table('fin_lot_consumption as c')->join('fin_lots as l', 'l.id', '=', 'c.lot_id')->whereIn('c.fk_id_sale', $sales->keys())->groupBy('c.fk_id_sale', 'l.po_id', 'l.fk_id_owner')->selectRaw('c.fk_id_sale, l.po_id, l.fk_id_owner, SUM(c.qty) q, SUM(c.qty * c.unit_cost_gbp) cost')->get();
            if ($cons->whereNotNull('fk_id_owner')->isNotEmpty() || $ags->where('scope', 'PO')->isNotEmpty()) {
                $cost = []; foreach ($cons as $c) $cost[$c->fk_id_sale] = ($cost[$c->fk_id_sale] ?? 0) + (float) $c->cost;
                $fees = DB::table('fin_settlement_lines')->where('company_id', $ent)->where('branch_id', $branchId)->where('amount_type', '<>', 'NET_SALES')->whereIn('order_id', $sales->pluck('order_no'))->groupBy('order_id')->selectRaw('order_id, SUM(amount) v')->pluck('v', 'order_id');
                foreach ($cons as $c) { $s = $sales[$c->fk_id_sale]; if ((int) $s->qty <= 0) continue; $po = (int) $c->po_id; $ow = (int) $c->fk_id_owner;
                    $raw[$po][$ow] = ($raw[$po][$ow] ?? 0) + ((float) $s->total_amount_gbp - ($cost[$s->id] ?? 0) + (float) ($fees[$s->order_no] ?? 0)) * ((float) $c->q / (int) $s->qty); $costBy[$ow] = ($costBy[$ow] ?? 0) + (float) $c->cost; }
            } else foreach ($cons as $c) $costBy[(int) $c->fk_id_owner] = ($costBy[(int) $c->fk_id_owner] ?? 0) + (float) $c->cost;
        }
        $lines = []; $roles = [];
        if ($vaSalary + $vaShare != 0.0) $roles[] = ['role' => 'VA', 'label' => 'VA' . ($vaSalary ? ' salary ' . number_format($vaSalary, 2) : '') . ($vaShare ? ($vaSalary ? ' + ' : ' ') . 'share ' . number_format($vaShare, 2) : ''), 'amount' => $r2($vaSalary + $vaShare), 'party' => null];
        if ($handlerFixed + $handlerShare != 0.0) $roles[] = ['role' => 'HANDLER', 'label' => 'Handler' . ($split->fk_id_handler_party ? ' (' . self::name((int) $split->fk_id_handler_party) . ')' : ' (the company itself)'), 'amount' => $r2($handlerFixed + $handlerShare), 'party' => (int) $split->fk_id_handler_party ?: null];
        $mk = fn (int $partner, string $scope, string $kind, float $pct, float $base, float $profit, float $fee = 0.0, int $ag = 0, float $feePct = 0.0, string $type = 'SLEEPING') => ['agreement' => $ag, 'partner' => $partner, 'scope' => $scope, 'kind' => $kind, 'pct' => $pct, 'fee_pct' => $feePct, 'type' => $type, 'base' => $r2($base), 'profit' => $r2($profit), 'fee' => $r2($fee)];
        $afterRoles = $r2($D - $vaShare - $handlerShare);
        if ($pos && $route === 'INVESTOR_INVOICING' && $split) {
            // the old four-way split: investor % of the distributable, to the lot owners by the cost of their units sold; units of the company's own lots stay with the company
            $pool = $r2($D * (float) $split->investor_pct / 100); $totalCost = array_sum($costBy);
            $w[] = ['label' => 'Investors ' . (float) $split->investor_pct . '%', 'amount' => -$pool]; if ($ownerShare) $w[] = ['label' => 'Account owner share ' . (float) $split->owner_pct . '% (stays in the company)', 'amount' => -$ownerShare];
            foreach ($costBy as $ow => $c) if ($ow && $totalCost > 0 && $c > 0) $lines[] = $mk($ow, 'LOTS', 'OWN_LOT', round($c / $totalCost * 100, 3), $pool, $pool * $c / $totalCost);
            if ($ownerFixed + $ownerShare != 0.0) $roles[] = ['role' => 'OWNER', 'label' => 'Account owner' . ($ownerFixed ? ' fixed ' . number_format($ownerFixed, 2) : '') . ($ownerShare ? ' share ' . number_format($ownerShare, 2) : '') . ' – stays in the company', 'amount' => $r2($ownerFixed + $ownerShare), 'party' => null];
        } else {
            $k = $D > 0 ? $afterRoles / $D : 0.0; $net1 = fn (float $v) => ($v - max(0, $v) * $d['rate'] / 100) * $k;   // after the tax reserve and the VA / handler shares
            // how much of his own units' profit a lot owner gets: a company choice (100 %, his agreement's %, or a fixed %)
            $lo = Choices::get('LOT_OWNER_SHARE', $ent); $ownPct = function (int $ow, int $po) use ($lo, $ags, $branchId): float { if ($lo !== 'AGREEMENT') return (float) $lo;
                $a = $ags->first(fn ($x) => (int) $x->investor_id === $ow && $x->scope === 'PO' && (int) $x->po_id === $po) ?? $ags->first(fn ($x) => (int) $x->investor_id === $ow && ($x->scope === 'POOL' || ($x->scope === 'MARKETPLACE' && (int) $x->branch_id === $branchId))); return $a ? (float) $a->profit_share_pct : 100.0; };
            $ownBy = []; $ownFull = []; foreach ($raw as $po => $byOwner) foreach ($byOwner as $ow => $v) if ($ow && $v > 0) { $ownFull[$ow][$po] = $net1($v); $ownBy[$ow][$po] = $net1($v) * $ownPct($ow, $po) / 100; }
            foreach ($ownBy as $ow => $pos2) foreach ($pos2 as $po => $v) $lines[] = $mk($ow, $po ? 'PO:' . $po : 'LOTS', 'OWN_LOT', $ownPct($ow, $po), $ownFull[$ow][$po], $v);
            $ownTotal = array_sum(array_map('array_sum', $ownBy)); $rest = $r2($afterRoles - $ownTotal);
            if ($ownTotal) $w[] = ['label' => 'Lot owners: profit of the units sold from their own lots', 'amount' => -$r2($ownTotal)];
            foreach ($ags as $a) {
                $pct = (float) $a->profit_share_pct; $feePct = $a->partner_type === 'WORKING' ? (float) $a->management_fee_pct : 0.0; $cap = null;
                if ($a->scope === 'PO') {
                    $po = (int) $a->po_id; if (! isset($raw[$po])) continue;
                    $own = (float) ($ownBy[(int) $a->investor_id][$po] ?? 0); $total = 0.0; foreach ($raw[$po] as $v) $total += $net1($v);
                    $ownsAny = DB::table('fin_lots')->where('company_id', $ent)->where('po_id', $po)->where('fk_id_owner', $a->investor_id)->exists();
                    $own = (float) ($ownFull[(int) $a->investor_id][$po] ?? 0);   // the units that are his, whatever % of their profit he gets
                    [, $base, $cap] = WaterfallService::stack((string) ($a->stacking_rule ?: 'SPLIT_UNITS'), $own, $total - $own, $ownsAny, $a->stack_cap_pct !== null ? (float) $a->stack_cap_pct : null); $key = 'PO:' . $po;
                } else { if ($a->scope === 'MARKETPLACE' && (int) $a->branch_id !== $branchId) continue; $base = $rest; $key = $a->scope === 'POOL' ? 'POOL' : 'MARKETPLACE:' . $branchId; }
                $profit = $base > 0 ? round($base * $pct / 100, 2) : 0.0; if ($cap !== null) $profit = min($profit, $cap);
                $lines[] = $mk((int) $a->investor_id, $key, 'SHARE', $pct, $base, $profit, $base > 0 ? round($base * $feePct / 100, 2) : 0.0, (int) $a->id, (float) $a->management_fee_pct, $a->partner_type);
            }
        }
        foreach ($lines as &$l) { $l['partner_name'] = self::name($l['partner']); $l['scope_name'] = $l['scope'] === 'LOTS' ? 'Own lots' : self::scopeName($ent, $l['scope']); } unset($l);
        $paid = round(array_sum(array_column($lines, 'profit')) + array_sum(array_column($lines, 'fee')), 2);
        if ($paid) $w[] = ['label' => 'Partners and investors', 'amount' => -$paid];
        $left = $pos ? $r2($afterRoles - $paid) : 0.0; $w[] = ['label' => 'Left in the company', 'amount' => $left, 'total' => true];
        return $d + ['lines' => $lines, 'to_partners' => $paid, 'left_for_company' => $pos ? $left : $r2($D), 'agreements' => $ags->count(), 'route' => $route, 'route_name' => WaterfallService::ROUTES[$route] ?? $route, 'split' => $split ? (int) $split->id : null,
            'loss_bf' => $lossBf, 'loss_cf' => $lossCf, 'final_net' => $final, 'roles' => $roles, 'waterfall' => $w];
    }

    /** Called inside the month lock: write the PROFIT / MANAGEMENT_FEE rows once, and what is owed to the VA and to an outside handler. */
    public static function postMonth(int $ent, int $branchId, string $month, object $run): array
    {
        $sh = self::monthShares($ent, $branchId, $month); $end = date('Y-m-t', strtotime($month . '-01')); $n = 0;
        foreach ($sh['lines'] as $l) foreach ([['PROFIT', $l['profit'], $l['pct']], ['MANAGEMENT_FEE', $l['fee'], $l['fee_pct']]] as [$type, $amount, $pct]) {
            if ($amount == 0.0) continue;
            $id = DB::table('fin_investor_ledger')->insertGetId(['investor_id' => $l['partner'], 'company_id' => $ent, 'period_id' => $run->id, 'entry_date' => $end, 'type' => $type, 'amount' => $amount, 'reference_no' => 'CLS ' . $run->run_code,
                'note' => mb_substr(($l['agreement'] ? "#{$l['agreement']} " : '') . (($l['kind'] ?? '') === 'OWN_LOT' ? 'Own lots · ' : '') . "{$l['scope_name']} " . rtrim(rtrim(number_format($pct, 3), '0'), '.') . '%', 0, 255), 'created_by' => Context::userId(), 'created_at' => now()]);
            Ledger::post($ent, $end, 'PARTNER_SHARE', $id, [['account' => '7000', 'debit' => $amount, 'ref_id' => $l['partner']], ['account' => '2100', 'credit' => $amount, 'ref_id' => $l['partner']]], ['memo' => ($type === 'PROFIT' ? 'Profit share ' : 'Management fee ') . $l['partner_name'] . ' · ' . $month]);
            $n++;
        }
        foreach ($sh['roles'] as $r) {
            if ($r['role'] === 'VA') Ledger::post($ent, $end, 'WATERFALL_VA', (int) $run->id, [['account' => '6100', 'debit' => $r['amount']], ['account' => '2300', 'credit' => $r['amount']]], ['memo' => "VA salary / share · $month", 'allow_no_ref' => true]);
            if ($r['role'] === 'HANDLER' && $r['party']) Ledger::post($ent, $end, 'WATERFALL_HANDLER', (int) $run->id, [['account' => '6000', 'debit' => $r['amount']], ['account' => '2000', 'credit' => $r['amount'], 'ref_id' => $r['party']]], ['memo' => "Handler fee / share · $month"]);
        }
        DB::table('aud_runs')->where('id', $run->id)->update(['loss_brought_forward' => $sh['loss_bf'], 'profit_route' => $sh['route']]);
        return $sh + ['rows' => $n];
    }

    // ───────────── partner account ─────────────

    /** Capital, profit to date, withdrawn and what the company owes the partner now (optionally as at a date). */
    public static function account(int $ent, int $partner, ?string $upTo = null): array
    {
        $q = DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $partner)->when($upTo, fn ($x) => $x->where('entry_date', '<=', $upTo));
        $by = (clone $q)->groupBy('type')->selectRaw('type, SUM(amount) v')->pluck('v', 'type'); $g = fn ($t) => round((float) ($by[$t] ?? 0), 2);
        $earned = round($g('PROFIT') + $g('MANAGEMENT_FEE') + $g('ADJUSTMENT'), 2); $out = round(abs($g('WITHDRAWAL')) + abs($g('REINVEST')), 2);
        return ['capital' => round($g('CAPITAL_IN') + abs($g('REINVEST')), 2), 'profit_to_date' => round($g('PROFIT') + $g('MANAGEMENT_FEE'), 2), 'adjustments' => $g('ADJUSTMENT'), 'withdrawn' => abs($g('WITHDRAWAL')), 'balance' => round($earned - $out, 2)];
    }

    public static function capitalIn(int $ent, int $partner, float $amount, int $bankId, string $date, ?string $note = null): int
    {
        return DB::transaction(function () use ($ent, $partner, $amount, $bankId, $date, $note) {
            if ($amount <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
            if (! DB::table('t_account')->where('id', $partner)->exists()) throw new \InvalidArgumentException('Choose the partner.');
            $bank = DB::table('fin_bank_accounts')->where('id', $bankId)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Choose a bank account of this company.');
            $base = Fx::toBase($amount, $bank->currency ?: Fx::base($ent), $date, $ent);
            $id = DB::table('fin_investor_ledger')->insertGetId(['investor_id' => $partner, 'company_id' => $ent, 'entry_date' => $date, 'type' => 'CAPITAL_IN', 'amount' => $base, 'note' => $note ? mb_substr($note, 0, 255) : null, 'created_by' => Context::userId(), 'created_at' => now()]);
            Ledger::post($ent, $date, 'PARTNER_CAPITAL', $id, [['account' => '1000', 'debit' => $base, 'ref_id' => (int) $bank->id, 'amount_cur' => $amount], ['account' => '2150', 'credit' => $base, 'ref_id' => $partner]], ['memo' => 'Capital in · ' . self::name($partner), 'currency' => $bank->currency ?: 'GBP']);
            Audit::log('fin_investor_ledger', $id, 'INSERT', ['type' => 'CAPITAL_IN', 'amount' => $base], $note, $ent);
            return $id;
        });
    }

    /** Ask to take profit out. Never more than the balance. It always waits for a second person unless the Owner enters it. */
    public static function requestWithdrawal(int $ent, int $partner, float $amount, int $bankId, ?string $note = null): array
    {
        return DB::transaction(function () use ($ent, $partner, $amount, $bankId, $note) {
            if ($amount <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
            DB::table('fin_bank_accounts')->where('id', $bankId)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Choose the bank account to pay from.');
            $waiting = (float) DB::table('fin_withdrawals')->where('company_id', $ent)->where('investor_id', $partner)->where('status', 'REQUESTED')->sum('amount'); $bal = self::account($ent, $partner)['balance'];
            if ($amount > $bal - $waiting + 0.005) throw new \InvalidArgumentException('This is more than the ' . number_format(max(0, $bal - $waiting), 2) . ' that can be taken out now' . ($waiting > 0 ? ' (' . number_format($waiting, 2) . ' is already waiting)' : '') . '.');
            $id = (int) DB::table('fin_withdrawals')->insertGetId(['investor_id' => $partner, 'company_id' => $ent, 'amount' => round($amount, 2), 'currency' => Fx::base($ent), 'requested_by' => (int) Context::userId(), 'requested_at' => now(), 'status' => 'REQUESTED', 'note' => $note ? mb_substr($note, 0, 255) : null, 'fk_id_pay_to_bank' => $bankId]);
            if (Context::role() === 'OWNER') { self::payWithdrawal($id); return ['id' => $id, 'status' => 'PAID']; }
            $a = Approvals::request($ent, 'WITHDRAWAL', 'fin_withdrawals', $id, round($amount, 2), ['category' => 'Withdrawal · ' . self::name($partner), 'amount' => round($amount, 2), 'currency' => Fx::base($ent), 'note' => $note], 'Approve withdrawal of ' . self::name($partner) . ': ' . number_format($amount, 2));
            DB::table('fin_withdrawals')->where('id', $id)->update(['task_id' => DB::table('sys_approvals')->where('id', $a)->value('fk_id_task')]);
            return ['id' => $id, 'status' => 'WAITING', 'approval' => $a];
        });
    }

    public static function payWithdrawal(int $id): void
    {
        $w = DB::table('fin_withdrawals')->where('id', $id)->lockForUpdate()->first(); if (! $w || $w->status !== 'REQUESTED') return;
        $ent = (int) $w->company_id; $date = date('Y-m-d');
        $lid = DB::table('fin_investor_ledger')->insertGetId(['investor_id' => $w->investor_id, 'company_id' => $ent, 'entry_date' => $date, 'type' => 'WITHDRAWAL', 'amount' => -1 * (float) $w->amount, 'reference_no' => 'WD ' . $w->id, 'note' => $w->note, 'created_by' => Context::userId(), 'created_at' => now()]);
        Ledger::post($ent, $date, 'WITHDRAWAL', $lid, [['account' => '2100', 'debit' => (float) $w->amount, 'ref_id' => (int) $w->investor_id], ['account' => '1000', 'credit' => (float) $w->amount, 'ref_id' => (int) $w->fk_id_pay_to_bank, 'amount_cur' => -1 * (float) $w->amount]], ['memo' => 'Withdrawal · ' . self::name((int) $w->investor_id)]);
        DB::table('fin_withdrawals')->where('id', $id)->update(['status' => 'PAID', 'approved_by' => Context::userId(), 'approved_at' => now(), 'paid_at' => now()]);
    }

    /** Partners of the company: anyone with an agreement or a ledger row. */
    public static function partners(int $ent, ?int $only = null): array
    {
        $ids = DB::table('fin_investor_agreements')->where('company_id', $ent)->pluck('investor_id')->merge(DB::table('fin_investor_ledger')->where('company_id', $ent)->pluck('investor_id'))->unique()->when($only, fn ($c) => $c->filter(fn ($i) => (int) $i === $only))->values(); $today = date('Y-m-d');
        return $ids->map(function ($id) use ($ent, $today) {
            $ags = DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $id)->orderByDesc('id')->get();
            return ['id' => (int) $id, 'name' => self::name((int) $id)] + self::account($ent, (int) $id) + ['agreements' => $ags->map(fn ($a) => ['id' => (int) $a->id, 'type' => $a->partner_type, 'scope' => $a->scope, 'branch_id' => $a->branch_id, 'po_id' => $a->po_id,
                'scope_name' => self::scopeName($ent, $a->scope === 'POOL' ? 'POOL' : ($a->scope === 'MARKETPLACE' ? 'MARKETPLACE:' . $a->branch_id : 'PO:' . $a->po_id)), 'pct' => (float) $a->profit_share_pct, 'fee_pct' => (float) $a->management_fee_pct, 'from' => $a->effective_from, 'to' => $a->effective_to,
                'capital' => $a->capital_amount, 'stacking_rule' => $a->stacking_rule, 'stack_cap_pct' => $a->stack_cap_pct, 'live' => $a->status === 'ACTIVE' && $a->effective_from <= $today && (! $a->effective_to || $a->effective_to >= $today), 'ended_reason' => $a->ended_reason])->all()];
        })->all();
    }

    /** The partner's statement of one locked shop × month: his share lines and his account as at the month end. */
    public static function statements(int $ent, int $partner): array
    {
        $rows = DB::table('fin_investor_ledger as l')->join('aud_runs as r', 'r.id', '=', 'l.period_id')->leftJoin('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->where('l.company_id', $ent)->where('l.investor_id', $partner)->whereIn('l.type', ['PROFIT', 'MANAGEMENT_FEE'])
            ->orderByDesc('r.period_month')->orderBy('l.id')->get(['r.id as run', 'r.run_code', 'r.period_month', 'r.owner_note', 'r.locked_at', 'r.net_profit', 'b.shop_name as shop', 'l.type', 'l.amount', 'l.note']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->run] ??= ['run' => (int) $r->run, 'code' => $r->run_code, 'month' => $r->period_month, 'month_name' => date('F Y', strtotime($r->period_month . '-01')), 'shop' => $r->shop, 'owner_note' => $r->owner_note, 'locked_at' => $r->locked_at, 'shop_profit' => (float) $r->net_profit, 'lines' => [], 'total' => 0.0,
                'account' => self::account($ent, $partner, date('Y-m-t', strtotime($r->period_month . '-01')))];
            $out[$r->run]['lines'][] = ['type' => $r->type, 'amount' => (float) $r->amount, 'note' => preg_replace('/^#\d+ /', '', (string) $r->note)]; $out[$r->run]['total'] = round($out[$r->run]['total'] + (float) $r->amount, 2);
        }
        return array_values($out);
    }
}
