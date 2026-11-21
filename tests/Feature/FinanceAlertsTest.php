<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\FinanceAlerts;
use App\Services\Ledger;
use App\Services\PartnerOnboarding;
use App\Services\PartnerService;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-66 ten finance alerts, R-FIN-67 alerts screen, R-FIN-16 annualised return, R-FIN-3 partner onboarding gate. */
class FinanceAlertsTest extends TestCase
{
    private int $bank; private int $acc;
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function only(string ...$codes): void { DB::table('fin_alert_rules')->update(['is_active' => 0]); DB::table('fin_alert_rules')->whereIn('code', $codes)->update(['is_active' => 1]); }
    private function freeTo(float $target): void
    {
        $delta = round($target - FinanceAlerts::freeCash(1)['free'], 2); if ($delta == 0.0) return;
        Ledger::post(1, date('Y-m-d'), 'TEST', null, [['account' => '1000', $delta > 0 ? 'debit' : 'credit' => abs($delta), 'ref_id' => $this->bank], ['account' => '2150', $delta > 0 ? 'credit' : 'debit' => abs($delta), 'ref_id' => $this->acc]]);
    }

    protected function setUp(): void
    {
        parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->bank = (int) DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Alert bank', 'account_label' => 'GBP', 'currency' => 'GBP', 'is_active' => 1, 'opening_balance' => 0]);
        $this->acc = $acc = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Olive', 'sur_name' => 'Owner', 'whatsapp_number' => '+44 7700 900555', 'f_active' => 1]);
        DB::table('t_users')->where('id', $this->owner->id)->update(['fk_id_account' => $acc]);
        DB::table('t_users')->where('role_code', 'OWNER')->where('id', '<>', $this->owner->id)->update(['f_active' => 0]);      // one Owner to count messages for
    }

    public function test_r66_free_cash_alert_task_and_whatsapp_no_repeat_within_hour_repeat_after_two_days_resolved(): void
    {
        $this->only('FA1'); Settings::set('free_cash_alert_limit', 10000, 1); Settings::set('alert_repeat_days', 2, 1);
        $this->freeTo(9000);
        $t0 = '2026-11-21 10:00:00';
        $this->assertSame(['raised' => 1, 'repeated' => 0, 'resolved' => 0, 'messages' => 2], FinanceAlerts::run(1, $t0), 'one task + one WhatsApp');
        $a = DB::table('fin_alerts')->where('company_id', 1)->where('code', 'FA1')->first();
        $this->assertSame(['OPEN', 'company'], [$a->status, $a->ref_key]); $this->assertStringContainsString('Free cash 9,000.00', $a->message);
        $task = DB::table('tasks')->where('dedupe_key', 'FIN_FA1:' . $a->id)->first(); $this->assertNotNull($task, 'task FIN_FA1:<alert id>'); $this->assertSame($this->owner->id, (int) $task->owner_user_id);
        $this->assertSame(1, DB::table('tm_messages')->where('channel', 'WHATSAPP')->where('template_key', 'FIN_ALERT')->where('link_id', $a->id)->count());
        // next hour: no repeat
        $this->assertSame(0, FinanceAlerts::run(1, '2026-11-21 11:00:00')['repeated']);
        $this->assertSame(1, DB::table('tm_messages')->where('channel', 'WHATSAPP')->where('template_key', 'FIN_ALERT')->where('link_id', $a->id)->count());
        // after 2 days: sent again (the task stays the same one)
        $this->assertSame(1, FinanceAlerts::run(1, '2026-11-23 10:00:00')['repeated']);
        $this->assertSame(2, DB::table('tm_messages')->where('channel', 'WHATSAPP')->where('template_key', 'FIN_ALERT')->where('link_id', $a->id)->count());
        $this->assertSame(1, DB::table('tasks')->where('dedupe_key', 'FIN_FA1:' . $a->id)->count());
        // cash 12,000 → resolved, task closed
        $this->freeTo(12000);
        $this->assertSame(1, FinanceAlerts::run(1, '2026-11-23 11:00:00')['resolved']);
        $this->assertSame('RESOLVED', DB::table('fin_alerts')->where('id', $a->id)->value('status'));
        $this->assertSame('DONE', DB::table('tasks')->where('id', $task->id)->value('status'));
        $this->assertSame(3, DB::table('tm_messages')->where('event_key', 'like', 'FA1:company|%')->count(), 'messages log (one queue): task + 2 WhatsApp');
    }

