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
    private const MONEY = ['OWNER', 'MANAGER', 'SOURCING', 'FINANCE', 'ACCOUNTANT', 'PARTNER'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function rights(Request $r): array
    {
        $role = $r->user()->role_code;
        return ['buy' => in_array($role, self::BUY, true), 'pay' => in_array($role, self::PAY, true), 'receive' => in_array($role, self::RECEIVE, true), 'cost' => in_array($role, self::COST, true),
            'go' => in_array($role, ['OWNER', 'MANAGER', 'PARTNER'], true), 'money' => in_array($role, self::MONEY, true)];
    }

    public function dashboard(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent();
        $cycles = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'CANCELLED'])->orderByDesc('id')->limit(30)->get(['id', 'cycle_code', 'product_name', 'cycle_type', 'sourcing_date']);
        $rows = [];
        foreach ($cycles as $c) { $w = Buy::cycle($c->id); $rows[] = ['id' => $c->id, 'code' => $c->cycle_code, 'name' => $c->product_name, 'type' => $c->cycle_type, 'date' => $c->sourcing_date, 'steps' => $w['steps']]; }
        $d = Buy::dashboard($ent);
        if (! in_array($r->user()->role_code, self::MONEY, true)) unset($d['pay_due_base'], $d['supplier_advances'], $d['supplier_payables']);
        return ['kpi' => $d, 'cycles' => $rows, 'rights' => $this->rights($r)];
    }

    public function pick(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = Context::ent();
        return ['banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', account_label) as name"), 'currency']),
            'locations' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('f_active', 1)->orWhereNull('f_active'))->whereNotIn('kind', ['DAMAGED', 'RETURNS', 'WRITTEN_OFF', 'QUARANTINE', 'MISSING', 'TRANSIT'])->orderBy('location_name')->limit(500)->get(['id', 'location_name as name']),
            'suppliers' => DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('supplier_name')->limit(500)->get(['id', 'supplier_name as name']),
            'products' => DB::table('t_product_vendors')->where('fk_id_ent', $ent)->orderBy('SKU_VENDOR')->limit(500)->get(['id', DB::raw("CONCAT(SKU_VENDOR, ' · ', product_name_vendor) as name")]),
            'base' => Fx::base($ent)];
    }

    // ---- research ----
    public function research(Request $r, int $id)
    {
        $this->can($r, self::SEE);
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
        $this->can($r, self::BUY);
        $d = $r->validate(['sell' => 'required|numeric|min:0', 'cost' => 'required|numeric|min:0', 'freight' => 'nullable|numeric|min:0', 'fee_pct' => 'nullable|numeric|min:0|max:100', 'ad_pct' => 'nullable|numeric|min:0|max:100', 'units' => 'nullable|integer|min:0']);
        $x = DB::table('src_research')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404);
        abort_if($x->status === 'GO', 422, 'This research already started sourcing.');
        DB::table('src_research')->where('id', $id)->update(['target_sell_price' => $d['sell'], 'est_unit_cost' => $d['cost'], 'est_freight_per_unit' => $d['freight'] ?? 0, 'est_fee_pct' => $d['fee_pct'] ?? 0, 'est_ad_pct' => $d['ad_pct'] ?? 0, 'est_monthly_units' => $d['units'] ?? $x->est_monthly_units]);
        Audit::log('src_research', $id, 'UPDATE', ['estimate' => [null, $d]]);
        return ['margin' => Buy::refreshMargin($id), 'message' => 'Estimate saved'];
    }

    public function researchChild(Request $r, int $id, string $kind)
    {
        $this->can($r, self::BUY);
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
        $this->can($r, self::BUY);
        $d = $r->validate(['status' => 'required|string', 'reason' => 'nullable|string|max:1000']);
        return $this->run(function () use ($d, $id) { Buy::researchStatus($id, $d['status'], $d['reason'] ?? null); return ['message' => 'Status: ' . $d['status']]; });
    }

    public function go(Request $r, int $id)
    {
        $d = $r->validate(['qty' => 'required|numeric|min:1', 'reason' => 'nullable|string|max:1000']);
        return $this->run(fn () => Buy::go($id, $d) + ['message' => 'Sourcing started: product, cycle and quotes are ready']);
    }

    // ---- cycle, RFQ, PO ----
    public function cycle(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $w = Buy::cycle($id);
        if (! in_array($r->user()->role_code, self::MONEY, true)) { foreach (['rfqs' => ['price'], 'pos' => ['po_total'], 'landed' => ['freight_cost', 'customs_duty', 'other_cost', 'total_cost', 'landed_cost_per_unit']] as $k => $cols) foreach ($w[$k] as $row) foreach ($cols as $c) unset($row->$c); $w['terms'] = []; }
        return $w + ['rights' => $this->rights($r)];
    }

    public function acceptRfq(Request $r, int $id)
    {
        $this->can($r, self::BUY);
        return $this->run(function () use ($id) { Buy::acceptRfq($id); return ['message' => 'Quote accepted']; });
    }

    public function createPo(Request $r)
    {
        $this->can($r, self::BUY);
        $d = $r->validate(['rfq' => 'nullable|integer', 'repeat_from' => 'nullable|integer', 'supplier' => 'nullable|integer', 'currency' => 'nullable|string|size:3', 'product' => 'nullable|integer', 'qty' => 'required|numeric|min:1', 'unit_price' => 'nullable|numeric|min:0', 'date' => 'nullable|date', 'deposit_pct' => 'nullable|numeric|min:0|max:100']);
        return $this->run(function () use ($d) {
            if (! empty($d['repeat_from'])) {      // direct PO: same supplier, last price, no research
                $old = DB::table('t_src_po')->where('id', $d['repeat_from'])->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('The PO to repeat was not found.');
                $lines = DB::table('t_src_po_lines')->where('fk_id_po', $old->id)->where('f_active', 1)->get()->map(fn ($l) => ['product' => $l->fk_id_product, 'qty' => $d['qty'], 'unit_price' => $l->unit_price])->all();
                $id = Buy::createPo(['supplier' => $old->fk_id_supplier, 'currency' => Fx::code($old->fk_id_currency, Context::ent()), 'date' => $d['date'] ?? null, 'lines' => $lines, 'deposit_pct' => $d['deposit_pct'] ?? $old->deposit_pct, 'repeat_from' => $old->id]);
            } elseif (! empty($d['rfq'])) {
                $id = Buy::createPo(array_filter(['rfq' => $d['rfq'], 'qty' => $d['qty'], 'date' => $d['date'] ?? null, 'deposit_pct' => $d['deposit_pct'] ?? null], fn ($v) => $v !== null));
            } else {
                if (empty($d['supplier']) || empty($d['product']) || ! isset($d['unit_price'])) throw new \InvalidArgumentException('Choose the supplier, the product and the price.');
                $id = Buy::createPo(['supplier' => $d['supplier'], 'currency' => $d['currency'] ?? Fx::base(Context::ent()), 'date' => $d['date'] ?? null, 'deposit_pct' => $d['deposit_pct'] ?? 30, 'lines' => [['product' => $d['product'], 'qty' => $d['qty'], 'unit_price' => $d['unit_price']]]]);
            }
            return ['id' => $id, 'message' => 'Purchase order made (draft)'];
        });
    }

    public function po(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $po = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.id', $id)->where('p.fk_id_ent', Context::ent())
            ->first(['p.id', 'p.po_number', 'p.po_date', 'p.status', 'p.po_total', 'p.exchange_rate', 'p.deposit_pct', 'p.fk_id_cycle', 'p.fk_id_supplier', 'p.f_paid_in_full', 's.supplier_name as supplier', 'c.currency_code as currency']) ?? abort(404);
        $money = in_array($r->user()->role_code, self::MONEY, true);
        $lines = DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_po', $id)->where('l.f_active', 1)
            ->get(['l.id', 'l.fk_id_product as product_id', 'l.sku', 'p.product_name_vendor as name', 'l.qty_confirmed as qty', 'l.unit_price', 'l.line_total',
                DB::raw('(SELECT COALESCE(SUM(k.actual_qty),0) FROM t_src_shipment_po_link k JOIN t_src_shipments s ON s.id=k.fk_id_shipment WHERE k.fk_id_po_line=l.id AND k.f_active=1 AND s.f_active=1) as shipped'),
                DB::raw('(SELECT COALESCE(SUM(g.received_qty),0) FROM t_src_grn_lines g JOIN t_src_grn h ON h.id=g.fk_id_grn WHERE g.fk_id_po_line=l.id AND h.posting_status="POSTED") as received')]);
        $ships = DB::table('t_src_shipments')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('id')->get(['id', 'shipment_code', 'planned_qty', 'etd', 'eta', 'actual_arrival', 'status']);
        $out = ['po' => $po, 'lines' => $lines, 'shipments' => $ships,
            'grns' => DB::table('t_src_grn')->whereIn('fk_id_shipment', $ships->pluck('id')->all() ?: [0])->where('f_active', 1)->orderBy('id')->get(['id', 'grn_number', 'fk_id_shipment', 'grn_date', 'units_received', 'damaged_units', 'missing_units', 'posting_status']),
            'lots' => DB::table('fin_lots')->where('po_id', $id)->get(['id', 'lot_code', 'sku', 'qty', 'qty_left', 'unit_cost_gbp', 'landed_status']), 'rights' => $this->rights($r), 'base' => Fx::base(Context::ent())];
        if ($money) {
            $out['terms'] = DB::table('t_src_payment_terms')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('term_no')->get(['id', 'milestone', 'pct', 'planned_amount', 'paid_amount', 'remaining_amount', 'due_date', 'status']);
            $out['payments'] = DB::table('t_src_payments')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('id')->get(['id', 'payment_code', 'payment_date', 'payment_amount', 'exchange_rate', 'base_amount', 'payment_type', 'status']);
            $out['landed'] = DB::table('t_src_landed_cost')->where('fk_id_po', $id)->where('f_active', 1)->orderByDesc('id')->get(['id', 'lc_code', 'run_version', 'freight_cost', 'customs_duty', 'other_cost', 'total_cost', 'landed_cost_per_unit', 'landed_status', 'superseded_by']);
        } else { foreach ($lines as $l) unset($l->unit_price, $l->line_total); unset($po->po_total, $po->exchange_rate); foreach ($out['lots'] as $l) unset($l->unit_cost_gbp); }
        return $out;
    }

    public function confirmPo(Request $r, int $id)
    {
        $this->can($r, self::BUY);
        return $this->run(fn () => Buy::confirmPo($id) + ['message' => 'PO confirmed: payment schedule made']);
    }

    public function pay(Request $r)
    {
        $this->can($r, self::PAY);
        $d = $r->validate(['term' => 'required|integer', 'amount' => 'required|numeric|gt:0', 'date' => 'required|date', 'bank' => 'required|integer', 'txn_ref' => 'nullable|string|max:255']);
        return $this->run(function () use ($d) { $x = Buy::payOrAsk($d); return $x + ['message' => $x['status'] === 'WAITING' ? 'Over the limit – the payment waits for approval and is not booked yet' : 'Payment posted to the ledger']; });
    }

    public function shipment(Request $r)
    {
        $this->can($r, self::BUY);
        $d = $r->validate(['po' => 'required|integer', 'eta' => 'required|date', 'etd' => 'nullable|date', 'mode' => 'nullable|string|max:60']);
        return $this->run(fn () => ['id' => Buy::createShipment($d), 'message' => 'Shipment saved']);
    }

    public function grn(Request $r)
    {
        $this->can($r, self::RECEIVE);
        $d = $r->validate(['shipment' => 'required|integer', 'location' => 'required|integer', 'date' => 'required|date', 'lines' => 'required|array|min:1', 'lines.*.po_line' => 'required|integer', 'lines.*.received' => 'required|integer|min:0', 'lines.*.damaged' => 'nullable|integer|min:0', 'lines.*.location' => 'nullable|integer']);
        return $this->run(fn () => ['id' => Buy::createGrn($d), 'message' => 'GRN saved as draft – check it and post']);
    }

    public function shipmentLines(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        abort_unless(DB::table('t_src_shipments')->where('id', $id)->where('fk_id_ent', Context::ent())->exists(), 404);
        return ['lines' => DB::table('t_src_shipment_po_link as k')->leftJoin('t_product_vendors as p', 'p.id', '=', 'k.fk_id_product')->where('k.fk_id_shipment', $id)->where('k.f_active', 1)->get(['k.fk_id_po_line as po_line', 'k.fk_id_product as product', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'k.actual_qty as qty'])
            ->map(function ($l) { $l->suggest = $l->product ? (\App\Services\SkuHomes::suggest((int) Context::ent(), (int) $l->product, (int) $l->qty)[0] ?? null) : null; return $l; })];
    }

    public function postGrn(Request $r, int $id)
    {
        $this->can($r, self::RECEIVE);
        return $this->run(fn () => Buy::postGrn($id) + ['message' => 'GRN posted: the units are in stock']);
    }

    public function landed(Request $r, int $id)
    {
        $this->can($r, self::COST);
        $d = $r->validate(['freight' => 'nullable|numeric|min:0', 'duty' => 'nullable|numeric|min:0', 'other' => 'nullable|numeric|min:0', 'final' => 'boolean']);
        return $this->run(fn () => Buy::landedCost($id, $d) + ['message' => ! empty($d['final']) ? 'Landed cost is FINAL' : 'Landed cost saved (provisional)']);
    }

    public function fx(Request $r)
    {
        $this->can($r, ['OWNER', 'FINANCE', 'ACCOUNTANT']);
        if ($r->isMethod('get')) return ['rates' => DB::table('fin_fx_rates')->orderByDesc('rate_date')->orderBy('currency')->limit(200)->get(), 'base' => Fx::base(Context::ent())];
        $d = $r->validate(['currency' => 'required|string|size:3', 'date' => 'required|date', 'rate' => 'required|numeric|gt:0']);
        return $this->run(function () use ($d) { Fx::set($d['currency'], $d['date'], (float) $d['rate']); Audit::log('fin_fx_rates', 0, 'UPDATE', [$d['currency'] . ' ' . $d['date'] => [null, $d['rate']]]); return ['message' => 'Rate saved']; });
    }

    // ───────────── samples, QC, customs, quick buy (SourcingExtras) ─────────────
    public function extras(Request $r, int $id)
    {
        $this->can($r, self::SEE);
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
        $this->can($r, self::SEE);
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

    public function quickBuy(Request $r)
    {
        $d = $r->validate(['supplier' => 'required|integer', 'location' => 'required|integer', 'date' => 'required|date', 'currency' => 'nullable|string|size:3', 'bank' => 'nullable|integer', 'reference' => 'nullable|string|max:150', 'note' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1', 'lines.*.product' => 'nullable|integer', 'lines.*.sku' => 'nullable|string|max:80', 'lines.*.qty' => 'required|integer|min:1', 'lines.*.unit_price' => 'required|numeric|min:0']);
        return $this->run(function () use ($d) { $x = \App\Services\SourcingExtras::quickBuy((int) Context::ent(), $d);
            $paid = $x['paid'] ? (collect($x['paid'])->contains(fn ($p) => $p['status'] === 'WAITING') ? ' · payment waits for approval' : ' · paid') : ' · not paid yet';
            return $x + ['message' => "Quick buy {$x['po_number']}: {$x['units']} unit(s) in stock$paid"]; });
    }
}
