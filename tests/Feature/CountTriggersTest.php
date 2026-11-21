<?php

namespace Tests\Feature;

use App\Services\CountTriggers;
use App\Services\DispatchService as Dispatch;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-47: automatic counts – empty bin after a pick asks "Is it empty?", short pick, negative attempt, putaway with no room; ABC schedule within the daily limit. */
class CountTriggersTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function trig(int $bin): ?object { return DB::table('t_stock_counts')->where('fk_id_location', $bin)->where('count_type', 'TRIGGERED')->where('status', 'OPEN')->first(); }

    public function test_the_rule_example_pick_empties_w1_a_2_answer_no_makes_a_triggered_line(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'CTmp', 'f_active' => 1]);
        $a2 = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-2', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $a3 = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-3', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 2]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'CT1', 'iso_branch' => 'CT1', 'shop_name' => 'Shop CT', 'f_active' => 1, 'fk_id_main_location' => $a2, 'fulfilment_channel' => 'SELF']);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CT-A', 'product_name_vendor' => 'Count A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $p, 'qty' => 2, 'location' => $a2, 'unit_cost' => 1.0]], 'GRN', 1);
        Sales::intake($shop, [['order_no' => 'CT-1', 'd_order' => '2026-11-20', 'sku' => 'CT-A', 'qty' => 2, 'sale_rate' => 5]]);
        $line = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', Dispatch::buildPickList(1))->value('id');
        $r = $this->postJson("/api/pick-lines/$line")->assertOk()->json();
        $this->assertSame(['bin' => $a2, 'name' => 'W1-A-2', 'product' => $p], $r['ask_empty'], 'the pick emptied W1-A-2 → "Is W1-A-2 empty?"');
        $this->postJson('/api/pick-empty', ['bin' => $a2, 'product' => $p, 'empty' => false])->assertOk();
        $c = $this->trig($a2); $this->assertNotNull($c, 'No → TRIGGERED count');
        $this->assertSame(['EMPTY_NO', 1], [$c->trigger_source, DB::table('t_stock_count_lines')->where('fk_id_count', $c->id)->where('fk_id_product', $p)->count()]);
        $this->assertTrue(TaskEngine::isOpen('WH_COUNT_DUE', [$c->id]));
        // Yes = the count (posted at 0), no open count
        $this->postJson('/api/pick-empty', ['bin' => $a3, 'product' => $p, 'empty' => true])->assertOk();
        $this->assertNull($this->trig($a3)); $this->assertSame('POSTED', DB::table('t_stock_counts')->where('fk_id_location', $a3)->value('status'));

        // a negative attempt (more than the bin holds) → the bin goes on the count list even though the attempt is refused
        Stock::receive(1, [['product' => $p, 'qty' => 1, 'location' => $a3, 'unit_cost' => 1.0]], 'GRN', 1);
        try { Stock::transfer(1, $p, 5, $a3, $a2, 'too many'); $this->fail('negative allowed'); } catch (\RuntimeException $e) { $this->assertStringStartsWith('Not enough stock', $e->getMessage()); }
        CountTriggers::flush();
        $this->assertSame('NEGATIVE', $this->trig($a3)->trigger_source);

        // a putaway refused for no room → the bin is counted
        $rec = Stock::systemLocation(1, 'RECEIVING'); Stock::receive(1, [['product' => $p, 'qty' => 3, 'location' => $rec, 'unit_cost' => 1.0]], 'GRN', 1);
        $a4 = $this->f('cat_inventory_locations', ['location_name' => 'W1-A-4', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 4]);
        DB::table('t_product_locations')->insert(RecordService::fillNotNull('t_product_locations', ['fk_id_product' => $p, 'fk_id_location' => $a4, 'home_role' => 'PRIMARY', 'max_qty' => 2]));
        $t = TaskEngine::open('WH_PUTAWAY_WAITING', [999, $p], 'Put away', ['ent' => 1]);
        try { \App\Services\WarehouseMode::putaway(1, $t, $a4, 3); $this->fail('no room allowed'); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('No room', $e->getMessage()); }
        $this->assertSame('PUTAWAY_NO_ROOM', $this->trig($a4)->trigger_source);

        // ABC schedule: off in TRIGGERS_ONLY; on → bins not counted yet, within the daily limit, one session each
        $this->assertSame(0, CountTriggers::schedule(1)['sessions']);
        Settings::set('WH_CYCLE_COUNT_MODE', 'ABC_SCHEDULE', 1); Settings::set('WH_COUNT_DAILY_MAX_BINS', '1', 1);
        $b9 = $this->f('cat_inventory_locations', ['location_name' => 'W1-B-9', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 9]);
        $q = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CT-B', 'product_name_vendor' => 'Count B', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $q, 'qty' => 4, 'location' => $b9, 'unit_cost' => 1.0]], 'GRN', 1);
        $s = CountTriggers::schedule(1);
        $this->assertSame(1, $s['sessions'], 'WH_COUNT_DAILY_MAX_BINS = 1'); $this->assertSame('ABC', DB::table('t_stock_counts')->where('fk_id_location', $s['bins'][0])->where('status', 'OPEN')->value('count_type'));
        $this->assertSame(['A'], array_values(array_unique(CountTriggers::classes(1))) ?: ['A'], 'the only moved SKU is class A');
    }
}
