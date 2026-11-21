<?php

namespace Tests\Feature;

use App\Services\CycleRepeat;
use App\Services\DocRollup;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-47 (PO documents "via Payment PAY-…"), 48 (cycle product documents), 49 (link check), 4 (repeat from cycle). */
class SourcingDocsRepeatTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }
    private function cycle(): int { return $this->f('t_src_cycles', ['cycle_code' => 'CYC-SD-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id, 'priority' => 'High', 'notes' => 'Keep the box', 'required_delivery_date' => '2026-12-20', 'requested_by' => $this->owner->id]); }
    private function errors(array $v): array { try { RecordService::save(RecordService::formOfTable('t_src_documents'), $v); return []; } catch (ValidationException $e) { return array_map(fn ($m) => $m[0], $e->errors()); } }

    public function test_r47_invoice_on_a_payment_shows_on_the_po_tab_via_payment_code(): void
    {
        $this->actingAs($this->owner); $cy = $this->cycle();
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-SD-01', 'status' => 'CONFIRMED']);
        $pay = $this->f('t_src_payments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'payment_code' => 'PAY-SD-01', 'payment_amount' => 100]);
        $this->f('t_src_documents', ['fk_id_cycle' => $cy, 'fk_id_payment' => $pay, 'doc_type' => 'Invoice', 'doc_name' => 'Supplier invoice', 'drive_url' => 'https://drive.google.com/file/d/1InvoiceFileId01/view']);
        $x = $this->getJson("/api/po/$po/documents")->assertOk()->json();
        $r = collect($x['rows'])->firstWhere('name', 'Supplier invoice');
        $this->assertSame('Payment', $r['linked_via']); $this->assertSame('via Payment PAY-SD-01', $r['linked_via_detail']);
        $this->assertSame('Cycle and PO are linked automatically. File stays in Google Drive.', $x['add_note']); $this->assertSame('PO-SD-01', $x['title']);
        // add document from there: name and URL required (old alert)
        $e = $this->errors(['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'doc_type' => 'Invoice', 'doc_name' => 'No link']);
        $this->assertSame('Document Name and Google Drive URL are required', $e['_form']);
    }

    public function test_r48_spec_sheet_on_the_product_master_shows_on_the_cycle_product(): void
    {
        $this->actingAs($this->owner); $cy = $this->cycle();
        $prod = $this->f('t_product_vendors', ['SKU_VENDOR' => 'LMP-' . uniqid(), 'product_name_vendor' => 'Lamp']);
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'fk_id_product' => $prod, 'product_name' => 'Lamp', 'sku' => 'LMP-01']);
        $other = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Fan', 'sku' => 'FAN-01']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-SD-02', 'status' => 'CONFIRMED']); $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'qty_ordered' => 5]);
        $po2 = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-SD-03', 'status' => 'CONFIRMED']); $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po2, 'fk_id_cycle_product' => $other, 'qty_ordered' => 5]);
        $d = fn ($n, $t, $l) => $this->f('t_src_documents', ['fk_id_cycle' => $cy, 'doc_type' => $t, 'doc_name' => $n, 'drive_url' => 'https://drive.google.com/file/d/1' . md5($n) . '/view'] + $l);
        $d('Spec sheet', 'Spec', ['fk_id_product' => $prod]); $d('Photo', 'Photo', ['fk_id_cycle_product' => $cp]); $d('Proforma', 'PI', ['fk_id_po' => $po]); $d('Fan PI', 'PI', ['fk_id_po' => $po2]);
        $x = $this->getJson("/api/cycle-product/$cp/documents")->assertOk()->json();
        $this->assertSame([['Photo', 'Product'], ['PI', 'Purchase Order'], ['Spec', 'Product Master']], array_map(fn ($r) => [$r['type'], $r['linked_via']], $x['rows']), 'sorted by type, old labels');
        $this->assertSame('via Product Master ' . DB::table('t_product_vendors')->where('id', $prod)->value('SKU_VENDOR'), collect($x['rows'])->firstWhere('name', 'Spec sheet')['linked_via_detail']);
        $this->assertSame(['Fan PI'], array_column($this->getJson("/api/cycle-product/$other/documents")->json('rows'), 'name'), "only the other product's PO");
        $this->assertSame('No documents yet', $x['empty']);
    }

    public function test_r49_a_link_without_scheme_is_refused_and_a_bad_stored_link_is_badged(): void
    {
        $this->actingAs($this->owner); $cy = $this->cycle(); $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-SD-04', 'status' => 'CONFIRMED']);
        $this->assertSame('Must start with https://', $this->errors(['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'doc_type' => 'PI', 'doc_name' => 'PI 4', 'drive_url' => 'drive.google.com/abc'])['drive_url'] ?? null);
        $this->assertSame([], $this->errors(['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'doc_type' => 'PI', 'doc_name' => 'PI 5', 'drive_url' => 'HTTPS://drive.google.com/file/d/1GoodLinkFile05/view']), 'any case');
        $this->f('t_src_documents', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'doc_type' => 'PI', 'doc_name' => 'Old text', 'drive_url' => 'see email']);      // the old tool saved any text
        $r = collect($this->getJson("/api/po/$po/documents")->json('rows'))->firstWhere('name', 'Old text');
        $this->assertFalse($r['link_ok']); $this->assertSame('URL missing or not a valid link', $r['link_problem']); $this->assertNull($r['preview']); $this->assertNull($r['url'], 'no buttons');
        \App\Services\CycleAlerts::sweep(1, ['SRC_DOC_MISSING']);
        $this->assertTrue(DB::table('tasks')->where('dedupe_key', 'like', 'SRC_DOC_MISSING:DOC%')->where('status', '<>', 'DONE')->exists(), 'link broken → SRC_DOC_MISSING');
    }

    public function test_r4_repeat_a_cycle_with_three_products_and_two_rfqs(): void
    {
        $this->actingAs($this->owner); $src = $this->cycle();
        $sup = $this->f('t_src_suppliers', ['supplier_code' => 'SR-' . uniqid(), 'supplier_name' => 'Supplier R']);
        $prod = $this->f('t_product_vendors', ['SKU_VENDOR' => 'RP-' . uniqid(), 'product_name_vendor' => 'Lamp']);
        $a = $this->f('t_src_cycle_products', ['fk_id_cycle' => $src, 'fk_id_product' => $prod, 'fk_id_supplier' => $sup, 'product_name' => 'Lamp', 'sku' => 'LMP-01', 'target_qty' => 100, 'target_unit_cost' => 5]);
        $b = $this->f('t_src_cycle_products', ['fk_id_cycle' => $src, 'fk_id_supplier' => $sup, 'product_name' => 'Fan', 'sku' => 'FAN-01', 'target_qty' => 50, 'target_unit_cost' => 9]);
        $c = $this->f('t_src_cycle_products', ['fk_id_cycle' => $src, 'product_name' => 'Cord', 'sku' => 'CRD-01', 'target_qty' => 10, 'target_unit_cost' => 1.5]);
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $src, 'product_name' => 'Old', 'sku' => 'OLD-01', 'f_active' => 0]);      // inactive: not copied
        $po = $this->f('t_src_po', ['fk_id_cycle' => $src, 'po_number' => 'PO-RP-01', 'status' => 'CONFIRMED', 'po_date' => '2026-10-01']);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $src, 'fk_id_po' => $po, 'fk_id_cycle_product' => $a, 'fk_id_product' => $prod, 'qty_ordered' => 100, 'unit_price' => 4.2]);
        $q1 = $this->f('t_src_rfq', ['fk_id_cycle' => $src, 'fk_id_supplier' => $sup, 'rfq_code' => 'RFQ-OLD-1', 'rfq_date' => '2026-09-01', 'final_unit_price' => 8.75, 'status' => 'ACCEPTED', 'qty' => 50]);
        $this->f('t_src_rfq', ['fk_id_cycle' => $src, 'fk_id_supplier' => $sup, 'rfq_code' => 'RFQ-OLD-2', 'rfq_date' => '2026-09-02', 'status' => 'Received', 'qty' => 10]);
        $this->f('t_src_cartons', ['fk_id_cycle' => $src, 'fk_id_po' => $po, 'fk_id_cycle_product' => $a, 'config_name' => 'Default', 'units_per_carton' => 40, 'carton_qty' => 3, 'total_units' => 100, 'carton_l' => 50]);
        $x = $this->postJson("/api/cycle/$src/repeat")->assertOk()->json();
        $new = DB::table('t_src_cycles')->where('id', $x['id'])->first();
        $this->assertSame([$src, date('Y-m-d'), 'Draft', 'High', 'Keep the box', '2026-12-20'], [(int) $new->source_cycle_id, $new->sourcing_date, $new->status, $new->priority, $new->notes, $new->required_delivery_date]);
        $this->assertNotSame(DB::table('t_src_cycles')->where('id', $src)->value('cycle_code'), $new->cycle_code, 'a new code');
        $ps = DB::table('t_src_cycle_products')->where('fk_id_cycle', $x['id'])->orderBy('id')->get();
        $this->assertSame([['Lamp', 4.2], ['Fan', 8.75], ['Cord', 1.5]], $ps->map(fn ($p) => [$p->product_name, (float) $p->target_unit_cost])->all(), 'last PO line, else RFQ final price, else the old target');
        $rf = DB::table('t_src_rfq')->where('fk_id_cycle', $x['id'])->orderBy('id')->get();
        $this->assertCount(2, $rf); $this->assertSame(['Sent', 'Sent'], $rf->pluck('status')->all()); $this->assertSame([date('Y-m-d'), date('Y-m-d')], $rf->pluck('rfq_date')->all());
        $this->assertStringStartsWith('copied from RFQ-OLD-1 – re-confirm price', $rf[0]->notes); $this->assertNotSame('RFQ-OLD-1', $rf[0]->rfq_code);
        $ct = DB::table('t_src_cartons')->where('fk_id_cycle', $x['id'])->first();
        $this->assertSame([40, (int) $ps[0]->id, null], [(int) $ct->units_per_carton, (int) $ct->fk_id_cycle_product, $ct->fk_id_po === null || (int) $ct->fk_id_po === 0 ? null : $ct->fk_id_po], 'carton config copied, no PO');
        $this->assertSame("#/cycle/{$x['id']}?step=details", $x['open']);
        $this->postJson('/api/cycle/999999/repeat')->assertStatus(422);
    }
}
