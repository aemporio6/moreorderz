<?php

namespace Tests\Feature;

use App\Services\Perm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-82: named rights of the sales rules (with the old tiktok.* aliases), checked on the server, several roles = the union. */
class SalesRightsTest extends TestCase
{
    public function test_a_user_with_roles_va_and_tl_can_set_the_daily_order_status(): void
    {
        $va = \App\Models\User::create(['login_id' => 'va-tl@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA TL', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->assertFalse(Perm::has($va, 'tiktok.status')); $this->assertFalse(Perm::has($va, 'daily_order.status'));
        $this->actingAs($this->owner); $this->assertSame(['TL'], Perm::setExtraRoles((int) $va->id, ['TL', 'VA'], (int) $this->owner->id));      // the main role is not stored again
        foreach (['tiktok.status', 'daily_order.status', 'daily_order.bulk', 'orders.fulfilManage', 'daily_order.add', 'health.edit_any'] as $r) $this->assertTrue(Perm::has($va, $r), $r);      // the union of VA and TL
        $this->assertSame('Allowed: your extra role TL has "' . Perm::label('orders.fulfilManage') . '".', Perm::explain($va, 'tiktok.status'));
        Perm::setUser((int) $va->id, 'orders.fulfilManage', 'REVOKED', 'not this month', null, (int) $this->owner->id); $this->assertFalse(Perm::has($va, 'daily_order.status'));      // a right taken away personally still comes first
        try { Perm::setExtraRoles((int) $va->id, ['OWNER'], (int) $this->owner->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Owner cannot be an extra role.', $e->getMessage()); }
    }

    public function test_every_name_of_the_rule_maps_to_a_right_that_exists_and_is_checked_on_the_server(): void
    {
        $cat = Perm::catalogue();
        foreach (Perm::ALIASES as $name => $code) $this->assertTrue(isset($cat[$code]) || in_array($code, ['orders.MONEY', 'orders.CONFIRM'], true), "$name → $code");
        $va = \App\Models\User::create(['login_id' => 'va-only@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]); $this->actingAs($va);
        $this->postJson('/api/fulfilment/bulk', ['ids' => [1], 'action' => 'status', 'status' => 'done'])->assertStatus(422)->assertJsonPath('message', 'Only a manager or team leader can change the status or delete orders here.');
        $this->assertTrue(Perm::has($va, 'tiktok.add')); $this->assertFalse(Perm::has($va, 'sale.unlock')); $this->assertFalse(Perm::has($va, 'setup.carrier'));
        $this->assertTrue(Perm::has($this->owner, 'audit.run')); $this->assertTrue(Perm::has($va, 'sale.see_investor'));
    }
}
