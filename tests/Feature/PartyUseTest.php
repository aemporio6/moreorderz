<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\PartyUse;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-17 "where used" guard; soft delete only; shops are never deleted with a person. */
class PartyUseTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    /** The rule test: a person who owns 2 shops → delete refused with both shop names; after the shops are reassigned → soft delete works and both shops still exist. */
    public function test_r17_delete_refused_while_used_then_soft_delete_and_shops_stay(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PU' . random_int(1000, 9999), 'first_name' => 'Shop', 'sur_name' => 'Owner', 'f_active' => 1]); $other = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PV' . random_int(1000, 9999), 'first_name' => 'New', 'sur_name' => 'Holder', 'f_active' => 1]);
        $s1 = $this->f('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Alpha Shop', 'iso_branch' => 'PU1', 'fk_id_owner' => $p, 'f_active' => 1]); $s2 = $this->f('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Beta Shop', 'iso_branch' => 'PU2', 'fk_id_owner' => $p, 'fk_id_seller' => $p, 'f_active' => 1]);
        $ownerType = (int) (DB::table('cat_account_types')->whereRaw("UPPER(role_code) = 'OWNER'")->value('id') ?: $this->f('cat_account_types', ['role_code' => 'OWNER', 'f_active' => 1]));
        $role = $this->f('t_account_in_types', ['fk_id_account' => $p, 'fk_id_account_type' => $ownerType, 'f_active' => 1]);
        $used = PartyUse::whereUsed($p); $this->assertSame(['Alpha Shop', 'Beta Shop'], $used['shop_owner'][2]); $this->assertSame(['Beta Shop'], $used['shop_seller'][2]);
        $r = $this->postJson("/api/people/$p/delete")->assertStatus(409)->json(); $this->assertFalse($r['deleted']); $this->assertStringContainsString('Alpha Shop, Beta Shop', $r['message']);
        $this->assertSame(1, (int) DB::table('t_account')->where('id', $p)->value('f_active'));
        try { RecordService::save('t_account_in_type', ['f_active' => 0], $role); $this->fail('role removed'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('Cannot remove role – used in shop(s): Alpha Shop, Beta Shop', array_values($e->errors())[0][0]); }
        DB::table('t_branches')->whereIn('id', [$s1, $s2])->update(['fk_id_owner' => $other, 'fk_id_seller' => $other]);      // the shops are reassigned
        $this->assertSame([], PartyUse::whereUsed($p)); $this->getJson("/api/people/$p/where-used")->assertOk()->assertJsonCount(0, 'used');
        $this->postJson("/api/people/$p/delete")->assertOk()->assertJsonPath('deleted', true);
        $this->assertSame(0, (int) DB::table('t_account')->where('id', $p)->value('f_active')); $this->assertSame(1, DB::table('mo_recycle_bin')->where('table_name', 't_account')->where('row_id', $p)->whereNull('restored_at')->count());
        $this->assertSame(2, DB::table('t_branches')->whereIn('id', [$s1, $s2])->where('f_active', 1)->count(), 'both shops still exist'); $this->assertSame(1, DB::table('t_account_in_types')->where('id', $role)->count(), 'sub-lists are kept');
        $this->postJson("/api/people/$p/delete")->assertStatus(422); $this->postJson("/api/people/$p/restore")->assertOk(); $this->assertSame(1, (int) DB::table('t_account')->where('id', $p)->value('f_active'));
        $mgr = $this->makeUser('VA'); $this->actingAs($mgr)->postJson("/api/people/$p/delete")->assertStatus(403);
    }
}
