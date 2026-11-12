<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Buying: research → GO → cycle → RFQ → PO → payments → shipment → GRN → landed cost (RULES_B_SOURCING).
 * Money rules: a supplier payment is an advance (1300) until goods arrive; GRN post puts the goods into stock (1200)
 * against the supplier payable (2000) and then uses the advances; landed cost adds freight and duty to the lot cost.
 */
class BuyingService
{
    public const RESEARCH_FLOW = ['IDEA', 'RESEARCHING', 'SHORTLIST', 'GO', 'NO_GO', 'PARKED'];

    // ---------------------------------------------------------------- research

    /** R-B_SOURCING-68: margin % = (sell − cost × FX − freight − sell × (fee % + ad %)) ÷ sell × 100. */
    public static function margin(float $sell, float $cost, float $freight, float $feePct, float $adPct, float $fx = 1.0): ?float
    {
        if ($sell <= 0) return null;
        return round(($sell - $cost * $fx - $freight - $sell * ($feePct + $adPct) / 100) / $sell * 100, 2);
    }

    public static function refreshMargin(int $researchId): ?float
    {
        $r = DB::table('src_research')->where('id', $researchId)->first() ?? throw new \RuntimeException('Research not found.');
        $fx = $r->currency && $r->currency !== Fx::base((int) $r->fk_id_ent) ? Fx::rate($r->currency, date('Y-m-d'), (int) $r->fk_id_ent) : 1.0;
        $m = self::margin((float) $r->target_sell_price, (float) $r->est_unit_cost, (float) $r->est_freight_per_unit, (float) $r->est_fee_pct, (float) $r->est_ad_pct, $fx);
        DB::table('src_research')->where('id', $researchId)->update(['est_margin_pct' => $m]);
        return $m;
    }

    public static function researchStatus(int $researchId, string $status, ?string $reason = null): void
    {
        if (! in_array($status, self::RESEARCH_FLOW, true) || $status === 'GO') throw new \InvalidArgumentException('Use GO – start sourcing for the GO decision.');
        if (in_array($status, ['NO_GO', 'PARKED'], true) && trim((string) $reason) === '') throw new \InvalidArgumentException('Give the reason for this decision.');
        $r = self::research($researchId);
        if ($r->status === 'GO') throw new \RuntimeException('This research already started sourcing; its status cannot change.');
        DB::table('src_research')->where('id', $researchId)->update(['status' => $status, 'decision_reason' => $reason ?? $r->decision_reason] + (in_array($status, ['NO_GO', 'PARKED'], true) ? ['decided_by' => Context::userId(), 'decided_at' => now()] : []));
        Audit::log('src_research', $researchId, 'UPDATE', ['status' => [$r->status, $status]], $reason, (int) $r->fk_id_ent);
        EventBus::fire('RESEARCH_SAVED', 'src_research', $researchId, ['status' => $status], (int) $r->fk_id_ent);
        TaskEngine::close('RES_STALE', [$researchId], 'Status changed');
    }

