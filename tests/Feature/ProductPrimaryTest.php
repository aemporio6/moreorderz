<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductPrimary;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-24 primary marketplace per company and the read view v_product_primary_price. */
class ProductPrimaryTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_d24_view_follows_the_primary_marketplace_without_product_row_updates(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $ebay = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBay24', 'f_active' => 1]); $amz = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz24', 'f_active' => 1]);
        $this->assertSame((int) DB::table('cat_marketplaces')->where('f_active', 1)->min('id'), ProductPrimary::marketplace(1), 'default: the first active marketplace by id');
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PP-' . uniqid(), 'product_name_vendor' => 'Primary', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'raw_price' => 1.11, 'sale_price' => 2.22]);
        DB::table('t_product_marketplace_prices')->where('fk_id_product', $p)->delete();
        $this->f('t_product_marketplace_prices', ['fk_id_product' => $p, 'fk_id_marketplace' => $ebay, 'raw_price' => 4.00, 'sale_price' => 9.99, 'list_price' => 12.00, 'marketplace_fee' => 1.30]);
        $this->f('t_product_marketplace_prices', ['fk_id_product' => $p, 'fk_id_marketplace' => $amz, 'raw_price' => 5.00, 'sale_price' => 11.49, 'list_price' => 13.00, 'marketplace_fee' => 1.72]);
        $this->f('t_product_marketplace_shipping', ['fk_id_product' => $p, 'fk_id_marketplace' => $ebay, 'fk_id_car_pkg_svc' => 7, 'fk_id_packing' => 3]);
        $va = $this->makeUser('MANAGER'); try { ProductPrimary::set(1, $ebay, $va); $this->fail('a Manager set it'); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner sets the primary marketplace.', $e->getMessage()); }
        ProductPrimary::set(1, $ebay, $this->owner);
        $v = ProductPrimary::of($p);
        $this->assertSame([$ebay, 4.0, 9.99, 12.0, 1.3, 7, 3], [(int) $v['fk_id_marketplace'], (float) $v['raw_price'], (float) $v['sale_price'], (float) $v['list_price'], (float) $v['ebay_fee'], (int) $v['fk_id_car_pkg_svc'], (int) $v['fk_id_packing']]);
        // the rule: primary = eBay; change eBay raw → the reader shows the new raw without any product-row update
        $before = (array) DB::table('t_product_vendors')->where('id', $p)->first();
        DB::table('t_product_marketplace_prices')->where('fk_id_product', $p)->where('fk_id_marketplace', $ebay)->update(['raw_price' => 4.75]);
        $this->assertSame(4.75, (float) ProductPrimary::of($p)['raw_price']); $this->assertSame(4.75, (float) ProductPrimary::many([$p])[$p]['raw_price']);
        $this->assertSame($before, (array) DB::table('t_product_vendors')->where('id', $p)->first(), 'the product row was not touched');
        // another primary marketplace → the same view shows that marketplace
        ProductPrimary::set(1, $amz, $this->owner); $v = ProductPrimary::of($p);
        $this->assertSame([$amz, 5.0, 11.49, null], [(int) $v['fk_id_marketplace'], (float) $v['raw_price'], (float) $v['sale_price'], $v['fk_id_car_pkg_svc']]);
        $this->assertSame((string) $amz, (string) Settings::get('PRIMARY_MARKETPLACE', 1));
    }
}
