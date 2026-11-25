<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\InboundShipments as In;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-49: inbound shipment prep – reserve, pick into cartons, item and carton labels, two-step transfer, centre differences. */
class InboundShipmentsTest extends TestCase
{
    private int $shop; private int $site; private int $a; private int $b; private int $w1; private int $w2;

    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function at(int $p, int $loc): int { return Stock::balance($p, $loc); }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'INmp', 'f_active' => 1]);
        $this->site = $this->f('cat_inventory_locations', ['location_name' => 'IN site', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $this->w1 = $this->f('cat_inventory_locations', ['location_name' => 'IN-W1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $this->site, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->w2 = $this->f('cat_inventory_locations', ['location_name' => 'IN-W2', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $this->site, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 2]);
        $this->shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'IN1', 'iso_branch' => 'IN1', 'shop_name' => 'Shop IN', 'f_active' => 1, 'fulfilment_channel' => 'SELF']);
        $this->a = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'IN-A', 'product_name_vendor' => 'Item A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->b = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'IN-B', 'product_name_vendor' => 'Item B', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $this->a, 'qty' => 20, 'location' => $this->w1, 'unit_cost' => 1.0], ['product' => $this->a, 'qty' => 30, 'location' => $this->w2, 'unit_cost' => 1.2], ['product' => $this->b, 'qty' => 10, 'location' => $this->w1, 'unit_cost' => 2.0]], 'GRN', 1);
        $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $this->a, 'fk_id_branch' => $this->shop, 'fnsku' => 'X00INA0001', 'marketplace_sku' => 'MSKU-A', 'f_active' => 1]);
    }

    private function make(): int
    {
        return $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'cartons' => [
            ['items' => [['product' => $this->a, 'qty' => 24]], 'weight_kg' => 12, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 30],
            ['items' => [['product' => $this->a, 'qty' => 10], ['product' => $this->b, 'qty' => 6]], 'weight_kg' => 9.5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20]]])->assertOk()->json('id');
    }

    public function test_the_rule_example_two_cartons_pick_40_ship_to_transit_with_the_same_owners(): void
    {
        $id = $this->make();
        $this->assertSame(['SINGLE', 'MIXED'], DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->orderBy('carton_no')->pluck('carton_kind')->all());
        $r = $this->postJson("/api/inbound/$id/reserve")->assertOk()->json();
        $this->assertSame(40, $r['units'], 'pick task 40 units');
        $this->assertSame(40, (int) DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $r['task'])->sum('qty'));
        $this->assertSame(['INBOUND_SHIPMENT', 'ALLOCATED'], [DB::table('wh_reservations')->where('fk_id_fba_shipment', $id)->value('reason'), DB::table('wh_reservations')->where('fk_id_fba_shipment', $id)->value('status')]);
        $this->assertTrue(TaskEngine::isOpen('WH_INBOUND_PICK', [$id]));
        $this->assertSame(16, Stock::available($this->a), 'A: 50 on the shelves − 34 reserved'); $this->assertSame(4, Stock::available($this->b));
        $owners = DB::table('fin_lots')->whereIn('fk_id_product', [$this->a, $this->b])->pluck('fk_id_owner', 'id')->all();

        // the picker: carton label → item → qty (an item code of the listing works as well as the SKU)
        $ref = DB::table('fba_shipments')->where('id', $id)->value('shipment_ref');
        $this->postJson("/api/inbound/$id/pick", ['carton' => "$ref-C02", 'item' => 'IN-A', 'qty' => 30])->assertStatus(422)->assertJsonPath('message', 'Carton 2 needs 10 more of this item.');
        $this->postJson("/api/inbound/$id/pick", ['carton' => 'NOPE-C09', 'item' => 'IN-A', 'qty' => 1])->assertStatus(422);
        $this->postJson("/api/inbound/$id/pick", ['carton' => "$ref-C01", 'item' => 'X00INA0001', 'qty' => 24])->assertOk()->assertJsonPath('left_in_carton', 0);
        $this->postJson("/api/inbound/$id/pick", ['carton' => "$ref-C02", 'item' => 'MSKU-A', 'qty' => 10])->assertOk();
        $this->postJson("/api/inbound/$id/ship")->assertStatus(422)->assertJsonPath('message', 'Pick every carton first.');
        $last = $this->postJson("/api/inbound/$id/pick", ['carton' => "$ref-C02", 'item' => 'IN-B', 'qty' => 6])->assertOk()->json();
        $this->assertTrue($last['all_done']); $this->assertSame('PACKED', DB::table('fba_shipments')->where('id', $id)->value('status'));
        $this->assertSame(0, $this->at($this->a, $this->w1) + $this->at($this->a, $this->w2) - 16, 'bins −34 of A'); $this->assertSame(4, $this->at($this->b, $this->w1), 'bins −6 of B');

        // ship → TRANSIT +40 with the same lots (owners kept), nothing left in the Dispatch area
        $s = $this->postJson("/api/inbound/$id/ship", ['tracking' => 'TRK1'])->assertOk()->json();
        $this->assertSame(40, $s['units']);
        $transit = Stock::systemLocation(1, 'TRANSIT'); $dispatch = Stock::systemLocation(1, 'DISPATCH');
        $this->assertSame([34, 6], [$this->at($this->a, $transit), $this->at($this->b, $transit)]);
        $this->assertSame(0, $this->at($this->a, $dispatch) + $this->at($this->b, $dispatch));
        $inTransit = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $transit)->where('b.qty', '>', 0)->pluck('l.fk_id_owner', 'l.id')->all();
        foreach ($inTransit as $lot => $o) $this->assertSame($owners[$lot], $o, 'same owner');
        $this->assertSame('X00INA0001', In::itemCode(1, $this->shop, $this->a));
        $this->assertSame(['SHIPPED'], DB::table('wh_reservations')->where('fk_id_fba_shipment', $id)->distinct()->pluck('status')->all());
        $this->assertFalse(TaskEngine::isOpen('WH_INBOUND_PICK', [$id]));
        $this->assertSame(16, Stock::available($this->a), 'shipped units do not come back as free');
    }

    public function test_short_stock_refuses_and_centre_difference_opens_stk_fc_diff(): void
    {
        $big = $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'cartons' => [['items' => [['product' => $this->b, 'qty' => 11]], 'weight_kg' => 5]]])->assertOk()->json('id');
        $this->postJson("/api/inbound/$big/reserve")->assertStatus(422)->assertJsonPath('message', 'Not enough free stock: IN-B (need 11, free 10). Nothing was reserved.');
        $this->assertSame(0, DB::table('wh_reservations')->where('fk_id_fba_shipment', $big)->count());
        $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'cartons' => []])->assertStatus(422)->assertJsonPath('errors.cartons.0', 'Add at least one carton with an item');

        $fc = $this->f('cat_inventory_locations', ['location_name' => 'FC LTN4', 'kind' => 'FBA', 'fk_id_ent' => 1, 'f_sellable' => 0, 'f_active' => 1]);
        $id = $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'dest_location' => $fc, 'cartons' => [['items' => [['product' => $this->b, 'qty' => 5]], 'weight_kg' => 3]]])->assertOk()->json('id');
        $this->postJson("/api/inbound/$id/reserve")->assertOk(); $ref = DB::table('fba_shipments')->where('id', $id)->value('shipment_ref');
        $this->postJson("/api/inbound/$id/pick", ['carton' => "$ref-C01", 'item' => 'IN-B', 'qty' => 5])->assertOk();
        $this->postJson("/api/inbound/$id/ship")->assertOk();
        $r = $this->postJson("/api/inbound/$id/receive", ['received' => [$this->b => 4]])->assertOk()->json();
        $this->assertSame(['IN-B: sent 5, received 4'], $r['differences']);
        $this->assertSame([4, 1], [$this->at($this->b, $fc), $this->at($this->b, Stock::systemLocation(1, 'TRANSIT'))]);
        $this->assertTrue(TaskEngine::isOpen('STK_FC_DIFF', [$ref, 'IN-B']));
        $this->assertSame('DIFFERENCE', DB::table('fba_shipments')->where('id', $id)->value('status'));
    }

    public function test_labels_and_packing_list_print_from_the_same_rows(): void
    {
        $id = $this->make(); $this->postJson("/api/inbound/$id/reserve")->assertOk();
        $c = In::cartonLabels(1, $id);
        $this->assertSame([1, 2, 2], [$c[0]['n'], $c[0]['of'], $c[1]['n']]);
        $this->assertSame([['sku' => 'IN-A', 'goods' => 'X00INA0001', 'qty' => 10], ['sku' => 'IN-B', 'goods' => 'IN-B', 'qty' => 6]], $c[1]['items']);
        $this->assertSame(9.5, $c[1]['kg']);
        $this->assertCount(40, In::itemLabels(1, $id), 'one item label per unit');
        $th = DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $a4 = DB::table('printer_profiles')->where('code', 'SETUP_A4_1')->value('id');
        $m = DB::table('label_media')->pluck('id', 'code');
        \App\Services\PrintService::calibrate(1, $th, $m['SETUP_ROLL_100X150'], true); \App\Services\PrintService::calibrate(1, $a4, $m['SETUP_A4_PLAIN'], true);
        foreach (['FBA_CARTON' => 2, 'FBA_ITEM' => 1, 'FBA_PACKLIST' => 1] as $kind => $n) {
            $j = $this->postJson('/api/print', ['kind' => $kind, 'ids' => [$id]])->assertOk()->json();
            $this->assertSame('PRINTED', $j['status'] ?? null, $kind . ': ' . json_encode($j));
            @unlink(storage_path('app/' . $j['file']));
        }
        $p = $this->getJson("/api/inbound/$id")->assertOk()->json();
        $this->assertSame(['PICKING', 2], [$p['shipment']['status'], count($p['cartons'])]); $this->assertNotEmpty($p['lines']);
    }

    public function test_carton_file_import_both_shapes_and_three_copies_three_log_rows(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $id = $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'cartons' => [['items' => [['product' => $this->a, 'qty' => 1]], 'weight_kg' => 1]]])->assertOk()->json('id');
        // one row per carton × SKU; the item code of the listing works as SKU
        $csv = "Carton,SKU,Qty,Weight kg,Length cm,Width cm,Height cm\n1,IN-A,24,12,40,30,30\n2,X00INA0001,10,9.5,40,30,20\n2,IN-B,6,,,,\n";
        $f = \Illuminate\Http\UploadedFile::fake()->createWithContent('cartons.csv', $csv);
        $r = $this->post("/api/inbound/$id/cartons-file", ['file' => $f], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 3], [$r['cartons'], $r['lines']]);
        $c = In::cartons($id);
        $this->assertSame(['SINGLE', 'MIXED'], array_column($c, 'kind'));
        $this->assertCount(2, $c[1]['items'], 'MIXED carton with 2 SKUs → 2 carton item rows');
        $this->assertSame(9.5, $c[1]['kg']);
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'INBOUND_CARTONS_IMPORTED')->where('entity_id', $id)->exists());

        // the pack-list grid shape (Box 1 / Box 2 columns), and a bad SKU refused with its line
        $grid = "SKU,Box 1 quantity,Box 2 quantity\nIN-A,24,10\nIN-B,,6\nBox weight (kg),12,9.5\nBox length (cm),40,40\nBox width (cm),30,30\nBox height (cm),30,20\n";
        $r2 = $this->post("/api/inbound/$id/cartons-file", ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('pack.csv', $grid)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 3], [$r2['cartons'], $r2['lines']]);
        $this->assertSame(1, DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->where('carton_no', 2)->where('carton_kind', 'MIXED')->count(), 'the file replaces the cartons');
        $this->post("/api/inbound/$id/cartons-file", ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('bad.csv', "Carton,SKU,Qty,Weight\n1,NOPE,3,2\n")], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', 'Line 2: SKU NOPE not found');

        // MIXED carton → 2 pick (transfer) lines; then only drafts take a file
        $this->postJson("/api/inbound/$id/reserve")->assertOk();
        $c2 = DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->where('carton_no', 2)->value('id');
        $this->assertSame(2, DB::table('wh_pick_task_lines')->where('fk_id_fba_carton', $c2)->distinct()->count('fk_id_product'));
        $this->post("/api/inbound/$id/cartons-file", ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('late.csv', $csv)], ['Accept' => 'application/json'])->assertStatus(422);

        // print 3 copies of a carton label → 3 log rows per carton; a reprint without a reason is refused
        $th = DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $m = DB::table('label_media')->pluck('id', 'code');
        \App\Services\PrintService::calibrate(1, $th, $m['SETUP_ROLL_100X150'], true);
        $j = $this->postJson('/api/print', ['kind' => 'FBA_CARTON', 'ids' => [$id], 'copies' => 3])->assertOk()->json();
        $this->assertSame(3, DB::table('label_print_log')->where('fk_id_print_job', $j['job'])->count());
        $this->postJson('/api/print', ['kind' => 'FBA_CARTON', 'ids' => [$id]])->assertStatus(422);
        @unlink(storage_path('app/' . $j['file']));
    }
    /** R-D-87 the destination of an inbound shipment = a child of the shop's FC parent (made on first use, reused after). */
    public function test_centre_code_makes_the_fc_child_as_destination(): void
    {
        $this->actingAs($this->owner);
        $mk = fn () => $this->postJson('/api/inbound', ['shop' => $this->shop, 'from_location' => $this->site, 'centre' => ' ltn4 ', 'cartons' => [['items' => [['product' => $this->b, 'qty' => 1]], 'weight_kg' => 1]]])->assertOk()->json('id');
        $a = $mk(); $b = $mk();
        $d1 = (int) DB::table('fba_shipments')->where('id', $a)->value('fk_id_dest_location');
        $this->assertSame($d1, (int) DB::table('fba_shipments')->where('id', $b)->value('fk_id_dest_location'), 'reused');
        $l = DB::table('cat_inventory_locations')->where('id', $d1)->first();
        $this->assertSame(['LTN4', 'MARKETPLACE_FULFILMENT', \App\Services\FcLocations::parent($this->shop, false)], [$l->location_name, $l->kind, (int) $l->fk_id_parent]);
        $this->assertContains($d1, \App\Services\FcLocations::holding($this->shop, 'MARKETPLACE_FC'));
    }
}
