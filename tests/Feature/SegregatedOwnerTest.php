<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\DispatchService;
use App\Services\RecordService;
use App\Services\StockService;
use App\Services\WarehouseAlerts;
use App\Services\WarehouseSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-11 segregated owner stock: own physical rows, never picked for a pool order, checked per owner. */
class SegregatedOwnerTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_h11_segregated_owner_rows_pick_and_check(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Consign', 'sur_name' => 'Partner', 'f_active' => 1]);
        $sku = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SEG-A', 'product_name_vendor' => 'Seg A', 'f_active' => 1, 'e_product_type' => 'pool']);
        $b1 = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-3', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $b2 = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-4', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        WarehouseSettings::save(1, 'WH_SEGREGATED_OWNERS', (string) $p);
        $this->assertSame([$p], StockService::segregated(1));
        // GRN 20 units for P → physical row (SKU-A, W1-A-3, owner P) 20; shared units keep owner 0
        StockService::receive(1, [['product' => $sku, 'qty' => 20, 'location' => $b1, 'unit_cost' => 2, 'owner' => $p, 'visibility' => 'POOL']], 'GRN', null);
        StockService::receive(1, [['product' => $sku, 'qty' => 5, 'location' => $b1, 'unit_cost' => 2]], 'GRN', null);
        $row = fn (int $loc, int $o) => (int) DB::table('t_product_locations')->where(['fk_id_product' => $sku, 'fk_id_location' => $loc, 'fk_id_owner_seg' => $o])->value('qty');
        $this->assertSame(20, $row($b1, $p));
        $this->assertFalse(DB::table('t_product_locations')->where(['fk_id_product' => $sku, 'fk_id_location' => $b1, 'fk_id_owner_seg' => 0])->where('qty', '>', 0)->exists());
        // a pool order cannot pick those 20
        $this->assertSame(5, StockService::availableFor($sku, null));
        $this->assertSame(5, (int) array_sum(array_column(DispatchService::binsOf($sku, 1, null), 'qty')));
        $this->assertSame(20, (int) array_sum(array_column(DispatchService::binsOf($sku, 1, $p), 'qty')));
        try { StockService::transfer(1, $sku, 6, $b1, $b2, 'pool pick', ['rule' => 'POOL']); $this->fail('a pool move took the owner\'s units'); } catch (\RuntimeException $e) { $this->assertStringContainsString('have 5', $e->getMessage()); }
        // a warehouse move keeps the owner and his row follows
        StockService::transfer(1, $sku, 8, $b1, $b2, 'putaway', ['owner' => $p]);
        $this->assertSame(12, $row($b1, $p)); $this->assertSame(8, $row($b2, $p));
        $this->assertSame(8, StockService::balance($sku, $b2, $p));
        // the equation per SKU and segregated owner
        $this->assertSame([], StockService::segCheck(1));
        DB::table('t_product_locations')->where(['fk_id_product' => $sku, 'fk_id_location' => $b2, 'fk_id_owner_seg' => $p])->update(['qty' => 7]);
        $bad = StockService::segCheck(1); $this->assertCount(1, $bad); $this->assertSame(['owner' => $p, 'product' => $sku, 'location' => $b2, 'book' => 8, 'physical' => 7], $bad[0]);
        $this->assertNotEmpty(array_filter(WarehouseAlerts::recon(1), fn ($x) => str_contains($x['title'], 'physical 7 but book 8')));
        // switched off again: his rows go to 0 and his units are shared stock again
        WarehouseSettings::save(1, 'WH_SEGREGATED_OWNERS', '');
        $this->assertSame(0, $row($b1, $p)); $this->assertSame(25, StockService::availableFor($sku, null));
    }
}
