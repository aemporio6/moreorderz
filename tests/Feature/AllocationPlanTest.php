<?php

namespace Tests\Feature;

use App\Services\AllocationPlan as AP;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\RecordService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-F_SETUP-47 allocations drive the stock plan: incoming per location by ETA, proposed transfers after the GRN. */
class AllocationPlanTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_f47_incoming_by_eta_and_proposed_transfer_after_grn(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $main = $this->f('cat_inventory_locations', ['location_name' => 'Main WH 47', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $fc = $this->f('cat_inventory_locations', ['location_name' => 'Shop A FC', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-47', 'supplier_name' => 'Alloc Co', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'AL-47', 'product_name_vendor' => 'Alloc item', 'f_active' => 1]);
        $cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-47', 'product_name' => 'A', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 1, 'created_at' => now()]);
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'fk_id_product' => $p, 'product_name' => 'x']);
        // the rule: PO 1,000 units, allocations 600 to shop A's FC and 400 to main warehouse, shipment ETA in 20 days
        $eta = date('Y-m-d', strtotime('+20 days'));
        $po = Buy::createPo(['cycle' => $cy, 'supplier' => $sup, 'currency' => 'GBP', 'date' => date('Y-m-d'), 'lines' => [['product' => $p, 'qty' => 1000, 'unit_price' => 1.0]]]); Buy::confirmPo($po);
        $a1 = $this->f('t_src_allocations', ['fk_id_ent' => 1, 'alloc_code' => 'AL-1', 'fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'sku' => 'AL-47', 'fk_id_warehouse' => $fc, 'allocated_qty' => 600, 'f_active' => 1]);
        $this->f('t_src_allocations', ['fk_id_ent' => 1, 'alloc_code' => 'AL-2', 'fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'sku' => 'AL-47', 'fk_id_warehouse' => $main, 'allocated_qty' => 400, 'f_active' => 1]);
        $this->assertSame([], AP::incoming(1, $fc), 'not shipped yet → nothing on the way');
        $ship = Buy::createShipment(['po' => $po, 'eta' => $eta]); DB::table('t_src_shipments')->where('id', $ship)->update(['fk_id_warehouse' => $main]);
        // → incoming of shop A shows +600 on the ETA
        $in = AP::incoming(1, $fc); $this->assertCount(1, $in); $this->assertSame([600, $eta, 'ALLOCATION', $p], [$in[0]['qty'], $in[0]['eta'], $in[0]['basis'], $in[0]['product']]);
        $this->assertSame(400, AP::incoming(1, $main)[0]['qty']); $this->assertSame(1000, array_sum(array_column(AP::incoming(1), 'qty')), 'the allocations replace the PO total, they are not added to it');
        // GRN posts 1,000 → one proposed transfer of 600 to the FC, stock unchanged until confirmed
        $l = DB::table('t_src_po_lines')->where('fk_id_po', $po)->value('id');
        Buy::postGrn(Buy::createGrn(['shipment' => $ship, 'location' => $main, 'date' => date('Y-m-d'), 'lines' => [['po_line' => $l, 'received' => 1000, 'damaged' => 0, 'location' => $main]]]));
        $t = DB::table('src_transfer_proposals')->get(); $this->assertCount(1, $t); $this->assertSame([600, $main, $fc, 'PROPOSED', $a1], [(int) $t[0]->qty, (int) $t[0]->fk_id_from, (int) $t[0]->fk_id_to, $t[0]->status, (int) $t[0]->fk_id_allocation]);
        $this->assertSame([1000, 0], [StockService::balance($p, $main), StockService::balance($p, $fc)], 'nothing moves by itself');
        $this->assertSame(1, DB::table('tasks')->where('dedupe_key', 'SRC_ALLOC_TRANSFER:' . $t[0]->id)->count()); $this->assertSame([], AP::incoming(1), 'arrived → no longer on the way');
        $d = $this->getJson('/api/allocation-plan')->assertOk()->json(); $this->assertSame(['AL-47', 600, 'Main WH 47', 'Shop A FC', 'PROPOSED'], [$d['proposals'][0]['sku'], $d['proposals'][0]['qty'], $d['proposals'][0]['from'], $d['proposals'][0]['to'], $d['proposals'][0]['status']]);
        $this->assertSame([1000, 0], [StockService::balance($p, $main), StockService::balance($p, $fc)], 'a GET writes nothing');
        $gl = DB::table('gl_journals')->count();
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson('/api/allocation-plan/' . $t[0]->id . '/confirm')->assertStatus(403); $this->actingAs($this->owner);
        $this->postJson('/api/allocation-plan/' . $t[0]->id . '/dismiss', ['reason' => ''])->assertStatus(422);
        $this->postJson('/api/allocation-plan/' . $t[0]->id . '/confirm')->assertOk()->assertJsonPath('message', '600 unit(s) moved.');
        $this->assertSame([400, 600], [StockService::balance($p, $main), StockService::balance($p, $fc)]); $this->assertSame(600, (int) DB::table('t_src_allocations')->where('id', $a1)->value('dispatched_qty'));
        $this->assertSame($gl, DB::table('gl_journals')->count(), 'a move between our own locations writes no ledger entry');
        $this->assertSame(0, DB::table('tasks')->where('dedupe_key', 'SRC_ALLOC_TRANSFER:' . $t[0]->id)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count());
        $this->postJson('/api/allocation-plan/' . $t[0]->id . '/confirm')->assertStatus(422);
    }
}
