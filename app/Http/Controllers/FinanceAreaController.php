<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\FinanceArea;
use App\Services\Perm;
use App\Services\TaxService;
use Illuminate\Http\Request;

/** Batch 7e-220 finance screens: Bank & cash, Owner finance home, product × period, ad spend, decisions, scenario, sources, account audit, month status, line profit, taxation. */
class FinanceAreaController extends Controller
{
    private function ent(): int { return (int) Context::ent(); }
    private function run(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    private function money(Request $r): void { Perm::need($r->user(), 'money.SEE'); }

    public function bankCash(Request $r) { $this->money($r); return $this->run(fn () => FinanceArea::bankCash($this->ent(), (string) ($r->query('month') ?: date('Y-m')))); }
    public function home(Request $r) { $this->money($r); return FinanceArea::home($this->ent(), $r->query('as_of')); }
    public function productPeriod(Request $r) { $this->money($r); return FinanceArea::productPeriod($this->ent(), (string) ($r->query('from') ?: date('Y-m-01')), (string) ($r->query('to') ?: date('Y-m-d'))); }
    public function adSpend(Request $r) { $this->money($r); return FinanceArea::adSpend($this->ent(), (string) ($r->query('month') ?: date('Y-m'))); }
    public function decisions(Request $r) { $this->money($r); return FinanceArea::decisions($this->ent()); }
    public function scenario(Request $r) { Perm::need($r->user(), 'money.forecast'); return $this->run(fn () => FinanceArea::scenario($this->ent(), (string) $r->input('title', ''), (array) $r->input('items', []), $r->boolean('save'))); }
    public function sources(Request $r) { $this->money($r); return ['steps' => ['Set up companies, shops, banks and partners', 'Load the marketplace reports and money files', 'Match the bank lines', 'See the profit by shop, product and month', 'Keep stock and lots right',
        'Close each month (the Owner locks it)', 'Partners read their own statements', 'Money moves only through the bank lines', 'Alerts tell what needs action'], 'sources' => FinanceArea::sources($this->ent())]; }
    public function accountAudit(Request $r, int $id) { $this->money($r); return FinanceArea::accountAudit($this->ent(), $id, $r->query('from'), $r->query('to')); }
    public function monthStatus(Request $r) { Perm::need($r->user(), 'orders.fulfilSee'); return FinanceArea::monthStatus($this->ent(), (int) $r->query('shop'), (string) ($r->query('from') ?: date('Y-01-01')), (string) ($r->query('to') ?: date('Y-m-d'))); }
    public function lineProfit(Request $r, int $id) { $this->money($r); return FinanceArea::lineProfit($id) ?? response()->json(['message' => 'Sale line not found.'], 404); }
    public function recode(Request $r) { Perm::need($r->user(), 'admin.owner'); return FinanceArea::recode($this->ent()); }

    public function tax(Request $r) { Perm::need($r->user(), 'tax.use'); return TaxService::dashboard($this->ent()); }
    public function taxPeriod(Request $r, int $id) { Perm::need($r->user(), 'tax.use'); return $this->run(fn () => TaxService::period($id)); }
    public function taxFill(Request $r, int $id) { Perm::need($r->user(), 'tax.use'); return $this->run(fn () => TaxService::fillFromFinance($id)); }
    public function taxNext(Request $r) { Perm::need($r->user(), 'tax.use'); return TaxService::nextPeriod((int) $r->query('company')); }
}
