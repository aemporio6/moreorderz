<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\FinanceArea;
use App\Services\Mask;
use App\Services\OpeningBalances;
use App\Services\Perm;
use App\Services\RecordService;
use App\Services\TaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Batch 7e-220 Finance: taxation, finance roles and partner lock-down, opening wizard, money mask, partner messages, profit spreads, bank & cash, home, provider profile, invoicing. */
class FinanceAreaTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function as(object $u): void { $this->actingAs($u); Context::set(1, $u->id); Perm::flush(); }
    private function err(callable $fn): array { try { $fn(); } catch (ValidationException $e) { return $e->errors(); } $this->fail('saved without an error'); }
    private function person(string $n, string $kind = 'M'): int { return $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => substr(uniqid(), -8), 'first_name' => $n, 'sur_name' => 'T', 'l_account_kind' => $kind, 'f_active' => 1]); }
    private function bank(int $ent = 1, string $label = 'Main'): int { return $this->f('fin_bank_accounts', ['company_id' => $ent, 'bank_name' => 'Bank', 'account_label' => $label . uniqid(), 'currency' => 'GBP', 'is_active' => 1, 'opening_balance' => 0, 'account_kind' => 'BANK']); }
    private function line(int $bank, float $amt, string $date, array $x = []): int { return $this->f('fin_bank_transactions', $x + ['company_id' => 1, 'bank_account_id' => $bank, 'txn_date' => $date, 'amount' => $amt, 'currency' => 'GBP', 'amount_gbp' => $amt, 'description' => 'line', 'match_status' => 'UNMATCHED', 'bank_txn_id' => uniqid('t', true)]); }

    public function test_tax_golden_ct_worksheet_size_and_setup_rules(): void
    {
        $this->as($this->owner);
        // R-TAX-2 golden figures with the seeded rate table
        $ct = fn ($x, $s, $e, $a = 0) => TaxService::ct($x, $s, $e, $a)['ct'];
        $this->assertSame([22750.0, 24625.0, 7600.0, 75000.0], [$ct(100000, '2024-04-01', '2025-03-31'), $ct(100000, '2024-04-01', '2025-03-31', 1), $ct(40000, '2024-04-01', '2025-03-31'), $ct(300000, '2024-04-01', '2025-03-31')]);
        $y = TaxService::ct(100000, '2024-01-01', '2024-12-31');
        $this->assertSame([5653.87, 17085.86, 22739.73], [$y['periods'][0]['segments'][0]['tax'], $y['periods'][0]['segments'][1]['tax'], $y['ct']]);
        $z = TaxService::ct(300000, '2024-01-01', '2025-06-30');
        $this->assertSame([[366, 200731.26, 49433.51], [181, 99268.74, 24446.63], 73880.14], [[$z['periods'][0]['days'], $z['periods'][0]['profit'], $z['periods'][0]['tax']], [$z['periods'][1]['days'], $z['periods'][1]['profit'], $z['periods'][1]['tax']], $z['ct']]);
        // R-TAX-4 / R-TAX-6 setup rules and helpers
        $co = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Tax Co ' . uniqid(), 'f_external' => 0, 'f_active' => 1]);
        $this->assertSame('A period of accounts cannot exceed 18 months.', $this->err(fn () => RecordService::save('tax_period', ['fk_company_house' => $co, 'period_start' => '2024-01-01', 'period_end' => '2025-07-31']))['period_end'][0]);
        $p = (int) RecordService::save('tax_period', ['fk_company_house' => $co, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'accounts_type' => 'MICRO', 'avg_employees' => 8])['id'];
        $this->assertSame('Period ending 31 Dec 2025', DB::table('tax_periods')->where('id', $p)->value('period_label'));
        $this->assertSame(['2026-01-01', '2026-12-31', 0], array_values(array_intersect_key(TaxService::nextPeriod($co), array_flip(['period_start', 'period_end', 'f_first_period']))));
        $this->assertArrayHasKey('fy_start_year', $this->err(fn () => RecordService::save('tax_rate', ['fy_start_year' => 2025, 'small_rate' => 19, 'main_rate' => 25])));
        // R-TAX-1 worksheet: turnover 120,000, COS 70,000, expenses 30,000 (2,000 DISALLOW) → net 20,000, taxable 22,000
        $li = fn ($c) => (int) DB::table('tax_line_items')->where('code', $c)->value('id');
        foreach ([['TO01', 120000], ['CS01', 70000], ['GAE01', 28000], ['GAE02', 2000], ['CA03', 50000], ['CAP02', 50000]] as [$c, $a]) RecordService::save('tax_entry', ['fk_period' => $p, 'fk_line_item' => $li($c), 'amount' => $a]);
        $x = TaxService::period($p);
        $this->assertSame([20000.0, 22000.0, true], [$x['worksheet']['pl']['net'], $x['worksheet']['tax']['taxable'], $x['worksheet']['bs']['balanced']]);
        $this->assertSame('2025-12-31', DB::table('tax_entries')->where('fk_period', $p)->value('entry_date'), 'entry date = period end');
        // R-TAX-3 size test: turnover 900,000, BS 600,000, 8 staff → MICRO (2 of 3)
        $w = $x['worksheet']; $w['pl']['turnover'] = 900000.0; $w['bs']['fixed_assets'] = 600000.0; $w['bs']['current_assets'] = 0.0; $w['bs']['prepayments'] = 0.0;
        $this->assertSame('MICRO', TaxService::size(DB::table('tax_periods')->where('id', $p)->first(), $w)['size']);
        $this->assertSame(1, count(TaxService::dashboard(1)['rows']));
    }

    public function test_fin1_fin2_roles_partner_lockdown_and_landing(): void
    {
        $fin = $this->makeUser('FINANCE'); $acct = $this->makeUser('ACCOUNTANT');
        $this->as($fin); $this->postJson('/api/close/lock', ['shop' => 1, 'month' => '2026-01'])->assertStatus(403);
        $this->assertTrue(Perm::has($fin, 'fin.reconcile')); $this->assertFalse(Perm::has($fin, 'fin.close_period'));
        $this->as($acct); $this->getJson('/api/ledger')->assertOk(); $this->assertFalse(Perm::has($acct, 'fin.reconcile'));
        $this->assertSame('#/ledger', $this->getJson('/api/boot')->json('landing'));
        // a partner account login sees only its own pages
        $partner = $this->makeUser('PARTNER'); $inv = $this->person('Partner P');
        DB::table('fin_partner_users')->insert(['user_id' => $partner->id, 'investor_id' => $inv, 'created_at' => now()]);
        $this->as($partner);
        $this->assertTrue($this->getJson('/api/boot')->assertOk()->json('partner_only'));
        $this->getJson('/api/sales')->assertStatus(403)->assertJsonPath('message', 'Partner Account: this page is not available.');
        $this->assertNotSame(403, $this->getJson('/api/my-account')->status());
    }

    public function test_fin20_opening_rows_and_fin5_payout_bank(): void
    {
        $this->as($this->owner);
        try { OpeningBalances::addRow(1, ['type' => 'OPENING_LOT', 'as_of_date' => '2026-01-01', 'sku' => 'X', 'qty' => 0, 'unit_cost' => 1], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('An OPENING_LOT needs sku, qty (>0) and unit_cost (>0).', $e->getMessage()); }
        $inv = $this->person('Capital P');
        OpeningBalances::addRow(1, ['type' => 'PARTNER_CAPITAL', 'as_of_date' => '2026-01-01', 'ref_id' => $inv, 'amount' => 5000], $this->owner);
        $this->assertSame(5000.0, (float) DB::table('fin_investor_ledger')->where('investor_id', $inv)->where('reference_no', 'OPENING')->sum('amount'));
        $this->assertSame('2026-01-01', OpeningBalances::asOf(1));
        OpeningBalances::lock(1, $this->owner);
        try { OpeningBalances::addRow(1, ['type' => 'STOCK_VALUE', 'as_of_date' => '2026-01-01', 'amount' => 1], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('LOCKED', $e->getMessage()); }
        // R-FIN-5: a shop of company 1 cannot take a bank account of company 2
        $e2 = (int) (DB::table('t_enterprise')->where('id', '<>', 1)->value('id') ?: $this->f('t_enterprise', ['ent_name' => 'Two Ltd', 'ent_short' => 'TWO', 'f_active' => 1]));
        $other = $this->bank($e2); $mine = $this->bank(1);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'M' . substr(uniqid(), -4), 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'P' . substr(uniqid(), -5), 'iso_branch' => 'P1', 'shop_name' => 'Payout shop', 'f_active' => 1]);
        $this->assertSame("Choose a bank account of this shop's company.", $this->err(fn () => RecordService::save('t_branch', ['fk_id_payout_bank' => $other], $shop))['fk_id_payout_bank'][0]);
        RecordService::save('t_branch', ['fk_id_payout_bank' => $mine], $shop);
        $this->assertSame($mine, (int) DB::table('t_branches')->where('id', $shop)->value('fk_id_payout_bank'));
    }

    public function test_fin10_fin11_fin15_messages_mask_and_po_sold_out(): void
    {
        $this->as($this->owner);
        $p = $this->person('Msg P'); DB::table('t_account')->where('id', $p)->update(['whatsapp_number' => '+447700900111', 'wa_enabled' => 0]);
        $this->assertSame([], FinanceArea::partnerMessage(1, $p, 'WITHDRAWAL_APPROVED', 'ok', 'k1'), 'WhatsApp off → no optional message');
        $this->assertSame(['WHATSAPP'], FinanceArea::partnerMessage(1, $p, 'STATEMENT_READY', 'ready', 'k2'), 'STATEMENT_READY is always sent');
        // R-FIN-11 money keys removed, history keeps "(hidden)"
        $x = Mask::stripMoney(['id' => 1, 'amount_gbp' => 5, 'rows' => [['amount' => 2, 'x' => 1]], 'history' => [['changes' => ['amount_gbp' => [1, 2]]]]]);
        $this->assertSame(['id' => 1, 'rows' => [['x' => 1]], 'history' => [['changes' => ['amount_gbp' => [null, '(hidden)']]]]], $x);
        $va = $this->makeUser('VA'); $this->as($va);
        $this->assertArrayNotHasKey('amount', $this->getJson('/api/home')->json() ?? []);
        $this->as($this->owner);
        // R-FIN-15 the last unit of a PO's lots ends its PO agreement the same day
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'S15', 'f_active' => 1]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'fk_id_supplier' => $sup, 'po_number' => 'PO15-' . uniqid(), 'f_active' => 1]);
        $lot = $this->f('fin_lots', ['company_id' => 1, 'sku' => 'S15', 'po_id' => $po, 'source' => 'GRN', 'received_date' => date('Y-m-d'), 'qty' => 10, 'qty_left' => 0, 'unit_cost_gbp' => 1, 'created_at' => now()]);
        $ag = $this->f('fin_investor_agreements', ['investor_id' => $p, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'PO', 'po_id' => $po, 'effective_from' => '2026-01-01', 'profit_share_pct' => 10, 'status' => 'ACTIVE', 'created_at' => now()]);
        \App\Services\EventBus::process(\App\Services\EventBus::fire('STOCK_CONSUMED', 'fin_lots', $lot, ['left' => 0], 1));      // listeners run after the commit; the test runs in one transaction
        $this->assertSame(['ENDED', 'PO sold out', date('Y-m-d')], array_values((array) DB::table('fin_investor_agreements')->where('id', $ag)->first(['status', 'ended_reason', 'effective_to'])));
    }

    public function test_fin44_fin64_fin65_spreads_bank_cash_home(): void
    {
        $this->as($this->owner);
        $mk = fn ($sku, $amt) => $this->f('t_sales', ['fk_id_ent' => 1, 'order_no' => 'O' . uniqid(), 'd_order' => '2026-03-10', 'sku' => $sku, 'qty' => 1, 'total_amount' => $amt, 'total_amount_gbp' => $amt, 'status' => 'Confirmed', 'sale_source' => 'active', 'f_locked' => 0, 'created_at' => now()]);
        $mk('A44', 6000); $mk('B44', 4000);
        $r = collect(FinanceArea::productPeriod(1, '2026-03-01', '2026-03-31', ['fees' => -1000, 'other' => 0])['rows'])->keyBy('sku');
        $this->assertSame(-600.0, $r['A44']['fees'], 'SKU sales 6,000 of 10,000 → 60 % of an unassigned fee −1,000');
        // R-FIN-64 budget FREIGHT 1,000, actual −1,250 → difference −250 (red)
        $b = $this->bank(); $this->line($b, -1250, '2026-03-05', ['category_code' => 'FREIGHT']);
        DB::table('fin_budgets')->insert(['company_id' => 1, 'year' => 2026, 'month' => 3, 'category_code' => 'FREIGHT', 'amount' => 1000]);
        $c = collect(FinanceArea::bankCash(1, '2026-03')['categories'])->firstWhere('category', 'FREIGHT');
        $this->assertSame([-250.0, true], [$c['difference'], $c['over']]);
        // R-FIN-65 the as-of date uses bank lines up to it
        $this->line($b, 500, '2026-10-01');
        $this->assertEquals(-1250.0, $this->getJson('/api/money/home?as_of=2026-09-30')->assertOk()->json('bank_as_of'));
        $this->assertSame(-750.0, FinanceArea::home(1, '2026-10-31')['bank_as_of']);
        // MR-0080 decisions: old unmatched lines with their £ and the action
        $this->assertSame('#/bank', collect(FinanceArea::decisions(1)['rows'])->firstWhere('kind', 'UNMATCHED')['action']);
        // MR-0081 scenario: a PO of 2,000 next week lowers the bank by 2,000 from that week
        $s = FinanceArea::scenario(1, 'PO plan', [['what' => 'PO', 'amount' => 2000, 'date' => date('Y-m-d', strtotime('+8 days')), 'dir' => 'out']], true);
        $this->assertSame(-2000.0, end($s['rows'])['change']); $this->assertTrue(DB::table('fin_scenarios')->where('id', $s['id'])->exists());
    }

    public function test_fin72_provider_profile_fin73_recode_and_line_profit(): void
    {
        $this->as($this->owner);
        $bankAcc = $this->bank(1, 'Bank');
        $prof = $this->f('payment_provider_profiles', ['fk_id_ent' => 1, 'code' => 'WAL1', 'name' => 'Wallet one', 'provider_kind' => 'WALLET', 'fk_id_payout_bank_account' => $bankAcc, 'f_active' => 1]);
        $cat = (int) DB::table('fin_categories')->where('code', 'BANK_FEES')->value('id') ?: $this->f('fin_categories', ['code' => 'BANK_FEES', 'name' => 'Bank fees', 'direction' => 'OUT', 'f_active' => 1]);
        $this->f('payment_provider_fee_buckets', ['fk_id_provider_profile' => $prof, 'bucket_code' => 'FX', 'label' => 'FX fee', 'source_rule' => 'conversion fee', 'charge_kind' => (string) $cat, 'sign_rule' => 'NEGATIVE_ONLY', 'f_active' => 1]);
        $wal = $this->bank(1, 'Wallet'); DB::table('fin_bank_accounts')->where('id', $wal)->update(['fk_id_provider_profile' => $prof]);
        $fee = $this->line($wal, -3.5, date('Y-m-d'), ['description' => 'Currency conversion fee']);
        $out = $this->line($wal, -400, date('Y-m-d'), ['description' => 'Withdrawal to bank']); $in = $this->line($bankAcc, 400, date('Y-m-d'), ['description' => 'From wallet']);
        foreach ([$fee, $out] as $id) \App\Services\BankMatch::providerProfile(1, DB::table('fin_bank_transactions')->where('id', $id)->first());
        $this->assertSame('BANK_FEES', DB::table('fin_bank_transactions')->where('id', $fee)->value('category_code'));
        $this->assertSame('OWN_TRANSFER_OUT', DB::table('fin_bank_transactions')->where('id', $out)->value('category_code'));
        $this->assertNotSame('UNMATCHED', DB::table('fin_bank_transactions')->where('id', $in)->value('match_status'), 'the bank deposit is the other half of the own transfer');
        // R-FIN-73 old codes re-coded once, sums equal
        $this->line($bankAcc, 100, '2026-02-01', ['category_code' => 'PARTNER_CAPITAL']);
        $r = FinanceArea::recode(1); $this->assertTrue($r['equal']); $this->assertSame(0, DB::table('fin_bank_transactions')->where('category_code', 'PARTNER_CAPITAL')->count());
        // MR-0079 line profit provisional → final
        $s = $this->f('t_sales', ['fk_id_ent' => 1, 'order_no' => 'LP' . uniqid(), 'd_order' => date('Y-m-d'), 'sku' => 'LP1', 'qty' => 1, 'total_amount' => 50, 'total_amount_gbp' => 50, 'cost_gbp' => 20, 'carrier_rate' => 5, 'status' => 'Confirmed', 'sale_source' => 'active', 'f_locked' => 0, 'created_at' => now()]);
        $p = FinanceArea::lineProfit($s); $this->assertSame(['PROVISIONAL', 25.0, ['fees']], [$p['state'], $p['profit_gbp'], $p['missing']]);
        DB::table('t_sales')->where('id', $s)->update(['f_locked' => 1]); $this->assertSame('FINAL', FinanceArea::lineProfit($s)['state']);
        DB::table('t_sales')->where('id', $s)->update(['carrier_rate' => 9]); $this->assertSame(25.0, (float) FinanceArea::lineProfit($s)['profit_gbp'], 'a final profit is frozen');
    }

    public function test_inv13_inv14_inv16_rd95_rc28_rc32_audit_and_key_rotation(): void
    {
        $this->as($this->owner);
        // R-INV-14 blanks filled from the account: e-mail a@b.com prints; no bank → INV_PARTY_INCOMPLETE task
        $acc = $this->person('Party'); $this->f('t_account_emails', ['fk_id_account' => $acc, 'email' => 'a@b.com', 'f_active' => 1]);
        $party = $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'ACCOUNT', 'fk_id_account' => $acc, 'party_code' => 'P' . substr(uniqid(), -5), 'display_name' => 'Party', 'f_active' => 1, 'created_at' => now()]);
        $d = \App\Services\InvestorDocs::printDetails(DB::table('t_inv_parties')->where('id', $party)->first());
        $this->assertSame(['a@b.com', 'Party', ['bank']], [$d->email, $d->bank_account_name, $d->missing]);
        \App\Services\InvestorDocs::partyCheck(1, DB::table('t_inv_parties')->where('id', $party)->first());
        $this->assertTrue(DB::table('tasks')->where('rule_code', 'INV_PARTY_INCOMPLETE')->exists());
        // R-INV-13 an unconfirmed month has no Settle button
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'I' . substr(uniqid(), -4), 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'I' . substr(uniqid(), -5), 'iso_branch' => 'I1', 'shop_name' => 'Inv shop', 'f_active' => 1]);
        $this->f('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'period_month' => 3, 'period_year' => 2026, 'status' => 'Pending', 'imported_at' => now()]);
        $m = collect(FinanceArea::monthStatus(1, $shop, '2026-03-01', '2026-03-31')['rows'])->firstWhere('month', '2026-03');
        $this->assertSame(['Pending', false], [$m['import'], $m['can_settle']]);
        // R-INV-16 the partner's stock CSV has no landing price
        $u = $this->makeUser('PARTNER'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $acc]); DB::table('t_inv_parties')->where('id', $party)->update(['f_portal_access' => 1]);
        $this->as(\App\Models\User::find($u->id));
        $cols = \App\Services\InvestorPortal::export(1, \App\Models\User::find($u->id), 'stock')['columns'];
        $this->assertNotContains('My landing price', $cols); $this->assertContains('Raw price', $cols);
        // R-D-95 a VA asking the investor-settings bank list → 403 + RIGHTS_DENIED
        $va = $this->makeUser('VA'); $this->as($va); DB::table('mo_user_perms')->insert(['user_id' => $va->id, 'perm_code' => 'form:t_inv_settings:VIEW', 'effect' => 'GRANTED', 'reason' => 't', 'created_at' => now()]); Perm::flush();
        $this->getJson('/api/options/t_inv_settings/fk_id_account_bank')->assertStatus(403);
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'RIGHTS_DENIED')->exists());
        $this->as($this->owner);
        // R-C-28 currency kept on create; R-C-32 PayPal sort code from debit card 1
        $eur = (int) DB::table('cat_currency')->where('currency_code', 'EUR')->value('id');
        if ($eur) { $b = (int) RecordService::save('t_account_bank', ['fk_id_account' => $acc, 'account_no' => '12345678', 'sort_branch_code' => '11-22-33', 'fk_currency_id' => $eur, 'f_active' => 1])['id']; $this->assertSame($eur, (int) DB::table('t_account_banks')->where('id', $b)->value('fk_currency_id')); }
        $bk = $this->f('cat_banks', ['bank_name' => 'PP Bank ' . uniqid(), 'f_active' => 1]);
        $card = $this->f('t_account_credit_cards', ['fk_id_account' => $acc, 'fk_bank_id' => $bk, 'card_type' => 'Debit', 'sort_branch_code' => '40-11-22', 'f_active' => 1]);
        $pp = (int) RecordService::save('t_account_paypal', ['fk_id_account' => $acc, 'fk_bank_id' => $bk, 'linked_debit_card_id1' => $card, 'f_active' => 1])['id'];
        $this->assertSame('40-11-22', DB::table('t_account_paypal')->where('id', $pp)->value('sort_branch_code'));
        // MR-1271 the account audit
        $this->assertSame('Party T', FinanceArea::accountAudit(1, $acc)['name']);
        // R-G_CORE-89 key rotation: values made with the old key read again with the new one
        $old = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC');
        $v = $this->f('sys_vault', ['fk_id_ent' => 1, 'table_name' => 't', 'record_id' => 1, 'column_name' => 'c', 'cipher' => $old->encryptString('secret-123')]);
        $r = \App\Console\Commands\MoRotateKey::rotate($old, true);
        $this->assertSame('secret-123', \Illuminate\Support\Facades\Crypt::decryptString(DB::table('sys_vault')->where('id', $v)->value('cipher')));
        $this->assertSame(0, $r['tables']['sys_vault.cipher']['failed']);
    }
}
