<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\Context;
use App\Services\Perm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 26-11 21:31: access cards like MY TOOL roles2 – role fills the ticks, a tick per person, approve up to £, access until. */
class AccessCardsTest extends TestCase
{
    public function test_cards_ticks_limit_until_and_reset(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $m = $this->makeUser('MANAGER');
        $card = collect($this->getJson('/api/access-cards')->assertOk()->json('cards'))->firstWhere('id', $m->id);
        $this->assertNotNull($card); $tick = collect($card['ticks'])->firstWhere('key', 'export');
        $this->assertFalse($tick['changed']);
        // a tick off for this person only
        $c = $this->postJson("/api/access-cards/{$m->id}", ['ticks' => ['export' => false]])->assertOk()->json('card');
        $this->assertSame('off', collect($c['ticks'])->firstWhere('key', 'export')['state']);
        Perm::flush(); $this->assertFalse(Perm::has(DB::table('t_users')->find($m->id), 'orders.saleExport'));
        // approve up to £
        $this->postJson("/api/access-cards/{$m->id}", ['approve_limit' => 250])->assertOk();
        $this->assertSame(250.0, Approvals::decideLimit(DB::table('t_users')->find($m->id), 'PAY', 1));
        // access until – after it, looks only
        $this->postJson("/api/access-cards/{$m->id}", ['until' => date('Y-m-d', strtotime('-1 day'))])->assertOk();
        Perm::flush(); $u = DB::table('t_users')->find($m->id);
        $this->assertTrue(Perm::has($u, 'stock.SEE')); $this->assertFalse(Perm::has($u, 'stock.CHANGE'));
        $c = collect($this->getJson('/api/access-cards')->json('cards'))->firstWhere('id', $m->id);
        $this->assertTrue($c['expired']); $this->assertStringContainsString('Access ended', (string) $c['suggest']);
        // back to the role
        $this->postJson("/api/access-cards/{$m->id}", ['reset' => true, 'until' => null])->assertOk();
        $this->assertSame(0, DB::table('mo_user_perms')->where('user_id', $m->id)->count());
        Perm::flush(); $this->assertTrue(Perm::has(DB::table('t_users')->find($m->id), 'stock.CHANGE'));
        // the Owner card can never be changed; others cannot open the cards
        $this->postJson("/api/access-cards/{$this->owner->id}", ['ticks' => ['setup' => false]])->assertStatus(422);
        $this->actingAs($m); $this->getJson('/api/access-cards')->assertStatus(403);
    }
}
