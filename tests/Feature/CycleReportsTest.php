<?php

namespace Tests\Feature;

use App\Services\CycleReports;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-2 Cycle Journey and R-F_SETUP-12 Cycle Summary PDF on the stage engine. */
class CycleReportsTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_journey_rule_example_two_pos_no_grn_skipped_rfq(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-J-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'DIRECT', 'skipped_steps' => 'rfq,samples', 'created_by' => $this->owner->id]);
        $s = $this->f('t_src_suppliers', ['supplier_code' => 'J-' . uniqid(), 'supplier_name' => 'Lamp Co']);
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Desk lamp', 'sku' => 'DL-1', 'target_qty' => 1000, 'target_unit_cost' => 4.5]);
        $p2 = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-J-2', 'po_date' => '2026-11-10', 'po_total' => 200, 'status' => 'DRAFT']);
        $p1 = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-J-1', 'po_date' => '2026-11-01', 'po_total' => 100, 'deposit_amount' => 30, 'balance_amount' => 70, 'status' => 'CONFIRMED']);
        $j = $this->getJson("/api/cycle/$cy/journey")->assertOk()->json();
        $b = collect($j['blocks'])->keyBy('key');
        $this->assertSame(['products', 'rfq', 'po', 'payments', 'allocation', 'shipment', 'grn'], array_keys($b->all()), 'seven blocks in this order');
        $this->assertSame('Skipped', $b['rfq']['empty_text']);
        $this->assertSame('No GRN yet', $b['grn']['empty_text']);
        $this->assertSame('No payments yet', $b['payments']['empty_text']);
        $this->assertCount(2, $b['po']['rows']);
        $this->assertEquals(['01-11-2026', 'PO-J-1', 'Lamp Co', 100, 30, 70, 'CONFIRMED'], $b['po']['rows'][0]['cells'], 'timeline order with a Date column first');
        $this->assertSame("#/po/$p1", $b['po']['rows'][0]['link']);
        $this->assertSame('Date', $b['po']['cols'][0]);
        $this->assertStringStartsWith("Cycle: CYC-J-", $j['header']);
        $this->assertStringContainsString('| Progress: ' . $j['progress_pct'] . '%', $j['header']);
        $this->assertSame('Payment Schedule · Payments', $j['stage'], 'current stage from the stage engine (skips jumped)');
        $this->assertSame('•••', CycleReports::journey(1, $cy, false)['blocks'][2]['rows'][0]['cells'][3], 'PO total hidden without buying.MONEY');
        $pdf = CycleReports::journeyPdf(1, $cy);
        $this->assertStringStartsWith('%PDF', $pdf['body']);
        $this->assertSame('CycleJourney_' . DB::table('t_src_cycles')->where('id', $cy)->value('cycle_code') . '_' . date('Ymd') . '.pdf', $pdf['name']);
        $this->assertStringContainsString('No GRN yet', $pdf['html']);
    }

    public function test_summary_rule_example(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-S-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'priority' => 'High', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $s = $this->f('t_src_suppliers', ['supplier_code' => 'S-' . uniqid(), 'supplier_name' => 'Lamp Co']);
        $r = $this->f('t_src_suppliers', ['supplier_code' => 'R-' . uniqid(), 'supplier_name' => 'Quote Only Ltd']);
        $this->f('t_src_rfq', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $r, 'rfq_code' => 'RFQ-S']);
        $this->assertSame('Quote Only Ltd', CycleReports::summary(1, $cy)['suppliers'], 'no PO yet → suppliers of the RFQs');
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Desk lamp']); $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Floor lamp']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-S-1', 'po_date' => '2026-11-01', 'status' => 'CONFIRMED', 'exchange_rate' => 0.5]);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qty_ordered' => 1000, 'unit_price' => 10, 'line_total' => 10000]);
        $this->f('t_src_payments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'payment_code' => 'PAY-S', 'payment_amount' => 4000, 'base_amount' => 2000]);
        $this->f('t_src_production', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'prod_code' => 'PR-S', 'produced_qty' => 800]);
        $this->f('t_src_qc', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-S', 'qty_inspected' => 200, 'qty_passed' => 190, 'corrective_action' => 'n/a']);
        $this->f('t_src_freight', ['fk_id_cycle' => $cy, 'freight_code' => 'FR-S', 'total_cost' => 600, 'exchange_rate' => 1]);
        $this->f('t_src_customs', ['fk_id_cycle' => $cy, 'customs_code' => 'CU-S', 'duty_amount' => 250, 'import_vat' => 1234.5]);
        $this->f('t_src_landed_cost', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'lc_code' => 'LC-S', 'total_cost' => 9600, 'accepted_qty' => 2000]);
        $this->f('t_src_landed_cost', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'lc_code' => 'LC-OLD', 'total_cost' => 99999, 'accepted_qty' => 1, 'superseded_by' => 1]);
        $this->f('t_src_allocations', ['fk_id_cycle' => $cy, 'alloc_code' => 'AL-S', 'allocated_qty' => 900]);
        $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'grn_number' => 'GRN-S', 'units_received' => 880, 'damaged_units' => 5, 'missing_units' => 15]);

        foreach ([500, 300] as $m) $this->f('t_src_rfq', ['fk_id_cycle' => $cy, 'rfq_code' => 'RFQ-M' . $m, 'moq' => $m]);
        $this->f('t_src_investments', ['fk_id_cycle' => $cy, 'invest_code' => 'INV-S', 'invest_amount' => 1000, 'base_amount' => 800, 'status' => 'Confirmed']);
        $x = $this->getJson("/api/cycle/$cy/summary")->assertOk()->json();
        $this->assertSame([300.0, 800.0], [(float) $x['header']['moq'], (float) $x['money']['invested']], 'R-B_SOURCING-24 MOQ = the lowest of the RFQs; R-25 invested in base');
        $this->assertSame('SOURCING CYCLE SUMMARY — ' . DB::table('t_src_cycles')->where('id', $cy)->value('cycle_code'), $x['title']);
        $this->assertSame(['Desk lamp, Floor lamp', 'Lamp Co', 'Open', 'High'], [$x['products'], $x['suppliers'], $x['status'], $x['priority']]);
        $this->assertSame('800 (80.0%)', $x['qty_text']['produced']);
        $this->assertEquals(95.0, $x['qty']['qc_pass_rate']);
        $this->assertEquals(4.8, $x['money']['landed_per_unit'], 'superseded runs are left out');
        $this->assertEquals([5000.0, 2000.0, 3000.0, 600.0, 250.0], [$x['money']['purchase_cost'], $x['money']['paid'], $x['money']['outstanding'], $x['money']['freight'], $x['money']['customs_duty']], 'purchase cost in base: 10,000 × 0.5');
        $this->assertEquals([1000.0, 880.0, 900.0, 100.0, 5.0, 15.0], [$x['qty']['ordered'], $x['qty']['received'], $x['qty']['allocated'], $x['qty']['unallocated'], $x['qty']['damaged'], $x['qty']['missing']]);
        $this->assertStringStartsWith('Import VAT (1,234.50) tracked separately — reclaimable, not in landed cost. Generated ', $x['footnote']);
        $pdf = CycleReports::summaryPdf(1, $cy);
        $this->assertStringStartsWith('%PDF', $pdf['body']);
        $this->assertStringContainsString('4.8000 ', $pdf['html']);
        $this->assertStringContainsString('95.0 %', $pdf['html']);
        $this->assertStringContainsString('800 (80.0%)', $pdf['html']);
        $this->assertSame('application/pdf', $this->get("/api/cycle/$cy/summary?pdf=1")->assertOk()->headers->get('Content-Type'));
    }
}
