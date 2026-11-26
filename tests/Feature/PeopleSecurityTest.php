<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\Context;
use App\Services\Perm;
use App\Services\PersonLinks;
use App\Services\RecordService;
use App\Services\RegistryLint;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Batch 7e-219 Accounts / People and Security: personal data, approval limits, maker-checker, the person rules, the kit, sign-in code. */
class PeopleSecurityTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function person(string $first, string $kind = 'M'): int { return $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => substr(uniqid(), -8), 'first_name' => $first, 'sur_name' => 'Test', 'l_account_kind' => $kind, 'l_party_type' => 'P', 'f_active' => 1]); }
    private function as(object $u): void { $this->actingAs($u); Context::set(1, $u->id); Perm::flush(); }
    private function err(callable $fn): array { try { $fn(); } catch (ValidationException $e) { return $e->errors(); } $this->fail('saved without an error'); }

    public function test_d7_personal_data_owner_and_hr_right_only_on_screen_and_on_save(): void
    {
        $p = $this->person('Staff');
        $this->as($this->owner); \App\Services\Choices::set('SECURE_FORMS', 'tax_period', 1);      // the phone-app step of secure pages is tested on its own
        $job = (int) RecordService::save('t_account_job_history', ['fk_id_account' => $p, 'fk_id_marketplace' => (int) DB::table('cat_marketplaces')->value('id') ?: $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'MPX', 'f_active' => 1]), 'd_job_start_date' => '2026-01-01', 'salary' => 900, 'f_active' => 1])['id'];
        $bank = $this->f('t_account_banks', ['fk_id_account' => $p, 'account_no' => '12345678', 'sort_branch_code' => '11-22-33', 'f_active' => 1]);
        $this->assertSame(900.0, (float) $this->getJson("/api/record/t_account_job_history/$job")->json('values.salary'));
        $mgr = $this->makeUser('MANAGER');
        foreach (['t_account_job_history', 't_account_bank'] as $fk) foreach (['VIEW', 'EDIT'] as $act) DB::table('mo_user_perms')->insert(['user_id' => $mgr->id, 'perm_code' => "form:$fk:$act", 'effect' => 'GRANTED', 'reason' => 'test', 'created_at' => now()]);
        $this->as($mgr);
        $v = $this->getJson("/api/record/t_account_job_history/$job")->assertOk()->json('values');
        $this->assertArrayNotHasKey('salary', $v, 'salary is not in the answer for a Manager');
        RecordService::save('t_account_job_history', ['salary' => 5000, 'notes' => 'x'], $job);
        $this->assertSame(900.0, (float) DB::table('t_account_job_history')->where('id', $job)->value('salary'), 'a hidden field is not changed on save');
        $b = $this->getJson("/api/record/t_account_bank/$bank")->assertOk()->json('values');
        $this->assertArrayNotHasKey('account_no', $b, 'a staff member\'s bank number is personal data');
        RecordService::save('t_account_bank', ['account_no' => '99999999'], $bank);
        $this->assertSame('12345678', DB::table('t_account_banks')->where('id', $bank)->value('account_no'));
        // the HR right (given to a person) opens it
        DB::table('mo_user_perms')->insert(['user_id' => $mgr->id, 'perm_code' => 'people.personal_data', 'effect' => 'GRANTED', 'reason' => 'HR', 'created_at' => now()]); Perm::flush();
        $this->assertSame(900.0, (float) $this->getJson("/api/record/t_account_job_history/$job")->json('values.salary'));
        $this->assertStringEndsWith('5678', (string) $this->getJson("/api/record/t_account_bank/$bank")->json('values.account_no'), 'shown again (masked by the vault as for everyone)');
    }

    public function test_e8_approval_limits_per_role_va_500_finance_2500_above_the_owner(): void
    {
        $fin = $this->makeUser('FINANCE'); $mgr = $this->makeUser('MANAGER');
        DB::table('mo_user_perms')->insert(['user_id' => $fin->id, 'perm_code' => 'money.approve', 'effect' => 'GRANTED', 'reason' => 'approver', 'created_at' => now()]); Perm::flush();
        $ap = fn (float $amt) => DB::table('sys_approvals')->insertGetId(['fk_id_ent' => 1, 'action_code' => 'EXPENSE', 'entity' => 'fin_expenses', 'entity_id' => 0, 'amount_gbp' => $amt, 'requested_by' => $mgr->id, 'requested_at' => now(), 'status' => 'PENDING']);
        $this->as($fin);
        $this->assertSame('REJECTED', Approvals::decide($ap(2400), false, 'not needed')->status, 'Finance decides up to 2,500');
        try { Approvals::decide($ap(3000), false, 'no'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Above your approval limit of 2,500.00 – the Owner decides this one.', $e->getMessage()); }
        $this->as($this->owner); $this->assertSame('REJECTED', Approvals::decide($ap(3000), false, 'no')->status, 'above → the Owner');
        $this->assertSame(500.0, Approvals::decideLimit($this->makeUser('VA'), 'EXPENSE', 1));
        $this->assertNull(Approvals::decideLimit($mgr, 'EXPENSE', 1), 'a role without a row has no limit');
    }

    public function test_d11_maker_checker_price_floor_and_lot_ownership_wait_for_the_owner(): void
    {
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'MC-' . uniqid(), 'product_name_vendor' => 'MC', 'stock' => 0, 'f_active' => 1]);
        $lst = $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $prod, 'min_price' => 10, 'price' => 20, 'f_active' => 1]);
        $mgr = $this->makeUser('MANAGER'); $this->as($mgr);
        $r = RecordService::save('listing', ['min_price' => 5, 'price' => 21], $lst);
        $this->assertTrue($r['pending']); $this->assertStringContainsString('waits', $r['note']);
        $row = DB::table('t_product_listings')->where('id', $lst)->first();
        $this->assertSame([10.0, 21.0], [(float) $row->min_price, (float) $row->price], 'the floor waits, the rest is saved');
        try { Approvals::decide($r['approval'], true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner approves this change.', $e->getMessage()); }
        $m2 = $this->makeUser('MANAGER'); $this->as($m2);
        try { Approvals::decide($r['approval'], true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner approves this change.', $e->getMessage()); }
        $this->as($this->owner); Approvals::decide($r['approval'], true);
        $this->assertSame(5.0, (float) DB::table('t_product_listings')->where('id', $lst)->value('min_price'));
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_product_listings')->where('record_id', $lst)->where('reason', 'Approved request #' . $r['approval'])->exists());
        // the Owner's own change is written at once
        $this->assertArrayNotHasKey('pending', RecordService::save('listing', ['min_price' => 6], $lst));
        // lot ownership by a Manager through the Stock screen waits; on approval the lot is split
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'WH MC', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $lot = StockService::receive(1, [['product' => $prod, 'qty' => 10, 'location' => $wh, 'unit_cost' => 2, 'received_date' => date('Y-m-d'), 'owner' => $this->person('Ali')]], 'GRN', 1)['lots'][0];
        $sara = $this->person('Sara');
        $this->as($mgr);
        $w = $this->postJson('/api/stock/ownership', ['lot' => $lot, 'qty' => 4, 'to_owner' => $sara])->assertOk()->json();
        $this->assertSame('WAITING', $w['status']); $this->assertSame(10, (int) DB::table('fin_lots')->where('id', $lot)->value('qty_left'));
        $this->as($this->owner); Approvals::decide($w['approval'], true);
        $this->assertSame(6, (int) DB::table('fin_lots')->where('id', $lot)->value('qty_left'));
        $this->assertSame(4, (int) DB::table('fin_lots')->where('fk_id_owner', $sara)->sum('qty_left'));
    }

    public function test_c5_c6_c7_person_text_names_contacts_and_reach(): void
    {
        $this->as($this->owner);
        $p = $this->person('Reach');
        // R-C-6 names in any script save, emoji and unreadable text are refused, spaces collapsed
        RecordService::save('t_account', ['first_name' => 'عامر', 'sur_name' => '  Khan   Ali '], $p);
        $this->assertSame(['عامر', 'Khan Ali'], array_values((array) DB::table('t_account')->where('id', $p)->first(['first_name', 'sur_name'])));
        $this->assertSame('First Name cannot contain emoji or special symbols.', $this->err(fn () => RecordService::save('t_account', ['first_name' => 'Ali 😀'], $p))['first_name'][0] ?? null);
        $this->assertStringContainsString('unreadable characters', json_encode($this->err(fn () => RecordService::save('t_account_address', ['fk_id_account' => $p, 'line1' => "Street \xC3\x28", 'l_address_type' => 'H']))));
        // R-C-5 names: letters only; DOB in the past; the required set
        $this->assertStringContainsString("can use letters", json_encode($this->err(fn () => RecordService::save('t_account', ['first_name' => 'Ali2'], $p))));
        $this->assertSame('Date of birth must be in the past.', $this->err(fn () => RecordService::save('t_account', ['d_of_birth' => date('Y-m-d', strtotime('+1 day'))], $p))['d_of_birth'][0]);
        $e = $this->err(fn () => RecordService::save('t_account', ['account_code' => substr(uniqid(), -7), 'first_name' => 'New', 'sur_name' => 'One']));
        $this->assertArrayHasKey('title', $e); $this->assertArrayHasKey('current_nationality', $e);
        // R-C-7 / R-C-27 e-mail lower case, valid, once per person; mobile format; the last way to reach is kept
        $m = (int) RecordService::save('t_account_email', ['fk_id_account' => $p, 'email' => 'Reach.Me@Example.COM', 'f_active' => 1], null, ['no_lookalike' => true])['id'];
        $this->assertSame('reach.me@example.com', DB::table('t_account_emails')->where('id', $m)->value('email'));
        $this->assertSame('This email is entered twice.', $this->err(fn () => RecordService::save('t_account_email', ['fk_id_account' => $p, 'email' => 'reach.me@example.com '], null, ['no_lookalike' => true]))['email'][0]);
        $this->assertSame('Please enter a valid email address.', $this->err(fn () => RecordService::save('t_account_email', ['fk_id_account' => $p, 'email' => 'not-an-email'], null, ['no_lookalike' => true]))['email'][0]);
        $this->assertSame('Add at least a mobile number or an email, so we can reach this person.', $this->err(fn () => RecordService::save('t_account_email', ['f_active' => 0], $m))['f_active'][0]);
        $mob = $this->f('t_account_mobiles', ['fk_id_account' => $p, 'mobile_no' => '+44 7700 900123', 'f_active' => 1]);
        RecordService::save('t_account_email', ['f_active' => 0], $m);      // a mobile is left → allowed
        $this->assertSame(0, (int) DB::table('t_account_emails')->where('id', $m)->value('f_active'));
        $this->assertStringContainsString('6 to 20 characters', json_encode($this->err(fn () => RecordService::save('t_account_mobile', ['mobile_no' => 'abc123'], $mob))));
    }

    public function test_c13_c24_c29_supplier_companies_apart_and_card_address_types(): void
    {
        $this->as($this->owner);
        $p = $this->person('Card');
        $own = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Own Co ' . uniqid(), 'f_external' => 0, 'f_active' => 1]);
        $sup = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Supplier Co ' . uniqid(), 'f_external' => 1, 'f_active' => 1]);
        foreach ([$own, $sup] as $c) $this->f('t_account_company_houses', ['fk_id_account' => $p, 'fk_company_house_id' => $c, 'f_active' => 1]);
        $names = array_column($this->getJson('/api/options/t_account_bank/fk_id_company?' . http_build_query(['parent' => ['fk_id_account' => $p]]))->assertOk()->json('options'), 1);
        $this->assertContains(DB::table('cat_company_house')->where('id', $own)->value('company_name'), $names);
        $this->assertNotContains(DB::table('cat_company_house')->where('id', $sup)->value('company_name'), $names, 'a supplier company never shows in the Banks › Company dropdown');
        $this->assertNotContains((string) $sup, array_column(\App\Services\Registry::options(['source' => 'table:cat_company_house', 'options_full' => null], 1), 0));
        // R-C-29 card: Personal address = Home, Business = Office; the pickers are split
        $home = $this->f('t_account_addresses', ['fk_id_account' => $p, 'line1' => '1 Home St', 'l_address_type' => 'H', 'f_active' => 1]);
        $hint = $this->getJson('/api/options/t_account_credit_card/linked_business_address?' . http_build_query(['parent' => ['fk_id_account' => $p]]))->json();
        $this->assertSame([], $hint['options']); $this->assertStringContainsString('+ Add office address', $hint['hint']);
        $this->assertSame('Business address must be an Office address.', $this->err(fn () => RecordService::save('t_account_credit_card', ['fk_id_account' => $p, 'linked_personal_address' => $home, 'linked_business_address' => $home, 'credit_limit' => 100]))['linked_business_address'][0]);
        $this->assertSame('Lived upto must be on or after lived from.', $this->err(fn () => RecordService::save('t_account_address', ['d_lived_from_addr1' => '2026-05-01', 'd_lived_upto_addr1' => '2026-01-01'], $home))['d_lived_upto_addr1'][0]);
    }

    public function test_c33_c34_c35_c37_subscription_kind_ip_warning_pc_and_the_shop_kit(): void
    {
        $this->as($this->owner);
        $p = $this->person('Seller'); $o = $this->person('Other');
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'eBay' . substr(uniqid(), -4), 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'K' . substr(uniqid(), -5), 'iso_branch' => 'K1', 'shop_name' => 'Kit shop', 'f_active' => 1]);
        $shopB = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'L' . substr(uniqid(), -5), 'iso_branch' => 'L1', 'shop_name' => 'Other shop', 'f_active' => 1]);
        $this->f('t_account_marketplaces', ['fk_id_account' => $p, 'fk_id_marketplace' => $mp, 'market_place_type' => 'Seller', 'fk_id_shop' => $shop, 'f_active' => 1]);
        $this->f('t_account_marketplaces', ['fk_id_account' => $o, 'fk_id_marketplace' => $mp, 'market_place_type' => 'Seller', 'fk_id_shop' => $shopB, 'f_active' => 1]);
        // R-C-33 kind IP → only the IP picker; a TOOL keeps neither link
        $ipItem = $this->f('cat_subscription_items', ['fk_id_ent' => 0, 'item_name' => 'IPBurger', 'kind' => 'IP', 'f_active' => 1]); $tool = $this->f('cat_subscription_items', ['fk_id_ent' => 0, 'item_name' => 'VPN', 'kind' => 'TOOL', 'f_active' => 1]);
        $this->assertSame(['item_kind' => 'IP'], $this->postJson('/api/form/t_account_subscription/calc', ['values' => ['fk_item_id' => $ipItem]])->assertOk()->json());
        $gbp = (int) DB::table('cat_currency')->where('currency_code', 'GBP')->value('id');
        $s = (int) RecordService::save('t_account_subscription', ['fk_id_account' => $p, 'fk_item_id' => $tool, 'fk_ipburger_id' => 3, 'fk_company_house_id' => 4, 'billing_cycle' => 'QUARTERLY', 'price' => 30, 'fk_id_currency' => $gbp, 'fk_id_branch' => $shop, 'f_active' => 1])['id'];
        $this->assertSame([0, 0], array_map('intval', array_values((array) DB::table('t_account_subscriptions')->where('id', $s)->first(['fk_ipburger_id', 'fk_company_house_id']))));
        // the shop's kit: £30 quarterly → +£10 a month; a social media account that promotes it
        $this->f('t_account_social_media', ['fk_id_account' => $p, 'username' => 'kitshop', 'fk_id_branch' => $shop, 'f_active' => 1]);
        $kit = $this->getJson("/api/shops/$shop/kit")->assertOk()->json();
        $this->assertSame(10.0, (float) $kit['monthly_cost']); $this->assertSame('kitshop', $kit['social_media'][0]['username']);
        // R-C-34 the same IP on another shop of the marketplace: warning, saved with a reason
        $ip = $this->f('cat_ip_burgers', ['fk_id_ent' => 1, 'ip_burger_ip' => '10.1.1.1', 'f_active' => 1]);
        $this->f('t_account_ipburgers', ['fk_id_account' => $o, 'fk_ipburger_id' => $ip, 'ipb_marketplace_id' => $mp, 'f_active' => 1]);
        $e = $this->err(fn () => RecordService::save('t_account_ipburger', ['fk_id_account' => $p, 'fk_ipburger_id' => $ip, 'fk_id_marketplace' => $mp, 'f_active' => 1]));
        $this->assertStringContainsString('is already used by', $e['fk_ipburger_id'][0]); $this->assertStringContainsString('Other shop', $e['fk_ipburger_id'][0]);
        $this->assertGreaterThan(0, RecordService::save('t_account_ipburger', ['fk_id_account' => $p, 'fk_ipburger_id' => $ip, 'fk_id_marketplace' => $mp, 'f_active' => 1], null, ['reason' => 'shared office line'])['id']);
        $this->assertCount(1, $this->getJson("/api/shops/$shop/kit")->json('ip_accounts'));
        // R-C-35 a PC: only a marketplace the person sells on, the hotspot SIM is his own; the SIM shows "Hotspot for PC"
        $mp2 = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'Etsy' . substr(uniqid(), -4), 'f_active' => 1]);
        $sim = $this->f('t_account_mobiles', ['fk_id_account' => $p, 'mobile_no' => '07700900555', 'f_active' => 1]); $otherSim = $this->f('t_account_mobiles', ['fk_id_account' => $o, 'mobile_no' => '07700900556', 'f_active' => 1]);
        $pcNo = $this->f('cat_tbl_pc', ['fk_id_ent' => 1, 'pc_number' => 'PC-77', 'f_active' => 1]);
        $e = $this->err(fn () => RecordService::save('t_account_pc', ['fk_id_account' => $p, 'pc_id' => $pcNo, 'fk_marketplace_id' => $mp2, 'hotspot_mobile_id' => $otherSim]));
        $this->assertArrayHasKey('fk_marketplace_id', $e); $this->assertArrayHasKey('hotspot_mobile_id', $e);
        RecordService::save('t_account_pc', ['fk_id_account' => $p, 'pc_id' => $pcNo, 'fk_marketplace_id' => $mp, 'hotspot_mobile_id' => $sim]);
        $this->assertSame('Hotspot for PC PC-77', $this->getJson('/api/page/t_account_mobile/' . $sim)->assertOk()->json('uses.0.text'));
    }

    public function test_c25_c31_c36_c29_submitted_on_buyer_purchases_director_slot_and_card_spend(): void
    {
        $this->as($this->owner);
        $p = $this->person('Director'); $d1 = $this->person('First');
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'Amz' . substr(uniqid(), -4), 'f_active' => 1]);
        $pid = $this->f('t_account_photo_ids', ['fk_id_account' => $p, 'long_digit_number' => 'P1234567', 'f_active' => 1]);
        $buyer = (int) RecordService::save('t_account_marketplace', ['fk_id_account' => $p, 'fk_id_marketplace' => $mp, 'market_place_type' => 'Buyer', 'fk_photo_submitted_id' => $pid, 'f_active' => 1])['id'];
        $this->assertSame(date('Y-m-d'), DB::table('t_account_marketplaces')->where('id', $buyer)->value('photo_submitted_date'), 'picking the passport fills "submitted on" with today');
        $this->assertStringStartsWith('Submitted to Marketplace', $this->getJson("/api/page/t_account_photo_id/$pid")->json('uses.0.text'));
        // R-C-31 "Bought with account"
        $this->assertContains((string) $buyer, array_column(\App\Services\Registry::options(['source' => 'buyer:accounts', 'options_full' => null], 1), 0));
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Sup C31', 'f_active' => 1]);
        $this->f('t_src_po', ['fk_id_ent' => 1, 'fk_id_supplier' => $sup, 'po_number' => 'C31-' . uniqid(), 'fk_id_buyer_account' => $buyer, 'f_active' => 1]);
        $this->assertSame('Purchases (1)', $this->getJson("/api/page/t_account_marketplace/$buyer")->json('uses.0.text'));
        // R-C-36 person P is director 2 with 40 % → the link shows "Director 2 · 40%", the preview highlights the slot
        $co = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Dir Co ' . uniqid(), 'fk_director_1' => $d1, 'director_1_share' => 60, 'fk_director_2' => $p, 'director_2_share' => 40, 'f_external' => 0, 'f_active' => 1]);
        $link = $this->f('t_account_company_houses', ['fk_id_account' => $p, 'fk_company_house_id' => $co, 'f_active' => 1]);
        $this->assertSame('Director 2 · 40%', $this->getJson("/api/page/t_account_company_house/$link")->json('uses.0.text'));
        $prev = $this->getJson("/api/people/company-preview/$co?person=$p")->assertOk()->json();
        $this->assertSame(2, $prev['slot']); $this->assertTrue(collect($prev['directors'])->firstWhere('slot', 2)['me']);
        // R-C-29 limit 2,000, statement spend 1,500 → "1,500 / 2,000 (75%)"
        $card = $this->f('t_account_credit_cards', ['fk_id_account' => $p, 'credit_limit' => 2000, 'f_card_billing_payment_date' => date('Y-m-01'), 'f_active' => 1]);
        $acc = $this->f('fin_bank_accounts', ['company_id' => 1, 'account_kind' => 'CARD', 'currency' => 'GBP', 'bank_name' => 'Card', 'account_label' => 'C29', 'is_active' => 1, 'fk_id_card' => $card]);
        $this->f('fin_bank_transactions', ['company_id' => 1, 'bank_account_id' => $acc, 'txn_date' => date('Y-m-d'), 'amount' => -1500, 'currency' => 'GBP', 'amount_gbp' => -1500, 'description' => 'spend', 'match_status' => 'UNMATCHED']);
        $this->assertSame('1,500 / 2,000 (75%)', PersonLinks::cardUsage($card)['text']);
    }

    public function test_c52_one_active_employment_row_and_probation_due_item(): void
    {
        $this->as($this->owner);
        $p = $this->person('Emp');
        $a = (int) RecordService::save('t_account_employment', ['fk_id_account' => $p, 'job_title' => 'VA', 'f_active' => 1])['id'];
        $b = (int) RecordService::save('t_account_employment', ['fk_id_account' => $p, 'job_title' => 'Team lead', 'd_probation_end' => date('Y-m-d', strtotime('+10 days')), 'f_active' => 1])['id'];
        $this->assertSame([0, 1], [(int) DB::table('t_account_employment')->where('id', $a)->value('f_active'), (int) DB::table('t_account_employment')->where('id', $b)->value('f_active')], 'a new row closes the old one');
        \App\Services\DueEngine::run(1, null, ['t_account_employment']);
        $this->assertTrue(DB::table('sys_due_items')->where('record_id', $b)->where('fk_id_source', DB::table('sys_due_sources')->where('code', 'PROBATION_END')->value('id'))->exists() || DB::table('tasks')->where('title', 'like', 'Probation of Team lead%')->exists(), 'probation end in 10 days → a due item');
        // a VA does not see the employment tab fields
        $va = $this->makeUser('VA'); $this->as($va);
        $this->assertArrayNotHasKey('d_probation_end', $this->getJson("/api/record/t_account_employment/$b")->json('values') ?? []);
    }

    public function test_c19_external_contacts_list_role_chips(): void
    {
        $this->as($this->owner);
        $v = $this->f('cat_account_types', ['account_type_name' => 'Vendorx', 'role_code' => 'VNDX' . substr(uniqid(), -4), 'iso_account_type' => 'VX', 'f_active' => 1]);
        $ids = [$this->person('Ext1', 'E'), $this->person('Ext2', 'E'), $this->person('Ext3', 'E')];
        foreach ([0, 1] as $i) DB::table('t_account_in_types')->insert(['fk_id_account' => $ids[$i], 'fk_id_account_type' => $v, 'f_active' => 1]);
        $d = $this->getJson('/api/people/external')->assertOk()->json();
        $this->assertSame(2, collect($d['chips'])->firstWhere('role', 'Vendorx')['count']);
        $this->assertCount(2, $this->getJson('/api/people/external?role=Vendorx')->json('rows'));
        $this->assertStringStartsWith('External Contacts (', $d['title']);
    }

    public function test_c38_the_loader_refuses_placeholder_lists_and_today_defaults(): void
    {
        $fh = fopen(database_path('meta/field_registry.csv'), 'r'); $head = fgetcsv($fh); $rows = [];
        while (($r = fgetcsv($fh)) !== false) $rows[] = array_combine($head, array_pad($r, count($head), ''));
        fclose($fh);
        $this->assertSame([], RegistryLint::errors($rows), 'the patched registry has 0 placeholder errors');
        $bad = RegistryLint::errors([['form_key' => 't_account', 'field_name' => 'grade', 'options_sample' => 'Standard | Premium | Seasonal | Core'], ['form_key' => 't_account', 'field_name' => 'd_of_birth', 'column_name' => 'd_of_birth', 'default_rule' => 'today']]);
        $this->assertCount(2, $bad);
    }

    public function test_a10_whatsapp_sign_in_code_for_owner_without_the_app(): void
    {
        $acc = $this->person('Owner');
        DB::table('t_account')->where('id', $acc)->update(['whatsapp_number' => '+447700900999']);
        DB::table('t_users')->where('id', $this->owner->id)->update(['fk_id_account' => $acc]);
        // no connection / template not approved → no code is asked (nobody is locked out)
        $this->post('/login', ['login' => 'owner@test', 'password' => 'secret-password'])->assertRedirect('/');
        $this->post('/logout');
        DB::table('api_connections')->insert(RecordService::fillNotNull('api_connections', ['fk_id_ent' => 1, 'adapter' => 'WHATSAPP_LOG', 'provider' => 'WHATSAPP', 'label' => 'log', 'status' => 'CONNECTED', 'settings_json' => '{}']));
        DB::table('wa_templates')->where('code', 'LOGIN_CODE')->update(['approval_status' => 'APPROVED']);
        $this->post('/login', ['login' => 'owner@test', 'password' => 'secret-password'])->assertRedirect('/login/code');
        $msg = DB::table('tm_messages')->where('rule_code', 'LOGIN_CODE')->orderByDesc('id')->first();
        $this->assertNotNull($msg); $this->assertNull($msg->send_after, 'a sign-in code is not held by quiet hours');
        preg_match('/\b(\d{6})\b/', $msg->text, $m);
        $this->get('/login/code')->assertSee('We sent a 6-digit code to your WhatsApp');
        $this->post('/login/code', ['code' => $m[1] === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->post('/login/code', ['code' => $m[1]])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner->fresh());
    }
}
