<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\WarehouseSettings;
use App\Services\WarehouseSites;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-3 system bins per site, R-H-6 a bin with units is not deactivated, R-H-8 shared site + scope, R-H-14 go-live dry run with a migration map – spec examples. */
class WarehouseSitesTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_r3_managed_site_gets_8_system_bins_never_deactivated(): void
    {
        $this->actingAs($this->owner);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        $bins = WarehouseSites::manage(1, $wh, true);
        $this->assertSame(array_keys(WarehouseSites::SYSTEM), array_column($bins, 'system_role'), 'switch WH1 to managed → 8 system bins');
        $this->assertCount(8, WarehouseSites::manage(1, $wh, true), 'never twice');
        $roles = collect($bins)->keyBy('system_role');
        $this->assertSame([1, 0, 0], [(int) $roles['UNASSIGNED']['f_sellable'], (int) $roles['RECEIVING']['f_sellable'], (int) $roles['RETURNS']['f_sellable']], 'only UNASSIGNED sellable by default');
        $this->assertSame('SYS-SHIPPED', $roles['SHIPPED']['location_code']);
        $form = RecordService::formOfTable('cat_inventory_locations');
        $e = $this->postJson("/api/record/$form/" . $roles['SHIPPED']['id'], ['values' => ['f_active' => 0]])->assertStatus(422)->json('errors');
        $this->assertSame('System bins cannot be deactivated.', collect($e)->flatten()->first());
        WarehouseSettings::save(1, 'WH_RECEIVING_SELLABLE', 'Y');
        $this->assertSame(1, (int) DB::table('cat_inventory_locations')->where('id', $roles['RECEIVING']['id'])->value('f_sellable'), 'the setting drives the bin');
        $this->assertSame((int) $roles['RETURNS']['id'], Stock::systemLocation(1, 'RETURNS', $wh), 'the site\'s own system bin');
        // R-H-6 a bin with units
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-A', 'product_name_vendor' => 'A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $wh, 'fk_id_parent' => $wh, 'f_sellable' => 1, 'f_active' => 1]);
        Stock::receive(1, [['product' => $p, 'qty' => 12, 'location' => $bin, 'unit_cost' => 1.00]], 'GRN', 1);
        $e = $this->postJson("/api/record/$form/$bin", ['values' => ['f_active' => 0]])->assertStatus(422)->json('errors');
        $this->assertSame('Move the 12 units first.', collect($e)->flatten()->first());
    }

    public function test_r8_shared_site_shows_only_my_skus(): void
    {
        $c2 = DB::table('t_enterprise')->insertGetId(RecordService::fillNotNull('t_enterprise', ['ent_name' => 'C2 Ltd', 'ent_short' => 'C2', 'iso_ent' => 'C2X', 'f_active' => 1]));
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1, 'shared_companies' => (string) $c2]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-1', 'location_code' => 'W1-A-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $wh, 'fk_id_parent' => $wh, 'f_sellable' => 1, 'f_active' => 1]);
        $a = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-A', 'product_name_vendor' => 'A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $z = $this->f('t_product_vendors', ['fk_id_ent' => $c2, 'SKU_VENDOR' => 'SKU-Z', 'product_name_vendor' => 'Z', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $a, 'qty' => 5, 'location' => $bin, 'unit_cost' => 1.00]], 'GRN', 1);
        Stock::receive((int) $c2, [['product' => $z, 'qty' => 3, 'location' => $bin, 'unit_cost' => 1.00]], 'GRN', 2);      // C2 may use the shared bin
        $c = WarehouseSites::contents($bin, (int) $c2);
        $this->assertSame(['SKU-Z'], array_column($c['items'], 'sku')); $this->assertSame('+ 1 other SKU not in your scope', $c['note'], 'a C2 user sees only SKU-Z and the note');
        $other = DB::table('t_enterprise')->insertGetId(RecordService::fillNotNull('t_enterprise', ['ent_name' => 'C3 Ltd', 'ent_short' => 'C3', 'iso_ent' => 'C3X', 'f_active' => 1]));
        try { WarehouseSites::contents($bin, (int) $other); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Not in your scope.', $e->getMessage()); }
    }

    public function test_r14_go_live_dry_run_map_and_unassigned(): void
    {
        $this->actingAs($this->owner);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        $s2 = $this->f('cat_inventory_locations', ['location_name' => 'S-2', 'kind' => 'SHELF', 'fk_id_ent' => 1, 'fk_id_parent' => $wh, 'f_sellable' => 1, 'f_active' => 1]);
        $new = $this->f('cat_inventory_locations', ['location_name' => 'W1-S-2', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $wh, 'fk_id_parent' => $wh, 'f_sellable' => 1, 'f_active' => 1]);
        $b = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-B', 'product_name_vendor' => 'B', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $b, 'qty' => 50, 'location' => $s2, 'unit_cost' => 2.00]], 'GRN', 1);
        DB::table('t_product_locations')->insert(RecordService::fillNotNull('t_product_locations', ['fk_id_product' => $b, 'fk_id_location' => $s2, 'qty' => 30]));
        $plain = WarehouseSites::goLive(1, $wh, [], false);
        $this->assertSame([['from' => $s2, 'to' => null, 'qty' => 0, 'note' => 'S-2 30 – no mapped bin'], ['from' => null, 'to' => WarehouseSites::systemBin($wh, 'UNASSIGNED'), 'qty' => 50, 'note' => 'UNASSIGNED']], $plain['plan'][0]['lines'], 'no map row → 50 to UNASSIGNED');
        $dry = $this->getJson("/api/warehouse/sites/$wh/go-live?" . http_build_query(['map' => [$s2 => $new]]))->assertOk()->json();
        $this->assertSame([30, 20], array_column($dry['plan'][0]['lines'], 'qty'), '30 → W1-S-2, 20 → UNASSIGNED');
        $this->assertSame(50, Stock::balance($b, $s2), 'the dry run changes nothing');
        $r = $this->postJson("/api/warehouse/sites/$wh/go-live", ['map' => [$s2 => $new], 'apply' => true])->assertOk()->json();
        $this->assertSame([30, 20, 50, 0], [Stock::balance($b, $new), Stock::balance($b, WarehouseSites::systemBin($wh, 'UNASSIGNED')), Stock::balance($b), $r['again']], 'Σ physical 50 = book 50; second run → 0 changes');
    }
}
