<?php

namespace Tests\Feature;

use App\Services\BankMatch;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\LotOwners;
use App\Services\MoneyService;
use App\Services\RecordService;
use App\Services\Registry;
use App\Services\RoleMaster;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-61 lot owner at GRN, R-FIN-70 M6 invoice / sum matching / provider fees, R-C-43 one roles master – the spec tests. */
class WeightBatch153Test extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function person(string $n): int { return $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'W' . random_int(10000, 99999), 'first_name' => $n, 'f_active' => 1]); }

    private function grn(int $qty): array
    {
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Dock ' . uniqid(), 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $r = $this->f('src_research', ['fk_id_ent' => 1, 'research_code' => 'RES-LO-' . uniqid(), 'title' => 'Owner mug', 'status' => 'SHORTLIST', 'target_sell_price' => 20, 'est_unit_cost' => 4, 'currency' => 'GBP', 'created_by' => $this->owner->id, 'created_at' => now()]);
        DB::table('src_research_suppliers')->insert(['fk_id_research' => $r, 'supplier_name_text' => 'Owner Supplier ' . uniqid(), 'unit_price' => 5.00, 'currency' => 'USD', 'moq' => 10, 'lead_days' => 20]);
        $go = Buy::go($r); Buy::acceptRfq($go['rfqs'][0]);
        $po = Buy::createPo(['rfq' => $go['rfqs'][0], 'qty' => $qty, 'date' => '2026-08-01']); Buy::confirmPo($po);
        $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-08-18']);
        return [$go['product'], $po, Buy::createGrn(['shipment' => $ship, 'location' => $wh, 'date' => '2026-08-20']), $wh];
    }

    private function agreement(int $inv, int $po, float $pct, float $cap, string $status = 'ACTIVE'): int
    {
        return $this->f('fin_investor_agreements', ['investor_id' => $inv, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'PO', 'po_id' => $po, 'effective_from' => '2026-01-01', 'capital_amount' => $cap, 'profit_share_pct' => $pct,
            'management_fee_pct' => 0, 'payout_schedule' => 'PER_PERIOD', 'status' => $status, 'created_by' => $this->owner->id, 'created_at' => now(), 'stacking_rule' => 'SPLIT_UNITS']);
    }

    public function test_b61_lots_split_by_active_po_agreements(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', '2026-08-01', 0.80);
        $this->assertSame([[1, 597, 10], [2, 398, 11]], LotOwners::split(995, [[1, 60, 10], [2, 40, 11]]), '995 at 60 / 40 → 597 + 398');
        $this->assertSame([[1, 34, 10], [2, 33, 11], [3, 33, 12]], LotOwners::split(100, [[1, 1, 10], [2, 1, 11], [3, 1, 12]]), 'remainder to the largest (first) share');
        [$prod, $po, $g, $wh] = $this->grn(1000);
        DB::table('t_src_grn_lines')->where('fk_id_grn', $g)->update(['received_qty' => 995, 'accepted_qty' => 995, 'damaged_qty' => 0, 'missing_qty' => 5]);
        $a = $this->person('Alia'); $b = $this->person('Bilal'); $c = $this->person('Chand');
        $this->agreement($b, $po, 40, 4000); $this->agreement($a, $po, 60, 6000); $this->agreement($c, $po, 50, 5000, 'DRAFT');
        Buy::postGrn($g);
        $lots = DB::table('fin_lots')->where('po_id', $po)->orderBy('id')->get(['id', 'fk_id_owner', 'qty']);
        $this->assertSame([[$a, 597], [$b, 398]], $lots->map(fn ($l) => [(int) $l->fk_id_owner, (int) $l->qty])->all(), 'lots A 597, B 398; A created first (lower lot id); DRAFT C gets none');
        $loc = (int) DB::table('fin_lot_balances')->where('fk_id_lot', $lots[0]->id)->value('fk_id_location');
        Stock::consumeForSale(1, 0, $prod, 600, $loc, null, ['date' => '2026-09-05']);
        $left = DB::table('fin_lots')->where('po_id', $po)->orderBy('id')->pluck('qty_left')->map(fn ($x) => (int) $x)->all();
        $this->assertSame([0, 395], $left, 'a sale of 600 takes A 597 then B 3');
        [, $po2, $g2] = $this->grn(50); Buy::postGrn($g2);
        $owner2 = DB::table('t_src_cycles')->where('id', DB::table('t_src_po')->where('id', $po2)->value('fk_id_cycle'))->value('fk_id_owner');
        $this->assertSame([(int) $owner2 ?: null], DB::table('fin_lots')->where('po_id', $po2)->pluck('fk_id_owner')->map(fn ($x) => $x === null ? null : (int) $x)->all(), 'no agreement → one lot to the cycle owner');
    }

    private function bank(string $kind = 'BANK'): int { return MoneyService::createBank(1, ['bank_name' => 'Test Bank', 'account_label' => 'Main ' . uniqid(), 'currency' => 'GBP', 'account_kind' => $kind]); }
    private function line(int $bank, float $amt, string $desc, string $date = '2026-10-10'): object
    {
        $id = DB::table('fin_bank_transactions')->insertGetId(RecordService::fillNotNull('fin_bank_transactions', ['batch_id' => 0, 'bank_txn_id' => uniqid('T'), 'bank_account_id' => $bank, 'company_id' => 1, 'txn_date' => $date, 'amount' => $amt, 'currency' => 'GBP', 'amount_gbp' => $amt, 'description' => $desc, 'match_status' => 'UNMATCHED']));
        return DB::table('fin_bank_transactions')->where('id', $id)->first();
    }

    public function test_fin70_invoice_sum_and_provider(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $bank = $this->bank();
        $us = $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'COMPANY', 'party_code' => 'SBS', 'display_name' => 'Our Co', 'company_name' => 'Our Co', 'payment_terms_days' => 0, 'f_is_sbs' => 1, 'f_portal_access' => 0, 'f_active' => 1, 'created_at' => now()]);
        $them = $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'ACCOUNT', 'party_code' => 'INV1', 'display_name' => 'Investor', 'company_name' => 'Investor', 'payment_terms_days' => 0, 'f_is_sbs' => 0, 'f_portal_access' => 0, 'f_active' => 1, 'created_at' => now()]);
        $inv = $this->f('t_invoices', ['fk_id_ent' => 1, 'fk_id_period' => 0, 'invoice_no' => 'INV-2026-0042', 'invoice_type' => 'INVESTOR', 'fk_issuer_party' => $us, 'fk_recipient_party' => $them, 'fk_id_investor_account' => 0, 'd_invoice' => '2026-10-01', 'd_due' => '2026-10-31', 'currency' => 'GBP', 'subtotal' => 250, 'total' => 250, 'amount_paid' => 0, 'status' => 'UNPAID', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $this->line($bank, 250.00, 'FASTER PAYMENT REF INV-2026-0042'); $this->line($bank, 250.00, 'NO REFERENCE');
        // sum matching: two open expenses of one payee = one bank line
        $payee = $this->person('Courier'); $other = $this->person('Printer');
        $e = fn ($p, $a, $d) => $this->f('fin_expenses', ['fk_id_ent' => 1, 'd_expense' => $d, 'category_code' => 'POSTAGE', 'fk_id_payee' => $p, 'amount' => $a, 'amount_gbp' => $a, 'fk_id_paid_from' => $bank, 'source' => 'MANUAL', 'f_active' => 1, 'created_at' => now()]);
        $e1 = $e($payee, 120.00, '2026-10-05'); $e2 = $e($payee, 80.50, '2026-10-07'); $e3 = $e($other, 99.00, '2026-10-06');
        $this->line($bank, -200.50, 'COURIER LTD');
        $n = MoneyService::autoMatch(1, $bank);
        $m = DB::table('fin_bank_transactions')->where('bank_account_id', $bank)->orderBy('id')->get();
        $this->assertSame(['M6', 'INVOICE', (string) $inv, 'INVOICE_RECEIPT'], [$m[0]->match_rule, $m[0]->matched_to_type, $m[0]->matched_to_id, $m[0]->category_code], 'M6: balance and invoice number → matched');
        $this->assertSame(['PAID', 'UNMATCHED'], [DB::table('t_invoices')->where('id', $inv)->value('status'), $m[1]->match_status], 'the invoice is paid; the line without the number stays');
        $this->assertSame(['M3S', "$e1,$e2"], [$m[2]->match_rule, $m[2]->matched_to_id], 'one payee, two expenses = the line');
        $this->assertSame(2, $n);
        MoneyService::unmatch(1, (int) $m[0]->id); MoneyService::unmatch(1, (int) $m[2]->id);
        $this->assertSame(['UNPAID', 0], [DB::table('t_invoices')->where('id', $inv)->value('status'), DB::table('fin_expenses')->whereIn('id', [$e1, $e2])->whereNotNull('fk_id_bank_line')->count()], 'undo puts both back');
        // by hand: several ticked items must add up and share one payee
        $l4 = $this->line($bank, -219.00, 'MIXED');
        $this->postJson("/api/bank-lines/{$l4->id}/many", ['ids' => [$e1, $e3]])->assertStatus(422)->assertJsonPath('message', 'All ticked items must be for the same payee.');
        $this->postJson("/api/bank-lines/{$l4->id}/many", ['ids' => [$e1, $e2]])->assertStatus(422)->assertJsonPath('message', 'The ticked items add up to 200.50, the bank line is 219.00.');
        $this->assertCount(3, $this->getJson("/api/bank-lines/{$l4->id}/payables")->assertOk()->json('rows'));
        $this->assertCount(3, BankMatch::subsets([1 => 5.0, 2 => 5.0, 3 => 5.0], 10.0), 'several sets → not auto-matched');
        // provider profile: fee line booked as bank fees
        $pp = $this->bank('PROVIDER'); $this->line($pp, -1.20, 'PayPal fee', '2026-10-11'); $this->line($pp, -1.20, 'Card payment', '2026-10-11');
        if (! DB::table('fin_categories')->where('code', 'BANK_FEES')->exists()) DB::table('fin_categories')->insert(RecordService::fillNotNull('fin_categories', ['code' => 'BANK_FEES', 'name' => 'Bank fees', 'direction' => 'OUT', 'profit_role' => 'FIXED', 'f_active' => 1]));
        MoneyService::autoMatch(1, $pp);
        $this->assertSame([['MPF', 'BANK_FEES'], [null, null]], DB::table('fin_bank_transactions')->where('bank_account_id', $pp)->orderBy('id')->get()->map(fn ($x) => [$x->match_rule, $x->category_code])->all());
    }

    public function test_c43_roles_master(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $sid = (int) RecordService::save('cat_account_type', ['fk_id_ent' => 1, 'account_type_name' => '  seller ', 'iso_account_type' => 'slr', 'role_code' => 'seller', 'f_active' => 1])['id'];
        $r = DB::table('cat_account_types')->where('id', $sid)->first();
        $this->assertSame(['Seller', 'SELLER'], [$r->account_type_name, $r->role_code], 'trim + first letter upper; code upper');
        try { RecordService::save('cat_account_type', ['fk_id_ent' => 1, 'account_type_name' => 'SELLER', 'role_code' => 'X1', 'f_active' => 1]); $this->fail('duplicate name saved'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('already exists', json_encode($e->errors())); }
        $p = $this->person('Sami'); DB::table('t_account_in_types')->insert(['fk_id_account' => $p, 'fk_id_account_type' => $sid, 'f_active' => 1]);
        try { RecordService::save('cat_account_type', ['role_code' => 'VA_SELLER'], $sid); $this->fail('code changed while used'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('locked', json_encode($e->errors())); }
        RecordService::save('cat_account_type', ['account_type_name' => 'VA / Seller'], $sid);
        $ids = array_map(fn ($o) => (int) $o[0], Registry::options(['source' => 'party:SELLER', 'type' => 'select'], 1, 'Sami'));
        $this->assertContains($p, $ids, 'rename "Seller" to "VA / Seller" → the SELLER people list still works (it reads the code)');
        $this->assertSame('VA / Seller', DB::table('cat_account_types')->where('id', $sid)->value('account_type_name'));
        $this->assertSame('OWNER', RoleMaster::code('owner'));
    }
}
