<?php

namespace Tests\Feature;

use App\Services\DocRollup;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-10 PO documents roll-up and R-F_SETUP-1 sourcing reports hub pickers. */
class DocRollupTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_po_roll_up_merges_paths_and_narrows_grns(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-D-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-DX', 'status' => 'CONFIRMED']); $other = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-DY', 'status' => 'CONFIRMED']);
        $sh = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-DX']); $sh2 = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $other, 'shipment_code' => 'SHP-DY']);
        $prod = $this->f('t_product_vendors', ['SKU_VENDOR' => 'DOC-' . uniqid(), 'product_name_vendor' => 'Lamp']);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_product' => $prod, 'qty_ordered' => 10]);
        $g1 = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $sh, 'grn_number' => 'GRN-DX']); $g2 = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $sh2, 'grn_number' => 'GRN-DY']); $g3 = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'grn_number' => 'GRN-NOSHIP']);
        $doc = fn (string $type, string $name, array $links) => $this->f('t_src_documents', ['fk_id_cycle' => $cy, 'doc_type' => $type, 'doc_name' => $name, 'created_by' => $this->owner->id] + $links);
        $pl = $doc('Packing list', 'PL 1', ['fk_id_po' => $po, 'fk_id_shipment' => $sh, 'drive_url' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view']);      // the rule example: once, "Direct PO, Shipment"
        $doc('GRN', 'GRN of this PO', ['fk_id_grn' => $g1]); $doc('GRN', 'GRN of the other PO', ['fk_id_grn' => $g2]); $doc('GRN', 'GRN without shipment', ['fk_id_grn' => $g3, 'drive_url' => 'https://drive.google.com/open?id=ZZyyXXwwVVuu11']);
        $doc('Spec', 'Product spec', ['fk_id_product' => $prod, 'drive_url' => 'https://example.test/spec.pdf']);
        $doc('PI', 'Other PO PI', ['fk_id_po' => $other]);

        $x = $this->getJson("/api/po/$po/documents")->assertOk()->json();
        $names = array_column($x['rows'], 'name');
        $this->assertSame(1, count(array_keys($names, 'PL 1')), 'a document reached by two paths shows once');
        $row = collect($x['rows'])->firstWhere('name', 'PL 1');
        $this->assertSame('Direct PO, Shipment', $row['linked_via']);
        $this->assertSame('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/preview', $row['preview']);
        $this->assertSame($this->owner->display_name, $row['uploaded_by']);
        $this->assertContains('GRN of this PO', $names); $this->assertContains('GRN without shipment', $names);
        $this->assertNotContains('GRN of the other PO', $names, 'GRNs narrowed to the PO\'s shipments (+ the cycle\'s GRNs without shipment)');
        $this->assertNotContains('Other PO PI', $names);
        $this->assertSame('Product Master', collect($x['rows'])->firstWhere('name', 'Product spec')['linked_via']);
        $this->assertSame('https://example.test/spec.pdf', collect($x['rows'])->firstWhere('name', 'Product spec')['preview'], 'no Drive id → the link itself');
        $this->assertSame('https://drive.google.com/file/d/ZZyyXXwwVVuu11/preview', collect($x['rows'])->firstWhere('name', 'GRN without shipment')['preview']);
        $this->assertSame(['GRN', 'GRN', 'Packing list', 'Spec'], array_column($x['rows'], 'type'), 'sorted by type');
        $this->assertSame(['fk_id_po' => $po, 'fk_id_cycle' => $cy], $x['add_preset']);
        $this->assertSame(DocRollup::NOTE, $x['note']);

        // cycle: every stage; product: master + its cycles
        $this->assertCount(6, $this->getJson("/api/cycle/$cy/documents")->assertOk()->json('rows'));
        $this->assertContains('PL 1', array_column(DocRollup::forProduct(1, $prod)['rows'], 'name'));
        // company scope
        DB::table('t_src_po')->where('id', $other)->update(['fk_id_ent' => 999]);
        $this->assertSame('Not found.', $this->getJson("/api/po/$other/documents")->assertStatus(422)->json('message'));
    }

    public function test_hub_pickers(): void
    {
        $this->actingAs($this->owner);
        $old = $this->f('t_src_cycles', ['cycle_code' => 'VEEU-26-001', 'product_name' => 'Fan', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $new = $this->f('t_src_cycles', ['cycle_code' => 'VEEU-26-002', 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $this->f('t_src_cycles', ['cycle_code' => 'VEEU-26-003', 'product_name' => 'Old', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 0, 'created_by' => $this->owner->id]);
        $this->f('t_src_cycles', ['cycle_code' => 'VEEU-26-004', 'product_name' => 'Other co', 'status' => 'Open', 'cycle_type' => 'FULL', 'fk_id_ent' => 999, 'created_by' => $this->owner->id]);
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $old, 'product_name' => 'Desk fan', 'sku' => 'FAN-XYZ']);
        $this->assertSame(['VEEU-26-002', 'VEEU-26-001'], array_column($this->getJson('/api/src-pick/cycles?q=VEEU-26')->assertOk()->json('rows'), 'code'), 'active cycles of the company, newest first');
        $this->assertSame(['VEEU-26-001'], array_column($this->getJson('/api/src-pick/cycles?q=FAN-XYZ')->json('rows'), 'code'), 'search on SKU of a cycle product');
        $p1 = $this->f('t_src_po', ['fk_id_cycle' => $old, 'po_number' => 'PO-HUB-1', 'status' => 'DRAFT']); $this->f('t_src_po', ['fk_id_cycle' => $new, 'po_number' => 'PO-HUB-2', 'status' => 'DRAFT']);
        $this->assertSame(['PO-HUB-1'], array_column($this->getJson("/api/src-pick/pos?cycle=$old&q=PO-HUB")->json('rows'), 'code'), 'filtered by the chosen cycle');
        $this->assertCount(2, $this->getJson('/api/src-pick/pos?q=PO-HUB')->json('rows'));
    }
}
