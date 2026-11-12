<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\Context;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\RecordService;
use App\Services\Settings;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    private int $bank; private int $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main current ••1234', 'currency' => 'GBP', 'opening_balance' => 1000, 'opening_date' => '2026-08-01']);
        $this->profile = DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
    }

    private function csv(array $rows): string
    {
        $o = "Date,Description,Reference,Amount,Balance,Transaction ID\n";
        foreach ($rows as $r) $o .= implode(',', $r) . "\n";
        return $o;
    }

    private function bankRow(): array { return collect(Money::banks(1))->firstWhere('id', $this->bank); }

    public function test_opening_balance_is_booked_and_a_statement_loads_once(): void
    {
        $this->assertSame(1000.0, Ledger::balance(1, '1000', null, $this->bank));
        $csv = $this->csv([['05/08/2026', 'CARD SOFTWARE LTD', 'INV 1', '-49.99', '950.01', 'T1'], ['06/08/2026', 'INTEREST', '', '1.25', '951.26', 'T2'], ['bad-date', 'X', '', '-1', '', 'T3'], ['07/08/2026', 'ZERO', '', '0', '', 'T4']]);
        $r = Money::importStatement(1, $this->bank, $this->profile, $csv, 'aug.csv');
        $this->assertSame([2, 0, 2], [$r['new'], $r['duplicates'], count($r['errors'])]);
        $this->assertStringContainsString('Row 4', $r['errors'][0]);
        $again = Money::importStatement(1, $this->bank, $this->profile, $csv, 'aug.csv');
        $this->assertSame([0, 2], [$again['new'], $again['duplicates']], 'the same lines never load twice');
        $b = $this->bankRow();
        $this->assertSame([951.26, 1000.0, -48.74, 2, true], [$b['statement_balance'], $b['books_base'], $b['difference'], $b['unmatched'], $b['explained']], 'the whole difference is the two unmatched lines');
        try { Money::importStatement(1, $this->bank, DB::table('bank_statement_profiles')->where('code', 'EX_BANK_DEBIT_CREDIT')->value('id'), $csv); $this->fail('wrong layout'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('is not in this file', $e->getMessage()); }
    }

    public function test_lines_without_a_bank_id_are_told_apart_by_their_position(): void
    {
        $csv = "Date,Description,Reference,Amount,Balance,Transaction ID\n05/08/2026,COFFEE,,-3.00,,\n05/08/2026,COFFEE,,-3.00,,\n";
        $this->assertSame(2, Money::importStatement(1, $this->bank, $this->profile, $csv)['new'], 'two equal lines on one day are two lines');
        $this->assertSame([0, 2], array_values(array_intersect_key(Money::importStatement(1, $this->bank, $this->profile, $csv), ['new' => 1, 'duplicates' => 1])));
    }

    public function test_a_person_says_what_a_line_is_and_the_books_follow_and_undo_takes_it_back(): void
    {
        Money::importStatement(1, $this->bank, $this->profile, $this->csv([['05/08/2026', 'CARD SOFTWARE LTD', '', '-49.99', '', 'T1'], ['06/08/2026', 'INTEREST', '', '1.25', '', 'T2'], ['07/08/2026', 'PARTNER MONEY', '', '5000', '', 'T3']]));
        $id = fn ($t) => (int) DB::table('fin_bank_transactions')->where('bank_txn_id', $t)->value('id');
        try { Money::categorise(1, $id('T1'), ['category' => 'OTHER_IN']); $this->fail('direction'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('money in', $e->getMessage()); }
        try { Money::categorise(1, $id('T1'), ['category' => 'SUPPLIER_PAYMENT']); $this->fail('not by hand'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('purchase order', $e->getMessage()); }
        $x = Money::categorise(1, $id('T1'), ['category' => 'SOFTWARE']);
        $this->assertSame('6000', $x['account']);
        $e = DB::table('fin_expenses')->where('id', $x['expense'])->first();
        $this->assertSame(['SOFTWARE', 49.99, 'BANK', $id('T1')], [$e->category_code, (float) $e->amount, $e->source, (int) $e->fk_id_bank_line]);
        Money::categorise(1, $id('T2'), ['category' => 'OTHER_IN']);
        Money::categorise(1, $id('T3'), ['category' => 'CAPITAL_IN']);
        $this->assertSame([49.99, -1.25, -5000.0], [Ledger::balance(1, '6000'), Ledger::balance(1, '4100'), Ledger::balance(1, '2150')]);
        $b = $this->bankRow();
        $this->assertSame([5951.26, 5951.26, 0.0, 0], [$b['statement_base'], $b['books_base'], $b['difference'], $b['unmatched']], 'all matched: books = statement');
        try { Money::categorise(1, $id('T1'), ['category' => 'OFFICE']); $this->fail('already matched'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already matched', $e->getMessage()); }
        Money::unmatch(1, $id('T1'));
        $this->assertSame(0.0, Ledger::balance(1, '6000'));
        $this->assertSame(0, (int) DB::table('fin_expenses')->where('id', $x['expense'])->value('f_active'));
        $this->assertSame(1, $this->bankRow()['unmatched']);
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }

    public function test_shop_accounts_need_the_shop_and_a_line_can_be_set_aside_with_a_reason(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $f('cat_marketplaces', ['fk_id_ent' => 1]), 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1]);
        Money::importStatement(1, $this->bank, $this->profile, $this->csv([['05/08/2026', 'MARKETPLACE PAYOUT', '', '120.00', '', 'T1'], ['06/08/2026', 'OLD LINE', '', '-5', '', 'T2']]));
        $t1 = (int) DB::table('fin_bank_transactions')->where('bank_txn_id', 'T1')->value('id'); $t2 = (int) DB::table('fin_bank_transactions')->where('bank_txn_id', 'T2')->value('id');
        try { Money::categorise(1, $t1, ['category' => 'SALES_PAYOUT']); $this->fail('shop needed'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('shop', $e->getMessage()); }
        Money::categorise(1, $t1, ['category' => 'SALES_PAYOUT', 'branch' => $shop]);
        $this->assertSame(-120.0, Ledger::balance(1, '1100', null, $shop), 'a payout with no settlement file reduces what the shop owes us');
        try { Money::ignore(1, $t2, ''); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        Money::ignore(1, $t2, 'Before the opening balance');
        $this->assertSame('IGNORED', DB::table('fin_bank_transactions')->where('id', $t2)->value('match_status'));
    }

    public function test_transfer_between_own_accounts_books_once_and_links_both_lines(): void
    {
        $save = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Savings', 'currency' => 'GBP']);
        Money::importStatement(1, $this->bank, $this->profile, $this->csv([['10/08/2026', 'TO SAVINGS', '', '-300', '', 'A1']]));
        Money::importStatement(1, $save, $this->profile, $this->csv([['11/08/2026', 'FROM CURRENT', '', '300', '', 'B1']]));
        $a = (int) DB::table('fin_bank_transactions')->where('bank_txn_id', 'A1')->value('id');
        $r = Money::transfer(1, $a, $save);
        $this->assertNotNull($r['mirror']);
        $this->assertSame([700.0, 300.0], [Ledger::balance(1, '1000', null, $this->bank), Ledger::balance(1, '1000', null, $save)]);
        $this->assertSame(0, DB::table('fin_bank_transactions')->where('match_status', 'UNMATCHED')->count());
        Money::unmatch(1, $a);
        $this->assertSame([1000.0, 0.0, 2], [Ledger::balance(1, '1000', null, $this->bank), Ledger::balance(1, '1000', null, $save), DB::table('fin_bank_transactions')->where('match_status', 'UNMATCHED')->count()]);
    }

    public function test_expense_by_hand_waits_for_a_second_person_over_the_limit_and_the_bank_line_then_matches_it(): void
    {
        $fin = $this->makeUser('FINANCE'); $mgr = $this->makeUser('MANAGER');
        Context::set(1, $fin->id); $this->actingAs($fin);
        $small = Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 40, 'date' => '2026-08-12', 'note' => 'Paper']);
        $this->assertSame('BOOKED', $small['status']);
        $big = Money::addExpense(1, ['bank' => $this->bank, 'category' => 'PROFESSIONAL', 'amount' => 900, 'date' => '2026-08-12', 'note' => 'Accountant']);
        $this->assertSame('WAITING', $big['status']);
        $this->assertSame(40.0, Ledger::balance(1, '6000'), 'nothing is booked while it waits');
        $this->assertTrue(TaskEngine::isOpen('FIN_APPROVE', [$big['approval']]));
        try { Approvals::decide($big['approval'], true); $this->fail('finance cannot decide'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Owner or a Manager', $e->getMessage()); }
        Context::set(1, $mgr->id); $this->actingAs($mgr);
        try { Approvals::decide($big['approval'], false); $this->fail('reason for no'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        Approvals::decide($big['approval'], true);
        $this->assertSame(940.0, Ledger::balance(1, '6000'));
        $this->assertFalse(TaskEngine::isOpen('FIN_APPROVE', [$big['approval']]));
        try { Approvals::decide($big['approval'], true); $this->fail('twice'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already decided', $e->getMessage()); }
        // a manager's own big expense: someone else must decide
        $own = Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 300, 'date' => '2026-08-13']);
        try { Approvals::decide($own['approval'], true); $this->fail('own request'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('second person', $e->getMessage()); }
        Money::cancelExpense(1, $own['id'], 'Entered twice');
        $this->assertSame('CANCELLED', DB::table('sys_approvals')->where('id', $own['approval'])->value('status'));
        // the statement arrives: both booked expenses are found by amount + date + account, nothing is booked twice
        $r = Money::importStatement(1, $this->bank, $this->profile, $this->csv([['12/08/2026', 'STATIONERY', '', '-40.00', '', 'E1'], ['14/08/2026', 'ACCOUNTANT', '', '-900.00', '', 'E2']]));
        $this->assertSame(2, $r['matched']);
        $this->assertSame(940.0, Ledger::balance(1, '6000'));
        $b = $this->bankRow(); $this->assertSame([60.0, 60.0, 0], [$b['statement_base'], $b['books_base'], $b['unmatched']]);
        try { Money::cancelExpense(1, $small['id'], 'x'); $this->fail('matched'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('matched to a bank line', $e->getMessage()); }
        Settings::set('APPROVAL_LIMIT_EXPENSE', 5000, 1);
        $this->assertSame('BOOKED', Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 300, 'date' => '2026-08-13'])['status'], 'the limit is a setting');
    }

    public function test_two_equal_candidates_are_left_for_a_person_and_old_unmatched_lines_get_a_task(): void
    {
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 25, 'date' => '2026-08-12']);
        Money::addExpense(1, ['bank' => $this->bank, 'category' => 'OFFICE', 'amount' => 25, 'date' => '2026-08-13']);
        $r = Money::importStatement(1, $this->bank, $this->profile, $this->csv([['12/08/2026', 'SHOP', '', '-25.00', '', 'E1']]));
        $this->assertSame(0, $r['matched'], 'two expenses fit: the tool does not guess');
        $b = $this->bankRow();
        $this->assertSame([-50.0, 25.0, 0.0, true], [$b['not_on_bank_yet'], $b['difference'], $b['unexplained'], $b['explained']], 'bank − books = the unmatched line (−25) less the two expenses not on the bank yet (−50)');
        $this->assertSame(1, Money::unmatchedTasks(1));
        $line = (int) DB::table('fin_bank_transactions')->where('bank_txn_id', 'E1')->value('id');
        $this->assertTrue(TaskEngine::isOpen('FIN_BANK_UNMATCHED', [$line]));
        Money::ignore(1, $line, 'Test');
        $this->assertFalse(TaskEngine::isOpen('FIN_BANK_UNMATCHED', [$line]));
    }
}
