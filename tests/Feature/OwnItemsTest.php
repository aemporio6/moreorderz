<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-20: own-item pickers list only the person's own items with the rule's labels; the server refuses another person's id; a draft person stays out of other pickers. */
class OwnItemsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function opts(string $form, string $field, array $parent): array { return $this->getJson("/api/options/$form/$field?" . http_build_query(['parent' => $parent]))->assertOk()->json('options'); }

    public function test_the_rule_example_a_bank_form_never_lists_b_mobiles_and_b_mobile_id_is_refused(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OA' . random_int(100, 999), 'first_name' => 'Asma', 'f_active' => 1]);
        $b = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OB' . random_int(100, 999), 'first_name' => 'Bilal', 'f_active' => 1]);
        $uk = $this->f('cat_country', ['country_name' => 'Own Kingdom', 'country_code' => '+44', 'iso_code' => 'O' . random_int(10, 99), 'f_active' => 1]);
        $dev = $this->f('t_mobile_devices', ['fk_id_ent' => 1, 'mobile_name' => 'Samsung S25', 'tot_slots' => 2, 'f_active' => 1]);
        $ma = $this->f('t_account_mobiles', ['fk_id_account' => $a, 'fk_id_country' => $uk, 'mobile_no' => '7700900111', 'fk_id_mobile_device' => $dev, 'f_active' => 1]);
        $mb = $this->f('t_account_mobiles', ['fk_id_account' => $b, 'fk_id_country' => $uk, 'mobile_no' => '7700900222', 'f_active' => 1]);
        $ad = $this->f('t_account_addresses', ['fk_id_account' => $a, 'l_address_type' => 'H', 'counti' => 'Kent', 'fk_id_country' => $uk, 'line1' => '1 Oak Road', 'f_active' => 1]);
        $bank = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Own Bank', 'bank_short_name' => 'OWB', 'f_active' => 1]);

        // A's bank form lists only A's mobiles, labelled "code-number device"; the address "Home county country line1"
        $this->assertSame([[(string) $ma, '+44-7700900111 Samsung S25']], $this->opts('t_account_bank', 'linked_mobile', ['fk_id_account' => $a]));
        $this->assertSame([[(string) $ad, 'Home Kent Own Kingdom 1 Oak Road']], $this->opts('t_account_bank', 'linked_address', ['fk_id_account' => $a]));
        $this->assertSame([], $this->opts('t_account_bank', 'linked_mobile', []), 'no person → nothing');
        // posting B's mobile id on A's bank → refused
        $this->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $a, 'fk_bank_id' => $bank, 'account_no' => '11112222', 'linked_mobile' => $mb]])
            ->assertStatus(422)->assertJsonPath('errors.linked_mobile.0', 'This mobile does not belong to this person');
        $id = $this->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $a, 'fk_bank_id' => $bank, 'account_no' => '11112222', 'linked_mobile' => $ma, 'linked_address' => $ad]])->assertOk()->json('id');
        $this->assertSame($ma, (int) DB::table('t_account_banks')->where('id', $id)->value('linked_mobile'));
        // moving the bank to B while it still links A's mobile → refused too
        $this->postJson("/api/record/t_account_bank/$id", ['values' => ['fk_id_account' => $b, 'linked_mobile' => $ma]])->assertStatus(422);
        // a card: "acc(sort) •••• last4" (masked)
        $card = $this->f('t_account_credit_cards', ['fk_id_account' => $a, 'account_no' => '87654321', 'sort_branch_code' => '20-00-00', 'card_last4' => '4242', 'f_active' => 1]);
        $this->assertSame('••4321(20-00-00) •••• 4242', \App\Services\OwnItems::label('t_account_credit_cards', DB::table('t_account_credit_cards')->where('id', $card)->first()));

        // "+ Add" on a person not saved yet: the person is saved as a DRAFT, kept out of other pickers, and the final Save makes it ACTIVE
        $d = $this->postJson('/api/record/t_account/draft', ['values' => ['first_name' => 'Draft', 'sur_name' => 'Person']])->assertOk()->json('id');
        $this->assertSame('DRAFT', DB::table('t_account')->where('id', $d)->value('record_status'));
        $people = array_column($this->getJson('/api/options/t_account_bank/fk_id_account?q=Draft')->json('options'), 0);
        $this->assertNotContains((string) $d, $people, 'a draft never appears in pickers of other forms');
        $this->postJson("/api/record/t_account/$d", ['values' => ['first_name' => 'Draft']])->assertOk();
        $this->assertSame('ACTIVE', DB::table('t_account')->where('id', $d)->value('record_status'));
        $this->assertContains((string) $d, array_column($this->getJson('/api/options/t_account_bank/fk_id_account?q=Draft')->json('options'), 0));
    }
}
