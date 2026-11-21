<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-7: duplicate guard supplier + order reference, in every mode; Save anyway needs a reason kept as DUPLICATE_FORCED. */
class SrcDuplicateTest extends TestCase
{
    private int $sup; private int $sup2; private int $p; private int $wh; private int $old;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->wh = $f('cat_inventory_locations', ['location_name' => 'W1-D-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Supplier S', 'f_active' => 1]);
        $this->sup2 = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Supplier T', 'f_active' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'DUP-01', 'product_name_vendor' => 'Dup bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->old = $f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-2611-004', 'sourcing_date' => '2026-11-01', 'product_name' => 'Dup bowl', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL', 'order_ref' => 'PI-778']);
        Buy::createPo(['cycle' => $this->old, 'supplier' => $this->sup, 'currency' => 'GBP', 'lines' => [['product' => $this->p, 'qty' => 10, 'unit_price' => 2]]]);
    }

    private function body(array $x = []): array
    {
        return $x + ['supplier' => $this->sup, 'location' => $this->wh, 'date' => date('Y-m-d'), 'reference' => 'pi-778 ', 'lines' => [['product' => $this->p, 'qty' => 2, 'unit_price' => 2.0]]];
    }

    public function test_the_rule_example_warning_reason_needed_then_saved_with_history(): void
    {
        $this->getJson('/api/buying/duplicate-check?' . http_build_query(['supplier' => $this->sup, 'ref' => 'pi-778 ']))->assertOk()->assertJson(['ok' => false, 'message' => 'Already exists as SC-2611-004', 'existing' => ['id' => $this->old]]);      // live: trimmed, case-insensitive
        $this->getJson('/api/buying/duplicate-check?' . http_build_query(['supplier' => $this->sup2, 'ref' => 'PI-778']))->assertOk()->assertJson(['ok' => true]);      // other supplier: not a duplicate

        $n = DB::table('t_src_cycles')->count();
        $this->postJson('/api/quick-buy', $this->body())->assertStatus(422)->assertJson(['message' => 'Supplier + order reference already exists as SC-2611-004. Save anyway?', 'duplicate' => ['id' => $this->old, 'code' => 'SC-2611-004']]);
        $this->postJson('/api/quick-buy', $this->body(['dup_reason' => ' ']))->assertStatus(422);      // Save anyway without a reason → blocked
        $this->assertSame($n, DB::table('t_src_cycles')->count(), 'nothing saved');
        $cyc = $this->postJson('/api/quick-buy', $this->body(['dup_reason' => 'Supplier re-used the PI for a 2nd order']))->assertOk()->json('cycle');
        $this->assertSame('pi-778', DB::table('t_src_cycles')->where('id', $cyc)->value('order_ref'), 'kept clean (trimmed)');
        $h = DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $cyc)->where('action', 'DUPLICATE_FORCED')->first();
        $this->assertNotNull($h); $this->assertSame('Supplier re-used the PI for a 2nd order', $h->reason);
    }

    public function test_direct_po_full_po_and_the_cycle_and_po_forms(): void
    {
        // Direct PO with the supplier PI number
        $this->postJson('/api/po', ['supplier' => $this->sup, 'product' => $this->p, 'qty' => 5, 'unit_price' => 2, 'currency' => 'GBP', 'order_ref' => 'PI-778'])->assertStatus(422)->assertJsonPath('duplicate.code', 'SC-2611-004');
        $this->postJson('/api/po', ['supplier' => $this->sup, 'product' => $this->p, 'qty' => 5, 'unit_price' => 2, 'currency' => 'GBP', 'order_ref' => 'PI-779'])->assertOk();

        // Full PO on a new cycle: the PI number goes onto the cycle, then the guard
        $c2 = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-2611-005', 'sourcing_date' => '2026-11-02', 'product_name' => 'x', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']));
        try { Buy::createPo(['cycle' => $c2, 'supplier' => $this->sup, 'currency' => 'GBP', 'order_ref' => 'PI-778', 'lines' => [['product' => $this->p, 'qty' => 1, 'unit_price' => 1]]]); $this->fail('duplicate'); }
        catch (\App\Services\SrcDuplicateException $e) { $this->assertSame('SC-2611-004', $e->existing->cycle_code); }
        Buy::createPo(['cycle' => $c2, 'supplier' => $this->sup2, 'currency' => 'GBP', 'order_ref' => 'PI-778', 'lines' => [['product' => $this->p, 'qty' => 1, 'unit_price' => 1]]]);      // another supplier → fine

        // Cycle form: the reference of a cycle with a PO of supplier T changed to one T already has
        $c3 = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-2611-006', 'sourcing_date' => '2026-11-03', 'product_name' => 'y', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']));
        Buy::createPo(['cycle' => $c3, 'supplier' => $this->sup2, 'currency' => 'GBP', 'lines' => [['product' => $this->p, 'qty' => 1, 'unit_price' => 1]]]);
        $r = $this->postJson("/api/record/t_src_cycles/$c3", ['values' => ['order_ref' => ' PI-778']])->assertStatus(422)->json('errors');
        $this->assertSame('Supplier + order reference already exists as SC-2611-005. Save anyway?', $r['order_ref'][0]);
        $this->assertSame('SC-2611-005|' . $c2, $r['_dup'][0]);
        $this->getJson('/api/form/t_src_cycles/live?' . http_build_query(['field' => 'order_ref', 'value' => 'pi-778', 'id' => $c3]))->assertJson(['ok' => false, 'message' => 'Already exists as SC-2611-005']);
        $this->postJson("/api/record/t_src_cycles/$c3", ['values' => ['order_ref' => ' PI-778'], 'dup_reason' => 'Two shipments on one PI'])->assertOk();
        $this->assertSame('PI-778', DB::table('t_src_cycles')->where('id', $c3)->value('order_ref'));
        $this->assertSame('Two shipments on one PI', DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $c3)->where('action', 'DUPLICATE_FORCED')->value('reason'));
        $this->postJson("/api/record/t_src_cycles/$c3", ['values' => ['notes' => 'other change']])->assertOk();      // other fields: no new check
    }
}
