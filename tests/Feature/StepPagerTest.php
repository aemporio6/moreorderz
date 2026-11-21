<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-19 ("already exists" guard on Next, the rule's example) and R-B_SOURCING-20 (step pager, skipped steps jumped). */
class StepPagerTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }
    private function cycle(string $type, string $skip): int { return $this->f('t_src_cycles', ['cycle_code' => 'CYC-PG-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => $type, 'created_by' => $this->owner->id, 'skipped_steps' => $skip]); }
    private function pg(int $cy, string $step): array { return $this->getJson("/api/cycle/$cy/pager?step=$step")->assertOk()->json(); }

    public function test_the_rule_example_po_with_one_shipment_then_save_and_next_from_allocation(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->cycle('FULL', ''); $s = $this->f('t_src_suppliers', ['supplier_code' => 'SP-' . uniqid(), 'supplier_name' => 'Supplier P']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-PG-' . uniqid(), 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
        $sh = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-PG-01']);
        $al = $this->f('t_src_allocations', ['fk_id_cycle' => $cy, 'fk_id_po' => $po]);
        $n = $this->postJson('/api/cycle/next', ['form' => 't_src_allocations', 'id' => $al])->assertOk()->json();
        $this->assertSame('t_src_shipments', $n['next']['form']);
        $this->assertSame([['id' => $sh, 'label' => 'SHP-PG-01']], $n['existing'], 'the dialog lists SHP-…-01 – Open it opens record ' . $sh);
        $this->assertSame('This PO already has 1 Shipments record (SHP-PG-01).', $n['existing_text']);
    }

    public function test_direct_cycle_pager_jumps_skipped_steps(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->cycle('DIRECT', 'rfq,samples,production,qc,shipment,freight,customs,landed_cost');
        // the rule's test: on PO lines → Next = Cartons; on Payments → Previous = Payment schedule; from PO, Previous skips Samples and RFQ to Details
        $this->assertSame('Cartons', $this->pg($cy, 'po_lines')['next']['label']);
        $this->assertSame("#/list/t_src_cartons?f[fk_id_cycle]=$cy&step=cartons", $this->pg($cy, 'po_lines')['next']['href'], 'a step without a section opens its list of this cycle');
        $this->assertSame('Payment schedule', $this->pg($cy, 'payments')['prev']['label']);
        $p = $this->pg($cy, 'po'); $this->assertSame('details', $p['prev']['key']); $this->assertSame("#/cycle/$cy?step=details", $p['prev']['href']);
        $this->assertSame('Investments', $this->pg($cy, 'payments')['next']['label'], 'Production and QC are skipped');
        $this->assertSame('2. Purchase orders', $p['current']['section'], 'PO is a section of the cycle page');
        $this->assertNull($this->pg($cy, 'details')['prev']); $this->assertNull($this->pg($cy, 'documents')['next']);
        $this->assertSame(['no' => 4, 'of' => 12], ['no' => $this->pg($cy, 'cartons')['current']['no'], 'of' => $this->pg($cy, 'cartons')['current']['of']]);
        // a FULL cycle walks every step: from PO, Previous = Samples
        $this->assertSame('Samples', $this->pg($this->cycle('FULL', ''), 'po')['prev']['label']);
        $this->getJson('/api/cycle/999999/pager?step=po')->assertNotFound();
    }
}
