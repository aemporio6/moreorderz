<?php

namespace Tests\Feature;

use App\Services\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-45 the Inventory setup page: locations as a tree with units, the location types, the two reason lists (add, rename, switch off; names once). */
class InventorySetupTest extends TestCase
{
    public function test_inventory_setup_page_and_reasons(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $site = (int) DB::table('cat_inventory_locations')->insertGetId(\App\Services\RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'IS Site', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()]));
        $bin = (int) DB::table('cat_inventory_locations')->insertGetId(\App\Services\RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'IS Bin A1', 'kind' => 'BIN', 'fk_id_parent' => $site, 'fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()]));
        $d = $this->getJson('/api/inventory-setup')->assertOk()->json();
        $rows = collect($d['locations'])->keyBy('id'); $this->assertSame(0, $rows[$site]['depth']); $this->assertSame(1, $rows[$bin]['depth']);
        $this->assertNotEmpty(collect($d['kinds'])->firstWhere('value', 'BIN'));
        $this->postJson('/api/inventory-setup/adjust', ['name' => '  Gift to  customer '])->assertOk();
        $id = (int) DB::table('cat_adjustment_reasons')->where('reason_name', 'Gift to customer')->value('id'); $this->assertGreaterThan(0, $id);
        $this->postJson('/api/inventory-setup/adjust', ['name' => 'gift to customer'])->assertStatus(422);
        $this->postJson('/api/inventory-setup/adjust', ['id' => $id, 'active' => 0])->assertOk();
        $this->assertSame(0, (int) DB::table('cat_adjustment_reasons')->where('id', $id)->value('f_active'));
        $sys = (int) DB::table('cat_adjustment_reasons')->insertGetId(['reason_name' => 'Count', 'f_active' => 1, 'f_system' => 1]);
        $this->postJson('/api/inventory-setup/adjust', ['id' => $sys, 'active' => 0])->assertStatus(422);
        $this->postJson('/api/inventory-setup/price', ['name' => 'Supplier price up'])->assertOk();
    }
}
