<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\RecordService;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use App\Services\WarehouseMode;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-7: Simple / Full presets fill the switches; Simple → GRN to the SKU's PRIMARY bin, no putaway; Full → RECEIVING + putaway task; back to Simple waits for open putaways. */
class WarehouseModeTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function grn(int $wh): array
    {
        $r = $this->f('src_research', ['fk_id_ent' => 1, 'research_code' => 'RES-WM-' . uniqid(), 'title' => 'Mode mug', 'status' => 'SHORTLIST', 'target_sell_price' => 20, 'est_unit_cost' => 4, 'currency' => 'GBP', 'created_by' => $this->owner->id, 'created_at' => now()]);
        DB::table('src_research_suppliers')->insert(['fk_id_research' => $r, 'supplier_name_text' => 'Mode Supplier ' . uniqid(), 'unit_price' => 5.00, 'currency' => 'USD', 'moq' => 10, 'lead_days' => 20]);
        $go = Buy::go($r); Buy::acceptRfq($go['rfqs'][0]);
        $po = Buy::createPo(['rfq' => $go['rfqs'][0], 'qty' => 20, 'date' => '2026-08-01']); Buy::confirmPo($po);
        $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-08-18']);
        return [$go['product'], Buy::createGrn(['shipment' => $ship, 'location' => $wh, 'date' => '2026-08-20'])];
    }

    public function test_the_rule_example_simple_posts_to_the_primary_bin_full_to_receiving_with_a_putaway_task(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', '2026-08-01', 0.80);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Dock', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'A-1-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $at = fn (int $p, int $loc) => (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $p)->where('b.fk_id_location', $loc)->sum('b.qty');

        // Simple preset → straight to the PRIMARY home bin, no putaway task
        $this->postJson('/api/warehouse/mode', ['preset' => 'SIMPLE'])->assertOk();
        $this->assertSame('SIMPLE', $this->getJson('/api/warehouse/mode')->json('preset'));
        [$p1, $g1] = $this->grn($wh);
        DB::table('t_product_locations')->insert(RecordService::fillNotNull('t_product_locations', ['fk_id_product' => $p1, 'fk_id_location' => $bin, 'home_role' => 'PRIMARY']));
        Buy::postGrn($g1);
        $this->assertSame([20, 0], [$at($p1, $bin), $at($p1, $wh)], 'the GRN line posts straight to the SKU\'s PRIMARY bin');
        $this->assertSame(0, WarehouseMode::openPutaways(1));

        // Full preset → RECEIVING + a putaway task; every switch filled
        $this->postJson('/api/warehouse/mode', ['preset' => 'FULL'])->assertOk();
        $this->assertSame(['Y', 'Y', 'BATCH', 'TASK', 'ABC_SCHEDULE'], [Settings::get('WH_ZONES_ON', 1), Settings::get('WH_PUTAWAY_STEP', 1), Settings::get('WH_PICK_MODE', 1), Settings::get('WH_REPLEN_MODE', 1), Settings::get('WH_CYCLE_COUNT_MODE', 1)]);
        [$p2, $g2] = $this->grn($wh);
        DB::table('t_product_locations')->insert(RecordService::fillNotNull('t_product_locations', ['fk_id_product' => $p2, 'fk_id_location' => $bin, 'home_role' => 'PRIMARY']));
        Buy::postGrn($g2);
        $rec = Stock::systemLocation(1, 'RECEIVING');
        $this->assertSame([20, 0], [$at($p2, $rec), $at($p2, $bin)], 'the same GRN posts to RECEIVING');
        $this->assertTrue(TaskEngine::isOpen('WH_PUTAWAY_WAITING', [$g2, $p2]));
        // back to Simple waits for the open putaway
        $this->postJson('/api/warehouse/mode', ['preset' => 'SIMPLE'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, '1 putaway task open'));
        $row = collect($this->getJson('/api/putaway')->assertOk()->json('rows'))->firstWhere('product', $p2);
        $this->assertSame([20, $bin], [$row['qty'], (int) $row['to']['bin']], 'suggested: the main home');
        $task = $row['task'];
        $this->postJson("/api/putaway/$task", ['bin' => $bin, 'qty' => 5])->assertOk();
        $this->assertTrue(TaskEngine::isOpen('WH_PUTAWAY_WAITING', [$g2, $p2]), '15 still waiting');
        $this->postJson("/api/putaway/$task", ['bin' => $bin])->assertOk();
        $this->assertSame([0, 20], [$at($p2, $rec), $at($p2, $bin)]);
        $this->assertFalse(TaskEngine::isOpen('WH_PUTAWAY_WAITING', [$g2, $p2]));
        $this->postJson('/api/warehouse/mode', ['preset' => 'SIMPLE'])->assertOk();
        $this->assertSame('N', Settings::get('WH_PUTAWAY_STEP', 1));
        $this->actingAs($this->makeUser('VA'))->postJson('/api/warehouse/mode', ['preset' => 'FULL'])->assertStatus(403);
    }
}
