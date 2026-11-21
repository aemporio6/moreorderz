<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Buying screens: dashboard, research workspace, cycle workspace, PO page and the buttons of each step. */
class BuyingController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'SOURCING', 'FINANCE', 'ACCOUNTANT', 'PARTNER', 'WAREHOUSE'];
    private const BUY = ['OWNER', 'MANAGER', 'SOURCING'];
    private const PAY = ['OWNER', 'FINANCE'];
    private const RECEIVE = ['OWNER', 'MANAGER', 'WAREHOUSE', 'SOURCING'];
    private const COST = ['OWNER', 'MANAGER', 'FINANCE'];

    private function can(Request $r, string $code): void
    {
        \App\Services\Perm::need($r->user(), $code);
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\App\Services\SrcDuplicateException $e) { return response()->json($e->payload(), 422); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function rights(Request $r): array
    {
        $role = $r->user()->role_code; $money = \App\Services\Perm::has($r->user(), 'see_money');
        return ['buy' => in_array($role, self::BUY, true), 'pay' => in_array($role, self::PAY, true), 'receive' => in_array($role, self::RECEIVE, true), 'landed_cost' => $money && in_array($role, self::COST, true),
            'go' => in_array($role, ['OWNER', 'MANAGER', 'PARTNER'], true), 'money' => $money, 'landed_final' => \App\Services\Perm::has($r->user(), \App\Services\LandedVersions::FINAL_RIGHT)];      // R-F_SETUP-16: one check, no role list
    }

    public function dashboard(Request $r)
    {
        $this->can($r, 'buying.SEE');
        $ent = (int) Context::ent();
        // R-F_SETUP-15: every block on its own; a failing one is named in the "could not load" box, the rest of the page works
        // R-F_SETUP-18 filters (supplier / search / stage tile) from the URL; the last ones are the user's saved view (Reset clears it)
        $uid = (int) $r->user()->id; $pk = 'view:buying_cycles'; $f = array_filter(['supplier' => (int) $r->query('supplier') ?: null, 'q' => trim((string) $r->query('q')) ?: null, 'stage' => (string) $r->query('stage') ?: null]);
        if ($r->boolean('reset')) { DB::table('t_user_column_prefs')->where('fk_id_user', $uid)->where('page_key', $pk)->delete(); $f = []; }
        elseif ($f) DB::table('t_user_column_prefs')->updateOrInsert(['fk_id_user' => $uid, 'page_key' => $pk], ['prefs_json' => json_encode($f), 'hidden_columns' => '', 'updated_at' => now()]);
        elseif (! $r->has('supplier') && ! $r->has('q') && ! $r->has('stage')) $f = json_decode((string) DB::table('t_user_column_prefs')->where('fk_id_user', $uid)->where('page_key', $pk)->value('prefs_json'), true) ?: [];
        $st = \App\Services\DashCards::run($ent, 'stages', 'Open cycles', fn () => \App\Services\CycleAlerts::dashboard($ent, max(1, (int) $r->query('page', 1)), 60, $f));      // R-F_SETUP-19: one stage engine for every cycle row
        $kp = \App\Services\DashCards::run($ent, 'kpi', 'Buying figures', function () use ($ent, $r) { $d = Buy::dashboard($ent);
            if (! \App\Services\Perm::has($r->user(), 'buying.MONEY')) unset($d['pay_due_base'], $d['supplier_advances'], $d['supplier_payables']); return $d; });
        return ['kpi' => $kp['ok'] ? $kp['data'] : null, 'cycles' => $st['ok'] ? $st['data']['rows'] : [], 'stages' => $st['ok'] ? $st['data'] : null, 'failed' => array_values(array_map(fn ($x) => $x['label'], array_filter([$kp, $st], fn ($x) => ! $x['ok']))), 'rights' => $this->rights($r)];
    }

    public function pick(Request $r)
    {
        $this->can($r, 'buying.SEE');
        $ent = Context::ent();
        $pays = in_array($r->user()->role_code, self::PAY, true);      // our bank accounts only for the people who pay (owner answer 21 Nov 2026: sourcing sees amounts, not banks)
        return ['banks' => $pays ? DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', account_label) as name"), 'currency']) : [],
            'locations' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('f_active', 1)->orWhereNull('f_active'))->whereNotIn('kind', ['DAMAGED', 'RETURNS', 'WRITTEN_OFF', 'QUARANTINE', 'MISSING', 'TRANSIT'])->orderBy('location_name')->limit(500)->get(['id', 'location_name as name']),
            'suppliers' => DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('supplier_name')->limit(500)->get(['id', 'supplier_name as name']),
            'products' => DB::table('t_product_vendors')->where('fk_id_ent', $ent)->orderBy('SKU_VENDOR')->limit(500)->get(['id', DB::raw("CONCAT(SKU_VENDOR, ' · ', product_name_vendor) as name")]),
            'base' => Fx::base($ent)];
    }

    // ---- research ----
    public function research(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        $x = DB::table('src_research')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404);
        try { $margin = Buy::refreshMargin($id); } catch (\Throwable $e) { $margin = $x->est_margin_pct; }
        $cycle = $x->fk_id_cycle ? DB::table('t_src_cycles')->where('id', $x->fk_id_cycle)->first(['id', 'cycle_code']) : null;
        return ['research' => ['id' => $x->id, 'code' => $x->research_code, 'title' => $x->title, 'status' => $x->status, 'sell' => $x->target_sell_price, 'cost' => $x->est_unit_cost, 'freight' => $x->est_freight_per_unit, 'fee_pct' => $x->est_fee_pct,
                'ad_pct' => $x->est_ad_pct, 'currency' => $x->currency ?: Fx::base((int) $x->fk_id_ent), 'units' => $x->est_monthly_units, 'margin' => $margin, 'risk_flags' => $x->risk_flags, 'reason' => $x->decision_reason, 'product' => $x->fk_id_product],
            'min_margin' => (float) Settings::get('RESEARCH_MIN_MARGIN', Context::ent(), 25), 'cycle' => $cycle,
            'competitors' => DB::table('src_research_competitors')->where('fk_id_research', $id)->orderBy('id')->get(), 'suppliers' => DB::table('src_research_suppliers')->where('fk_id_research', $id)->orderBy('id')->get(), 'rights' => $this->rights($r)];
    }

    public function researchNumbers(Request $r, int $id)
    {
        $this->can($r, 'buying.BUY');
        $d = $r->validate(['sell' => 'required|numeric|min:0', 'cost' => 'required|numeric|min:0', 'freight' => 'nullable|numeric|min:0', 'fee_pct' => 'nullable|numeric|min:0|max:100', 'ad_pct' => 'nullable|numeric|min:0|max:100', 'units' => 'nullable|integer|min:0']);
        $x = DB::table('src_research')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404);
        abort_if($x->status === 'GO', 422, 'This research already started sourcing.');
        DB::table('src_research')->where('id', $id)->update(['target_sell_price' => $d['sell'], 'est_unit_cost' => $d['cost'], 'est_freight_per_unit' => $d['freight'] ?? 0, 'est_fee_pct' => $d['fee_pct'] ?? 0, 'est_ad_pct' => $d['ad_pct'] ?? 0, 'est_monthly_units' => $d['units'] ?? $x->est_monthly_units]);
        Audit::log('src_research', $id, 'UPDATE', ['estimate' => [null, $d]]);
        return ['margin' => Buy::refreshMargin($id), 'message' => 'Estimate saved'];
    }

    public function researchChild(Request $r, int $id, string $kind)
    {
        $this->can($r, 'buying.BUY');
        abort_unless(DB::table('src_research')->where('id', $id)->where('fk_id_ent', Context::ent())->exists(), 404);
        if ($kind === 'competitor') {
            $d = $r->validate(['listing_url' => 'required|url|max:500', 'seller_name' => 'nullable|string|max:120', 'price' => 'nullable|numeric|min:0', 'est_monthly_sold' => 'nullable|integer|min:0', 'review_count' => 'nullable|integer|min:0', 'rating' => 'nullable|numeric|min:0|max:5']);
            DB::table('src_research_competitors')->insert(array_filter($d, fn ($v) => $v !== null) + ['fk_id_research' => $id, 'checked_at' => date('Y-m-d')]);
        } else {
            $d = $r->validate(['fk_id_supplier' => 'nullable|integer', 'supplier_name_text' => 'required_without:fk_id_supplier|nullable|string|max:150', 'source_url' => 'nullable|url|max:500', 'unit_price' => 'required|numeric|min:0', 'currency' => 'required|string|size:3', 'moq' => 'nullable|integer|min:0', 'lead_days' => 'nullable|integer|min:0|max:999'],
                ['supplier_name_text.required_without' => 'Choose a supplier or type a new name.']);
            DB::table('src_research_suppliers')->insert(array_filter($d, fn ($v) => $v !== null && $v !== '') + ['fk_id_research' => $id]);
        }
        Audit::log('src_research', $id, 'UPDATE', [$kind => [null, 'added']]);
        return ['message' => $kind === 'competitor' ? 'Competitor added' : 'Supplier candidate added'];
    }

    public function researchStatus(Request $r, int $id)
    {
        $this->can($r, 'buying.BUY');
        $d = $r->validate(['status' => 'required|string', 'reason' => 'nullable|string|max:1000']);
        return $this->run(function () use ($d, $id) { Buy::researchStatus($id, $d['status'], $d['reason'] ?? null); return ['message' => 'Status: ' . $d['status']]; });
    }

    public function go(Request $r, int $id)
    {
        $d = $r->validate(['qty' => 'required|numeric|min:1', 'reason' => 'nullable|string|max:1000']);
        return $this->run(fn () => Buy::go($id, $d) + ['message' => 'Sourcing started: product, cycle and quotes are ready']);
    }

    // ---- cycle, RFQ, PO ----
    public function shipmentPos(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\ShipmentLinks::page((int) Context::ent(), $id)); }
    public function shipmentPoSave(Request $r, int $id) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\ShipmentLinks::save((int) Context::ent(), $id, $r->only(['po', 'planned', 'actual', 'reason']))); }
    public function shipmentPoRemove(Request $r, int $id, int $po) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\ShipmentLinks::remove((int) Context::ent(), $id, $po)); }
    public function cycleOverview(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\CycleAlerts::overview((int) Context::ent(), $id, $r->user())); }
    /** R-F_SETUP-3 / 4 / 9: landed cost per cycle, outstanding supplier payments, PO payment position (money: buying.MONEY). */
    public function repLanded(Request $r, int $id) { $this->can($r, 'buying.MONEY'); return $this->run(fn () => \App\Services\SourcingReports::landed((int) Context::ent(), $id)); }
    /** R-F_SETUP-15: one card per call – contacts, shipments, payments (see money), needs. */
    public function card(Request $r, string $card)
    {
        $this->can($r, 'buying.SEE'); $ent = (int) Context::ent();
        if (in_array($card, ['payments', 'investors', 'invoicing'], true) && ! \App\Services\Perm::has($r->user(), 'buying.MONEY')) abort(403, 'This card needs the right to see money.');
        $money = \App\Services\Perm::has($r->user(), 'buying.MONEY');
        if ($card === 'sales' && ! \App\Services\Perm::has($r->user(), 'orders.SEE')) abort(403, 'This card needs the right to see sales.');
        $per = in_array($card, ['investors', 'invoicing', 'team', 'buyingcard', 'sales'], true) ? \App\Services\Period::forCard((int) $r->user()->id, $card, $r->query('period'), $r->query('from'), $r->query('to')) : null;      // R-F_SETUP-27
        $fn = match ($card) {
            'contacts' => ['Contact today', fn () => \App\Services\SourcingBoard::contactToday($ent)], 'shipments' => ['Shipments on the way', fn () => \App\Services\SourcingBoard::shipments($ent, $r->boolean('all') ? 500 : 15)],
            'payments' => ['Supplier payments', fn () => \App\Services\DashCards::payments($ent)], 'needs' => ['Needs action', fn () => \App\Services\DashCards::needs($ent, $r->boolean('all'))],
            'investors' => ['Investors', fn () => \App\Services\DashCards::investors($ent, $per['from'], $per['to']) + ['period' => $per]],
            'sales' => ['Sales', fn () => \App\Services\SalesCard::card($ent, $r->query('mp') ? (int) $r->query('mp') : null, $per['from'], $per['to'], $money) + ['period' => $per]],      // R-F_SETUP-26
            'stockhealth' => ['Inventory Management', fn () => \App\Services\StockHealth::card($ent)],      // R-F_SETUP-28
            'lots' => ['Inventory receiving', fn () => \App\Services\DashCards::lots($ent, $money)], 'glance' => ['Sourcing Management', fn () => \App\Services\DashCards::glance($ent, $money)],
            'buyingcard' => ['Buying Management', fn () => \App\Services\DashCards::buying($ent, $per['from'], $per['to'], $money) + ['period' => $per]],
            'team' => ['Team activity', fn () => \App\Services\DashCards::team($ent, $per['from'], $per['to']) + ['period' => $per]],
            'invoicing' => ['Invoicing / Profit', fn () => \App\Services\DashCards::invoicing($ent, $per['from'], $per['to'], (string) Context::role()) + ['period' => $per]],
            default => abort(404) };
        return \App\Services\DashCards::run($ent, $card, $fn[0], $fn[1]);
    }
    /** R-F_SETUP-28: the products behind one row of the Inventory Management card. */
    public function stockHealth(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\StockHealth::list((int) Context::ent(), (string) $r->query('c', 'low'))); }
    /** R-F_SETUP-22 / 23: Contact today and the shipments board on the buying dashboard. */
    public function contactsToday(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\SourcingBoard::contactToday((int) Context::ent())); }
    public function contactsWa(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\SourcingBoard::whatsapp((int) Context::ent(), (string) $r->input('key'), (int) $r->input('cycle'), (string) $r->input('item'), trim((string) $r->input('text')))); }
    public function shipBoard(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\SourcingBoard::shipments((int) Context::ent(), $r->boolean('all') ? 500 : 15)); }
    public function repTransit(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\SourcingReports::inTransit((int) Context::ent())); }
    public function repSuppliers(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(function () use ($r) { $x = \App\Services\SourcingReports::supplierPerf((int) Context::ent(), (string) ($r->query('from') ?: date('Y-01-01')), (string) ($r->query('to') ?: date('Y-m-d')));
        if (! \App\Services\Perm::has($r->user(), 'see_money')) foreach ($x['rows'] as &$row) unset($row['value']); return $x; }); }
    public function repInventory(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(function () use ($r) { $x = \App\Services\SourcingReports::inventory((int) Context::ent(), $r->query('as_of') ?: null);
        if (! \App\Services\Perm::has($r->user(), 'see_money')) { foreach ($x['physical'] as &$row) unset($row['value']); unset($x['total']['value']); foreach (['by_country', 'by_location'] as $k) foreach ($x[$k] as &$v) unset($v['value']); } return $x; }); }
    /** R-F_SETUP-8: OWNER / PARTNER (and buying money) see every investor; an INVESTOR sees only his own row. */
    public function repInvestors(Request $r)
    {
        $role = (string) Context::role(); $u = $r->user();
        if ($role === 'INVESTOR') return $this->run(fn () => \App\Services\SourcingReports::investors((int) Context::ent(), (int) ($u->fk_id_account ?: -1)));
        $this->can($r, 'buying.MONEY'); return $this->run(fn () => \App\Services\SourcingReports::investors((int) Context::ent()));
    }
    public function repOutstanding(Request $r) { $this->can($r, 'buying.MONEY'); return $this->run(fn () => \App\Services\SourcingReports::outstanding((int) Context::ent(), $r->only(['supplier', 'cycle', 'ageing', 'currency']))); }
    public function poPosition(Request $r, int $id) { $this->can($r, 'buying.MONEY'); return $this->run(fn () => \App\Services\SourcingReports::poPosition((int) Context::ent(), $id)); }
    public function linkTerm(Request $r, int $id) { $this->can($r, 'buying.PAY'); return $this->run(fn () => \App\Services\SourcingReports::linkPayment((int) Context::ent(), $id, (int) $r->input('term'))); }

    /** R-F_SETUP-1 hub pickers (company scope, R-F_SETUP-17): active cycles newest first, searched on code / product / SKU; POs on number or code, of the chosen cycle. */
    public function pickCycles(Request $r)
    {
        $this->can($r, 'buying.SEE'); $q = trim((string) $r->query('q'));
        return ['rows' => DB::table('t_src_cycles as c')->where('c.fk_id_ent', Context::ent())->where('c.f_active', 1)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('c.cycle_code', 'like', "%$q%")->orWhere('c.product_name', 'like', "%$q%")->orWhere('c.sku', 'like', "%$q%")
                ->orWhereExists(fn ($e) => $e->from('t_src_cycle_products as cp')->whereColumn('cp.fk_id_cycle', 'c.id')->where('cp.f_active', 1)->where(fn ($y) => $y->where('cp.sku', 'like', "%$q%")->orWhere('cp.product_name', 'like', "%$q%")))))
            ->orderByDesc('c.id')->limit(30)->get(['c.id', 'c.cycle_code as code', 'c.product_name as name', 'c.status'])];
    }
    public function pickPos(Request $r)
    {
        $this->can($r, 'buying.SEE'); $q = trim((string) $r->query('q')); $cy = (int) $r->query('cycle');
        return ['rows' => DB::table('t_src_po')->where('fk_id_ent', Context::ent())->where('f_active', 1)->when($cy, fn ($w) => $w->where('fk_id_cycle', $cy))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('po_number', 'like', "%$q%")->orWhere('po_code', 'like', "%$q%")))->orderByDesc('id')->limit(30)
            ->get(['id', DB::raw('COALESCE(NULLIF(po_number, \'\'), po_code) as code'), 'status', 'fk_id_cycle as cycle'])];
    }

    /** R-F_SETUP-11: supplier PO PDF (version n, kept with the PO) and Send to supplier. */
    public function poPdf(Request $r, int $id) { $this->can($r, 'buying.MONEY'); return $this->run(function () use ($r, $id) { $x = \App\Services\PoPdf::make((int) Context::ent(), $id, ! $r->has('bank') || $r->boolean('bank')); return response($x['body'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $x['file'] . '"']); }); }
    public function poSend(Request $r, int $id) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\PoPdf::send((int) Context::ent(), $id, (string) $r->input('via'))); }

    /** R-F_SETUP-10: document roll-up of a PO / cycle / product (duplicates merged with every "Linked via"). */
    /** R-B_SOURCING-4: Repeat this cycle. */
    public function cycleRepeat(Request $r, int $id) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\CycleRepeat::repeat((int) Context::ent(), $id, (int) $r->user()->id)); }
    /** R-B_SOURCING-48: documents of one cycle product. */
    public function cycleProductDocs(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\DocRollup::forCycleProduct((int) Context::ent(), $id)); }
    public function poDocs(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\DocRollup::forPo((int) Context::ent(), $id)); }
    public function cycleDocs(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\DocRollup::forCycle((int) Context::ent(), $id)); }
    public function productDocs(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\DocRollup::forProduct((int) Context::ent(), $id)); }
    /** R-F_SETUP-13: the GRN PDF (made again, kept in the cycle's documents). Labels go through the print routes (kind GRN_LABEL). */
    public function grnPdf(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(function () use ($id) { $x = \App\Services\GrnPrint::store((int) Context::ent(), $id); return response($x['body'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $x['file'] . '"']); }); }
    /** R-F_SETUP-2 Cycle Journey / R-F_SETUP-12 Cycle Summary: JSON, or ?pdf=1 for the PDF. */
    public function journey(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(function () use ($r, $id) { $money = \App\Services\Perm::has($r->user(), 'buying.MONEY'); if (! $r->boolean('pdf')) return \App\Services\CycleReports::journey((int) Context::ent(), $id, $money);
        $x = \App\Services\CycleReports::journeyPdf((int) Context::ent(), $id, $money); return response($x['body'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $x['name'] . '"']); }); }
    public function summary(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(function () use ($r, $id) { $money = \App\Services\Perm::has($r->user(), 'buying.MONEY');
        if (! $r->boolean('pdf')) { $s = \App\Services\CycleReports::summary((int) Context::ent(), $id); if (! $money) unset($s['money'], $s['import_vat'], $s['footnote']); return $s; }
        if (! $money) abort(403, 'The cycle summary shows money – it needs the right to see buying money.');
        $x = \App\Services\CycleReports::summaryPdf((int) Context::ent(), $id); return response($x['body'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $x['name'] . '"']); }); }
    /** R-B_SOURCING-18: after a step form's save – the next step's form, its preset (locked parents) and the records that already exist. */
    public function cycleNext(Request $r) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\WorkflowContext::saveNext((string) $r->input('form'), (int) $r->input('id'), (int) \Illuminate\Support\Facades\Auth::id())); }
    /** The carried context of a cycle for the user, and the preset of a step form opened inside it. */
    public function cycleContext(Request $r, int $id) { $this->can($r, 'buying.SEE'); $ctx = \App\Services\WorkflowContext::get($id, (int) \Illuminate\Support\Facades\Auth::id());
        $f = (string) $r->query('form'); return ['context' => $ctx, 'preset' => $f !== '' ? \App\Services\WorkflowContext::prefill($f, $id, $ctx) : null, 'step' => $f !== '' ? \App\Services\WorkflowContext::stepOf($f) : null, 'next' => $f !== '' ? \App\Services\WorkflowContext::next($f, $id) : null]; }
    /** R-B_SOURCING-20: Previous / Next step of the cycle record (skipped steps jumped). */
    public function cyclePager(Request $r, int $id) { $this->can($r, 'buying.SEE'); if (! DB::table('t_src_cycles')->where('id', $id)->where('fk_id_ent', Context::ent())->exists()) abort(404);
        return \App\Services\WorkflowContext::pager($id, (string) $r->query('step', 'details'), \App\Services\Perm::has($r->user(), 'see_money')); }
    public function cycleWhatsapp(Request $r, int $id) { $this->can($r, 'buying.SEE'); return $this->run(fn () => \App\Services\CycleAlerts::whatsapp((int) Context::ent(), $id, (string) $r->input('key'), trim((string) $r->input('text')))); }
    public function grnClaim(Request $r, int $id) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\CycleAlerts::claim((int) Context::ent(), $id, strtoupper((string) $r->input('status')), (string) $r->input('reason'))); }
    public function docRequirements(Request $r) { $this->can($r, 'buying.SEE'); return ['rows' => DB::table('src_doc_requirements')->where('fk_id_ent', Context::ent())->orderBy('step_key')->orderBy('doc_type')->get(), 'steps' => array_map(fn ($x) => $x[3], \App\Services\CycleAlerts::DOC_STEPS),
        'types' => DB::table('cat_doc_types')->where('f_active', 1)->orderBy('doc_type_name')->pluck('doc_type_name'), 'can_edit' => in_array(Context::role(), ['OWNER', 'MANAGER'], true)]; }
    public function docRequirementSave(Request $r)
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER'], true)) abort(403, 'Only the Owner or a Manager sets the required documents.');
        return $this->run(function () use ($r) {
            $k = (string) $r->input('step_key'); $t = trim((string) $r->input('doc_type')); if (! isset(\App\Services\CycleAlerts::DOC_STEPS[$k])) throw new \InvalidArgumentException('Choose the step.'); if ($t === '') throw new \InvalidArgumentException('Write the document type.');
            DB::table('src_doc_requirements')->updateOrInsert(['fk_id_ent' => Context::ent(), 'step_key' => $k, 'doc_type' => mb_substr($t, 0, 100)], ['f_active' => $r->boolean('active', true) ? 1 : 0, 'created_at' => now()]);
            \App\Services\Audit::log('src_doc_requirements', 0, 'UPDATE', ['step' => $k, 'document' => $t, 'active' => $r->boolean('active', true)]); \App\Services\CycleAlerts::touched();
            return ['message' => "Required document saved: $t on " . \App\Services\CycleAlerts::DOC_STEPS[$k][3] . '.'];
        });
    }

    public function cycle(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        $w = Buy::cycle($id);
        $w['stages'] = \App\Services\CycleAlerts::pipeline(DB::table('t_src_cycles')->where('id', $id)->first());      // R-F_SETUP-19: the cycle header reads the same stage engine
        if (! \App\Services\Perm::has($r->user(), 'buying.MONEY')) { foreach (['rfqs' => ['price'], 'pos' => ['po_total'], 'landed' => ['freight_cost', 'customs_duty', 'other_cost', 'total_cost', 'landed_cost_per_unit']] as $k => $cols) foreach ($w[$k] as $row) foreach ($cols as $c) unset($row->$c); $w['terms'] = []; }
        return $w + ['rights' => $this->rights($r)];
    }

    public function acceptRfq(Request $r, int $id)
    {
        $this->can($r, 'buying.BUY');
        return $this->run(function () use ($id) { Buy::acceptRfq($id); return ['message' => 'Quote accepted']; });
    }

    /** Direct PO in one transaction (R-B_SOURCING-2): what the form opens with, and the save. */
    public function directPoPrefill(Request $r) { $this->can($r, 'buying.BUY'); return $this->run(fn () => \App\Services\DirectPo::prefill((int) Context::ent(), (int) $r->query('supplier'), $r->filled('repeat') ? (int) $r->query('repeat') : null)); }
    public function directPo(Request $r)
    {
        $this->can($r, 'buying.BUY');
        return $this->run(fn () => \App\Services\DirectPo::save((int) Context::ent(), $r->only(['supplier', 'repeat_from', 'currency', 'rate', 'incoterm', 'payment_term', 'expected_ready', 'required_delivery', 'deposit_pct', 'paid_in_full', 'notes', 'order_ref', 'dup_reason', 'dup_checked', 'lines'])));
    }

    public function createPo(Request $r)
    {
        $this->can($r, 'buying.BUY');
        $d = $r->validate(['rfq' => 'nullable|integer', 'repeat_from' => 'nullable|integer', 'supplier' => 'nullable|integer', 'currency' => 'nullable|string|size:3', 'product' => 'nullable|integer', 'qty' => 'required|numeric|min:1', 'unit_price' => 'nullable|numeric|min:0', 'date' => 'nullable|date', 'deposit_pct' => 'nullable|numeric|min:0|max:100', 'order_ref' => 'nullable|string|max:100', 'dup_reason' => 'nullable|string|max:300']);
        $dup = array_filter(['order_ref' => $d['order_ref'] ?? null, 'dup_reason' => $d['dup_reason'] ?? null]);
        return $this->run(function () use ($d, $dup) {
            if (! empty($d['repeat_from'])) {      // direct PO: same supplier, last price, no research
                $old = DB::table('t_src_po')->where('id', $d['repeat_from'])->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('The PO to repeat was not found.');
                $lines = DB::table('t_src_po_lines')->where('fk_id_po', $old->id)->where('f_active', 1)->get()->map(fn ($l) => ['product' => $l->fk_id_product, 'qty' => $d['qty'], 'unit_price' => $l->unit_price])->all();
                $id = Buy::createPo(['supplier' => $old->fk_id_supplier, 'currency' => Fx::code($old->fk_id_currency, Context::ent()), 'date' => $d['date'] ?? null, 'lines' => $lines, 'deposit_pct' => $d['deposit_pct'] ?? $old->deposit_pct, 'repeat_from' => $old->id] + $dup);
            } elseif (! empty($d['rfq'])) {
                $id = Buy::createPo(array_filter(['rfq' => $d['rfq'], 'qty' => $d['qty'], 'date' => $d['date'] ?? null, 'deposit_pct' => $d['deposit_pct'] ?? null], fn ($v) => $v !== null) + $dup);
            } else {
                if (empty($d['supplier']) || empty($d['product']) || ! isset($d['unit_price'])) throw new \InvalidArgumentException('Choose the supplier, the product and the price.');
                $id = Buy::createPo(['supplier' => $d['supplier'], 'currency' => $d['currency'] ?? Fx::base(Context::ent()), 'date' => $d['date'] ?? null, 'deposit_pct' => $d['deposit_pct'] ?? 30, 'lines' => [['product' => $d['product'], 'qty' => $d['qty'], 'unit_price' => $d['unit_price']]]] + $dup);
            }
            return ['id' => $id, 'message' => 'Purchase order made (draft)'];
        });
    }

    public function po(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        $po = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.id', $id)->where('p.fk_id_ent', Context::ent())
            ->first(['p.id', 'p.po_number', 'p.po_date', 'p.status', 'p.po_total', 'p.exchange_rate', 'p.deposit_pct', 'p.fk_id_cycle', 'p.fk_id_supplier', 'p.f_paid_in_full', 's.supplier_name as supplier', 'c.currency_code as currency']) ?? abort(404);
        $money = \App\Services\Perm::has($r->user(), 'buying.MONEY');
        $lines = DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_po', $id)->where('l.f_active', 1)
            ->get(['l.id', 'l.fk_id_product as product_id', 'l.sku', 'p.product_name_vendor as name', 'l.qty_confirmed as qty', 'l.unit_price', 'l.line_total', 'l.line_type', 'l.line_notes', 'l.fk_id_sample',
                DB::raw('(SELECT COALESCE(SUM(k.actual_qty),0) FROM t_src_shipment_po_link k JOIN t_src_shipments s ON s.id=k.fk_id_shipment WHERE k.fk_id_po_line=l.id AND k.f_active=1 AND s.f_active=1) as shipped'),
                DB::raw('(SELECT COALESCE(SUM(g.received_qty),0) FROM t_src_grn_lines g JOIN t_src_grn h ON h.id=g.fk_id_grn WHERE g.fk_id_po_line=l.id AND h.posting_status="POSTED") as received')]);
        $ships = DB::table('t_src_shipments')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('id')->get(['id', 'shipment_code', 'planned_qty', 'etd', 'eta', 'actual_arrival', 'status']);
        $out = ['po' => $po, 'lines' => $lines, 'shipments' => $ships,
            'grns' => DB::table('t_src_grn')->whereIn('fk_id_shipment', $ships->pluck('id')->all() ?: [0])->where('f_active', 1)->orderBy('id')->get(['id', 'grn_number', 'fk_id_shipment', 'grn_date', 'units_received', 'damaged_units', 'missing_units', 'posting_status']),
            'lots' => DB::table('fin_lots')->where('po_id', $id)->get(['id', 'lot_code', 'sku', 'qty', 'qty_left', 'unit_cost_gbp', 'landed_status']), 'rights' => $this->rights($r), 'base' => Fx::base(Context::ent()), 'line_types' => \App\Services\PoLines::TYPES, 'line_hint' => \App\Services\PoLines::HINT, 'stock_note' => \App\Services\PoLines::stockNote($id)];
        if ($money) {
            $out['terms'] = DB::table('t_src_payment_terms')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('term_no')->get(['id', 'milestone', 'pct', 'planned_amount', 'paid_amount', 'remaining_amount', 'due_date', 'status']);
            $out['payments'] = DB::table('t_src_payments')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('id')->get(['id', 'payment_code', 'payment_date', 'payment_amount', 'exchange_rate', 'base_amount', 'payment_type', 'status']);
            $out['landed'] = DB::table('t_src_landed_cost')->where('fk_id_po', $id)->where('f_active', 1)->orderByDesc('id')->get(['id', 'lc_code', 'run_version', 'freight_cost', 'customs_duty', 'other_cost', 'total_cost', 'landed_cost_per_unit', 'landed_status', 'superseded_by']);
        } else { foreach ($lines as $l) unset($l->unit_price, $l->line_total); unset($po->po_total, $po->exchange_rate); foreach ($out['lots'] as $l) unset($l->unit_cost_gbp); }
        return $out;
    }

    public function confirmPo(Request $r, int $id)
    {
        $this->can($r, 'buying.BUY');
        return $this->run(fn () => Buy::confirmPo($id) + ['message' => 'PO confirmed: payment schedule made']);
    }

    public function pay(Request $r)
    {
        $this->can($r, 'buying.PAY');
        $d = $r->validate(['term' => 'required|integer', 'amount' => 'required|numeric|gt:0', 'date' => 'required|date', 'bank' => 'required|integer', 'txn_ref' => 'nullable|string|max:255']);
        return $this->run(function () use ($d) { $x = Buy::payOrAsk($d); return $x + ['message' => $x['status'] === 'WAITING' ? 'Over the limit – the payment waits for approval and is not booked yet' : 'Payment posted to the ledger']; });
    }

    public function shipment(Request $r)
    {
        $this->can($r, 'buying.BUY');
        $d = $r->validate(['po' => 'required|integer', 'eta' => 'required|date', 'etd' => 'nullable|date', 'mode' => 'nullable|string|max:60']);
        return $this->run(fn () => ['id' => Buy::createShipment($d), 'message' => 'Shipment saved']);
    }

    public function grn(Request $r)
    {
        $this->can($r, 'buying.RECEIVE');
        $d = $r->validate(['shipment' => 'required|integer', 'location' => 'required|integer', 'date' => 'required|date', 'lines' => 'required|array|min:1', 'lines.*.po_line' => 'required|integer', 'lines.*.received' => 'required|integer|min:0', 'lines.*.damaged' => 'nullable|integer|min:0', 'lines.*.location' => 'nullable|integer']);
        return $this->run(fn () => ['id' => Buy::createGrn($d), 'message' => 'GRN saved as draft – check it and post']);
    }

    public function shipmentLines(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        abort_unless(DB::table('t_src_shipments')->where('id', $id)->where('fk_id_ent', Context::ent())->exists(), 404);
        return ['lines' => DB::table('t_src_shipment_po_link as k')->leftJoin('t_product_vendors as p', 'p.id', '=', 'k.fk_id_product')->where('k.fk_id_shipment', $id)->where('k.f_active', 1)->get(['k.fk_id_po_line as po_line', 'k.fk_id_product as product', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'k.actual_qty as qty'])
            ->map(function ($l) { $l->suggest = $l->product ? (\App\Services\SkuHomes::suggest((int) Context::ent(), (int) $l->product, (int) $l->qty)[0] ?? null) : null; return $l; })];
    }

    public function postGrn(Request $r, int $id)
    {
        $this->can($r, 'buying.RECEIVE');
        return $this->run(fn () => Buy::postGrn($id) + ['message' => 'GRN posted: the units are in stock']);
    }

    public function landed(Request $r, int $id)
    {
        $this->can($r, 'buying.COST');
        $d = $r->validate(['freight' => 'nullable|numeric|min:0', 'duty' => 'nullable|numeric|min:0', 'other' => 'nullable|numeric|min:0', 'final' => 'boolean', 'freight_method' => 'nullable|in:QUANTITY,VALUE,WEIGHT,CBM', 'duty_method' => 'nullable|in:QUANTITY,VALUE,WEIGHT,CBM', 'other_method' => 'nullable|in:QUANTITY,VALUE,WEIGHT,CBM', 'loss' => 'nullable|in:ABSORB,WRITE_OFF']);
        if (! empty($d['final']) && ! \App\Services\Perm::has($r->user(), \App\Services\LandedVersions::FINAL_RIGHT)) return response()->json(['message' => 'Partner / Manager only'], 403);      // R-B_SOURCING-46 / 55
        return $this->run(function () use ($id, $d) { $x = Buy::landedCost($id, $d);
            return $x + ['message' => ! $x['applied'] ? "Version v{$x['version']} saved – it waits: {$x['waiting']}" : ($x['status'] === 'FINAL' ? "Landed cost v{$x['version']} is FINAL" : "Landed cost v{$x['version']} saved (provisional)")]; });
    }

    /** R-B_SOURCING-46 preview: nothing saved. */
    public function landedPreview(Request $r, int $id)
    {
        $this->can($r, 'buying.COST');
        $d = $r->validate(['freight' => 'nullable|numeric|min:0', 'duty' => 'nullable|numeric|min:0', 'other' => 'nullable|numeric|min:0', 'freight_method' => 'nullable|in:QUANTITY,VALUE,WEIGHT,CBM', 'duty_method' => 'nullable|in:QUANTITY,VALUE,WEIGHT,CBM', 'loss' => 'nullable|in:ABSORB,WRITE_OFF']);
        return $this->run(fn () => \App\Services\LandedVersions::preview($id, array_filter($d, fn ($v) => $v !== null)) + ['may_final' => \App\Services\Perm::has($r->user(), \App\Services\LandedVersions::FINAL_RIGHT)]);
    }

    /** R-B_SOURCING-43 / 45 / 60: the lines of a run (ten buckets, calc, override) and the versions of its cycle. */
    public function landedRun(Request $r, int $runId)
    {
        $this->can($r, 'buying.MONEY');
        $h = DB::table('t_src_landed_cost')->where('id', $runId)->where('fk_id_ent', Context::ent())->first() ?? abort(404);
        $lines = DB::table('t_src_landed_lines as l')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_landed', $runId)->orderBy('l.id')->get(['l.*', 'p.SKU_VENDOR as sku']);
        $versions = DB::table('t_src_landed_cost')->where('f_active', 1)->where(fn ($q) => $h->fk_id_cycle ? $q->where('fk_id_cycle', $h->fk_id_cycle) : $q->where('fk_id_po', $h->fk_id_po))->orderByDesc('id')->get(['id', 'lc_code', 'run_version', 'landed_status', 'total_cost', 'landed_cost_per_unit', 'created_at', 'warnings']);
        return ['run' => $h, 'lines' => $lines, 'versions' => $versions, 'buckets' => \App\Services\LandedEngine::BUCKETS, 'may_final' => \App\Services\Perm::has($r->user(), \App\Services\LandedVersions::FINAL_RIGHT), 'limit_pct' => \App\Services\LandedVersions::limitPct((int) Context::ent())];
    }

    /** R-B_SOURCING-45 override of one landed line (empty = back to calculated). */
    public function landedOverride(Request $r, int $lineId)
    {
        $this->can($r, 'buying.COST');
        $d = $r->validate(['unit' => 'nullable|numeric|min:0', 'reason' => 'nullable|string|max:255']);
        return $this->run(fn () => \App\Services\LandedVersions::override($lineId, isset($d['unit']) && $d['unit'] !== '' ? (float) $d['unit'] : null, (string) ($d['reason'] ?? '')));
    }

    public function fx(Request $r)
    {
        $this->can($r, 'buying.fx');
        if ($r->isMethod('get')) return ['rates' => DB::table('fin_fx_rates')->orderByDesc('rate_date')->orderBy('currency')->limit(200)->get(), 'base' => Fx::base(Context::ent())];
        $d = $r->validate(['currency' => 'required|string|size:3', 'date' => 'required|date', 'rate' => 'required|numeric|gt:0']);
        return $this->run(function () use ($d) { Fx::set($d['currency'], $d['date'], (float) $d['rate']); Audit::log('fin_fx_rates', 0, 'UPDATE', [$d['currency'] . ' ' . $d['date'] => [null, $d['rate']]]); return ['message' => 'Rate saved']; });
    }

    // ───────────── samples, QC, customs, quick buy (SourcingExtras) ─────────────
    public function extras(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        return \App\Services\SourcingExtras::page((int) Context::ent(), $id);
    }

    public function sample(Request $r)
    {
        $d = $r->validate(['id' => 'nullable|integer', 'cycle' => 'required|integer', 'cycle_product' => 'nullable|integer', 'sample_date' => 'required|date', 'sample_qty' => 'nullable|numeric|gt:0', 'sample_cost' => 'nullable|numeric|min:0', 'shipping_cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3', 'exchange_rate' => 'nullable|numeric|gt:0', 'tracking_number' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000']);
        return $this->run(fn () => ['id' => \App\Services\SourcingExtras::saveSample((int) Context::ent(), $d), 'message' => 'Sample saved']);
    }

    public function sampleStep(Request $r, int $id, string $step)
    {
        $ent = (int) Context::ent();
        return $this->run(function () use ($r, $id, $step, $ent) {
            if ($step === 'received') { \App\Services\SourcingExtras::sampleReceived($ent, $id, $r->validate(['date' => 'required|date'])['date']); return ['message' => 'Sample marked as received']; }
            if ($step === 'decide') { $d = $r->validate(['approve' => 'required|boolean', 'reason' => 'nullable|string|max:500']); \App\Services\SourcingExtras::decideSample($ent, $id, (bool) $d['approve'], $d['reason'] ?? null); return ['message' => $d['approve'] ? 'Sample approved' : 'Sample rejected']; }
            $d = $r->validate(['bank' => 'required|integer']); $x = \App\Services\SourcingExtras::bookSample($ent, $id, (int) $d['bank']);
            return $x + ['message' => 'Sample cost ' . number_format($x['total'], 2) . ' booked as expense, shared by ' . count($x['parts']) . ' shop(s)'];
        });
    }

    public function qcPhoto(Request $r)
    {
        $r->validate(['file' => 'required|file|max:8192']);
        return $this->run(fn () => ['id' => \App\Services\SourcingExtras::savePhoto((int) Context::ent(), $r->file('file')), 'message' => 'Photo added']);
    }

    public function qcPhotoShow(Request $r, int $id)
    {
        $this->can($r, 'buying.SEE');
        $doc = DB::table('sys_documents')->where('id', $id)->where('fk_id_ent', Context::ent())->where('tags', 'qc')->first() ?? abort(404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($doc->stored_name), 404);
        return response(\Illuminate\Support\Facades\Storage::disk('local')->get($doc->stored_name), 200, ['Content-Type' => $doc->mime ?: 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function qc(Request $r)
    {
        $d = $r->validate(['po' => 'required|integer', 'inspection_type' => 'nullable|string|max:40', 'inspector' => 'nullable|string|max:120', 'inspection_date' => 'required|date', 'qty_submitted' => 'nullable|numeric|min:0', 'qty_inspected' => 'required|numeric|min:0', 'qty_passed' => 'required|numeric|min:0',
            'qty_failed' => 'required|numeric|min:0', 'corrective_action' => 'nullable|string|max:2000', 'report_ref' => 'nullable|string|max:200', 're_inspection_of' => 'nullable|integer', 'override_pass' => 'nullable|boolean', 'override_reason' => 'nullable|string|max:500',
            'checks' => 'nullable|array', 'checks.*.text' => 'nullable|string|max:200', 'checks.*.result' => 'nullable|string|max:4', 'checks.*.note' => 'nullable|string|max:255', 'checks.*.photo' => 'nullable|integer', 'checks.*.product' => 'nullable|integer']);
        return $this->run(function () use ($d) { $x = \App\Services\SourcingExtras::saveQc((int) Context::ent(), $d); return $x + ['message' => "QC {$x['code']}: {$x['result']}"]; });
    }

    public function qcAction(Request $r, int $id)
    {
        $d = $r->validate(['action' => 'required|string|max:2000']);
        return $this->run(function () use ($id, $d) { \App\Services\SourcingExtras::qcAction((int) Context::ent(), $id, $d['action']); return ['message' => 'Corrective action saved']; });
    }

    public function customs(Request $r)
    {
        $d = $r->validate(['id' => 'nullable|integer', 'shipment' => 'required|integer', 'declaration_number' => 'nullable|string|max:120', 'hs_code' => 'nullable|string|max:40', 'importer_of_record' => 'nullable|string|max:200', 'customs_qty' => 'nullable|numeric|min:0', 'customs_value' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3', 'exchange_rate' => 'nullable|numeric|gt:0', 'duty_rate' => 'nullable|numeric|min:0|max:100', 'duty_amount' => 'nullable|numeric|min:0', 'import_vat' => 'nullable|numeric|min:0', 'clearance_date' => 'nullable|date', 'status' => 'nullable|string|max:20', 'product' => 'nullable|integer', 'broker' => 'nullable|integer']);
        return $this->run(fn () => ['id' => \App\Services\SourcingExtras::saveCustoms((int) Context::ent(), $d), 'message' => 'Customs entry saved']);
    }

    public function customsPay(Request $r, int $id)
    {
        $d = $r->validate(['bank' => 'required|integer', 'date' => 'required|date', 'duty' => 'nullable|numeric|min:0', 'import_vat' => 'nullable|numeric|min:0']);
        return $this->run(function () use ($id, $d) { $x = \App\Services\SourcingExtras::payCustoms((int) Context::ent(), $id, (int) $d['bank'], $d['date'], isset($d['duty']) ? (float) $d['duty'] : null, isset($d['import_vat']) ? (float) $d['import_vat'] : null);
            return $x + ['message' => 'Paid: duty ' . number_format($x['duty_base'], 2) . ' (into the goods\' cost) · import VAT ' . number_format($x['vat_base'], 2) . ' (to claim back)']; });
    }

    /** Add a FEE / COST / SAMPLE (or PRODUCT) line to a draft PO (R-B_SOURCING-6). */
    public function poLine(Request $r, int $id)
    {
        $this->can($r, 'buying.BUY');
        $d = $r->validate(['line_type' => 'required|string|max:20', 'name' => 'nullable|string|max:300', 'product' => 'nullable|integer', 'qty' => 'nullable|numeric|min:0', 'unit_price' => 'required|numeric|min:0']);
        return $this->run(function () use ($id, $d) {
            $po = DB::table('t_src_po')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('PO not found.');
            if ($po->status !== 'DRAFT') throw new \InvalidArgumentException('Lines can only be added while the PO is a draft.');
            $res = \App\Services\RecordService::save('t_src_po_lines', ['fk_id_po' => $id, 'fk_id_cycle' => $po->fk_id_cycle, 'line_type' => $d['line_type'], 'line_notes' => $d['name'] ?? null, 'fk_id_product' => $d['product'] ?? null,
                'sku' => ! empty($d['product']) ? DB::table('t_product_vendors')->where('id', $d['product'])->value('SKU_VENDOR') : null, 'qty_ordered' => $d['qty'] ?? null, 'qty_confirmed' => $d['qty'] ?? null, 'unit_price' => $d['unit_price']]);
            return ['id' => $res['id'], 'message' => 'Line added · PO total ' . number_format((float) DB::table('t_src_po')->where('id', $id)->value('po_total'), 2)];
        });
    }

    /** Live duplicate check supplier + order reference (R-B_SOURCING-7). */
    public function duplicateCheck(Request $r) { return \App\Services\SrcDuplicate::live((int) Context::ent(), $r->integer('supplier') ?: null, (string) $r->input('ref'), $r->integer('cycle') ?: null); }

    public function quickBuy(Request $r)
    {
        $d = $r->validate(['supplier' => 'required|integer', 'location' => 'required|integer', 'date' => 'required|date', 'currency' => 'nullable|string|size:3', 'bank' => 'nullable|integer', 'reference' => 'nullable|string|max:150', 'dup_reason' => 'nullable|string|max:300', 'note' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1', 'lines.*.product' => 'nullable|integer', 'lines.*.sku' => 'nullable|string|max:80', 'lines.*.qty' => 'required|integer|min:1', 'lines.*.unit_price' => 'required|numeric|min:0']);
        return $this->run(function () use ($d) { $x = \App\Services\SourcingExtras::quickBuy((int) Context::ent(), $d);
            $paid = $x['paid'] ? (collect($x['paid'])->contains(fn ($p) => $p['status'] === 'WAITING') ? ' · payment waits for approval' : ' · paid') : ' · not paid yet';
            return $x + ['message' => "Quick buy {$x['po_number']}: {$x['units']} unit(s) in stock$paid"]; });
    }
}
