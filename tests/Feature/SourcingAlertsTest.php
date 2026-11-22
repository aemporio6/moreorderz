<?php

namespace Tests\Feature;

use App\Services\CycleAlerts as CA;
use App\Services\RecordService;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-26 (alert registry, Overview: pipeline, Needs action, contacts), with R-17 gaps and R-39 quantity check. */
class SourcingAlertsTest extends TestCase
{
    private int $cy; private int $po; private int $prod; private int $cp; private int $sup;

    private function ins(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    private function world(string $type = 'FULL'): void
    {
        $this->actingAs($this->owner);
        $this->cy = $this->ins('t_src_cycles', ['cycle_code' => 'CYC-T-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => $type, 'created_by' => $this->owner->id]);
        $this->prod = $this->ins('t_product_vendors', ['SKU_VENDOR' => 'LAMP-1', 'product_name_vendor' => 'Lamp']);
        $this->cp = $this->ins('t_src_cycle_products', ['fk_id_cycle' => $this->cy, 'fk_id_product' => $this->prod, 'product_name' => 'Lamp']);
        $this->sup = $this->ins('t_src_suppliers', ['supplier_code' => 'S-' . uniqid(), 'supplier_name' => 'Ningbo Bright Lights', 'phone_whatsapp' => '+86 138 0013 8000']);
        $this->po = $this->ins('t_src_po', ['po_number' => 'PO-T-' . uniqid(), 'fk_id_cycle' => $this->cy, 'fk_id_supplier' => $this->sup, 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
        $this->ins('t_src_po_lines', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'fk_id_cycle_product' => $this->cp, 'fk_id_product' => $this->prod, 'sku' => 'LAMP-1', 'qty_ordered' => 1000]);
    }

    private function open(string $code, array $key): bool { return TaskEngine::isOpen($code, $key); }

    public function test_the_rule_example_grn_short_and_qc_failed_close_by_the_worker(): void
    {
        $this->world();
        $grn = $this->ins('t_src_grn', ['fk_id_cycle' => $this->cy, 'grn_number' => 'GRN-T1', 'grn_date' => '2026-11-10', 'units_received' => 996, 'missing_units' => 4, 'damaged_units' => 0]);
        $qc = $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-T1', 'qty_inspected' => 100, 'qty_failed' => 12, 'inspection_date' => '2026-11-05']);
        CA::sweep(1);
        $this->assertTrue($this->open('SRC_GRN_SHORT', ['GRN-T1'])); $this->assertTrue($this->open('SRC_QC_FAILED', ['QC-T1']));
        $t = DB::table('tasks')->where('dedupe_key', 'SRC_QC_FAILED:QC-T1')->first(); $this->assertSame('HIGH', $t->priority);      // P1 → HIGH (IC-9)
        DB::table('t_src_qc')->where('id', $qc)->update(['corrective_action' => 'Supplier reworks the 12 units']); CA::sweep(1);
        $this->assertFalse($this->open('SRC_QC_FAILED', ['QC-T1']));      // closed within one worker run
        try { CA::claim(1, $grn, 'NO_CLAIM', ''); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        CA::claim(1, $grn, 'NO_CLAIM', 'Supplier sends 4 with the next order'); $this->assertFalse($this->open('SRC_GRN_SHORT', ['GRN-T1'])); CA::sweep(1); $this->assertFalse($this->open('SRC_GRN_SHORT', ['GRN-T1']));
    }

    public function test_quantity_check_mismatch_and_over_allocation(): void
    {
        $this->world(); $code = DB::table('t_src_cycles')->where('id', $this->cy)->value('cycle_code');
        $grn = $this->ins('t_src_grn', ['fk_id_cycle' => $this->cy, 'grn_number' => 'GRN-T2', 'grn_date' => '2026-11-10']);
        $line = $this->ins('t_src_grn_lines', ['fk_id_grn' => $grn, 'fk_id_product' => $this->prod, 'sku' => 'LAMP-1', 'expected_qty' => 1000, 'received_qty' => 990, 'accepted_qty' => 980, 'damaged_qty' => 10, 'missing_qty' => 10]);
        $al = $this->ins('t_src_allocations', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'fk_id_cycle_product' => $this->cp, 'alloc_code' => 'AL-1', 'allocated_qty' => 1000]);
        $this->assertSame('Balanced', CA::quantities($this->cy)[0]['chip']);      // the v1.7 rule example
        DB::table('t_src_grn_lines')->where('id', $line)->update(['damaged_qty' => 5]); $q = CA::quantities($this->cy)[0]; $this->assertSame(['Mismatch', 'red'], [$q['chip'], $q['tone']]);
        CA::sweep(1); $this->assertTrue($this->open('SRC_QTY_MISMATCH', [$code, 'LAMP-1']));
        DB::table('t_src_allocations')->where('id', $al)->update(['allocated_qty' => 1050]); $this->assertSame('50 OVER', CA::quantities($this->cy)[0]['chip']);
        CA::sweep(1); $this->assertTrue($this->open('SRC_OVER_ALLOC', [$code, 'LAMP-1'])); $this->assertSame('NORMAL', DB::table('tasks')->where('dedupe_key', "SRC_OVER_ALLOC:$code:LAMP-1")->value('priority'));
        DB::table('t_src_allocations')->where('id', $al)->update(['allocated_qty' => 1000]); DB::table('t_src_grn_lines')->where('id', $line)->update(['damaged_qty' => 10]); CA::sweep(1);
        $this->assertFalse($this->open('SRC_OVER_ALLOC', [$code, 'LAMP-1'])); $this->assertFalse($this->open('SRC_QTY_MISMATCH', [$code, 'LAMP-1']));
    }

    public function test_pipeline_gaps_skipped_steps_and_historical_cycles(): void
    {
        $this->world(); $c = DB::table('t_src_cycles')->where('id', $this->cy)->first();
        $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-T1', 'eta' => '2030-01-01']);
        $p = CA::pipeline($c); $this->assertSame(['rfq', 'samples', 'pay', 'production', 'qc', 'allocation'], $p['missing']); $this->assertSame('freight', $p['current']); $this->assertStringStartsWith('record missing: RFQ / Quotations', $p['missing_text']);      // FULL cycle with PO but no RFQ
        CA::sweep(1); $this->assertTrue($this->open('SRC_STEP_MISSING', [$c->cycle_code, 'rfq'])); $this->assertSame($this->owner->id, (int) DB::table('tasks')->where('dedupe_key', "SRC_STEP_MISSING:{$c->cycle_code}:rfq")->value('owner_user_id'));      // the cycle's user
        DB::table('t_src_cycles')->where('id', $this->cy)->update(['cycle_type' => 'DIRECT', 'skipped_steps' => 'rfq,samples,production,qc,pay,allocation']); $p = CA::pipeline(DB::table('t_src_cycles')->where('id', $this->cy)->first());
        $this->assertSame([], $p['missing']); $this->assertSame('skipped', collect($p['steps'])->firstWhere('key', 'rfq')['state']);      // DIRECT cycle: no "missing" warning for the skipped steps
        CA::sweep(1); $this->assertFalse($this->open('SRC_STEP_MISSING', [$c->cycle_code, 'rfq']));
        $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-U1', 'qty_inspected' => 10, 'corrective_action' => 'n/a']); $p = CA::pipeline(DB::table('t_src_cycles')->where('id', $this->cy)->first());      // a record on a skipped step un-skips it
        $this->assertSame('done', collect($p['steps'])->firstWhere('key', 'qc')['state']); $this->assertStringNotContainsString('qc', (string) DB::table('t_src_cycles')->where('id', $this->cy)->value('skipped_steps'));
        $this->assertSame('STEP_UNSKIPPED', DB::table('sys_workflow_state')->where(['workflow' => 'SRC_CYCLE', 'entity_id' => $this->cy, 'step_key' => 'qc'])->value('reason'));
        DB::table('t_src_cycles')->where('id', $this->cy)->update(['cycle_type' => 'HISTORICAL', 'skipped_steps' => '']); $codes = array_unique(array_column(CA::detect(1, $this->cy), 'code'));
        $this->assertNotContains('SRC_STEP_MISSING', $codes);      // old-entry cycles get only PAY_DUE, GRN_SHORT, SHIP_OVERDUE, LOT_NO_COST
    }

    public function test_payments_shipments_documents_lots_and_stock(): void
    {
        $this->world(); $code = fn ($t, $id, $c) => DB::table($t)->where('id', $id)->value($c);
        $t1 = $this->ins('t_src_payment_terms', ['fk_id_po' => $this->po, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 300, 'remaining_amount' => 300, 'status' => 'PENDING']);      // no due date: never lost
        $t2 = $this->ins('t_src_payment_terms', ['fk_id_po' => $this->po, 'term_no' => 2, 'milestone' => 'Balance', 'planned_amount' => 700, 'remaining_amount' => 700, 'status' => 'PENDING', 'due_date' => '2030-01-01']);
        $s1 = $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-LATE', 'eta' => date('Y-m-d', strtotime('-2 days'))]);
        $s2 = $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-SOON', 'eta' => date('Y-m-d', strtotime('+2 days'))]);
        DB::table('src_doc_requirements')->insert(['fk_id_ent' => 1, 'step_key' => 'shipment', 'doc_type' => 'Bill of lading', 'f_active' => 1]);
        $this->ins('t_src_documents', ['fk_id_cycle' => $this->cy, 'fk_id_shipment' => $s1, 'doc_type' => 'Bill of lading', 'doc_name' => 'BL', 'drive_url' => 'https://drive.google.com/x']);
        $bad = $this->ins('t_src_documents', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'doc_type' => 'Invoice', 'doc_name' => 'Inv', 'drive_url' => 'drive.google.com/abc']);
        $lot = (int) DB::table('fin_lots')->insertGetId(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'LAMP-1', 'fk_id_product' => $this->prod, 'fk_id_cycle' => $this->cy, 'qty' => 5, 'qty_left' => 5, 'unit_cost_gbp' => 0, 'lot_code' => 'LOT-T1', 'received_date' => '2026-11-10', 'created_at' => now()]));
        DB::table('t_product_vendors')->where('id', $this->prod)->update(['stock' => 5, 'landing_price' => 0]);
        $partner = $this->makeUser('PARTNER'); CA::sweep(1);
        $this->assertTrue($this->open('SRC_PAY_DUE', CA::key('SRC_PAY_DUE', $t1))); $this->assertFalse($this->open('SRC_PAY_DUE', CA::key('SRC_PAY_DUE', $t2)));
        $this->assertTrue($this->open('SRC_SHIP_OVERDUE', ['SH-LATE'])); $this->assertTrue($this->open('SRC_SHIP_ARRIVING', ['SH-SOON'])); $this->assertFalse($this->open('SRC_SHIP_OVERDUE', ['SH-SOON']));
        $this->assertTrue($this->open('SRC_DOC_MISSING', ['SH-SOON', 'Bill of lading'])); $this->assertFalse($this->open('SRC_DOC_MISSING', ['SH-LATE', 'Bill of lading'])); $this->assertTrue($this->open('SRC_DOC_MISSING', ['DOC' . $bad, 'Invoice']));
        $this->assertTrue($this->open('SRC_LOT_NO_COST', ['LOT-T1'])); $this->assertTrue($this->open('STK_NO_LOCATION', ['LAMP-1'])); $this->assertTrue($this->open('STK_NO_COST', ['LAMP-1']));
        $this->assertSame($partner->id, (int) DB::table('tasks')->where('dedupe_key', 'STK_NO_COST:LAMP-1')->value('owner_user_id'));      // STK_NO_COST → Partner (06C)
        // the per-cycle Overview: P1 first, then P2, then due date; next action = first row
        $o = CA::overview(1, $this->cy, $this->owner); $ps = array_column($o['needs'], 'p'); $this->assertSame($ps, array_merge(array_filter($ps, fn ($p) => $p === 'P1'), array_filter($ps, fn ($p) => $p === 'P2')));
        $this->assertSame($o['needs'][0]['title'], $o['next_action']); $this->assertContains('Shipment overdue · SH-LATE', array_column($o['needs'], 'title'));
        $this->assertNotContains('SRC_PAY_DUE', array_column(array_filter($o['needs'], fn ($r) => $r['p'] === 'P2'), 'code'));
        DB::table('t_src_payment_terms')->where('id', $t1)->update(['status' => 'PAID', 'remaining_amount' => 0]); DB::table('t_src_shipments')->where('id', $s1)->update(['actual_arrival' => date('Y-m-d')]);
        DB::table('t_src_documents')->where('id', $bad)->update(['drive_url' => 'https://drive.google.com/abc']); DB::table('fin_lots')->where('id', $lot)->update(['unit_cost_gbp' => 3.2]); DB::table('t_product_vendors')->where('id', $this->prod)->update(['landing_price' => 3.2]);
        CA::sweep(1);
        foreach ([['SRC_PAY_DUE', CA::key('SRC_PAY_DUE', $t1)], ['SRC_SHIP_OVERDUE', ['SH-LATE']], ['SRC_DOC_MISSING', ['DOC' . $bad, 'Invoice']], ['SRC_LOT_NO_COST', ['LOT-T1']], ['STK_NO_COST', ['LAMP-1']]] as [$c, $k]) $this->assertFalse($this->open($c, $k), $c);      // auto-closed
    }

    public function test_contacts_with_whatsapp_logged_on_the_cycle(): void
    {
        $this->world();
        $fw = $this->ins('t_src_forwarders', ['forwarder_code' => 'F-' . uniqid(), 'forwarder_name' => 'Fast Cargo', 'phone' => '0044 7700 900123']);
        $sh = $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-C', 'fk_id_forwarder' => $fw]);
        $c = collect(CA::contacts(1, $this->cy))->keyBy('name');
        $this->assertSame(['NL', 'FC'], [$c['Ningbo Bright Lights']['initials'], $c['Fast Cargo']['initials']]); $this->assertSame(['Supplier'], $c['Ningbo Bright Lights']['roles']); $this->assertSame('447700900123', $c['Fast Cargo']['whatsapp']);
        $this->assertSame('Cycle owner', collect(CA::contacts(1, $this->cy))->last()['roles'][0]);
        $r = CA::whatsapp(1, $this->cy, $c['Fast Cargo']['key'], 'When does SH-C leave?'); $this->assertStringStartsWith('https://wa.me/447700900123?text=', $r['url']);
        $this->assertStringContainsString('Fast Cargo', (string) DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $this->cy)->orderByDesc('id')->value('changes_json'));
        $o = $this->getJson("/api/cycle/{$this->cy}/overview")->assertOk()->json(); $this->assertCount(12, $o['pipeline']['steps']); $this->assertArrayHasKey('contacts', $o);
    }

