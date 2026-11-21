<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\NotListed;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-58: products not listed per shop – universe, Not used, drill-down, LST_CREATE tasks that close by themselves. */
class NotListedTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_pool_on_ebay_stock_5_no_listing_counts_as_not_used(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $ebay = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayNL', 'f_active' => 1]);
        $amz = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmzNL', 'f_active' => 1]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'NL-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $s101 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $ebay, 'shop_no' => '101', 'iso_branch' => 'N101', 'shop_name' => 'Pets', 'f_active' => 1]);
        $s102 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $ebay, 'shop_no' => '102', 'iso_branch' => 'N102', 'shop_name' => 'Toys', 'f_active' => 1]);
        $s201 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $amz, 'shop_no' => '201', 'iso_branch' => 'N201', 'shop_name' => 'Amz', 'f_active' => 1]);
        $p = fn (string $sku, array $x = []) => $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $a = $p('NL-A'); $b = $p('NL-B'); $none = $p('NL-NOSTOCK'); $parent = $p('NL-PARENT'); $child = $p('NL-CHILD', ['f_id_parent' => $parent]); $oos = $p('NL-OOS', ['f_out_of_stock' => 1]);
        Stock::receive(1, array_map(fn ($id) => ['product' => $id, 'qty' => 5, 'location' => $bin, 'unit_cost' => 1.0], [$a, $b, $parent, $child, $oos]), 'GRN', 1);
        DB::table('t_product_vendors')->where('id', $oos)->update(['f_out_of_stock' => 1]);      // marked out of stock by hand after the stock came in
        $vis = fn (int $prod, int $mp, string $mode, int $branch = 0) => DB::table('t_product_visibility')->insert(['fk_id_product' => $prod, 'fk_id_marketplace' => $mp, 'visibility_mode' => $mode, 'fk_id_branch' => $branch, 'created_at' => now()]);
        $vis($a, $ebay, 'POOL'); $vis($none, $ebay, 'POOL'); $vis($parent, $ebay, 'POOL'); $vis($oos, $ebay, 'POOL'); $vis($b, $ebay, 'PRIVATE', $s102); $vis($b, $amz, 'POOL');

        $sum = collect(NotListed::summary(1)['rows'])->keyBy('shop');
        // shop 101: A (Pool, stock 5, no listing) and the child (follows its Pool parent); not the parent, not the stock-less one, not OOS, not B (private to 102)
        $this->assertSame([2, 2], [$sum[$s101]['total'], $sum[$s101]['not_used']], 'product visible Pool on eBay, stock 5, no eBay listing in shop 101 → counted in "Not used"');
        $this->assertSame(['NL-A', 'NL-CHILD'], collect(NotListed::lines(1, ['shop' => $s101])['rows'])->pluck('sku')->sort()->values()->all());
        $this->assertSame(3, $sum[$s102]['total'], 'A + child + B (Private, shop 102 ticked)');
        $this->assertSame([1, 1], [$sum[$s201]['total'], $sum[$s201]['not_used']]);
        // an ACTIVE listing takes it out
        $l = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $a, 'fk_id_branch' => $s101, 'fk_id_marketplace' => $ebay, 'marketplace_sku' => 'NL-A', 'status' => 'ACTIVE', 'f_active' => 1]);
        $this->assertSame(1, collect(NotListed::summary(1)['rows'])->firstWhere('shop', $s101)['not_used']);
        DB::table('t_product_listings')->where('id', $l)->update(['status' => 'INACTIVE']);

        // bulk "Create listing task": one per shop + product, then closed when the listing exists
        $r = $this->postJson('/api/not-listed/tasks', ['pairs' => [[$s101, $a], [$s101, $a], [$s101, $none]]])->assertOk()->json();
        $this->assertSame([1, 2], [$r['made'], $r['skipped']]);
        $this->assertTrue(TaskEngine::isOpen('LST_CREATE', [$s101, $a]));
        $this->getJson("/api/not-listed?shop=$s101")->assertOk()->assertJsonPath('lines.count', 2);
        DB::table('t_product_listings')->where('id', $l)->update(['status' => 'ACTIVE']);
        $this->assertSame(1, NotListed::closeDone(1));
        $this->assertFalse(TaskEngine::isOpen('LST_CREATE', [$s101, $a]));
        $this->actingAs($this->makeUser('WAREHOUSE'))->getJson('/api/not-listed')->assertStatus(403);
    }
}
