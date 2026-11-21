<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\StockHealth;
use App\Services\StockService;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-28: Inventory health card "Inventory Management" – six rows from product stock, each a filtered list; mismatch also a task. */
class StockHealthTest extends TestCase
{
    private const ENT = 77;
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function product(string $sku, array $x = []): int { return $this->f('t_product_vendors', $x + ['fk_id_ent' => self::ENT, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Product $sku", 'stock' => 0, 'f_active' => 1, 'landing_price' => 2.5]); }
    private function at(int $p, string $sku, float $qty, ?int $loc): void
    {
        $lot = $this->f('fin_lots', ['company_id' => self::ENT, 'sku' => $sku, 'fk_id_product' => $p, 'qty' => $qty, 'qty_left' => $qty, 'unit_cost_gbp' => 2.5, 'received_date' => '2026-01-01', 'created_at' => now()]);
        DB::table('fin_lot_balances')->insert(['fk_id_lot' => $lot, 'fk_id_location' => $loc, 'qty' => $qty]);
    }
    private function row(array $card, string $key): ?array { return collect($card['rows'])->firstWhere('key', $key); }

    public function test_the_rule_example_stock_50_location_40_is_one_mismatch_and_one_task(): void
    {
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'HL-WH1', 'kind' => 'WAREHOUSE']);
        $p = $this->product('HL-50', ['stock' => 50]); $this->at($p, 'HL-50', 40, $wh);
        $this->assertSame(1, StockHealth::count(self::ENT, 'mismatch'));
        $this->assertSame('1', $this->row(StockHealth::card(self::ENT), 'mismatch')['figure'], 'no "items" word on mismatch (old)');
        StockService::selfCheck(self::ENT);
        $this->assertTrue(TaskEngine::isOpen('STK_LEDGER_MISMATCH', [$p]));
        $this->assertSame(1, DB::table('tasks')->where('dedupe_key', "STK_LEDGER_MISMATCH:$p")->count(), 'one task, deduped');
        StockService::selfCheck(self::ENT); $this->assertSame(1, DB::table('tasks')->where('dedupe_key', "STK_LEDGER_MISMATCH:$p")->count());
        // fixed → the row is 0 and the task closes
        DB::table('fin_lot_balances')->where('fk_id_location', $wh)->update(['qty' => 50]); DB::table('fin_lots')->where('fk_id_product', $p)->update(['qty' => 50, 'qty_left' => 50]);
        DB::table('t_stock_movements')->insert(RecordService::fillNotNull('t_stock_movements', ['fk_id_product' => $p, 'qty_change' => 50, 'movement_type' => 'purchase_in', 'created_at' => now()]));
        $this->assertSame(0, StockHealth::count(self::ENT, 'mismatch'));
        StockService::selfCheck(self::ENT); $this->assertFalse(TaskEngine::isOpen('STK_LEDGER_MISMATCH', [$p]));
    }

    public function test_every_row_its_count_and_its_filtered_list(): void
    {
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'HL-WH2', 'kind' => 'WAREHOUSE']);
        $low = $this->product('HL-LOW', ['stock' => 3, 'min_level' => 5]); $this->at($low, 'HL-LOW', 3, $wh);
        $this->product('HL-OOS', ['stock' => 0]); $flag = $this->product('HL-FLAG', ['stock' => 4, 'f_out_of_stock' => 1]); $this->at($flag, 'HL-FLAG', 4, $wh);
        $noloc = $this->product('HL-NOLOC', ['stock' => 7]);                       // stock with no location row → also a mismatch (new)
        $nocost = $this->product('HL-NOCOST', ['stock' => 2, 'landing_price' => 0]); $this->at($nocost, 'HL-NOCOST', 2, $wh);
        $this->product('HL-OFF', ['stock' => 0, 'f_active' => 0]);                 // inactive: not counted
        $c = StockHealth::card(self::ENT);
        $this->assertSame('Inventory Management', $c['title']);
        $this->assertSame(['Total units on hand', '16'], [$this->row($c, 'total')['label'], $this->row($c, 'total')['figure']], 'no visibility split yet → total');
        $this->assertSame('1 items', $this->row($c, 'low')['figure']);
        $this->assertSame('2 items', $this->row($c, 'oos')['figure'], 'stock ≤ 0 or the out-of-stock flag');
        $this->assertSame('1 items', $this->row($c, 'noloc')['figure']);
        $this->assertSame('1', $this->row($c, 'nocost')['figure'], 'cost null or ≤ 0, no "items" word');
        $this->assertSame('1', $this->row($c, 'mismatch')['figure']);
        $this->assertSame('#/stock/health?c=noloc', $this->row($c, 'noloc')['link']);
        $this->assertSame(['HL-NOLOC'], array_column(StockHealth::list(self::ENT, 'mismatch')['rows'], 'sku'));
        $this->assertSame(['HL-FLAG', 'HL-OOS'], array_column(StockHealth::list(self::ENT, 'oos')['rows'], 'sku'));
        // K5 split: private on any marketplace × shop = Private; pool otherwise
        DB::table('t_product_visibility')->insert([['fk_id_product' => $low, 'fk_id_marketplace' => 1, 'visibility_mode' => 'POOL'], ['fk_id_product' => $low, 'fk_id_marketplace' => 2, 'visibility_mode' => 'PRIVATE'], ['fk_id_product' => $noloc, 'fk_id_marketplace' => 1, 'visibility_mode' => 'POOL']]);
        $c = StockHealth::card(self::ENT);
        $this->assertSame(['3', '7'], [$this->row($c, 'private')['figure'], $this->row($c, 'pool')['figure']], 'one private row makes the product Private (old MIN() made it Pool)');
        $this->assertNull($this->row($c, 'total'));
    }

    public function test_the_card_and_the_list_over_http(): void
    {
        $this->actingAs($this->owner);
        $this->assertSame('Inventory Management', $this->getJson('/api/buying/card/stockhealth')->assertOk()->json('data.title'));
        $this->assertSame('Out of stock', $this->getJson('/api/stock-health?c=oos')->assertOk()->json('title'));
        $this->getJson('/api/stock-health?c=nope')->assertStatus(422);
    }
}
