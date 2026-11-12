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
use App\Services\WaterfallService as W;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Profit waterfall: split rule, loss carried forward, the two routes, stacking, statements as PDF, adjustment to a locked month. */
class WaterfallTest extends TestCase
{
    private int $shop; private int $bank; private int $a; private int $b; private int $h; private int $p; private int $bin; private string $m1; private string $m2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m1 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month')); $this->m2 = date('Y-m', strtotime(date('Y-m-01') . ' -2 month'));
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
        PriceService::set($this->p, $mp, 8, 30.00, $this->m2 . '-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 1000, 'opening_date' => $this->m2 . '-01']);
        [$this->a, $this->b, $this->h] = array_map(fn ($n) => $f('t_account', ['fk_id_ent' => 1, 'first_name' => $n, 'sur_name' => 'Party', 'account_code' => $n, 'f_active' => 1]), ['Anna', 'Bilal', 'Handler']);
        Storage::fake('local');
        $own = \App\Models\User::find(DB::table('t_users')->where('role_code', 'OWNER')->value('id')); $this->actingAs($own); Context::set(1, $own->id);
    }

    private function stock(int $qty, array $more = []): void { Stock::receive(1, [['product' => $this->p, 'qty' => $qty, 'location' => $this->bin, 'unit_cost' => 4.00, 'received_date' => $this->m2 . '-01'] + $more], 'GRN', 1); }
    private function sell(string $no, string $month, int $qty, float $rate = 30): void { Sales::intake($this->shop, [['order_no' => $no, 'd_order' => $month . '-05', 'sku' => 'BOWL-01', 'qty' => $qty, 'sale_rate' => $rate]]); Sales::confirmMonth($this->shop, $month, true); }

