<?php

namespace Tests\Feature;

use App\Services\GrnPrint;
use App\Services\PoPdf;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-11: supplier PO PDF (po_a4), versions and Send to supplier. */
class PoPdfTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_the_rule_example_totals_versions_and_send(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-PP-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $s = $this->f('t_src_suppliers', ['supplier_code' => 'NBL', 'supplier_name' => 'Ningbo Lamps', 'email' => 'sales@supplier.example.test', 'phone_whatsapp' => '+86 138 0000 1111', 'wechat' => 'nblamps', 'address' => '8 Harbour Rd', 'city' => 'Ningbo', 'f_print_cn' => 1]);
        $p = $this->f('t_product_vendors', ['SKU_VENDOR' => 'PP-' . uniqid(), 'product_name_vendor' => 'Desk lamp', 'product_name_cn' => '台灯']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-PP-1', 'po_code' => 'PO-PP-1', 'po_date' => '2026-11-01', 'status' => 'CONFIRMED', 'po_total' => 10000, 'deposit_amount' => 3000, 'balance_amount' => 7000, 'notes' => 'Pack in 5-ply cartons']);
        $l1 = $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_product' => $p, 'sku' => 'L-1', 'qty_ordered' => 500, 'unit_price' => 10, 'line_total' => 5000]);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'sku' => 'L-2', 'line_notes' => 'Floor lamp', 'qty_ordered' => 200, 'unit_price' => 15, 'line_total' => 3000]);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'sku' => 'L-3', 'line_notes' => 'Shade', 'qty_ordered' => 400, 'unit_price' => 5, 'line_total' => 2000]);
        $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'pct' => 30, 'planned_amount' => 3000, 'status' => 'DUE']);
        $html = PoPdf::page(1, $po, 1);
        foreach (['PURCHASE ORDER', 'PO TOTAL', '10,000.00', 'Deposit', '3,000.00', 'BALANCE', '7,000.00', 'Payment schedule', '台灯', 'Buyer Signature', 'Supplier Signature', 'Name: ', 'Version 1', 'Ningbo Lamps', 'WeChat nblamps', 'Pack in 5-ply cartons', 'Line Total'] as $t) $this->assertStringContainsString($t, $html);
        $this->assertSame(['PO Number', 'PO Date', 'Sourcing Cycle', 'Supplier', 'Currency', 'Incoterm', 'Payment Terms', 'Expected Production', 'Required Ship Date'], array_keys(PoPdf::data(1, $po)['meta']), 'old 3 × 3 order');

        $v1 = PoPdf::make(1, $po);
        $this->assertSame(['PO_PO-PP-1_NBL_v1.pdf', 1], [$v1['file'], $v1['version']]); $this->assertStringStartsWith('%PDF', $v1['body']);
        $this->assertSame(1, PoPdf::make(1, $po)['version'], 'the same data prints the same version');
        DB::table('t_src_po_lines')->where('id', $l1)->update(['qty_ordered' => 600]);
        $v2 = PoPdf::make(1, $po);
        $this->assertSame('PO_PO-PP-1_NBL_v2.pdf', $v2['file'], 'a second print after a qty change saves …_v2.pdf');
        $this->assertFileExists(GrnPrint::abs($v2['path']));
        $this->assertSame(2, DB::table('t_src_documents')->where('fk_id_po', $po)->where('doc_type', 'PO PDF')->count(), 'every version linked to the PO');
        $this->assertSame('application/pdf', $this->get("/api/po/$po/pdf")->assertOk()->headers->get('Content-Type'));

        $this->postJson("/api/po/$po/send", ['via' => 'EMAIL'])->assertOk()->assertJsonPath('message', 'PO PO-PP-1 v2 queued to the supplier by email (sales@supplier.example.test).');
        $this->assertSame('EMAIL', DB::table('src_po_pdf_versions')->where('fk_id_po', $po)->where('version', 2)->value('sent_via'));
        $this->postJson("/api/po/$po/send", ['via' => 'WHATSAPP'])->assertOk();
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_src_po')->where('record_id', $po)->where('reason', 'PO sent to the supplier')->exists());
        foreach (DB::table('src_po_pdf_versions')->where('fk_id_po', $po)->pluck('file_path') as $f) @unlink(GrnPrint::abs($f));
        // company scope
        DB::table('t_src_po')->where('id', $po)->update(['fk_id_ent' => 999]);
        $this->assertSame('Not found.', $this->getJson("/api/po/$po/pdf")->assertStatus(422)->json('message'));
    }
}
