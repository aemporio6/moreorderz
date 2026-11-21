<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\Fx;
use App\Services\PartnerService as Partners;
use App\Services\WaterfallService as Waterfall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Partners and investors: agreements, share totals, partner account, capital, withdrawals, statements. A partner login sees only himself. */
class PartnerController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'PARTNER'];
    private const EDIT = ['OWNER'];
    private const MONEY = ['OWNER', 'FINANCE'];

    private function can(Request $r, string $code): void
    {
        \App\Services\Perm::need($r->user(), $code);
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    /** null = may see everyone; a number = this partner only. */
    private function own(Request $r): ?int
    {
        if ($r->user()->role_code !== 'PARTNER') return null;
        return Partners::partnerOfUser((int) $r->user()->id) ?? abort(403, 'Your login is not linked to a partner account yet. Ask the Owner.');
    }

    public function index(Request $r)
    {
        $this->can($r, 'partners.SEE');
        $ent = (int) Context::ent(); $own = $this->own($r); $today = date('Y-m-d'); $tot = [];
        if (! $own) foreach (Partners::scopeTotals($ent, $today) as $k => $t) $tot[] = ['scope' => Partners::scopeName($ent, $k), 'pct' => $t, 'over' => $t > 100.0001];
        $out = ['partners' => Partners::partners($ent, $own), 'totals' => $tot, 'base' => Fx::base($ent), 'own' => $own, 'rights' => ['edit' => \App\Services\Perm::has($r->user(), 'partners.EDIT'), 'money' => \App\Services\Perm::has($r->user(), 'partners.MONEY')]];
        if (! $own) $out += ['accounts' => DB::table('t_account')->where('f_active', 1)->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'sur_name', 'account_code'])->map(fn ($a) => ['id' => $a->id, 'name' => trim(($a->first_name ?? '') . ' ' . ($a->sur_name ?? '')) ?: $a->account_code]),
            'shops' => \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']), 'pos' => DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('id')->limit(200)->get(['id', 'po_number as name']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name'])];
        return $out;
    }

    public function show(Request $r, int $id)
    {
        $this->can($r, 'partners.SEE');
        $ent = (int) Context::ent(); $own = $this->own($r); abort_if($own && $own !== $id, 403, 'You can only see your own partner account.');
        $p = Partners::partners($ent, $id)[0] ?? abort(404);
        return ['partner' => $p, 'base' => Fx::base($ent), 'own' => (bool) $own, 'statements' => Partners::statements($ent, $id), 'files' => Waterfall::statementFiles($ent, $id),
            'ledger' => DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $id)->orderByDesc('entry_date')->orderByDesc('id')->limit(300)->get(['id', 'entry_date', 'type', 'amount', 'reference_no', 'note']),
            'withdrawals' => DB::table('fin_withdrawals')->where('company_id', $ent)->where('investor_id', $id)->orderByDesc('id')->limit(50)->get(['id', 'amount', 'status', 'requested_at', 'paid_at', 'note']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name']),
            'rights' => ['edit' => \App\Services\Perm::has($r->user(), 'partners.EDIT'), 'money' => \App\Services\Perm::has($r->user(), 'partners.MONEY'), 'withdraw' => \App\Services\Perm::has($r->user(), 'partners.show_may')]];
    }

    public function agreement(Request $r)
    {
        $this->can($r, 'partners.EDIT');
        $d = $r->validate(['id' => 'nullable|integer', 'investor_id' => 'required|integer', 'partner_type' => 'required|in:ACTIVE,SLEEPING,WORKING', 'scope' => 'required|in:POOL,MARKETPLACE,PO', 'branch_id' => 'nullable|integer', 'po_id' => 'nullable|integer',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'profit_share_pct' => 'required|numeric', 'management_fee_pct' => 'nullable|numeric', 'capital_amount' => 'nullable|numeric|min:0', 'stacking_rule' => 'nullable|string|max:12', 'stack_cap_pct' => 'nullable|numeric']);
        return $this->run(function () use ($d) { $x = Partners::saveAgreement((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['warning'] ?: 'Agreement saved']; });
    }

    public function endAgreement(Request $r, int $id)
    {
        $this->can($r, 'partners.EDIT');
        $d = $r->validate(['date' => 'required|date', 'reason' => 'required|string|max:190']);
        return $this->run(function () use ($id, $d) { Partners::endAgreement((int) Context::ent(), $id, $d['date'], $d['reason']); return ['message' => 'Agreement ends on ' . $d['date']]; });
    }

    public function capital(Request $r, int $id)
    {
        $this->can($r, 'partners.MONEY');
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'bank' => 'required|integer', 'date' => 'required|date', 'note' => 'nullable|string|max:255']);
        return $this->run(function () use ($id, $d) { Partners::capitalIn((int) Context::ent(), $id, (float) $d['amount'], (int) $d['bank'], $d['date'], $d['note'] ?? null); return ['message' => 'Capital booked']; });
    }

    // ───────────── waterfall: split rules, routes, statements, adjustments ─────────────

    public function waterfall(Request $r)
    {
        $this->can($r, 'partners.waterfall');
        $ent = (int) Context::ent(); $month = date('Y-m');
        $shops = \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']);
        return ['splits' => Waterfall::splits($ent), 'routes' => $shops->map(fn ($s) => ['shop' => $s->id, 'name' => $s->name, 'route' => Waterfall::route($ent, (int) $s->id, $month), 'own' => DB::table('t_branch_profit_routes')->where('fk_id_company', $ent)->where('fk_id_branch', $s->id)->orderByDesc('d_start')->limit(5)->get(['profit_route', 'd_start', 'd_end', 'reason'])]),
            'route_names' => Waterfall::ROUTES, 'stacking' => Waterfall::STACKING, 'default_route' => \App\Services\Choices::get('DEFAULT_PROFIT_ROUTE', $ent), 'shops' => $shops, 'month' => $month, 'base' => Fx::base($ent), 'can_edit' => \App\Services\Perm::has($r->user(), 'partners.waterfall_owner'),
            'accounts' => DB::table('t_account')->where('f_active', 1)->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'sur_name', 'account_code'])->map(fn ($a) => ['id' => $a->id, 'name' => trim(($a->first_name ?? '') . ' ' . ($a->sur_name ?? '')) ?: $a->account_code]),
            'locked' => DB::table('aud_runs as r')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->where('r.fk_id_ent', $ent)->where('r.status', 'LOCKED')->orderByDesc('r.period_month')->limit(60)->get(['r.id', 'r.period_month', 'r.run_code', 'b.shop_name as shop']),
            'partners' => collect(Partners::partners($ent))->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->values(), 'statements' => Waterfall::statementFiles($ent)];
    }

    public function split(Request $r)
    {
        $this->can($r, 'partners.EDIT');
        $d = $r->validate(['fk_id_branch' => 'nullable|integer', 'd_effective_from' => 'required|date', 'investor_pct' => 'required|numeric', 'owner_pct' => 'nullable|numeric', 'va_pct' => 'nullable|numeric', 'handler_pct' => 'nullable|numeric', 'owner_mode' => 'required|string', 'va_mode' => 'required|string',
            'handler_mode' => 'required|string', 'owner_fixed' => 'nullable|numeric', 'handler_fixed' => 'nullable|numeric', 'va_salary_override' => 'nullable|numeric', 'fk_id_handler_party' => 'nullable|integer', 'notes' => 'nullable|string|max:500']);
        return $this->run(function () use ($d) { $x = Waterfall::saveSplit((int) Context::ent(), $d); return $x + ['message' => $x['warning'] ?: 'Profit split saved']; });
    }

    public function retireSplit(Request $r, int $id)
    {
        $this->can($r, 'partners.EDIT');
        return $this->run(function () use ($id) { Waterfall::retireSplit((int) Context::ent(), $id); return ['message' => 'Profit split removed. Locked months keep the split they were calculated with.']; });
    }

    public function route(Request $r)
    {
        $this->can($r, 'partners.EDIT');
        $d = $r->validate(['shop' => 'required|integer', 'route' => 'required|string', 'from' => 'required|string|size:7', 'reason' => 'required|string|max:500']);
        return $this->run(function () use ($d) { Waterfall::setRoute((int) Context::ent(), (int) $d['shop'], $d['route'], $d['from'], $d['reason']); return ['message' => 'Route set from ' . $d['from']]; });
    }

    public function adjust(Request $r)
    {
        $this->can($r, 'partners.EDIT');
        $d = $r->validate(['run' => 'required|integer', 'partner' => 'required|integer', 'amount' => 'required|numeric', 'note' => 'required|string|max:200']);
        return $this->run(function () use ($d) { $x = Waterfall::adjust((int) Context::ent(), (int) $d['run'], (int) $d['partner'], (float) $d['amount'], $d['note']); return $x + ['message' => "Adjustment booked on {$x['booked_on']} · statement version {$x['version']} made"]; });
    }

    public function statementPdf(Request $r, int $id)
    {
        $this->can($r, 'partners.SEE');
        try { [$bytes, $name] = Waterfall::statementFile((int) Context::ent(), $id, $this->own($r)); }
        catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"']);
    }

    public function withdraw(Request $r, int $id)
    {
        $this->can($r, 'partners.withdraw');
        $own = $this->own($r); abort_if($own && $own !== $id, 403, 'You can only ask for your own money.');
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'bank' => 'required|integer', 'note' => 'nullable|string|max:255']);
        return $this->run(function () use ($id, $d) { $x = Partners::requestWithdrawal((int) Context::ent(), $id, (float) $d['amount'], (int) $d['bank'], $d['note'] ?? null); return $x + ['message' => $x['status'] === 'PAID' ? 'Withdrawal paid and booked' : 'Request saved – it waits for approval']; });
    }

    /**
     * My Partner Account (R-FIN-19). A partner login sees his own account; the Owner may "view as partner" (?partner=ID, read-only, audited as REVEAL);
     * any other login gets the explanation page (no data).
     */
    private function accountOf(Request $r, bool $write = false): array
    {
        if ($r->user()->role_code === 'PARTNER') return [$this->own($r), false];
        if ($r->filled('partner') && \App\Services\Perm::has($r->user(), 'partners.viewAs')) {
            abort_if($write, 403, 'View as partner is read-only.');
            return [(int) $r->input('partner'), true];
        }
        return [null, false];
    }

    public function myAccount(Request $r)
    {
        [$id, $viewAs] = $this->accountOf($r); $ent = (int) Context::ent();
        if (! $id) return ['staff' => true, 'message' => 'My Partner Account is the page a partner sees about his own money: capital, profit, withdrawals, balance, stock share, profit by product and statements to accept. Your login is not a partner login, so there is nothing to show here.'
            . (\App\Services\Perm::has($r->user(), 'partners.viewAs') ? ' As Owner you can choose a partner below and view the page as he sees it (read-only).' : ''),
            'partners' => \App\Services\Perm::has($r->user(), 'partners.viewAs') ? collect(Partners::partners($ent))->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])->values()->all() : []];
        abort_unless(DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $id)->exists() || DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $id)->exists(), 404, 'No partner account.');
        $d = $r->validate(['as_of' => 'nullable|date', 'compare' => 'nullable|date']);
        try { $page = \App\Services\PartnerAccount::page($ent, $id, $d['as_of'] ?? null, $d['compare'] ?? null); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['compare' => [$e->getMessage()]]], 422); }
        if ($viewAs) \App\Services\Audit::log('t_account', $id, 'REVEAL', ['view_as_partner' => $id], 'Owner viewed My Partner Account as the partner', $ent);
        return $page + ['partner' => $id, 'view_as' => $viewAs, 'read_only' => $viewAs, 'banks' => $viewAs ? [] : DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name'])];
    }

    public function myNotify(Request $r)
    {
        [$id] = $this->accountOf($r, true); abort_unless($id, 403, 'Only a partner login has these switches.');
        $d = $r->validate(['whatsapp' => 'nullable|boolean', 'email' => 'nullable|boolean']);
        return \App\Services\PartnerAccount::setNotify($id, $d);
    }

    /** R-FIN-18: the Owner reinvests for any partner. */
    public function reinvest(Request $r, int $id)
    {
        abort_unless($r->user()->role_code === 'OWNER', 403, 'Refused: a partner (or the Owner) reinvests.');
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:200']);
        return $this->run(fn () => \App\Services\PartnerAccount::reinvest((int) Context::ent(), $id, (float) $d['amount'], $d['note'] ?? null));
    }

    public function myReinvest(Request $r)
    {
        [$id] = $this->accountOf($r, true); abort_unless($id, 403, 'Only a partner login can reinvest.');
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:200']);
        return $this->run(fn () => \App\Services\PartnerAccount::reinvest((int) Context::ent(), $id, (float) $d['amount'], $d['note'] ?? null));
    }
}