    public function test_split_rule_checks_and_the_stacking_sum_of_the_spec(): void
    {
        $base = ['d_effective_from' => $this->m2 . '-01', 'investor_pct' => 50, 'owner_pct' => 30, 'va_pct' => 10, 'handler_pct' => 5, 'owner_mode' => 'SHARE', 'va_mode' => 'SHARE', 'handler_mode' => 'SHARE'];
        try { W::saveSplit(1, $base); $this->fail('95'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('must add up to exactly 100%. They add up to 95%', $e->getMessage()); }
        try { W::saveSplit(1, ['handler_pct' => 10, 'owner_mode' => 'FIXED_SHARE'] + $base); $this->fail('fee'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Account Owner fixed fee', $e->getMessage()); }
        try { W::saveSplit(1, ['handler_pct' => 10, 'va_mode' => 'SALARY', 'va_pct' => 10] + $base); $this->fail('mode clears'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('add up to 90%', $e->getMessage(), 'VA on salary has no % – the mode clears it'); }
        $x = W::saveSplit(1, ['handler_pct' => 10, 'handler_mode' => 'FIXED_SHARE', 'handler_fixed' => 15, 'fk_id_handler_party' => $this->h] + $base);
        $this->assertStringContainsString('pays the same party twice', $x['warning']);
        $own = W::saveSplit(1, ['fk_id_branch' => $this->shop, 'investor_pct' => 100, 'owner_pct' => 0, 'va_pct' => 0, 'handler_pct' => 0] + $base)['id'];
        $this->assertSame($own, (int) W::split(1, $this->shop, date('Y-m-d'))->id, 'the shop\'s own rule wins over the Default');
        W::retireSplit(1, $own);
        $this->assertSame($x['id'], (int) W::split(1, $this->shop, date('Y-m-d'))->id, 'a shop without its own rule uses the Default');
        // R-FIN-75: PO of 100 units, party owns 40, share 10 %, PO profit 1,000
        $pay = function (string $rule, ?float $cap = null) { [$own, $base, $max] = W::stack($rule, 400, 600, true, $cap); $s = round($base * 0.10, 2); return $own + ($max !== null ? min($s, $max) : $s); };
        $this->assertSame([460.0, 400.0, 450.0, 500.0], [$pay('SPLIT_UNITS'), $pay('NONE'), $pay('CAPPED', 5), $pay('FULL_STACK')]);
        $this->assertSame(100.0, W::stack('NONE', 0, 1000, false, null)[1] * 0.10, 'without a lot of his own the share is on the whole PO');
    }

    public function test_loss_is_carried_forward_then_va_handler_and_partners_are_paid_in_order_and_locked_months_get_statements(): void
    {
        $this->stock(500);
        W::saveSplit(1, ['d_effective_from' => $this->m2 . '-01', 'investor_pct' => 60, 'owner_pct' => 20, 'va_pct' => 10, 'handler_pct' => 10, 'owner_mode' => 'SHARE', 'va_mode' => 'SALARY_SHARE', 'va_salary_override' => 20, 'handler_mode' => 'SHARE', 'fk_id_handler_party' => $this->h]);
        Partners::saveAgreement(1, ['investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => $this->m2 . '-01', 'profit_share_pct' => 50]);
        // month 2: one sale (gross 26) and a shop expense of 126 → loss 100, VA salary 20 on top → 120 carried forward, nobody is paid a share
        $this->sell('ORD-1', $this->m2, 1);
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'SOFTWARE', 'amount' => 126, 'date' => $this->m2 . '-10', 'branch' => $this->shop]);
        $s2 = Partners::monthShares(1, $this->shop, $this->m2);
        $this->assertSame([-120.0, 120.0, 0.0, 0.0, 'LOT_PROFIT'], [$s2['final_net'], $s2['loss_cf'], $s2['to_partners'], $s2['left_for_company'] + 120.0, $s2['route']]);
        Close::lock(1, $this->shop, $this->m2, 'A loss month.');
        $this->assertSame([-20.0, 0.0], [Ledger::balance(1, '2300'), Ledger::balance(1, '7000')], 'the salary is owed, no share is paid in a loss month');
        // month 1: profit 260 − salary 20 = 240 − loss 120 = 120; VA 10 % = 12, handler 10 % = 12, partner 50 % of the 96 left = 48
        $this->sell('ORD-2', $this->m1, 10);
        $s1 = Partners::monthShares(1, $this->shop, $this->m1);
        $this->assertSame([240.0, 120.0, 120.0, 0.0, 48.0, 48.0], [$s1['final_net'], $s1['loss_bf'], $s1['distributable'], $s1['loss_cf'], $s1['to_partners'], $s1['left_for_company']]);
        $this->assertSame([['VA', 32.0], ['HANDLER', 12.0]], array_map(fn ($r) => [$r['role'], $r['amount']], $s1['roles']));
        $this->assertSame(48.0, end($s1['waterfall'])['amount']);
        $x = Close::lock(1, $this->shop, $this->m1, 'Back in profit.');
        $this->assertSame([-52.0, -12.0, -48.0, 48.0], [Ledger::balance(1, '2300'), Ledger::balance(1, '2000', null, $this->h), Ledger::balance(1, '2100', null, $this->a), Partners::account(1, $this->a)['balance']]);
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // statements: company + partner for each locked month; real PDFs
        $files = W::statementFiles(1); $run1 = $x['run'];
        $this->assertSame([4, 2], [count($files), count(W::statementFiles(1, null, $run1))]);   // the loss month also gives the partner a statement: it shows that nothing was shared
        $mine = W::statementFiles(1, $this->a)[0];
        [$bytes, $name] = W::statementFile(1, (int) $mine->id, null);
        $this->assertSame(['%PDF', true, 48.0], [substr($bytes, 0, 4), str_contains($name, 'partner_' . $this->a . '_v1'), (float) $mine->partner_profit]);
        // adjustment to the locked month: booked forward, statement v2, v1 kept and marked superseded
        try { W::adjust(1, $run1, $this->a, 0, 'x'); $this->fail('zero'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('cannot be 0', $e->getMessage()); }
        try { W::adjust(1, $run1, $this->a, 5, ' '); $this->fail('note'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('note is mandatory', $e->getMessage()); }
        $adj = W::adjust(1, $run1, $this->a, 10, 'Late fee credit');
        $this->assertSame([date('Y-m-01'), 2, 58.0, -58.0], [$adj['booked_on'], $adj['version'], Partners::account(1, $this->a)['balance'], Ledger::balance(1, '2100', null, $this->a)]);
        $vs = collect(W::statementFiles(1, $this->a, $run1))->map(fn ($s) => [(int) $s->version, (int) $s->superseded])->all();
        $this->assertSame([[2, 0], [1, 1]], $vs);
        $this->assertSame('%PDF', substr(W::statementFile(1, (int) $mine->id, null)[0], 0, 4), 'version 1 can still be opened');
        $this->assertSame(0.0, round(Ledger::balance(1, '7000', null, null, $this->m1 . '-31') - 48.0, 2), 'the locked month itself did not change');
        // roles and API
        $fin = $this->makeUser('FINANCE'); $pu = $this->makeUser('PARTNER'); DB::table('fin_partner_users')->insert(RecordService::fillNotNull('fin_partner_users', ['investor_id' => $this->b, 'user_id' => $pu->id]));
        $this->actingAs($fin)->postJson('/api/partners/adjust', ['run' => $run1, 'partner' => $this->a, 'amount' => 5, 'note' => 'no'])->assertStatus(403);
        $this->actingAs($fin)->get("/api/statements/{$mine->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($pu)->get("/api/statements/{$mine->id}/pdf", ['Accept' => 'application/json'])->assertStatus(422);
        $d = $this->actingAs($fin)->getJson('/api/partners/waterfall')->assertOk()->json();
        $this->assertSame([1, 'LOT_PROFIT', 5], [count($d['splits']), $d['routes'][0]['route'], count($d['statements'])]);
        // a split or a route cannot start in or before a locked month
        $own = \App\Models\User::find(DB::table('t_users')->where('role_code', 'OWNER')->value('id')); $this->actingAs($own); Context::set(1, $own->id);
        try { W::setRoute(1, $this->shop, 'INVESTOR_INVOICING', $this->m1, 'try'); $this->fail('locked'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('is locked – the route cannot change before it', $e->getMessage()); }
        try { W::adjust(1, (int) DB::table('aud_runs')->insertGetId(RecordService::fillNotNull('aud_runs', ['run_code' => 'OPEN-1', 'fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'period_month' => date('Y-m'), 'status' => 'OPEN'])), $this->a, 5, 'x'); $this->fail('open'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('only for LOCKED months', $e->getMessage()); }
    }

    public function test_lot_owner_earns_on_his_own_units_and_the_po_share_follows_the_stacking_rule_and_the_other_route_splits_by_cost(): void
    {
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-T1', 'po_number' => 'PO-T1', 'po_date' => $this->m2 . '-01', 'f_active' => 1]));
        $this->stock(40, ['owner' => $this->a, 'po_id' => $po]); $this->stock(60, ['po_id' => $po, 'received_date' => $this->m2 . '-02']);
        $ag = Partners::saveAgreement(1, ['investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'PO', 'po_id' => $po, 'effective_from' => $this->m2 . '-01', 'profit_share_pct' => 10])['id'];
        try { Partners::saveAgreement(1, ['id' => $ag, 'investor_id' => $this->a, 'partner_type' => 'SLEEPING', 'scope' => 'PO', 'po_id' => $po, 'effective_from' => $this->m2 . '-01', 'profit_share_pct' => 10, 'stacking_rule' => 'CAPPED']); $this->fail('cap'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Give the cap', $e->getMessage()); }
        $this->sell('ORD-1', $this->m1, 100, 14);      // 100 units, 10 profit each: 40 from Anna's lot, 60 from the company's
        $pay = function (string $rule, ?float $cap = null) use ($ag) { DB::table('fin_investor_agreements')->where('id', $ag)->update(['stacking_rule' => $rule, 'stack_cap_pct' => $cap]); $s = Partners::monthShares(1, $this->shop, $this->m1); return [$s['to_partners'], $s['left_for_company'], count($s['lines'])]; };
        $this->assertSame([460.0, 540.0, 2], $pay('SPLIT_UNITS')); $this->assertSame([400.0, 600.0, 2], $pay('NONE')); $this->assertSame([450.0, 550.0, 2], $pay('CAPPED', 5)); $this->assertSame([500.0, 500.0, 2], $pay('FULL_STACK'));
        // the other route for the same month (not both): investor 50 % of 1,000 to the lot owners by cost – Anna owns 40 % of the cost sold
        W::saveSplit(1, ['d_effective_from' => $this->m2 . '-01', 'investor_pct' => 50, 'owner_pct' => 30, 'va_pct' => 10, 'handler_pct' => 10, 'owner_mode' => 'SHARE', 'va_mode' => 'SHARE', 'handler_mode' => 'SHARE']);
        W::setRoute(1, $this->shop, 'INVESTOR_INVOICING', $this->m2, 'Old invoicing way for this shop');
        $s = Partners::monthShares(1, $this->shop, $this->m1);
        $this->assertSame(['INVESTOR_INVOICING', 200.0, 1, 600.0], [$s['route'], $s['to_partners'], count($s['lines']), $s['left_for_company']], '1,000 − VA 100 − handler 100 (the company itself) − Anna 200 (40 % of the 500 investor part)');
        $this->assertSame(['VA', 'HANDLER', 'OWNER'], array_column($s['roles'], 'role'));
        $x = Close::lock(1, $this->shop, $this->m1, 'Invoicing route.');
        $this->assertSame(['INVESTOR_INVOICING', -200.0, -100.0], [DB::table('aud_runs')->where('id', $x['run'])->value('profit_route'), Ledger::balance(1, '2100', null, $this->a), Ledger::balance(1, '2300')]);
        $this->assertSame(1, DB::table('t_branch_profit_routes')->where('fk_id_branch', $this->shop)->whereNull('d_end')->count(), 'one route per shop at a time');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }
}
