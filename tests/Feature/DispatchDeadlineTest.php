<?php

namespace Tests\Feature;

use App\Services\DispatchDeadline;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H_WAREHOUSE R-H-24: dispatch-by from handling days in working days of the site's country (weekends + bank holidays), cut-off time; board priority. */
class DispatchDeadlineTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_friday_15_00_after_cutoff_monday_holiday_is_tuesday(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $uk = $this->f('cat_country', ['country_name' => 'Deadlineland', 'country_code' => '+44', 'iso_code' => 'D' . random_int(10, 99), 'f_active' => 1]);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'DLmp', 'f_active' => 1]);
        $prof = $this->f('mp_profiles', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'code' => 'DL' . random_int(100, 999), 'name' => 'DL profile', 'handling_days' => 1, 'dispatch_cutoff_time' => '14:00:00', 'f_active' => 1]);
        $site = $this->f('cat_inventory_locations', ['location_name' => 'DL site', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_country' => $uk, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'fk_id_mp_profile' => $prof, 'shop_no' => 'DL1', 'iso_branch' => 'DL1', 'shop_name' => 'Shop DL', 'f_active' => 1, 'fk_id_main_location' => $site]);
        // Friday 2026-11-27 15:00, cut-off 14:00; Monday 2026-11-30 a bank holiday in the site's country → Tuesday end of day
        $this->f('cal_non_working_days', ['fk_id_country' => $uk, 'fk_id_ent' => 1, 'd_day' => '2026-11-30', 'name' => 'Bank holiday']);
        $this->assertSame('2026-12-01 23:59:59', DispatchDeadline::compute(1, $shop, '2026-11-27 15:00:00'));
        $this->assertSame('2026-11-27 23:59:59', DispatchDeadline::compute(1, $shop, '2026-11-27 13:30:00'), 'before the cut-off: Friday');
        $this->assertSame('2026-12-01 23:59:59', DispatchDeadline::compute(1, $shop, '2026-11-28 10:00:00'), 'Saturday counts from the next working day');
        DB::table('mp_profiles')->where('id', $prof)->update(['handling_days' => 2]);
        $this->assertSame('2026-12-01 23:59:59', DispatchDeadline::compute(1, $shop, '2026-11-27 10:00:00'), '2 handling days: Friday + Tuesday (Monday is a holiday)');
        DB::table('mp_profiles')->where('id', $prof)->update(['handling_days' => 1]);

        // intake: the file's date wins; otherwise computed
        Sales::intake($shop, [['order_no' => 'DL-1', 'd_order' => '2026-11-27', 'order_time' => '2026-11-27 15:00:00', 'sku' => 'DL-X', 'qty' => 1, 'sale_rate' => 5],
            ['order_no' => 'DL-2', 'd_order' => '2026-11-27', 'sku' => 'DL-Y', 'qty' => 1, 'sale_rate' => 5, 'dispatch_by' => '2026-11-29']], ['no_zero_task' => true]);
        $this->assertSame(['2026-12-01 23:59:59', '2026-11-29 00:00:00'], [(string) DB::table('t_sales')->where('order_no', 'DL-1')->value('dispatch_by'), (string) DB::table('t_sales')->where('order_no', 'DL-2')->value('dispatch_by')]);

        // board priority: overdue → due today before collection → due today → later; express first
        $now = '2026-12-01 10:00:00';
        $this->assertSame([0, 1, 2, 3, 3], [DispatchDeadline::bucket('2026-11-30 23:59:59', '15:00', $now), DispatchDeadline::bucket('2026-12-01 23:59:59', '15:00:00', $now), DispatchDeadline::bucket('2026-12-01 23:59:59', '09:00:00', $now), DispatchDeadline::bucket('2026-12-02 23:59:59', '15:00', $now), DispatchDeadline::bucket(null, null, $now)]);
        $rows = DispatchDeadline::sort([['id' => 1, 'fk_id_branch' => $shop, 'dispatch_by' => '2026-12-02 23:59:59', 'd_order' => '2026-11-28'], ['id' => 2, 'fk_id_branch' => $shop, 'dispatch_by' => '2026-11-29 00:00:00', 'd_order' => '2026-11-27'],
            ['id' => 3, 'fk_id_branch' => $shop, 'dispatch_by' => '2026-12-02 23:59:59', 'd_order' => '2026-11-29', 'carrier_service_text' => 'Express 24']], $now);
        $this->assertSame([2, 3, 1], array_column($rows, 'id'), 'overdue first; among later ones express first');
    }
}
