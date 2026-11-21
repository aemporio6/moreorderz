<?php

namespace Tests\Feature;

use App\Services\CycleAlerts;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-19: stage engine (position, missing, progress) feeding the dashboard and the cycle header. */
class StageEngineTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_the_rule_example_direct_po_cycle(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_cycles')->update(['f_active' => 0]);      // only this test's cycles count on the dashboard
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-SE-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'DIRECT', 'skipped_steps' => 'rfq,samples,production,qc', 'created_by' => $this->owner->id,
            'sourcing_date' => now()->subDays(10)->toDateString(), 'created_at' => '2026-11-05 10:00:00']);
        $sup = $this->f('t_src_suppliers', ['supplier_code' => 'SE-' . uniqid(), 'supplier_name' => 'Ningbo Lamps']);
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $sup, 'product_name' => 'Desk lamp', 'target_qty' => 500]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $sup, 'po_number' => 'PO-SE-1', 'status' => 'CONFIRMED', 'po_date' => '2026-11-06']);
        $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'fk_id_cycle' => $cy, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 0, 'status' => 'PAID']);
        $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-SE-1']);

        $p = CycleAlerts::pipeline(DB::table('t_src_cycles')->where('id', $cy)->first());
        $this->assertSame('shipment', $p['last_done'], 'lastDone = 7 Shipments');
        $this->assertSame(['allocation'], $p['missing']);
        $this->assertSame('record missing: Destination Allocation', $p['missing_text']);
        $this->assertSame(['freight', 'Freight Costs'], [$p['current'], $p['current_name']]);
        $this->assertSame(38, $p['progress_pct'], 'round(3 / (12 − 4) × 100)');
        $st = collect($p['steps'])->keyBy('key');
        $this->assertSame(['skipped', 'hatched', true], [$st['rfq']['state'], $st['rfq']['strip'], $st['rfq']['done']], 'skip = done, shown hatched');
        $this->assertSame(['#0F5E63', '#16181D', '#E1E4DE', '#E8A33D'], [$st['po']['strip'], $st['freight']['strip'], $st['customs']['strip'], $st['allocation']['strip']]);
        $miss = array_values(array_filter(CycleAlerts::detect(1, $cy), fn ($r) => $r['code'] === 'SRC_STEP_MISSING'));
        $this->assertCount(1, $miss);
        $this->assertSame([$p['steps'][0]['name'], 'Destination Allocation'], ['RFQ / Quotations', $miss[0]['chip']]);

        // a second cycle with no records: current = first not-skipped stage
        $cy2 = $this->f('t_src_cycles', ['cycle_code' => 'CYC-SE2-' . uniqid(), 'product_name' => 'Fan', 'status' => 'Open', 'cycle_type' => 'FULL', 'skipped_steps' => 'rfq', 'created_by' => $this->owner->id]);
        $p2 = CycleAlerts::pipeline(DB::table('t_src_cycles')->where('id', $cy2)->first());
        $this->assertSame(['samples', 0, []], [$p2['current'], $p2['progress_pct'], $p2['missing']]);

        // the dashboard: tiles per current stage + the row fields
        $d = $this->getJson('/api/buying')->assertOk()->json();
        $tiles = collect($d['stages']['tiles'])->pluck('open', 'key');
        $this->assertSame([1, 1, 0], [$tiles['freight'], $tiles['samples'], $tiles['rfq']]);
        $row = collect($d['cycles'])->firstWhere('id', $cy);
        $this->assertStringEndsWith(' · entered 05 Nov · Open', $row['cell']);
        $this->assertSame([['Ningbo Lamps'], ['Desk lamp 500'], ['PO-SE-1'], 10, 38, 'Freight Costs'], [$row['suppliers'], $row['products'], $row['pos'], $row['days_open'], $row['progress_pct'], $row['current_name']]);
        $this->assertSame('Record missing · Destination Allocation', $row['next_action']);
        $this->assertSame(0, collect($d['cycles'])->firstWhere('id', $cy2)['days_open'], 'no sourcing date → 0');
        // the cycle header reads the same engine
        $this->assertSame(38, $this->getJson("/api/cycle/$cy")->assertOk()->json('stages.progress_pct'));

        // every stage with records → "Complete", not counted on a tile
        DB::table('t_src_cycles')->where('id', $cy)->update(['skipped_steps' => 'rfq,samples,production,qc,allocation,freight,customs,grn,landed_cost']);
        $p = CycleAlerts::pipeline(DB::table('t_src_cycles')->where('id', $cy)->first());
        $this->assertSame([null, 'Complete', 100], [$p['current'], $p['current_name'], $p['progress_pct']]);
    }
}
