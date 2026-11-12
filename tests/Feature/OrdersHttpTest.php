<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrdersHttpTest extends TestCase
{
    public function test_order_to_month_confirm_through_the_screens_api_with_roles(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $car = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier A', 'f_active' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fk_id_default_carrier' => $car, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $bin, 'unit_cost' => 4]], 'GRN', 1);
        $va = $this->makeUser('VA'); $wh = $this->makeUser('WAREHOUSE'); $tl = $this->makeUser('TL'); $src = $this->makeUser('SOURCING');
        $month = date('Y-m'); $today = date('Y-m-d');
        $order = ['branch' => $shop, 'order_no' => 'ORD-1', 'd_order' => $today, 'sku' => 'BOWL-01', 'qty' => 3, 'sale_rate' => 12.99, 'cust_name' => 'Test Buyer'];

        $this->actingAs($src)->getJson('/api/orders')->assertStatus(403);
        $this->actingAs($wh)->postJson('/api/orders', $order)->assertStatus(403);                       // warehouse does not enter orders
        $this->actingAs($va)->postJson('/api/orders', ['qty' => 0] + $order)->assertStatus(422);
        $this->postJson('/api/orders', $order)->assertOk()->assertJsonPath('new', 1);
        $this->postJson('/api/orders', ['order_no' => 'ORD-2', 'qty' => 2] + $order)->assertOk();
        $this->postJson('/api/pick-lists')->assertStatus(403);                                         // a VA does not pick
        $b = $this->actingAs($wh)->getJson('/api/orders')->assertOk()->json();
        $this->assertSame(2, $b['counts']['to_pick']);
        $cancel = collect($b['rows'])->firstWhere('order_no', 'ORD-2')['id'];
        $this->actingAs($va)->postJson("/api/orders/$cancel/cancel", [])->assertStatus(422);            // reason needed
        $this->postJson("/api/orders/$cancel/cancel", ['reason' => 'Buyer cancelled'])->assertOk();
        $this->assertSame(7, Stock::available($p));

        $task = $this->actingAs($wh)->postJson('/api/pick-lists')->assertOk()->json('id');
        $this->postJson('/api/pick-lists')->assertStatus(422)->assertJsonPath('message', 'Nothing is waiting to be picked.');
        $line = $this->getJson("/api/pick-lists/$task")->assertOk()->json('lines.0');
        $this->assertSame(['A-A-01', 3], [$line['bin'], $line['qty']]);
        $this->postJson('/api/pack', ['order_no' => 'ORD-1', 'branch' => $shop])->assertStatus(422);
        $this->postJson("/api/pick-lines/{$line['id']}")->assertOk();
        $this->postJson('/api/pack', ['order_no' => 'ORD-1', 'branch' => $shop, 'tracking_no' => 'TRK1', 'weight_g' => 500])->assertOk();
        $ses = $this->postJson('/api/handover', ['carrier' => $car])->assertOk()->json('id');
        $this->postJson("/api/handover/$ses/scan", ['code' => 'NOPE'])->assertOk()->assertJsonPath('result', 'UNKNOWN');
        $this->postJson("/api/handover/$ses/scan", ['code' => 'TRK1', 'op_id' => 'a1'])->assertOk()->assertJsonPath('result', 'OK');
        $this->postJson("/api/handover/$ses/close")->assertOk()->assertJsonPath('shipped', 1);
        $this->postJson("/api/handover/$ses/close")->assertStatus(422);

        $pre = $this->getJson("/api/confirm?branch=$shop&month=$month")->assertOk()->json();
        $this->assertArrayNotHasKey('sales_total', $pre, 'warehouse sees no sales money');
        $this->assertSame([1, 3], [$pre['to_confirm'], $pre['units_out']]);
        $this->postJson('/api/confirm', ['branch' => $shop, 'month' => $month])->assertStatus(403);
        $this->actingAs($tl)->postJson('/api/confirm', ['branch' => $shop, 'month' => $month])->assertOk()->assertJsonPath('cost', 12);
        $this->postJson('/api/confirm', ['branch' => $shop, 'month' => $month])->assertStatus(422)->assertJsonPath('message', 'This month is already confirmed.');
        $this->assertSame([38.97, 12.00], [-Ledger::balance(1, '4000'), Ledger::balance(1, '5000')]);

        $this->actingAs($wh)->postJson('/api/returns', ['code' => 'TRK1', 'qty' => 1, 'condition' => 'SELLABLE'])->assertOk()->assertJsonPath('before_confirm', false);
        $this->postJson('/api/returns', ['code' => 'WHAT-IS-THIS'])->assertOk()->assertJsonPath('matched', false);
        $this->assertCount(2, $this->getJson('/api/returns')->json('rows'));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertSame(8, Stock::balance($p));
    }
}
