<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\RecordService;
use App\Services\VaStatements;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-65: VA statement per shop + month – one formula with R-C-40, version, PDF, Owner approval, payables, message to the VA. */
class VaStatementTest extends TestCase
{
    private int $mp; private int $shop; private int $va; private int $batch;

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->va = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'account_code' => 'VA-7', 'first_name' => 'Vera', 'sur_name' => 'Seller', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fk_id_seller' => $this->va]));
        DB::table('t_account_job_history')->insert($this->fill('t_account_job_history', ['fk_id_account' => $this->va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => '2020-01-01', 'share_percentage' => 10, 'salary' => 60000, 'pound_exchange_rate' => 300]));
        // sale 2,000, buy 1,200, fee 100, VA 10 %
        $this->batch = DB::table('t_sale_imports')->insertGetId($this->fill('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'import_nature' => 'Normal', 'file_name' => 'f.csv', 'period_month' => 7, 'period_year' => 2026, 'marketplace_fee' => 100, 'va_share_percentage' => 10, 'fk_id_va_account' => $this->va, 'status' => 'Pending']));
        foreach ([[1500, 900], [500, 300]] as $i => [$t, $b]) DB::table('t_sales')->insert($this->fill('t_sales', ['fk_id_ent' => 1, 'fk_id_import' => $this->batch, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'order_no' => "O-$i", 'd_order' => '2026-07-05', 'sku' => 'S', 'qty' => 1, 'sale_rate' => $t, 'total_amount' => $t, 'buy_amount' => $b, 'profit' => $t - $b, 'status' => 'Pending', 'sale_source' => 'active']));
    }

    public function test_the_formula_with_both_examples_of_the_rule(): void
    {
        $this->assertSame(['after_fee_profit' => 700.0, 'va_share_amount' => 70.0, 'net_to_store' => 580.0], VaStatements::calc(2000, 1200, 100, 50, 10, false));
        $this->assertSame(['after_fee_profit' => 700.0, 'va_share_amount' => 65.0, 'net_to_store' => 585.0], VaStatements::calc(2000, 1200, 100, 50, 10, true));
        $this->world(); $p = VaStatements::preview($this->shop, '2026-07', 50);
        $this->assertSame([2000.0, 1200.0, 100.0, 700.0, 10.0, 70.0, 580.0, 'VA-7 - Vera Seller'], [$p['sale_total'], $p['buy_total'], $p['fee_total'], $p['after_fee_profit'], $p['va_share_pct'], $p['va_share_amount'], $p['net_to_store'], $p['va_name']]);
        Choices::setShop('VA_EXPENSES_BEFORE_SHARE', $this->shop, '1', 1); \App\Services\Settings::flush();
        $p = VaStatements::preview($this->shop, '2026-07', 50); $this->assertSame([65.0, 585.0, true], [$p['va_share_amount'], $p['net_to_store'], $p['expenses_before_share']]);
        try { VaStatements::preview($this->shop, '2026-06', 0); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no sales import for June 2026', $e->getMessage()); }
    }

    public function test_statement_is_saved_with_a_version_and_a_pdf_and_waits_for_the_owner(): void
    {
        $this->world();
        foreach ([['', 'x', 'Enter the expenses. Use 0 if there are none.'], [50, ' ', 'Write what the expenses are (Expenses detail).']] as [$e, $d, $msg]) { try { VaStatements::generate($this->shop, '2026-07', $e, $d, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $x) { $this->assertSame($msg, $x->getMessage()); } }
        $r = VaStatements::generate($this->shop, '2026-07', 50, 'packing tape', $this->owner); $this->assertSame(1, $r['version']); $this->assertSame('va_statements/' . $r['id'] . '/va_statement_101A_2026-07.pdf', $r['file']);
        $this->assertStringStartsWith('%PDF', file_get_contents(storage_path('app/' . $r['file'])));
        $s = DB::table('va_statements')->where('id', $r['id'])->first(); $this->assertSame(['WAITING', 700.0, 70.0, 580.0, 100.0, $this->va, $this->batch], [$s->status, (float) $s->after_fee_profit, (float) $s->va_share_amount, (float) $s->net_to_store, (float) $s->fee_total, (int) $s->fk_id_va, (int) $s->fk_id_batch]);
        $r2 = VaStatements::generate($this->shop, '2026-07', 0, null, $this->owner); $this->assertSame(2, $r2['version']); $this->assertSame('REPLACED', DB::table('va_statements')->where('id', $r['id'])->value('status'));
        $this->assertTrue(is_file(storage_path('app/' . $r['file'])));      // the old version's file stays
        $this->getJson('/api/va-statements')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.version', 2);
        $this->get('/api/va-statements/' . $r2['id'] . '/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/va-statements/preview?shop=' . $this->shop . '&month=2026-07&expenses=50')->assertOk()->assertJsonPath('net_to_store', 580);
        foreach ([$r, $r2] as $x) { @unlink(storage_path('app/' . $x['file'])); @rmdir(dirname(storage_path('app/' . $x['file']))); }
    }

    public function test_owner_approval_makes_one_salary_payable_and_the_share_payable_and_sends_to_the_va(): void
    {
        $this->world(); DB::table('t_account')->where('id', $this->va)->update(['login_id' => 'vera@demo.test']);
        $mgr = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'mgr@demo.test', 'display_name' => 'M', 'role_code' => 'MANAGER', 'f_active' => 1])));
        $v1 = VaStatements::generate($this->shop, '2026-07', 50, 'tape', $mgr)['id'];
        try { VaStatements::approve($v1, $mgr); $this->fail('no error'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }      // a Manager makes it, only the Owner approves
        $a = VaStatements::approve($v1, $this->owner); $this->assertSame(200.0, $a['salary_gbp']);      // 60,000 at the job row's own rate 300 = £200
        $this->assertSame('APPROVED', DB::table('va_statements')->where('id', $v1)->value('status')); $this->assertSame(1, DB::table('sys_events')->where('event_code', 'VA_STATEMENT_APPROVED')->count());
        $job = (int) DB::table('t_account_job_history')->where('fk_id_account', $this->va)->value('id'); $sal = "SALARY:{$this->va}:$job:2026-07"; $shr = "VA_SHARE:{$this->va}:{$this->shop}:2026-07";
        $this->assertSame([200.0, 'SALARY_VA'], [(float) DB::table('fin_cash_commitments')->where('dedupe_key', $sal)->value('amount_gbp'), DB::table('fin_cash_commitments')->where('dedupe_key', $sal)->value('category_code')]);
        $this->assertSame(70.0, (float) DB::table('fin_cash_commitments')->where('dedupe_key', $shr)->value('amount_gbp'));
        // approve v1 and then v2 of the same month → still one salary payable; the share payable follows the new version
        $v2 = VaStatements::generate($this->shop, '2026-07', 0, null, $mgr)['id']; VaStatements::approve($v2, $this->owner);
        $this->assertSame([1, 1, 2], [DB::table('fin_cash_commitments')->where('category_code', 'SALARY_VA')->count(), DB::table('fin_cash_commitments')->where('category_code', 'VA_SHARE_PAYOUT')->count(), DB::table('fin_cash_commitments')->count()]);
        $this->assertSame(['REPLACED', 'APPROVED'], [DB::table('va_statements')->where('id', $v1)->value('status'), DB::table('va_statements')->where('id', $v2)->value('status')]);
        try { VaStatements::approve($v2, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('This statement is already approved.', $e->getMessage()); }
        // the VA share paid by a bank line categorised VA_SHARE_PAYOUT closes the payable; the payout goes to the payable account, not to cost
        $this->assertSame('2300', \App\Services\MoneyService::accountFor('VA_SHARE_PAYOUT')); $cid = VaStatements::bankPaid(1, 'VA_SHARE_PAYOUT', $this->va, 70.00, 555); $this->assertNotNull($cid);
        $this->assertSame(['PAID', 555], [DB::table('fin_cash_commitments')->where('id', $cid)->value('status'), (int) DB::table('fin_cash_commitments')->where('id', $cid)->value('fk_id_bank_line')]); $this->assertNull(VaStatements::bankPaid(1, 'VA_SHARE_PAYOUT', $this->va, 70.00, 556));
        // a VA login sees only his own approved statements; send back needs a reason
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'vera@demo.test', 'display_name' => 'Vera', 'role_code' => 'VA', 'f_active' => 1])));
        $l = VaStatements::list($va, ['all' => 1]); $this->assertSame([$v2], array_column($l['rows'], 'id')); $this->assertFalse($l['can_approve']);
        $v3 = VaStatements::generate($this->shop, '2026-07', 10, 'bags', $mgr)['id']; VaStatements::reject($v3, 'expenses are wrong', $this->owner); $this->assertSame(['REJECTED', 'expenses are wrong'], [DB::table('va_statements')->where('id', $v3)->value('status'), DB::table('va_statements')->where('id', $v3)->value('reject_reason')]);
        foreach (DB::table('va_statements')->pluck('pdf_file') as $f) { @unlink(storage_path('app/' . $f)); @rmdir(dirname(storage_path('app/' . $f))); }
    }
}
