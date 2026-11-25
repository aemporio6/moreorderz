<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\PaymentTerms;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-30 term paid / remaining / status from its payments, summed in PO currency, re-synced on every change. */
class PaymentTermsTest extends TestCase
{
    public function test_r30_paid_is_summed_in_po_currency_and_resynced_on_change_move_and_reversal(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        Fx::set('USD', '2026-11-01', 0.80); Fx::set('EUR', '2026-11-01', 0.88);
        $usd = Buy::currencyId('USD'); $eur = Buy::currencyId('EUR');
        $po = $f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-PT-1', 'fk_id_currency' => $usd, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $t1 = $f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000, 'status' => 'DUE', 'f_active' => 1]);
        $t2 = $f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 2, 'milestone' => 'Balance', 'planned_amount' => 0, 'paid_amount' => 0, 'remaining_amount' => 0, 'status' => 'DUE', 'f_active' => 1]);
        $pay = fn ($term, $amt, $cur, $rate, $st = 'PAID') => $f('t_src_payments', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_term' => $term, 'payment_amount' => $amt, 'fk_id_currency' => $cur, 'exchange_rate' => $rate, 'payment_date' => '2026-11-01', 'status' => $st, 'f_active' => 1, 'payment_code' => uniqid('P')]);
        $a = $pay($t1, 400, $usd, 0.80); $b = $pay($t1, 100, $eur, 0.88); $pay($t1, 999, $usd, 0.80, 'PENDING_APPROVAL');
        $r = PaymentTerms::sync($t1);
        $this->assertSame([510.0, 490.0, 51.0, 'PART_PAID'], [$r['paid_amount'], $r['remaining_amount'], $r['completion_pct'], $r['status']], '100 EUR × 0.88 ÷ 0.80 = 110 USD; the payment waiting for approval is not counted');
        $this->assertSame([0.0, 'DUE'], [PaymentTerms::sync($t2)['completion_pct'], PaymentTerms::sync($t2)['status']], 'planned 0: 0 %, never Paid');
        RecordService::save('t_src_payments', ['fk_id_term' => $t2], $b);      // moved to another term: both terms are re-synced
        $this->assertSame(['400.0000', '110.0000'], [DB::table('t_src_payment_terms')->where('id', $t1)->value('paid_amount'), DB::table('t_src_payment_terms')->where('id', $t2)->value('paid_amount')]);
        RecordService::save('t_src_payments', ['payment_amount' => 1000], $a); $this->assertSame('PAID', DB::table('t_src_payment_terms')->where('id', $t1)->value('status'));
        RecordService::save('t_src_payments', ['f_active' => 0], $a); $this->assertSame(['0.0000', 'DUE'], [DB::table('t_src_payment_terms')->where('id', $t1)->value('paid_amount'), DB::table('t_src_payment_terms')->where('id', $t1)->value('status')], 'reversed: re-synced');
    }

    /** R-B_SOURCING-32 the rule example: USD 1,000 on 05/11, table 0.7812 → base 781.20; typed 0.7790 → base 779.00, difference −2.20. */
    public function test_r32_rate_table_line_and_typed_rate_on_money_rows(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', '2026-11-05', 0.7812);
        $a = Fx::rateNote('USD', '2026-11-05', 1000); $this->assertSame([781.2, 0.0], [$a['base'], $a['difference']]);
        $b = Fx::rateNote('USD', '2026-11-05', 1000, 0.7790); $this->assertSame([779.0, -2.2, 'Rate table 0.7812 · difference -2.20 (base 779.00)'], [$b['base'], $b['difference'], $b['text']]);
        $this->getJson('/api/fx/note?currency=USD&date=2026-11-05&amount=1000&rate=0.779')->assertOk()->assertJsonPath('difference', -2.2);
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-PT-2', 'fk_id_currency' => Buy::currencyId('USD'), 'status' => 'CONFIRMED', 'f_active' => 1]));
        $fc = RecordService::save('t_src_freight_costs', ['fk_id_po' => $po, 'cost_type' => 'FREIGHT', 'amount' => 1000, 'fk_id_currency' => Buy::currencyId('USD'), 'cost_date' => '2026-11-05']);
        $row = DB::table('t_src_freight_costs')->where('id', $fc['id'])->first(); $this->assertSame([0.7812, 781.2], [(float) $row->exchange_rate, (float) $row->base_amount], 'an empty rate takes the table of the row date');
        Fx::set('JPY', '2026-12-01', 0.005); $this->expectException(\Illuminate\Validation\ValidationException::class);
        RecordService::save('t_src_freight_costs', ['fk_id_po' => $po, 'cost_type' => 'FREIGHT', 'amount' => 10, 'fk_id_currency' => Buy::currencyId('JPY'), 'cost_date' => '2026-11-05']);      // no rate on or before the date: never a guess
    }


    /** R-B_SOURCING-52 system values of the sourcing lists exist, cannot be deleted or moved, may be renamed; other values stay free. */
    public function test_r52_system_values_of_sourcing_lists_are_locked(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        \App\Services\SrcLists::ensure(1); DB::table('cat_src_lov')->where('fk_id_ent', 1)->where('lov_group', 'PO_STATUS')->delete();
        $this->assertSame(['PO_STATUS: Confirmed', 'PO_STATUS: Completed'], \App\Services\SrcLists::ensure(1));
        $this->assertSame([], \App\Services\SrcLists::ensure(1), 'a second run adds nothing');
        $id = (int) DB::table('cat_src_lov')->where('fk_id_ent', 1)->where('lov_group', 'PO_STATUS')->where('lov_value', 'Confirmed')->value('id');
        try { RecordService::save('cat_src_lov', ['f_active' => 0], $id); $this->fail(); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('This value is used by the rules – it cannot be deleted. You may rename it.', $e->errors()['f_active'][0]); }
        try { RecordService::save('cat_src_lov', ['lov_group' => 'RFQ_STATUS'], $id); $this->fail(); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('stays in its list', $e->errors()['f_active'][0]); }
        RecordService::save('cat_src_lov', ['lov_value' => 'Confirmed (supplier)'], $id); $this->assertSame('Confirmed (supplier)', DB::table('cat_src_lov')->where('id', $id)->value('lov_value'));
        $free = RecordService::save('cat_src_lov', ['lov_group' => 'PO_STATUS', 'lov_value' => 'On hold', 'lov_order' => 5, 'f_active' => 1])['id'];
        RecordService::save('cat_src_lov', ['f_active' => 0], $free); $this->assertSame(0, (int) DB::table('cat_src_lov')->where('id', $free)->value('f_active'));
    }


    /** R-B_SOURCING-33 live formulas recomputed on the server (the rule example: GRN expected 1,000, received 990, damaged 11 → shortage 10, damage 1.11 %). */
    public function test_r33_live_formulas_of_the_step_forms(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $F = \App\Services\StepFormulas::class;
        $this->assertSame(['shortage_qty' => 10.0, 'damage_pct' => 1.11], $F::of('t_src_grn', ['expected_units' => 1000, 'units_received' => 990, 'damaged_units' => 11]));
        $this->assertSame(['shortage_qty' => 0.0, 'damage_pct' => 0.0], $F::of('t_src_grn', ['expected_units' => 0, 'units_received' => 0, 'damaged_units' => 3]), 'received 0 → 0 %');
        $this->assertSame(['defect_rate' => 4.0], $F::of('t_src_qc', ['qty_inspected' => 200, 'qty_failed' => 8]));
        $this->assertSame(['qty_in_transit' => 40.0], $F::of('t_src_transfers', ['qty_dispatched' => 100, 'qty_received' => 60]));
        $this->assertSame(['duty_amount' => 12.5], $F::of('t_src_customs', ['customs_value' => 500, 'duty_rate' => 2.5]));
        $this->assertSame(['line_total' => 97.0], $F::of('t_src_po_lines', ['qty_ordered' => 10, 'unit_price' => 10, 'discount' => 5, 'other_charges' => 2]));
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-PT-3', 'status' => 'CONFIRMED', 'f_active' => 1]));
        DB::table('t_src_po_lines')->insert(RecordService::fillNotNull('t_src_po_lines', ['fk_id_po' => $po, 'qty_ordered' => 500, 'unit_price' => 1, 'line_type' => 'PRODUCT', 'f_active' => 1]));
        $this->assertSame(['accepted_qty' => 290.0, 'variance_qty' => -100.0, 'progress_pct' => 60.0], $F::of('t_src_production', ['fk_id_po' => $po, 'planned_qty' => 400, 'produced_qty' => 300, 'rejected_qty' => 10]));
        $id = RecordService::save('t_src_qc', ['fk_id_po' => $po, 'qty_inspected' => 50, 'qty_failed' => 5, 'defect_rate' => 99, 'inspection_date' => '2026-11-20'])['id'];
        $this->assertEquals(10.0, (float) DB::table('t_src_qc')->where('id', $id)->value('defect_rate'), 'a posted value is replaced by the formula');
    }


    /** R-B_SOURCING-57 the rule example: term 3,000 USD; typed BANK payment USD 1,000 (base £790) → the bank line −£791.50 is within 2 % and matches it (no new payment); a credit-note payment is never offered. */
    public function test_r57_bank_line_matches_the_typed_foreign_payment_within_the_percent(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        Fx::set('USD', '2026-11-01', 0.80); $bank = \App\Services\MoneyService::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 5000, 'opening_date' => '2026-10-01']);
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-57', 'supplier_name' => 'Maker Co', 'f_active' => 1]);
        $po = $f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-57', 'fk_id_currency' => Buy::currencyId('USD'), 'fk_id_supplier' => $sup, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $term = $f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 3000, 'paid_amount' => 0, 'remaining_amount' => 3000, 'status' => 'DUE', 'f_active' => 1]);
        $pid = Buy::pay(['term' => $term, 'amount' => 1000, 'date' => '2026-11-05', 'bank' => $bank, 'rate' => 0.79]); $this->assertEquals(790.0, (float) DB::table('t_src_payments')->where('id', $pid)->value('base_amount'));
        $cn = $f('t_src_payments', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_term' => $term, 'fk_id_supplier' => $sup, 'payment_amount' => 1000, 'fk_id_currency' => Buy::currencyId('USD'), 'exchange_rate' => 0.79, 'base_amount' => 790, 'payment_date' => '2026-11-05', 'status' => 'PAID', 'paid_via' => 'CREDIT_NOTE', 'payment_method' => 'CREDIT_NOTE', 'f_active' => 1, 'payment_code' => 'PAY-CN-57']);
        $line = fn ($a) => $f('fin_bank_transactions', ['batch_id' => 0, 'bank_txn_id' => uniqid('T'), 'bank_account_id' => $bank, 'company_id' => 1, 'txn_date' => '2026-11-06', 'amount' => $a, 'currency' => 'GBP', 'amount_gbp' => $a, 'description' => 'MAKER CO', 'match_status' => 'UNMATCHED']);
        $far = $line(-900.00); $near = $line(-791.50); $before = DB::table('t_src_payments')->count();
        \App\Services\MoneyService::autoMatch(1, $bank);
        $l = DB::table('fin_bank_transactions')->where('id', $near)->first(); $this->assertSame(['PAYMENT', (string) $pid, 'M2%'], [$l->matched_to_type, (string) $l->matched_to_id, $l->match_rule]);
        $this->assertSame($before, DB::table('t_src_payments')->count(), 'no new payment'); $this->assertSame($near, (int) DB::table('t_src_payments')->where('id', $pid)->value('fk_id_bank_line'));
        $this->assertNull(DB::table('t_src_payments')->where('id', $cn)->value('fk_id_bank_line'), 'the credit-note payment is never linked to a bank line'); $this->assertSame('UNMATCHED', DB::table('fin_bank_transactions')->where('id', $far)->value('match_status'), '14 % away: left for a person');
    }

}
