<?php

namespace Tests\Feature;

use App\Services\DispatchService as Dispatch;
use App\Services\Ledger;
use App\Services\LostParcels;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-36: lost parcel after claim_days → WH_PARCEL_LOST; case → claim → PAID booked on the shop; resend = new parcel with a new reservation; the lost parcel stays shipped. */
class LostParcelsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_handed_over_10_days_claim_7_paid_12_and_resend(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id); Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'LPmp', 'f_active' => 1]);
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier L', 'claim_days' => 7, 'f_active' => 1]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'LP-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'LP1', 'iso_branch' => 'LP1', 'shop_name' => 'Shop LP', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fk_id_default_carrier' => $car, 'fulfilment_channel' => 'SELF']);
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LP-MUG', 'product_name_vendor' => 'Mug', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'pack_weight_g' => 300]);
        Stock::receive(1, [['product' => $prod, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2.0]], 'GRN', 1);
        Sales::intake($shop, [['order_no' => 'LP-O1', 'd_order' => '2026-11-01', 'sku' => 'LP-MUG', 'qty' => 1, 'sale_rate' => 20]]);
        $pick = fn () => array_map(fn ($l) => Dispatch::pick((int) $l), DB::table('wh_pick_task_lines')->where('fk_id_pick_task', Dispatch::buildPickList(1))->pluck('id')->all());
        $pick();
        $p1 = Dispatch::pack(['order_no' => 'LP-O1', 'branch' => $shop, 'weight_g' => 300, 'carrier' => $car, 'tracking_no' => 'LPTRK1']);
        $ses = Dispatch::openHandover($car); Dispatch::scan($ses, 'LPTRK1'); Dispatch::closeHandover($ses);
        DB::table('wh_parcels')->where('id', $p1)->update(['handed_over_at' => now()->subDays(10)]);
        $avail = Stock::available($prod);

        // 10 days after handover, claim_days 7, not delivered → WH_PARCEL_LOST
        $this->assertSame(1, LostParcels::sweep(1)); $this->assertTrue(TaskEngine::isOpen('WH_PARCEL_LOST', [$p1]));
        $this->getJson('/api/lost-parcels')->assertOk()->assertJsonPath('rows.0.id', $p1);
        $this->postJson("/api/lost-parcels/$p1/claim", ['ref' => 'CL-1', 'amount' => 12])->assertStatus(422);
        $this->postJson("/api/lost-parcels/$p1/open")->assertOk();
        $this->postJson("/api/lost-parcels/$p1/claim", ['ref' => 'CL-1', 'amount' => 12])->assertOk();
        $this->assertSame(['CLAIMED', 'CL-1'], [DB::table('wh_parcels')->where('id', $p1)->value('claim_status'), DB::table('wh_parcels')->where('id', $p1)->value('claim_ref')]);
        // the lost parcel stays shipped: no stock comes back
        $this->assertSame('SHIPPED', DB::table('wh_reservations')->where('fk_id_sale', DB::table('wh_parcel_lines')->where('fk_id_parcel', $p1)->value('fk_id_sale'))->value('status'));
        $this->assertSame($avail, Stock::available($prod));
        // claim £12 PAID → income on the shop
        $this->actingAs($this->makeUser('WAREHOUSE'))->postJson("/api/lost-parcels/$p1/settle", ['result' => 'PAID'])->assertStatus(403);
        $this->actingAs($this->owner)->postJson("/api/lost-parcels/$p1/settle", ['result' => 'PAID'])->assertOk();
        $this->assertSame(-12.00, Ledger::balance(1, '4100'), 'other income credited 12.00');
        $j = DB::table('gl_journals')->where('source_type', 'PARCEL_CLAIM')->where('source_id', $p1)->value('id');
        $this->assertSame($shop, (int) DB::table('gl_lines as l')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $j)->where('a.code', '4100')->value('l.ref_id'), 'booked on the shop');
        $this->assertFalse(TaskEngine::isOpen('WH_PARCEL_LOST', [$p1]));

        // resend → a new reservation, picked and packed as a new parcel on the same order
        $this->postJson("/api/lost-parcels/$p1/resend")->assertOk();
        $this->postJson("/api/lost-parcels/$p1/resend")->assertStatus(422);
        $this->assertSame(1, DB::table('wh_reservations')->where('reason', 'RESEND')->where('status', 'OPEN')->count());
        $pick();
        $p2 = Dispatch::pack(['order_no' => 'LP-O1', 'branch' => $shop, 'weight_g' => 300, 'carrier' => $car, 'tracking_no' => 'LPTRK2']);
        $this->assertNotSame($p1, $p2); $this->assertSame($p1, (int) DB::table('wh_parcels')->where('id', $p2)->value('resend_of'));
        $this->assertSame($avail - 1, Stock::available($prod), 'the replacement used one more unit');
    }
}
