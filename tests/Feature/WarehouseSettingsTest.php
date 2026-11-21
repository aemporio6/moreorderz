<?php

namespace Tests\Feature;

use App\Services\Settings;
use App\Services\WarehouseSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-57: Settings › Warehouse – every switch in tabs with default and help, typed checks, company level, history, data untouched. */
class WarehouseSettingsTest extends TestCase
{
    public function test_tabs_defaults_checks_and_history(): void
    {
        $this->actingAs($this->owner);
        $d = $this->getJson('/api/settings/warehouse')->assertOk()->json();
        $this->assertSame(['General', 'Location codes', 'Receiving and putaway', 'Reservation', 'Picking', 'Scanning', 'Packing and dispatch', 'Returns and held stock', 'Replenishment', 'Counting', 'Adjustments', 'Slotting', 'Printing', 'Calendar'], array_column($d['tabs'], 'name'));
        $rows = collect($d['tabs'])->flatMap(fn ($t) => $t['rows'])->keyBy('key');
        foreach (['WH_ENABLED' => 'N', 'WH_PRESET' => 'SIMPLE', 'WH_LOC_FORMAT' => '{ZONE}-{LEVEL}-{BIN}', 'WH_BIN_BARCODE_PREFIX' => 'L:', 'WH_RESERVE_ON' => 'INTAKE', 'WH_PICK_STRATEGY' => 'HOME_FIRST', 'WH_SCAN_MODE' => 'BOTH', 'WH_COUNT_DAYS_C' => '180', 'WH_LOSS_LOT_RULE' => 'FIFO', 'WH_BARCODE_TYPE' => 'CODE128', 'WH_WEEKEND_DAYS' => '6,7', 'ADJUST_APPROVAL_QTY' => '50'] as $k => $def) {
            $this->assertTrue($rows->has($k), "$k on the page"); $this->assertSame($def, $rows[$k]['default'], "$k default");
        }
        $this->assertGreaterThan(60, $rows->count(), 'all switches of the rule');
        $this->assertTrue($rows->every(fn ($r) => $r['label'] !== ''));

        // typed checks
        $bad = ['WH_ENABLED' => 'maybe', 'WH_PICK_MODE' => 'RANDOM', 'WH_BATCH_MAX_ORDERS' => '0', 'WH_LOC_FORMAT' => 'ABC', 'WH_WEEKEND_DAYS' => '8', 'WH_RECOUNT_THRESHOLD_PCT' => '150'];
        foreach ($bad as $k => $v) $this->postJson('/api/settings/warehouse', ['key' => $k, 'value' => $v])->assertStatus(422);
        $this->postJson('/api/settings/warehouse', ['key' => 'WH_ABC_B_PCT', 'value' => '30'])->assertStatus(422)->assertJsonPath('errors.value.0', 'A % + B % cannot be more than 100');

        // save at company level + history; existing data never changes
        $bins = DB::table('cat_inventory_locations')->count();
        $this->postJson('/api/settings/warehouse', ['key' => 'WH_PICK_MODE', 'value' => 'batch'])->assertOk()->assertJsonPath('value', 'BATCH');
        $this->assertSame('BATCH', DB::table('sys_settings')->where('fk_id_ent', 1)->where('setting_key', 'WH_PICK_MODE')->value('setting_value'));
        $this->postJson('/api/settings/warehouse', ['key' => 'WH_WEEKEND_DAYS', 'value' => '5, 6'])->assertOk()->assertJsonPath('value', '5,6');
        $h = $this->getJson('/api/settings/warehouse')->json('history');
        $this->assertSame(['WH_WEEKEND_DAYS', '6,7', '5,6'], [$h[0]['key'], $h[0]['from'], $h[0]['to']]);
        $this->assertSame(['WH_PICK_MODE', 'SINGLE', 'BATCH'], [$h[1]['key'], $h[1]['from'], $h[1]['to']]);
        $this->postJson('/api/settings/warehouse', ['key' => 'WH_PICK_MODE', 'value' => 'BATCH'])->assertOk()->assertJsonPath('message', 'No change.');
        $this->assertSame($bins, DB::table('cat_inventory_locations')->count());
        $this->postJson('/api/settings/warehouse', ['key' => 'WH_HOME_MIN_DEFAULT', 'value' => ''])->assertOk();
        $this->assertSame('Y', (string) (Settings::get('WH_BIN_MULTI_SKU', 1) ?: WarehouseSettings::defs()['WH_BIN_MULTI_SKU'][2]));

        $m = $this->makeUser('WAREHOUSE'); \App\Services\Perm::flush();
        $this->actingAs($m)->getJson('/api/settings/warehouse')->assertStatus(403);
    }
}
