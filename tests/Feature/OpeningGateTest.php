<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\OpeningBalances as OB;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-87 GRN lots only after the opening balances are LOCKED and dated on / after the as-of date; status lists are Reference Register lists. */
class OpeningGateTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_g87_opening_gate_and_status_lists(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $main = $this->f('cat_inventory_locations', ['location_name' => 'Main WH 87', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-87', 'supplier_name' => 'Gate Co', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'OG-87', 'product_name_vendor' => 'Gate item', 'f_active' => 1]);
        $cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-87', 'product_name' => 'G', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 1, 'created_at' => now()]);
        $grn = function (string $date) use ($cy, $sup, $p, $main) {
            $po = Buy::createPo(['cycle' => $cy, 'supplier' => $sup, 'currency' => 'GBP', 'date' => $date, 'lines' => [['product' => $p, 'qty' => 10, 'unit_price' => 2.0]]]); Buy::confirmPo($po);
            $ship = Buy::createShipment(['po' => $po, 'eta' => $date]); $l = DB::table('t_src_po_lines')->where('fk_id_po', $po)->value('id');
            return Buy::createGrn(['shipment' => $ship, 'location' => $main, 'date' => $date, 'lines' => [['po_line' => $l, 'received' => 10, 'damaged' => 0, 'location' => $main]]]);
        };
        $this->assertSame(['SHIPPED', 'IN_TRANSIT', 'AT_PORT', 'CUSTOMS'], OB::statuses('TRANSIT_STATUSES'));
        $this->assertTrue(DB::table('cat_references')->where('ref_code', 'GRN_POSTED_STATUSES')->exists(), 'a Reference Register list, not config text');
        $this->postJson('/api/opening', ['as_of' => '2026-10-01'])->assertOk()->assertJsonPath('as_of', '2026-10-01');
        // not locked yet → no lot
        $g1 = $grn('2026-10-15'); try { Buy::postGrn($g1); $this->fail('refused before the lock'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Lock the opening balances first', $e->getMessage()); }
        $this->assertSame(0, DB::table('fin_lots')->where('fk_id_product', $p)->count());
        $va = $this->makeUser('MANAGER'); $this->actingAs($va)->postJson('/api/opening/lock')->assertStatus(422)->assertJsonPath('message', 'Only the Owner locks the opening balances.');
        $this->actingAs($this->owner)->postJson('/api/opening/lock')->assertStatus(422)->assertJsonPath('message', 'Nothing to lock: enter the opening balances first.');      // R-FIN-20
        $this->postJson('/api/opening/rows', ['type' => 'STOCK_VALUE', 'as_of_date' => '2026-10-01', 'amount' => 100])->assertOk();
        $this->postJson('/api/opening/lock')->assertOk()->assertJsonPath('locked', true);
        $this->postJson('/api/opening', ['as_of' => '2026-09-01'])->assertStatus(422);
        // dated before the as-of date → posted, no lot
        $g0 = $grn('2026-09-20'); $r = Buy::postGrn($g0); $this->assertTrue($r['before_opening']); $this->assertSame('POSTED', DB::table('t_src_grn')->where('id', $g0)->value('posting_status'));
        $this->assertSame(0, DB::table('fin_lots')->where('fk_id_product', $p)->count());
        // after the lock and dated after → lot with the landed cost in GBP
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1]); $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'G87', 'iso_branch' => 'G87', 'shop_name' => 'Gate shop', 'f_active' => 1]);
        $this->assertSame('GREEN', collect(\App\Services\CloseService::check(1, $shop, '2026-10')['checks'])->firstWhere('code', 'OPENING_LOCKED')['status'], 'close check: opening locked');
        Buy::postGrn($g1); $lot = DB::table('fin_lots')->where('fk_id_product', $p)->first(); $this->assertSame([10, 2.0], [(int) $lot->qty, (float) $lot->unit_cost_gbp]);
    }
}
