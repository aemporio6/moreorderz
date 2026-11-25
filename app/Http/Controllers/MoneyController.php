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

    private function can(Request $r, string $code): void
    {
        \App\Services\Perm::need($r->user(), $code);
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function lists(int $ent): array
    {
        return ['categories' => DB::table('fin_categories')->where('f_active', 1)->whereNotIn('code', ['SUPPLIER_PAYMENT', 'SPLIT', 'OWN_TRANSFER_IN', 'OWN_TRANSFER_OUT'])->orderBy('sort_no')->orderBy('name')->get(['code', 'name', 'direction'])
                ->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'direction' => $c->direction, 'needs_shop' => in_array(Money::ACCOUNTS[$c->code] ?? '', ['1100', '5100', '5300', '5400'], true)]),
            'shops' => \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
            'cycles' => DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CANCELLED', 'Cancelled', 'CLOSED', 'Closed'])->orderByDesc('id')->limit(200)->get(['id', DB::raw("CONCAT(cycle_code, ' · ', COALESCE(product_name, '')) as name")])];      /* R-B_SOURCING-63 import costs name their cycle */
    }

    // ---- dashboard and profit ----
    public function dashboard(Request $r)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent(); $banks = Money::banks($ent); $shops = Settle::shopBalances($ent); $m = date('Y-m');
        $due = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')
            ->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.status', '<>', 'PAID')->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])->where('t.remaining_amount', '>', 0)->orderByRaw('t.due_date IS NULL, t.due_date')->limit(15)
            ->get(['t.id', 'p.id as po_id', 'p.po_number', 's.supplier_name as supplier', 't.milestone', 't.remaining_amount', 'c.currency_code as currency', 't.due_date']);
        $today = date('Y-m-d');
        return ['base' => Fx::base($ent), 'banks' => $banks, 'cash' => round(array_sum(array_column($banks, 'books_base')), 2), 'shops' => $shops,
            'unsettled' => round(array_sum(array_column($shops, 'unsettled')), 2), 'on_the_way' => round(array_sum(array_column($shops, 'on_the_way')), 2), 'held_back' => round(array_sum(array_column($shops, 'held_back')), 2),
            'due' => $due, 'overdue' => $due->filter(fn ($d) => $d->due_date && $d->due_date < $today)->count(),
            'expenses_month' => round((float) DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereRaw("DATE_FORMAT(d_expense, '%Y-%m') = ?", [$m])->sum('amount_gbp'), 2), 'month' => $m,
            'approvals' => DB::table('sys_approvals')->where('fk_id_ent', $ent)->where('status', 'PENDING')->count(), 'unmatched' => array_sum(array_column($banks, 'unmatched')),
            'to_book' => \App\Services\Perm::mine(DB::table('mo_settlements'), 'fk_id_branch')->where('fk_id_ent', $ent)->where('status', 'LOADED')->count(), 'refunds' => $this->refundRows($ent)->count(),
            'stock' => \App\Services\Ledger::balance($ent, '1200'), 'supplier_advances' => \App\Services\Ledger::balance($ent, '1300'), 'supplier_payables' => -1 * \App\Services\Ledger::balance($ent, '2000')];
    }

    public function profit(Request $r)
    {
        $this->can($r, 'money.SEE');
        $d = $r->validate(['by' => 'nullable|in:' . implode(',', array_keys(\App\Services\ProfitService::VIEWS)), 'from' => 'nullable|date', 'to' => 'nullable|date', 'shop' => 'nullable|integer']);
        return \App\Services\ProfitService::view((int) Context::ent(), $d['by'] ?? 'shop', $d['from'] ?? date('Y-m-01', strtotime('-2 month')), $d['to'] ?? date('Y-m-d'), ! empty($d['shop']) ? (int) $d['shop'] : null);
    }

    // ---- refund proposals ----
    private function refundRows(int $ent)
    {
        return \App\Services\Perm::mine(DB::table('t_sale_refund_proposals as p'), 's.fk_id_branch')->join('t_sales as s', 's.id', '=', 'p.fk_id_sale')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('p.status', 'PROPOSED')->orderBy('p.id')
            ->get(['p.id', 'p.refund_kind', 'p.amount', 'p.created_at', 's.order_no', 's.sku', 's.qty', 's.total_amount_gbp', 's.status as sale_status', 's.d_order', 'b.shop_name as shop']);
    }

    public function refunds(Request $r)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent();
        $x = \App\Services\RefundProposals::page($r->user());
        return $x + ['base' => Fx::base($ent), 'shops' => $this->lists($ent)['shops'], 'can_edit' => \App\Services\Perm::has($r->user(), 'money.refunds_may')];
    }
    public function refundCandidates(Request $r, int $id) { try { return \App\Services\RefundProposals::candidates($id, (string) $r->query('q'), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function refundExtra(Request $r, int $id, string $what)
    {
        try { return match ($what) { 'link' => \App\Services\RefundProposals::link($id, $r->input('sale') ? (int) $r->input('sale') : null, $r->input('sku'), $r->user()), 'reject-unknown' => \App\Services\RefundProposals::rejectUnknown($id, $r->input('reason'), $r->user()), 'outside' => \App\Services\RefundProposals::markOutside($id, $r->boolean('outside', true), $r->user()), default => abort(404) }; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** A refund entered by hand: the whole order, or an amount of it. */
    public function refundManual(Request $r)
    {
        $this->can($r, 'money.refundManual');
        $d = $r->validate(['shop' => 'required|integer', 'order_no' => 'required|string|max:100', 'amount' => 'nullable|numeric|gt:0', 'reason' => 'required|string|min:3|max:250']);
        return $this->run(function () use ($d) { $x = Settle::refundOrder((int) Context::ent(), (int) $d['shop'], trim($d['order_no']), isset($d['amount']) ? (float) $d['amount'] : null, $d['reason']);
            return $x + ['message' => $x['cancelled'] ? "{$x['cancelled']} line(s) not confirmed yet were cancelled and their stock is free again" . ($x['amount'] ? '; ' . number_format($x['amount'], 2) . ' refunded' : '') : number_format($x['amount'], 2) . " refunded on {$x['lines']} line(s)"]; });
    }

    public function refundDecide(Request $r, int $id)
    {
        $this->can($r, 'money.refundDecide');
        $d = $r->validate(['accept' => 'required|boolean', 'reason' => 'nullable|string|max:300']);
        return $this->run(function () use ($id, $d) { Settle::decideRefund((int) Context::ent(), $id, (bool) $d['accept'], $d['reason'] ?? null); return ['message' => $d['accept'] ? 'Refund booked' : 'Proposal rejected']; });
    }

    // ---- bank ----
    public function bank(Request $r)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent(); $show = $r->query('show', 'UNMATCHED'); $bank = (int) $r->query('bank', 0);
        $led = $show === 'LEDGER' ? \App\Services\BankLedger::filters($r->query(), $r->user()) : null;      // R-FIN-31 Ledger / Accountant view
        $base = DB::table('fin_bank_transactions as t')->join('fin_bank_accounts as b', 'b.id', '=', 't.bank_account_id')
            ->when($led, fn ($q) => \App\Services\BankLedger::apply($q, $led), fn ($q) => $q->where('t.company_id', $ent)->when($show !== 'ALL', fn ($w) => $w->where('t.match_status', 'UNMATCHED')))
            ->when($bank, fn ($q) => $q->where('t.bank_account_id', $bank));
        $totals = $led ? \App\Services\BankLedger::totals(clone $base) : null;
        $lines = (clone $base)->orderByDesc('t.txn_date')->orderByDesc('t.id')->limit(300)
            ->get(['t.id', 't.bank_account_id', 'b.account_label as bank', 't.txn_date', 't.amount', 't.currency', 't.description', 't.counterparty', 't.reference_no', 't.match_status', 't.matched_to_type', 't.match_rule', 't.category_code']);
        $names = DB::table('fin_categories')->pluck('name', 'code');
        foreach ($lines as $l) { $l->category = $names[$l->category_code] ?? null; $l->suggest = $l->match_status === 'UNMATCHED' ? \App\Services\BankCategoryHint::suggest($ent, $l) : null; }      // R-FIN-30: a chip, never applied by itself
        return ['banks' => Money::banks($ent), 'lines' => $lines, 'show' => $show, 'bank' => $bank, 'base' => Fx::base($ent), 'ledger' => $led ? ['filters' => $led, 'totals' => $totals, 'companies' => \App\Services\BankLedger::companies($r->user()), 'statuses' => \App\Services\BankLedger::STATUSES] : null, 'can_edit' => \App\Services\Perm::has($r->user(), 'money.EDIT'),
            'vat' => \App\Services\Choices::get('VAT_REGISTERED', $ent) === '1',
            'profiles' => DB::table('bank_statement_profiles')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('id')->get(['id', 'name', 'date_format'])] + $this->lists($ent);
    }

    /** R-FIN-33 accountant export and R-FIN-36 stock valuation at a date (CSV / XLSX). */
    public function exportLedger(Request $r)
    {
        $this->can($r, 'money.SEE'); $y = (int) ($r->query('year') ?: date('Y'));
        $d = fn ($v, $def) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $def;
        return $this->run(function () use ($r, $y, $d) { $x = \App\Services\FinanceExports::ledger((int) ($r->query('company') === 'all' ? 0 : ($r->query('company') ?? Context::ent())), $d($r->query('from'), "$y-01-01"), $d($r->query('to'), "$y-12-31"), (string) $r->query('format', 'csv'), $r->user());
            return response($x['body'], 200, ['Content-Type' => $x['type'], 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']); });
    }
    public function exportValuation(Request $r)
    {
        $this->can($r, 'money.SEE'); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('date')) ? (string) $r->query('date') : date('Y-m-d');
        return $this->run(function () use ($r, $date) { $x = \App\Services\FinanceExports::valuation((int) ($r->query('company') === 'all' ? 0 : ($r->query('company') ?? Context::ent())), $date, (string) $r->query('format', 'csv'), $r->user());
            return response($x['body'], 200, ['Content-Type' => $x['type'], 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']); });
    }
    /** R-FIN-25 report status board per shop × report type. */
    public function reportBoard(Request $r) { $this->can($r, 'money.SEE'); $b = \App\Services\FinanceExports::board((int) Context::ent()); foreach (\App\Services\FinanceReports::boardRows((int) Context::ent()) as $x) $b['rows'][] = $x + ['days' => $x['data_until'] ? (int) floor((time() - strtotime($x['data_until'])) / 86400) : null, 'state' => $x['data_until'] ? 'loaded' : 'nothing yet'];
        return $b + ['types' => array_map(fn ($t) => $t[1], \App\Services\FinanceReports::TYPES), 'shops' => \Illuminate\Support\Facades\DB::table('t_branches')->where('fk_id_ent', (int) Context::ent())->where('f_active', 1)->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name'])]; }

    /** R-FIN-54 Products & stock profit list. */
    public function productProfit(Request $r)
    {
        $this->can($r, 'money.SEE');
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'q' => 'nullable|string|max:80', 'losing' => 'nullable|boolean', 'cost_missing' => 'nullable|boolean', 'sort' => 'nullable|string|max:20']);
        return \App\Services\ProductProfitList::list((int) Context::ent(), array_filter($d, fn ($v) => $v !== null && $v !== ''));
    }

    /** R-FIN-51 stock snapshot of a date (built when that day has none yet); POST rebuilds it (Owner / Finance). */
    public function stockSnapshot(Request $r)
    {
        $this->can($r, 'money.SEE');
        $d = $r->validate(['date' => 'nullable|date|before_or_equal:today']); $date = $d['date'] ?? date('Y-m-d');
        if ($r->isMethod('post')) {
            abort_unless(in_array($r->user()->role_code, ['OWNER', 'FINANCE'], true), 403, 'Only the Owner or Finance rebuilds a snapshot.');
            \App\Services\StockSnapshot::build((int) Context::ent(), $date);
        }
        return \App\Services\StockSnapshot::view((int) Context::ent(), $date);
    }

    /** R-FIN-67 alerts screen: rules (Owner switches), open alerts, messages sent. */
    public function alerts(Request $r)
    {
        $this->can($r, 'money.SEE');
        return \App\Services\FinanceAlerts::screen((int) Context::ent(), max(1, (int) $r->query('page', 1)), $r->query('q') ?: null, max(1, (int) $r->query('mpage', 1)))
            + ['may_edit' => $r->user()->role_code === 'OWNER', 'may_run' => in_array($r->user()->role_code, ['OWNER', 'FINANCE'], true)];
    }

    public function alertRule(Request $r, string $code)
    {
        abort_unless($r->user()->role_code === 'OWNER', 403, 'Only the Owner switches finance alerts.');
        $d = $r->validate(['on' => 'nullable|boolean', 'to_roles' => 'nullable|array', 'channels' => 'nullable|array']);
        return $this->run(fn () => \App\Services\FinanceAlerts::saveRule(strtoupper($code), array_filter($d, fn ($v) => $v !== null)));
    }

    public function alertsRun(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'FINANCE'], true), 403, 'Only the Owner or Finance runs the checks.');
        $n = \App\Services\FinanceAlerts::run((int) Context::ent());
        return $n + ['message' => "Checked: {$n['raised']} new, {$n['repeated']} sent again, {$n['resolved']} resolved."];
    }

    public function bankCreate(Request $r)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['bank_name' => 'required|string|max:100', 'account_label' => 'required|string|max:100', 'currency' => 'required|string|size:3', 'opening_balance' => 'nullable|numeric', 'opening_date' => 'nullable|date', 'account_kind' => 'nullable|in:BANK,PROVIDER']);
        return $this->run(fn () => ['id' => Money::createBank((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)), 'message' => 'Bank account added']);
    }

    public function split(Request $r, int $id)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['parts' => 'required|array|min:2', 'parts.*.kind' => 'required|in:CATEGORY,CUSTOMS,SUPPLIER', 'parts.*.amount' => 'required|numeric', 'parts.*.category' => 'nullable|string|max:40', 'parts.*.branch' => 'nullable|integer',
            'parts.*.vat_amount' => 'nullable|numeric|min:0', 'parts.*.customs' => 'nullable|integer', 'parts.*.import_vat' => 'nullable|numeric|min:0', 'parts.*.term' => 'nullable|integer', 'parts.*.note' => 'nullable|string|max:255']);
        return $this->run(function () use ($id, $d) { $x = Money::split((int) Context::ent(), $id, $d['parts']); return $x + ['message' => "Bank line split into {$x['parts']} parts"]; });
    }

    public function splitTargets(Request $r)
    {
        $this->can($r, 'money.EDIT'); $ent = (int) Context::ent();
        return ['customs' => DB::table('t_src_customs')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($w) => $w->whereNull('pay_status')->orWhere('pay_status', '<>', 'PAID'))->orderByDesc('id')->limit(100)->get(['id', 'customs_code', 'base_amount', 'import_vat', 'declaration_number']),
            'terms' => DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.remaining_amount', '>', 0)->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])->orderByDesc('t.id')->limit(100)
                ->get(['t.id', 'p.po_number', 't.milestone', 't.remaining_amount', 'c.currency_code as currency'])];
    }

    /** R-FIN-61 supplier payment schedule (view over the PO terms) + R-FIN-64 what we owe suppliers. */
    public function supplierSchedule(Request $r)
    {
        $this->can($r, 'money.forecast');
        return \App\Services\SupplierSchedule::view((int) Context::ent(), $r->only(['status', 'supplier']));
    }

    public function forecast(Request $r)
    {
        $this->can($r, 'money.forecast');
        return \App\Services\CashForecast::build((int) Context::ent(), (int) $r->query('weeks', 8));
    }

    public function bankImport(Request $r, int $id)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['profile' => 'required|integer', 'csv' => 'required_without:file|nullable|string|max:5000000', 'file' => 'nullable|file|max:20480', 'file_name' => 'nullable|string|max:200']);
        if ($r->hasFile('file')) { $f = $r->file('file'); abort_unless(in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'xls', 'csv', 'txt'], true), 422, 'Choose the bank file (.csv or .xlsx).');
            $d['csv'] = in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'xls'], true) ? Money::excelToCsv($f->getRealPath()) : (string) file_get_contents($f->getRealPath()); $d['file_name'] = $f->getClientOriginalName(); }
        return $this->run(function () use ($id, $d) {
            $x = Money::importStatement((int) Context::ent(), $id, (int) $d['profile'], $d['csv'], $d['file_name'] ?? 'statement.csv');
            return $x + ['message' => "{$x['new']} new line(s), {$x['matched']} matched by the tool" . ($x['duplicates'] ? ", {$x['duplicates']} were already loaded" : '') . ($x['errors'] ? ', ' . count($x['errors']) . ' row(s) could not be read' : '')];
        });
    }

    /** R-FIN-24 step "check": the dry run (nothing written), the file waits under a token for this login. */
    public function bankImportCheck(Request $r, int $id)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['profile' => 'required|integer', 'csv' => 'required_without:file|nullable|string|max:26214400', 'file' => 'nullable|file|max:25600', 'file_name' => 'nullable|string|max:200']);
        return $this->run(function () use ($r, $id, $d) {
            if ($r->hasFile('file')) { $f = $r->file('file'); \App\Services\BankImportCheck::checkFile($f->getClientOriginalName(), (int) $f->getSize());
                $d['csv'] = in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'xls'], true) ? Money::excelToCsv($f->getRealPath()) : (string) file_get_contents($f->getRealPath()); $d['file_name'] = $f->getClientOriginalName(); }
            return \App\Services\BankImportCheck::dryRun((int) Context::ent(), $id, (int) $d['profile'], $d['csv'], $d['file_name'] ?? 'statement.csv', (int) $r->user()->id);
        });
    }

    /** R-FIN-24 step "apply": load the checked file by its token. */
    public function bankImportApply(Request $r)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['token' => 'required|string|max:64', 'again' => 'nullable|boolean']);
        return $this->run(function () use ($r, $d) {
            $x = \App\Services\BankImportCheck::apply((int) Context::ent(), $d['token'], (int) $r->user()->id, (bool) ($d['again'] ?? false));
            return $x + ['message' => "{$x['new']} new line(s), {$x['matched']} matched by the tool" . ($x['duplicates'] ? ", {$x['duplicates']} were already loaded" : '') . ($x['errors'] ? ', ' . count($x['errors']) . ' row(s) could not be read' : '')];
        });
    }

    /** R-FIN-4 / 6 finance settings page and saves (Owner only for saves). */
    public function financeSettings(Request $r) { $this->can($r, 'money.SEE'); return \App\Services\FinanceSettings::page((int) Context::ent()) + ['may_edit' => $r->user()->role_code === 'OWNER']; }
    public function financeSettingSave(Request $r)
    {
        $this->can($r, 'money.SEE');
        try { return \App\Services\FinanceSettings::saveSystem((string) $r->input('key', ''), $r->input('value')); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], str_starts_with($e->getMessage(), 'Refused') ? 403 : 422); }
    }
    public function financeCompanySave(Request $r)
    {
        $this->can($r, 'money.SEE');
        try { return \App\Services\FinanceSettings::saveCompany((int) Context::ent(), (array) $r->input('values', [])); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => implode(' ', $e->errors), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 403); }
    }

    public function lineAction(Request $r, int $id, string $what)
    {
        $this->can($r, 'money.EDIT');
        $ent = (int) Context::ent();
        return $this->run(function () use ($r, $id, $what, $ent) {
            if ($what === 'categorise') { $d = $r->validate(['category' => 'required|string|max:40', 'branch' => 'nullable|integer', 'note' => 'nullable|string|max:200', 'vat_amount' => 'nullable|numeric|min:0', 'cycle' => 'nullable|integer', 'shipment' => 'nullable|integer']); Money::categorise($ent, $id, array_filter($d, fn ($v) => $v !== null)); return ['message' => 'Booked']; }
            if ($what === 'transfer') { $d = $r->validate(['other' => 'required|integer']); $x = Money::transfer($ent, $id, (int) $d['other']); return ['message' => 'Transfer booked' . ($x['mirror'] ? ' – the line in the other account is linked too' : '')]; }
            if ($what === 'payout') { $d = $r->validate(['branch' => 'required|integer', 'pay_key' => 'required|string|max:40']); Money::matchPayout($ent, $id, (int) $d['branch'], $d['pay_key']); return ['message' => 'Payout matched']; }
            if ($what === 'ignore') { $d = $r->validate(['reason' => 'required|string|max:200']); Money::ignore($ent, $id, $d['reason']); return ['message' => 'Line set aside']; }
            if ($what === 'match') { $d = $r->validate(['kind' => 'required|in:INVOICE,LOAN,CLAIM', 'target' => 'required|integer', 'interest' => 'nullable|numeric|min:0']); return \App\Services\BankMatch::matchTo($ent, $id, $d['kind'], (int) $d['target'], $d); }      // R-FIN-28 by hand to an invoice / loan
            if ($what === 'many') { $d = $r->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']); return \App\Services\BankMatch::matchMany($ent, $id, 'EXPENSE', $d['ids']); }      // R-FIN-70 several payables by hand
            Money::unmatch($ent, $id); return ['message' => 'Match undone'];
        });
    }

    /** R-FIN-28 the open invoices and loans a line can be matched to (direction-aware). */
    public function matchTargets(Request $r, int $id)
    {
        $this->can($r, 'money.SEE'); $ent = (int) Context::ent();
        $l = DB::table('fin_bank_transactions')->where('id', $id)->where('company_id', $ent)->first() ?? abort(404); $in = (float) $l->amount > 0;
        $ours = DB::table('t_inv_parties')->where('fk_id_ent', $ent)->where('f_is_sbs', 1)->pluck('id')->all();
        return ['invoices' => DB::table('t_invoices')->where('fk_id_ent', $ent)->whereIn('status', ['UNPAID', 'PART_PAID'])->where('invoice_type', '<>', 'CREDIT_NOTE')->whereIn($in ? 'fk_issuer_party' : 'fk_recipient_party', $ours ?: [0])
                ->orderBy('d_due')->limit(200)->get(['id', 'invoice_no', 'total', 'amount_paid', 'd_due'])->map(fn ($i) => ['id' => (int) $i->id, 'name' => $i->invoice_no . ' · balance ' . number_format((float) $i->total - (float) $i->amount_paid, 2)])->all(),
            'claims' => $in ? DB::table('t_src_grn')->where('fk_id_ent', $ent)->where('claim_status', 'SETTLED')->where('claim_settled_as', 'REFUND')->where('claim_base_amount', '>', 0)->orderByDesc('id')->limit(100)->get(['id', 'grn_number', 'claim_base_amount'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => ($g->grn_number ?: 'GRN ' . $g->id) . ' · refund ' . number_format((float) $g->claim_base_amount, 2)])->all() : [],
            'loans' => DB::table('fin_loans')->where('company_id', $ent)->where('is_active', 1)->where('direction', $in ? 'GIVEN' : 'TAKEN')->get(['id', 'counterparty', 'balance'])->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->counterparty . ' · balance ' . number_format((float) $x->balance, 2)])->all()];
    }

    /** Booked payouts of exactly this line's amount, for a person to pick from. */
    public function payouts(Request $r, int $id)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent(); $l = DB::table('fin_bank_transactions')->where('id', $id)->where('company_id', $ent)->first() ?? abort(404);
        $names = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id');
        return ['rows' => array_map(fn ($c) => ['branch' => (int) $c->fk_id_branch, 'shop' => $names[$c->fk_id_branch] ?? '', 'pay_key' => (string) $c->pay_key, 'date' => $c->pay_date, 'statements' => (int) $c->n], \App\Services\SettlementService::payoutCandidates($ent, (float) $l->amount, (string) $l->currency, $l->txn_date, 3650))];
    }

    public function rematch(Request $r, int $id)
    {
        $this->can($r, 'money.EDIT');
        abort_unless(DB::table('fin_bank_accounts')->where('id', $id)->where('company_id', Context::ent())->exists(), 404);
        $n = Money::autoMatch((int) Context::ent(), $id);
        return ['matched' => $n, 'message' => $n ? "$n line(s) matched" : 'Nothing more could be matched for sure'];
    }

    // ---- expenses ----
    public function expenses(Request $r)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent(); $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('month')) ? $r->query('month') : date('Y-m');
        $rows = DB::table('fin_expenses as e')->leftJoin('fin_bank_accounts as b', 'b.id', '=', 'e.fk_id_paid_from')->leftJoin('t_users as u', 'u.id', '=', 'e.created_by')
            ->leftJoin('sys_approvals as a', fn ($j) => $j->on('a.entity_id', '=', 'e.id')->where('a.entity_table', 'fin_expenses'))->where('e.fk_id_ent', $ent)->whereRaw("DATE_FORMAT(e.d_expense, '%Y-%m') = ?", [$month])->orderByDesc('e.d_expense')->orderByDesc('e.id')
            ->get(['e.id', 'e.d_expense', 'e.category_code', 'e.amount', 'b.currency', 'e.amount_gbp', 'b.account_label as bank', 'e.note', 'e.source', 'e.f_active', 'e.fk_id_bank_line', 'a.status as approval', 'u.display_name as by']);
        $names = DB::table('fin_categories')->pluck('name', 'code');
        foreach ($rows as $x) $x->category = $names[$x->category_code] ?? $x->category_code;
        foreach ($rows as $x) $x->status = $x->approval === 'PENDING' ? 'WAITING' : ($x->approval === 'REJECTED' ? 'REJECTED' : ($x->f_active ? ($x->fk_id_bank_line ? 'ON_BANK' : 'BOOKED') : 'CANCELLED'));
        return ['rows' => $rows, 'month' => $month, 'total' => round((float) $rows->whereIn('status', ['BOOKED', 'ON_BANK'])->sum('amount_gbp'), 2), 'base' => Fx::base($ent), 'limit' => Approvals::limit($ent, 'EXPENSE', 250),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name', 'currency']), 'can_add' => \App\Services\Perm::has($r->user(), 'money.EXPENSE'), 'vat' => \App\Services\Choices::get('VAT_REGISTERED', $ent) === '1'] + $this->lists($ent);
    }

    public function expenseAdd(Request $r)
    {
        $this->can($r, 'money.EXPENSE');
        $d = $r->validate(['bank' => 'required|integer', 'category' => 'required|string|max:40', 'amount' => 'required|numeric|min:0.01', 'date' => 'required|date', 'branch' => 'nullable|integer', 'note' => 'nullable|string|max:200', 'vat_amount' => 'nullable|numeric|min:0']);
        return $this->run(function () use ($d) { $x = Money::addExpense((int) Context::ent(), array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['status'] === 'WAITING' ? 'Saved – it is over the limit, so it waits for approval and is not booked yet' : 'Expense booked']; });
    }

    public function expenseCancel(Request $r, int $id)
    {
        $this->can($r, 'money.EXPENSE');
        $d = $r->validate(['reason' => 'required|string|max:200']);
        return $this->run(function () use ($id, $d) { Money::cancelExpense((int) Context::ent(), $id, $d['reason']); return ['message' => 'Expense cancelled']; });
    }

    // ---- approvals ----
    public function approvals(Request $r)
    {
        $this->can($r, 'money.SEE');
        $rows = Approvals::pending((int) Context::ent()); $me = (int) $r->user()->id; $ok = \App\Services\Perm::has($r->user(), 'money.approve');
        foreach ($rows as $a) $a->can_decide = $ok && $a->status === 'PENDING' && (int) $a->requested_by !== $me;
        return ['rows' => $rows, 'waiting' => count(array_filter($rows, fn ($a) => $a->status === 'PENDING')), 'base' => Fx::base((int) Context::ent())];
    }

    public function decide(Request $r, int $id)
    {
        $this->can($r, 'money.approve');
        $d = $r->validate(['approve' => 'required|boolean', 'reason' => 'nullable|string|max:300']);
        return $this->run(function () use ($id, $d) { Approvals::decide($id, (bool) $d['approve'], $d['reason'] ?? null); return ['message' => $d['approve'] ? 'Approved and booked' : 'Rejected']; });
    }

    // ---- marketplace statements ----
    public function settlements(Request $r)
    {
        $this->can($r, 'money.SEE');
        $ent = (int) Context::ent(); $shop = (int) $r->query('shop', 0);
        $q = \App\Services\Perm::mine(DB::table('mo_settlements as s'), 's.fk_id_branch')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->when($shop, fn ($x) => $x->where('s.fk_id_branch', $shop));
        $counts = (clone $q)->groupBy('s.status')->selectRaw('s.status, COUNT(*) n, SUM(s.payable) v')->get()->keyBy('status');
        return ['rows' => $q->orderByDesc('s.statement_date')->orderByDesc('s.id')->limit(300)->get(['s.id', 'b.shop_name as shop', 's.statement_id', 's.payout_id', 's.statement_date', 's.payout_date', 's.currency', 's.total', 's.net_sales', 's.shipping', 's.fees', 's.adjustments', 's.reserve', 's.payable', 's.status', 's.rows_count', 's.f_rows_complete']),
            'counts' => $counts, 'shop' => $shop, 'balances' => Settle::shopBalances($ent), 'base' => Fx::base($ent), 'can_edit' => \App\Services\Perm::has($r->user(), 'money.EDIT'), 'shops' => $this->lists($ent)['shops'],
            'periods' => DB::table('mo_settlement_periods as p')->join('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)->orderByDesc('p.period_to')->limit(50)->get(['p.id', 'b.shop_name as shop', 'p.fk_id_branch', 'p.period_from', 'p.period_to', 'p.total', 'p.net_sales', 'p.shipping', 'p.fees', 'p.adjustments', 'p.hub_check', 'p.checks_json'])
                ->map(function ($p) { $p->checks = array_values(json_decode($p->checks_json ?: '[]', true)); unset($p->checks_json); return $p; }),
            'refunds' => DB::table('t_sale_refund_proposals')->where('status', 'PROPOSED')->whereIn('fk_id_sale', \App\Services\Perm::mine(DB::table('t_sales'), 'fk_id_branch')->where('fk_id_ent', $ent)->select('id'))->count()];
    }

    public function settlementImport(Request $r)
    {
        $this->can($r, 'money.EDIT');
        $r->validate(['shop' => 'required|integer', 'file' => 'required|file|max:20480']);
        $f = $r->file('file');
        abort_unless(in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'xls', 'csv', 'txt', 'tsv'], true), 422, 'Choose the file the marketplace gave you (.xlsx, .csv or .txt).');
        $opt = $r->validate(['platform' => 'nullable|string|max:10', 'reference' => 'nullable|string|max:34', 'payout_date' => 'nullable|date', 'currency' => 'nullable|string|size:3']);
        return $this->run(function () use ($r, $f, $opt) {
            $x = \App\Services\MarketplaceFiles::import((int) Context::ent(), (int) $r->input('shop'), $f->getRealPath(), $f->getClientOriginalName(), $opt);
            return $x + ['message' => "{$x['format_name']}: {$x['statements_new']} new statement(s)" . ($x['statements_already'] ? ", {$x['statements_already']} were already loaded" : '') . (($x['all_ok'] ?? true) ? ' · all checks passed' : ' · loaded, but look at the checks')];
        });
    }

    public function settlementStep(Request $r, int $id, string $step)
    {
        $this->can($r, 'money.EDIT');
        return $this->run(function () use ($id, $step) { $ent = (int) Context::ent(); if ($step === 'post') { Settle::post($ent, $id); return ['message' => 'Statement booked']; } Settle::unpost($ent, $id); return ['message' => 'Statement taken back']; });
    }

    public function settlementPostAll(Request $r)
    {
        $this->can($r, 'money.EDIT');
        $d = $r->validate(['shop' => 'required|integer']);
        return $this->run(function () use ($d) { $n = Settle::postAll((int) Context::ent(), (int) $d['shop']); return ['booked' => $n, 'message' => "$n statement(s) booked"]; });
    }

    /** R-FIN-70: open expenses of the line's account (±30 days) to tick for a match by hand. */
    public function payables(Request $r, int $id)
    {
        $this->can($r, 'money.SEE'); $ent = (int) Context::ent();
        $l = \Illuminate\Support\Facades\DB::table('fin_bank_transactions')->where('id', $id)->where('company_id', $ent)->first() ?? abort(404);
        return ['line' => ['id' => (int) $l->id, 'amount' => (float) $l->amount, 'date' => $l->txn_date], 'rows' => \Illuminate\Support\Facades\DB::table('fin_expenses as e')->leftJoin('t_account as a', 'a.id', '=', 'e.fk_id_payee')
            ->where('e.fk_id_ent', $ent)->where('e.f_active', 1)->whereNull('e.fk_id_bank_line')->where('e.fk_id_paid_from', $l->bank_account_id)
            ->whereBetween('e.d_expense', [date('Y-m-d', strtotime("$l->txn_date -30 day")), date('Y-m-d', strtotime("$l->txn_date +30 day"))])->orderBy('e.d_expense')->limit(200)
            ->get(['e.id', 'e.d_expense', 'e.amount', 'e.category_code', 'e.fk_id_payee', \Illuminate\Support\Facades\DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as payee"), 'e.note'])];
    }

    /** R-FIN-22 ADS / FBA_STOCK / RETURNS / REIMB / STORAGE files of a shop. */
    public function financeReport(Request $r)
    {
        if (! $r->hasFile('file')) return response()->json(['message' => 'No file uploaded'], 422);
        return $this->run(fn () => \App\Services\FinanceReports::import((int) Context::ent(), (int) $r->input('shop'), (string) $r->input('type'), $r->file('file')->getClientOriginalName(), (string) file_get_contents($r->file('file')->getRealPath()), $r->user()));
    }
}
