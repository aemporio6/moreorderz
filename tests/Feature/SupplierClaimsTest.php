<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\Ledger;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\SupplierClaims as SC;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-56 claim section, R-57 CREDIT_NOTE payments, R-63 settled claim lowers the cost (new landed version), R-31 over-payment needs a reason and the Owner. */
class SupplierClaimsTest extends TestCase
{
    private int $wh; private int $po; private int $p; private int $cy; private int $sup; private int $grn; private int $bank;
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function cycle(string $cur, float $price, int $qty, int $received, int $damaged): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); if ($cur !== 'GBP') Fx::set($cur, '2026-08-01', 0.80);
        $this->wh = $this->f('cat_inventory_locations', ['location_name' => 'SC main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $this->sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-SC' . random_int(100, 999), 'supplier_name' => 'Claim Co', 'f_active' => 1]);
        $this->p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SC-A' . random_int(100, 999), 'product_name_vendor' => 'Claim item', 'f_active' => 1]);
        $this->cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-SC' . random_int(100, 999), 'product_name' => 'SC', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 1, 'created_at' => now()]);
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $this->cy, 'fk_id_product' => $this->p, 'product_name' => 'x']);
        $this->f('t_src_allocations', ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cy, 'fk_id_cycle_product' => $cp, 'allocated_qty' => $qty, 'f_active' => 1]);
        $this->po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $this->sup, 'currency' => $cur, 'date' => '2026-08-01', 'lines' => [['product' => $this->p, 'qty' => $qty, 'unit_price' => $price]]]);
        Buy::confirmPo($this->po); $ship = Buy::createShipment(['po' => $this->po, 'eta' => '2026-08-18']);
        $l = DB::table('t_src_po_lines')->where('fk_id_po', $this->po)->value('id');
        $this->grn = Buy::createGrn(['shipment' => $ship, 'location' => $this->wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $l, 'received' => $received, 'damaged' => $damaged, 'location' => $this->wh]]]);
        Buy::postGrn($this->grn);
        $this->bank = Fx::base(1) ? (int) \App\Services\MoneyService::createBank(1, ['bank_name' => 'SC bank', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 0, 'opening_date' => '2026-08-01']) : 0;
    }

    public function test_r56_claim_section_defaults_status_and_credit_balance(): void
    {
        // the rule's test: line missing 4, damaged 2 at USD 5.00 → 6 units, USD 30.00, base at the GRN-date rate
        $this->cycle('USD', 5.0, 100, 96, 2);
        $c = SC::refresh(1, $this->grn);
        $this->assertSame([6.0, 30.0, 'USD', 24.0, 'OPEN'], [$c['grn']['units'], $c['grn']['amount'], $c['grn']['currency'], $c['grn']['base'], $c['grn']['status']]);
        $line = (int) $c['lines'][0]->id;
        try { SC::setLine(1, $line, 4, null, ''); $this->fail('less needs a reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        try { SC::setLine(1, $line, 7, null, 'x'); $this->fail('more than missing + damaged'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('between 0 and 6', $e->getMessage()); }
        $this->assertSame(20.0, SC::setLine(1, $line, 4, null, 'two damaged were our fault')['grn']['amount']);
        SC::setLine(1, $line, 6, null, null);
        try { SC::status(1, $this->grn, 'SETTLED', []); $this->fail('settled as'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('credit note, refund or replacement', $e->getMessage()); }
        $this->postJson("/api/grn/{$this->grn}/claim", ['status' => 'AGREED'])->assertOk();
        $this->assertTrue(TaskEngine::isOpen('SRC_GRN_SHORT', \App\Services\CycleAlerts::key('SRC_GRN_SHORT', $this->grn)) || ! DB::table('tasks')->where('rule_code', 'SRC_GRN_SHORT')->exists());
        $s = $this->postJson("/api/grn/{$this->grn}/claim", ['status' => 'SETTLED', 'settled_as' => 'CREDIT_NOTE', 'reference' => 'CN-778'])->assertOk()->json();
        $this->assertEquals([30.0, 0.0, 30.0], [$s['grn']['amount'], $s['grn']['credit_used'], $s['grn']['credit_remaining']]);
        $this->assertFalse(TaskEngine::isOpen('SRC_GRN_SHORT', \App\Services\CycleAlerts::key('SRC_GRN_SHORT', $this->grn)), 'settled closes the claim task');
        // a later CREDIT_NOTE payment of 20.00 → used 20.00, remaining 10.00
        $term = (int) DB::table('t_src_payment_terms')->where('fk_id_po', $this->po)->orderBy('term_no')->value('id');
        SC::payWithCredit(1, $term, $this->grn, 20);
        $g = SC::show(1, $this->grn)['grn']; $this->assertSame([20.0, 10.0], [$g['credit_used'], $g['credit_remaining']]);
        try { SC::status(1, $this->grn, 'OPEN', []); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('stays settled', $e->getMessage()); }
    }

    public function test_r57_credit_note_payment_never_cash_and_never_more_than_the_credit(): void
    {
        $this->cycle('USD', 5.0, 100, 60, 0);      // 40 missing → claim 40 × 5 = 200
        SC::refresh(1, $this->grn); SC::status(1, $this->grn, 'SETTLED', ['settled_as' => 'CREDIT_NOTE']);
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $this->po)->orderBy('term_no')->pluck('id');
        $journals = DB::table('gl_journals')->where('source_type', 'PAYMENT')->count();
        // the rule's test: credit note payment USD 300 from a claim with 200 remaining → refused
        $this->postJson('/api/payments/credit-note', ['term' => $terms->last(), 'grn' => $this->grn, 'amount' => 300])->assertStatus(422)->assertJsonPath('message', 'Only 200.00 credit is left on claim ' . (DB::table('t_src_grn')->where('id', $this->grn)->value('grn_number') ?: DB::table('t_src_grn')->where('id', $this->grn)->value('grn_code')) . '.');
        $id = $this->postJson('/api/payments/credit-note', ['term' => $terms->last(), 'grn' => $this->grn, 'amount' => 150])->assertOk()->json('id');
        $p = DB::table('t_src_payments')->where('id', $id)->first();
        $this->assertSame(['CREDIT_NOTE', 'CREDIT_NOTE', null], [$p->payment_type, $p->paid_via, $p->fk_id_bank_line]);
        $this->assertSame($journals, DB::table('gl_journals')->where('source_type', 'PAYMENT')->count(), 'no cash booking for a credit note');
        $this->assertSame(150.0, (float) DB::table('t_src_payment_terms')->where('id', $terms->last())->value('paid_amount'));
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_r63_settled_claim_lowers_the_cost_through_a_new_landed_version(): void
    {
        // the rule's test: claim settled £24.00 on 6 units of A (400 accepted) → v2 per unit −0.0600
        $this->cycle('GBP', 2.5, 406, 406, 6);
        Buy::landedCost($this->po, ['final' => true]);
        $before = (float) DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('unit_cost_gbp');
        $this->assertSame(2.5375, $before, '1,015 ÷ 400');
        SC::refresh(1, $this->grn); $this->assertSame(15.0, (float) DB::table('t_src_grn')->where('id', $this->grn)->value('claim_amount'), '6 × 2.50');
        SC::setLine(1, (int) DB::table('t_src_grn_lines')->where('fk_id_grn', $this->grn)->value('id'), 6, 24, null);      // the supplier credits £4.00 a unit
        $r = SC::status(1, $this->grn, 'SETTLED', ['settled_as' => 'CREDIT_NOTE']);
        $this->assertSame(['FINAL', true], [$r['landed']['status'], $r['landed']['applied']], 'a later version, FINAL by itself');
        $after = (float) DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('unit_cost_gbp');
        $this->assertSame(-0.06, round($after - $before, 4));
        $this->assertSame(24.0, round(Ledger::balance(1, '1300', 'PARTY', $this->sup), 2), 'the supplier owes us the credit');
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200')); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        $this->assertSame(0.0, round(Ledger::balance(1, '4000'), 2), 'never income');
        // REPLACEMENT changes no cost
        $this->assertSame(2, DB::table('t_src_landed_cost')->where('fk_id_cycle', $this->cy)->count());
    }

    public function test_r31_over_payment_needs_a_reason_and_waits_for_the_owner(): void
    {
        $this->cycle('GBP', 2.0, 100, 100, 0);
        $t = DB::table('t_src_payment_terms')->where('fk_id_po', $this->po)->orderBy('term_no')->first();
        DB::table('t_src_payment_terms')->where('id', $t->id)->update(['planned_amount' => 200, 'remaining_amount' => 200]);
        // the rule's test: term remaining 200.00, payment 250.00 without reason → refused
        $this->postJson('/api/pay', ['term' => $t->id, 'amount' => 250, 'date' => '2026-09-01', 'bank' => $this->bank])->assertStatus(422)
            ->assertJsonPath('message', 'WARNING: This payment (250.00) exceeds the remaining amount of the selected Payment Term (200.00). Give the reason for paying more than the term.');
        // with reason → saved, the Approvals card shows it; nothing booked yet
        $x = $this->postJson('/api/pay', ['term' => $t->id, 'amount' => 250, 'date' => '2026-09-01', 'bank' => $this->bank, 'overpay_reason' => 'supplier adds 50 for rush'])->assertOk()->json();
        $this->assertTrue($x['overpay']);
        $this->assertTrue(collect(Approvals::pending(1))->contains(fn ($a) => (int) ((array) $a)['id'] === (int) $x['approval']), 'on the Approvals card');
        $this->assertSame(['PENDING_APPROVAL', 0], [DB::table('t_src_payments')->where('id', $x['id'])->value('status'), (int) DB::table('t_src_payments')->where('id', $x['id'])->value('f_active')]);
        $this->assertSame(0.0, (float) DB::table('t_src_payment_terms')->where('id', $t->id)->value('paid_amount'));
        $this->assertFalse(DB::table('gl_journals')->where('source_type', 'PAYMENT')->where('source_id', $x['id'])->exists(), 'not booked before approval');
        // a second person approves (never the one who asked) → booked once, term paid
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); Context::set(1, $m->id);
        Approvals::decide($x['approval'], true);
        $this->assertSame(['PAID', 1], [DB::table('t_src_payments')->where('id', $x['id'])->value('status'), (int) DB::table('t_src_payments')->where('id', $x['id'])->value('f_active')]);
        $this->assertSame([250.0, 'PAID'], [(float) DB::table('t_src_payment_terms')->where('id', $t->id)->value('paid_amount'), DB::table('t_src_payment_terms')->where('id', $t->id)->value('status')]);
        $this->assertSame(1, DB::table('gl_journals')->where('source_type', 'PAYMENT')->where('source_id', $x['id'])->count());
        $this->assertSame(1, DB::table('t_src_payments')->where('fk_id_term', $t->id)->count(), 'the waiting row is booked, not a second one');
        // refused → cancelled
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $t2 = (int) DB::table('t_src_payment_terms')->where('fk_id_po', $this->po)->orderBy('term_no')->skip(1)->value('id');
        $y = $this->postJson('/api/pay', ['term' => $t2, 'amount' => 99999, 'date' => '2026-09-01', 'bank' => $this->bank, 'overpay_reason' => 'typo test'])->assertOk()->json();
        $this->actingAs($m); Context::set(1, $m->id); Approvals::decide($y['approval'], false, 'too much');
        $this->assertSame(['REJECTED', 0], [DB::table('t_src_payments')->where('id', $y['id'])->value('status'), (int) DB::table('t_src_payments')->where('id', $y['id'])->value('f_active')]);
    }
    /** R-FIN-28 a claim settled as REFUND is paid back through the bank: matched by hand, Cr supplier 1300, never more than the refund; undo reverses it. */
    public function test_fin28_refund_claim_matched_to_a_bank_line(): void
    {
        $this->cycle('GBP', 5.0, 100, 90, 0);      // 10 missing → refund 50.00
        SC::refresh(1, $this->grn); SC::status(1, $this->grn, 'SETTLED', ['settled_as' => 'REFUND']);
        $mk = fn ($a) => DB::table('fin_bank_transactions')->insertGetId(RecordService::fillNotNull('fin_bank_transactions', ['batch_id' => 0, 'bank_txn_id' => uniqid('T'), 'bank_account_id' => $this->bank, 'company_id' => 1, 'txn_date' => '2026-08-25', 'amount' => $a, 'currency' => 'GBP', 'amount_gbp' => $a, 'description' => 'CLAIM CO REFUND', 'match_status' => 'UNMATCHED']));
        $big = $mk(60.0); $ok = $mk(50.0);
        $this->assertSame([$this->grn], array_column($this->getJson("/api/bank-lines/$ok/targets")->json('claims'), 'id'));
        $this->postJson("/api/bank-lines/$big/match", ['kind' => 'CLAIM', 'target' => $this->grn])->assertStatus(422)->assertJsonPath('message', 'The bank line (60.00) is more than the refund still due (50.00).');
        $before = \App\Services\Ledger::balance(1, '1300', 'PARTY', $this->sup);
        $this->postJson("/api/bank-lines/$ok/match", ['kind' => 'CLAIM', 'target' => $this->grn])->assertOk();
        $this->assertEqualsWithDelta($before - 50, \App\Services\Ledger::balance(1, '1300', 'PARTY', $this->sup), 0.001, 'the supplier no longer owes the refund');
        $this->postJson("/api/bank-lines/$ok/unmatch")->assertOk();
        $this->assertEqualsWithDelta($before, \App\Services\Ledger::balance(1, '1300', 'PARTY', $this->sup), 0.001);
    }
    /** R-B_SOURCING-63 an import freight / duty bank line names its cycle and becomes a PAID cost row there – the landed cost takes it into stock value; undo removes it. */
    public function test_b63_import_freight_and_duty_bank_lines_reach_the_landed_cost(): void
    {
        $this->cycle('GBP', 5.0, 100, 100, 0);
        $mk = fn ($a) => DB::table('fin_bank_transactions')->insertGetId(RecordService::fillNotNull('fin_bank_transactions', ['batch_id' => 0, 'bank_txn_id' => uniqid('T'), 'bank_account_id' => $this->bank, 'company_id' => 1, 'txn_date' => '2026-08-22', 'amount' => $a, 'currency' => 'GBP', 'amount_gbp' => $a, 'description' => 'FORWARDER LTD', 'match_status' => 'UNMATCHED']));
        $fr = $mk(-40.0); $du = $mk(-20.0);
        $this->postJson("/api/bank-lines/$fr/categorise", ['category' => 'IMPORT_FREIGHT'])->assertStatus(422)->assertJsonPath('message', 'Choose the purchase cycle this import cost belongs to – it goes into the landed cost of its products.');
        $this->postJson("/api/bank-lines/$fr/categorise", ['category' => 'IMPORT_FREIGHT', 'cycle' => $this->cy])->assertOk();
        $this->postJson("/api/bank-lines/$du/categorise", ['category' => 'IMPORT_DUTY', 'cycle' => $this->cy])->assertOk();
        $this->assertSame([40.0, 'PAID'], [(float) DB::table('t_src_freight_costs')->where('fk_id_bank_line', $fr)->value('paid_base_amount'), DB::table('t_src_freight_costs')->where('fk_id_bank_line', $fr)->value('pay_status')]);
        $this->assertSame(20.0, (float) DB::table('t_src_customs')->where('fk_id_bank_line', $du)->value('paid_base_amount'));
        $r = Buy::landedCost($this->po, []);
        $this->assertEqualsWithDelta(5.60, (float) DB::table('fin_lots')->where('fk_id_product', $this->p)->value('unit_cost_gbp'), 0.001, '500 + 40 freight + 20 duty over 100 units');
        $this->postJson("/api/bank-lines/$fr/unmatch")->assertOk();
        $this->assertSame(0, (int) DB::table('t_src_freight_costs')->where('fk_id_bank_line', $fr)->value('f_active'));
    }
}
