<?php

namespace Tests\Feature;

use App\Services\InvestorDocs as Docs;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-D-83 documents from a settled month, R-D-84 cancel / credit note / payments / statuses / reopen – the spec examples. */
class InvestorDocsTest extends TestCase
{
    private int $period; private int $inv; private int $ownerAcc;

    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    protected function setUp(): void
    {
        parent::setUp();
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101', 'iso_branch' => '101', 'shop_name' => 'Shop 101', 'f_active' => 1]);
        $this->ownerAcc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWN1', 'first_name' => 'Omar', 'f_active' => 1]);
        $this->inv = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'ALP', 'first_name' => 'Alpha Invest', 'f_active' => 1]);
        $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'COMPANY', 'party_code' => 'SBS', 'display_name' => 'Safe Buy Stores', 'company_name' => 'Safe Buy Stores Ltd', 'f_is_sbs' => 1, 'f_active' => 1, 'created_at' => now()]);
        $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'COMPANY', 'fk_id_account' => $this->inv, 'party_code' => 'ALP', 'display_name' => 'Alpha', 'company_name' => 'Alpha Invest Ltd', 'email' => 'alpha@demo.test', 'f_is_sbs' => 0, 'f_active' => 1, 'created_at' => now()]);
        $this->period = $this->f('t_inv_periods', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'fk_id_branch' => $shop, 'd_from' => '2026-10-01', 'd_to' => '2026-10-31', 'status' => 'CONFIRMED',
            'investor_pct' => 50, 'owner_pct' => 30, 'owner_share' => 60, 'owner_fixed' => 25, 'va_pct' => 0, 'va_salary' => 0, 'va_share' => 0, 'shipping' => 12.5, 'packing_material' => 0, 'packing_fee' => 0, 'storage_fee' => 0, 'extra_3pl' => 0,
            'handler_share' => 0, 'handler_fixed' => 0, 'fk_owner_account' => $this->ownerAcc]);
        $this->f('t_inv_period_investors', ['fk_id_period' => $this->period, 'fk_id_investor_account' => $this->inv, 'units' => 10, 'capital' => 200, 'share_amount' => 100, 'weight_pct' => 50, 'f_self' => 0]);
    }

    public function test_r83_generate_numbering_and_never_twice(): void
    {
        $this->actingAs($this->owner);
        $r = $this->postJson('/api/invoices/generate/' . $this->period)->assertOk()->json();
        $this->assertSame('3 document(s) generated.', $r['message']);
        $docs = DB::table('t_invoices')->where('fk_id_period', $this->period)->orderBy('id')->get()->keyBy('invoice_type');
        $this->assertSame('INV-ALP-101-202610-001', $docs['INVESTOR']->invoice_no, 'investor party code "ALP", shop "101", Oct 2026, first document');
        $this->assertSame(['UNPAID', 300.0, 'Shop 101 / Alpha Invest Ltd / October 2026'], [$docs['INVESTOR']->status, (float) $docs['INVESTOR']->total, $docs['INVESTOR']->reference]);
        $this->assertSame(['OS-101-202610-001', 'NOT_REQUIRED', null], [$docs['OWNER_STATEMENT']->invoice_no, $docs['OWNER_STATEMENT']->status, $docs['OWNER_STATEMENT']->d_due], 'owner statement, no due date');
        $this->assertSame(['SBS-3PL-101-202610-001', 12.5], [$docs['SBS_3PL']->invoice_no, (float) $docs['SBS_3PL']->total]);
        $this->assertSame(['Account rent (fixed) - October 2026', 'Account Owner share 30% of Final Net Profit - Shop 101, October 2026'], DB::table('t_invoice_lines')->where('fk_id_invoice', $docs['OWNER_STATEMENT']->id)->orderBy('line_no')->pluck('description')->all());
        $this->assertSame('All invoices for this month already exist.', $this->postJson('/api/invoices/generate/' . $this->period)->json('message'), 'generating again creates nothing');
        DB::table('t_inv_periods')->where('id', $this->period)->update(['status' => 'UNCONFIRMED']);
        $this->postJson('/api/invoices/generate/' . $this->period)->assertStatus(422)->assertJsonPath('message', 'Invoices can only be generated for a confirmed and settled sales month.');
    }

    public function test_r84_payments_statuses_cancel_credit_reopen(): void
    {
        $this->actingAs($this->owner);
        Docs::generate(1, $this->period, $this->owner);
        $inv = DB::table('t_invoices')->where('invoice_type', 'INVESTOR')->first();
        $pay = fn (array $v, $file = null) => $this->post('/api/invoices/' . $inv->id . '/pay', $v + ($file ? ['proof' => $file] : []), ['Accept' => 'application/json']);
        $pay(['amount' => 400, 'date' => date('Y-m-d')])->assertStatus(422)->assertJsonPath('message', 'Amount is more than the balance due (£300.00).');
        $pay(['amount' => 10, 'date' => date('Y-m-d', strtotime('+2 days'))])->assertStatus(422)->assertJsonPath('message', 'Payment date cannot be in the future.');
        $pay(['amount' => 10, 'date' => '31/02/2026'])->assertStatus(422)->assertJsonPath('message', 'Enter a valid payment date.');
        Storage::fake('local');
        $pay(['amount' => 10, 'date' => date('Y-m-d')], UploadedFile::fake()->create('slip.gif', 10))->assertStatus(422);
        $pay(['amount' => 100, 'date' => date('d-m-Y')], UploadedFile::fake()->create('slip.jpeg', 10))->assertOk()->assertJsonPath('message', 'Payment recorded.');
        $this->assertSame('PART_PAID', DB::table('t_invoices')->where('id', $inv->id)->value('status'));
        $pay(['amount' => 199.997, 'date' => date('Y-m-d')])->assertOk();
        $this->assertSame('PAID', DB::table('t_invoices')->where('id', $inv->id)->value('status'), 'paid + 0.004 ≥ total → PAID');
        $this->postJson('/api/invoices/' . $inv->id . '/cancel', ['reason' => 'wrong'])->assertStatus(422)->assertJsonPath('message', 'Payments are recorded on this invoice. Delete them first, then cancel.');
        $this->postJson('/api/invoices/reopen/' . $this->period)->assertStatus(422)->assertJsonPath('message', "Invoice {$inv->invoice_no} is still active. Cancel all invoices of this month first.");
        foreach (DB::table('t_invoice_payments')->where('fk_id_invoice', $inv->id)->pluck('id') as $p) $this->postJson("/api/invoices/payments/$p/delete")->assertOk();
        $this->assertSame('UNPAID', DB::table('t_invoices')->where('id', $inv->id)->value('status'));
        DB::table('t_invoices')->where('id', $inv->id)->update(['sent_at' => now()]);      // sent → a credit note
        $this->postJson('/api/invoices/' . $inv->id . '/cancel', ['reason' => ''])->assertStatus(422)->assertJsonPath('message', 'Enter a reason for cancelling.');
        $c = $this->postJson('/api/invoices/' . $inv->id . '/cancel', ['reason' => 'wrong month'])->assertOk()->json();
        $cn = DB::table('t_invoices')->where('id', $c['credit_note'])->first();
        $this->assertSame(['CREDITED', 'CN-101-202610-001', -300.0, 'Credit for INV-ALP-101-202610-001 - wrong month'], [DB::table('t_invoices')->where('id', $inv->id)->value('status'), $cn->invoice_no, (float) $cn->total, $cn->reference], 'sent £300 with no payment → CREDITED + CN for −£300');
        $this->postJson('/api/invoices/' . $inv->id . '/cancel', ['reason' => 'again'])->assertStatus(422)->assertJsonPath('message', 'This document is already cancelled or is a credit note.');
        $three = DB::table('t_invoices')->where('invoice_type', 'SBS_3PL')->value('id');
        $this->postJson("/api/invoices/$three/cancel", ['reason' => 'redo'])->assertOk(); $this->assertSame('CANCELLED', DB::table('t_invoices')->where('id', $three)->value('status'), 'never sent → CANCELLED');
        $this->postJson('/api/invoices/reopen/' . $this->period)->assertOk()->assertJsonPath('message', 'Settlement reopened. The sales stay confirmed; settle the month again when ready.');
        $this->assertSame(['UNCONFIRMED', 'CANCELLED'], [DB::table('t_inv_periods')->where('id', $this->period)->value('status'), DB::table('t_invoices')->where('invoice_type', 'OWNER_STATEMENT')->value('status')], 'statements auto-cancelled');
        $this->postJson('/api/invoices/reopen/' . $this->period)->assertStatus(422)->assertJsonPath('message', 'Settlement not found or already reopened.');
        $o = (object) ['status' => 'UNPAID', 'd_due' => '2020-01-01']; $this->assertSame('Overdue', Docs::badge($o), 'overdue is computed, never stored');
    }

    /** R-D-83 portal: PORTAL_CAN_GENERATE (default no); an investor creates only his own document. */
    public function test_r83_portal_generates_only_the_investors_own_invoice(): void
    {
        $u = $this->makeUser('PARTNER'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $this->inv]); $u->refresh();
        $other = $this->makeUser('PARTNER');
        $this->actingAs($u)->postJson('/api/portal/invoices/generate/' . $this->period)->assertStatus(422)->assertJsonPath('message', 'Invoices are made by the office. Ask them to generate this month.');
        $this->assertSame('N', \App\Services\Choices::LIST['PORTAL_CAN_GENERATE'][2], 'default off');
        \App\Services\Settings::set('PORTAL_CAN_GENERATE', 'Y', 1);
        $this->actingAs($other)->postJson('/api/portal/invoices/generate/' . $this->period)->assertStatus(422)->assertJsonPath('message', 'You have no share in this sales month.');
        $this->actingAs($u)->postJson('/api/portal/invoices/generate/' . $this->period)->assertOk()->assertJsonPath('message', 'Invoice generated.');
        $this->assertSame(['INVESTOR'], DB::table('t_invoices')->where('fk_id_period', $this->period)->pluck('invoice_type')->all(), 'only his own – no owner statement, no 3PL');
        $this->assertSame('INV-ALP-101-202610-001', DB::table('t_invoices')->where('fk_id_period', $this->period)->value('invoice_no'));
        $this->actingAs($u)->postJson('/api/portal/invoices/generate/' . $this->period)->assertOk()->assertJsonPath('message', 'Your invoice already exists.');
        $this->actingAs($this->owner)->postJson('/api/invoices/generate/' . $this->period)->assertOk()->assertJsonPath('message', '2 document(s) generated.');
        $this->assertSame(1, DB::table('t_invoices')->where('fk_id_period', $this->period)->where('invoice_type', 'INVESTOR')->count(), 'the office run skips the existing one');
    }
    /** R-D-84 / R-G_CORE-86 portal: own documents only; "Record payment" and "E-mail invoice" yes, "Generate" no by default. */
    public function test_r84_portal_sees_own_documents_records_payment_and_emails(): void
    {
        $u = $this->makeUser('PARTNER'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $this->inv]); $u->refresh();
        $this->actingAs($u)->getJson('/api/portal/invoices')->assertStatus(422)->assertJsonPath('message', 'Your login does not have investor portal access. Please contact the administrator.');
        $this->actingAs($this->owner)->postJson('/api/invoices/generate/' . $this->period)->assertOk();
        DB::table('t_inv_parties')->where('fk_id_account', $this->inv)->update(['f_portal_access' => 1, 'email' => 'investor@example.test']);
        DB::table('t_inv_parties')->whereNotIn('fk_id_account', [$this->inv])->orWhereNull('fk_id_account')->update(['email' => 'owner@example.test']);
        $l = $this->actingAs($u)->getJson('/api/portal/invoices')->assertOk()->json();
        $this->assertSame([1, true, true, false], [count($l['docs']), $l['docs'][0]['can_pay'], $l['docs'][0]['can_send'], $l['can_generate']], 'only his INVESTOR document');
        $id = $l['docs'][0]['id']; $others = DB::table('t_invoices')->where('id', '<>', $id)->value('id');
        if ($others) $this->actingAs($u)->postJson("/api/portal/invoices/$others/email")->assertStatus(422)->assertJsonPath('message', 'Invoice not found.');
        $pr = $this->actingAs($u)->postJson("/api/portal/invoices/$id/pay", ['amount' => 1, 'date' => date('Y-m-d'), 'method' => 'BANK']); $this->assertSame(200, $pr->status(), (string) $pr->json('message'));
        $this->assertSame(1.0, (float) DB::table('t_invoices')->where('id', $id)->value('amount_paid'));
        $this->actingAs($u)->postJson("/api/portal/invoices/$id/email")->assertOk()->assertJsonPath('message', 'Invoice e-mailed to the Account Owner.');
        \App\Services\Settings::set('PORTAL_CAN_RECORD_PAYMENT', 'N', 1); \App\Services\Settings::set('PORTAL_CAN_SEND_INVOICE', 'N', 1);
        $this->actingAs($u)->postJson("/api/portal/invoices/$id/pay", ['amount' => 1, 'date' => date('Y-m-d')])->assertStatus(422)->assertJsonPath('message', 'Recording payments is not allowed.');
        $this->actingAs($u)->postJson("/api/portal/invoices/$id/email")->assertStatus(422)->assertJsonPath('message', 'Sending is not allowed.');
    }
}
