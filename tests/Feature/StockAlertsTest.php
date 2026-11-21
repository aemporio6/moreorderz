<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-46: Stock alerts – out of stock / near min level rows, counted-qty update as an adjustment with a required reason, STK_LOW. */
class StockAlertsTest extends TestCase
{
    private int $bin;

    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function prod(string $sku, array $x = []): int { return $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']); }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->bin = $this->f('cat_inventory_locations', ['location_name' => 'SA-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        if (! DB::table('cat_adjustment_reasons')->where('reason_name', 'Count')->exists()) DB::table('cat_adjustment_reasons')->insert(['reason_name' => 'Count', 'f_active' => 1]);
    }

    public function test_the_rule_example_near_min_then_count_12_closes_stk_low(): void
    {
        $p = $this->prod('SA-A', ['min_level' => 5, 'f_alert_min_level' => 1]);
        Stock::receive(1, [['product' => $p, 'qty' => 2, 'location' => $this->bin, 'unit_cost' => 1.5]], 'GRN', 1);
        $ok = $this->prod('SA-OK', ['min_level' => 5, 'f_alert_min_level' => 1]); Stock::receive(1, [['product' => $ok, 'qty' => 9, 'location' => $this->bin, 'unit_cost' => 1]], 'GRN', 1);
        $child = $this->prod('SA-CH', ['f_id_parent' => $p]);
        $oos = $this->prod('SA-OOS'); Stock::receive(1, [['product' => $oos, 'qty' => 3, 'location' => $this->bin, 'unit_cost' => 1]], 'GRN', 1);
        DB::table('t_product_vendors')->where('id', $oos)->update(['f_out_of_stock' => 1]);      // marked out of stock by hand on the product form
        Stock::lowStockTasks(1);
        $this->assertTrue(TaskEngine::isOpen('STK_LOW', [$p]));

        $rows = collect($this->getJson('/api/stock-alerts')->assertOk()->json('rows'))->keyBy('sku');
        $this->assertSame(['Near min level', 2, 5], [$rows['SA-A']['status'], $rows['SA-A']['stock'], $rows['SA-A']['min']]);
        $this->assertSame('Out of stock', $rows['SA-OOS']['status'], 'manual out of stock');
        $this->assertFalse($rows->has('SA-OK')); $this->assertFalse($rows->has('SA-CH'), 'child products are left out');
        $this->assertSame($this->bin, $rows['SA-A']['location']);

        $this->postJson("/api/stock-alerts/$p", ['location' => $this->bin, 'new_qty' => 12, 'reason' => ''])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Choose the reason');
        $this->postJson("/api/stock-alerts/$p", ['location' => $this->bin, 'new_qty' => 2, 'reason' => 'Count'])->assertOk()->assertJsonPath('message', 'No change.');
        $r = $this->postJson("/api/stock-alerts/$p", ['location' => $this->bin, 'new_qty' => 12, 'reason' => 'Count'])->assertOk()->json();
        $this->assertSame([true, 10, 12], [$r['changed'], $r['diff'], $r['stock']]);
        $mv = DB::table('t_stock_movements')->where('fk_id_product', $p)->orderByDesc('id')->first();
        $this->assertSame(10, (int) $mv->qty_change);
        $this->assertStringContainsString('Count – Stock update from alerts page', (string) $mv->notes);
        $this->assertFalse(collect($this->getJson('/api/stock-alerts')->json('rows'))->contains('sku', 'SA-A'), 'the row disappears');
        $this->assertFalse(TaskEngine::isOpen('STK_LOW', [$p]), 'STK_LOW closed');
    }

    public function test_big_change_waits_for_approval_and_reserved_units_count_as_used(): void
    {
        Settings::set('ADJUST_APPROVAL_QTY', '5', 1);
        $p = $this->prod('SA-B', ['min_level' => 3, 'f_alert_min_level' => 1]);
        Stock::receive(1, [['product' => $p, 'qty' => 4, 'location' => $this->bin, 'unit_cost' => 1]], 'GRN', 1);
        $this->assertFalse(collect($this->getJson('/api/stock-alerts')->json('rows'))->contains('sku', 'SA-B'));
        DB::table('wh_reservations')->insert(['fk_id_ent' => 1, 'fk_id_site' => 0, 'fk_id_product' => $p, 'owner_rule' => 'POOL', 'qty' => 2, 'status' => 'OPEN', 'created_at' => now()]);
        $this->assertSame(2, collect($this->getJson('/api/stock-alerts')->json('rows'))->firstWhere('sku', 'SA-B')['stock'], 'available = 4 on hand − 2 reserved');
        $wh = $this->makeUser('WAREHOUSE'); \App\Services\Perm::flush();
        $this->assertTrue(\App\Services\Perm::has($wh, 'stock.CHANGE')); $this->assertFalse(\App\Services\Perm::has($wh, 'stock.adjust_owner'));
        $this->actingAs($wh)->postJson("/api/stock-alerts/$p", ['location' => $this->bin, 'new_qty' => 20, 'reason' => 'Count'])->assertOk()->assertJsonPath('waiting', true);
        $this->assertSame(4, Stock::balance($p, $this->bin), 'stock has not changed yet');
    }
}
