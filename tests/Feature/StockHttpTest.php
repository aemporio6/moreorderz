<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockHttpTest extends TestCase
{
    private function fill(string $table, array $data): array
    {
        $m = new \ReflectionMethod(\App\Services\RecordService::class, 'fillNotNull');
        return $m->invoke(null, $table, $data);
    }

    private function seedStock(): array
    {
        $p = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'min_level' => 5, 'f_alert_min_level' => 1]));
        $a = DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        $b = DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['location_name' => 'Shelf B', 'kind' => 'SHELF', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        return [$p, $a, $b];
    }

    public function test_opening_stock_move_adjust_through_the_screens_api(): void
    {
        [$p, $a, $b] = $this->seedStock();
        $this->actingAs($this->owner);
        $this->postJson('/api/stock/opening', ['product' => $p, 'location' => $a, 'qty' => 20, 'unit_cost' => 3.5, 'received_date' => '2026-08-01'])->assertOk();
        $this->postJson('/api/stock/transfer', ['product' => $p, 'qty' => 6, 'from' => $a, 'to' => $b])->assertOk();
        $this->postJson('/api/stock/transfer', ['product' => $p, 'qty' => 60, 'from' => $a, 'to' => $b])->assertStatus(422)->assertJsonPath('message', 'Not enough stock of BOWL-01 at Main: need 60, have 14.');
        $this->postJson('/api/stock/transfer', ['product' => $p, 'qty' => 1, 'from' => $a, 'to' => $a])->assertStatus(422);
        $this->postJson('/api/stock/adjust', ['product' => $p, 'location' => $b, 'qty' => -1])->assertStatus(422);                 // no reason
        $this->postJson('/api/stock/adjust', ['product' => $p, 'location' => $b, 'qty' => -1, 'reason' => 'Broken'])->assertOk();
        $d = $this->getJson('/api/stock/on-hand')->assertOk()->json();
        $this->assertSame(19, $d['total_units']);
        $this->assertSame(66.5, (float) $d['total_value']);
        $this->assertSame(Ledger::balance(1, '1200'), (float) $d['total_value']);
        $prod = $this->getJson("/api/stock/product/$p")->assertOk()->json();
        $this->assertSame([14, 5], array_column($prod['rows'], 'qty'));
        $led = $this->getJson("/api/stock/ledger?product=$p")->assertOk()->json();
        $this->assertSame(4, $led['total']);
        $this->assertSame(1, count($this->getJson('/api/stock/lots')->json('rows')));
        $this->assertTrue($this->getJson('/api/stock/check')->json('value_matches_ledger'));
    }

    public function test_roles_warehouse_changes_stock_but_sees_no_money_va_sees_nothing(): void
    {
        [$p, $a, $b] = $this->seedStock();
        Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $a, 'unit_cost' => 3.5]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $wh = $this->makeUser('WAREHOUSE');
        $d = $this->actingAs($wh)->getJson('/api/stock/on-hand')->assertOk()->json();
        $this->assertFalse($d['show_value']);
        $this->assertNull($d['total_value']);
        $this->assertArrayNotHasKey('value', $d['rows'][0]);
        $this->assertArrayNotHasKey('unit_cost_gbp', $this->getJson('/api/stock/ledger')->json('rows')[0]);
        $this->postJson('/api/stock/transfer', ['product' => $p, 'qty' => 2, 'from' => $a, 'to' => $b])->assertOk();
        $this->postJson('/api/stock/opening', ['product' => $p, 'location' => $a, 'qty' => 5, 'unit_cost' => 1, 'received_date' => '2026-08-01'])->assertStatus(403);
        $va = $this->makeUser('VA');
        $this->actingAs($va)->getJson('/api/stock/on-hand')->assertStatus(403);
        $this->actingAs($va)->postJson('/api/stock/adjust', ['product' => $p, 'location' => $a, 'qty' => -1, 'reason' => 'x y z'])->assertStatus(403);
    }

    public function test_nightly_job_opens_low_stock_task_and_closes_it_when_stock_is_back(): void
    {
        [$p, $a] = $this->seedStock();
        Stock::receive(1, [['product' => $p, 'qty' => 4, 'location' => $a, 'unit_cost' => 3.5]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->artisan('mo:nightly')->assertExitCode(0);
        $this->assertTrue(TaskEngine::isOpen('STK_LOW', [$p]));
        $this->assertContains('Low stock', array_column($this->actingAs($this->owner)->getJson('/api/home')->json('cards'), 'label'));
        Stock::receive(1, [['product' => $p, 'qty' => 50, 'location' => $a, 'unit_cost' => 3.5]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->artisan('mo:nightly')->assertExitCode(0);
        $this->assertFalse(TaskEngine::isOpen('STK_LOW', [$p]));
    }

    public function test_count_flow_prices_bins_pool_and_held_over_http(): void
    {
        [$p, $a, $b] = $this->seedStock();
        Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $a, 'unit_cost' => 2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $wh = $this->makeUser('WAREHOUSE'); $mgr = $this->makeUser('MANAGER');
        $id = $this->actingAs($wh)->postJson('/api/stock/counts', ['location' => $a])->assertOk()->json('id');
        $c = $this->getJson("/api/stock/counts/$id")->assertOk()->json();
        $this->assertTrue($c['count']['blind']);
        $this->assertNull($c['lines'][0]['qty_system'], 'blind count hides the system number');
        $this->postJson("/api/stock/counts/$id/lines", ['lines' => [['product' => $p, 'qty' => 9, 'note' => 'one missing']]])->assertOk();
        $this->postJson("/api/stock/counts/$id/submit")->assertOk();
        $this->postJson("/api/stock/counts/$id/approve")->assertStatus(403);                       // warehouse cannot approve
        $this->assertSame(10, $this->getJson("/api/stock/counts/$id")->json('lines.0.qty_system'), 'after submit the system number shows');
        $this->actingAs($mgr)->postJson("/api/stock/counts/$id/post")->assertStatus(422);          // not approved yet
        $this->postJson("/api/stock/counts/$id/approve")->assertOk();
        $this->postJson("/api/stock/counts/$id/post")->assertOk()->assertJsonPath('lines_posted', 1);
        $this->assertSame(9, Stock::balance($p));

        $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1]));
        $this->postJson("/api/prices/$p", ['marketplace' => $mp, 'raw_price' => 5, 'sale_price' => 9.99, 'from_date' => '2026-08-01'])->assertOk();
        $this->postJson("/api/prices/$p", ['marketplace' => $mp, 'raw_price' => 5, 'sale_price' => 9.99, 'from_date' => '2026-08-01'])->assertStatus(422);
        $this->assertCount(1, $this->getJson("/api/prices/$p")->assertOk()->json('timeline'));
        $this->actingAs($wh)->postJson("/api/prices/$p", ['marketplace' => $mp, 'raw_price' => 5, 'sale_price' => 1, 'from_date' => '2026-09-01'])->assertStatus(403);

        $this->actingAs($mgr)->postJson('/api/stock/bins', ['parent' => $a, 'zone' => 'B', 'levels' => 2, 'positions' => 2, 'create' => true])->assertOk()->assertJsonPath('created', 4);
        $this->actingAs($wh)->postJson('/api/stock/bins', ['parent' => $a, 'zone' => 'C', 'levels' => 1, 'positions' => 1, 'create' => true])->assertStatus(403);
        $this->actingAs($wh)->getJson('/api/stock/pool')->assertStatus(403);                       // pool shows money
        $this->assertCount(1, $this->actingAs($mgr)->getJson('/api/stock/pool')->assertOk()->json('rows'));
        $this->assertSame([], $this->getJson('/api/stock/held')->assertOk()->json('rows'));
    }
}