    /**
     * R-B_SOURCING-69: GO starts sourcing without typing twice – one transaction: draft product, cycle, one RFQ per supplier candidate.
     * $opt: qty, cycle_type (FULL / QUICK), reason (needed when the margin is under the minimum or a risk flag is set), product (link an existing one).
     */
    public static function go(int $researchId, array $opt = []): array
    {
        return DB::transaction(function () use ($researchId, $opt) {
            $r = DB::table('src_research')->where('id', $researchId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Research not found.');
            $ent = (int) $r->fk_id_ent;
            if ($r->status === 'GO' || $r->fk_id_cycle) throw new \RuntimeException('This research already started a cycle. For a repeat order use Direct PO.');
            if (! in_array(Context::role(), ['OWNER', 'MANAGER', 'PARTNER'], true)) throw new \RuntimeException('GO needs a Manager, Partner or Owner.');
            $margin = self::refreshMargin($researchId); $min = (float) Settings::get('RESEARCH_MIN_MARGIN', $ent, 25);
            if ((($margin !== null && $margin < $min) || trim((string) $r->risk_flags) !== '') && trim((string) ($opt['reason'] ?? '')) === '') {
                throw new \InvalidArgumentException($margin !== null && $margin < $min ? "The estimated margin ($margin %) is under the minimum ($min %). Write why it is still a GO." : 'A risk flag is set. Write why it is still a GO.');
            }
            $qty = (float) ($opt['qty'] ?? $r->est_monthly_units ?? 0);
            $productId = $opt['product'] ?? null;
            if (! $productId) {
                $productId = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => $ent, 'product_name_vendor' => $r->title, 'SKU_VENDOR' => 'DRAFT-' . $r->research_code,
                    'fk_id_brand' => $r->fk_id_brand, 'f_active' => 0, 'stock' => 0, 'd_created' => date('Y-m-d'), 'created_by' => Context::userId(), 'e_product_type' => 'pool']));
                Audit::log('t_product_vendors', $productId, 'INSERT', ['from_research' => $r->research_code], null, $ent);
                SearchIndex::put((string) RecordService::formOfTable('t_product_vendors'), 't_product_vendors', $productId, $ent);
            }
            $cycleId = DB::table('t_src_cycles')->insertGetId(['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('CYC', $ent), 'sourcing_date' => date('Y-m-d'), 'requested_by' => Context::userId(), 'brand' => $r->fk_id_brand,
                'product_name' => $r->title, 'target_qty' => $qty, 'target_unit_cost' => $r->est_unit_cost, 'priority' => 'NORMAL', 'status' => 'OPEN', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(),
                'cycle_type' => $opt['cycle_type'] ?? 'FULL', 'fk_id_owner' => $r->fk_id_owner, 'fk_id_research' => $researchId]);
            DB::table('t_src_cycle_products')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycleId, 'fk_id_brand' => $r->fk_id_brand, 'fk_id_product' => $productId, 'product_name' => $r->title, 'target_qty' => $qty,
                'target_unit_cost' => $r->est_unit_cost, 'row_status' => 'ACTIVE', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
            $rfqs = [];
            foreach (DB::table('src_research_suppliers')->where('fk_id_research', $researchId)->orderBy('id')->get() as $c) {
                $supplierId = $c->fk_id_supplier ?: DB::table('t_src_suppliers')->insertGetId(['fk_id_ent' => $ent, 'supplier_code' => substr(Numbering::next('SUP', $ent), 0, 30), 'supplier_name' => $c->supplier_name_text ?: 'New supplier', 'website' => $c->source_url ? mb_substr($c->source_url, 0, 300) : null,
                    'moq' => $c->moq, 'production_lead_days' => $c->lead_days, 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
                if (! $c->fk_id_supplier) DB::table('src_research_suppliers')->where('id', $c->id)->update(['fk_id_supplier' => $supplierId]);
                $rfqs[] = DB::table('t_src_rfq')->insertGetId(['fk_id_ent' => $ent, 'rfq_code' => Numbering::next('RFQ', $ent), 'rfq_date' => date('Y-m-d'), 'fk_id_cycle' => $cycleId, 'fk_id_supplier' => $supplierId, 'qty' => $qty, 'moq' => $c->moq,
                    'quoted_unit_price' => $c->unit_price, 'final_unit_price' => $c->unit_price, 'fk_id_currency' => self::currencyId($c->currency), 'lead_time_days' => $c->lead_days, 'status' => 'QUOTED', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
            }
            DB::table('src_research')->where('id', $researchId)->update(['status' => 'GO', 'fk_id_product' => $productId, 'fk_id_cycle' => $cycleId, 'decision_reason' => $opt['reason'] ?? $r->decision_reason, 'decided_by' => Context::userId(), 'decided_at' => now()]);
            Audit::log('src_research', $researchId, 'UPDATE', ['status' => [$r->status, 'GO'], 'cycle' => $cycleId], $opt['reason'] ?? null, $ent);
            TaskEngine::close('RES_STALE', [$researchId], 'GO');
            EventBus::fire('RESEARCH_GO', 'src_research', $researchId, ['product' => $productId, 'cycle' => $cycleId, 'rfqs' => $rfqs], $ent);
            return ['product' => (int) $productId, 'cycle' => (int) $cycleId, 'rfqs' => $rfqs];
        });
    }

    /** RES_STALE: research that has not moved for RESEARCH_STALE_DAYS (not GO / NO_GO / PARKED). */
    public static function staleResearch(int $ent): array
    {
        $days = (int) Settings::get('RESEARCH_STALE_DAYS', $ent, 14);
        $rows = DB::table('src_research as r')->where('r.fk_id_ent', $ent)->whereIn('r.status', ['IDEA', 'RESEARCHING', 'SHORTLIST'])->where(fn ($w) => $w->whereNull('r.f_deleted')->orWhere('r.f_deleted', 0))
            ->selectRaw("r.id, r.title, r.research_code, r.created_by, COALESCE((SELECT MAX(a.created_at) FROM sys_audit_log a WHERE a.table_name='src_research' AND a.record_id=r.id), r.created_at) last_change")
            ->havingRaw('last_change < ?', [now()->subDays($days)])->get();
        return TaskEngine::sync('RES_STALE', $rows->map(fn ($r) => ['key' => [$r->id], 'title' => "Research not moved for $days days – decide or park: {$r->title}", 'opt' => ['module' => 'src_research', 'record_id' => $r->id, 'owner_user_id' => $r->created_by ?: null]])->all(), null, $ent);
    }

    // ---------------------------------------------------------------- RFQ and PO

    public static function acceptRfq(int $rfqId): void
    {
        $q = DB::table('t_src_rfq')->where('id', $rfqId)->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('Quote not found.');
        if ($q->d_valid_until && $q->d_valid_until < date('Y-m-d')) throw new \RuntimeException("This quote was valid until {$q->d_valid_until}. Ask the supplier for a new one.");
        DB::table('t_src_rfq')->where('fk_id_cycle', $q->fk_id_cycle)->where('status', 'ACCEPTED')->update(['status' => 'QUOTED']);
        DB::table('t_src_rfq')->where('id', $rfqId)->update(['status' => 'ACCEPTED', 'updated_by' => Context::userId(), 'updated_at' => now()]);
        Audit::log('t_src_rfq', $rfqId, 'UPDATE', ['status' => [$q->status, 'ACCEPTED']], null, (int) $q->fk_id_ent);
        EventBus::fire('STEP_DONE', 't_src_cycles', (int) $q->fk_id_cycle, ['step' => 'RFQ', 'rfq' => $rfqId], (int) $q->fk_id_ent);
    }

    /**
     * Make a draft PO. $d: cycle, supplier, currency (code), date, lines [{product, qty, unit_price, other_charges?}], deposit_pct?, fk_id_payment_term?.
     * With 'rfq' the supplier, currency and price come from the accepted quote and the cycle's product.
     */
    public static function createPo(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent();
            if (! empty($d['rfq'])) {
                $q = DB::table('t_src_rfq')->where('id', $d['rfq'])->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('Quote not found.');
                if ($q->status !== 'ACCEPTED') throw new \RuntimeException('Accept the quote first.');
                $cp = DB::table('t_src_cycle_products')->where('fk_id_cycle', $q->fk_id_cycle)->where('f_active', 1)->first();
                $d += ['cycle' => $q->fk_id_cycle, 'supplier' => $q->fk_id_supplier, 'currency' => Fx::code($q->fk_id_currency, $ent),
                    'lines' => [['product' => $cp->fk_id_product, 'qty' => $d['qty'] ?? $q->qty, 'unit_price' => $q->final_unit_price ?? $q->quoted_unit_price]]];
            }
            if (empty($d['lines'])) throw new \InvalidArgumentException('A purchase order needs at least one line.');
            $cur = strtoupper($d['currency'] ?? Fx::base($ent)); $date = $d['date'] ?? date('Y-m-d');
            $po = DB::table('t_src_po')->insertGetId(['fk_id_ent' => $ent, 'po_code' => ($no = Numbering::next('PO', $ent, $date)), 'po_number' => $no, 'po_date' => $date, 'fk_id_cycle' => $d['cycle'] ?? null, 'fk_id_supplier' => $d['supplier'],
                'fk_id_currency' => self::currencyId($cur), 'fk_id_payment_term' => $d['fk_id_payment_term'] ?? null, 'requested_by' => Context::userId(), 'status' => 'DRAFT', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(),
                'deposit_pct' => $d['deposit_pct'] ?? 30, 'po_total' => 0, 'fk_id_repeat_from_po' => $d['repeat_from'] ?? null]);
            $total = 0.0;
            foreach ($d['lines'] as $l) {
                if ((float) $l['qty'] <= 0 || (float) $l['unit_price'] < 0) throw new \InvalidArgumentException('Each line needs a quantity above zero and a price.');
                $p = DB::table('t_product_vendors')->where('id', $l['product'])->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('A PO line needs a product of this company.');
                $lt = round((float) $l['qty'] * (float) $l['unit_price'] + (float) ($l['other_charges'] ?? 0), 2);
                DB::table('t_src_po_lines')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $d['cycle'] ?? null, 'fk_id_po' => $po, 'sku' => $p->SKU_VENDOR, 'qty_ordered' => $l['qty'], 'qty_confirmed' => $l['qty'], 'unit_price' => $l['unit_price'],
                    'other_charges' => $l['other_charges'] ?? 0, 'line_total' => $lt, 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'line_type' => 'PRODUCT', 'fk_id_product' => $p->id]);
                $total += $lt;
            }
            DB::table('t_src_po')->where('id', $po)->update(['po_total' => round($total, 2)]);
            Audit::log('t_src_po', $po, 'INSERT', ['po_number' => $no, 'total' => round($total, 2), 'currency' => $cur], null, $ent);
            SearchIndex::put((string) RecordService::formOfTable('t_src_po'), 't_src_po', $po, $ent);
            return (int) $po;
        });
    }

    /** Confirm: the FX rate of the PO date is kept on the PO, the payment schedule is made (deposit % now, balance before shipping). */
    public static function confirmPo(int $poId): array
    {
        return DB::transaction(function () use ($poId) {
            $po = self::po($poId, true);
            if ($po->status !== 'DRAFT') throw new \RuntimeException("This PO is {$po->status}; only a draft can be confirmed.");
            $ent = (int) $po->fk_id_ent; $cur = Fx::code($po->fk_id_currency, $ent);
            $rate = Fx::rate($cur, $po->po_date, $ent);
            $dep = max(0, min(100, (float) ($po->deposit_pct ?? 30)));
            $depAmt = round($po->po_total * $dep / 100, 2); $balAmt = round($po->po_total - $depAmt, 2);
            $terms = [];
            foreach (array_filter([[1, 'Deposit', $dep, $depAmt, $po->po_date, 'ON_CONFIRM'], [2, 'Balance', 100 - $dep, $balAmt, $po->required_ship_date ?: date('Y-m-d', strtotime($po->po_date . ' +30 days')), 'BEFORE_SHIP']], fn ($t) => $t[3] > 0) as [$no, $ms, $pct, $amt, $due, $rule]) {
                $terms[] = DB::table('t_src_payment_terms')->insertGetId(['fk_id_ent' => $ent, 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $poId, 'term_no' => $no, 'milestone' => $ms, 'pct' => $pct, 'planned_amount' => $amt, 'paid_amount' => 0,
                    'remaining_amount' => $amt, 'completion_pct' => 0, 'status' => 'DUE', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'due_date' => $due, 'due_rule' => $rule]);
            }
            DB::table('t_src_po')->where('id', $poId)->update(['status' => 'CONFIRMED', 'exchange_rate' => $rate, 'deposit_amount' => $depAmt, 'balance_amount' => $balAmt, 'approved_by' => (string) Context::userId(), 'approval_date' => date('Y-m-d')]);
            Audit::log('t_src_po', $poId, 'UPDATE', ['status' => ['DRAFT', 'CONFIRMED'], 'fx_rate' => $rate], null, $ent);
            EventBus::fire('PO_CONFIRMED', 't_src_po', $poId, ['total' => (float) $po->po_total, 'currency' => $cur, 'terms' => $terms], $ent);
            self::payDueTasks($ent);
            return ['terms' => $terms, 'fx_rate' => $rate];
        });
    }

    /** SRC_PAY_DUE: a task per payment term that is due within SRC_PAY_REMIND_DAYS and not fully paid; closes when the term is Paid. */
    public static function payDueTasks(int $ent): array
    {
        $days = (int) Settings::get('SRC_PAY_REMIND_DAYS', $ent, 3);
        $rows = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.status', '<>', 'PAID')->where('p.status', '<>', 'CANCELLED')
            ->where('t.due_date', '<=', now()->addDays($days)->toDateString())->get(['t.id', 't.milestone', 't.remaining_amount', 't.due_date', 'p.po_number', 'p.id as po_id', 'p.exchange_rate']);
        return TaskEngine::sync('SRC_PAY_DUE', $rows->map(fn ($t) => ['key' => [$t->id], 'title' => "Supplier payment due {$t->due_date}: {$t->milestone} of {$t->po_number}", 'opt' => ['module' => 't_src_po', 'record_id' => $t->po_id,
            'amount' => round($t->remaining_amount * (float) ($t->exchange_rate ?: 1), 2), 'fix_url' => "#/po/{$t->po_id}"]])->all(), null, $ent);
    }

    // ---------------------------------------------------------------- payments

    /**
     * Pay a supplier against a PO term. $d: term, amount (PO currency), date, bank (fin_bank_accounts id), method?, txn_ref?.
     * Ledger: supplier advance (1300) up, bank (1000) down, in base currency at the rate of the payment date.
     */
    public static function pay(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent();
            $t = DB::table('t_src_payment_terms')->where('id', $d['term'])->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \RuntimeException('Payment term not found.');
            $po = self::po((int) $t->fk_id_po);
            if (! in_array($po->status, ['CONFIRMED', 'SHIPPED', 'RECEIVED'], true)) throw new \RuntimeException('Confirm the PO before paying.');
            $amt = round((float) $d['amount'], 2); $tol = (float) Settings::get('SRC_MONEY_TOL', $ent, 0.009);
            if ($amt <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
            if ($amt > $t->remaining_amount + $tol) throw new \RuntimeException('This is more than the ' . number_format($t->remaining_amount, 2) . ' still open on this term.');
            $bank = DB::table('fin_bank_accounts')->where('id', $d['bank'])->where('company_id', $ent)->where('is_active', 1)->first() ?? throw new \RuntimeException('Choose a bank account of this company.');
            $cur = Fx::code($po->fk_id_currency, $ent); $date = $d['date'] ?? date('Y-m-d');
            $rate = Fx::rate($cur, $date, $ent); $base = round($amt * $rate, 2);
            $pid = DB::table('t_src_payments')->insertGetId(['fk_id_ent' => $ent, 'payment_code' => Numbering::next('PAY', $ent, $date), 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $po->id, 'fk_id_supplier' => $po->fk_id_supplier, 'fk_id_term' => $t->id,
                'payment_type' => $t->milestone, 'payment_method' => $d['method'] ?? 'BANK', 'payment_amount' => $amt, 'fk_id_currency' => $po->fk_id_currency, 'exchange_rate' => $rate, 'base_amount' => $base, 'payment_date' => $date,
                'status' => 'PAID', 'txn_ref' => $d['txn_ref'] ?? null, 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'paid_via' => 'BANK', 'notes' => $d['notes'] ?? null]);
            $paid = round($t->paid_amount + $amt, 2); $rem = round($t->planned_amount - $paid, 2);
            DB::table('t_src_payment_terms')->where('id', $t->id)->update(['paid_amount' => $paid, 'remaining_amount' => max(0, $rem), 'completion_pct' => $t->planned_amount > 0 ? round($paid / $t->planned_amount * 100, 2) : 100, 'status' => $rem <= $tol ? 'PAID' : 'PART_PAID']);
            Ledger::post($ent, $date, 'PAYMENT', $pid, [
                ['account' => '1300', 'debit' => $base, 'ref_id' => $po->fk_id_supplier, 'amount_cur' => $amt],
                ['account' => '1000', 'credit' => $base, 'ref_id' => $bank->id, 'amount_cur' => -$amt],
            ], ['memo' => "Supplier payment {$po->po_number} · {$t->milestone}", 'currency' => $cur, 'fx_rate' => $rate]);
            if (! DB::table('t_src_payment_terms')->where('fk_id_po', $po->id)->where('f_active', 1)->where('status', '<>', 'PAID')->exists()) DB::table('t_src_po')->where('id', $po->id)->update(['f_paid_in_full' => 1]);
            if ($rem <= $tol) TaskEngine::close('SRC_PAY_DUE', [$t->id], 'Term paid');
            self::applyAdvances($ent, (int) $po->fk_id_supplier, $date);
            Audit::log('t_src_payments', $pid, 'INSERT', ['amount' => $amt, 'currency' => $cur, 'base' => $base], null, $ent);
            EventBus::fire('PAYMENT_POSTED', 't_src_payments', $pid, ['po' => $po->id, 'term' => $t->id, 'base' => $base], $ent);
            return (int) $pid;
        });
    }

    /** Use the advances paid to a supplier against what is owed to him for goods received. */
    public static function applyAdvances(int $ent, int $supplierId, ?string $date = null): float
    {
        $adv = Ledger::balance($ent, '1300', 'PARTY', $supplierId);          // + = we paid ahead
        $owed = -Ledger::balance($ent, '2000', 'PARTY', $supplierId);        // + = we owe for goods
        $use = round(min($adv, $owed), 2);
        if ($use <= 0) return 0.0;
        Ledger::post($ent, $date ?? date('Y-m-d'), 'ADVANCE_APPLIED', $supplierId, [['account' => '2000', 'debit' => $use, 'ref_id' => $supplierId], ['account' => '1300', 'credit' => $use, 'ref_id' => $supplierId]], ['memo' => 'Advance used against goods received']);
        return $use;
    }

    // ---------------------------------------------------------------- shipment and GRN

    /** $d: po, eta, etd?, mode?, lines? [{po_line, qty}] (default: everything still to ship). */
    public static function createShipment(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $po = self::po((int) $d['po']); $ent = (int) $po->fk_id_ent;
            if (! in_array($po->status, ['CONFIRMED', 'SHIPPED'], true)) throw new \RuntimeException('Confirm the PO before shipping.');
            $lines = $d['lines'] ?? DB::table('t_src_po_lines')->where('fk_id_po', $po->id)->where('f_active', 1)->get()->map(fn ($l) => ['po_line' => $l->id, 'qty' => $l->qty_confirmed - self::shippedQty($l->id)])->filter(fn ($l) => $l['qty'] > 0)->values()->all();
            if (! $lines) throw new \RuntimeException('Everything on this PO is already on a shipment.');
            $sid = DB::table('t_src_shipments')->insertGetId(['fk_id_ent' => $ent, 'shipment_code' => Numbering::next('SHP', $ent), 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $po->id, 'fk_id_supplier' => $po->fk_id_supplier,
                'planned_qty' => array_sum(array_column($lines, 'qty')), 'shipping_mode' => $d['mode'] ?? null, 'etd' => $d['etd'] ?? null, 'eta' => $d['eta'] ?? null, 'status' => 'IN_TRANSIT', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'fk_id_forwarder' => $d['forwarder'] ?? null]);
            foreach ($lines as $l) {
                $pl = DB::table('t_src_po_lines')->where('id', $l['po_line'])->where('fk_id_po', $po->id)->first() ?? throw new \RuntimeException('A shipment line must belong to this PO.');
                if ($l['qty'] > $pl->qty_confirmed - self::shippedQty($pl->id) + 0.0001) throw new \RuntimeException("Line {$pl->sku}: more than is left to ship.");
                DB::table('t_src_shipment_po_link')->insert(['fk_id_shipment' => $sid, 'fk_id_po' => $po->id, 'planned_qty' => $l['qty'], 'actual_qty' => $l['qty'], 'f_active' => 1, 'created_at' => now(), 'fk_id_po_line' => $pl->id, 'fk_id_product' => $pl->fk_id_product]);
            }
            DB::table('t_src_po')->where('id', $po->id)->update(['status' => 'SHIPPED']);
            EventBus::fire('SHIPMENT_SAVED', 't_src_shipments', $sid, ['po' => $po->id, 'eta' => $d['eta'] ?? null], $ent);
            return (int) $sid;
        });
    }

    /** SRC_SHIP_ARRIVING / SRC_SHIP_OVERDUE from ETA and arrival. */
    public static function shipmentTasks(int $ent): array
    {
        $soon = now()->addDays((int) Settings::get('SRC_SHIP_ARRIVING_DAYS', $ent, 3))->toDateString(); $today = date('Y-m-d');
        $open = DB::table('t_src_shipments')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('actual_arrival')->whereNotNull('eta')->get(['id', 'shipment_code', 'eta']);
        $a = TaskEngine::sync('SRC_SHIP_ARRIVING', $open->filter(fn ($s) => $s->eta >= $today && $s->eta <= $soon)->map(fn ($s) => ['key' => [$s->id], 'title' => "Shipment {$s->shipment_code} arrives {$s->eta} – get the warehouse ready", 'opt' => ['module' => 't_src_shipments', 'record_id' => $s->id]])->values()->all(), null, $ent);
        $o = TaskEngine::sync('SRC_SHIP_OVERDUE', $open->filter(fn ($s) => $s->eta < $today)->map(fn ($s) => ['key' => [$s->id], 'title' => "Shipment {$s->shipment_code} is overdue (ETA {$s->eta}) – chase the forwarder", 'opt' => ['module' => 't_src_shipments', 'record_id' => $s->id]])->values()->all(), null, $ent);
        return ['arriving' => $a, 'overdue' => $o];
    }

    /** Draft GRN for a shipment. $d: shipment, location, date?, lines [{po_line, received, damaged?, missing?}] (default: everything planned, nothing damaged). */
    public static function createGrn(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $s = DB::table('t_src_shipments')->where('id', $d['shipment'])->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('Shipment not found.');
            $ent = (int) $s->fk_id_ent; $date = $d['date'] ?? date('Y-m-d');
            $links = DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $s->id)->where('f_active', 1)->get()->keyBy('fk_id_po_line');
            $in = collect($d['lines'] ?? $links->map(fn ($l) => ['po_line' => $l->fk_id_po_line, 'received' => $l->actual_qty])->values());
            $gid = DB::table('t_src_grn')->insertGetId(['fk_id_ent' => $ent, 'grn_code' => ($no = Numbering::next('GRN', $ent, $date)), 'grn_number' => $no, 'fk_id_cycle' => $s->fk_id_cycle, 'fk_id_shipment' => $s->id, 'fk_id_warehouse' => $d['location'],
                'arrival_date' => $date, 'grn_date' => $date, 'received_by' => (string) Context::userId(), 'posting_status' => 'DRAFT', 'status' => 'DRAFT', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'grn_source' => 'PO']);
            $exp = 0; $rec = 0; $dmg = 0; $mis = 0;
            foreach ($in as $l) {
                $link = $links[$l['po_line']] ?? throw new \RuntimeException('A GRN line must be on this shipment.');
                $r = (float) $l['received']; $dg = (float) ($l['damaged'] ?? 0); $ms = (float) ($l['missing'] ?? max(0, $link->actual_qty - $r));
                if ($r < 0 || $dg < 0 || $dg > $r) throw new \InvalidArgumentException('Received and damaged quantities do not fit.');
                DB::table('t_src_grn_lines')->insert(['fk_id_grn' => $gid, 'fk_id_po_line' => $l['po_line'], 'fk_id_product' => $link->fk_id_product, 'sku' => DB::table('t_product_vendors')->where('id', $link->fk_id_product)->value('SKU_VENDOR'),
                    'expected_qty' => $link->actual_qty, 'received_qty' => $r, 'accepted_qty' => $r - $dg, 'damaged_qty' => $dg, 'missing_qty' => $ms, 'fk_id_location' => $d['location'], 'created_by' => Context::userId(), 'created_at' => now()]);
                $exp += $link->actual_qty; $rec += $r; $dmg += $dg; $mis += $ms;
            }
            DB::table('t_src_grn')->where('id', $gid)->update(['expected_units' => $exp, 'units_received' => $rec, 'damaged_units' => $dmg, 'missing_units' => $mis, 'shortage_qty' => $mis, 'damage_pct' => $rec > 0 ? round($dmg / $rec * 100, 2) : 0]);
            return (int) $gid;
        });
    }

    /**
     * Post a GRN (once): each line becomes a lot through the stock service at provisional cost (PO unit price × the PO rate);
     * the goods value is owed to the supplier (2000) and advances already paid are used. Short or damaged → claim task.
     */
    public static function postGrn(int $grnId): array
    {
        return DB::transaction(function () use ($grnId) {
            $g = DB::table('t_src_grn')->where('id', $grnId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('GRN not found.');
            if ($g->posting_status === 'POSTED') throw new \RuntimeException("GRN {$g->grn_number} is already posted. Stock is never received twice.");
            $ent = (int) $g->fk_id_ent;
            $ship = DB::table('t_src_shipments')->where('id', $g->fk_id_shipment)->first();
            $po = self::po((int) $ship->fk_id_po);
            $rate = (float) ($po->exchange_rate ?: Fx::rate(Fx::code($po->fk_id_currency, $ent), $po->po_date, $ent));
            $lines = []; $owner = DB::table('t_src_cycles')->where('id', $g->fk_id_cycle)->value('fk_id_owner');
            foreach (DB::table('t_src_grn_lines as l')->leftJoin('t_src_po_lines as p', 'p.id', '=', 'l.fk_id_po_line')->where('l.fk_id_grn', $grnId)->get(['l.*', 'p.unit_price']) as $l) {
                if ($l->received_qty <= 0) continue;
                $lines[] = ['product' => (int) $l->fk_id_product, 'qty' => (int) $l->received_qty, 'damaged_qty' => (int) $l->damaged_qty, 'location' => (int) $l->fk_id_location, 'unit_cost' => round((float) $l->unit_price * $rate, 4),
                    'received_date' => $g->grn_date, 'owner' => $owner ?: null, 'po_id' => $po->id, 'cycle_id' => $g->fk_id_cycle];
                DB::table('t_product_vendors')->where('id', $l->fk_id_product)->where('f_active', 0)->where('SKU_VENDOR', 'like', 'DRAFT-%')->update(['f_active' => 1]);   // a draft product that has stock is live
            }
            if (! $lines) throw new \RuntimeException('Nothing was received on this GRN.');
            $res = StockService::receive($ent, $lines, 'GRN', $grnId, ['lot_source' => 'GRN', 'contra' => '2000', 'contra_ref' => $po->fk_id_supplier]);
            DB::table('t_src_grn')->where('id', $grnId)->update(['posting_status' => 'POSTED', 'status' => 'POSTED', 'approved_by' => (string) Context::userId(), 'approved_at' => date('Y-m-d')]);
            DB::table('t_src_shipments')->where('id', $ship->id)->update(['actual_arrival' => $g->arrival_date ?: $g->grn_date, 'status' => 'ARRIVED']);
            TaskEngine::close('SRC_SHIP_ARRIVING', [$ship->id], 'Arrived'); TaskEngine::close('SRC_SHIP_OVERDUE', [$ship->id], 'Arrived');
            $allIn = ! DB::table('t_src_po_lines as p')->where('p.fk_id_po', $po->id)->where('p.f_active', 1)
                ->whereRaw('p.qty_confirmed > (SELECT COALESCE(SUM(l.received_qty + l.missing_qty),0) FROM t_src_grn_lines l JOIN t_src_grn g ON g.id=l.fk_id_grn WHERE l.fk_id_po_line=p.id AND g.posting_status="POSTED")')->exists();
            if ($allIn) DB::table('t_src_po')->where('id', $po->id)->update(['status' => 'RECEIVED']);
            if ($g->missing_units > 0 || $g->damaged_units > 0) {
                TaskEngine::open('SRC_GRN_SHORT', [$grnId], "GRN {$g->grn_number}: " . (int) $g->missing_units . ' short, ' . (int) $g->damaged_units . ' damaged – claim from the supplier', ['ent' => $ent, 'module' => 't_src_grn', 'record_id' => $grnId]);
            }
            foreach ($res['lots'] as $lot) TaskEngine::open('SRC_LOT_NO_COST', [$lot], 'Lot has only a provisional cost – run the landed cost for ' . $po->po_number, ['ent' => $ent, 'module' => 't_src_po', 'record_id' => $po->id, 'fix_url' => "#/po/{$po->id}"]);
            $used = self::applyAdvances($ent, (int) $po->fk_id_supplier, $g->grn_date);
            Audit::log('t_src_grn', $grnId, 'APPROVE', ['posted' => true, 'value' => $res['value']], null, $ent);
            return $res + ['advance_used' => $used, 'po_status' => $allIn ? 'RECEIVED' : $po->status];
        });
    }

    // ---------------------------------------------------------------- landed cost

    /**
     * Landed cost run for a PO. $d: freight, duty, other (base currency), final (bool).
     * Split (R-B_SOURCING default): freight and other by quantity, duty by value, over the units received of this PO.
     * Each lot gets the new unit cost; units already sold have their cost of sale corrected; the extra cost is accrued (1210) until its bill is paid.
     */
    public static function landedCost(int $poId, array $d): array
    {
        return DB::transaction(function () use ($poId, $d) {
            $po = self::po($poId, true); $ent = (int) $po->fk_id_ent;
            $lots = DB::table('fin_lots')->where('po_id', $poId)->where('company_id', $ent)->where('source', 'GRN')->lockForUpdate()->get();
            if ($lots->isEmpty()) throw new \RuntimeException('Nothing of this PO is received yet. Post the GRN first.');
            if ($lots->where('landed_status', 'FINAL')->isNotEmpty()) throw new \RuntimeException('The landed cost of this PO is already FINAL.');
            $freight = round((float) ($d['freight'] ?? 0), 2); $duty = round((float) ($d['duty'] ?? 0), 2); $other = round((float) ($d['other'] ?? 0), 2);
            if ($freight < 0 || $duty < 0 || $other < 0) throw new \InvalidArgumentException('Costs cannot be negative.');
            $rate = (float) ($po->exchange_rate ?: 1);
            $prev = DB::table('t_src_landed_cost')->where('fk_id_po', $poId)->where('f_active', 1)->whereNull('superseded_by')->orderByDesc('id')->first();
            // product cost of each lot = PO price × rate (the provisional cost at GRN)
            $base = []; $qtyAll = 0; $valAll = 0.0;
            foreach ($lots as $l) {
                $orig = (int) $l->qty + (int) DB::table('fin_lots')->where('source', 'SPLIT')->where('source_id', $l->id)->sum('qty');
                $unit = round((float) DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('fk_id_product', $l->fk_id_product)->value('unit_price') * $rate, 4);
                $base[$l->id] = ['qty' => $orig, 'unit' => $unit]; $qtyAll += $orig; $valAll += $orig * $unit;
            }
            $toStock = 0.0; $toCogs = 0.0; $n = 0; $weighted = 0.0;
            foreach ($lots as $l) {
                $b = $base[$l->id];
                $extra = $b['qty'] > 0 ? ($qtyAll > 0 ? ($freight + $other) * $b['qty'] / $qtyAll : 0) + ($valAll > 0 ? $duty * ($b['qty'] * $b['unit']) / $valAll : 0) : 0;
                $new = round($b['unit'] + ($b['qty'] > 0 ? $extra / $b['qty'] : 0), 4);
                $family = DB::table('fin_lots')->where('id', $l->id)->orWhere(fn ($w) => $w->where('source', 'SPLIT')->where('source_id', $l->id))->get();      // the lot and the lots split from it
                foreach ($family as $f) {
                    $delta = $new - (float) $f->unit_cost_gbp;
                    $inStock = (int) DB::table('fin_lot_balances')->where('fk_id_lot', $f->id)->sum('qty');
                    $sold = (int) DB::table('fin_lot_consumption')->where('lot_id', $f->id)->sum('qty');
                    $toStock += $delta * $inStock; $toCogs += $delta * $sold;
                    DB::table('fin_lots')->where('id', $f->id)->update(['unit_cost_gbp' => $new, 'landed_status' => ! empty($d['final']) ? 'FINAL' : 'PROVISIONAL']);
                    DB::table('fin_lot_consumption')->where('lot_id', $f->id)->update(['unit_cost_gbp' => $new]);
                    DB::table('t_stock_movements')->where('fk_id_lot', $f->id)->update(['unit_cost_gbp' => $new]);
                    if (! empty($d['final'])) TaskEngine::close('SRC_LOT_NO_COST', [$f->id], 'Landed cost is final');
                }
                $weighted += $new * $b['qty']; $n++;
            }
            $toStock = round($toStock, 2); $toCogs = round($toCogs, 2); $total = round($toStock + $toCogs, 2);
            if (abs($total) >= 0.01) {
                $lines = [];
                if (abs($toStock) >= 0.01) $lines[] = $toStock > 0 ? ['account' => '1200', 'debit' => $toStock] : ['account' => '1200', 'credit' => -$toStock];
                if (abs($toCogs) >= 0.01) $lines[] = $toCogs > 0 ? ['account' => '5000', 'debit' => $toCogs] : ['account' => '5000', 'credit' => -$toCogs];
                $lines[] = $total > 0 ? ['account' => '1210', 'credit' => $total] : ['account' => '1210', 'debit' => -$total];
                Ledger::post($ent, date('Y-m-d'), 'LANDED_COST', $poId, $lines, ['memo' => "Landed cost {$po->po_number}: freight $freight, duty $duty, other $other"]);
            }
            $lc = DB::table('t_src_landed_cost')->insertGetId(['fk_id_ent' => $ent, 'lc_code' => Numbering::next('LC', $ent), 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $poId, 'product_cost' => round($valAll, 2), 'freight_cost' => $freight, 'customs_duty' => $duty,
                'other_cost' => $other, 'freight_method' => 'QUANTITY', 'duty_method' => 'VALUE', 'total_cost' => round($valAll + $freight + $duty + $other, 2), 'accepted_qty' => $qtyAll, 'landed_cost_per_unit' => $qtyAll > 0 ? round($weighted / $qtyAll, 4) : 0,
                'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now(), 'run_version' => ($prev->run_version ?? 0) + 1, 'landed_status' => ! empty($d['final']) ? 'FINAL' : 'PROVISIONAL']);
            if ($prev) DB::table('t_src_landed_cost')->where('id', $prev->id)->update(['superseded_by' => $lc]);
            foreach ($lots->pluck('fk_id_product')->unique() as $pid) DB::table('t_product_vendors')->where('id', $pid)->update(['landing_price' => round((float) DB::table('fin_lots')->where('fk_id_product', $pid)->orderByDesc('received_date')->orderByDesc('id')->value('unit_cost_gbp'), 2)]);
            EventBus::fire(! empty($d['final']) ? 'LANDED_FINAL' : 'LANDED_PROVISIONAL', 't_src_po', $poId, ['run' => $lc, 'to_stock' => $toStock, 'to_cost_of_sale' => $toCogs], $ent);
            return ['run' => (int) $lc, 'to_stock' => $toStock, 'to_cost_of_sale' => $toCogs, 'unit_cost' => $qtyAll > 0 ? round($weighted / $qtyAll, 4) : 0.0, 'lots' => $n];
        });
    }

    // ---------------------------------------------------------------- reads

    /** Cycle workspace: header + the step bar (colour from data) + the records of each step. */
    public static function cycle(int $cycleId): array
    {
        $c = DB::table('t_src_cycles')->where('id', $cycleId)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Cycle not found.');
        $rfqs = DB::table('t_src_rfq as q')->leftJoin('t_src_suppliers as s', 's.id', '=', 'q.fk_id_supplier')->leftJoin('cat_currency as cu', 'cu.id', '=', 'q.fk_id_currency')->where('q.fk_id_cycle', $cycleId)->where('q.f_active', 1)->orderBy('q.id')
            ->get(['q.id', 'q.rfq_code', 's.supplier_name as supplier', 'q.qty', 'q.moq', 'q.final_unit_price as price', 'cu.currency_code as currency', 'q.lead_time_days as lead_days', 'q.status', 'q.d_valid_until'])->all();
        $pos = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as cu', 'cu.id', '=', 'p.fk_id_currency')->where('p.fk_id_cycle', $cycleId)->where('p.f_active', 1)->orderBy('p.id')
            ->get(['p.id', 'p.po_number', 's.supplier_name as supplier', 'p.po_date', 'p.po_total', 'cu.currency_code as currency', 'p.status', 'p.f_paid_in_full'])->all();
        $poIds = array_column($pos, 'id');
        $terms = DB::table('t_src_payment_terms')->whereIn('fk_id_po', $poIds ?: [0])->where('f_active', 1)->orderBy('fk_id_po')->orderBy('term_no')->get(['id', 'fk_id_po', 'milestone', 'planned_amount', 'paid_amount', 'remaining_amount', 'due_date', 'status'])->all();
        $ships = DB::table('t_src_shipments')->whereIn('fk_id_po', $poIds ?: [0])->where('f_active', 1)->orderBy('id')->get(['id', 'shipment_code', 'fk_id_po', 'planned_qty', 'etd', 'eta', 'actual_arrival', 'status'])->all();
        $grns = DB::table('t_src_grn')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->orderBy('id')->get(['id', 'grn_number', 'fk_id_shipment', 'grn_date', 'units_received', 'damaged_units', 'missing_units', 'posting_status'])->all();
        $lcs = DB::table('t_src_landed_cost')->whereIn('fk_id_po', $poIds ?: [0])->where('f_active', 1)->orderBy('id')->get(['id', 'lc_code', 'fk_id_po', 'freight_cost', 'customs_duty', 'other_cost', 'total_cost', 'landed_cost_per_unit', 'landed_status', 'run_version', 'superseded_by'])->all();
        $done = fn ($ok, $started) => $ok ? 'done' : ($started ? 'prog' : 'pend');
        $steps = [
            ['key' => 'rfq', 'name' => 'Quotes', 'state' => $done((bool) array_filter($rfqs, fn ($q) => $q->status === 'ACCEPTED') || $pos, (bool) $rfqs)],
            ['key' => 'po', 'name' => 'Purchase order', 'state' => $done((bool) array_filter($pos, fn ($p) => $p->status !== 'DRAFT'), (bool) $pos)],
            ['key' => 'pay', 'name' => 'Payments', 'state' => $done($terms && ! array_filter($terms, fn ($t) => $t->status !== 'PAID'), (bool) array_filter($terms, fn ($t) => $t->paid_amount > 0))],
            ['key' => 'ship', 'name' => 'Shipment', 'state' => $done($ships && ! array_filter($ships, fn ($s) => ! $s->actual_arrival), (bool) $ships)],
            ['key' => 'grn', 'name' => 'Receiving', 'state' => $done($grns && ! array_filter($grns, fn ($g) => $g->posting_status !== 'POSTED') && ! array_filter($pos, fn ($p) => $p->status !== 'RECEIVED'), (bool) $grns)],
            ['key' => 'lc', 'name' => 'Landed cost', 'state' => $done((bool) array_filter($lcs, fn ($l) => $l->landed_status === 'FINAL' && ! $l->superseded_by), (bool) $lcs)],
        ];
        $research = $c->fk_id_research ? DB::table('src_research')->where('id', $c->fk_id_research)->first(['id', 'research_code', 'title', 'est_margin_pct']) : null;
        return ['cycle' => ['id' => $c->id, 'code' => $c->cycle_code, 'name' => $c->product_name, 'type' => $c->cycle_type, 'status' => $c->status, 'target_qty' => (float) $c->target_qty, 'date' => $c->sourcing_date],
            'research' => $research, 'products' => DB::table('t_src_cycle_products as cp')->leftJoin('t_product_vendors as p', 'p.id', '=', 'cp.fk_id_product')->where('cp.fk_id_cycle', $cycleId)->get(['cp.fk_id_product as id', 'p.SKU_VENDOR as sku', 'cp.product_name as name', 'cp.target_qty'])->all(),
            'steps' => $steps, 'rfqs' => $rfqs, 'pos' => $pos, 'terms' => $terms, 'shipments' => $ships, 'grns' => $grns, 'landed' => $lcs];
    }

    /** Buying dashboard numbers. */
    public static function dashboard(int $ent): array
    {
        $today = date('Y-m-d');
        $due = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.status', '<>', 'PAID')->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED']);
        return [
            'research_open' => DB::table('src_research')->where('fk_id_ent', $ent)->whereIn('status', ['IDEA', 'RESEARCHING', 'SHORTLIST'])->count(),
            'cycles_open' => DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'CANCELLED'])->count(),
            'po_draft' => DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->where('status', 'DRAFT')->count(),
            'pay_due_count' => (clone $due)->where('t.due_date', '<=', $today)->count(),
            'pay_due_base' => round((float) (clone $due)->sum(DB::raw('t.remaining_amount * COALESCE(p.exchange_rate, 1)')), 2),
            'in_transit' => DB::table('t_src_shipments')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('actual_arrival')->count(),
            'overdue' => DB::table('t_src_shipments')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('actual_arrival')->where('eta', '<', $today)->count(),
            'grn_to_post' => DB::table('t_src_grn')->where('fk_id_ent', $ent)->where('f_active', 1)->where('posting_status', '<>', 'POSTED')->count(),
            'lots_provisional' => DB::table('fin_lots')->where('company_id', $ent)->where('source', 'GRN')->where('landed_status', 'PROVISIONAL')->where('qty_left', '>', 0)->count(),
            'supplier_advances' => Ledger::balance($ent, '1300'), 'supplier_payables' => -Ledger::balance($ent, '2000'),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private static function research(int $id): object
    {
        return DB::table('src_research')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('Research not found.');
    }

    private static function po(int $id, bool $lock = false): object
    {
        $q = DB::table('t_src_po')->where('id', $id)->where('fk_id_ent', Context::ent());
        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new \RuntimeException('Purchase order not found.');
    }

    private static function shippedQty(int $poLineId): float
    {
        return (float) DB::table('t_src_shipment_po_link as k')->join('t_src_shipments as s', 's.id', '=', 'k.fk_id_shipment')->where('k.fk_id_po_line', $poLineId)->where('k.f_active', 1)->where('s.f_active', 1)->sum('k.actual_qty');
    }

    /** Currency id for a code; the currency is added to the list the first time it is used. */
    public static function currencyId(?string $code): ?int
    {
        if (! $code) return null;
        $code = strtoupper($code);
        return (int) (DB::table('cat_currency')->where('currency_code', $code)->value('id') ?? DB::table('cat_currency')->insertGetId(['fk_id_ent' => Context::ent(), 'currency_code' => $code, 'currency_name' => $code, 'f_active' => 1, 'created_at' => now()]));
    }
}
