<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\RecordService;
use App\Services\SupplierBanks as SB;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SUP_BANK (06_ENGINES §A, R-FIN-70, R-B_SOURCING-57 / 66): supplier bank details wait for a phone check by a second person and the Owner; unapproved details block the payment; staff banks are outside. */
class SupplierBanksTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function as(object $u): void { $this->actingAs($u); Context::set(1, $u->id); }

    public function test_supplier_bank_needs_the_phone_check_then_the_owner_and_blocks_the_payment_until_then(): void
    {
        \App\Services\Fx::set('USD', '2026-11-01', 0.80);
        $maker = $this->makeUser('MANAGER'); $fin = $this->makeUser('FINANCE'); $this->as($maker);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-B1', 'supplier_name' => 'Maker Co', 'f_active' => 1]);
        $country = (int) (DB::table('cat_country')->value('id') ?: $this->f('cat_country', []));
        $bankName = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Bank of Test', 'f_active' => 1]); $cur = Buy::currencyId('USD');
        $row = ['fk_id_src_supplier' => $sup, 'country_id' => $country, 'fk_bank_id' => $bankName, 'account_no' => '6222000011112222', 'fk_currency_id' => $cur, 'beneficiary_name' => 'Maker Co Ltd', 'f_active' => 1];
        $id = (int) RecordService::save('supplier_bank', $row)['id'];
        $b = DB::table('t_account_banks')->where('id', $id)->first(); $this->assertSame(['PENDING', $maker->id], [$b->approval_status, (int) $b->bank_changed_by]);
        $ap = DB::table('sys_approvals')->where('id', $b->fk_id_bank_approval)->first(); $this->assertSame(['SUP_BANK', 'HIGH', 'PENDING'], [$ap->action_code, $ap->risk, $ap->status]);
        $this->assertTrue(TaskEngine::isOpen('ACC_BANK_PHONE_CHECK', [$id]));
        // the payment is blocked, and the payment screen says why (beneficiary shown, never the full number)
        $ourBank = \App\Services\MoneyService::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 5000, 'opening_date' => '2026-10-01']);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-B1', 'fk_id_currency' => $cur, 'fk_id_supplier' => $sup, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $term = $this->f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000, 'status' => 'DUE', 'f_active' => 1]);
        $this->as($this->owner);
        try { Buy::pay(['term' => $term, 'amount' => 100, 'date' => '2026-11-05', 'bank' => $ourBank]); $this->fail('paid to unapproved details'); } catch (\RuntimeException $e) { $this->assertSame(SB::BLOCKED, $e->getMessage()); }
        $info = $this->getJson('/api/pay/bank-info?term=' . $term)->assertOk()->json(); $this->assertSame(['Maker Co Ltd', 'PENDING', false], [$info['banks'][0]['beneficiary'], $info['banks'][0]['status'], $info['payable']]);
        $this->assertStringNotContainsString('6222000011112222', json_encode($info));
        // the Owner cannot approve before the phone check; the maker cannot do the phone check himself
        try { Approvals::decide((int) $ap->id, true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('A second person must record the phone check with the supplier first.', $e->getMessage()); }
        $this->as($maker); try { SB::phoneCheck($id, 'Spoke to Li', $maker); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('a second person must phone', $e->getMessage()); }
        $this->as($fin); $this->postJson("/api/supplier-banks/$id/phone-check", ['note' => 'Spoke to Li on the number of the old invoice'])->assertOk(); $this->assertFalse(TaskEngine::isOpen('ACC_BANK_PHONE_CHECK', [$id]));
        try { Approvals::decide((int) $ap->id, true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner approves the bank details of a supplier.', $e->getMessage()); }
        $this->as($this->owner); Approvals::decide((int) $ap->id, true);
        $this->assertSame('APPROVED', DB::table('t_account_banks')->where('id', $id)->value('approval_status'));
        $this->assertGreaterThan(0, Buy::pay(['term' => $term, 'amount' => 100, 'date' => '2026-11-05', 'bank' => $ourBank]), 'approved details: the payment goes through');
        // a change of the paying details starts again; a change of another field does not
        $this->as($maker); RecordService::save('supplier_bank', ['f_active' => 1], $id); $this->assertSame('APPROVED', DB::table('t_account_banks')->where('id', $id)->value('approval_status'));
        RecordService::save('supplier_bank', ['beneficiary_name' => 'Maker Trading HK'], $id); $b2 = DB::table('t_account_banks')->where('id', $id)->first(); $this->assertSame(['PENDING', null], [$b2->approval_status, $b2->phone_checked_at]);
        $this->as($this->owner); try { Buy::pay(['term' => $term, 'amount' => 100, 'date' => '2026-11-06', 'bank' => $ourBank]); $this->fail(); } catch (\RuntimeException $e) { $this->assertSame(SB::BLOCKED, $e->getMessage()); }
        Approvals::decide((int) $b2->fk_id_bank_approval, false, 'Not confirmed'); $this->assertSame('REJECTED', DB::table('t_account_banks')->where('id', $id)->value('approval_status'));
    }

    public function test_staff_bank_is_outside_and_a_supplier_without_details_follows_the_choice(): void
    {
        $this->as($this->owner); $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SB' . random_int(1000, 9999), 'first_name' => 'Staff', 'sur_name' => 'One', 'f_active' => 1]);
        $id = $this->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'account_no' => '12345678']])->assertOk()->json('id');
        $this->assertSame('APPROVED', DB::table('t_account_banks')->where('id', $id)->value('approval_status'), 'a staff bank is a normal history entry'); $this->assertSame(0, DB::table('sys_approvals')->where('action_code', 'SUP_BANK')->count());
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-B2', 'supplier_name' => 'No Bank Co', 'f_active' => 1]);
        SB::assertPayable(1, $sup); \App\Services\Choices::set('SUP_BANK_NO_DETAILS', 'BLOCK', 1);
        $this->expectException(\RuntimeException::class); SB::assertPayable(1, $sup);
    }

    /** R-B_SOURCING-66 the D warning is in every supplier dropdown of the record forms; the withdrawal form reads "Requested by" from the logins. */
    public function test_rated_d_warning_in_the_form_dropdown_and_withdrawal_requested_by(): void
    {
        $this->as($this->owner); $bad = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-D', 'supplier_name' => 'Risky Co', 'rating' => 'D', 'f_active' => 1]); $ok = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-A', 'supplier_name' => 'Good Co', 'rating' => 'A', 'f_active' => 1]);
        $o = collect(\App\Services\FormAssist::options('t_src_po', 'fk_id_supplier', null)['options'])->keyBy(fn ($x) => (int) $x[0]);
        $this->assertStringEndsWith('⚠ Supplier rated D – do not use', $o[$bad][1]); $this->assertStringNotContainsString('rated D', $o[$ok][1]);
        $f = collect(\App\Services\Registry::fields('fin_withdrawal'))->firstWhere('field_name', 'requested_by'); $this->assertSame(['table:t_users', 1], [$f['source'], (int) $f['readonly']]);
        $this->assertContains('beneficiary_name', array_column(\App\Services\Registry::fields('supplier_bank'), 'field_name'));
    }

}
