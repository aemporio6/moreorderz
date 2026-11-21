<?php

namespace Tests\Feature;

use App\Services\GrnPrint;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-13: GRN PDF (grn_a4) and the 100 × 150 carton label. */
class GrnPrintTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_the_rule_example_and_the_labels(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-G-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id, 'drive_folder_url' => 'https://drive.example.test/folder']);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Leeds DC', 'kind' => 'WAREHOUSE']); $bin = $this->f('cat_inventory_locations', ['location_name' => 'A-01', 'kind' => 'BIN', 'fk_id_parent' => $wh]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-G-1', 'status' => 'CONFIRMED']);
        $sh = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-G-1']);
        $pl = $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'sku' => 'LMP-1', 'qty_ordered' => 1000]);
        $this->f('t_src_cartons', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'sku' => 'LMP-1', 'units_per_carton' => 400]);
        $g = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $sh, 'fk_id_warehouse' => $wh, 'grn_number' => 'GRN-G-1', 'grn_code' => 'GRN-G-1', 'grn_date' => '2026-11-20', 'received_by' => (string) $this->owner->id,
            'expected_units' => 1000, 'units_received' => 980, 'damaged_units' => 15, 'missing_units' => 5, 'expected_cartons' => 3, 'cartons_received' => 3, 'receiving_qc_status' => 'PASSED', 'notes' => 'Two cartons wet', 'claim_reference' => 'CLM-77']);
        $this->f('t_src_grn_lines', ['fk_id_grn' => $g, 'fk_id_po_line' => $pl, 'sku' => 'LMP-1', 'expected_qty' => 1000, 'received_qty' => 980, 'damaged_qty' => 15, 'missing_qty' => 5, 'fk_id_location' => $bin]);

        $d = GrnPrint::data(1, $g);
        $this->assertSame('GOODS RECEIPT NOTE — GRN-G-1', $d['title']);
        $this->assertEquals([20, '20', 1.53], [$d['summary']['shortage'], $d['summary']['shortage_text'], $d['summary']['damage_pct']]);
        $this->assertEquals(965, $d['lines'][0]['accepted']);
        $this->assertSame(['Leeds DC', 'Leeds DC › A-01', $this->owner->display_name], [$d['warehouse'], $d['lines'][0]['location'], $d['received_by']]);
        $html = GrnPrint::page(1, $g);
        foreach (['Expected Units', 'Shortage', 'Damage %', '1.53', 'Accepted', '965', 'Receiving Notes', 'Two cartons wet', 'Photos: https://drive.example.test/folder', 'Claim ref: CLM-77', 'Received By', 'Checked By', 'SHP-G-1'] as $t) $this->assertStringContainsString($t, $html);

        // over-delivery
        DB::table('t_src_grn')->where('id', $g)->update(['units_received' => 1010]);
        $this->assertSame('+10 over', GrnPrint::data(1, $g)['summary']['shortage_text']);
        DB::table('t_src_grn')->where('id', $g)->update(['units_received' => 980]);

        // labels: 980 ÷ 400 per carton → 3 cartons (400, 400, 180), "n of 3"
        $c = GrnPrint::cartons(1, $g);
        $this->assertEquals([[1, 3, 400], [2, 3, 400], [3, 3, 180]], array_map(fn ($x) => [$x['n'], $x['of'], $x['qty']], $c['cartons']));
        $lbl = GrnPrint::labels(1, $g); $this->assertCount(3, $lbl); $this->assertStringContainsString('CARTON 3 / 3', $lbl[2]); $this->assertStringContainsString('data:image/png;base64,', $lbl[0]);

        // the PDF file and the document row; made again → same row
        $x = GrnPrint::store(1, $g);
        $this->assertSame('GRN_GRN-G-1_' . date('Ymd') . '.pdf', $x['file']);
        $this->assertStringStartsWith('%PDF', $x['body']); $this->assertFileExists(GrnPrint::abs($x['path']));
        $this->assertSame($x['document_id'], GrnPrint::store(1, $g)['document_id']);
        $this->assertSame(1, DB::table('t_src_documents')->where('fk_id_grn', $g)->where('doc_type', 'GRN PDF')->count());
        $this->assertSame('application/pdf', $this->get("/api/grn/$g/pdf")->assertOk()->headers->get('Content-Type'));
        @unlink(GrnPrint::abs($x['path']));

        // R-F_SETUP-17: a GRN of another company → Not found
        DB::table('t_src_grn')->where('id', $g)->update(['fk_id_ent' => 999]);
        $this->assertSame('Not found.', $this->getJson("/api/grn/$g/pdf")->assertStatus(422)->json('message'));
    }
}
