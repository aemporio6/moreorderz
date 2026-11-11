<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    public function test_balanced_journal_posts_and_trial_balance_is_equal(): void
    {
        // Supplier advance £1,000 from the bank, then goods arrive (advance becomes stock).
        Ledger::post(1, '2026-08-03', 'PAYMENT', 1, [
            ['account' => '1300', 'debit' => 1000.00, 'ref_id' => 7],
            ['account' => '1000', 'credit' => 1000.00, 'ref_id' => 1],
        ]);
        Ledger::post(1, '2026-08-20', 'GRN', 1, [
            ['account' => '1200', 'debit' => 1000.00],
            ['account' => '1300', 'credit' => 1000.00, 'ref_id' => 7],
        ]);
        $tb = Ledger::trialBalance(1);
        $this->assertTrue($tb['balanced']);
        $this->assertSame(1000.00, $tb['debit']);
        $this->assertSame(0.00, Ledger::balance(1, '1300', 'PARTY', 7));
        $this->assertSame(-1000.00, Ledger::balance(1, '1000'));
        $this->assertSame(1000.00, Ledger::balance(1, '1200'));
    }

    public function test_unbalanced_journal_is_refused(): void
    {
        $this->expectExceptionMessage('does not balance');
        Ledger::post(1, '2026-08-03', 'EXPENSE', 1, [['account' => '6000', 'debit' => 10.00], ['account' => '1000', 'credit' => 9.99, 'ref_id' => 1]]);
    }

    public function test_penny_rounding_cannot_hide_a_difference(): void
    {
        $this->expectExceptionMessage('does not balance');
        Ledger::post(1, '2026-08-03', 'EXPENSE', 1, [['account' => '6000', 'debit' => 0.01], ['account' => '6000', 'debit' => 0.01], ['account' => '1000', 'credit' => 0.03, 'ref_id' => 1]]);
    }

    public function test_sub_ledger_account_needs_a_reference(): void
    {
        $this->expectExceptionMessage('needs a PARTY reference');
        Ledger::post(1, '2026-08-03', 'PAYMENT', 1, [['account' => '1300', 'debit' => 5], ['account' => '6000', 'credit' => 5]]);
    }

    public function test_reversal_brings_every_balance_back_to_zero_and_cannot_run_twice(): void
    {
        $id = Ledger::post(1, '2026-08-03', 'EXPENSE', 9, [['account' => '6000', 'debit' => 45.50], ['account' => '1000', 'credit' => 45.50, 'ref_id' => 1]]);
        $rid = Ledger::reverse($id, '2026-08-04');
        $this->assertSame(0.00, Ledger::balance(1, '6000'));
        $this->assertSame(0.00, Ledger::balance(1, '1000'));
        $this->assertSame($id, (int) DB::table('gl_journals')->where('id', $rid)->value('reverses_id'));
        $this->expectExceptionMessage('already reversed');
        Ledger::reverse($id);
    }

    public function test_locked_month_takes_no_journal(): void
    {
        Settings::set('GL_LOCKED_UPTO', '2026-08', 1);
        Ledger::post(1, '2026-09-01', 'EXPENSE', 1, [['account' => '6000', 'debit' => 1], ['account' => '1000', 'credit' => 1, 'ref_id' => 1]]);
        $this->expectExceptionMessage('is locked');
        Ledger::post(1, '2026-08-31', 'EXPENSE', 1, [['account' => '6000', 'debit' => 1], ['account' => '1000', 'credit' => 1, 'ref_id' => 1]]);
    }

    public function test_profit_and_loss_matches_the_golden_month(): void
    {
        // Golden truth, Bizmart Aug 2026: sales 2,202.09, marketplace costs 690.20, net 1,511.89, profit 377.50.
        Ledger::post(1, '2026-08-31', 'SALE_CONFIRM', 1, [['account' => '1100', 'debit' => 2202.09, 'ref_id' => 3], ['account' => '4000', 'credit' => 2202.09, 'ref_id' => 3]]);
        Ledger::post(1, '2026-08-31', 'SETTLEMENT', 1, [['account' => '5100', 'debit' => 690.20, 'ref_id' => 3], ['account' => '1100', 'credit' => 690.20, 'ref_id' => 3]]);
        Ledger::post(1, '2026-08-31', 'SALE_CONFIRM', 1, [['account' => '5000', 'debit' => 1134.39], ['account' => '1200', 'credit' => 1134.39]]);
        $pl = Ledger::profitAndLoss(1, '2026-08-01', '2026-08-31');
        $this->assertSame(2202.09, $pl['income']);
        $this->assertSame(377.50, $pl['profit']);
        $this->assertSame(1511.89, Ledger::balance(1, '1100', 'SHOP', 3));   // what the marketplace still owes = net payout
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_companies_do_not_mix(): void
    {
        DB::table('t_enterprise')->insert(['id' => 2, 'fk_id_module' => 0, 'iso_ent' => 'TS2', 'ent_name' => 'Second Ltd', 'ent_address' => '-', 'f_active' => 1, 'ent_short' => 'TS2']);
        Ledger::post(1, '2026-08-03', 'EXPENSE', 1, [['account' => '6000', 'debit' => 10], ['account' => '1000', 'credit' => 10, 'ref_id' => 1]]);
        Ledger::post(2, '2026-08-03', 'EXPENSE', 1, [['account' => '6000', 'debit' => 99], ['account' => '1000', 'credit' => 99, 'ref_id' => 5]]);
        $this->assertSame(10.00, Ledger::balance(1, '6000'));
        $this->assertSame(99.00, Ledger::balance(2, '6000'));
        $this->assertStringContainsString('JNL-TS2-', DB::table('gl_journals')->where('fk_id_ent', 2)->value('journal_no'));
    }
}
