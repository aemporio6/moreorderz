<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\WorkflowContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-18: "Save & Next" opens the next step's form with the carried parents locked; context kept per cycle and user. */
class WorkflowContextTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    private function cycle(string $skip = ''): array
    {
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-WF-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id, 'skipped_steps' => $skip]);
        $s = $this->f('t_src_suppliers', ['supplier_code' => 'SW-' . uniqid(), 'supplier_name' => 'Supplier W']);
        return [$cy, $s];
    }

    public function test_the_rule_example_po_then_po_lines_and_shipment_then_freight(): void
    {
        $this->actingAs($this->owner);
        [$cy, $s] = $this->cycle();
        $num = 'PO-WF-' . uniqid() . '-01';
        $po = $this->postJson('/api/record/t_src_po', ['values' => ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => $num, 'po_date' => '2026-11-01', 'required_ship_date' => '2026-12-01', 'status' => 'DRAFT']])->assertOk()->json('id');
        $this->assertSame(['index' => 3, 'key' => 'po', 'label' => 'Purchase order', 'next' => 'PO lines'], $this->getJson('/api/form/t_src_po')->assertOk()->json('workflow'));

        // Save & Next → the PO lines form, PO = the saved PO, locked; the server gives the new id (no "last id" guess)
        $n = $this->postJson('/api/cycle/next', ['form' => 't_src_po', 'id' => $po])->assertOk()->json();
        $this->assertSame('t_src_po_lines', $n['next']['form']);
        $this->assertSame($po, $n['preset']['fk_id_po']);
        $this->assertSame($cy, $n['preset']['fk_id_cycle']);
        $this->assertContains('fk_id_po', $n['locked']);
        $this->assertSame([], $n['existing']);
        \App\Services\Choices::set('SAVE_NEXT_LOCK', '0', 1);      // owner rule: Settings › Choices – filled but free to change
        $this->assertSame([[], $po], [$this->postJson('/api/cycle/next', ['form' => 't_src_po', 'id' => $po])->assertOk()->json('locked'), $this->postJson('/api/cycle/next', ['form' => 't_src_po', 'id' => $po])->json('preset.fk_id_po')]);
        \App\Services\Choices::set('SAVE_NEXT_LOCK', '1', 1);
        $this->assertSame($po, WorkflowContext::get($cy, $this->owner->id)['po'], 'the context is kept server-side per cycle and user');
        $this->assertSame([], WorkflowContext::get($cy, $this->owner->id + 999), 'not for another user');

        // a shipment of the PO → Save & Next opens Freight with that shipment and PO
        $sh = $this->postJson('/api/record/t_src_shipments', ['values' => ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-WF-' . uniqid(), 'ship_date' => '2026-11-05']])->assertOk()->json('id');
        $n = $this->postJson('/api/cycle/next', ['form' => 't_src_shipments', 'id' => $sh])->assertOk()->json();
        $this->assertSame('t_src_freight', $n['next']['form']);
        $this->assertSame($sh, $n['preset']['fk_id_shipment']);
        $this->assertContains('fk_id_shipment', $n['locked']);
        $ctx = WorkflowContext::get($cy, $this->owner->id);
        $this->assertSame([$po, $sh, $s], [$ctx['po'], $ctx['shipment'], $ctx['supplier']]);

        // a new step form opened inside the cycle takes the parents from the memory (GRN form ← the shipment)
        $x = $this->getJson("/api/cycle/$cy/context?form=t_src_grn")->assertOk()->json();
        $this->assertSame($sh, $x['preset']['fk_id_shipment']);
        $this->assertSame('Inventory receiving', $x['next']['label'], 'the button names the real next step of this cycle');

        // QC → the next step is Investments (no records yet)
        $n = $this->postJson('/api/cycle/next', ['form' => 't_src_qc', 'id' => $this->f('t_src_qc', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-WF'])])->assertOk()->json();
        $this->assertSame('t_src_investments', $n['next']['form']);
    }

    public function test_skipped_steps_are_jumped_and_existing_records_named(): void
    {
        $this->actingAs($this->owner);
        [$cy, $s] = $this->cycle('freight,customs');
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-WS-' . uniqid(), 'status' => 'DRAFT', 'po_date' => '2026-11-01']);
        $sh = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-WS-1']);
        $grn = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $sh, 'grn_number' => 'GRN-WS-1']);
        $n = $this->postJson('/api/cycle/next', ['form' => 't_src_shipments', 'id' => $sh])->assertOk()->json();
        $this->assertSame('t_src_grn', $n['next']['form'], 'Freight and Customs are skipped in this cycle');
        $this->assertSame([['id' => $grn, 'label' => 'GRN-WS-1']], $n['existing']);
        $this->assertSame('This shipment already has 1 Landing / GRN record (GRN-WS-1).', $n['existing_text']);
        // the last step
        $d = $this->f('t_src_documents', ['fk_id_cycle' => $cy]);
        $this->assertNull($this->postJson('/api/cycle/next', ['form' => 't_src_documents', 'id' => $d])->assertOk()->json('next'));
        // a record outside a cycle
        $this->postJson('/api/cycle/next', ['form' => 't_src_documents', 'id' => $this->f('t_src_documents', [])])->assertStatus(422);
    }
}
