<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-9: hide with a reason + Restore; soft delete that cascades to the children (one Restore brings all back); blocked by stock / lot / PO / sale → "Archive instead". */
class ProductArchiveTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_delete_family_to_the_bin_restore_all_and_sale_blocks(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = fn (string $sku, array $x = []) => $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'stock' => 0, 'f_active' => 1]);
        $parent = $p('AR-PARENT'); $c1 = $p('AR-C1', ['f_id_parent' => $parent, 'f_parent' => 3]); $c2 = $p('AR-C2', ['f_id_parent' => $parent, 'f_parent' => 3]);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'ARmp', 'f_active' => 1]);
        $vp = DB::table('t_variation_marketplace_prices')->insertGetId(RecordService::fillNotNull('t_variation_marketplace_prices', ['fk_id_variation' => $c1, 'fk_id_marketplace' => $mp, 'raw_price' => 5, 'f_active' => 1]));

        // delete → 3 rows in the recycle bin, the children's marketplace rows go with them
        $this->postJson("/api/products/$parent/delete")->assertOk()->assertJsonPath('message', 'Deleted AR-PARENT + 2 child SKU(s).');
        $this->assertSame(3, DB::table('mo_recycle_bin')->where('table_name', 't_product_vendors')->where('group_key', DB::table('t_product_vendors')->where('id', $parent)->value('delete_group'))->count());
        $this->assertSame(['DELETED', 'DELETED', 'DELETED'], DB::table('t_product_vendors')->whereIn('id', [$parent, $c1, $c2])->pluck('row_state')->all());
        $this->assertSame(0, (int) DB::table('t_variation_marketplace_prices')->where('id', $vp)->value('f_active'));
        $bin = collect($this->getJson('/api/products/bin')->assertOk()->json('rows'))->firstWhere('product', $parent); $this->assertSame(3, (int) $bin['rows_n']);
        $this->assertSame([$parent], array_column($this->getJson('/api/products?status=deleted&per=100&q=AR-PARENT')->json('rows'), 'id'));
        $this->assertSame([], array_column($this->getJson('/api/products?status=active&per=100&q=AR-')->json('rows'), 'id'));
        // one Restore returns all 3
        $this->postJson("/api/products/$parent/restore")->assertOk()->assertJsonPath('message', 'AR-PARENT restored with 2 child SKU(s).');
        $this->assertSame([1, 1, 1], DB::table('t_product_vendors')->whereIn('id', [$parent, $c1, $c2])->pluck('f_active')->map(fn ($x) => (int) $x)->all());
        $this->assertSame(1, (int) DB::table('t_variation_marketplace_prices')->where('id', $vp)->value('f_active'));

        // a product with 1 sale → delete refused "Archive instead"; hide with a reason, then Restore from "Hidden products"
        $sold = $p('AR-SOLD');
        $this->f('t_sales', ['fk_id_ent' => 1, 'fk_product_id' => $sold, 'order_no' => 'AR-1', 'd_order' => date('Y-m-d'), 'qty' => 1, 'status' => 'Pending']);
        $this->postJson("/api/products/$sold/delete")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Archive instead: AR-SOLD has sales'));
        $this->postJson("/api/products/$sold/hide", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/products/$sold/hide", ['reason' => 'Range discontinued'])->assertOk();
        $h = collect($this->getJson('/api/products?status=hidden&per=100&q=AR-SOLD')->json('rows'))->firstWhere('id', $sold);
        $this->assertSame(['HIDDEN', 'Range discontinued'], [$h['state'], $h['hidden_reason']]);
        $this->postJson("/api/products/$sold/restore")->assertOk(); $this->assertSame('ACTIVE', DB::table('t_product_vendors')->where('id', $sold)->value('row_state'));
        // stock or an open lot also blocks
        DB::table('t_product_vendors')->where('id', $c2)->update(['stock' => 3]);
        $this->postJson("/api/products/$parent/delete")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'stock on AR-C2'));
        // rights: a Manager may hide but not delete
        $mgr = $this->makeUser('MANAGER');
        $this->actingAs($mgr)->postJson("/api/products/$c1/delete")->assertStatus(403);
        $this->actingAs($mgr)->postJson("/api/products/$c1/hide", ['reason' => 'Old colour'])->assertOk();
    }
}
