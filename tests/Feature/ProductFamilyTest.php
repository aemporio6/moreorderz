<?php

namespace Tests\Feature;

use App\Services\ProductFamily;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-1: family coding (Parent 2 / self 1, Alone 1 / self 0, child 3) and the Add defaults from the registry. */
class ProductFamilyTest extends TestCase
{
    public function test_the_rule_example(): void
    {
        $this->actingAs($this->owner);
        $mp = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'PFmp', 'f_active' => 1]));
        // Add with f_family = Parent → f_parent 2, self 1
        $p = $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => ' PF-PARENT ', 'product_name_vendor' => 'Family', 'min_level' => 0, 'f_family' => 1, 'f_group_name' => 'Lamps']])->assertOk()->json('id');
        $r = DB::table('t_product_vendors')->find($p);
        $this->assertSame([2, 1, 'Lamps', 'PF-PARENT'], [(int) $r->f_parent, (int) $r->fk_id_product_vendor_self, $r->f_group_name, $r->SKU_VENDOR]);
        // Add with f_family = Alone and no condition → condition 1, alert 1, OOS 0, purchase date empty, today, 0.00 price rows
        $a = $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'PF-ALONE', 'product_name_vendor' => 'Single', 'min_level' => 0, 'f_family' => 0, 'f_group_name' => 'X']])->assertOk()->json('id');
        $r = DB::table('t_product_vendors')->find($a);
        $this->assertSame([1, 0, null, 1, 1, 0, null], [(int) $r->f_parent, (int) $r->fk_id_product_vendor_self, $r->f_group_name, (int) $r->fk_id_product_condition, (int) $r->f_alert_min_level, (int) $r->f_out_of_stock, $r->d_purchase_date]);
        $this->assertSame(date('Y-m-d'), substr((string) $r->d_created, 0, 10));
        $this->assertSame(0.0, (float) DB::table('t_product_marketplace_prices')->where('fk_id_product', $a)->where('fk_id_marketplace', $mp)->value('sale_price'));
        $this->assertTrue(DB::table('t_product_marketplace_prices')->where('fk_id_product', $a)->where('fk_id_marketplace', $mp)->exists(), 'every marketplace row 0.00');

        // the edit form shows the switch from the code; switching the parent to Alone clears the group name
        $this->assertSame(1, $this->getJson("/api/record/t_product_vendor/$p")->assertOk()->json('values.f_parent'));
        $this->assertSame(0, $this->getJson("/api/record/t_product_vendor/$a")->json('values.f_parent'));
        $this->postJson("/api/record/t_product_vendor/$p", ['values' => ['f_family' => 0]])->assertOk();
        $this->assertSame([1, null], [(int) DB::table('t_product_vendors')->where('id', $p)->value('f_parent'), DB::table('t_product_vendors')->where('id', $p)->value('f_group_name')]);

        // a child stays a child whatever the switch says
        $c = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PF-KID', 'product_name_vendor' => 'Kid', 'f_parent' => 3, 'f_id_parent' => $p, 'f_active' => 1]));
        $this->postJson("/api/record/t_product_vendor/$c", ['values' => ['f_family' => 1]])->assertOk();
        $this->assertSame(3, (int) DB::table('t_product_vendors')->where('id', $c)->value('f_parent'));

        // checks
        $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => str_repeat('A', 51), 'product_name_vendor' => 'Long', 'min_level' => 0]])->assertStatus(422)->assertJsonPath('errors.sku_vendor.0', 'SKU can be at most 50 characters.');
        $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'PF-NEG', 'product_name_vendor' => 'Neg', 'min_level' => -1]])->assertStatus(422);
        $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'PF-LAND', 'product_name_vendor' => 'Land', 'min_level' => 0, 'landing_price' => -2]])->assertStatus(422)->assertJsonPath('errors.landing_price.0', 'Landing price must be 0.00 or more.');
        $this->assertSame(['f_parent' => 2, 'fk_id_product_vendor_self' => 1], ProductFamily::familyCode(1));
    }
}
