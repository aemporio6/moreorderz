<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\FinanceExtras as F;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** VAT return, loans, budgets, capital calls, partner answers on statements. */
class FinanceExtrasController extends Controller
{
    private function can(Request $r, array $roles): void { abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here.'); }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private const MONEY = ['OWNER', 'FINANCE', 'ACCOUNTANT', 'MANAGER'];

    public function vat(Request $r)
    {
        $this->can($r, self::MONEY); $ent = (int) Context::ent();
        return $this->run(function () use ($r, $ent) {
            $periods = F::vatPeriods($ent); $from = (string) ($r->query('from') ?: $periods[1]['from']); $to = (string) ($r->query('to') ?: $periods[1]['to']);
            $saved = DB::table('mo_vat_returns')->where(['fk_id_ent' => $ent, 'period_from' => $from, 'period_to' => $to])->first();
            return ['periods' => $periods, 'from' => $from, 'to' => $to, 'calc' => F::vatCalc($ent, $from, $to), 'saved' => $saved,
                'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', COALESCE(account_label,'')) name"), 'currency'])];
        });
    }
    public function vatBook(Request $r) { return $this->run(fn () => F::vatBook((int) Context::ent(), (string) $r->input('from'), (string) $r->input('to'), $r->input('reference'))); }
    public function vatPay(Request $r, int $id) { return $this->run(fn () => F::vatPay((int) Context::ent(), $id, (int) $r->input('bank'), (string) ($r->input('date') ?: date('Y-m-d')))); }

    public function loans(Request $r) { $this->can($r, self::MONEY); return F::loansPage((int) Context::ent()); }
    public function loanNew(Request $r) { return $this->run(fn () => ['id' => F::loanNew((int) Context::ent(), (array) $r->input()), 'message' => 'Loan recorded and booked']); }
    public function loanPay(Request $r, int $id) { return $this->run(fn () => F::loanPay((int) Context::ent(), $id, (array) $r->input())); }

    public function budgets(Request $r) { $this->can($r, self::MONEY); return F::budgets((int) Context::ent(), (int) ($r->query('year') ?: date('Y'))); }
    public function budgetSet(Request $r)
    {
        return $this->run(function () use ($r) { $a = $r->input('amounts'); F::budgetSet((int) Context::ent(), (int) $r->input('year'), (string) $r->input('category'), is_array($a) ? $a : [(float) $r->input('amount')]); return ['message' => 'Budget saved']; });
    }

    public function calls(Request $r)
    {
        $this->can($r, ['OWNER', 'FINANCE', 'MANAGER', 'PARTNER']); $d = F::callsPage((int) Context::ent());
        if ($r->user()->role_code === 'PARTNER') {   // a partner sees only his own part of each call
            $mine = (int) DB::table('fin_partner_users')->where('user_id', $r->user()->id)->value('investor_id');
            $d['calls'] = $d['calls']->map(function ($c) use ($mine) { $c->answers = $c->answers->where('investor_id', $mine)->values(); return $c; })->filter(fn ($c) => $c->answers->isNotEmpty())->values(); $d['banks'] = [];
        }
        return $d + ['partner' => $r->user()->role_code === 'PARTNER'];
    }
    public function callNew(Request $r) { return $this->run(fn () => ['id' => F::callNew((int) Context::ent(), (array) $r->input()), 'message' => 'Capital call made; the partners get it by WhatsApp / e-mail']); }
    public function callAnswer(Request $r, int $id) { return $this->run(function () use ($r, $id) { F::callAnswer((int) Context::ent(), $id, (string) $r->input('status'), $r->input('amount') !== null ? (float) $r->input('amount') : null, $r->input('bank') ? (int) $r->input('bank') : null, $r->input('date')); return ['ok' => true]; }); }

    public function statementAnswer(Request $r, int $id) { return $this->run(fn () => F::statementAnswer((int) Context::ent(), $id, (string) $r->input('what'), $r->input('question'))); }
    public function statementReply(Request $r, int $id) { return $this->run(function () use ($r, $id) { F::statementReply((int) Context::ent(), $id, (string) $r->input('answer')); return ['message' => 'Answer saved and sent to the partner']; }); }
    public function questions(Request $r)
    {
        $this->can($r, ['OWNER', 'FINANCE', 'MANAGER', 'PARTNER']);
        $mine = $r->user()->role_code === 'PARTNER' ? (int) DB::table('fin_partner_users')->where('user_id', $r->user()->id)->value('investor_id') : null;
        return ['can_reply' => $mine === null, 'rows' => DB::table('fin_statement_queries as q')->when($mine !== null, fn ($x) => $x->where('q.investor_id', $mine))->join('fin_statements as s', 's.id', '=', 'q.statement_id')->join('aud_runs as r', 'r.id', '=', 's.period_id')->leftJoin('t_account as a', 'a.id', '=', 'q.investor_id')
            ->where('r.fk_id_ent', Context::ent())->orderByRaw('q.answered_at IS NOT NULL')->orderByDesc('q.id')->limit(200)
            ->get(['q.id', 'q.question', 'q.answer', 'q.asked_at', 'q.answered_at', 's.version', 's.id as statement_id', 'r.run_code', 'r.period_month', 'q.investor_id', DB::raw("TRIM(CONCAT(a.first_name, ' ', a.sur_name)) who")])];
    }
}
