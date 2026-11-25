<?php

namespace Tests\Feature;

use App\Services\FcLocations;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-87 rule example: shop 201A (Amazon profile, programme FBA) → FC parent "201A FC" with children LTN4 and BHX4; an FC line confirms from their lots; DROPSHIP takes no stock; RETURNS is not available. */
class FulfilmentCentreTest extends TestCase
{
    public function test_rule_example(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amazon']);
        $wh = $f('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '201A', 'iso_branch' => '201A', 'shop_name' => 'Amazon shop', 'f_active' => 1, 'fk_id_main_location' => $wh, 'fulfilment_channel' => 'MARKETPLACE_FC']);
        DB::table('t_branches')->where('id', $shop)->update(['fk_id_mp_profile' => \App\Services\Profiles::ensureForMarketplace($mp)]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'FC-1', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $ltn = FcLocations::child($shop, 'LTN4'); $bhx = FcLocations::child($shop, 'BHX4'); $parent = FcLocations::parent($shop);
        $this->assertSame(['201A FC', 'MARKETPLACE_FULFILMENT', $parent, $parent], [DB::table('cat_inventory_locations')->where('id', $parent)->value('location_name'), DB::table('cat_inventory_locations')->where('id', $parent)->value('kind'), (int) DB::table('cat_inventory_locations')->where('id', $ltn)->value('fk_id_parent'), (int) DB::table('cat_inventory_locations')->where('id', $bhx)->value('fk_id_parent')]);
        Stock::receive(1, [['product' => $p, 'qty' => 2, 'location' => $ltn, 'unit_cost' => 3.0, 'received_date' => '2026-08-01'], ['product' => $p, 'qty' => 5, 'location' => $bhx, 'unit_cost' => 4.0, 'received_date' => '2026-08-05']], 'GRN', 1);
        $ret = Stock::systemLocation(1, 'RETURNS'); Stock::receive(1, [['product' => $p, 'qty' => 9, 'location' => $ret, 'unit_cost' => 4.0, 'received_date' => '2026-08-05']], 'GRN', 2);
        $this->assertSame(7, Stock::available($p), 'the 9 at RETURNS are not available');
        \App\Services\PriceService::set($p, $mp, 8, 20.00, '2026-08-01');
        $fc = Sales::intake($shop, [['order_no' => 'A-1', 'd_order' => '2026-08-10', 'sku' => 'FC-1', 'qty' => 3, 'sale_rate' => 20]])['ids'][0];
        $ds = Sales::intake($shop, [['order_no' => 'A-2', 'd_order' => '2026-08-10', 'sku' => 'FC-1', 'qty' => 1, 'sale_rate' => 20, 'fulfilment_channel' => 'DROPSHIP']])['ids'][0];
        $this->assertSame([$parent, 0], [(int) DB::table('t_sales')->where('id', $fc)->value('fk_id_sell_location'), (int) DB::table('wh_reservations')->where('fk_id_sale', $fc)->count()], 'an FC line sells from the FC parent and is not reserved in our warehouse');
        $this->assertSame([], Sales::confirmPreview($shop, '2026-08')['shortages']);
        Sales::confirmMonth($shop, '2026-08');
        $this->assertSame([0, 4], [Stock::balance($p, $ltn), Stock::balance($p, $bhx)], 'LTN4 (older lot) first, then BHX4');
        $this->assertSame([10.0, 0], [(float) DB::table('t_sales')->where('id', $fc)->value('cost_gbp'), (int) DB::table('fin_lot_consumption')->where('fk_id_sale', $ds)->count()], '2 × 3.00 + 1 × 4.00; DROPSHIP took no stock');
    }
}
