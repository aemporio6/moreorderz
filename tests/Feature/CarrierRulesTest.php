<?php

namespace Tests\Feature;

use App\Services\CarrierRules;
use App\Services\DispatchService as Dispatch;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-30: carrier service rules – first match by priority suggests; buyer's chosen service wins when the profile says so; no match → default + hint; a change needs a reason (logged). */
class CarrierRulesTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_420_g_30_pounds_suggests_carrier_a_48h(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id); Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'CRmp', 'f_active' => 1]);
        $prof = $this->f('mp_profiles', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'code' => 'CRP' . random_int(100, 999), 'name' => 'CR profile', 'f_service_from_order' => 0, 'f_active' => 1]);
        $carA = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier A', 'f_active' => 1]); $carB = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier B', 'f_active' => 1]);
        $a48 = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Carrier A 48h', 'fk_id_carrier' => $carA, 'f_active' => 1, 'needs_weight' => 0]);
        $bTr = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Carrier B tracked', 'fk_id_carrier' => $carB, 'f_active' => 1, 'needs_weight' => 0]);
        $exp = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Carrier A express', 'fk_id_carrier' => $carA, 'f_active' => 1, 'needs_weight' => 0]);
        $this->f('carrier_service_rules', ['fk_id_ent' => 1, 'priority' => 10, 'weight_to_g' => 2000, 'value_to' => 50, 'fk_id_carrier' => $carA, 'fk_id_car_pkg_svc' => $a48, 'f_active' => 1]);
        $this->f('carrier_service_rules', ['fk_id_ent' => 1, 'priority' => 20, 'dest_country_group' => 'DOMESTIC', 'fk_id_carrier' => $carB, 'fk_id_car_pkg_svc' => $bTr, 'f_active' => 1]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'CR-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'fk_id_mp_profile' => $prof, 'shop_no' => 'CR1', 'iso_branch' => 'CR1', 'shop_name' => 'Shop CR', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fk_id_default_carrier' => $carB, 'fulfilment_channel' => 'SELF']);

        // the rule example: 420 g, £30 → Carrier A 48h; heavier → else rule (Carrier B tracked)
        $s = CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'GB', 'weight_g' => 420, 'value' => 30]);
        $this->assertSame([$a48, $carA], [$s['service'], $s['carrier']]); $this->assertStringContainsString('Carrier A 48h', $s['why']);
        $this->assertSame($bTr, CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'GB', 'weight_g' => 2400, 'value' => 30])['service']);
        $this->assertSame($bTr, CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'GB', 'weight_g' => 420, 'value' => 50])['service'], 'value < 50 only');
        // nothing fits (abroad, heavy) → the shop's default and a hint
        $n = CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'US', 'weight_g' => 5000, 'value' => 80]);
        $this->assertNull($n['rule']); $this->assertStringContainsString('No carrier rule fits this parcel (WORLD, 5000 g', $n['hint']); $this->assertSame($carB, $n['carrier']);
        $this->assertSame(['DOMESTIC', 'REGION', 'WORLD'], [CarrierRules::group(1, 'GB'), CarrierRules::group(1, 'DE'), CarrierRules::group(1, 'US')]);
        // the buyer chose the service: the map wins when the profile says so
        $this->f('carrier_service_map', ['fk_id_mp_profile' => $prof, 'service_text' => 'Express Delivery', 'fk_id_carrier' => $carA, 'fk_id_car_pkg_svc' => $exp, 'f_active' => 1]);
        $this->assertSame($a48, CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'GB', 'weight_g' => 420, 'value' => 30, 'service_text' => 'express delivery'])['service'], 'profile off: the rules decide');
        DB::table('mp_profiles')->where('id', $prof)->update(['f_service_from_order' => 1]);
        $this->assertSame($exp, CarrierRules::suggest(1, ['branch' => $shop, 'country' => 'GB', 'weight_g' => 420, 'value' => 30, 'service_text' => 'express delivery'])['service']);
        DB::table('mp_profiles')->where('id', $prof)->update(['f_service_from_order' => 0]);

        // at pack: the suggestion is used; another service needs a reason, which is logged
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CR-MUG', 'product_name_vendor' => 'Mug', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'pack_weight_g' => 420]);
        Stock::receive(1, [['product' => $prod, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2.0]], 'GRN', 1);
        foreach (['CR-O1', 'CR-O2'] as $o) Sales::intake($shop, [['order_no' => $o, 'd_order' => '2026-11-10', 'sku' => 'CR-MUG', 'qty' => 1, 'sale_rate' => 30, 'ship_to_country_code' => 'GB']]);
        $task = Dispatch::buildPickList(1); foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->pluck('id') as $l) Dispatch::pick((int) $l);
        $this->getJson("/api/pack/check?order_no=CR-O1&branch=$shop")->assertOk()->assertJsonPath('service.service', $a48);
        $p1 = Dispatch::pack(['order_no' => 'CR-O1', 'branch' => $shop, 'weight_g' => 420]);
        $this->assertSame([$a48, $carA], [(int) DB::table('wh_parcels')->where('id', $p1)->value('fk_id_car_pkg_svc'), (int) DB::table('wh_parcels')->where('id', $p1)->value('fk_id_carrier')]);
        $this->postJson('/api/pack', ['order_no' => 'CR-O2', 'branch' => $shop, 'weight_g' => 420, 'service' => $bTr])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Give the reason for changing the suggested carrier service'));
        $p2 = $this->postJson('/api/pack', ['order_no' => 'CR-O2', 'branch' => $shop, 'weight_g' => 420, 'service' => $bTr, 'service_reason' => 'Buyer asked for tracking'])->assertOk()->json('id');
        $this->assertSame('Buyer asked for tracking', DB::table('sys_audit_log')->where('table_name', 'wh_parcels')->where('record_id', $p2)->value('reason'));
    }
}
