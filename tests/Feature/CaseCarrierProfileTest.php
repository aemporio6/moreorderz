<?php

namespace Tests\Feature;

use App\Services\Carriers;
use App\Services\Context;
use App\Services\DispatchService as Dispatch;
use App\Services\DueEngine;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-A_SALES-90: case channel / priority / reply-by (red when passed, a due item, no new task), carrier service facts with the weight limit at pack, profile returns window and seller portal link. */
class CaseCarrierProfileTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_a90_case_facts_service_weight_returns_window_and_portal_link(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'N90', 'f_active' => 1]);
        $prof = $this->f('mp_profiles', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'code' => 'N9' . random_int(100, 999), 'name' => 'Profile 90', 'f_active' => 1, 'return_window_days' => 30, 'seller_portal_url' => 'https://seller.example.test/home']);
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier N', 'f_active' => 1]);
        $svc = $this->f('t_carrier_services', ['fk_id_carrier' => $car, 'service_name' => 'Small parcel 48', 'f_active' => 1, 'delivery_days' => 2, 'max_weight_kg' => 2, 'f_tracked' => 1, 'f_signature' => 0]);
        $free = $this->f('t_carrier_services', ['fk_id_carrier' => $car, 'service_name' => 'Any weight', 'f_active' => 1]);
        $p48 = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'N Small 48', 'fk_id_carrier' => $car, 'fk_id_car_service' => $svc, 'f_active' => 1, 'needs_weight' => 0]);
        $pAny = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'N Any', 'fk_id_carrier' => $car, 'fk_id_car_service' => $free, 'f_active' => 1, 'needs_weight' => 0]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'N90-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'fk_id_mp_profile' => $prof, 'shop_no' => 'N90', 'iso_branch' => 'N90', 'shop_name' => 'Shop 90', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fk_id_default_carrier' => $car, 'fulfilment_channel' => 'SELF']);

        // carrier service facts next to the service; a parcel heavier than the service takes is refused; empty = no limit
        $this->assertSame('2 days · max 2 kg · tracked', Carriers::serviceFacts($p48)['text']); $this->assertSame('', Carriers::serviceFacts($pAny)['text']);
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'N90-POT', 'product_name_vendor' => 'Pot', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'pack_weight_g' => 2500]);
        Stock::receive(1, [['product' => $prod, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2.0]], 'GRN', 1);
        $old = date('Y-m-d', strtotime('-45 days'));
        Sales::intake($shop, [['order_no' => 'N90-O1', 'd_order' => $old, 'sku' => 'N90-POT', 'qty' => 1, 'sale_rate' => 30, 'ship_to_country_code' => 'GB']]);
        Sales::intake($shop, [['order_no' => 'N90-O2', 'd_order' => date('Y-m-d'), 'sku' => 'N90-POT', 'qty' => 1, 'sale_rate' => 30, 'ship_to_country_code' => 'GB']]);
        $task = Dispatch::buildPickList(1); foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->pluck('id') as $l) Dispatch::pick((int) $l);
        $presets = collect($this->getJson("/api/pack/check?order_no=N90-O1&branch=$shop")->assertOk()->json('presets'))->keyBy('id');
        $this->assertSame('2 days · max 2 kg · tracked', $presets[$p48]['facts']['text']);
        try { Dispatch::pack(['order_no' => 'N90-O1', 'branch' => $shop, 'service' => $p48, 'weight_g' => 2500, 'service_reason' => 'test', 'tracking_no' => 'N90T1']); $this->fail('a 2.5 kg parcel went on a 2 kg service'); }
        catch (\InvalidArgumentException $e) { $this->assertSame('Small parcel 48 takes at most 2 kg – choose another service.', $e->getMessage()); }
        $this->assertSame(0, DB::table('wh_parcels')->where('order_no', 'N90-O1')->count());
        Dispatch::pack(['order_no' => 'N90-O1', 'branch' => $shop, 'service' => $pAny, 'weight_g' => 2500, 'service_reason' => 'heavy', 'tracking_no' => 'N90T1']);
        Dispatch::pack(['order_no' => 'N90-O2', 'branch' => $shop, 'service' => $pAny, 'weight_g' => 2500, 'service_reason' => 'heavy', 'tracking_no' => 'N90T2']);
        $s = Dispatch::openHandover($car); Dispatch::scan($s, 'N90T1'); Dispatch::scan($s, 'N90T2'); Dispatch::closeHandover($s);

        // returns window of the profile: 45 days after the order with a 30-day window → flagged; a fresh order is not
        $late = Dispatch::receiveReturn(['code' => 'N90T1', 'qty' => 1, 'grade' => 'RESELLABLE_NEW']);
        $this->assertSame('outside the returns window (30 days from the order date)', $late['window_flag']);
        $this->assertStringContainsString('[outside the returns window', (string) DB::table('t_sale_returns')->where('id', $late['return'])->value('note'));
        $this->assertNull(Dispatch::receiveReturn(['code' => 'N90T2', 'qty' => 1, 'grade' => 'RESELLABLE_NEW'])['window_flag']);

        // seller portal link button on the shop page
        $links = collect(RecordService::page((string) RecordService::formOfTable('t_branches'), $shop)['parents'])->firstWhere('label', 'Seller portal');
        $this->assertSame(['https://seller.example.test/home', true], [$links['url'], $links['external']]);

        // customer case: came through, priority, reply by; red when the date has passed; a due item, no second task
        $sale = (int) DB::table('t_sales')->where('order_no', 'N90-O2')->value('id');
        $ref = DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id'); $type = DB::table('reference_items')->insertGetId(['fk_reference_id' => $ref, 'reference_name' => 'Late delivery 90', 'fk_id_ent' => 0, 'sort_no' => 1, 'f_active' => 1, 'f_pending' => 0, 'created_at' => now()]);
        $case = (int) $this->postJson("/api/sales/$sale/case", ['type' => $type, 'reason' => 'Buyer asks where it is'])->assertOk()->json('id');
        $this->postJson("/api/sale-cases/$case/facts", ['case_channel' => 'FAX'])->assertStatus(422);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->postJson("/api/sale-cases/$case/facts", ['case_channel' => 'EMAIL', 'priority' => 'HIGH', 'd_reply_by' => $yesterday])->assertOk();
        $row = collect($this->getJson('/api/sale-cases?status=PENDING')->assertOk()->json('rows'))->firstWhere('id', $case);
        $this->assertSame(['E-mail', 'HIGH', true, $yesterday], [$row['channel'], $row['priority'], $row['overdue'], $row['reply_by_iso']]);
        $tasks = fn () => DB::table('tasks')->count(); $before = $tasks();
        $t = DB::table('tasks')->where('dedupe_key', 'SALE_QUERY:' . $case)->first(); $this->assertSame([$yesterday, 'HIGH'], [substr((string) $t->due_at, 0, 10), $t->priority]);
        DueEngine::run(1, null, ['t_sale_order_reminders']);
        $item = DB::table('sys_due_items as i')->join('sys_due_sources as s', 's.id', '=', 'i.fk_id_source')->where('s.code', 'CASE_REPLY')->where('i.record_id', $case)->first(['i.status', 'i.title', 'i.fk_id_task']);
        $this->assertSame(['OVERDUE', 'Reply to the case of order N90-O2', null], [$item->status, $item->title, $item->fk_id_task]);
        $this->assertSame($before, $tasks(), 'no new task – the case has its own');
        $cust = $this->f('crm_customers', ['fk_id_ent' => 1]); DB::table('t_sale_order_reminders')->where('id', $case)->update(['fk_id_customer' => $cust]);
        $open = RecordService::page((string) RecordService::formOfTable('crm_customers'), $cust)['sections']['Open cases'][0];
        $this->assertTrue($open['alert']); $this->assertStringContainsString('OVERDUE', $open['value']); $this->assertSame('Order N90-O2', $open['label']);
        $this->postJson("/api/sale-cases/$case/resolve")->assertOk(); DueEngine::run(1, null, ['t_sale_order_reminders']);
        $this->assertSame('DONE', DB::table('sys_due_items as i')->join('sys_due_sources as s', 's.id', '=', 'i.fk_id_source')->where('s.code', 'CASE_REPLY')->where('i.record_id', $case)->value('i.status'));
    }
}
