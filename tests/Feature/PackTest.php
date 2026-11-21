<?php

namespace Tests\Feature;

use App\Services\DispatchService as Dispatch;
use App\Services\Ledger;
use App\Services\PackService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-29: pack verification by scan, Short back to picking, box suggestion, weight when the service needs it, packing materials as stock. */
class PackTest extends TestCase
{
    private int $p; private int $q; private int $bin; private int $shop; private int $b2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->b2 = $f('wh_box_sizes', ['fk_id_ent' => 1, 'box_code' => 'B2', 'inner_l_mm' => 220, 'inner_w_mm' => 160, 'inner_h_mm' => 120, 'max_weight_g' => 3000, 'tare_g' => 80, 'f_active' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-A', 'product_name_vendor' => 'Mug', 'ean' => '5012345678900', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'fk_id_pack_box' => $this->b2, 'pack_weight_g' => 140]);
        $this->q = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-B', 'product_name_vendor' => 'Plate', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'pack_weight_g' => 300, 'pack_l_mm' => 200, 'pack_w_mm' => 200, 'pack_h_mm' => 30]);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'P-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $car = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier P', 'f_active' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'P1', 'iso_branch' => 'P1', 'shop_name' => 'Shop P', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fk_id_default_carrier' => $car, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 10, 'location' => $this->bin, 'unit_cost' => 2.0], ['product' => $this->q, 'qty' => 10, 'location' => $this->bin, 'unit_cost' => 3.0]], 'GRN', 1);
    }

    private function picked(string $no, array $items): void
    {
        Sales::intake($this->shop, array_map(fn ($sku, $n) => ['order_no' => $no, 'd_order' => '2026-11-10', 'sku' => $sku, 'qty' => $n, 'sale_rate' => 9.99], array_keys($items), $items));
        $task = Dispatch::buildPickList(1);
        foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->pluck('id') as $l) Dispatch::pick((int) $l);
    }

    public function test_the_rule_example_three_mugs_box_b2_weight_and_packing_stock(): void
    {
        $this->picked('ORD-P1', ['SKU-A' => 3]);
        $box = $this->q;      // B2 is a packing product (a plate stands in for the box product here)
        DB::table('wh_box_sizes')->where('id', $this->b2)->update(['fk_id_packing_product' => $box]);
        Settings::set('WH_PACKING_STOCK', 'Y', 1);

        $c = $this->getJson('/api/pack/check?' . http_build_query(['order_no' => 'ORD-P1', 'branch' => $this->shop]))->assertOk()->json();
        $this->assertSame([$this->b2, 'B2', 500], [$c['suggest']['box'], $c['suggest']['box_code'], $c['suggest']['weight_g']], 'own pack box B2; 3 × 140 g + 80 g box');
        $this->assertTrue($c['scan_required']);

        $body = ['order_no' => 'ORD-P1', 'branch' => $this->shop, 'weight_g' => 420];
        $this->postJson('/api/pack', $body)->assertStatus(422)->assertJsonPath('message', 'Scan each item of the order to verify it (or switch WH_SCAN_CONFIRM_PACK off).');
        $this->postJson('/api/pack', $body + ['scans' => ['SKU-A', '5012345678900', 'SKU-B']])->assertStatus(422)->assertJsonPath('message', 'Wrong item: SKU-B is not in this order. Missing: 1 × SKU-A – scan it or press Short.');
        $this->postJson('/api/pack', $body + ['scans' => ['SKU-A', 'SKU-A', 'sku-a', '5012345678900']])->assertStatus(422)->assertJsonPath('message', 'Extra item: 1 × SKU-A more than ordered.');
        $before = Stock::balance($box, $this->bin);
        $id = $this->postJson('/api/pack', $body + ['scans' => ['SKU-A', '5012345678900', 'sku-a']])->assertOk()->json('id');
        $pc = DB::table('wh_parcels')->where('id', $id)->first();
        $this->assertSame([$this->b2, $this->b2, 420], [(int) $pc->fk_id_box, (int) $pc->box_suggested, (int) $pc->weight_g]);
        $this->assertSame(3, (int) DB::table('wh_parcel_lines')->where('fk_id_parcel', $id)->value('qty_scanned'));
        $this->assertSame($before - 1, Stock::balance($box, $this->bin), 'box −1');
        $mv = DB::table('t_stock_movements')->where('source_type', 'PACKING_USE')->where('source_id', $id)->first();
        $this->assertSame(['packing_use', 'PACKING_USE', -1], [$mv->movement_type, $mv->notes, (int) $mv->qty_change]);
        $this->assertSame(3.0, (float) $pc->packing_cost);
        $this->assertSame(3.0, Ledger::balance(1, '5200'), 'packing cost under shipping and fulfilment');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_short_goes_back_to_picking_mixed_box_and_weight_needed(): void
    {
        $this->picked('ORD-P2', ['SKU-A' => 1, 'SKU-B' => 2]);
        $sug = PackService::suggest(1, PackService::lines(1, 'ORD-P2', $this->shop));
        $this->assertSame(['BOX_S', 860], [$sug['box_code'], $sug['weight_g']], 'two SKUs: smallest WH_BOX that fits the summed volume (2 × 200×200×30 mm) and weight (140 + 2 × 300 g + 120 g box)');

        // Short: one plate is not in the tote → back to picking, the unit sits in Missing
        $this->postJson('/api/pack', ['order_no' => 'ORD-P2', 'branch' => $this->shop, 'scans' => ['SKU-A', 'SKU-B']])->assertStatus(422)->assertJsonPath('message', 'Missing: 1 × SKU-B – scan it or press Short.');
        $this->postJson('/api/pack/short', ['order_no' => 'ORD-P2', 'branch' => $this->shop, 'product' => $this->q, 'qty' => 1])->assertOk()->assertJsonPath('back_to_picking', 1);
        $sale = DB::table('t_sales')->where('order_no', 'ORD-P2')->where('fk_product_id', $this->q)->first();
        $this->assertSame(['RESERVED', 'OPEN', 1], [$sale->wh_status, DB::table('wh_reservations')->where('fk_id_sale', $sale->id)->value('status'), (int) DB::table('wh_reservations')->where('fk_id_sale', $sale->id)->value('qty_picked')]);
        $this->assertSame([1, 2], [Stock::balance($this->q, Stock::systemLocation(1, 'MISSING')), Stock::balance($this->p, Stock::systemLocation(1, 'DISPATCH')) + Stock::balance($this->q, Stock::systemLocation(1, 'DISPATCH'))]);
        $task = Dispatch::buildPickList(1); $this->assertNotNull($task, 'the line is on a new pick list');
        foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->pluck('id') as $l) Dispatch::pick((int) $l);

        // the carrier preset needs the weight
        $car = DB::table('t_branches')->where('id', $this->shop)->value('fk_id_default_carrier');
        $pk = DB::table('t_carrier_packages')->insertGetId(RecordService::fillNotNull('t_carrier_packages', ['fk_id_carrier' => $car, 'package_name' => 'Parcel', 'f_active' => 1]));
        $sv = DB::table('t_carrier_services')->insertGetId(RecordService::fillNotNull('t_carrier_services', ['fk_id_carrier' => $car, 'service_name' => 'Tracked 48', 'f_active' => 1]));
        $pre = DB::table('t_carrier_pkg_svc')->insertGetId(['pkg_svc_name' => 'Parcel Tracked 48', 'fk_id_carrier' => $car, 'fk_id_car_package' => $pk, 'fk_id_car_service' => $sv, 'rate' => 3.2, 'f_active' => 1, 'needs_weight' => 1]);
        $body = ['order_no' => 'ORD-P2', 'branch' => $this->shop, 'service' => $pre, 'scans' => ['SKU-A', 'SKU-B', 'SKU-B']];
        $this->postJson('/api/pack', $body)->assertStatus(422)->assertJsonPath('message', 'Weight is needed for this carrier service – weigh the parcel.');
        $id = $this->postJson('/api/pack', $body + ['weight_g' => 880, 'box' => $this->b2])->assertOk()->json('id');
        $this->assertSame([880, $this->b2, $pre], [(int) DB::table('wh_parcels')->where('id', $id)->value('weight_g'), (int) DB::table('wh_parcels')->where('id', $id)->value('fk_id_box'), (int) DB::table('wh_parcels')->where('id', $id)->value('fk_id_car_pkg_svc')]);      // the user may change the box
    }

    public function test_split_parcel(): void
    {
        $this->picked('ORD-P3', ['SKU-A' => 1, 'SKU-B' => 1]);
        $a = (int) DB::table('t_sales')->where('order_no', 'ORD-P3')->where('fk_product_id', $this->p)->value('id');
        $this->postJson('/api/pack', ['order_no' => 'ORD-P3', 'branch' => $this->shop, 'sales' => [$a], 'scans' => ['SKU-A', 'SKU-B']])->assertStatus(422)->assertJsonPath('message', 'Wrong item: SKU-B is not in this order.');
        $one = $this->postJson('/api/pack', ['order_no' => 'ORD-P3', 'branch' => $this->shop, 'sales' => [$a], 'scans' => ['SKU-A']])->assertOk()->json('id');
        $this->assertSame(['SKU-B'], array_column($this->getJson('/api/pack/check?' . http_build_query(['order_no' => 'ORD-P3', 'branch' => $this->shop]))->json('lines'), 'sku'), 'only the line not yet in a parcel is left');
        $two = $this->postJson('/api/pack', ['order_no' => 'ORD-P3', 'branch' => $this->shop, 'scans' => ['SKU-B']])->assertOk()->json('id');
        $this->assertNotSame($one, $two);
        $this->postJson('/api/pack', ['order_no' => 'ORD-P3', 'branch' => $this->shop, 'scans' => ['SKU-B']])->assertStatus(422);
    }
}
