<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\TaskEngine;
use App\Services\ThreePlTariff;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-23: shipping per marketplace incl. 3PL fees – company tariff defaults, ≥ 0 at 2 dp, per order / per unit, PRD_FEE_MISSING self-check. */
class ThreePlTariffTest extends TestCase
{
    private int $ebay; private int $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->ebay = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayT', 'f_active' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TPL-01', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
    }

    public function test_the_rule_example_tariff_fills_new_rows_and_the_self_check_lists_sold_products_with_no_fee(): void
    {
        $this->postJson("/api/tariff-3pl/{$this->ebay}", ['packing_fee' => '0.35', 'packing_fee_per' => 'ORDER', 'storage_fee' => '0.05', 'storage_fee_per' => 'UNIT'])->assertOk();
        $this->postJson("/api/tariff-3pl/{$this->ebay}", ['packing_fee' => 'abc'])->assertStatus(422);
        $row = collect($this->getJson("/api/prices/{$this->p}/marketplaces")->assertOk()->json('rows'))->firstWhere('fk_id_marketplace', $this->ebay);
        $this->assertSame([0.35, 0.05, 'TARIFF'], [(float) $row['packing_fee'], (float) $row['storage_fee'], $row['fee_source']], "new product's eBay row shows 0.35 / 0.05");
        $this->postJson("/api/prices/{$this->p}/marketplace/{$this->ebay}", ['raw_price' => 10])->assertOk();
        $s = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $this->p)->where('fk_id_marketplace', $this->ebay)->first();
        $this->assertSame([0.35, 0.05, 'TARIFF'], [(float) $s->packing_fee, (float) $s->storage_fee, $s->fee_source], 'saved row keeps the tariff fees');
        $this->postJson("/api/prices/{$this->p}/marketplace/{$this->ebay}", ['packing_fee' => '-2', 'storage_fee' => '0.126'])->assertOk();
        $s = DB::table('t_product_marketplace_shipping')->where('id', $s->id)->first();
        $this->assertSame([0.0, 0.13, 'TYPED'], [(float) $s->packing_fee, (float) $s->storage_fee, $s->fee_source], 'clamped to ≥ 0 at 2 dp; typed wins');

        // per order / per unit: 3 units → packing 0.35 once + storage 0.05 × 3
        $this->assertSame(0.5, ThreePlTariff::feeCost(1, $this->ebay, 0.35, 0.05, 3));

        // the generic marketplace shipping form starts a new row from the tariff too
        $q = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TPL-02', 'product_name_vendor' => 'Shade', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']));
        $car = DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier T', 'f_active' => 1]));
        $pre = DB::table('t_carrier_pkg_svc')->insertGetId(['pkg_svc_name' => 'Large letter 2nd', 'fk_id_carrier' => $car, 'fk_id_car_package' => 0, 'fk_id_car_service' => 0, 'rate' => 1.5, 'f_active' => 1]);
        $id = RecordService::save('product_mp_shipping', ['fk_id_product' => $q, 'fk_id_marketplace' => $this->ebay, 'fk_id_car_pkg_svc' => $pre])['id'];
        $this->assertSame([0.35, 0.05], [(float) DB::table('t_product_marketplace_shipping')->where('id', $id)->value('packing_fee'), (float) DB::table('t_product_marketplace_shipping')->where('id', $id)->value('storage_fee')]);

        // self-check: a sold product with 0 fees → PRD_FEE_MISSING; filled → closed
        $mp2 = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmzT', 'f_active' => 1]));
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp2, 'shop_no' => 'T1', 'iso_branch' => 'T1', 'shop_name' => 'Shop T', 'f_active' => 1]));
        DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'order_no' => 'T-1', 'd_order' => date('Y-m-d'), 'sku' => 'TPL-01', 'fk_product_id' => $this->p, 'qty' => 1, 'sale_rate' => 10, 'status' => 'Pending', 'sale_source' => 'active']));
        ThreePlTariff::missingTasks(1);
        $this->assertTrue(TaskEngine::isOpen('PRD_FEE_MISSING', [$this->p]), 'sold on AmzT with no packing / storage fee');
        $this->assertStringContainsString('TPL-01 on AmzT', (string) DB::table('tasks')->where('dedupe_key', 'PRD_FEE_MISSING:' . $this->p)->value('title'));
        $this->postJson("/api/prices/{$this->p}/marketplace/$mp2", ['raw_price' => 10, 'packing_fee' => 0.4])->assertOk();
        ThreePlTariff::missingTasks(1);
        $this->assertFalse(TaskEngine::isOpen('PRD_FEE_MISSING', [$this->p]), 'closes by itself when filled');
        $this->assertSame(0, $this->getJson('/api/tariff-3pl')->assertOk()->json('missing'));
    }
}
