<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Fx;
use App\Services\RecordService;
use App\Services\ShipmentLinks as SL;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-38: shipment ↔ PO links – split and consolidation, remaining per PO, a reason above the ordered quantity, main PO. */
class ShipmentLinksTest extends TestCase
{
    private function po(int $qty): int
    {
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-' . uniqid(), 'supplier_name' => 'Sup', 'f_active' => 1]); $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'P-' . uniqid(), 'f_active' => 1]);
        $po = Buy::createPo(['supplier' => $sup, 'currency' => 'GBP', 'date' => '2026-08-01', 'lines' => [['product' => $p, 'qty' => $qty, 'unit_price' => 1]]]); Buy::confirmPo($po); return $po;
    }

    public function test_the_rule_example_split_and_reason(): void
    {
        $this->actingAs($this->owner); $po = $this->po(1000); $line = (int) DB::table('t_src_po_lines')->where('fk_id_po', $po)->value('id');
        $s1 = Buy::createShipment(['po' => $po, 'eta' => '2026-09-01', 'lines' => [['po_line' => $line, 'qty' => 600]]]); $s2 = Buy::createShipment(['po' => $po, 'eta' => '2026-09-10']);
        $g = SL::page(1, $s2); $this->assertSame([400.0, 1000.0, 600.0, 0.0, true], [$g['rows'][0]['actual'], $g['rows'][0]['ordered'], $g['rows'][0]['other_shipments'], $g['rows'][0]['remaining'], $g['rows'][0]['main']]);      // 600 + 400 → remaining 0
        $r = SL::save(1, $s2, ['po' => $po, 'planned' => 500, 'actual' => 500]); $this->assertTrue($r['need_reason']); $this->assertStringContainsString('more than the 1000 ordered', $r['message']);      // 100 more asks for a reason
        $this->assertSame(400.0, (float) DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $s2)->sum('actual_qty'));
        SL::save(1, $s2, ['po' => $po, 'planned' => 500, 'actual' => 500, 'reason' => 'Supplier sent 100 free samples']);
        $g = SL::page(1, $s2); $this->assertSame([500.0, -100.0, 'Supplier sent 100 free samples'], [$g['rows'][0]['actual'], $g['rows'][0]['remaining'], $g['rows'][0]['reason']]);
        $this->assertSame(1, DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $s2)->where('fk_id_po', $po)->count());      // one row per pair: saving again updates it
    }

    public function test_consolidation_main_po_remove_and_select_a_po(): void
    {
        $this->actingAs($this->owner); $a = $this->po(100); $b = $this->po(50);
        $s = Buy::createShipment(['po' => $a, 'eta' => '2026-09-01']);
        try { SL::save(1, $s, ['planned' => 1]); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Select a PO', $e->getMessage()); }
        SL::save(1, $s, ['po' => $b, 'planned' => 50, 'actual' => '']);      // empty actual = 0
        $g = SL::page(1, $s); $this->assertSame([2, $a], [count($g['rows']), $g['shipment']['main_po']]); $this->assertSame(0.0, $g['rows'][1]['actual']);
        SL::remove(1, $s, $a); $this->assertSame($b, (int) DB::table('t_src_shipments')->where('id', $s)->value('fk_id_po'));      // the first row left is the main PO
        SL::save(1, $s, ['po' => $a, 'planned' => 100, 'actual' => 100]); $this->assertSame(1, (int) DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $s)->where('fk_id_po', $a)->value('f_active'));      // back again
        $this->getJson("/api/shipment/$s/pos")->assertOk()->assertJsonPath('note', SL::NOTE);
    }
}
