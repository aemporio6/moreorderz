<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-21: dependent dropdowns of the person forms – one golden test per chain. */
class PersonChainsTest extends TestCase
{
    private int $p; private int $other; private int $uk; private int $pk; private int $bankX; private int $bankY;

    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function opts(string $form, string $field, array $parents): array
    {
        $qs = http_build_query(['parent' => $parents]);
        return $this->getJson("/api/options/$form/$field?$qs")->assertOk()->json();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'P' . random_int(1000, 9999), 'first_name' => 'Hira', 'sur_name' => 'Khan', 'f_active' => 1]);
        $this->other = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'Q' . random_int(1000, 9999), 'first_name' => 'Other', 'sur_name' => 'Person', 'f_active' => 1]);
        $this->uk = $this->f('cat_country', ['country_name' => 'Chainland UK', 'country_code' => 9901, 'iso_code' => 'X1', 'f_active' => 1]);
        $this->pk = $this->f('cat_country', ['country_name' => 'Chainland PK', 'country_code' => 9902, 'iso_code' => 'X2', 'f_active' => 1]);
        $this->bankX = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Bank X', 'bank_short_name' => 'BX', 'f_active' => 1]);
        $this->bankY = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Bank Y', 'bank_short_name' => 'BY', 'f_active' => 1]);
    }

    public function test_mobile_country_networks_addresses_and_device_slots(): void
    {
        $n1 = $this->f('cat_mobile_network', ['fk_id_ent' => 1, 'fk_id_country' => $this->uk, 'network_name' => 'Vodafone', 'f_active' => 1]);
        $this->f('cat_mobile_network', ['fk_id_ent' => 1, 'fk_id_country' => $this->uk, 'network_name' => 'Old net', 'f_active' => 0]);
        $this->f('cat_mobile_network', ['fk_id_ent' => 1, 'fk_id_country' => $this->pk, 'network_name' => 'Jazz', 'f_active' => 1]);
        $this->assertSame([[(string) $n1, 'Vodafone']], $this->opts('t_account_mobile', 'fk_id_mobile_network', ['fk_id_country' => $this->uk])['options'], 'active networks of that country only');
        $this->assertSame('Choose the country first.', $this->opts('t_account_mobile', 'fk_id_mobile_network', [])['hint']);
        $a1 = $this->f('t_account_addresses', ['fk_id_account' => $this->p, 'line1' => '1 UK Road', 'fk_id_country' => $this->uk, 'f_active' => 1]);
        $this->f('t_account_addresses', ['fk_id_account' => $this->p, 'line1' => '2 PK Road', 'fk_id_country' => $this->pk, 'f_active' => 1]);
        $this->f('t_account_addresses', ['fk_id_account' => $this->other, 'line1' => '3 UK Road', 'fk_id_country' => $this->uk, 'f_active' => 1]);
        $this->assertSame([[(string) $a1, '1 UK Road']], $this->opts('t_account_mobile', 'fk_id_address', ['fk_id_account' => $this->p, 'fk_id_country' => $this->uk])['options'], "this person's addresses in that country");
        $dev = $this->f('t_mobile_devices', ['fk_id_ent' => 1, 'mobile_name' => 'Rack phone', 'tot_slots' => 3, 'f_active' => 1]);
        $this->f('t_account_mobiles', ['fk_id_account' => $this->other, 'mobile_no' => '07000000001', 'fk_id_mobile_device' => $dev, 'slotno' => '2', 'f_active' => 1]);
        $this->assertSame([['1', 'Slot 1'], ['3', 'Slot 3']], $this->opts('t_account_mobile', 'slotno', ['fk_id_mobile_device' => $dev])['options'], 'device → free slots');
        $this->assertSame([['1', 'Slot 1'], ['2', 'Slot 2'], ['3', 'Slot 3']], $this->opts('t_account_mobile', 'slotno', ['fk_id_mobile_device' => $dev, 'slotno' => '2'])['options'], 'on edit the saved slot stays selectable');
    }

    public function test_six_questions_per_bank_provider_marketplace_social_subscription_company_house_paypal(): void
    {
        $q = fn (string $type, int $owner, string $text) => $this->f('sys_security_questions', ['owner_type' => $type, 'owner_id' => $owner, 'question' => $text, 'f_active' => 1]);
        $qx = $q('BANK', $this->bankX, 'First school?'); $q('BANK', $this->bankY, 'Pet name?');
        $this->assertSame([[(string) $qx, 'First school?']], $this->opts('t_account_bank', 'fk_question_id1', ['fk_bank_id' => $this->bankX])['options']);
        $this->assertSame([[(string) $qx, 'First school?']], $this->opts('t_account_bank', 'fk_question_id6', ['fk_bank_id' => $this->bankX])['options']);
        $this->assertSame([], $this->opts('t_account_bank', 'fk_question_id1', [])['options'], 'no bank → no questions');
        $prov = $this->f('cc_providers', ['fk_id_ent' => 1, 'card_provider_name' => 'Amex', 'f_active' => 1]); $qp = $q('CARD_PROVIDER', $prov, 'Card word?');
        $this->assertSame((string) $qp, $this->opts('t_account_credit_card', 'fk_question_id2', ['fk_cc_provider_id' => $prov])['options'][0][0]);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'ChainMP', 'f_active' => 1]); $qm = $q('MARKETPLACE', $mp, 'Shop word?');
        $this->assertSame((string) $qm, $this->opts('t_account_marketplace', 'fk_question_id3', ['fk_id_marketplace' => $mp])['options'][0][0]);
        $soc = $this->f('cat_social_media', ['fk_id_ent' => 1, 'social_media_name' => 'Chainbook', 'f_active' => 1]); $qs = $q('SOCIAL', $soc, 'Social word?');
        $this->assertSame((string) $qs, $this->opts('t_account_social_media', 'fk_question_id1', ['fk_social_media_id' => $soc])['options'][0][0]);
        $item = $this->f('cat_subscription_items', ['fk_id_ent' => 1, 'item_name' => 'Chain VPN', 'f_active' => 1]); $qi = $q('SUBSCRIPTION', $item, 'Sub word?');
        $this->assertSame((string) $qi, $this->opts('t_account_subscription', 'fk_question_id4', ['fk_item_id' => $item])['options'][0][0]);
        $ch = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Chain Ltd']); $qc = $q('COMPANIES_HOUSE', $ch, 'CH word?');
        $this->assertSame((string) $qc, $this->opts('t_account_company_house', 'fk_question_id5', ['fk_company_house_id' => $ch])['options'][0][0]);
        $qpp = $q('PAYPAL', $this->bankX, 'PayPal word?');
        $this->assertSame((string) $qpp, $this->opts('t_account_paypal', 'fk_question_id1', ['fk_bank_id' => $this->bankX])['options'][0][0]);
    }

    public function test_bank_and_person_accounts_paypal_debit_cards_shops_and_owner_companies(): void
    {
        $ax = $this->f('t_account_banks', ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankX, 'account_no' => '11112222', 'sort_branch_code' => '20-00-00', 'f_active' => 1]);
        $this->f('t_account_banks', ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankY, 'account_no' => '33334444', 'f_active' => 1]);
        $this->f('t_account_banks', ['fk_id_account' => $this->other, 'fk_bank_id' => $this->bankX, 'account_no' => '55556666', 'f_active' => 1]);
        $o = $this->opts('t_account_marketplace', 'fk_id_bank_account', ['fk_id_account' => $this->p, 'fk_id_bank' => $this->bankX]);
        $this->assertSame([[(string) $ax, 'Bank X ••2222 · sort code 20-00-00']], $o['options'], 'payout bank + person → that person\'s accounts at that bank, last 4 digits only');
        $this->assertSame([(string) $ax], array_column($this->opts('t_account_marketplace', 'fk_id_bank_payment_account', ['fk_id_account' => $this->p, 'fk_id_bank_payment' => $this->bankX])['options'], 0), 'payment bank separately');
        $this->assertSame([(string) $ax], array_column($this->opts('t_account_subscription', 'fk_id_bank_account', ['fk_id_account' => $this->p, 'fk_id_bank' => $this->bankX])['options'], 0), 'paid-from on subscriptions');
        $this->assertSame('Choose the bank first.', $this->opts('t_account_subscription', 'fk_id_bank_account', ['fk_id_account' => $this->p])['hint']);

        $d1 = $this->f('t_account_credit_cards', ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankX, 'card_type' => 'Debit', 'name_on_card' => 'H KHAN', 'card_last4' => '4242', 'sort_branch_code' => '20-00-00', 'f_active' => 1]);
        $this->f('t_account_credit_cards', ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankX, 'card_type' => 'Credit', 'card_last4' => '1111', 'f_active' => 1]);
        $this->f('t_account_credit_cards', ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankY, 'card_type' => 'Debit', 'card_last4' => '9999', 'f_active' => 1]);
        foreach ([1, 2, 3] as $i) $this->assertSame([[(string) $d1, 'H KHAN ••4242 · sort code 20-00-00']], $this->opts('t_account_paypal', "linked_debit_card_id$i", ['fk_id_account' => $this->p, 'fk_bank_id' => $this->bankX])['options'], "PayPal picker $i: Debit cards at that bank");

        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'ChainMP2', 'f_active' => 1]);
        $s1 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'C1', 'iso_branch' => 'C1', 'shop_name' => 'Chain Shop', 'f_active' => 1]);
        $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'C2', 'iso_branch' => 'C2', 'shop_name' => 'Closed Shop', 'f_active' => 0]);
        $this->assertSame([[(string) $s1, "$s1-Chain Shop"]], $this->opts('t_account_marketplace', 'fk_id_shop', ['fk_id_marketplace' => $mp])['options'], 'marketplace → active shops "id-shop name"');
        $this->assertSame([[(string) $s1, "$s1-Chain Shop"]], $this->opts('t_account_job_history', 'fk_id_branch', ['fk_id_marketplace' => $mp])['options'], 'job marketplace → shops');

        $co = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Hira Trading Ltd', 'fk_director_1' => $this->p, 'director_1_share' => 60, 'fk_director_2' => $this->other, 'director_2_share' => 40]);
        $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Not Hers Ltd', 'fk_director_1' => $this->other, 'director_1_share' => 100]);
        $this->assertSame([[(string) $co, 'Hira Trading Ltd – [Director 1: Hira Khan Share: 60%, Director 2: Other Person Share: 40%]']], $this->opts('shop', 'company', ['fk_id_owner' => $this->p])['options'], 'shop owner → that owner\'s companies');

        // every own list: this person's rows only; a changed parent clears its children (cascades_from)
        $this->f('t_account_emails', ['fk_id_account' => $this->p, 'email' => 'hira@example.test', 'f_active' => 1]); $this->f('t_account_emails', ['fk_id_account' => $this->other, 'email' => 'other@example.test', 'f_active' => 1]);
        $this->assertSame(['hira@example.test'], array_column($this->opts('t_account_bank', 'linked_email', ['fk_id_account' => $this->p])['options'], 1));
        $def = collect($this->getJson('/api/form/t_account_paypal')->assertOk()->json('sections'))->flatMap(fn ($s) => $s['fields'])->keyBy('name');
        $this->assertSame('fk_id_account,fk_bank_id', $def['linked_debit_card_id1']['cascades_from']);
    }
}