    public function test_r66_other_alerts_find_their_subjects(): void
    {
        $today = date('Y-m-d');
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'FAmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'FA1', 'iso_branch' => 'FA' . random_int(100, 999), 'shop_name' => 'Alert shop', 'f_active' => 1]);
        // FA2 payout 1,000 matched to a bank deposit of 990 (tolerance 1) in an open month
        $line = (int) DB::table('fin_bank_transactions')->insertGetId(['batch_id' => 0, 'company_id' => 1, 'bank_account_id' => $this->bank, 'bank_txn_id' => 'FA2-' . uniqid(), 'txn_date' => $today, 'description' => 'Payout', 'amount' => 990, 'amount_gbp' => 990, 'currency' => 'GBP', 'match_status' => 'MANUAL']);
        $st = $this->f('mo_settlements', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'statement_id' => 'ST-FA2', 'statement_date' => $today, 'currency' => 'GBP', 'payable' => 1000, 'status' => 'PAID', 'fk_id_bank_line' => $line]);
        Settings::set('payout_tolerance', 1, 1);
        $this->assertArrayHasKey("settlement:$st", FinanceAlerts::subjects('FA2', 1));
        // FA6 a fee of −300 ten days ago (limit 250)
        $fee = $this->f('fin_settlement_lines', ['batch_id' => 0, 'company_id' => 1, 'branch_id' => $shop, 'settlement_id' => 'ST-FA2', 'bucket' => 'FEE', 'amount' => -300, 'currency' => 'GBP', 'posted_date' => date('Y-m-d', strtotime('-10 days')), 'row_hash' => sha1(uniqid())]);
        $this->assertArrayHasKey("fee:$fee", FinanceAlerts::subjects('FA6', 1));
        // FA8 an agreement ending in 20 days
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Ending', 'sur_name' => 'Partner', 'f_active' => 1]);
        $ag = $this->f('fin_investor_agreements', ['investor_id' => $p, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => '2026-01-01', 'effective_to' => date('Y-m-d', strtotime('+20 days')), 'profit_share_pct' => 10, 'management_fee_pct' => 0, 'status' => 'ACTIVE', 'created_at' => now()]);
        $this->assertArrayHasKey("agreement:$ag", FinanceAlerts::subjects('FA8', 1));
        // FA10 a month still OPEN 12+ days after its end
        $run = $this->f('aud_runs', ['run_code' => 'FA10-' . uniqid(), 'fk_id_ent' => 1, 'fk_id_branch' => $shop, 'period_month' => date('Y-m', strtotime('first day of -2 months')), 'status' => 'OPEN', 'created_at' => now()]);
        $this->assertArrayHasKey("run:$run", FinanceAlerts::subjects('FA10', 1));
        // FA7 stock with value and no sale for 200 days → DEAD (slow 90 / dead 180)
        $this->f('fin_lots', ['company_id' => 1, 'sku' => 'FA7-SKU', 'source' => 'GRN', 'received_date' => date('Y-m-d', strtotime('-200 days')), 'qty' => 5, 'qty_left' => 5, 'unit_cost_gbp' => 4, 'created_at' => now()]);
        Settings::set('slow_days', 90, 1); Settings::set('dead_days', 180, 1);
        $this->assertStringStartsWith('DEAD: FA7-SKU', FinanceAlerts::subjects('FA7', 1)['sku:FA7-SKU'] ?? '');
        // FA5 refund rate: 0 % and 2 % in earlier months, 25 % in the newest → more than twice the average and ≥ 2 %
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'FA5-SKU', 'product_name_vendor' => 'Refund item', 'f_active' => 1]);
        $sale = fn (string $d, int $q, string $s) => $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $prod, 'order_no' => 'FA5-' . uniqid(), 'sku' => 'FA5-SKU', 'd_order' => $d, 'qty' => $q, 'status' => $s]);
        $m2 = date('Y-m-15', strtotime('first day of -2 months')); $m1 = date('Y-m-15', strtotime('first day of -1 month')); $m0 = date('Y-m-01');
        $sale($m2, 50, 'Confirmed'); $sale($m1, 49, 'Confirmed'); $sale($m1, 1, 'Refunded'); $sale($m0, 3, 'Confirmed'); $sale($m0, 1, 'Refunded');
        $this->assertStringContainsString('Refund rate of FA5-SKU is 25 %', FinanceAlerts::subjects('FA5', 1, date('Y-m-d'))['sku:FA5-SKU'] ?? '');
    }

    public function test_r67_screen_toggle_fa6_off_no_fa6_raised_and_run_now(): void
    {
        $this->only('FA6');
        $this->f('fin_settlement_lines', ['batch_id' => 0, 'company_id' => 1, 'branch_id' => 0, 'settlement_id' => 'ST-FA6', 'bucket' => 'FEE', 'amount' => -900, 'currency' => 'GBP', 'posted_date' => date('Y-m-d'), 'row_hash' => sha1(uniqid())]);
        $this->postJson('/api/money/alerts/FA6', ['on' => false])->assertOk();
        $this->postJson('/api/money/alerts/run')->assertOk()->assertJsonPath('raised', 0);
        $this->assertSame(0, DB::table('fin_alerts')->where('code', 'FA6')->count(), 'switched off → no FA6 raised');
        $this->postJson('/api/money/alerts/FA6', ['on' => true, 'channels' => ['EMAIL', 'TASK'], 'to_roles' => ['OWNER', 'FINANCE_VA']])->assertOk();
        $this->assertSame(['EMAIL', 'TASK'], array_values(array_filter(explode(',', DB::table('fin_alert_rules')->where('code', 'FA6')->value('channels')))));
        $this->postJson('/api/money/alerts/run')->assertOk()->assertJsonPath('raised', 1);
        $s = $this->getJson('/api/money/alerts')->assertOk()->json();
        $this->assertCount(10, $s['rules']); $this->assertSame(1, $s['alerts_total']); $this->assertSame('FA6', $s['alerts'][0]['code']); $this->assertTrue($s['may_edit']);
        $this->assertNotEmpty($this->getJson('/api/money/alerts?q=FA6')->json('messages'), 'messages sent, searchable');
        $this->assertNotEmpty($this->getJson('/api/money/alerts?q=FA')->json('messages'), 'search by the start of the alert code');
        $this->postJson('/api/money/alerts/FA6', ['channels' => []])->assertStatus(422);
        $fin = $this->makeUser('FINANCE');
        $this->actingAs($fin)->postJson('/api/money/alerts/FA6', ['on' => false])->assertStatus(403);
    }

    public function test_r16_annualised_return(): void
    {
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Annie', 'sur_name' => 'Annual', 'f_active' => 1]);
        foreach ([['CAPITAL_IN', 10000, '2026-01-01'], ['PROFIT', 1200, '2026-06-30']] as [$t, $a, $d])
            $this->f('fin_investor_ledger', ['investor_id' => $p, 'company_id' => 1, 'entry_date' => $d, 'type' => $t, 'amount' => $a, 'created_at' => now()]);
        $r = PartnerService::annualReturn(1, $p, '2026-07-01');
        $this->assertSame([181, 24.2], [$r['days'], $r['return_pct_annualised']], '1,200 ÷ 10,000 × 365 ÷ 181 × 100');
        $this->assertNull(PartnerService::annualReturn(1, $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'No', 'sur_name' => 'Capital', 'f_active' => 1]))['return_pct_annualised'], 'null when capital is 0');
        $this->getJson("/api/partners/$p?as_of=2026-07-01")->assertOk()->assertJsonPath('annual.return_pct_annualised', 24.2);
    }

    public function test_r3_partner_login_blocked_until_six_items_then_allowed(): void
    {
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Gate', 'sur_name' => 'Partner', 'f_active' => 1]);
        $u = $this->makeUser('PARTNER');
        $this->assertSame(1, PartnerOnboarding::refresh($p)['done'], 'the first look already ticks what the records show (master entry)');
        // link through the form: a staff login is refused, the partner login is taken
        try { RecordService::save('fin_partner_user', ['user_id' => $this->makeUser('ACCOUNTANT')->id, 'investor_id' => $p]); $this->fail('staff login must be refused'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame(PartnerOnboarding::STAFF_REFUSED, $e->errors()['user_id'][0]); }
        RecordService::save('fin_partner_user', ['user_id' => $u->id, 'investor_id' => $p]);
        $ag = PartnerService::saveAgreement(1, ['investor_id' => $p, 'effective_from' => '2026-01-01', 'profit_share_pct' => 10])['id'];
        DB::table('fin_investor_agreements')->where('id', $ag)->update(['agreement_doc_path' => 'agreements/gate.pdf']);      // the signed PDF uploaded
        $this->f('t_account_photo_ids', ['fk_id_account' => $p, 'long_digit_number' => 'X1', 'f_active' => 1]);
        $s = PartnerOnboarding::refresh($p);
        $this->assertSame(5, $s['done'], '5 of 6 by the records'); $this->assertSame(['capital received + bank line matched'], $s['missing']);

        $this->post('/logout');
        $this->post('/login', ['login' => $u->login_id, 'password' => 'secret-password'])
            ->assertSessionHasErrors(['login' => 'Partner Account is blocked until onboarding is complete. Missing: capital received + bank line matched']);
        $this->assertGuest();
        // capital booked (the bank line) → login allowed
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        PartnerService::capitalIn(1, $p, 5000, $this->bank, date('Y-m-d'), 'first capital');
        $this->assertTrue(PartnerOnboarding::state($p)['complete']);
        $this->post('/logout');
        $this->post('/login', ['login' => $u->login_id, 'password' => 'secret-password'])->assertRedirect('/');
        // the Owner unticks one item → blocked again; audited
        $this->post('/logout'); $this->actingAs($this->owner);
        $this->postJson("/api/partners/$p/onboarding", ['item' => 'id_proof', 'on' => false])->assertOk()->assertJsonPath('complete', false);
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 'fin_onboarding')->where('record_id', $p)->exists());
        $this->actingAs($this->makeUser('FINANCE'))->postJson("/api/partners/$p/onboarding", ['item' => 'id_proof', 'on' => true])->assertStatus(403);
    }
}
