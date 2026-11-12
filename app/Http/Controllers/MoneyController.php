<?php

namespace App\Http\Controllers;

use App\Services\Approvals;
use App\Services\Context;
use App\Services\Fx;
use App\Services\MoneyService as Money;
use App\Services\SettlementService as Settle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Money: bank and cash, expenses, approvals, marketplace statements (payouts and settlements). */
class MoneyController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'];
    private const EDIT = ['OWNER', 'FINANCE'];
    private const EXPENSE = ['OWNER', 'MANAGER', 'FINANCE'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function lists(int $ent): array
    {
        return ['categories' => DB::table('fin_categories')->where('f_active', 1)->whereNotIn('code', ['SUPPLIER_PAYMENT', 'SPLIT', 'OWN_TRANSFER_IN', 'OWN_TRANSFER_OUT'])->orderBy('sort_no')->orderBy('name')->get(['code', 'name', 'direction'])
                ->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'direction' => $c->direction, 'needs_shop' => in_array(Money::ACCOUNTS[$c->code] ?? '', ['1100', '5100', '5300', '5400'], true)]),
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name'])];
    }

    // ---- bank ----
    public function bank(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $show = $r->query('show', 'UNMATCHED'); $bank = (int) $r->query('bank', 0);
        $lines = DB::table('fin_bank_transactions as t')->join('fin_bank_accounts as b', 'b.id', '=', 't.bank_account_id')->where('t.company_id', $ent)
            ->when($show !== 'ALL', fn ($q) => $q->where('t.match_status', 'UNMATCHED'))->when($bank, fn ($q) => $q->where('t.bank_account_id', $bank))->orderByDesc('t.txn_date')->orderByDesc('t.id')->limit(300)
            ->get(['t.id', 't.bank_account_id', 'b.account_label as bank', 't.txn_date', 't.amount', 't.currency', 't.description', 't.reference_no', 't.match_status', 't.matched_to_type', 't.match_rule', 't.category_code']);
        $names = DB::table('fin_categories')->pluck('name', 'code'); foreach ($lines as $l) $l->category = $names[$l->category_code] ?? null;
        return ['banks' => Money::banks($ent), 'lines' => $lines, 'show' => $show, 'bank' => $bank, 'base' => Fx::base($ent), 'can_edit' => in_array($r->user()->role_code, self::EDIT, true),
            'profiles' => DB::table('bank_statement_profiles')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('id')->get(['id', 'name', 'date_format'])] + $this->lists($ent);
    }

    public function bankCreate(Request $r)
    {
        $this->can($r, self::EDIT);
        $d = $r->validate(['bank_name' => 'required|string|max:100', 'account_label' => 'required|string|max:100', 'currency' => 'required|string|size:3', 'opening_balance' => 'nullable|numeric', 'opening_date' => 'nullable|date']);
        return $this->run(fn () => ['id' => Money::createBank((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)), 'message' => 'Bank account added']);
    }

    public function bankImport(Request $r, int $id)
    {
        $this->can($r, self::EDIT);
        $d = $r->validate(['profile' => 'required|integer', 'csv' => 'required|string|max:5000000', 'file_name' => 'nullable|string|max:200']);
        return $this->run(function () use ($id, $d) {
            $x = Money::importStatement((int) Context::ent(), $id, (int) $d['profile'], $d['csv'], $d['file_name'] ?? 'statement.csv');
            return $x + ['message' => "{$x['new']} new line(s), {$x['matched']} matched by the tool" . ($x['duplicates'] ? ", {$x['duplicates']} were already loaded" : '') . ($x['errors'] ? ', ' . count($x['errors']) . ' row(s) could not be read' : '')];
        });
    }

    public function lineAction(Request $r, int $id, string $what)
    {
        $this->can($r, self::EDIT);
        $ent = (int) Context::ent();
        return $this->run(function () use ($r, $id, $what, $ent) {
            if ($what === 'categorise') { $d = $r->validate(['category' => 'required|string|max:40', 'branch' => 'nullable|integer', 'note' => 'nullable|string|max:200']); Money::categorise($ent, $id, array_filter($d, fn ($v) => $v !== null)); return ['message' => 'Booked']; }
            if ($what === 'transfer') { $d = $r->validate(['other' => 'required|integer']); $x = Money::transfer($ent, $id, (int) $d['other']); return ['message' => 'Transfer booked' . ($x['mirror'] ? ' – the line in the other account is linked too' : '')]; }
            if ($what === 'payout') { $d = $r->validate(['branch' => 'required|integer', 'pay_key' => 'required|string|max:40']); Money::matchPayout($ent, $id, (int) $d['branch'], $d['pay_key']); return ['message' => 'Payout matched']; }
            if ($what === 'ignore') { $d = $r->validate(['reason' => 'required|string|max:200']); Money::ignore($ent, $id, $d['reason']); return ['message' => 'Line set aside']; }
            Money::unmatch($ent, $id); return ['message' => 'Match undone'];
        });
    }

    /** Booked payouts of exactly this line's amount, for a person to pick from. */
    public function payouts(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $l = DB::table('fin_bank_transactions')->where('id', $id)->where('company_id', $ent)->first() ?? abort(404);
        $names = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id');
        return ['rows' => array_map(fn ($c) => ['branch' => (int) $c->fk_id_branch, 'shop' => $names[$c->fk_id_branch] ?? '', 'pay_key' => (string) $c->pay_key, 'date' => $c->pay_date, 'statements' => (int) $c->n], \App\Services\SettlementService::payoutCandidates($ent, (float) $l->amount, (string) $l->currency, $l->txn_date, 3650))];
    }

    public function rematch(Request $r, int $id)
    {
        $this->can($r, self::EDIT);
        abort_unless(DB::table('fin_bank_accounts')->where('id', $id)->where('company_id', Context::ent())->exists(), 404);
        $n = Money::autoMatch((int) Context::ent(), $id);
        return ['matched' => $n, 'message' => $n ? "$n line(s) matched" : 'Nothing more could be matched for sure'];
    }

    // ---- expenses ----
    public function expenses(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('month')) ? $r->query('month') : date('Y-m');
        $rows = DB::table('fin_expenses as e')->leftJoin('fin_bank_accounts as b', 'b.id', '=', 'e.fk_id_paid_from')->leftJoin('t_users as u', 'u.id', '=', 'e.created_by')
            ->leftJoin('sys_approvals as a', fn ($j) => $j->on('a.entity_id', '=', 'e.id')->where('a.entity_table', 'fin_expenses'))->where('e.fk_id_ent', $ent)->whereRaw("DATE_FORMAT(e.d_expense, '%Y-%m') = ?", [$month])->orderByDesc('e.d_expense')->orderByDesc('e.id')
            ->get(['e.id', 'e.d_expense', 'e.category_code', 'e.amount', 'b.currency', 'e.amount_gbp', 'b.account_label as bank', 'e.note', 'e.source', 'e.f_active', 'e.fk_id_bank_line', 'a.status as approval', 'u.display_name as by']);
        $names = DB::table('fin_categories')->pluck('name', 'code');
        foreach ($rows as $x) $x->category = $names[$x->category_code] ?? $x->category_code;
        foreach ($rows as $x) $x->status = $x->approval === 'PENDING' ? 'WAITING' : ($x->approval === 'REJECTED' ? 'REJECTED' : ($x->f_active ? ($x->fk_id_bank_line ? 'ON_BANK' : 'BOOKED') : 'CANCELLED'));
        return ['rows' => $rows, 'month' => $month, 'total' => round((float) $rows->whereIn('status', ['BOOKED', 'ON_BANK'])->sum('amount_gbp'), 2), 'base' => Fx::base($ent), 'limit' => Approvals::limit($ent, 'EXPENSE', 250),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name', 'currency']), 'can_add' => in_array($r->user()->role_code, self::EXPENSE, true)] + $this->lists($ent);
    }

    public function expenseAdd(Request $r)
    {
        $this->can($r, self::EXPENSE);
        $d = $r->validate(['bank' => 'required|integer', 'category' => 'required|string|max:40', 'amount' => 'required|numeric|min:0.01', 'date' => 'required|date', 'branch' => 'nullable|integer', 'note' => 'nullable|string|max:200']);
        return $this->run(function () use ($d) { $x = Money::addExpense((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['status'] === 'WAITING' ? 'Saved – it is over the limit, so it waits for approval and is not booked yet' : 'Expense booked']; });
    }

    public function expenseCancel(Request $r, int $id)
    {
        $this->can($r, self::EXPENSE);
        $d = $r->validate(['reason' => 'required|string|max:200']);
        return $this->run(function () use ($id, $d) { Money::cancelExpense((int) Context::ent(), $id, $d['reason']); return ['message' => 'Expense cancelled']; });
    }

    // ---- approvals ----
    public function approvals(Request $r)
    {
        $this->can($r, self::SEE);
        $rows = Approvals::pending((int) Context::ent()); $me = (int) $r->user()->id; $ok = in_array($r->user()->role_code, Approvals::APPROVERS, true);
        foreach ($rows as $a) $a->can_decide = $ok && $a->status === 'PENDING' && (int) $a->requested_by !== $me;
        return ['rows' => $rows, 'waiting' => count(array_filter($rows, fn ($a) => $a->status === 'PENDING')), 'base' => Fx::base((int) Context::ent())];
    }

    public function decide(Request $r, int $id)
    {
        $this->can($r, Approvals::APPROVERS);
        $d = $r->validate(['approve' => 'required|boolean', 'reason' => 'nullable|string|max:300']);
        return $this->run(function () use ($id, $d) { Approvals::decide($id, (bool) $d['approve'], $d['reason'] ?? null); return ['message' => $d['approve'] ? 'Approved and booked' : 'Rejected']; });
    }

    // ---- marketplace statements ----
    public function settlements(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $shop = (int) $r->query('shop', 0);
        $q = DB::table('mo_settlements as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->when($shop, fn ($x) => $x->where('s.fk_id_branch', $shop));
        $counts = (clone $q)->groupBy('s.status')->selectRaw('s.status, COUNT(*) n, SUM(s.payable) v')->get()->keyBy('status');
        return ['rows' => $q->orderByDesc('s.statement_date')->orderByDesc('s.id')->limit(300)->get(['s.id', 'b.shop_name as shop', 's.statement_id', 's.payout_id', 's.statement_date', 's.payout_date', 's.currency', 's.total', 's.net_sales', 's.shipping', 's.fees', 's.adjustments', 's.reserve', 's.payable', 's.status', 's.rows_count', 's.f_rows_complete']),
            'counts' => $counts, 'shop' => $shop, 'balances' => Settle::shopBalances($ent), 'base' => Fx::base($ent), 'can_edit' => in_array($r->user()->role_code, self::EDIT, true), 'shops' => $this->lists($ent)['shops'],
            'periods' => DB::table('mo_settlement_periods as p')->join('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)->orderByDesc('p.period_to')->limit(50)->get(['p.id', 'b.shop_name as shop', 'p.fk_id_branch', 'p.period_from', 'p.period_to', 'p.total', 'p.net_sales', 'p.shipping', 'p.fees', 'p.adjustments', 'p.hub_check', 'p.checks_json'])
                ->map(function ($p) { $p->checks = array_values(json_decode($p->checks_json ?: '[]', true)); unset($p->checks_json); return $p; }),
            'refunds' => DB::table('t_sale_refund_proposals')->where('status', 'PROPOSED')->whereIn('fk_id_sale', DB::table('t_sales')->where('fk_id_ent', $ent)->select('id'))->count()];
    }

    public function settlementImport(Request $r)
    {
        $this->can($r, self::EDIT);
        $r->validate(['shop' => 'required|integer', 'file' => 'required|file|max:20480']);
        $f = $r->file('file');
        abort_unless(in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'xls'], true), 422, 'Choose the Excel file the marketplace gave you (.xlsx).');
        return $this->run(function () use ($r, $f) {
            $x = Settle::importTikTok((int) Context::ent(), (int) $r->input('shop'), $f->getRealPath(), $f->getClientOriginalName());
            return $x + ['message' => "{$x['statements_new']} new statement(s)" . ($x['statements_already'] ? ", {$x['statements_already']} were already loaded" : '') . ' · all checks passed to the penny'];
        });
    }

    public function settlementStep(Request $r, int $id, string $step)
    {
        $this->can($r, self::EDIT);
        return $this->run(function () use ($id, $step) { $ent = (int) Context::ent(); if ($step === 'post') { Settle::post($ent, $id); return ['message' => 'Statement booked']; } Settle::unpost($ent, $id); return ['message' => 'Statement taken back']; });
    }

    public function settlementPostAll(Request $r)
    {
        $this->can($r, self::EDIT);
        $d = $r->validate(['shop' => 'required|integer']);
        return $this->run(function () use ($d) { $n = Settle::postAll((int) Context::ent(), (int) $d['shop']); return ['booked' => $n, 'message' => "$n statement(s) booked"]; });
    }
}
