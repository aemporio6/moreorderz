<?php

namespace Tests\Feature;

use App\Services\CloseService as Close;
use App\Services\Context;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloseTest extends TestCase
{
    private int $shop; private int $p; private int $bank; private string $m1; private string $m2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m1 = date('Y-m', strtotime(date('Y-m-01') . ' -2 month')); $this->m2 = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 100, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => $this->m1 . '-01']], 'GRN', 1);
        PriceService::set($this->p, $mp, 8, 20.00, $this->m1 . '-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 100, 'opening_date' => $this->m1 . '-01']);
    }

    private function order(string $no, string $date, int $qty = 1): void { Sales::intake($this->shop, [['order_no' => $no, 'd_order' => $date, 'sku' => 'BOWL-01', 'qty' => $qty, 'sale_rate' => 20]]); }
    private function st(array $page, string $code): string { return collect($page['checks'])->firstWhere('code', $code)['status']; }

    public function test_checks_turn_red_and_green_with_the_facts_and_the_month_is_checked_when_nothing_is_red(): void
    {
        $this->order('ORD-1', $this->m1 . '-05', 2);
        $profile = DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
        Money::importStatement(1, $this->bank, $profile, "Date,Description,Reference,Amount,Balance,Transaction ID\n" . date('d/m/Y', strtotime($this->m1 . '-10')) . ",SOMETHING,,-5,,T1\n");
        $p = Close::check(1, $this->shop, $this->m1);
        $this->assertSame(['OPEN', 2], [$p['status'], $p['red']]);
        $this->assertSame(['RED', 'RED', 'NA', 'GREEN', 'GREEN', 'GREEN'], [$this->st($p, 'SALES_CONFIRMED'), $this->st($p, 'BANK_ALL_CATEGORISED'), $this->st($p, 'FILES_PRESENT'), $this->st($p, 'STOCK_ROLL'), $this->st($p, 'PREVIOUS_LOCKED'), $this->st($p, 'SHARES_LE_100')]);
        $run = Close::run(1, $this->shop, $this->m1);
        $this->assertTrue(TaskEngine::isOpen('FIN_CLOSE_CHECK', [$run->id]));
        Sales::confirmMonth($this->shop, $this->m1, true);
        Money::categorise(1, (int) DB::table('fin_bank_transactions')->value('id'), ['category' => 'OFFICE']);
        $p = Close::check(1, $this->shop, $this->m1);
        $this->assertSame(['CHECKED', 0, 100], [$p['status'], $p['red'], (int) Close::run(1, $this->shop, $this->m1)->score]);
        $this->assertFalse(TaskEngine::isOpen('FIN_CLOSE_CHECK', [$run->id]));
        $this->assertSame([2, 40.0, 8.0, 32.0], [$p['figures']['units'], $p['figures']['sales'], $p['figures']['cost_of_goods'], $p['figures']['profit']]);
        // a new fact sends a CHECKED month back to OPEN at the next run
        $this->order('ORD-2', $this->m1 . '-20');
        $this->assertSame('OPEN', Close::check(1, $this->shop, $this->m1)['status']);
    }

    public function test_only_the_owner_locks_with_a_note_and_a_locked_month_is_frozen_and_locks_the_ledger(): void
    {
        $this->order('ORD-1', $this->m1 . '-05', 2); Sales::confirmMonth($this->shop, $this->m1, true);
        $this->order('ORD-5', $this->m2 . '-05', 1);
        $mgr = $this->makeUser('MANAGER'); $owner = DB::table('t_users')->where('role_code', 'OWNER')->value('id');
        Context::set(1, $mgr->id); $this->actingAs($mgr);
        try { Close::lock(1, $this->shop, $this->m1, 'ok'); $this->fail('manager'); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only the Owner closes a period.', $e->getMessage()); }
        $this->actingAs(\App\Models\User::find($owner)); Context::set(1, $owner);
        try { Close::lock(1, $this->shop, $this->m1, ' '); $this->fail('note'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('one-line owner note', $e->getMessage()); }
        try { Close::lock(1, $this->shop, date('Y-m'), 'x'); $this->fail('not ended'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not ended', $e->getMessage()); }
        try { Close::lock(1, $this->shop, $this->m2, 'x'); $this->fail('checks'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Cannot close: 2 check(s) fail', $e->getMessage()); }   // not confirmed + earlier month open
        $x = Close::lock(1, $this->shop, $this->m1, 'Good month, one supplier delay.');
        $this->assertSame($this->m1, $x['ledger_locked_up_to']);
        $this->assertSame($this->m1, (string) Settings::get('GL_LOCKED_UPTO', 1, ''));
        try { Close::lock(1, $this->shop, $this->m1, 'again'); $this->fail('twice'); } catch (\InvalidArgumentException $e) { $this->assertSame('This period is already LOCKED.', $e->getMessage()); }
        $p = Close::page(1, $this->shop, $this->m1);
        $this->assertSame(['LOCKED', true, 'Good month, one supplier delay.', 32.0], [$p['status'], $p['frozen'], $p['owner_note'], (float) $p['figures']['profit']]);

        // the ledger refuses a back-dated booking in the locked month
        try { Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 5, 'date' => $this->m1 . '-15']); $this->fail('locked'); } catch (\RuntimeException $e) { $this->assertStringContainsString('is locked', $e->getMessage()); }
        // a late order of the locked month: confirmed forward (dated today); the frozen figures of the month do not move
        $this->order('ORD-LATE', $this->m1 . '-28', 1);
        Sales::confirmMonth($this->shop, $this->m1, true);
        $this->assertSame(60.0, Ledger::balance(1, '1100', null, $this->shop));
        $this->assertSame(date('Y-m-d'), DB::table('gl_journals')->where('source_type', 'SALE_CONFIRM')->orderByDesc('id')->value('journal_date'));
        $this->assertSame(32.0, (float) Close::check(1, $this->shop, $this->m1)['figures']['profit'], 'checking a locked month changes nothing');
        // next month can now be closed
        Sales::confirmMonth($this->shop, $this->m2, true);
        $this->assertSame($this->m2, Close::lock(1, $this->shop, $this->m2, 'Fine.')['ledger_locked_up_to']);
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }

    public function test_only_marked_checks_can_be_waived_by_the_owner_with_a_reason(): void
    {
        $this->order('ORD-1', $this->m1 . '-05'); Sales::confirmMonth($this->shop, $this->m1, true);
        $own = \App\Models\User::find(DB::table('t_users')->where('role_code', 'OWNER')->value('id')); $this->actingAs($own); Context::set(1, $own->id);
        // a statement of the month whose payout is not on the bank yet, and no file covering the month
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => 'ST1', 'statement_date' => $this->m1 . '-12', 'currency' => 'GBP', 'total' => 18, 'net_sales' => 20, 'fees' => -2, 'payable' => 18, 'rows_total' => 18, 'rows_count' => 1, 'f_rows_complete' => 1, 'status' => 'POSTED']);
        $p = Close::check(1, $this->shop, $this->m1);
        $this->assertSame(['RED', 'RED', 'RED', 'RED', 'GREEN'], [$this->st($p, 'FILES_PRESENT'), $this->st($p, 'ORDERS_VS_TXN'), $this->st($p, 'HUB_TOTALS'), $this->st($p, 'PAYOUT_VS_BANK'), $this->st($p, 'SETTLEMENT_COMPLETE')]);
        try { Close::waive(1, $this->shop, $this->m1, 'FILES_PRESENT', 'no'); $this->fail('not waivable'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('cannot be waived', $e->getMessage()); }
        try { Close::waive(1, $this->shop, $this->m1, 'PAYOUT_VS_BANK', ''); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        try { Close::waive(1, $this->shop, $this->m1, 'STOCK_ROLL', 'x'); $this->fail('not waivable'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('cannot be waived', $e->getMessage()); }
        Close::waive(1, $this->shop, $this->m1, 'PAYOUT_VS_BANK', 'Payout arrives on the 3rd of next month');
        $p = Close::page(1, $this->shop, $this->m1);
        $this->assertSame(['WAIVED', 'Payout arrives on the 3rd of next month', 3], [$this->st($p, 'PAYOUT_VS_BANK'), collect($p['checks'])->firstWhere('code', 'PAYOUT_VS_BANK')['waive_reason'], $p['red']]);
        $mgr = $this->makeUser('MANAGER'); Context::set(1, $mgr->id); $this->actingAs($mgr);
        try { Close::waive(1, $this->shop, $this->m1, 'HUB_TOTALS', 'x'); $this->fail('owner only'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Only the Owner', $e->getMessage()); }
    }

    public function test_month_close_api_overview_roles_and_night_task(): void
    {
        $this->order('ORD-1', $this->m1 . '-05'); Sales::confirmMonth($this->shop, $this->m1, true);
        $fin = $this->makeUser('FINANCE'); $wh = $this->makeUser('WAREHOUSE'); $owner = \App\Models\User::find(DB::table('t_users')->where('role_code', 'OWNER')->value('id'));
        $q = ['shop' => $this->shop, 'month' => $this->m1];
        $this->actingAs($wh)->getJson('/api/close')->assertStatus(403);
        $o = $this->actingAs($fin)->getJson('/api/close')->assertOk()->json();
        $this->assertSame('OPEN', collect($o['months'])->firstWhere('month', $this->m1)['cells'][0]['status']);
        $this->assertSame('EMPTY', collect($o['months'])->firstWhere('month', $this->m2)['cells'][0]['status']);
        $this->assertSame(1, Close::openTasks(1));
        $this->assertTrue(TaskEngine::isOpen('FIN_PERIOD_OPEN', [$this->shop . '-' . $this->m1]));
        $this->postJson('/api/close/check', $q)->assertOk()->assertJsonPath('status', 'CHECKED');
        $this->postJson('/api/close/lock', $q + ['note' => 'x'])->assertStatus(403);
        $this->actingAs($owner)->postJson('/api/close/lock', $q)->assertStatus(422);
        $this->postJson('/api/close/lock', $q + ['note' => 'All good'])->assertOk()->assertJsonPath('ledger_locked_up_to', $this->m1);
        $this->getJson('/api/close/month?' . http_build_query($q))->assertOk()->assertJsonPath('status', 'LOCKED')->assertJsonPath('frozen', true);
        $this->assertFalse(TaskEngine::isOpen('FIN_PERIOD_OPEN', [$this->shop . '-' . $this->m1]));
        $this->getJson('/api/close/month?shop=' . $this->shop . '&month=2026-13')->assertStatus(422);
    }

    public function test_the_first_money_file_of_a_shop_may_start_inside_the_month(): void
    {
        $this->order('ORD-1', $this->m1 . '-20'); Sales::confirmMonth($this->shop, $this->m1, true);
        $end = date('Y-m-t', strtotime($this->m1 . '-01'));
        DB::table('mo_settlement_periods')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'period_from' => $this->m1 . '-03', 'period_to' => $end, 'hub_check' => 'OK', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => 'ST1', 'statement_date' => $this->m1 . '-25', 'currency' => 'GBP', 'status' => 'PAID', 'f_rows_complete' => 1]);
        $c = collect(Close::check(1, $this->shop, $this->m1)['checks'])->firstWhere('code', 'FILES_PRESENT');
        $this->assertSame('GREEN', $c['status']); $this->assertStringContainsString("first file starts", $c['detail']);
        // a later file with a hole at the start of the next month is still red
        DB::table('mo_settlement_periods')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'period_from' => $this->m2 . '-05', 'period_to' => date('Y-m-t', strtotime($this->m2 . '-01')), 'hub_check' => 'OK', 'created_at' => now(), 'updated_at' => now()]);
        $this->order('ORD-2', $this->m2 . '-10');
        $this->assertSame('RED', collect(Close::check(1, $this->shop, $this->m2)['checks'])->firstWhere('code', 'FILES_PRESENT')['status']);
    }

    public function test_choices_keep_both_ways_available_and_only_the_owner_switches(): void
    {
        $c = collect(\App\Services\Choices::all(1))->keyBy('key');
        $this->assertSame(['1', '1', 'FORWARD'], [$c['PROFIT_SHARE_AFTER_OVERHEADS']['value'], $c['CLOSE_FIRST_FILE_PARTIAL']['value'], $c['LATE_LINES_LOCKED_MONTH']['value']], 'the recommended way is the default');
        foreach ($c as $x) { $this->assertGreaterThanOrEqual(2, count($x['options'])); $this->assertCount($x['fact'] ? 0 : 1, array_filter($x['options'], fn ($o) => $o['recommended'])); }
        $mgr = $this->makeUser('MANAGER');
        $this->actingAs($mgr)->getJson('/api/choices')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/choices', ['key' => 'LATE_LINES_LOCKED_MONTH', 'value' => 'REFUSE'])->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/choices', ['key' => 'LATE_LINES_LOCKED_MONTH', 'value' => 'MAYBE'])->assertStatus(422);
        // strict first file: the mid-month file no longer covers the first month
        $this->order('ORD-1', $this->m1 . '-20'); Sales::confirmMonth($this->shop, $this->m1, true);
        DB::table('mo_settlement_periods')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'period_from' => $this->m1 . '-03', 'period_to' => date('Y-m-t', strtotime($this->m1 . '-01')), 'hub_check' => 'OK', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'TIKTOK_SETTLEMENT', 'statement_id' => 'ST1', 'statement_date' => $this->m1 . '-25', 'currency' => 'GBP', 'status' => 'PAID', 'f_rows_complete' => 1]);
        $this->postJson('/api/choices', ['key' => 'CLOSE_FIRST_FILE_PARTIAL', 'value' => '0'])->assertOk();
        $this->assertSame('RED', collect(Close::check(1, $this->shop, $this->m1)['checks'])->firstWhere('code', 'FILES_PRESENT')['status']);
        $this->postJson('/api/choices', ['key' => 'CLOSE_FIRST_FILE_PARTIAL', 'value' => '1'])->assertOk();
        // late line of a locked month: refused when the company chooses so, booked forward otherwise
        Context::set(1, $this->owner->id);
        foreach (['ORDERS_VS_TXN', 'PAYOUT_VS_BANK'] as $w) if (collect(Close::check(1, $this->shop, $this->m1)['checks'])->firstWhere('code', $w)['status'] === 'RED') Close::waive(1, $this->shop, $this->m1, $w, 'test');
        Close::lock(1, $this->shop, $this->m1, 'ok');
        $this->order('ORD-LATE', $this->m1 . '-28');
        $this->postJson('/api/choices', ['key' => 'LATE_LINES_LOCKED_MONTH', 'value' => 'REFUSE'])->assertOk();
        try { Sales::confirmMonth($this->shop, $this->m1, true); $this->fail('refused'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Settings › Choices', $e->getMessage()); }
        $this->postJson('/api/choices', ['key' => 'LATE_LINES_LOCKED_MONTH', 'value' => 'FORWARD'])->assertOk();
        $this->assertSame(1, Sales::confirmMonth($this->shop, $this->m1, true)['lines']);
        $this->assertSame(3, DB::table('sys_audit_log')->where('table_name', 'sys_settings')->where('fk_id_ent', 1)->where('changes_json', 'like', '%LATE_LINES_LOCKED_MONTH%')->count() + DB::table('sys_audit_log')->where('changes_json', 'like', '%CLOSE_FIRST_FILE_PARTIAL%')->count() - 1, 'every switch is in the audit');
    }
}
