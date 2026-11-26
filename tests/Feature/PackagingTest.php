<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Packaging;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11 02:31: packaging worked out by itself and used by the product, a PO and the carrier box. */
class PackagingTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_packaging_is_worked_out_and_reaches_the_po(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $par = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PK-PAR', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool',
            'pack_l_mm' => 200, 'pack_w_mm' => 150, 'pack_h_mm' => 100, 'pack_weight_g' => 500, 'units_per_carton' => 100, 'carton_l' => 60, 'carton_w' => 50, 'carton_h' => 40]);
        $kid = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PK-KID', 'product_name_vendor' => 'Lamp blue', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'f_id_parent' => $par, 'f_parent' => 3, 'pack_weight_g' => 520]);
        $k = Packaging::product($kid, 950);
        $this->assertContains('carton_l', $k['from_parent'], 'a child takes the carton of its parent');
        $this->assertSame(0.12, $k['carton']['cbm']); $this->assertSame(52.0, $k['carton']['net']); $this->assertSame(55.2, $k['carton']['gross']); $this->assertTrue($k['carton']['gross_estimated']);
        $this->assertSame(20.0, $k['carton']['air_kg']);
        $this->assertSame(['full' => 9, 'tail' => 50, 'cartons' => 10], array_intersect_key($k['plan'], ['full' => 1, 'tail' => 1, 'cartons' => 1]));
        $this->assertEqualsWithDelta(9 * 55.2 + 50 * 0.52 + 3.2, $k['plan']['kg'], 0.05, 'tail carton = pcs × pack weight + empty carton');
        // the best carrier box: smallest that fits (any rotation) and carries the weight
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'RMZ', 'carrier_code' => 'RMZ', 'f_active' => 1]);
        $this->f('t_carrier_packages', ['fk_id_carrier' => $car, 'package_name' => 'Large letter', 'weight' => 0.75, 'dim_length' => 35, 'dim_width' => 25, 'dim_height' => 2.5, 'f_active' => 1]);
        $this->f('t_carrier_packages', ['fk_id_carrier' => $car, 'package_name' => 'Small parcel', 'weight' => 2, 'dim_length' => 45, 'dim_width' => 35, 'dim_height' => 16, 'f_active' => 1]);
        $this->f('t_carrier_packages', ['fk_id_carrier' => $car, 'package_name' => 'Medium parcel', 'weight' => 20, 'dim_length' => 61, 'dim_width' => 46, 'dim_height' => 46, 'f_active' => 1]);
        $this->f('t_carrier_packages', ['fk_id_carrier' => $car, 'package_name' => 'Too light', 'weight' => 0.2, 'dim_length' => 30, 'dim_width' => 30, 'dim_height' => 30, 'f_active' => 1]);
        $this->assertSame(['RMZ – Small parcel', 'RMZ – Medium parcel'], array_column(Packaging::product($kid)['best_box'], 'name'));
        // a PO: lines with cartons, a line without sizes is named
        $bare = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PK-BARE', 'product_name_vendor' => 'No sizes', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-PK-1', 'po_number' => 'PO-PK-1', 'po_date' => '2026-11-01', 'po_total' => 10, 'status' => 'CONFIRMED', 'f_active' => 1]);
        foreach ([[$kid, 'PK-KID', 950], [$bare, 'PK-BARE', 10]] as [$p, $s, $q]) $this->f('t_src_po_lines', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_product' => $p, 'sku' => $s, 'qty_ordered' => $q, 'f_active' => 1]);
        $d = $this->getJson("/api/packaging/po/$po")->assertOk()->json();
        $this->assertSame(10, $d["totals"]["cartons"]); $this->assertSame(1, $d['totals']['tails']); $this->assertSame(['PK-BARE'], $d['missing']);
        $this->getJson("/api/packaging/product/$kid?qty=100")->assertOk()->assertJsonPath('plan.cartons', 1);
    }
}
