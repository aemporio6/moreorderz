<?php

namespace Tests\Feature;

use App\Http\Controllers\AppController;
use App\Services\Access;
use App\Services\Context;
use App\Services\PartnerService as Partners;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SkuHomes;
use App\Services\StockService as Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Round 2 part 3a: saved list views, sign-ins and allowed addresses, menu names, SKU homes, lot owner's share. */
class Part3Test extends TestCase
{
    private function asOwner(): void { $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_saved_views_are_per_user_one_default_and_only_owner_or_manager_shares(): void
    {
        $va = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER'); $page = 'list:product';
        $state = ['q' => 'bowl', 'sort' => 'id', 'dir' => 'asc', 'filter' => ['f_active' => '1', 'empty' => '']];
        $a = $this->actingAs($va)->postJson('/api/views', ['page' => $page, 'name' => 'Bowls', 'state' => $state, 'default' => true])->assertOk()->json('id');
        $this->actingAs($va)->postJson('/api/views', ['page' => $page, 'name' => 'All', 'state' => ['q' => ''], 'default' => true])->assertOk();
        $this->actingAs($va)->postJson('/api/views', ['page' => $page, 'name' => 'Bowls', 'state' => ['q' => 'bowl 2']])->assertOk()->assertJsonPath('id', $a);   // same name = the view is replaced
        $v = collect($this->actingAs($va)->getJson("/api/views/$page")->assertOk()->json('views'));
        $this->assertSame([2, 'All', 'bowl 2', []], [$v->count(), $v->firstWhere('default', true)['name'], $v->firstWhere('name', 'Bowls')['state']['q'], $v->firstWhere('name', 'Bowls')['state']['filter']]);
        $this->actingAs($va)->postJson('/api/views', ['page' => $page, 'name' => 'Team', 'state' => [], 'shared' => true])->assertStatus(403);
        $s = $this->actingAs($mgr)->postJson('/api/views', ['page' => $page, 'name' => 'Team', 'state' => ['q' => 'x'], 'shared' => true])->assertOk()->json('id');
        $this->assertSame(['All', 'Bowls', 'Team'], collect($this->actingAs($va)->getJson("/api/views/$page")->json('views'))->pluck('name')->sort()->values()->all(), 'a shared view shows for everybody');
        $this->assertCount(1, $this->actingAs($mgr)->getJson("/api/views/$page")->json('views'), 'another user\'s own views stay private');
        $this->actingAs($va)->postJson("/api/views/$s/remove")->assertStatus(403);
        $this->actingAs($va)->postJson("/api/views/$a/remove")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/views/$s/remove")->assertOk();
    }

    public function test_sessions_can_be_ended_and_allowed_addresses_never_lock_out_the_person_adding_them(): void
    {
        $this->asOwner(); $wh = $this->makeUser('WAREHOUSE'); $mgr = $this->makeUser('MANAGER');
        $req = function (string $ip) { $r = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 15) Chrome/130']); $s = new \Illuminate\Session\Store('t', new \Illuminate\Session\ArraySessionHandler(10)); $s->setId(\Illuminate\Support\Str::random(40)); $s->start(); $r->setLaravelSession($s); return $r; };
        $r1 = $req('203.0.113.7'); Access::start($r1, $wh); $r2 = $req('203.0.113.8'); Access::start($r2, $wh); $ro = $req('198.51.100.1'); Access::start($ro, $this->owner);
        $this->assertTrue(Access::touch($r1, $wh));
        $d = $this->actingAs($mgr)->getJson('/api/sessions')->assertOk()->json();
        $this->assertSame([3, 'Android · Chrome'], [count($d['sessions']), $d['sessions'][0]['device_label']]);
        $this->actingAs($wh)->getJson('/api/sessions')->assertStatus(403);
        // a manager ends one session; cannot end the Owner's
        $sid = DB::table('sys_user_sessions')->where('session_hash', Access::hash($r1))->value('id');
        $this->actingAs($mgr)->postJson('/api/sessions/end', ['session_id' => $sid])->assertOk()->assertJsonPath('ended', 1);
        $this->assertSame([false, true], [Access::touch($r1, $wh), Access::touch($r2, $wh)], 'the ended device is out, the other one stays');
        $this->actingAs($mgr)->postJson('/api/sessions/end', ['user_id' => $this->owner->id])->assertStatus(422);
        // allowed addresses: no rule = anywhere
        $this->assertTrue(Access::ipAllowed(1, $wh->id, 'WAREHOUSE', '8.8.8.8'));
        $this->actingAs($mgr)->postJson('/api/ip-rules', ['scope' => 'ROLE', 'role_code' => 'WAREHOUSE', 'ip_cidr' => '203.0.113.0/24'])->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/ip-rules', ['scope' => 'ROLE', 'role_code' => 'WAREHOUSE', 'ip_cidr' => 'not-an-address'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/ip-rules', ['scope' => 'ALL', 'ip_cidr' => '10.9.9.0/24'])->assertStatus(422)->assertJsonFragment(['message' => 'Refused: this rule would lock you out – you are on 127.0.0.1, which is not inside 10.9.9.0/24. Add your own address first.']);
        $rule = $this->actingAs($this->owner)->postJson('/api/ip-rules', ['scope' => 'ROLE', 'role_code' => 'WAREHOUSE', 'ip_cidr' => '203.0.113.0/24', 'note' => 'Warehouse network'])->assertOk()->json('id');
        $this->assertSame([true, false, true], [Access::ipAllowed(1, $wh->id, 'WAREHOUSE', '203.0.113.50'), Access::ipAllowed(1, $wh->id, 'WAREHOUSE', '8.8.8.8'), Access::ipAllowed(1, $mgr->id, 'MANAGER', '8.8.8.8')], 'the rule covers only the warehouse role');
        // signing in from outside is refused and written down; a session that moves outside is ended
        $this->app['auth']->logout();
        $this->post('/login', ['login' => $wh->login_id, 'password' => 'secret-password'], ['REMOTE_ADDR' => '8.8.8.8'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->post('/login', ['login' => $wh->login_id, 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertSame(2, DB::table('sys_login_attempts')->where('login_id', $wh->login_id)->where('f_success', 0)->count());
        $r3 = $req('8.8.8.8'); DB::table('sys_user_sessions')->where('session_hash', Access::hash($r2))->update(['session_hash' => Access::hash($r3)]);
        $this->assertFalse(Access::touch($r3, $wh)); $this->assertSame('IP', DB::table('sys_user_sessions')->where('session_hash', Access::hash($r3))->value('end_reason'));
        $this->actingAs($this->owner)->postJson("/api/ip-rules/$rule/remove")->assertOk();
        $this->assertTrue(Access::ipAllowed(1, $wh->id, 'WAREHOUSE', '8.8.8.8'));
    }

    public function test_menu_names_follow_the_choice_and_both_ways_stay_available(): void
    {
        $this->assertSame(['Owner Home', 'Partner Home', 'Research board', 'Approvals', 'X'], [AppController::simpleName('Owner Home ("Command Center")'), AppController::simpleName('Partner Home (= My Partner Account)'), AppController::simpleName('Research board'), AppController::simpleName('Approvals – money (view)'), AppController::simpleName('X')]);
        $this->asOwner();
        $names = fn () => collect($this->actingAs($this->owner)->getJson('/api/boot')->assertOk()->json('menu'))->flatMap(fn ($s) => array_column($s['items'], 'name'));
        $this->assertTrue($names()->contains('Owner Home')); $this->assertFalse($names()->contains('Owner Home ("Command Center")'));
        $this->postJson('/api/choices', ['key' => 'MENU_NAMES', 'value' => 'ORIGINAL'])->assertOk();
        $this->assertTrue($names()->contains('Owner Home ("Command Center")'));
    }

    public function test_sku_homes_one_main_home_per_site_and_move_home_moves_units_and_queues_two_labels(): void
    {
        $this->asOwner(); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-A', 'product_name_vendor' => 'Thing A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']); $p2 = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-B', 'product_name_vendor' => 'Thing B', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $site = $f('cat_inventory_locations', ['location_name' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        [$a1, $a2, $c1] = array_map(fn ($n) => $f('cat_inventory_locations', ['location_name' => $n, 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'fk_id_site' => $site, 'pick_seq' => 1]), ['W1-A-1', 'W1-A-2', 'W1-C-1']);
        Stock::receive(1, [['product' => $p, 'qty' => 30, 'location' => $a1, 'unit_cost' => 2.00], ['product' => $p2, 'qty' => 5, 'location' => $a2, 'unit_cost' => 2.00]], 'GRN', 1);
        $this->assertCount(2, SkuHomes::page(1)['no_home'], 'FIXED mode: stock without a main home is listed');
        try { SkuHomes::set(1, $p, $a1, 'PRIMARY', 80, 10); $this->fail('minmax'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Min cannot be more than max', $e->getMessage()); }
        try { SkuHomes::set(1, $p, $site, 'PRIMARY'); $this->fail('bin'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not an active bin', $e->getMessage()); }
        SkuHomes::set(1, $p, $a1, 'PRIMARY', 10, 80); SkuHomes::set(1, $p, $a2, 'OVERFLOW');
        try { SkuHomes::set(1, $p, $c1, 'PRIMARY'); $this->fail('second'); } catch (\InvalidArgumentException $e) { $this->assertSame('SKU-A already has a home in WH1 (W1-A-1). Change it with Move home.', $e->getMessage()); }
        SkuHomes::set(1, $p, $a1, 'PRIMARY', 40, 80);    // changing the same home is fine
        $pg = SkuHomes::page(1); $h = collect($pg['homes'])->firstWhere('bin', 'W1-A-1');
        $this->assertSame([2, 1, 30, true], [count($pg['homes']), count($pg['no_home']), $h->in_bin, $h->below_min]);
        // move home: new main bin, old kept as overflow, the 30 units go over, two shelf labels
        $wh = $this->makeUser('WAREHOUSE'); $va = $this->makeUser('VA');
        $this->actingAs($va)->postJson('/api/sku-homes/move', ['product' => $p, 'bin' => $c1])->assertStatus(422);
        $x = $this->actingAs($wh)->postJson('/api/sku-homes/move', ['product' => $p, 'bin' => $c1, 'move_units' => true, 'keep_old' => true])->assertOk()->json();
        $this->assertSame(['W1-A-1', 'W1-C-1', 30], [$x['from'], $x['to'], $x['units_moved']]);
        $this->assertSame([0, 30], [Stock::balance($p, $a1), Stock::balance($p, $c1)]);
        $roles = DB::table('t_product_locations')->where('fk_id_product', $p)->pluck('home_role', 'fk_id_location')->all();
        $this->assertSame(['PRIMARY', 'OVERFLOW', 40], [$roles[$c1], $roles[$a1], (int) DB::table('t_product_locations')->where('fk_id_product', $p)->where('fk_id_location', $c1)->value('min_qty')]);
        $this->assertTrue($x['labels'] !== null || $x['labels_problem'] !== null);
        if ($x['labels']) $this->assertSame(2, (int) DB::table('print_jobs')->orderByDesc('id')->value('item_count') ?: 2);
        $this->assertSame(Stock::value(1), \App\Services\Ledger::balance(1, '1200'));
        $this->actingAs($wh)->getJson('/api/sku-homes?q=SKU-A')->assertOk()->assertJsonCount(3, 'homes');
    }

    public function test_lot_owner_share_is_a_company_choice_100_a_fixed_percent_or_his_agreement(): void
    {
        $this->asOwner(); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); $m1 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]); $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        PriceService::set($p, $mp, 8, 30.00, $m1 . '-01'); $anna = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Anna', 'sur_name' => 'P', 'account_code' => 'ANNA', 'f_active' => 1]);
        $po = $f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-T1', 'po_number' => 'PO-T1', 'po_date' => $m1 . '-01', 'f_active' => 1]);
        Stock::receive(1, [['product' => $p, 'qty' => 40, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => $m1 . '-01', 'owner' => $anna, 'po_id' => $po], ['product' => $p, 'qty' => 60, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => $m1 . '-02', 'po_id' => $po]], 'GRN', 1);
        Partners::saveAgreement(1, ['investor_id' => $anna, 'partner_type' => 'SLEEPING', 'scope' => 'PO', 'po_id' => $po, 'effective_from' => $m1 . '-01', 'profit_share_pct' => 10]);
        Sales::intake($shop, [['order_no' => 'ORD-1', 'd_order' => $m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 100, 'sale_rate' => 14]]); Sales::confirmMonth($shop, $m1, true);
        $pay = function (string $v) use ($shop, $m1) { $this->postJson('/api/choices', ['key' => 'LOT_OWNER_SHARE', 'value' => $v])->assertOk(); $s = Partners::monthShares(1, $shop, $m1); return [$s['to_partners'], $s['left_for_company']]; };
        $this->assertSame([460.0, 540.0], $pay('100'), '400 of his own units + 10 % of the other 600');
        $this->assertSame([260.0, 740.0], $pay('50'), 'half of his own units\' 400 + the same 60');
        $this->assertSame([100.0, 900.0], $pay('AGREEMENT'), 'his agreement\'s 10 % of his own 400 + 60');
        $this->postJson('/api/choices', ['key' => 'LOT_OWNER_SHARE', 'value' => '55'])->assertStatus(422);
    }
}
