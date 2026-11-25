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

    /** R-B_SOURCING-23 a saved value never disappears: a supplier no longer in the list is shown by name ("[Saved: Ningbo Lights] (re-select from list)" in the form); an unknown id comes back as the id; saving the form untouched keeps the value. */
    public function test_b23_a_saved_value_not_in_the_list_keeps_its_name_and_its_value(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $sup = (int) DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-77', 'supplier_name' => 'Ningbo Lights', 'f_active' => 0]));
        $po = (int) DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-B23', 'fk_id_supplier' => $sup, 'status' => 'DRAFT', 'f_active' => 1]));
        $this->assertNotContains($sup, array_map(fn ($o) => (int) $o[0], \App\Services\FormAssist::options('t_src_po', 'fk_id_supplier', null)['options']), 'an inactive supplier is not offered');
        $l = $this->getJson("/api/options/t_src_po/fk_id_supplier/saved?ids=$sup,999999")->assertOk()->json('labels'); $this->assertSame(['Ningbo Lights', '999999'], [$l[(string) $sup], $l['999999']]);
        RecordService::save('t_src_po', ['notes' => 'checked'], $po); $this->assertSame($sup, (int) DB::table('t_src_po')->where('id', $po)->value('fk_id_supplier'), 'untouched: the value is kept');
    }

}