    public function test_quantities_block_completion_and_show_the_claim(): void
    {
        $this->world();
        $grn = $this->ins('t_src_grn', ['fk_id_cycle' => $this->cy, 'grn_number' => 'GRN-T9', 'grn_date' => '2026-11-10']);      /* v1.7 quantities (IC-3): expected = received + missing, received = accepted + damaged */
        $line = $this->ins('t_src_grn_lines', ['fk_id_grn' => $grn, 'fk_id_product' => $this->prod, 'sku' => 'LAMP-1', 'expected_qty' => 1000, 'received_qty' => 990, 'accepted_qty' => 980, 'damaged_qty' => 10, 'missing_qty' => 10]);
        $this->ins('t_src_allocations', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'fk_id_cycle_product' => $this->cp, 'alloc_code' => 'AL-9', 'allocated_qty' => 1000]);
        $q = CA::quantities($this->cy)[0]; $this->assertSame(['Balanced', 10.0], [$q['chip'], $q['claim']], 'Balanced · claim 10');
        DB::table('t_src_grn_lines')->where('id', $line)->update(['accepted_qty' => 970]);
        try { RecordService::save('src_cycle', ['status' => 'Completed'], $this->cy); $this->fail('closed with a mismatch'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringStartsWith('Quantities do not balance – see Quantity check', $e->errors()['status'][0]); }
        DB::table('t_src_grn_lines')->where('id', $line)->update(['accepted_qty' => 980]);
        RecordService::save('src_cycle', ['status' => 'Completed'], $this->cy);
        $this->assertSame('Completed', DB::table('t_src_cycles')->where('id', $this->cy)->value('status'));
    }

    public function test_month_close_quantity_check(): void
    {
        $this->world();
        $shop = $this->ins('t_branches', ['shop_no' => 'QC1', 'shop_name' => 'Qty shop', 'iso_branch' => 'QC1']);
        DB::table('t_src_cycles')->where('id', $this->cy)->update(['fk_id_main_branch' => $shop]);
        $grn = $this->ins('t_src_grn', ['fk_id_cycle' => $this->cy, 'grn_number' => 'GRN-QC', 'grn_date' => '2026-11-10']);
        $this->ins('t_src_grn_lines', ['fk_id_grn' => $grn, 'fk_id_product' => $this->prod, 'sku' => 'LAMP-1', 'expected_qty' => 1000, 'received_qty' => 990, 'accepted_qty' => 970, 'damaged_qty' => 10, 'missing_qty' => 10]);
        $c = collect(\App\Services\CloseService::evaluate(1, $shop, '2026-11')['checks'])->keyBy('code')['QTY_BALANCED'];
        $this->assertSame('RED', $c['status']); $this->assertStringStartsWith('Quantities do not balance – see Quantity check', $c['detail']);
    }
}
