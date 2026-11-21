<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductsList;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-25: the ownership chip on product and lot (JOINT / HOLDING / CENTRAL / PRIVATE), filterable. */
class OwnershipTypeTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function product(string $sku): int { return $this->f('t_product_vendors', ['SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'fk_id_ent' => 1, 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']); }
    private function skus(array $q): array { return collect($this->getJson('/api/products?' . http_build_query($q + ['per' => 100, 'q' => 'OT-']))->assertOk()->json('rows'))->map(fn ($r) => explode(' · ', $r['sku'])[0])->sort()->values()->all(); }

    public function test_product_chip_filter_and_lot_chip(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $eb = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayOT', 'f_active' => 1]); $am = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmzOT', 'f_active' => 1]);
        [$j, $h, $c, $p, $mix] = [$this->product('OT-J'), $this->product('OT-H'), $this->product('OT-C'), $this->product('OT-P'), $this->product('OT-M')];
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Inv A', 'f_active' => 1]); $b = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Inv B', 'f_active' => 1]);
        DB::table('t_product_investors')->insert([['fk_id_product' => $j, 'fk_id_account' => $a, 'created_at' => now()], ['fk_id_product' => $j, 'fk_id_account' => $b, 'created_at' => now()], ['fk_id_product' => $h, 'fk_id_account' => $a, 'created_at' => now()]]);
        DB::table('t_product_visibility')->insert([['fk_id_product' => $c, 'fk_id_marketplace' => $eb, 'visibility_mode' => 'POOL', 'created_at' => now()], ['fk_id_product' => $p, 'fk_id_marketplace' => $eb, 'visibility_mode' => 'PRIVATE', 'created_at' => now()],
            ['fk_id_product' => $mix, 'fk_id_marketplace' => $eb, 'visibility_mode' => 'POOL', 'created_at' => now()], ['fk_id_product' => $mix, 'fk_id_marketplace' => $am, 'visibility_mode' => 'PRIVATE', 'created_at' => now()]]);

        $this->assertSame(['OT-J'], $this->skus(['type' => 'JOINT']));
        $this->assertSame(['OT-H'], $this->skus(['type' => 'HOLDING']));
        $this->assertSame(['OT-C', 'OT-M'], $this->skus(['type' => 'CENTRAL']), 'pool on any marketplace → CENTRAL (All channels)');
        $this->assertSame(['OT-P'], $this->skus(['type' => 'PRIVATE']));
        $this->assertSame(['OT-P'], $this->skus(['type' => 'PRIVATE', 'mp' => $eb]), 'with the eBay chip: only the product that is private on eBay');
        $this->assertSame(['OT-M'], $this->skus(['type' => 'PRIVATE', 'mp' => $am]), 'with the Amazon chip the mixed product is private');
        // the filter agrees with the chip on every row
        foreach ($this->getJson('/api/products?per=100&q=OT-&type=CENTRAL')->json('rows') as $r) $this->assertSame('CENTRAL', $r['type']);

        // lot chip: two investors on the lot's PO → JOINT; a pool lot with none → CENTRAL; filter on the lots page
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'OT-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        Stock::receive(1, [['product' => $c, 'qty' => 5, 'location' => $bin, 'unit_cost' => 1, 'po_id' => 9901]], 'GRN', 1);
        Stock::receive(1, [['product' => $c, 'qty' => 3, 'location' => $bin, 'unit_cost' => 1]], 'GRN', 1);
        foreach ([1, 2] as $i) $this->f('t_src_investments', ['fk_id_ent' => 1, 'fk_id_po' => 9901, 'fk_id_investor' => $i, 'invest_amount' => 10, 'status' => 'PAID', 'f_active' => 1, 'invest_code' => "OT-INV-$i"]);
        $lots = collect($this->getJson("/api/stock/product/$c")->assertOk()->json('rows'))->pluck('type', 'lot_code');
        $po = DB::table('fin_lots')->where('fk_id_product', $c)->where('po_id', 9901)->value('lot_code'); $other = DB::table('fin_lots')->where('fk_id_product', $c)->whereNull('po_id')->value('lot_code');
        $vis = DB::table('fin_lots')->where('lot_code', $other)->value('visibility');
        $this->assertSame('JOINT', $lots[$po]);
        $this->assertSame($vis === 'POOL' ? 'CENTRAL' : ($vis === 'PRIVATE' ? 'PRIVATE' : null), $lots[$other]);
        $this->assertSame([$po], collect($this->getJson('/api/stock/lots?type=JOINT&q=OT-C')->assertOk()->json('rows'))->pluck('lot_code')->all());
        $this->assertSame(['JOINT', 'HOLDING', 'CENTRAL', 'PRIVATE'], ProductsList::OWNER_TYPES);
    }
}
