<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\Ledger;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuyingTest extends TestCase
{
    private int $wh; private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wh = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        $this->bank = DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Test Bank', 'account_label' => 'Main GBP', 'currency' => 'GBP', 'is_active' => 1]);
        Fx::set('USD', '2026-08-01', 0.80);          // 1 USD = 0.80 GBP
        Fx::set('USD', '2026-08-20', 0.78);
    }

    private function research(array $x = []): int
    {
        $id = DB::table('src_research')->insertGetId(RecordService::fillNotNull('src_research', $x + ['fk_id_ent' => 1, 'research_code' => 'RES-TST-2608-' . uniqid(), 'title' => 'Silicone dog bowl', 'status' => 'SHORTLIST',
            'target_sell_price' => 20, 'est_unit_cost' => 4, 'est_freight_per_unit' => 1.5, 'est_fee_pct' => 13, 'est_ad_pct' => 5, 'currency' => 'GBP', 'est_monthly_units' => 1000, 'created_by' => $this->owner->id, 'created_at' => now()]));
        foreach ([['Shenzhen Petware', 5.00, 500], ['Ningbo Bowls', 5.40, 300]] as [$n, $price, $moq]) {
            DB::table('src_research_suppliers')->insert(['fk_id_research' => $id, 'supplier_name_text' => $n, 'unit_price' => $price, 'currency' => 'USD', 'moq' => $moq, 'lead_days' => 25]);
        }
        return $id;
    }

    public function test_margin_estimate_matches_the_rule_example(): void
    {
        $this->assertSame(54.5, Buy::margin(20.00, 4.00, 1.50, 13, 5));
        $this->assertNull(Buy::margin(0, 4, 1, 13, 5));
        $this->assertSame(54.5, Buy::refreshMargin($this->research()));
    }

    public function test_go_makes_product_cycle_and_one_rfq_per_candidate_once_only(): void
    {
        $id = $this->research();
        $this->actingAs($this->owner);
        $r = Buy::go($id);
        $this->assertSame('DRAFT-', substr(DB::table('t_product_vendors')->where('id', $r['product'])->value('SKU_VENDOR'), 0, 6));
        $this->assertSame($id, (int) DB::table('t_src_cycles')->where('id', $r['cycle'])->value('fk_id_research'));
        $this->assertCount(2, $r['rfqs']);
        $this->assertSame(2, DB::table('t_src_suppliers')->count(), 'new supplier names became suppliers');
        $this->assertSame('GO', DB::table('src_research')->where('id', $id)->value('status'));
        try { Buy::go($id); $this->fail('second GO'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already started a cycle', $e->getMessage()); }
        $this->expectExceptionMessage('status cannot change');
        Buy::researchStatus($id, 'PARKED', 'changed my mind');
    }

    public function test_go_needs_a_reason_when_margin_is_low_and_a_senior_role(): void
    {
        $low = $this->research(['target_sell_price' => 8]);          // margin (8-4-1.5-1.44)/8 = 13.25 %
        $this->actingAs($this->owner);
        try { Buy::go($low); $this->fail('low margin GO without reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('under the minimum (25 %)', $e->getMessage()); }
        $this->assertSame(0, DB::table('t_src_cycles')->count(), 'nothing half-made');
        Buy::go($low, ['reason' => 'Bundle item, drives other sales']);
        $va = $this->makeUser('VA'); $this->actingAs($va); Context::set(1, $va->id);
        $this->expectExceptionMessage('GO needs a Manager, Partner or Owner');
        Buy::go($this->research());
    }

    public function test_no_go_needs_a_reason_and_stale_research_gets_a_task_that_closes_on_a_move(): void
    {
        $id = $this->research();
        DB::table('src_research')->where('id', $id)->update(['created_at' => now()->subDays(20)]);
        $this->assertSame(1, Buy::staleResearch(1)['opened']);
        $this->assertTrue(TaskEngine::isOpen('RES_STALE', [$id]));
        try { Buy::researchStatus($id, 'NO_GO'); $this->fail('no reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        Buy::researchStatus($id, 'PARKED', 'Season is over');
        $this->assertFalse(TaskEngine::isOpen('RES_STALE', [$id]));
    }

    /** The whole road: idea → GO → quote → PO → deposit → shipment → goods on the shelf → balance paid → landed cost. Money checked at every stop. */
    public function test_idea_to_stock_on_the_shelf_with_the_ledger_balanced_all_the_way(): void
    {
        $this->actingAs($this->owner);
        $go = Buy::go($this->research());
        Buy::acceptRfq($go['rfqs'][0]);
        $po = Buy::createPo(['rfq' => $go['rfqs'][0], 'qty' => 1000, 'date' => '2026-08-01']);
        $this->assertSame(5000.00, (float) DB::table('t_src_po')->where('id', $po)->value('po_total'));          // 1,000 × 5.00 USD
        try { Buy::pay(['term' => 0, 'amount' => 1, 'bank' => $this->bank]); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('term not found', $e->getMessage()); }

        $c = Buy::confirmPo($po);
        $this->assertSame(0.80, $c['fx_rate']);
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $po)->orderBy('term_no')->get();
        $this->assertSame([1500.0, 3500.0], [(float) $terms[0]->planned_amount, (float) $terms[1]->planned_amount]);    // 30 / 70
        $this->assertTrue(TaskEngine::isOpen('SRC_PAY_DUE', [$terms[0]->id]), 'deposit is due at once');
        try { Buy::confirmPo($po); $this->fail('confirmed twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('only a draft', $e->getMessage()); }

        // deposit 1,500 USD on 1 Aug at 0.80 = 1,200.00 GBP
        try { Buy::pay(['term' => $terms[0]->id, 'amount' => 1600, 'date' => '2026-08-01', 'bank' => $this->bank]); $this->fail('overpaid'); } catch (\RuntimeException $e) { $this->assertStringContainsString('more than the 1,500.00 still open', $e->getMessage()); }
        Buy::pay(['term' => $terms[0]->id, 'amount' => 1500, 'date' => '2026-08-01', 'bank' => $this->bank]);
        $sup = (int) DB::table('t_src_po')->where('id', $po)->value('fk_id_supplier');
        $this->assertSame(1200.00, Ledger::balance(1, '1300', 'PARTY', $sup));
        $this->assertSame(-1200.00, Ledger::balance(1, '1000', 'BANK', $this->bank));
        $this->assertFalse(TaskEngine::isOpen('SRC_PAY_DUE', [$terms[0]->id]), 'paid term closes its task');

        $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-08-18']);
        try { Buy::createShipment(['po' => $po, 'eta' => '2026-08-19']); $this->fail('shipped twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already on a shipment', $e->getMessage()); }
        $line = (int) DB::table('t_src_po_lines')->where('fk_id_po', $po)->value('id');

        // 990 arrive, 10 of them damaged, 10 missing
        // the line carries its own place (the putaway suggestion); it wins over the place of the GRN head
        $other = DB::table('cat_inventory_locations')->insertGetId(\App\Services\RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'OTHER-BIN', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        try { Buy::createGrn(['shipment' => $ship, 'location' => $other, 'date' => '2026-08-20', 'lines' => [['po_line' => $line, 'received' => 990, 'damaged' => 10, 'location' => 999999]]]); $this->fail('bad place'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not an active location', $e->getMessage()); }
        $grn = Buy::createGrn(['shipment' => $ship, 'location' => $other, 'date' => '2026-08-20', 'lines' => [['po_line' => $line, 'received' => 990, 'damaged' => 10, 'location' => $this->wh]]]);
        $this->assertSame(0, Stock::balance($go['product']), 'a draft GRN moves no stock');
        $res = Buy::postGrn($grn);
        $this->assertSame(3960.00, $res['value']);                      // 990 × 5.00 × 0.80 (the PO rate, provisional)
        $this->assertSame(980, Stock::balance($go['product'], $this->wh));
        $this->assertSame(980, Stock::available($go['product']));
        $this->assertSame(990, Stock::balance($go['product']));
        $this->assertSame(1200.00, $res['advance_used']);
        $this->assertSame(0.00, Ledger::balance(1, '1300', 'PARTY', $sup), 'the deposit is used against the goods');
        $this->assertSame(-2760.00, Ledger::balance(1, '2000', 'PARTY', $sup), 'still owed for the goods: 3,960 − 1,200');
        $this->assertTrue(TaskEngine::isOpen('SRC_GRN_SHORT', [$grn]));
        $this->assertSame(1, (int) DB::table('t_product_vendors')->where('id', $go['product'])->value('f_active'), 'the draft product is live once it has stock');
        $this->assertSame('RECEIVED', DB::table('t_src_po')->where('id', $po)->value('status'), '990 received + 10 missing = 1,000 ordered: every unit is accounted for; the 10 missing live on in the claim task');
        try { Buy::postGrn($grn); $this->fail('posted twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already posted', $e->getMessage()); }
        $this->assertSame(990, Stock::balance($go['product']), 'stock is never received twice');

        // balance 3,500 USD on 20 Aug at 0.78 = 2,730.00 GBP
        Buy::pay(['term' => $terms[1]->id, 'amount' => 3500, 'date' => '2026-08-20', 'bank' => $this->bank]);
        $this->assertSame(1, (int) DB::table('t_src_po')->where('id', $po)->value('f_paid_in_full'));
        $this->assertSame(-3930.00, Ledger::balance(1, '1000', 'BANK', $this->bank));

        // landed cost: freight 495.00 (by quantity) + duty 198.00 (by value) over 990 units → +0.70 per unit
        $lc = Buy::landedCost($po, ['freight' => 495, 'duty' => 198, 'final' => true]);
        $this->assertSame(4.70, $lc['unit_cost']);
        $this->assertSame(693.00, $lc['to_stock']);
        $lot = DB::table('fin_lots')->where('po_id', $po)->first();
        $this->assertSame(['FINAL', 4.7], [$lot->landed_status, (float) $lot->unit_cost_gbp]);
        $this->assertFalse(TaskEngine::isOpen('SRC_LOT_NO_COST', [$lot->id]));
        $this->assertSame(4653.00, Stock::value(1));                    // 990 × 4.70
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'), 'stock value = stock account, to the penny');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        try { Buy::landedCost($po, ['freight' => 1]); $this->fail('final changed'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already FINAL', $e->getMessage()); }

        $w = Buy::cycle($go['cycle']);
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done'], array_column($w['steps'], 'state'));
        $this->assertTrue(TaskEngine::isOpen('SRC_GRN_SHORT', [$grn]), 'the claim for the 10 missing and 10 damaged units is still open');
        $this->assertSame('Silicone dog bowl', $w['research']->title);
        $d = Buy::dashboard(1);
        $this->assertSame([0, 0, 0], [$d['po_draft'], $d['in_transit'], $d['grn_to_post']]);
    }

    public function test_landed_cost_after_some_units_are_sold_corrects_the_cost_of_sale(): void
    {
        $this->actingAs($this->owner);
        $go = Buy::go($this->research());
        Buy::acceptRfq($go['rfqs'][0]);
        $po = Buy::createPo(['rfq' => $go['rfqs'][0], 'qty' => 100, 'date' => '2026-08-01']);
        Buy::confirmPo($po);
        $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-08-18']);
        Buy::postGrn(Buy::createGrn(['shipment' => $ship, 'location' => $this->wh, 'date' => '2026-08-20']));
        Stock::consumeForSale(1, 801, $go['product'], 40, $this->wh, null, ['date' => '2026-08-25']);          // 40 sold at provisional 4.00
        $this->assertSame(160.00, Ledger::balance(1, '5000'));
        $lc = Buy::landedCost($po, ['freight' => 50, 'final' => true]);                                         // +0.50 per unit
        $this->assertSame([30.00, 20.00], [$lc['to_stock'], $lc['to_cost_of_sale']]);
        $this->assertSame(180.00, Ledger::balance(1, '5000'), 'cost of the 40 sold units is corrected: 40 × 4.50');
        $this->assertSame(4.5, (float) DB::table('fin_lot_consumption')->where('fk_id_sale', 801)->value('unit_cost_gbp'));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertSame(270.00, Stock::value(1));
    }

    public function test_missing_exchange_rate_stops_the_po_with_a_clear_message(): void
    {
        $this->actingAs($this->owner);
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'X1', 'product_name_vendor' => 'X', 'stock' => 0, 'f_active' => 1]));
        $s = DB::table('t_src_suppliers')->insertGetId(['fk_id_ent' => 1, 'supplier_name' => 'Euro Supplier', 'f_active' => 1, 'created_at' => now()]);
        $po = Buy::createPo(['supplier' => $s, 'currency' => 'EUR', 'date' => '2026-08-01', 'lines' => [['product' => $p, 'qty' => 10, 'unit_price' => 2]]]);
        $this->expectExceptionMessage('No exchange rate for EUR on or before 2026-08-01');
        Buy::confirmPo($po);
    }
}
