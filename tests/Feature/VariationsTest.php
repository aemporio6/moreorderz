<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\Variations as V;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D R-D-4: variations (children) – one grid, inheritance from the parent, resolve by id / SKU, never stock, duplicate child. */
class VariationsTest extends TestCase
{
    private int $p; private int $mp;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TEE', 'product_name_vendor' => 'T-shirt', 'f_parent' => 1, 'fk_id_vendor' => 7, 'f_active' => 1, 'landing_price' => 2, 'hs_code' => '6109']);
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'f_active' => 1]);
        DB::table('t_product_marketplace_prices')->insert(['fk_id_product' => $this->p, 'fk_id_marketplace' => $this->mp, 'raw_price' => 5, 'sale_price' => 8.25, 'list_price' => 8.25, 'marketplace_fee' => 1]);
    }

    public function test_the_rule_example_inheritance_and_resolve_by_sku(): void
    {
        $this->world();
        $r = V::save($this->p, [['sku' => 'tee-red-m', 'colour' => 'Red', 'size' => 'M', 'stock' => 50]], $this->owner); $this->assertSame([1, 0, 0], [$r['created'], $r['saved'], $r['skipped']]);
        $c = DB::table('t_product_vendors')->where('SKU_VENDOR', 'TEE-RED-M')->first();
        $this->assertSame([3, $this->p, 7, 'T-shirt', 0], [(int) $c->f_parent, (int) $c->f_id_parent, (int) $c->fk_id_vendor, $c->product_name_vendor, (int) $c->stock]);      // parent's vendor and name, stock never typed
        $row = V::grid($this->p, $this->owner)['rows'][0]['marketplaces'][$this->mp];
        $this->assertSame([5.0, false], [$row['raw_price']['value'], $row['raw_price']['own']]);      // grid shows £5 "from parent"
        $this->assertSame(0, DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $c->id)->count());
        $r = V::save($this->p, [['sku' => 'TEE-RED-M', 'colour' => 'Red', 'marketplaces' => [$this->mp => ['raw_price' => 6]]]], $this->owner);      // SKU of an existing child, no id → that child
        $this->assertSame([0, 1], [$r['created'], $r['saved']]);
        $v = DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $c->id)->first(); $this->assertSame([6.0, null], [(float) $v->raw_price, $v->sale_price]);      // only the child's own value is stored
        $row = V::grid($this->p, $this->owner)['rows'][0]['marketplaces'][$this->mp]; $this->assertSame([6.0, true, 8.25, false], [$row['raw_price']['value'], $row['raw_price']['own'], $row['sale_price']['value'], $row['sale_price']['own']]);
        V::save($this->p, [['key' => 'v' . $c->id, 'sku' => 'TEE-RED-M', 'marketplaces' => [$this->mp => ['marketplace_fee' => 1.5]]]], $this->owner);      // another field: the child's raw £6 stays
        $v = DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $c->id)->first(); $this->assertSame([6.0, 1.5], [(float) $v->raw_price, (float) $v->marketplace_fee]);
        V::save($this->p, [['key' => 'v' . $c->id, 'sku' => 'TEE-RED-M', 'marketplaces' => [$this->mp => ['raw_price' => '']]]], $this->owner); $this->assertNull(DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $c->id)->value('raw_price'));      // emptied: back to the parent's
        $this->assertSame(1, DB::table('t_product_vendors')->where('f_id_parent', $this->p)->count());
    }

    public function test_child_rules_errors_copy_remove_and_duplicate(): void
    {
        $this->world();
        V::save($this->p, [['sku' => 'TEE-1'], ['sku' => 'TEE'], ['sku' => '']], $this->owner);
        $r = V::save($this->p, [['sku' => 'TEE-2'], ['sku' => 'TEE'], ['sku' => '', 'name' => 'x']], $this->owner);
        $this->assertSame('1 created · 0 saved · 2 skipped', $r['message']); $this->assertStringContainsString('SKU TEE already exists', $r['errors'][0]); $this->assertStringContainsString('Child SKU is required', $r['errors'][1]);
        $child = (int) DB::table('t_product_vendors')->where('SKU_VENDOR', 'TEE-1')->value('id');
        try { V::save($child, [['sku' => 'X']], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('This product is a child product and child sku can not be added for this product', $e->getMessage()); }
        V::copyParentPrices($this->p, $this->owner); $this->assertSame(2, DB::table('t_variation_marketplace_prices')->where('fk_id_marketplace', $this->mp)->where('sale_price', 8.25)->count());
        $d = V::duplicate($this->p, $child, $this->owner); $this->assertSame('TEE-3', $d['card']['sku']); $this->assertSame(8.25, (float) $d['card']['marketplaces'][$this->mp]['sale_price']); $this->assertArrayNotHasKey('stock', $d['card']);      // next free suffix (TEE-2 is taken)
        V::remove($this->p, $child, $this->owner); $this->assertSame(0, (int) DB::table('t_product_vendors')->where('id', $child)->value('f_active')); $this->assertSame(0, (int) DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $child)->value('f_active'));
        $this->assertCount(1, V::grid($this->p, $this->owner)['rows']);
        $va = $this->makeUser('VA'); $this->assertFalse(V::grid($this->p, $va)['can_edit']);      // VA: read only
        try { V::save($this->p, [['sku' => 'TEE-9']], $va); $this->fail(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Http\Exceptions\HttpResponseException $e) { $this->assertTrue(true); }
        $this->assertGreaterThan(0, DB::table('sys_audit_log')->where('table_name', 't_product_vendors')->where('record_id', $child)->count());      // every child change is in the history
    }
}
