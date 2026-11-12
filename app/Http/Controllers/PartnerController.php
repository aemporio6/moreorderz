<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\Fx;
use App\Services\PartnerService as Partners;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Partners and investors: agreements, share totals, partner account, capital, withdrawals, statements. A partner login sees only himself. */
class PartnerController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'PARTNER'];
    private const EDIT = ['OWNER'];
    private const MONEY = ['OWNER', 'FINANCE'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
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
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $own = $this->own($r); $today = date('Y-m-d'); $tot = [];
        if (! $own) foreach (Partners::scopeTotals($ent, $today) as $k => $t) $tot[] = ['scope' => Partners::scopeName($ent, $k), 'pct' => $t, 'over' => $t > 100.0001];
        $out = ['partners' => Partners::partners($ent, $own), 'totals' => $tot, 'base' => Fx::base($ent), 'own' => $own, 'rights' => ['edit' => in_array($r->user()->role_code, self::EDIT, true), 'money' => in_array($r->user()->role_code, self::MONEY, true)]];
        if (! $own) $out += ['accounts' => DB::table('t_account')->where('f_active', 1)->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'sur_name', 'account_code'])->map(fn ($a) => ['id' => $a->id, 'name' => trim(($a->first_name ?? '') . ' ' . ($a->sur_name ?? '')) ?: $a->account_code]),
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']), 'pos' => DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('id')->limit(200)->get(['id', 'po_number as name']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name'])];
        return $out;
    }

    public function show(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $own = $this->own($r); abort_if($own && $own !== $id, 403, 'You can only see your own partner account.');
        $p = Partners::partners($ent, $id)[0] ?? abort(404);
        return ['partner' => $p, 'base' => Fx::base($ent), 'own' => (bool) $own, 'statements' => Partners::statements($ent, $id),
            'ledger' => DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $id)->orderByDesc('entry_date')->orderByDesc('id')->limit(300)->get(['id', 'entry_date', 'type', 'amount', 'reference_no', 'note']),
            'withdrawals' => DB::table('fin_withdrawals')->where('company_id', $ent)->where('investor_id', $id)->orderByDesc('id')->limit(50)->get(['id', 'amount', 'status', 'requested_at', 'paid_at', 'note']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name']),
            'rights' => ['edit' => in_array($r->user()->role_code, self::EDIT, true), 'money' => in_array($r->user()->role_code, self::MONEY, true), 'withdraw' => in_array($r->user()->role_code, ['OWNER', 'FINANCE', 'PARTNER'], true)]];
    }

    public function agreement(Request $r)
    {
        $this->can($r, self::EDIT);
        $d = $r->validate(['id' => 'nullable|integer', 'investor_id' => 'required|integer', 'partner_type' => 'required|in:ACTIVE,SLEEPING,WORKING', 'scope' => 'required|in:POOL,MARKETPLACE,PO', 'branch_id' => 'nullable|integer', 'po_id' => 'nullable|integer',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'profit_share_pct' => 'required|numeric', 'management_fee_pct' => 'nullable|numeric', 'capital_amount' => 'nullable|numeric|min:0']);
        return $this->run(function () use ($d) { $x = Partners::saveAgreement((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['warning'] ?: 'Agreement saved']; });
    }

    public function endAgreement(Request $r, int $id)
    {
        $this->can($r, self::EDIT);
        $d = $r->validate(['date' => 'required|date', 'reason' => 'required|string|max:190']);
        return $this->run(function () use ($id, $d) { Partners::endAgreement((int) Context::ent(), $id, $d['date'], $d['reason']); return ['message' => 'Agreement ends on ' . $d['date']]; });
    }

    public function capital(Request $r, int $id)
    {
        $this->can($r, self::MONEY);
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'bank' => 'required|integer', 'date' => 'required|date', 'note' => 'nullable|string|max:255']);
        return $this->run(function () use ($id, $d) { Partners::capitalIn((int) Context::ent(), $id, (float) $d['amount'], (int) $d['bank'], $d['date'], $d['note'] ?? null); return ['message' => 'Capital booked']; });
    }

    public function withdraw(Request $r, int $id)
    {
        $this->can($r, ['OWNER', 'FINANCE', 'PARTNER']);
        $own = $this->own($r); abort_if($own && $own !== $id, 403, 'You can only ask for your own money.');
        $d = $r->validate(['amount' => 'required|numeric|gt:0', 'bank' => 'required|integer', 'note' => 'nullable|string|max:255']);
        return $this->run(function () use ($id, $d) { $x = Partners::requestWithdrawal((int) Context::ent(), $id, (float) $d['amount'], (int) $d['bank'], $d['note'] ?? null); return $x + ['message' => $x['status'] === 'PAID' ? 'Withdrawal paid and booked' : 'Request saved – it waits for approval']; });
    }
}
