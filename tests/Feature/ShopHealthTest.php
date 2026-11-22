<?php

namespace Tests\Feature;

use App\Services\Perm;
use App\Services\RecordService;
use App\Services\ShopHealth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-73 / 74: monthly account health per shop – rows, running totals, locks, limits + alert task, admin / TL view, history. */
class ShopHealthTest extends TestCase
{
    /** These tests are about their own rules; the shop scope by role (R-G_CORE-63, Settings › Choices OWN_SHOP_ROLES) is tested in ScopeSecureTest. */
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1); }
    private int $mp; private int $shopA; private int $shopB; private object $va; private object $tl; private int $accA; private int $accB;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => RecordService::fillNotNull($t, $d);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->accA = DB::table('t_account')->insertGetId($f('t_account', ['fk_id_ent' => 1, 'first_name' => 'User', 'sur_name' => 'A', 'f_active' => 1])); $this->accB = DB::table('t_account')->insertGetId($f('t_account', ['fk_id_ent' => 1, 'first_name' => 'User', 'sur_name' => 'B', 'f_active' => 1]));
        $this->shopA = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fk_id_seller' => $this->accA, 'shop_profile_url' => 'https://shop.example/a']));
        $this->shopB = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '102B', 'shop_name' => 'Toys', 'iso_branch' => 'P2', 'f_active' => 1, 'fk_id_seller' => $this->accB]));
        $this->va = \App\Models\User::create(['login_id' => 'va-h@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'User A', 'fk_id_ent' => 1, 'f_active' => 1, 'fk_id_account' => $this->accA]);
        $this->tl = \App\Models\User::create(['login_id' => 'tl-h@test', 'password_hash' => 'x', 'role_code' => 'TL', 'display_name' => 'Lead', 'fk_id_ent' => 1, 'f_active' => 1]);
    }

    public function test_rows_running_totals_and_locks(): void
    {
        $this->world(); $this->actingAs($this->va); $p = ShopHealth::page([], $this->va, '2026-07-10');
        $this->assertSame(['101A (Pets)'], array_column($p['rows'], 'shop_label')); $this->assertSame(12, DB::table('mo_shop_health')->where('fk_id_branch', $this->shopA)->where('year', 2026)->count()); $this->assertSame(0, DB::table('mo_shop_health')->where('fk_id_branch', $this->shopB)->count());
        $this->assertSame(7, $p['month']); $this->assertFalse($p['months'][6]['locked']); $this->assertTrue($p['months'][7]['locked']); $this->assertSame('Wait for August to come.', ShopHealth::page(['month' => 8], $this->va, '2026-07-10')['locked_text']);
        ShopHealth::save($this->shopA, 2026, 7, 'pf1', '10', $this->va, '2026-07-10'); $r = ShopHealth::save($this->shopA, 2026, 7, 'pf2', '25', $this->va, '2026-07-10')['row'];
        $h = DB::table('mo_shop_health')->where(['fk_id_branch' => $this->shopA, 'month' => 7])->first(); $this->assertSame([10, 15], [(int) $h->pf_week_1, (int) $h->pf_week_2]); $this->assertSame(25, $r['positive']); $this->assertSame([10, 25, null, null], $r['pf']);
        foreach ([['pf3', '2x'], ['nf_total', '-1'], ['metric:TDR', '1,5']] as [$fld, $v]) { try { ShopHealth::save($this->shopA, 2026, 7, $fld, $v, $this->va, '2026-07-10'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Only digits and a dot', $e->getMessage()); } }
        try { ShopHealth::save($this->shopA, 2026, 8, 'pf1', '1', $this->va, '2026-07-10'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Wait for August to come.', $e->getMessage()); }
        try { ShopHealth::save($this->shopB, 2026, 7, 'pf1', '1', $this->va, '2026-07-10'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('only the shops where you are the seller or the owner', $e->getMessage()); }
        ShopHealth::save($this->shopA, 2026, 7, 'cnf', 'Late parcel, buyer unhappy', $this->va, '2026-07-10'); ShopHealth::save($this->shopA, 2026, 7, 'nf_total', '2', $this->va, '2026-07-10'); ShopHealth::save($this->shopA, 2026, 7, 'metric:TDR', '0.45', $this->va, '2026-07-10');
        ShopHealth::save($this->shopA, 2026, 6, 'level', '1', $this->va, '2026-07-10'); $row = ShopHealth::page([], $this->va, '2026-07-10')['rows'][0];
        $this->assertSame(['Top Rated', 'green'], [$row['prev_level']['label'], $row['prev_level']['colour']]); $this->assertSame(2, $row['negative']); $this->assertSame('Late parcel, buyer unhappy', $row['cnf']); $this->assertSame(0.45, $row['metrics'][0]['value']); $this->assertSame('https://shop.example/a', $row['url']); $this->assertSame(date('d-m-Y'), $row['entered']);
    }

    public function test_limits_raise_and_close_the_alert_task(): void
    {
        $this->world(); $prof = DB::table('mp_profiles')->insertGetId(RecordService::fillNotNull('mp_profiles', ['fk_id_ent' => 1, 'code' => 'T_' . uniqid(), 'name' => 'Test profile', 'f_active' => 1, 'created_at' => now()])); DB::table('t_branches')->where('id', $this->shopA)->update(['fk_id_mp_profile' => $prof]);
        DB::table('mp_profile_health_metrics')->insert([['fk_id_mp_profile' => $prof, 'metric_code' => 'LATE', 'label' => 'Late dispatch rate', 'unit' => '%', 'direction' => 'LOWER', 'threshold_amber' => 3, 'threshold_red' => 5, 'f_active' => 1], ['fk_id_mp_profile' => $prof, 'metric_code' => 'RESP', 'label' => 'Reply rate', 'unit' => '%', 'direction' => 'HIGHER', 'threshold_amber' => 95, 'threshold_red' => 90, 'f_active' => 1]]);
        $open = fn () => DB::table('tasks')->where('status', 'TODO')->where('title', 'like', 'Shop 101A (Pets):%')->pluck('title')->all();
        $r = ShopHealth::save($this->shopA, 2026, 7, 'metric:LATE', '4', $this->owner, '2026-07-10')['row']; $this->assertSame('amber', $r['metrics'][0]['score']); $this->assertSame([], $open());
        $r = ShopHealth::save($this->shopA, 2026, 7, 'metric:LATE', '6.5', $this->owner, '2026-07-10')['row']; $this->assertSame('red', $r['metrics'][0]['score']); $this->assertSame(['Shop 101A (Pets): Late dispatch rate is 6.5% – above the limit 5% (July 2026)'], $open());
        ShopHealth::save($this->shopA, 2026, 7, 'metric:RESP', '85', $this->owner, '2026-07-10'); $this->assertCount(2, $open()); ShopHealth::save($this->shopA, 2026, 7, 'metric:RESP', '99', $this->owner, '2026-07-10'); ShopHealth::save($this->shopA, 2026, 7, 'metric:LATE', '1', $this->owner, '2026-07-10'); $this->assertSame([], $open());
        try { ShopHealth::save($this->shopA, 2026, 7, 'metric:TDR', '1', $this->owner, '2026-07-10'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('no health metric TDR', $e->getMessage()); }
        ShopHealth::save($this->shopA, 2026, 6, 'level', '1', $this->owner, '2026-07-10'); ShopHealth::save($this->shopA, 2026, 7, 'level', '3', $this->owner, '2026-07-10'); $this->assertSame(['Shop 101A (Pets): seller level dropped from Top Rated to Below Standard (July 2026)'], $open());
        ShopHealth::save($this->shopA, 2026, 7, 'level', '1', $this->owner, '2026-07-10'); $this->assertSame([], $open());
        \App\Services\Settings::set("HEALTH_LEVELS_PROFILE_$prof", json_encode([['code' => 'GOLD', 'label' => 'Gold', 'short' => 'G', 'colour' => 'green'], ['code' => 'RISK', 'label' => 'At risk', 'short' => 'R', 'colour' => 'red']]), 1);      // another marketplace profile has its own levels
        $r = ShopHealth::save($this->shopA, 2026, 7, 'level', 'RISK', $this->owner, '2026-07-10')['row']; $this->assertSame(['At risk', 'red'], [$r['level_label'], $r['level_colour']]); $this->assertSame(['GOLD', 'RISK'], array_column($r['levels'], 'code'));
    }

    public function test_tl_view_filters_entry_for_a_va_and_history(): void
    {
        $this->world(); $this->actingAs($this->tl); $all = ShopHealth::page([], $this->tl, '2026-07-10'); $this->assertSame(2, $all['count']); $this->assertSame('Search Result (2)', $all['result_text']); $this->assertTrue($all['any']);
        $p = ShopHealth::page(['marketplace' => $this->mp, 'va' => $this->accA, 'year' => 2026], $this->tl, '2026-07-10'); $this->assertSame(['101A (Pets)'], array_column($p['rows'], 'shop_label')); $this->assertSame('User A', $p['rows'][0]['va']); $this->assertSame(['User A', 'User B'], array_column($p['lists']['vas'], 'name')); $this->assertSame(['101A-Pets'], array_column($p['lists']['shops'], 'name'));
        ShopHealth::save($this->shopA, 2026, 7, 'pf1', '7', $this->tl, '2026-07-10'); ShopHealth::save($this->shopA, 2026, 7, 'nf_total', '3', $this->tl, '2026-07-10');
        $t = ShopHealth::trend($this->shopA, 2026, $this->tl); $this->assertSame('entered by TL', $t['history'][0]['text']); $this->assertSame('Negative feedback (number)', $t['history'][0]['field']); $this->assertSame(7, $t['months'][6]['positive']); $this->assertCount(12, $t['months']);
        $this->assertSame(['positive' => 7, 'negative' => 3], ShopHealth::page([], $this->tl, '2026-07-10')['boxes']); $this->assertSame('No Data Found', ShopHealth::page(['shop' => 999999], $this->tl, '2026-07-10')['empty_text']);
        $this->actingAs($this->va); $mine = ShopHealth::page(['va' => $this->accB], $this->va, '2026-07-10'); $this->assertFalse($mine['any']); $this->assertNull($mine['lists']); $this->assertSame(['101A (Pets)'], array_column($mine['rows'], 'shop_label'));
        $this->getJson('/api/shop-health?month=7&year=2026')->assertOk()->assertJsonPath('rows.0.positive', 7); $this->postJson('/api/shop-health', ['shop' => $this->shopB, 'year' => 2026, 'month' => 1, 'field' => 'pf1', 'value' => '1'])->assertStatus(403);      // R-C-41: the VA's shop scope now refuses another shop at the door
    }
}
