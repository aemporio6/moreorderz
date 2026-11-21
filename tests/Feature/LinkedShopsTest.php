<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-42: linked shops by role – straight from the shops, no assignment needed, inactive greyed, counts on the chips. */
class LinkedShopsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_owner_2_seller_1(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'LKmp', 'f_active' => 1]);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'LKP' . random_int(10, 99), 'first_name' => 'Laila', 'f_active' => 1]);
        $a = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'LK1', 'iso_branch' => 'LK1', 'shop_name' => 'Owned A', 'f_active' => 1, 'fk_id_owner' => $p]);
        $b = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'LK2', 'iso_branch' => 'LK2', 'shop_name' => 'Owned B (closed)', 'f_active' => 0, 'fk_id_owner' => $p]);
        $c = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'LK3', 'iso_branch' => 'LK3', 'shop_name' => 'Sold for', 'f_active' => 1]);
        $this->postJson("/api/people/$p/shops", ['fk_id_branch' => $c, 'role_code' => 'VA'])->assertOk();      // the VA assignment is the one source of SELLER

        $d = $this->getJson("/api/people/$p/shops")->assertOk()->json();
        $chips = collect($d['linked']['roles'])->mapWithKeys(fn ($r) => [$r['label'] => $r['count']])->all();
        $this->assertSame(['Owner' => 2, 'Seller' => 1], $chips);
        $own = collect($d['linked']['roles'])->firstWhere('code', 'OWNER')['rows'];
        $this->assertSame([$b, $a], array_column($own, 'shop'), 'newest first, no assignment needed');
        $this->assertSame([true, false], array_column($own, 'inactive'), 'the closed shop is shown greyed');
        $this->assertSame("#/rec/t_branch/$a", $own[1]['link']);
        $this->assertSame($c, collect($d['linked']['roles'])->firstWhere('code', 'SELLER')['rows'][0]['shop']);
        $this->assertTrue(collect($d['shops'])->contains(fn ($s) => (int) $s['id'] === $b && (int) $s['f_active'] === 0), 'pickers show every shop, inactive ones too');

        $q = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'LKQ' . random_int(10, 99), 'first_name' => 'Qadir', 'f_active' => 1]);
        $e = $this->getJson("/api/people/$q/shops")->json('linked');
        $this->assertSame([0, 0, 'No linked shop found for this role for this user.'], [$e['roles'][0]['count'], $e['roles'][1]['count'], $e['empty']]);
    }

    public function test_owner_lists_are_people_with_the_active_owner_role_sorted_by_first_name(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $z = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWZ' . random_int(10, 99), 'first_name' => 'Zara', 'f_active' => 1]);
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWA' . random_int(10, 99), 'first_name' => 'Asad', 'f_active' => 1]);
        $n = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWN' . random_int(10, 99), 'first_name' => 'Nobody', 'f_active' => 1]);
        $f = ['source' => 'party:OWNER', 'form_key' => 'shop'];
        $before = array_column(\App\Services\Registry::options($f, 1, null, 1000), 0);
        $this->assertContains((string) $n, $before, 'while nobody carries the role, every person is offered');
        $type = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', ['OWNER'])->value('id') ?: $this->f('cat_account_types', ['role_code' => 'OWNER', 'account_type_name' => 'Owner', 'f_active' => 1, 'fk_id_ent' => 1]);
        foreach ([$z, $a] as $p) $this->f('t_account_in_types', ['fk_id_account' => $p, 'fk_id_account_type' => $type, 'f_active' => 1]);
        $opts = \App\Services\Registry::options($f, 1, null, 1000);
        $ids = array_column($opts, 0);
        $this->assertNotContains((string) $n, $ids, 'a person without the role is left out');
        $this->assertLessThan(array_search((string) $z, $ids, true), array_search((string) $a, $ids, true), 'sorted by first name');
    }
}
