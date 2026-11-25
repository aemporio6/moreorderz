<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\SystemRows;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-F_SETUP-42 locked system rows: no delete, no rename of the code, other fields by the Owner only, a lock in the list. */
class SystemRowsTest extends TestCase
{
    public function test_r42_a_system_row_cannot_be_deleted_or_recoded_and_shows_a_lock(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        DB::table('cat_subscription_items')->insert(RecordService::fillNotNull('cat_subscription_items', ['id' => 9, 'fk_id_ent' => 1, 'item_name' => 'Marketplace store', 'item_iso_code' => 'STORE', 'f_active' => 1, 'f_system' => 1]));
        $free = (int) DB::table('cat_subscription_items')->insertGetId(RecordService::fillNotNull('cat_subscription_items', ['fk_id_ent' => 1, 'item_name' => 'Other tool', 'item_iso_code' => 'TOOL', 'f_active' => 1]));
        $err = function (array $v, int $id = 9) { try { RecordService::save('cat_subscription_items', $v, $id); return null; } catch (\Illuminate\Validation\ValidationException $e) { return array_values($e->errors())[0][0]; } };
        $this->assertSame('System row – cannot be deleted', $err(['f_active' => 0]));      // the rule test: delete subscription item 9 → refused
        $this->assertSame('System row – its code cannot be changed', $err(['item_iso_code' => 'SHOP']));
        $this->assertNull($err(['item_name' => 'Marketplace store fee'])); $this->assertSame('Marketplace store fee', DB::table('cat_subscription_items')->where('id', 9)->value('item_name'));
        $this->assertNull($err(['f_active' => 0], $free), 'a normal row can be made inactive');
        $mgr = $this->makeUser('MANAGER'); $this->actingAs($mgr); Context::set(1, $mgr->id);
        $this->assertSame('System row – only the Owner changes it', $err(['item_name' => 'Renamed by a manager']));
        $this->assertSame([9], SystemRows::locked('cat_subscription_items', [9, $free]));
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->assertContains(9, $this->getJson('/api/list/cat_subscription_items')->assertOk()->json('locked'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('cat_banks', 'f_system'), 'every master has the flag');
    }
}
