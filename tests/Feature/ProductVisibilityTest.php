<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-20: visibility per marketplace × shop – Pool stores no shops, Private the ticked shops, a shop of another marketplace is refused, upsert not delete-all. */
class ProductVisibilityTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_ebay_pool_amazon_private_101_104_onbuy_off_and_tiktok_shop_refused(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = fn (string $n) => $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => $n, 'f_active' => 1]);
        $ebay = $mp('eBayV'); $amz = $mp('AmazonV'); $onbuy = $mp('OnBuyV'); $tt = $mp('TikTokV');
        $shop = fn (int $m, string $no) => $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $m, 'shop_no' => $no, 'iso_branch' => "V$no", 'shop_name' => "Shop $no", 'f_active' => 1]);
        $s101 = $shop($amz, '101'); $s104 = $shop($amz, '104'); $s105 = $shop($amz, '105'); $s205 = $shop($tt, '205'); $shop($ebay, '301');
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'VIS-1', 'product_name_vendor' => 'Vis item', 'f_active' => 1]);
        // OnBuy was on before: unticking it clears it
        DB::table('t_product_visibility')->insert(['fk_id_product' => $prod, 'fk_id_marketplace' => $onbuy, 'visibility_mode' => 'pool', 'fk_id_branch' => 0, 'created_at' => now()]);

        $tab = [$ebay => ['on' => true, 'mode' => 'POOL', 'shops' => [$s101]], $amz => ['on' => true, 'mode' => 'PRIVATE', 'shops' => [$s101, $s104, $s104]], $onbuy => ['on' => false]];
        $this->postJson("/api/products/$prod/visibility", ['tab' => $tab + [$tt => ['on' => true, 'mode' => 'PRIVATE', 'shops' => [$s205]]]])->assertOk();
        $this->postJson("/api/products/$prod/visibility", ['tab' => [$amz => ['on' => true, 'mode' => 'PRIVATE', 'shops' => [$s101, $s205]]]])->assertStatus(422)->assertJsonPath("errors.shops_$amz.0", "Shop $s205 is not a shop of this marketplace.");
        $this->postJson("/api/products/$prod/visibility", ['tab' => $tab + [$tt => ['on' => false]]])->assertOk();
        $rows = DB::table('t_product_visibility')->where('fk_id_product', $prod)->orderBy('fk_id_marketplace')->orderBy('fk_id_branch')->get()->map(fn ($r) => [(int) $r->fk_id_marketplace, $r->visibility_mode, (int) $r->fk_id_branch])->all();
        $this->assertSame([[$ebay, 'pool', 0], [$amz, 'private', $s101], [$amz, 'private', $s104]], $rows, 'eBay Pool with no shops; Amazon Private 101 + 104 (no duplicates); OnBuy and TikTok off');
        $this->assertSame([$s101, $s104], DB::table('t_product_visibility_shops')->where('fk_id_product', $prod)->orderBy('fk_id_branch')->pluck('fk_id_branch')->map(fn ($x) => (int) $x)->all());
        $m = collect($this->getJson("/api/products/$prod/visibility")->assertOk()->json('marketplaces'))->keyBy('id');
        $this->assertSame(['2 shop(s) selected', 'Pool – every active shop', 'Not sold there'], [$m[$amz]['summary'], $m[$ebay]['summary'], $m[$onbuy]['summary']]);
        // upsert: the Amazon 101 row keeps its id when 105 is added
        $id101 = DB::table('t_product_visibility')->where(['fk_id_product' => $prod, 'fk_id_branch' => $s101])->value('id');
        $this->postJson("/api/products/$prod/visibility", ['tab' => [$amz => ['on' => true, 'mode' => 'PRIVATE', 'shops' => [$s101, $s105]]]])->assertOk();
        $this->assertSame($id101, DB::table('t_product_visibility')->where(['fk_id_product' => $prod, 'fk_id_branch' => $s101])->value('id'));
        // Private with no shop is allowed with a warning; any other mode is saved as pool
        $this->postJson("/api/products/$prod/visibility", ['tab' => [$amz => ['on' => true, 'mode' => 'PRIVATE', 'shops' => []]]])->assertOk()->assertJsonPath('warnings.0', 'Private with no shop – nobody can sell it (AmazonV).');
        $this->postJson("/api/products/$prod/visibility", ['tab' => [$onbuy => ['on' => true, 'mode' => 'weird']]])->assertOk();
        $this->assertSame('pool', DB::table('t_product_visibility')->where(['fk_id_product' => $prod, 'fk_id_marketplace' => $onbuy])->value('visibility_mode'));

        // a lot's default visibility at receipt = the product's mode for the marketplace of the receiving location's shop
        $this->postJson("/api/products/$prod/visibility", ['tab' => [$amz => ['on' => true, 'mode' => 'PRIVATE', 'shops' => [$s101]]]])->assertOk();
        $loc = $this->f('cat_inventory_locations', ['location_name' => 'Amazon room', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_branch' => $s104, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        Stock::receive(1, [['product' => $prod, 'qty' => 2, 'location' => $loc, 'unit_cost' => 1.0]], 'GRN', 1);
        $this->assertSame('PRIVATE', DB::table('fin_lots')->where('fk_id_product', $prod)->orderByDesc('id')->value('visibility'));
        // rights
        $this->actingAs($this->makeUser('WAREHOUSE'))->postJson("/api/products/$prod/visibility", ['tab' => []])->assertStatus(403);
    }
}
