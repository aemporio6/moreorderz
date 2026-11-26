<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-60 the old per-shop product rows become listings. */
class LegacyListingsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_d60_old_shop_rows_become_listings(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz60', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'L60', 'iso_branch' => 'L60', 'shop_name' => 'Old shop', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LEG-1', 'product_name_vendor' => 'Legacy one', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $old = fn (array $d) => $this->f('t_products_branch', $d + ['fk_id_branch' => $shop, 'product_name_local' => 'Old title ' . $d['sku_ebay'], 'fk_id_product_type' => 3, 'sale_price_ebay' => 9.99, 'fk_id_vendor' => 7, 'pur_price' => 3.10, 'f_active' => 1, 'fk_id_product_main' => 0]);
        // the rule: row with pur_price £3.10, asin B0ABC → listing expected cost £3.10, item id "B0ABC"
        $a = $old(['sku_ebay' => 'EB-1', 'sku_vendor' => 'LEG-1', 'asin_amazon' => 'B0ABC', 'url_ebay_product' => 'https://example.test/i/1', 'url_vendor_product' => 'https://example.test/v/1', 'ref_vendor_product' => 'REF9', 'fk_id_carrier' => 4]);
        $b = $old(['sku_ebay' => 'NOPE-1', 'sku_vendor' => 'NOPE-1']);                       // no product → the shop line makes it, private to its shop (owner 26-11 23:14)
        $c = $old(['sku_ebay' => 'EB-3', 'sku_vendor' => 'LEG-1', 'fk_id_vendor' => 0]);     // vendor missing
        $d = $old(['sku_ebay' => 'EB-4', 'sku_vendor' => 'LEG-1', 'asin_amazon' => 'b0abc']); // same item id in the same shop
        $n = DB::table('t_product_listings')->count();
        $dry = $this->getJson('/api/product-imports/legacy-listings')->assertOk()->json();
        $this->assertSame([false, 2, 1, 2, $n], [$dry['applied'], $dry['ready'], $dry['new_products'], count($dry['errors']), DB::table('t_product_listings')->count()], 'a dry run writes nothing');
        $this->assertFalse(DB::table('t_product_vendors')->where('SKU_VENDOR', 'NOPE-1')->exists());
        $why = collect($dry['errors'])->pluck('reason', 'row');
 $this->assertSame('Required: Vendor', $why[$c]); $this->assertSame('Item id b0abc is already a listing of this shop', $why[$d]);
        $mgr = $this->makeUser('MANAGER'); $this->actingAs($mgr)->postJson('/api/product-imports/legacy-listings')->assertStatus(422)->assertJsonPath('message', 'Only the Owner moves the old shop rows.');
        $r = $this->actingAs($this->owner)->postJson('/api/product-imports/legacy-listings')->assertOk()->json(); $this->assertSame([2, 1], [$r['created'], $r['products_made']]);
        $np = DB::table('t_product_vendors')->where('SKU_VENDOR', 'NOPE-1')->first(); $this->assertSame(['Old title NOPE-1', 'private', '3.10', '9.99'], [$np->product_name_vendor, $np->e_product_type, (string) $np->raw_price, (string) $np->sale_price]);
        $this->assertSame([$shop], DB::table('t_product_visibility_shops')->where('fk_id_product', $np->id)->pluck('fk_id_branch')->map(fn ($x) => (int) $x)->all(), 'private to its shop');
        $l = DB::table('t_product_listings')->where('legacy_branch_row', $a)->first();
        $this->assertSame(['3.10', 'B0ABC', 'B0ABC', 'EB-1', 'Old title EB-1', '9.99', 'ACTIVE'], [$l->expected_cost, $l->item_id, $l->asin, $l->marketplace_sku, $l->listing_title, $l->price, $l->status]);
        $this->assertSame([$p, $shop, $mp, 3, 7, 'LEG-1', 'REF9', 4, 'https://example.test/i/1', 'https://example.test/v/1'], [(int) $l->fk_id_product, (int) $l->fk_id_branch, (int) $l->fk_id_marketplace, (int) $l->fk_id_product_type, (int) $l->fk_id_vendor, $l->vendor_sku, $l->vendor_ref, (int) $l->fk_id_carrier, $l->listing_url, $l->vendor_url]);
        // a second run adds nothing
        $again = $this->postJson('/api/product-imports/legacy-listings')->assertOk()->json(); $this->assertSame([false, 0, 2], [$again['applied'], $again['ready'], $again['already']]);
        // undo removes what the batch made; then it can run again
        $this->postJson('/api/product-imports/legacy-listings/undo/' . $r['job'])->assertOk()->assertJsonPath('message', 'Undone: 2 listing(s) removed.');
        $this->assertFalse(DB::table('t_product_vendors')->where('SKU_VENDOR', 'NOPE-1')->exists(), 'the product it made goes too');
        $this->assertSame($n, DB::table('t_product_listings')->count()); $this->postJson('/api/product-imports/legacy-listings/undo/' . $r['job'])->assertStatus(422);
        $this->assertSame(2, $this->getJson('/api/product-imports/legacy-listings')->json('ready'));
    }
}
