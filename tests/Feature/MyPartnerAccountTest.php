<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-19: My Partner Account – as-of vs compare cards, return %, stock share, profit by product, "never shows", Owner view-as audited, staff explanation. */
class MyPartnerAccountTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_partner_sees_only_himself_never_list_holds_and_owner_view_as_is_audited(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        DB::table('fin_company_settings')->updateOrInsert(['company_id' => 1], RecordService::fillNotNull('fin_company_settings', ['company_id' => 1, 'vat_rate' => 0, 'corp_tax_rate' => 20]));
        $me = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Pia', 'sur_name' => 'Partner', 'f_active' => 1]);
        $other = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Otto', 'sur_name' => 'Other', 'f_active' => 1]);
        $ag = fn (int $who, float $pct) => $this->f('fin_investor_agreements', ['investor_id' => $who, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => '2025-01-01', 'profit_share_pct' => $pct, 'management_fee_pct' => 0, 'status' => 'ACTIVE', 'created_at' => now()]);
        $ag($me, 40); $ag($other, 60);
        $led = fn (int $who, string $type, float $amt, string $date) => $this->f('fin_investor_ledger', ['investor_id' => $who, 'company_id' => 1, 'entry_date' => $date, 'type' => $type, 'amount' => $amt, 'created_at' => now()]);
        $asOf = date('Y-m-d'); $mid = date('Y-m-d', strtotime('-10 days')); $old = date('Y-m-d', strtotime('-3 months'));
        $led($me, 'CAPITAL_IN', 1000, $old); $led($me, 'PROFIT', 100, $old); $led($me, 'PROFIT', 150, $mid); $led($me, 'WITHDRAWAL', -50, $mid); $led($other, 'CAPITAL_IN', 9999, $old);
        // stock: 10 units at 3.00 received three months ago, 2 used ten days ago
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PA-1', 'product_name_vendor' => 'Partner item', 'f_active' => 1]);
        $lot = $this->f('fin_lots', ['company_id' => 1, 'sku' => 'PA-1', 'fk_id_product' => $prod, 'source' => 'GRN', 'received_date' => $old, 'qty' => 10, 'qty_left' => 8, 'unit_cost_gbp' => 3, 'created_at' => now()]);
        $this->f('fin_lot_consumption', ['lot_id' => $lot, 'sku' => 'PA-1', 'qty' => 2, 'unit_cost_gbp' => 3, 'consumed_on' => $mid]);
        // a locked month with a confirmed sale of PA-1 (profit 50)
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'PAmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'PA1', 'iso_branch' => 'PA1', 'shop_name' => 'PA shop', 'f_active' => 1]);
        $month = date('Y-m', strtotime('-1 month'));
        $this->f('aud_runs', ['run_code' => 'PA-RUN', 'fk_id_ent' => 1, 'fk_id_branch' => $shop, 'period_month' => $month, 'status' => 'LOCKED', 'created_at' => now()]);
        $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $prod, 'd_order' => $month . '-15', 'qty' => 2, 'profit' => 50, 'status' => 'Confirmed']);

        // the partner login
        $pu = $this->makeUser('PARTNER'); DB::table('fin_partner_users')->insert(RecordService::fillNotNull('fin_partner_users', ['investor_id' => $me, 'user_id' => $pu->id]));
        $res = $this->actingAs($pu)->getJson('/api/my-account?as_of=' . $asOf)->assertOk(); $d = $res->json();
        $this->assertSame([1000.0, 250.0, 50.0, 200.0], array_map('floatval', array_values($d['now'])), 'Capital + Profit − Withdrawn = Balance (as of today)');
        $this->assertSame([1000.0, 100.0, 0.0, 100.0], array_map('floatval', array_values($d['then'])), 'compare = one month back');
        $this->assertSame(date('Y-m-d', strtotime($asOf . ' -1 month')), $d['compare']);
        $this->assertEquals(20.0, $d['return_pct'], '250 profit in 12 months × (1 − 20 %) ÷ 1000 capital');
        $this->assertEquals([9.6, 12.0], [$d['stock']['now'], $d['stock']['then']], 'stock at cost × my POOL 40 %: 8 × 3 now, 10 × 3 then');
        $this->assertEquals([['sku' => 'PA-1', 'product' => 'Partner item', 'units' => 2, 'profit' => 50.0, 'my_share' => 16.0]], $d['profit_by_product'], '50 × 40 % × (1 − 20 %)');
        // never shows
        $raw = $res->getContent();
        foreach (['supplier_name', 'unit_cost_gbp', 'bank_balance', '"ledger"'] as $k) $this->assertStringNotContainsString($k, $raw, "never shows $k");
        $this->assertStringNotContainsString('Otto', $raw); $this->assertStringNotContainsString('9999', $raw);
        $this->assertStringNotContainsString('"investor_id":' . $other, $raw);
        $this->actingAs($pu)->getJson("/api/my-account?partner=$other")->assertOk()->assertJsonPath('partner', $me);      // a partner cannot switch to another partner

        // notification switches and reinvest
        $this->actingAs($pu)->postJson('/api/my-account/notify', ['whatsapp' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('t_account')->where('id', $me)->value('wa_enabled'));
        DB::table('t_account')->where('id', $me)->update(['whatsapp_number' => '+44 7700 900123']);
        $this->assertNull(\App\Services\Connect\Messenger::whatsappOf($me, true), 'optional message switched off');
        $this->assertNotNull(\App\Services\Connect\Messenger::whatsappOf($me), 'statements are always sent');
        $this->actingAs($pu)->postJson('/api/my-account/reinvest', ['amount' => 500])->assertStatus(422);
        $this->actingAs($pu)->postJson('/api/my-account/reinvest', ['amount' => 80])->assertOk();
        $this->assertSame([1080.0, 120.0], [(float) $this->actingAs($pu)->getJson('/api/my-account')->json('now.capital'), (float) $this->actingAs($pu)->getJson('/api/my-account')->json('now.balance')]);

        // Owner: explanation without ?partner, view-as read-only and audited
        $this->actingAs($this->owner)->getJson('/api/my-account')->assertOk()->assertJsonPath('staff', true);
        $before = DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $me)->where('action', 'REVEAL')->count();
        $this->actingAs($this->owner)->getJson("/api/my-account?partner=$me")->assertOk()->assertJsonPath('read_only', true)->assertJsonPath('view_as', true);
        $this->assertSame($before + 1, DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $me)->where('action', 'REVEAL')->count(), 'view-as writes an audit row');
        $this->actingAs($this->owner)->postJson("/api/my-account/reinvest?partner=$me", ['amount' => 1])->assertStatus(403);
        // staff: explanation only, no data
        $s = $this->actingAs($this->makeUser('MANAGER'))->getJson("/api/my-account?partner=$me")->assertOk()->json();
        $this->assertTrue($s['staff']); $this->assertArrayNotHasKey('now', $s); $this->assertSame([], $s['partners']);
    }
}
