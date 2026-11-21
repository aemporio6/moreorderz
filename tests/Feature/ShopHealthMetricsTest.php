<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\ShopHealth;
use App\Services\TaskEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-83: shop health metrics with proposed eBay defaults, red → task to the shop's TL, closed when green, import of the service-metrics report. */
class ShopHealthMetricsTest extends TestCase
{
    public function test_the_rule_example_red_task_to_the_tl_closed_next_month_and_import(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id); $f = fn (string $t, array $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayH', 'f_active' => 1]);
        $acc = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Tariq', 'sur_name' => 'Lead', 'f_active' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'SHOP-07', 'shop_name' => 'Seven', 'iso_branch' => 'S7', 'f_active' => 1]);
        $tl = \App\Models\User::create(['login_id' => 'tl-83@test', 'password_hash' => 'x', 'role_code' => 'TL', 'display_name' => 'Tariq Lead', 'fk_id_ent' => 1, 'f_active' => 1, 'fk_id_account' => $acc]);
        $this->makeUser('TL');      // another TL with no tasks: the shop's own TL must still get it
        DB::table('t_account_branches')->insert(RecordService::fillNotNull('t_account_branches', ['fk_id_branch' => $shop, 'fk_id_account' => $acc, 'role_code' => 'SUPERVISOR', 'd_from' => '2025-01-01', 'f_active' => 1]));
        $this->assertSame([0.5, 2.0, 3.0, 7.0, 0.3], [ShopHealth::DEFAULT_METRICS[0]['amber'], ShopHealth::DEFAULT_METRICS[0]['red'], ShopHealth::DEFAULT_METRICS[1]['amber'], ShopHealth::DEFAULT_METRICS[1]['red'], ShopHealth::DEFAULT_METRICS[2]['red']], 'proposed eBay defaults');
        $this->assertSame(['green', 'amber', 'red'], [ShopHealth::score(ShopHealth::DEFAULT_METRICS[0], 0.4), ShopHealth::score(ShopHealth::DEFAULT_METRICS[0], 1.5), ShopHealth::score(ShopHealth::DEFAULT_METRICS[0], 2.4)]);

        // defect rate 2.4 % for SHOP-07 Nov → red + task to the TL
        ShopHealth::save($shop, 2025, 11, 'metric:TDR', '2.4', $this->owner, '2026-01-10');
        $this->assertTrue(TaskEngine::isOpen('HEALTH_ALERT', [$shop, 'TDR', '2025-11']));
        $this->assertSame($tl->id, (int) DB::table('tasks')->where('dedupe_key', "HEALTH_ALERT:$shop:TDR:2025-11")->value('owner_user_id'), "the shop's TL");
        // 0.4 % next month → task closed
        ShopHealth::save($shop, 2025, 12, 'metric:TDR', '0.4', $this->owner, '2026-01-10');
        $this->assertFalse(TaskEngine::isOpen('HEALTH_ALERT', [$shop, 'TDR', '2025-11']));

        // the service-metrics report (Import Center)
        $csv = "shop,month,TDR,LDR,level\nSHOP-07,2025-10,0.3,8.5,2\nNOPE,2025-10,1,1,\nSHOP-07,2025-1,1,1,\n";
        $path = tempnam(sys_get_temp_dir(), 'sh'); file_put_contents($path, $csv);
        $r = $this->postJson('/api/shop-health/import', ['file' => new UploadedFile($path, 'metrics.csv', 'text/csv', null, true)])->assertOk()->json();
        $this->assertSame(3, $r['saved']); $this->assertCount(2, $r['refused']);
        $h = DB::table('mo_shop_health')->where(['fk_id_branch' => $shop, 'year' => 2025, 'month' => 10])->first();
        $this->assertSame(['TDR' => 0.3, 'LDR' => 8.5], json_decode($h->metrics_json, true)); $this->assertSame('2', (string) $h->seller_level);
        $this->assertTrue(TaskEngine::isOpen('HEALTH_ALERT', [$shop, 'LDR', '2025-10']), 'late shipment 8.5 % > 7 % → red');
    }
}
