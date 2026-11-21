<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\PartnerService;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-18 Reinvest profit as capital. */
class PartnerReinvestTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function led(int $who, string $type, float $amt, string $date): void { $this->f('fin_investor_ledger', ['investor_id' => $who, 'company_id' => 1, 'entry_date' => $date, 'type' => $type, 'amount' => $amt, 'created_at' => now()]); }

    public function test_r18_refused_over_allowed_then_profit_to_capital_and_pool_agreement_copied(): void
    {
        Context::set(1, $this->owner->id);
        $me = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Rafi', 'sur_name' => 'Reinvest', 'f_active' => 1]);
        $old = date('Y-m-d', strtotime('-60 days')); $today = date('Y-m-d');
        $ag = $this->f('fin_investor_agreements', ['investor_id' => $me, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $old, 'capital_amount' => 10000, 'profit_share_pct' => 30, 'management_fee_pct' => 0, 'status' => 'ACTIVE', 'created_at' => now()]);
        $this->led($me, 'CAPITAL_IN', 10000, $old); $this->led($me, 'PROFIT', 3000, $old); $this->led($me, 'WITHDRAWAL', -1000, $old);
        $pu = $this->makeUser('PARTNER'); DB::table('fin_partner_users')->insert(RecordService::fillNotNull('fin_partner_users', ['investor_id' => $me, 'user_id' => $pu->id]));

        // the rule's test: profit 3,000, withdrawn 1,000, reinvest 2,500 → refused (allowed 2,000)
        $this->actingAs($pu)->postJson('/api/my-account/reinvest', ['amount' => 2500])->assertStatus(422)
            ->assertJsonPath('message', 'Refused: only 2,000.00 of accumulated profit is available to reinvest.');
        $this->assertSame(0, DB::table('fin_investor_ledger')->where('investor_id', $me)->where('reference_no', 'REINVEST')->count(), 'nothing written when refused');

        // 1,500 → PROFIT −1,500 and CAPITAL_IN +1,500 (reference REINVEST) dated today
        $this->actingAs($pu)->postJson('/api/my-account/reinvest', ['amount' => 1500])->assertOk();
        $rows = DB::table('fin_investor_ledger')->where('investor_id', $me)->where('reference_no', 'REINVEST')->orderBy('id')->get(['type', 'amount', 'entry_date']);
        $this->assertSame([['PROFIT', -1500.0, $today], ['CAPITAL_IN', 1500.0, $today]], $rows->map(fn ($r) => [$r->type, (float) $r->amount, $r->entry_date])->all());
        $acc = PartnerService::account(1, $me);
        $this->assertSame([11500.0, 500.0], [(float) $acc['capital'], (float) $acc['balance']]);
        // the pool agreement started before today → ended yesterday, copied from today with capital + 1,500
        $a = DB::table('fin_investor_agreements')->where('investor_id', $me)->orderBy('id')->get();
        $this->assertCount(2, $a);
        $this->assertSame([date('Y-m-d', strtotime('-1 day')), 'ENDED'], [$a[0]->effective_to, $a[0]->status]);
        $this->assertSame([$today, null, 'ACTIVE', 11500.0, 30.0], [$a[1]->effective_from, $a[1]->effective_to, $a[1]->status, (float) $a[1]->capital_amount, (float) $a[1]->profit_share_pct]);
        $this->assertTrue(DB::table('tm_notifications')->where('user_id', $this->owner->id)->where('text', 'like', 'REINVEST:%Rafi%')->exists(), 'the Owner is told');
        $this->assertTrue(DB::table('gl_journals')->where('source_type', 'PARTNER_REINVEST')->exists(), 'payable 2100 → capital 2150 posted');

        // a second reinvest the same day: the agreement now starts today → capital updated in place, no new version
        $this->actingAs($this->owner)->postJson("/api/partners/$me/reinvest", ['amount' => 200])->assertOk()->assertJsonPath('capital', 11700);
        $this->assertSame(2, DB::table('fin_investor_agreements')->where('investor_id', $me)->count());
        $this->assertSame(11700.0, (float) DB::table('fin_investor_agreements')->where('investor_id', $me)->where('status', 'ACTIVE')->value('capital_amount'));
        // the Owner may; any other staff may not; a partner may not reinvest for someone else
        $this->actingAs($this->makeUser('MANAGER'))->postJson("/api/partners/$me/reinvest", ['amount' => 1])->assertStatus(403);
        $this->actingAs($pu)->postJson("/api/partners/$me/reinvest", ['amount' => 1])->assertStatus(403);
        $this->assertNotSame($ag, (int) DB::table('fin_investor_agreements')->where('investor_id', $me)->where('status', 'ACTIVE')->value('id'));
    }
}
