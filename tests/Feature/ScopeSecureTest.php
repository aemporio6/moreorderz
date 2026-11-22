<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-59 secure pages ask the code before they open, R-G_CORE-63 data scope "own shops" by role – spec examples. */
class ScopeSecureTest extends TestCase
{
    public function test_r59_secure_page_asks_the_code_before_it_opens_and_logs_the_open(): void
    {
        $g = new Google2FA(); $secret = $g->generateSecretKey(32);
        $o = $this->makeUser('OWNER'); DB::table('t_users')->where('id', $o->id)->update(['totp_secret' => encrypt($secret)]); $o->totp_secret = encrypt($secret);
        $this->actingAs($o);
        $this->getJson('/api/list/t_account_bank')->assertStatus(428)->assertJsonPath('need', 'CODE')->assertJsonPath('secure_page', 't_account_bank');
        $this->postJson('/api/me/secure-code', ['code' => $g->getCurrentOtp($secret)])->assertOk();
        $this->getJson('/api/list/t_account_bank')->assertOk(); $this->getJson('/api/list/t_account_bank')->assertOk();
        $this->assertSame(1, DB::table('sys_audit_log')->where('table_name', 't_account_bank')->where('action', 'REVEAL')->where('reason', 'secure page')->count(), 'the open is logged once per session');
        $m = $this->makeUser('MANAGER'); DB::table('t_users')->where('id', $m->id)->update(['totp_secret' => encrypt($secret)]); $m->totp_secret = encrypt($secret);
        $this->actingAs($m); Choices::set('SECURE_FORMS', 't_account_paypal', 1);
        $this->assertNotSame(428, $this->getJson('/api/list/t_account_bank')->status(), 'not a secure page any more');
    }

    public function test_r63_own_shops_by_role(): void
    {
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_no' => 'SHOP-07', 'iso_branch' => 'S07' . uniqid(), 'shop_name' => 'Shop seven', 'f_active' => 1]));
        $va2 = $this->makeUser('VA'); $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'V2' . random_int(1000, 9999), 'first_name' => 'User B', 'f_active' => 1]));
        DB::table('t_users')->where('id', $va2->id)->update(['fk_id_account' => $acc]); $va2->fk_id_account = $acc;
        DB::table('t_account_branches')->insert(RecordService::fillNotNull('t_account_branches', ['fk_id_account' => $acc, 'fk_id_branch' => $shop, 'role_code' => 'VA', 'f_active' => 1, 'd_from' => date('Y-m-d', strtotime('-1 day'))]));
        $this->assertSame([$shop], Perm::shops($va2), 'VA 2 with SHOP-07 only');
        $vaNone = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER');
        $this->assertSame([0], Perm::shops($vaNone), 'an own-shops role with no shop sees no shop rows');
        $this->assertFalse(Perm::shopAllowed($vaNone, $shop));
        $this->assertNull(Perm::shops($mgr), 'a Manager sees every shop');
        Choices::set('OWN_SHOP_ROLES', 'TL', 1); $this->assertNull(Perm::shops($vaNone), 'Choices: VA no longer an own-shops role');
        // adding SHOP-07 again → refused (spec text)
        $d = ['fk_id_account' => $acc, 'fk_id_branch' => $shop, 'role_code' => 'VA', 'd_from' => date('Y-m-d')];
        $this->actingAs($this->makeUser('OWNER'));
        $this->assertSame('Refused: User B already has SHOP-07.', \App\Services\FormRules::check('t_account_branch', $d, [], null)['fk_id_branch'] ?? \App\Services\ShopAssignments::check($d, [], null)['fk_id_branch'] ?? null);
    }
}
