<?php

namespace Tests\Feature;

use App\Services\Perm;
use Tests\TestCase;

/** R-H-58 warehouse rights per action: picker / packer actions for WAREHOUSE, manager actions for MANAGER, settings for OWNER; maker-checker on counts. */
class WarehouseRightsTest extends TestCase
{
    public function test_default_roles_per_action(): void
    {
        $wh = $this->makeUser('WAREHOUSE'); $mg = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $ow = $this->makeUser('OWNER');
        foreach (['wh.work.pick', 'wh.work.pack', 'wh.work.handover', 'wh.work.move', 'wh.label.void', 'wh.label.reprint', 'wh.picklist.build', 'wh.count.create'] as $r) {
            $this->assertTrue(Perm::has($wh, $r), "WAREHOUSE: $r"); $this->assertFalse(Perm::has($va, $r), "VA: $r");
        }
        foreach (['wh.picklist.reassign', 'wh.handover.reopen', 'wh.slotting.approve', 'wh.sku_home.edit', 'wh.reservation.override'] as $r) {
            $this->assertFalse(Perm::has($wh, $r), "WAREHOUSE must not: $r"); $this->assertTrue(Perm::has($mg, $r), "MANAGER: $r");
        }
        foreach (['wh.settings.edit', 'wh.site.share', 'wh.migration.run'] as $r) { $this->assertFalse(Perm::has($mg, $r), "MANAGER must not: $r"); $this->assertTrue(Perm::has($ow, $r)); }
        $this->actingAs($wh)->postJson('/api/handover/1/unscan', ['parcel' => 1, 'reason' => 'test'])->assertStatus(403);
        $this->actingAs($wh)->postJson('/api/pick-lists/1/reassign', ['user' => 1])->assertStatus(403);
    }

    public function test_the_counter_cannot_approve_his_own_count(): void
    {
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh); \App\Services\Context::set(1, (int) $wh->id);
        $bin = \Illuminate\Support\Facades\DB::table('cat_inventory_locations')->insertGetId(\App\Services\RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'C-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        $id = \App\Services\CountService::open(1, $bin);
        \Illuminate\Support\Facades\DB::table('t_stock_counts')->where('id', $id)->update(['status' => 'SUBMITTED']);
        try { \App\Services\CountService::approve($id); $this->fail('own count approved'); } catch (\RuntimeException $e) { $this->assertSame('Another person must approve this count.', $e->getMessage()); }
    }
}
