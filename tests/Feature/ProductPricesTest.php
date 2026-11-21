<?php

namespace Tests\Feature;

use App\Services\ProductPrices as PP;
use App\Services\RecordService;
use App\Services\Settings;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D R-D-22 (price per marketplace, K6) with R-D-23 fees: Sale = max(0, Raw + carrier + packing), manual Sale kept, fee / margin line, price guard. */
class ProductPricesTest extends TestCase
{
    private int $p; private int $mp; private int $car; private int $pack;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PP-1', 'product_name_vendor' => 'Mug', 'landing_price' => 3]);
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'f_active' => 1]);
        $this->car = $f('t_carrier_pkg_svc', ['pkg_svc_name' => 'RM 2nd Small', 'rate' => 2.85, 'f_active' => 1]);
        $this->pack = $f('t_packings', ['fk_id_ent' => 1, 'packing_name' => 'Box S', 'packing_rate' => 0.40, 'f_active' => 1]);
    }

    public function test_the_rule_example(): void
    {
        $this->world();
        $r = PP::save($this->p, $this->mp, ['raw_price' => 5, 'fk_id_car_pkg_svc' => $this->car, 'fk_id_packing' => $this->pack], $this->owner);
        $this->assertSame(8.25, $r['row']['sale_price']); $this->assertSame('Sale = Raw £5.00 + Carrier £2.85 + Packing £0.40', $r['row']['line_sale']);
        PP::save($this->p, $this->mp, ['sale_price' => 8.99], $this->owner);      // a manual Sale
        $row = collect(PP::page($this->p, $this->owner)['rows'])->firstWhere('fk_id_marketplace', $this->mp); $this->assertSame(8.99, $row['sale_price']);      // reopening never recalculates
        PP::save($this->p, $this->mp, ['marketplace_fee' => 1.20, 'sale_price' => 8.99], $this->owner); $this->assertSame(8.99, (float) DB::table('t_product_marketplace_prices')->where('fk_id_product', $this->p)->value('sale_price'));      // other fields: Sale stays
        DB::table('t_carrier_pkg_svc')->where('id', $this->car)->update(['rate' => 2]); DB::table('t_packings')->where('id', $this->pack)->update(['packing_rate' => 0]);
        $r = PP::save($this->p, $this->mp, ['raw_price' => -10, 'fk_id_packing' => $this->pack], $this->owner);
        $this->assertSame(0.0, $r['row']['sale_price']);      // Raw −10 with rates 2 → £0.00
        $this->assertSame(1, DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $this->p, 'fk_id_marketplace' => $this->mp, 'change_type' => 'price_change', 'from_date' => date('Y-m-d')])->count());      // same-day changes: one period, the newest wins
        $this->assertSame(0.0, (float) DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $this->p, 'fk_id_marketplace' => $this->mp, 'change_type' => 'price_change'])->value('new_price'));
    }

    public function test_fee_margin_line_and_the_price_guard(): void
    {
        $this->world(); Settings::set('PRICE_TARGET_MARGIN', '0.50', 1);
        $r = PP::save($this->p, $this->mp, ['raw_price' => 5, 'fk_id_car_pkg_svc' => $this->car, 'fk_id_packing' => $this->pack, 'marketplace_fee' => 1.10, 'packing_fee' => 0.30, 'storage_fee' => 0.20], $this->owner);
        $this->assertSame('Fee £1.10 · Margin after fees £3.65 (44.2%)', $r['row']['line_fee']); $this->assertSame('Carrier £2.85 | Packing £0.40', $r['row']['line_ship']); $this->assertNull($r['warning']);      // 8.25 − 1.10 − 3.00 − 0.30 − 0.20
        $r = PP::save($this->p, $this->mp, ['sale_price' => 5.00], $this->owner);      // floor 3 + 1.10 + 0.30 + 0.20 + 0.50 = 5.10
        $this->assertStringContainsString('below the floor £5.10', $r['warning']); $this->assertTrue(TaskEngine::isOpen('PRICE_BELOW_FLOOR', [$this->p, $this->mp]));
        PP::save($this->p, $this->mp, ['sale_price' => 6.00], $this->owner); $this->assertFalse(TaskEngine::isOpen('PRICE_BELOW_FLOOR', [$this->p, $this->mp]));
        $lot = DB::table('fin_lots')->insertGetId(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'PP-1', 'fk_id_product' => $this->p, 'qty' => 5, 'qty_left' => 5, 'unit_cost_gbp' => 4.5, 'received_date' => '2026-01-01', 'created_at' => now()]));
        $this->assertSame(4.5, PP::fifo($this->p));      // FIFO cost from the oldest lot with units
        DB::table('t_carrier_pkg_svc')->where('id', $this->car)->update(['rate' => 0]); $this->assertStringContainsString('is not set (0)', PP::rates($this->car, null)['warnings'][0]);
        $this->assertStringContainsString('No carrier preset', PP::rates(null, null)['warnings'][0]);
        $shop = (int) DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '9P', 'shop_name' => 'P', 'iso_branch' => 'P9', 'f_active' => 1]));
        $this->assertSame('6.00', \App\Services\FormAssist::chips('listing', 'price', ['product' => $this->p, 'shop' => $shop])[0]['value']);      // a listing's price defaults to the Sale of its shop's marketplace
        $this->assertSame('Ebay', collect($this->getJson("/api/prices/{$this->p}/marketplaces")->assertOk()->json('rows'))->firstWhere('fk_id_marketplace', $this->mp)['marketplace']);
    }
}
