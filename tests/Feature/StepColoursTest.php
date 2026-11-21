<?php

namespace Tests\Feature;

use App\Services\CycleAlerts as CA;
use App\Services\RecordService;
use App\Services\StepColours;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-16: step state and colour from the data (lists in config/workflows.php). */
class StepColoursTest extends TestCase
{
    private int $cy; private int $po;

    private function ins(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    private function colours(): array
    {
        $p = CA::pipeline(DB::table('t_src_cycles')->where('id', $this->cy)->first());
        return array_column($p['steps'], 'colour', 'key');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner);
        $this->cy = $this->ins('t_src_cycles', ['cycle_code' => 'CYC-SC-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $sup = $this->ins('t_src_suppliers', ['supplier_code' => 'S-' . uniqid(), 'supplier_name' => 'Test Supplier']);
        $this->po = $this->ins('t_src_po', ['po_number' => 'PO-SC-' . uniqid(), 'fk_id_cycle' => $this->cy, 'fk_id_supplier' => $sup, 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
    }

    public function test_the_rule_example(): void
    {
        $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-1', 'qc_result' => 'Passed', 'corrective_action' => 'n/a']);
        $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-2', 'qc_result' => 'Failed', 'corrective_action' => 'n/a']);
        $this->ins('t_src_samples', ['fk_id_cycle' => $this->cy, 'sample_code' => 'SMP-1', 'approval_status' => 'Approved']);
        $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-1', 'status' => 'Arrived']);
        $this->ins('t_src_shipments', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'shipment_code' => 'SH-2', 'status' => 'In Transit']);
        $c = $this->colours();
        $this->assertSame('issue', $c['qc']);        // red
        $this->assertSame('done', $c['samples']);    // green
        $this->assertSame('prog', $c['shipment']);   // amber
        $this->assertSame('done', $c['po']);
        $this->assertSame('pend', $c['customs']);    // grey
    }

    public function test_latest_qc_per_po_skipped_hatched_and_inactive_rows(): void
    {
        $f = $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-1', 'qc_result' => 'FAILED', 'corrective_action' => 'rework']);
        $this->assertSame('issue', $this->colours()['qc']);
        $this->ins('t_src_qc', ['fk_id_cycle' => $this->cy, 'fk_id_po' => $this->po, 'qc_code' => 'QC-2', 'qc_result' => 'PASSED', 're_inspection_of' => $f, 'corrective_action' => 'n/a']);
        $this->assertSame('done', $this->colours()['qc']);       // the re-inspection replaces the failed row
        DB::table('t_src_cycles')->where('id', $this->cy)->update(['skipped_steps' => 'rfq,production']);
        $c = $this->colours(); $this->assertSame('skipped', $c['rfq']); $this->assertSame('skipped', $c['production']);
        $s = $this->ins('t_src_samples', ['fk_id_cycle' => $this->cy, 'sample_code' => 'SMP-X', 'approval_status' => 'Rejected']);
        $this->assertSame('issue', $this->colours()['samples']);
        DB::table('t_src_samples')->where('id', $s)->update(['f_active' => 0]);
        $this->assertSame('pend', $this->colours()['samples']);   // only active rows count
    }

    public function test_overview_carries_colours_and_the_legend(): void
    {
        $o = $this->getJson("/api/cycle/{$this->cy}/overview")->assertOk()->json('pipeline');
        $this->assertSame(['pend', 'prog', 'done', 'issue', 'skipped'], array_column($o['legend'], 'key'));
        $this->assertSame(['#6c757d', '#ffc107', '#28a745', '#dc3545'], array_slice(array_column($o['legend'], 'colour'), 0, 4));
        $this->assertSame('Completed', collect($o['steps'])->firstWhere('key', 'po')['colour_label']);
        $this->assertSame('pend', StepColours::colour([], false));
    }
}
