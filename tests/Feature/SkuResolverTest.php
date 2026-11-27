<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SkuResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Marketplace SKUs that carry the product inside: shop prefix, ASIN, item id, listing SKU; bundles never guessed. */
class SkuResolverTest extends TestCase
{
    public function test_marketplace_skus_find_their_product(): void
    {
        $mp = (int) DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz', 'marketplace_descr' => 'Amazon', 'f_active' => 1]));
        $shop = (int) DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '109', 'shop_name' => 'Mon', 'f_active' => 1]));
        $mk = fn (string $sku) => (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => $sku]));
        $a = $mk('B240-WINDOW-HAND-' . uniqid()); $b = $mk('LEAD-' . uniqid()); $c = $mk('AE-' . uniqid()); $d = $mk('LS-' . uniqid());
        $l = fn (int $p, array $x) => DB::table('t_product_listings')->insert(RecordService::fillNotNull('t_product_listings', $x + ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'f_active' => 1, 'created_at' => now()]));
        $l($b, ['asin' => 'B07KVQY61C']); $l($c, ['item_id' => '1005008666351859']); $l($d, ['marketplace_sku' => 'XYZ-777']);
        $sku = DB::table('t_product_vendors')->where('id', $a)->value('SKU_VENDOR');
        $this->assertSame([$a, 'SKU without the shop prefix'], SkuResolver::find(1, $mp, 'MON-109-' . $sku));
        $this->assertSame([$b, 'ASIN B07KVQY61C'], SkuResolver::find(1, $mp, 'MON-109B07KVQY61C109'));
        $this->assertSame([$c, 'item id 1005008666351859'], SkuResolver::find(1, $mp, 'ALX-101C-1005008666351859'));
        $this->assertSame([$d, 'listing SKU'], SkuResolver::find(1, $mp, 'xyz-777'));
        $this->assertNull(SkuResolver::find(1, $mp, $sku . '+B178-PET'));
        $this->assertNull(SkuResolver::find(1, $mp, 'NOTHING-HERE'));
        $this->artisan('mo:sku-check')->assertExitCode(0);
    }
}
