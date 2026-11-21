<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductCopy;
use App\Services\RecordService;
use App\Services\Settings;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-3 Parent / Alone switch, R-D-7 out-of-stock automation, R-D-8 duplicate product, R-D-10 central (company-owned) inventory. */
class ProductsBatchTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function prod(string $sku, array $x = []): int { return $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'f_active' => 1]); }
    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_r3_parent_with_children_never_back_to_alone_and_needs_a_group_name(): void
    {
        $p = $this->prod('PA-1', ['f_parent' => 2, 'fk_id_product_vendor_self' => 1, 'f_group_name' => 'Lamps']);
        foreach (['A', 'B', 'C'] as $s) $this->prod("PA-1-$s", ['f_parent' => 3, 'f_id_parent' => $p]);
        // the rule's test: parent with 3 children, set Alone, save → refused
        $this->postJson("/api/record/t_product_vendor/$p", ['values' => ['f_family' => 0]])->assertStatus(422)->assertJsonPath('errors.f_family.0', '3 children – move or delete them first');
        $this->assertSame(2, (int) DB::table('t_product_vendors')->where('id', $p)->value('f_parent'));
        // children gone (inactive) → allowed
        DB::table('t_product_vendors')->where('f_id_parent', $p)->update(['f_active' => 0]);
        $this->postJson("/api/record/t_product_vendor/$p", ['values' => ['f_family' => 0]])->assertOk();
        $this->assertSame(1, (int) DB::table('t_product_vendors')->where('id', $p)->value('f_parent'));
        // a parent needs its group / family name
        $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'PA-2', 'product_name_vendor' => 'No group', 'min_level' => 0, 'f_family' => 1, 'f_group_name' => '']])->assertStatus(422)->assertJsonPath('errors.f_group_name.0', 'Give the group / family name of the parent.');
    }

    public function test_r7_out_of_stock_follows_the_stock_manual_tick_is_an_override_with_a_reason(): void
    {
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'OOS bin', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->prod('OOS-P', ['f_parent' => 2]); $c = $this->prod('OOS-P-1', ['f_parent' => 3, 'f_id_parent' => $p]);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayOOS', 'f_active' => 1]);
        $lst = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $c, 'fk_id_marketplace' => $mp, 'status' => 'ACTIVE', 'f_active' => 1]);
        Stock::receive(1, [['product' => $c, 'qty' => 1, 'location' => $bin, 'unit_cost' => 2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->assertSame([0, 0], [(int) DB::table('t_product_vendors')->where('id', $c)->value('f_out_of_stock'), (int) DB::table('t_product_listings')->where('id', $lst)->value('f_out_of_stock')]);
        // the rule's test: child qty 1, sale of 1 confirmed → child and its eBay listing show Out of stock
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'OOS1', 'iso_branch' => 'OO' . random_int(100, 999), 'shop_name' => 'OOS shop', 'f_active' => 1]);
        $sale = $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $c, 'order_no' => 'OOS-' . uniqid(), 'd_order' => date('Y-m-d'), 'qty' => 1, 'status' => 'Confirmed', 'sale_source' => 'active']);
        Stock::consumeForSale(1, $sale, $c, 1, $bin);
        $this->assertSame([1, 1], [(int) DB::table('t_product_vendors')->where('id', $c)->value('f_out_of_stock'), (int) DB::table('t_product_listings')->where('id', $lst)->value('f_out_of_stock')]);
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'PRODUCT_OOS')->where('entity_id', $c)->exists(), 'event PRODUCT_OOS');
        // GRN of 5 → cleared automatically
        Stock::receive(1, [['product' => $c, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2]], 'GRN', null);
        $this->assertSame([0, 0], [(int) DB::table('t_product_vendors')->where('id', $c)->value('f_out_of_stock'), (int) DB::table('t_product_listings')->where('id', $lst)->value('f_out_of_stock')]);
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'PRODUCT_BACK_IN_STOCK')->where('entity_id', $c)->exists(), 'event PRODUCT_BACK_IN_STOCK');
        // ticking by hand needs a reason; it then overrides the stock (stays out of stock after another receipt)
        $this->postJson("/api/record/t_product_vendor/$c", ['values' => ['f_out_of_stock' => 1]])->assertStatus(422)->assertJsonPath('errors.oos_reason.0', 'Give the reason for changing "Out of stock" by hand.');
        $this->postJson("/api/record/t_product_vendor/$c", ['values' => ['f_out_of_stock' => 1, 'oos_reason' => 'listing paused for photos']])->assertOk();
        Stock::receive(1, [['product' => $c, 'qty' => 2, 'location' => $bin, 'unit_cost' => 2]], 'GRN', null);
        $this->assertSame([1, 1], [(int) DB::table('t_product_vendors')->where('id', $c)->value('f_out_of_stock'), (int) DB::table('t_product_vendors')->where('id', $c)->value('oos_manual')]);
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_product_vendors')->where('record_id', $c)->where('changes_json', 'like', '%listing paused for photos%')->exists(), 'in the history');
        // unticked (with a reason) → back to what the stock says: in stock
        $this->postJson("/api/record/t_product_vendor/$c", ['values' => ['f_out_of_stock' => 0, 'oos_reason' => 'photos done']])->assertOk();
        $this->assertSame([0, null], [(int) DB::table('t_product_vendors')->where('id', $c)->value('f_out_of_stock'), DB::table('t_product_vendors')->where('id', $c)->value('oos_manual')]);
    }

    public function test_r8_duplicate_copies_the_product_never_the_stock(): void
    {
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'Dup bin', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $eb = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayDup', 'f_active' => 1]); $am = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmzDup', 'f_active' => 1]);
        $p = $this->prod('AB-100', ['f_parent' => 2, 'f_group_name' => 'Lamps', 'landing_price' => 3.2]);
        $this->prod('AB-100-RED', ['f_parent' => 3, 'f_id_parent' => $p]); $this->prod('AB-100-BLUE', ['f_parent' => 3, 'f_id_parent' => $p]);
        \App\Services\PriceService::set($p, $eb, 7.5, 9.99, '2026-01-01'); \App\Services\PriceService::set($p, $eb, 8.0, 10.99, '2026-06-01'); \App\Services\PriceService::set($p, $am, 9.0, 12.49, '2026-03-01');
        $this->f('t_product_visibility', ['fk_id_product' => $p, 'fk_id_marketplace' => $eb, 'visibility_mode' => 'POOL']);
        $inv = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Ivy', 'sur_name' => 'Investor', 'f_active' => 1]); $this->f('t_product_investors', ['fk_id_product' => $p, 'fk_id_account' => $inv]);
        $this->f('t_product_locations', ['fk_id_product' => $p, 'fk_id_location' => $bin, 'qty' => 40, 'notes' => 'top shelf']);
        Stock::receive(1, [['product' => $p, 'qty' => 40, 'location' => $bin, 'unit_cost' => 3.2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->assertSame('AB-100-2', $this->getJson("/api/products/$p/duplicate")->assertOk()->json('suggest'));
        $this->postJson("/api/products/$p/duplicate", ['sku' => ''])->assertStatus(422)->assertJsonPath('message', 'Source product and new SKU are required.');
        $this->postJson("/api/products/$p/duplicate", ['sku' => 'AB-100-RED'])->assertStatus(422)->assertJsonPath('message', 'SKU "AB-100-RED" already exists.');
        // the rule's test: duplicate AB-100 (stock 40, 2 children) to AB-200 without the option
        $r = $this->postJson("/api/products/$p/duplicate", ['sku' => 'AB-200'])->assertOk()->json(); $n = $r['id'];
        $row = DB::table('t_product_vendors')->find($n);
        $this->assertSame(['AB-200', 0, 'Lamps', 3.2, date('Y-m-d')], [$row->SKU_VENDOR, (int) $row->stock, $row->f_group_name, (float) $row->landing_price, substr((string) $row->d_created, 0, 10)]);
        $this->assertSame(0, Stock::balance($n), 'stock never copied'); $this->assertSame(0, DB::table('fin_lots')->where('fk_id_product', $n)->count());
        $this->assertSame(0, DB::table('t_product_vendors')->where('f_id_parent', $n)->count(), 'no children without the option');
        $this->assertEquals([8.0, 10.99], [(float) DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $n, 'fk_id_marketplace' => $eb])->value('raw_price'), (float) DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $n, 'fk_id_marketplace' => $eb])->value('sale_price')]);
        $tl = collect(\App\Services\PriceService::timeline($n));
        $this->assertSame([1, 1], [$tl->where('fk_id_marketplace', $eb)->count(), $tl->where('fk_id_marketplace', $am)->count()], 'one "Created" period per marketplace');
        $this->assertSame('POOL', strtoupper((string) DB::table('t_product_visibility')->where('fk_id_product', $n)->value('visibility_mode')));
        $this->assertSame($inv, (int) DB::table('t_product_investors')->where('fk_id_product', $n)->value('fk_id_account'));
        $this->assertSame([0, 'top shelf'], [(int) DB::table('t_product_locations')->where('fk_id_product', $n)->value('qty'), DB::table('t_product_locations')->where('fk_id_product', $n)->value('notes')]);
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_product_vendors')->where('record_id', $n)->where('changes_json', 'like', '%SKU AB-100 (#' . $p . ')%')->exists(), 'history: copied from');
        // with the option: the variations come too, same suffix
        $r2 = ProductCopy::duplicate($p, 'AB-300', true, $this->owner);
        $this->assertSame(['AB-300-BLUE', 'AB-300-RED'], DB::table('t_product_vendors')->where('f_id_parent', $r2['id'])->orderBy('SKU_VENDOR')->pluck('SKU_VENDOR')->all());
        // the Add right is needed
        $this->actingAs($w = $this->makeUser('WAREHOUSE'))->postJson("/api/products/$p/duplicate", ['sku' => 'AB-400'])->assertStatus(422)->assertJsonPath('message', 'You do not have add rights.');
    }

    public function test_r10_central_products_need_the_right_listing_fields_stay_open(): void
    {
        $own = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'OWN-' . random_int(100, 999), 'supplier_name' => 'Our own stock', 'f_active' => 1]);
        Settings::set('PRODUCT_DEFAULT_VENDOR', $own, 1);
        $p = $this->prod('CEN-1', ['fk_id_vendor_inventory' => $own]); $q = $this->prod('SHOP-1');
        $va = $this->makeUser('VA');
        // the rule's test: VA without products.edit_central edits the name of a central product → refused
        $this->actingAs($va)->postJson("/api/record/t_product_vendor/$p", ['values' => ['product_name_vendor' => 'New name']])->assertStatus(422)->assertJsonPath('errors._form.0', 'Cannot edit item of Local Inventory');
        $this->actingAs($va)->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'CEN-2', 'product_name_vendor' => 'x', 'min_level' => 0, 'fk_id_vendor_inventory' => $own]])->assertStatus(422)->assertJsonPath('errors.fk_id_vendor_inventory.0', 'Cannot add item of Local Inventory');
        // … editing the listing title of the same product succeeds; a shop product stays editable
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'CenMp', 'f_active' => 1]);
        $l = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_marketplace' => $mp, 'listing_title' => 'Old title', 'status' => 'ACTIVE', 'f_active' => 1]);
        Context::set(1, $va->id); RecordService::save('listing', ['title' => 'New listing title'], $l);
        $this->assertSame('New listing title', DB::table('t_product_listings')->where('id', $l)->value('listing_title'));
        $this->actingAs($va)->postJson("/api/record/t_product_vendor/$q", ['values' => ['product_name_vendor' => 'Shop item renamed']])->assertOk();
        // the Owner (has the right) edits the central product
        $this->actingAs($this->owner)->postJson("/api/record/t_product_vendor/$p", ['values' => ['product_name_vendor' => 'Owner rename']])->assertOk();
    }

    public function test_r68_default_item_protected_and_reassign_before_delete(): void
    {
        if (! DB::table('cat_product_conditions')->where('id', 1)->exists()) DB::table('cat_product_conditions')->insert(['id' => 1, 'condition_name' => 'New', 'f_active' => 1]);
        DB::table('cat_product_conditions')->where('id', 1)->update(['condition_name' => 'New', 'f_active' => 1]); Settings::set('PRODUCT_DEFAULT_CONDITION', 1, 1);
        $used = $this->f('cat_product_conditions', ['condition_name' => 'Used', 'f_active' => 1]); $ref = $this->f('cat_product_conditions', ['condition_name' => 'Refurbished', 'f_active' => 1]);
        foreach ([1, 2, 3, 4] as $i) $this->prod("COND-$i", ['fk_id_product_condition' => $used]);
        $p = $this->postJson('/api/bulk/cat_product_condition', ['action' => 'DELETE', 'ids' => [1, $used, $ref]])->assertOk()->json('rows');
        $by = collect($p)->keyBy('id');
        $this->assertSame('The default "New" condition cannot be deleted.', $by[1]['skip']);
        // the rule's test: delete condition "Used" used by 4 products → refused with the Reassign action
        $this->assertSame('This condition is used by 4 product(s). Reassign them first.', $by[$used]['skip']); $this->assertNotEmpty($by[$used]['used']);
        $this->assertNull($by[$ref]['skip']);
        // the default is never switched off either
        $this->postJson('/api/record/cat_product_condition/1', ['values' => ['f_active' => 0]])->assertStatus(422)->assertJsonPath('errors.f_active.0', 'The default "New" condition cannot be switched off.');
        // Reassign: the 4 products move to "Refurbished", then "Used" can be deleted
        $this->assertContains($ref, array_column($this->getJson("/api/bulk/cat_product_condition/reassign?from=$used")->assertOk()->json('targets'), 'id'));
        $this->postJson('/api/bulk/cat_product_condition/reassign', ['from' => $used, 'to' => $ref])->assertOk();
        $this->assertSame(4, DB::table('t_product_vendors')->where('fk_id_product_condition', $ref)->where('SKU_VENDOR', 'like', 'COND-%')->count());
        $this->assertNull(collect($this->postJson('/api/bulk/cat_product_condition', ['action' => 'DELETE', 'ids' => [$used]])->json('rows'))->first()['skip']);
        $this->postJson('/api/bulk/cat_product_condition/reassign', ['from' => $used, 'to' => $used])->assertStatus(422);
    }
}
