<?php

namespace Tests\Feature;

use App\Services\Perm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-16: one "see money" permission, checked on the server – without it the API does not return money keys. */
class SeeMoneyTest extends TestCase
{
    private function keys($v, array &$out): void { if (is_array($v)) foreach ($v as $k => $x) { if (is_string($k)) $out[] = $k; $this->keys($x, $out); } }

    public function test_without_see_money_the_api_returns_no_money_keys(): void
    {
        foreach (['VA', 'STOCK', 'CS'] as $role) $this->assertFalse(Perm::byDefault($role, 'buying.MONEY'), "$role is off by default");
        foreach (['OWNER', 'PARTNER', 'FINANCE', 'SOURCING'] as $role) $this->assertTrue(Perm::byDefault($role, 'buying.MONEY'), "$role is on by default (owner answer: sourcing sees the amounts)");
        Perm::setRole('SOURCING', 'buying.MONEY', false, $this->owner->id); Perm::flush();      // the Owner switches it off → the server drops every money key
        $this->assertSame('buying.MONEY', Perm::code('see_money'), 'one permission');

        $u = $this->makeUser('SOURCING'); $this->actingAs($u);
        $all = [];
        $d = $this->getJson('/api/buying')->assertOk()->json(); $this->keys($d, $all);
        foreach (['buyingcard', 'lots', 'glance', 'needs', 'shipments', 'contacts'] as $c) $this->keys($this->getJson("/api/buying/card/$c")->assertOk()->json(), $all);
        $this->keys($this->getJson('/api/src-report/suppliers')->assertOk()->json(), $all);
        $this->keys($this->getJson('/api/src-report/inventory')->assertOk()->json(), $all);
        foreach (['value', 'profit', 'buy_value', 'cost', 'pay_due_base', 'supplier_advances', 'supplier_payables', 'open_po_value'] as $k) $this->assertNotContains($k, $all, "no \"$k\" key without see money");
        $this->assertFalse($d['rights']['money']); $this->assertFalse($d['rights']['landed_cost'], 'no landed cost entry without see money');
        $this->assertNotContains('PO outstanding', array_column($this->getJson('/api/buying/card/glance')->json('data.rows'), 'label'));
        foreach (['payments', 'investors', 'invoicing'] as $c) $this->getJson("/api/buying/card/$c")->assertStatus(403);
        $this->getJson('/api/src-report/outstanding')->assertStatus(403);

        // the Owner gives the role the right → the same calls return the money
        Perm::setRole('SOURCING', 'buying.MONEY', true, $this->owner->id); Perm::flush();
        $this->assertTrue($this->getJson('/api/buying')->json('rights.money'));
        $this->getJson('/api/buying/card/payments')->assertOk();
        $this->assertArrayHasKey('value', $this->getJson('/api/buying/card/buyingcard')->json('data'));
        $this->assertSame([], $this->getJson('/api/buying/pick')->assertOk()->json('banks'), 'sourcing sees amounts, never our bank accounts');
    }
}
