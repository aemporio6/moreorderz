<?php

namespace Tests\Feature;

use App\Services\CloseService as Close;
use App\Services\Context;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\PartnerService as Partners;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerTest extends TestCase
{
    private int $shop; private int $bank; private int $a; private int $b; private string $m1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m1 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 500, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => $this->m1 . '-01']], 'GRN', 1);
        PriceService::set($p, $mp, 8, 30.00, $this->m1 . '-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 1000, 'opening_date' => $this->m1 . '-01']);
        $this->a = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Partner', 'sur_name' => 'One', 'account_code' => 'P1', 'f_active' => 1]);
        $this->b = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Partner', 'sur_name' => 'Two', 'account_code' => 'P2', 'f_active' => 1]);
        $own = \App\Models\User::find(DB::table("t_users")->where("role_code", "OWNER")->value("id")); $this->actingAs($own); Context::set(1, $own->id);
    }

    public function test_the_spec_example_tax_reserve_then_pool_share_and_working_fee(): void
    {
        DB::table('fin_company_settings')->updateOrInsert(['company_id' => 1], RecordService::fillNotNull('fin_company_settings', ['company_id' => 1, 'vat_rate' => 0, 'corp_tax_rate' => 19]));
        $d = Partners::distributable(1, 2600);
        $this->assertSame([494.0, 2106.0], [$d['tax_reserve'], $d['distributable']]);
        $this->assertSame([0.0, -300.0], [Partners::distributable(1, -300)['tax_reserve'], Partners::distributable(1, -300)['distributable']]);
        $ag = [(object) ['id' => 1, 'investor_id' => 7, 'scope' => 'POOL', 'branch_id' => null, 'po_id' => null, 'profit_share_pct' => 40, 'management_fee_pct' => 5, 'partner_type' => 'WORKING'],
            (object) ['id' => 2, 'investor_id' => 8, 'scope' => 'MARKETPLACE', 'branch_id' => 99, 'po_id' => null, 'profit_share_pct' => 10, 'management_fee_pct' => 0, 'partner_type' => 'SLEEPING']];
        $l = Partners::shareLines($ag, ['POOL' => 2106.0, 'MARKETPLACE:5' => 2106.0]);
        $this->assertSame([1, 842.4, 105.3], [count($l), $l[0]['profit'], $l[0]['fee']], 'POOL 40% of 2,106 = 842.40; working fee 5% = 105.30; the other shop\'s agreement gets nothing here');
        $this->assertSame([0.0, 0.0], [Partners::shareLines($ag, ['POOL' => -300.0])[0]['profit'], Partners::shareLines($ag, ['POOL' => -300.0])[0]['fee']], 'a loss pays nothing');
    }

    public function test_agreement_rules_and_share_totals_over_100_warn_and_block_the_close(): void
    {
        $base = ['investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 60];
        try { Partners::saveAgreement(1, ['profit_share_pct' => 0] + $base); $this->fail('pct'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('more than 0', $e->getMessage()); }
        try { Partners::saveAgreement(1, ['management_fee_pct' => 5] + $base); $this->fail('fee'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('working partner', $e->getMessage()); }
        try { Partners::saveAgreement(1, ['scope' => 'MARKETPLACE'] + $base); $this->fail('shop'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('shop', $e->getMessage()); }
        $this->assertNull(Partners::saveAgreement(1, $base)['warning']);
        $x = Partners::saveAgreement(1, ['investor_id' => $this->b, 'profit_share_pct' => 50] + $base);
        $this->assertStringContainsString('total share is over 100% in Pool (all shops) (110%)', $x['warning']);
        Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => $this->m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 30]]); Sales::confirmMonth($this->shop, $this->m1, true);
        $p = Close::check(1, $this->shop, $this->m1); $c = collect($p['checks'])->keyBy('code');
        $this->assertSame(['RED', 'GREEN'], [$c['SHARES_LE_100']['status'], $c['SPLIT_RULES']['status']]);
        $this->assertStringContainsString('110%', $c['SHARES_LE_100']['detail']);
        Partners::endAgreement(1, $x['id'], date('Y-m-d', strtotime($this->m1 . '-01 -1 day')) < $this->m1 . '-01' ? $this->m1 . '-01' : $this->m1 . '-01', 'Entered by mistake');
        DB::table('fin_investor_agreements')->where('id', $x['id'])->update(['effective_to' => date('Y-m-d', strtotime($this->m1 . '-01 -1 day')), 'effective_from' => date('Y-m-d', strtotime($this->m1 . '-01 -2 day')), 'status' => 'ENDED']);
        $this->assertSame('GREEN', collect(Close::check(1, $this->shop, $this->m1)['checks'])->firstWhere('code', 'SHARES_LE_100')['status']);
    }

    public function test_locking_a_month_pays_the_partners_once_into_their_ledger_and_the_books(): void
    {
        Partners::saveAgreement(1, ['investor_id' => $this->a, 'partner_type' => 'WORKING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 40, 'management_fee_pct' => 5]);
        Partners::saveAgreement(1, ['investor_id' => $this->b, 'partner_type' => 'SLEEPING', 'scope' => 'MARKETPLACE', 'branch_id' => $this->shop, 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 10]);
        Partners::saveAgreement(1, ['investor_id' => $this->b, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => date('Y-m-d'), 'profit_share_pct' => 20]);   // starts later: not in last month
        Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => $this->m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 10, 'sale_rate' => 30]]); Sales::confirmMonth($this->shop, $this->m1, true);
        $sh = Partners::monthShares(1, $this->shop, $this->m1);     // profit 300 − 40 = 260, no tax rate set
        $this->assertSame([260.0, 2, 143.0, 117.0], [$sh['distributable'], count($sh['lines']), $sh['to_partners'], $sh['left_for_company']]);   // 104 + 13 fee + 26
        $x = Close::lock(1, $this->shop, $this->m1, 'Good month.');
        $this->assertSame([3, 143.0], [$x['partner_rows'], $x['to_partners']]);
        $this->assertSame([117.0, 26.0], [Partners::account(1, $this->a)['balance'], Partners::account(1, $this->b)['balance']]);
        $this->assertSame([143.0, -117.0, -26.0], [Ledger::balance(1, '7000'), Ledger::balance(1, '2100', null, $this->a), Ledger::balance(1, '2100', null, $this->b)]);
        $p = Close::page(1, $this->shop, $this->m1);
        $this->assertSame(143.0, (float) $p['shares']['to_partners'], 'the shares are frozen with the month');
        $st = Partners::statements(1, $this->a);
        $this->assertSame([1, 117.0, 2, 'Good month.', 117.0], [count($st), $st[0]['total'], count($st[0]['lines']), $st[0]['owner_note'], $st[0]['account']['balance']]);
        try { Partners::saveAgreement(1, ['id' => DB::table('fin_investor_agreements')->where('investor_id', $this->a)->value('id'), 'investor_id' => $this->a, 'partner_type' => 'WORKING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 90]); $this->fail('paid agreement'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already paid on this agreement', $e->getMessage()); }
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }

    public function test_capital_in_and_withdrawal_with_approval_never_more_than_the_balance_and_a_partner_sees_only_himself(): void
    {
        $fin = $this->makeUser('FINANCE'); $mgr = $this->makeUser('MANAGER'); $pu = $this->makeUser('PARTNER'); $other = $this->makeUser('PARTNER');
        DB::table('fin_partner_users')->insert(['user_id' => $pu->id, 'investor_id' => $this->a, 'created_at' => now()]);
        Partners::saveAgreement(1, ['investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 50]);
        Partners::saveAgreement(1, ['investor_id' => $this->b, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 10]);
        Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => $this->m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 10, 'sale_rate' => 30]]); Sales::confirmMonth($this->shop, $this->m1, true);
        Close::lock(1, $this->shop, $this->m1, 'ok');                      // partner A earns 130
        $this->actingAs($fin)->postJson("/api/partners/{$this->a}/capital", ['amount' => 5000, 'bank' => $this->bank, 'date' => date('Y-m-d')])->assertOk();
        $this->assertSame([5000.0, -5000.0, 6000.0], [Partners::account(1, $this->a)['capital'], Ledger::balance(1, '2150', null, $this->a), Ledger::balance(1, '1000', null, $this->bank)]);
        // the partner's own login
        $this->actingAs($other)->getJson('/api/partners')->assertStatus(403);                       // not linked to a partner
        $list = $this->actingAs($pu)->getJson('/api/partners')->assertOk()->json();
        $this->assertSame([1, $this->a, []], [count($list['partners']), $list['partners'][0]['id'], $list['totals']]);
        $this->getJson("/api/partners/{$this->b}")->assertStatus(403);
        $this->postJson("/api/partners/{$this->b}/withdraw", ['amount' => 5, 'bank' => $this->bank])->assertStatus(403);
        $this->postJson('/api/partners/agreement', [])->assertStatus(403);
        $this->postJson("/api/partners/{$this->a}/withdraw", ['amount' => 500, 'bank' => $this->bank])->assertStatus(422)->assertJsonPath('message', 'This is more than the 130.00 that can be taken out now.');
        $w = $this->postJson("/api/partners/{$this->a}/withdraw", ['amount' => 100, 'bank' => $this->bank, 'note' => 'Monthly'])->assertOk()->assertJsonPath('status', 'WAITING')->json();
        $this->postJson("/api/partners/{$this->a}/withdraw", ['amount' => 50, 'bank' => $this->bank])->assertStatus(422);     // 100 already waiting, only 30 left
        $this->assertSame(130.0, Partners::account(1, $this->a)['balance'], 'nothing moves while it waits');
        $this->actingAs($mgr)->postJson("/api/approvals/{$w['approval']}", ['approve' => true])->assertOk();
        $this->assertSame([30.0, 100.0, 5900.0, -30.0], [Partners::account(1, $this->a)['balance'], Partners::account(1, $this->a)['withdrawn'], Ledger::balance(1, '1000', null, $this->bank), Ledger::balance(1, '2100', null, $this->a)]);
        $one = $this->actingAs($pu)->getJson("/api/partners/{$this->a}")->assertOk()->json();
        $this->assertSame([1, 'PAID', 3], [count($one['statements']), $one['withdrawals'][0]['status'], count($one['ledger'])]);
        // a rejected request frees the amount again
        $w2 = $this->postJson("/api/partners/{$this->a}/withdraw", ['amount' => 30, 'bank' => $this->bank])->assertOk()->json();
        $this->actingAs($mgr)->postJson("/api/approvals/{$w2['approval']}", ['approve' => false, 'reason' => 'Cash is tight this week'])->assertOk();
        $this->assertSame('REJECTED', DB::table('fin_withdrawals')->where('id', $w2['id'])->value('status'));
        $this->actingAs($fin)->getJson('/api/partners')->assertOk()->assertJsonCount(2, 'partners');
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }

    public function test_partners_share_the_profit_after_the_shops_share_of_the_overheads_and_the_setting_can_switch_it_off(): void
    {
        Partners::saveAgreement(1, ['investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->m1 . '-01', 'profit_share_pct' => 50]);
        Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => $this->m1 . '-05', 'sku' => 'BOWL-01', 'qty' => 10, 'sale_rate' => 30]]); Sales::confirmMonth($this->shop, $this->m1, true);   // shop profit 260
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'SOFTWARE', 'amount' => 60, 'date' => $this->m1 . '-10']);                                          // overhead, no shop
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 10, 'date' => $this->m1 . '-11', 'branch' => $this->shop]);                     // the shop's own cost
        $f = Close::figures(1, $this->shop, $this->m1);
        $this->assertSame([250.0, 60.0, 100.0, 190.0], [$f['profit'], $f['overhead_share'], $f['sales_share_pct'], $f['profit_to_share']], 'the only shop with sales carries all the overheads');
        $sh = Partners::monthShares(1, $this->shop, $this->m1);
        $this->assertSame([190.0, 95.0, 60.0], [$sh['distributable'], $sh['lines'][0]['profit'], $sh['overhead_share']]);
        $this->assertSame('GREEN', collect(Close::check(1, $this->shop, $this->m1)['checks'])->firstWhere('code', 'EXPENSES')['status']);
        \App\Services\Choices::set('PROFIT_SHARE_AFTER_OVERHEADS', '0', 1);
        $this->assertSame(125.0, Partners::monthShares(1, $this->shop, $this->m1)['lines'][0]['profit'], 'switched off: the share is on the shop profit before overheads');
    }
}
