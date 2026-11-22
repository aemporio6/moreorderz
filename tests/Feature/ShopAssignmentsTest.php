<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-41: shop assignment = shop scope – unique (person, shop, role), VA overlap refused, events, VA cache on the shop, the person sees only those shops. */
class ShopAssignmentsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_assign_s_twice_as_va_refused_and_p_sees_only_s(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'SAmp', 'f_active' => 1]);
        $s = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'SA1', 'iso_branch' => 'SA1', 'shop_name' => 'Shop S', 'f_active' => 1]);
        $t = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'SA2', 'iso_branch' => 'SA2', 'shop_name' => 'Shop T', 'f_active' => 1]);
        $own = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'SA3', 'iso_branch' => 'SA3', 'shop_name' => 'Own shop', 'f_active' => 1]);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SAP' . random_int(10, 99), 'first_name' => 'Pari', 'f_active' => 1]);
        $q = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SAQ' . random_int(10, 99), 'first_name' => 'Qasim', 'f_active' => 1]);
        DB::table('t_branches')->where('id', $own)->update(['fk_id_owner' => $p]);

        $a = $this->postJson("/api/people/$p/shops", ['fk_id_branch' => $s, 'role_code' => 'VA'])->assertOk()->json('id');
        $this->assertSame(date('Y-m-d'), DB::table('t_account_branches')->where('id', $a)->value('d_from'), 'from date defaults to today');
        $this->postJson("/api/people/$p/shops", ['fk_id_branch' => $s, 'role_code' => 'VA'])->assertStatus(422)->assertJsonPath('errors.fk_id_branch.0', 'Refused: Pari already has SA1.');
        $this->postJson("/api/people/$p/shops", ['fk_id_branch' => $s, 'role_code' => 'SUPERVISOR'])->assertOk();
        // another VA of the same shop at the same time → refused; after the first ends → allowed
        $this->postJson("/api/people/$q/shops", ['fk_id_branch' => $s, 'role_code' => 'VA'])->assertStatus(422)->assertJsonPath('errors.d_from.0', 'Shop SA1 Shop S already has a VA from ' . date('Y-m-d') . '.');
        $this->assertSame($p, (int) DB::table('t_branches')->where('id', $s)->value('fk_id_seller'), "the shop's VA field is a cache of today's VA");
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'SHOP_ACCESS_GRANTED')->where('entity_id', $a)->exists());
        $this->assertSame(1, (int) DB::table('sys_registry')->where('table_name', 't_branches')->where('column_name', 'fk_id_seller')->value('readonly'));

        // P logs in → sees only S plus own / seller shops
        $u = $this->makeUser('VA'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $p]); $u = $u->fresh();
        $mine = Perm::shops($u); sort($mine); $exp = [$s, $own]; sort($exp);
        $this->assertSame($exp, $mine);
        $this->assertNotContains($t, $mine);
        // cards, delete (event REVOKED), the cache follows
        $cards = $this->getJson("/api/people/$p/shops")->assertOk()->json('rows');
        $this->assertSame(['SA1', 'SA1'], array_column($cards, 'shop_no'));
        $this->postJson("/api/people/$p/shops/$a/delete")->assertOk();
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'SHOP_ACCESS_REVOKED')->where('entity_id', $a)->exists());
        $this->assertEmpty(DB::table('t_branches')->where('id', $s)->value('fk_id_seller'), 'no VA today');
        $this->postJson("/api/people/$q/shops", ['fk_id_branch' => $s, 'role_code' => 'VA'])->assertOk();
        $this->assertSame($q, (int) DB::table('t_branches')->where('id', $s)->value('fk_id_seller'));
    }
}
