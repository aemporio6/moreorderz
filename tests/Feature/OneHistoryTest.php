<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\History;
use App\Services\RecordService;
use App\Services\SaleLines;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-122 old change logs become one history: copied once at cut-over, one time line with "from the old tool", only sys_history written afterwards. */
class OneHistoryTest extends TestCase
{
    public function test_g122_old_change_shows_after_cutover_and_new_edits_go_to_sys_history_only(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]); $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'H122', 'iso_branch' => 'H122', 'shop_name' => 'History shop', 'f_active' => 1]);
        $sale = $f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'order_no' => 'H-1', 'd_order' => date('Y-m-d'), 'sku' => 'S', 'qty' => 1, 'sale_rate' => 10, 'total_amount' => 10, 'status' => 'Pending', 'sale_source' => 'active']);
        // a sale edited in the old tool
        DB::table('t_sale_change_log')->insert(['fk_id_sale' => $sale, 'save_key' => 'old1', 'action' => 'Edit Sale', 'field_name' => 'qty', 'field_label' => 'Quantity', 'old_value' => '2', 'new_value' => '1', 'fk_id_user' => 0, 'changed_by' => 'Old VA', 'changed_at' => '2026-05-01 10:00:00']);
        $this->assertSame(['t_sale_change_log' => 1], array_intersect_key(History::cutover(), ['t_sale_change_log' => 1]));
        $this->assertSame([], array_filter(History::cutover()), 'a second cut-over adds nothing');
        $h = History::forRecord('t_sales', $sale); $this->assertCount(1, $h); $this->assertTrue($h[0]['legacy']); $this->assertSame(['2', '1'], $h[0]['changes']['Quantity']);
        $before = DB::table('t_sale_change_log')->count();
        SaleLines::edit($sale, ['qty' => 3, 'sale_rate' => 10]);
        $this->assertSame($before, DB::table('t_sale_change_log')->count(), 'the old log table gets no row');
        $this->assertGreaterThanOrEqual(1, DB::table('sys_history')->where('entity', 't_sales')->where('record_id', $sale)->whereNull('legacy_table')->count());
        $log = SaleLines::history($sale); $this->assertContains('Old VA', array_column(array_map(fn ($x) => (array) $x, (array) $log), 'changed_by'), 'Log History shows old and new rows together');
        // the record History tab: one time line with the chip
        $form = RecordService::formOfTable('t_sales');
        if ($form) { $d = $this->getJson("/api/page/$form/$sale")->assertOk()->json("history"); $this->assertTrue(collect($d)->contains(fn ($x) => ! empty($x['legacy']))); }
    }
}
