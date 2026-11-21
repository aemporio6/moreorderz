<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SourcingInherit;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-21: supplier of the cycle (latest PO, else RFQ) and SKU from the cycle product in the step forms. */
class SourcingInheritTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_the_rule_example_rfq_a_po_b_qc_gets_b_and_sku_read_only(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-IN-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $a = $this->f('t_src_suppliers', ['supplier_code' => 'SA-' . uniqid(), 'supplier_name' => 'Supplier A']); $b = $this->f('t_src_suppliers', ['supplier_code' => 'SB-' . uniqid(), 'supplier_name' => 'Supplier B']);
        $this->f('t_src_rfq', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $a, 'rfq_code' => 'RFQ-IN', 'status' => 'QUOTED']);
        $this->assertSame($a, SourcingInherit::cycleSupplier($cy), 'no PO yet → the RFQ supplier');
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $b, 'po_number' => 'PO-IN-' . uniqid(), 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
        $this->assertSame([$b, $a], SourcingInherit::suppliers($cy));

        // new QC form of the cycle → supplier B (filled because empty); a typed supplier is kept
        $qc = $this->postJson('/api/record/t_src_qc', ['values' => ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-IN-1']])->assertOk()->json('id');
        $this->assertSame($b, (int) DB::table('t_src_qc')->where('id', $qc)->value('fk_id_supplier'));
        $qc2 = $this->postJson('/api/record/t_src_qc', ['values' => ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-IN-2', 'fk_id_supplier' => $a]])->assertOk()->json('id');
        $this->assertSame($a, (int) DB::table('t_src_qc')->where('id', $qc2)->value('fk_id_supplier'));
        // chips: both suppliers of the cycle, B first
        $chips = $this->getJson("/api/suggest/t_src_qc/fk_id_supplier?" . http_build_query(['form' => ['fk_id_cycle' => $cy]]))->assertOk()->json('chips');
        $this->assertSame([(string) $b, (string) $a], array_column($chips, 'value'));

        // pick product "Lamp" → SKU LMP-01, read-only (a typed SKU is ignored); cleared with the product
        $p = $this->f('t_product_vendors', ['SKU_VENDOR' => 'LMP-01', 'product_name_vendor' => 'Lamp']);
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'fk_id_product' => $p, 'product_name' => 'Lamp']);
        $c = $this->postJson('/api/record/t_src_cartons', ['values' => ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'sku' => 'TYPED', 'units_per_carton' => 10]])->assertOk()->json('id');
        $this->assertSame('LMP-01', DB::table('t_src_cartons')->where('id', $c)->value('sku'));
        $this->assertSame(1, (int) DB::table('sys_registry')->where('form_key', 't_src_cartons')->where('column_name', 'sku')->value('readonly'));
        $this->postJson("/api/record/t_src_cartons/$c", ['values' => ['fk_id_cycle_product' => '']])->assertOk();
        $this->assertNull(DB::table('t_src_cartons')->where('id', $c)->value('sku'), 'cleared when the product is cleared');
    }

    public function test_payment_takes_the_po_supplier(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-IP-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $a = $this->f('t_src_suppliers', ['supplier_code' => 'PA-' . uniqid(), 'supplier_name' => 'Old PO supplier']); $b = $this->f('t_src_suppliers', ['supplier_code' => 'PB-' . uniqid(), 'supplier_name' => 'New PO supplier']);
        $old = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $a, 'po_number' => 'PO-PA-' . uniqid(), 'status' => 'CONFIRMED', 'po_date' => '2026-10-01']);
        $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $b, 'po_number' => 'PO-PB-' . uniqid(), 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
        $data = ['fk_id_cycle' => $cy, 'fk_id_po' => $old]; SourcingInherit::check('t_src_payments', $data, []);
        $this->assertSame($a, $data['fk_id_supplier'], 'the chosen PO\'s supplier, not the cycle\'s latest');
        $this->assertSame('the supplier of the chosen PO', SourcingInherit::chips('t_src_payments', ['fk_id_cycle' => $cy, 'fk_id_po' => $old])[0]['why']);
    }
}
