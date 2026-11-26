<?php

namespace Tests\Feature;

use App\Services\AccountCode;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-4: one account-code rule – upper case, format, unique per enterprise, suggestion MAX+1 (external "-EX"). */
class AccountCodeTest extends TestCase
{
    public function test_the_rule_example_213_and_214_ex_suggest_215_and_215_ex(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        DB::table('t_account')->where('fk_id_ent', 1)->update(['account_code' => DB::raw("CONCAT('X', id)")]);      // no numeric codes but ours
        foreach (['213', '214-EX'] as $c) DB::table('t_account')->insert(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => $c, 'first_name' => "P$c", 'f_active' => 1]));
        $this->assertSame(['215', '215-EX'], [AccountCode::suggest(1), AccountCode::suggest(1, true)]);
        $this->assertSame('215', $this->getJson('/api/form/t_account')->assertOk()->json('blank.account_code'), 'the new form opens with the suggestion');

        // typing "214-ex" → upper-cased, refused as used, suggests 215-EX (external form)
        $this->postJson('/api/record/external_contact', ['values' => ['account_code' => '214-ex', 'first_name' => 'Ext']])->assertStatus(422)->assertJsonPath('errors.account_code.0', 'Account Code 214-EX is already used. Suggested: 215-EX');
        $this->getJson('/api/form/t_account/live?' . http_build_query(['field' => 'account_code', 'value' => '214-ex']))->assertOk()->assertJsonPath('ok', false)->assertJsonPath('value', '214-EX');
        $this->postJson('/api/record/t_account', ['values' => ['account_code' => 'BAD CODE!', 'first_name' => 'X']])->assertStatus(422)->assertJsonPath('errors.account_code.0', 'Account Code: up to 10 letters, numbers or "-" (for example 214-EX).');
        $this->postJson('/api/record/t_account', ['values' => ['account_code' => '', 'first_name' => 'X']])->assertStatus(422)->assertJsonPath('errors.account_code.0', 'Account Code is required.');
        $this->postJson('/api/record/t_account', ['values' => ['account_code' => '12345678901', 'first_name' => 'X']])->assertStatus(422);
        $id = $this->postJson('/api/record/t_account', ['values' => ['account_code' => '215', 'title' => 'Mr', 'current_nationality' => $c = (int) \Illuminate\Support\Facades\DB::table('cat_country')->value('id'), 'old_nationality' => $c, 'first_name' => 'Managed', 'sur_name' => 'One']])->assertOk()->json('id');
        $this->assertSame('216', AccountCode::suggest(1));
        $this->postJson('/api/record/t_account', ['values' => ['account_code' => '215', 'title' => 'Mr', 'current_nationality' => $c, 'old_nationality' => $c, 'first_name' => 'Twin', 'sur_name' => 'Two']])->assertStatus(422)->assertJsonPath('errors.account_code.0', 'Account Code 215 is already used. Suggested: 216');
        $this->postJson("/api/record/t_account/$id", ['values' => ['account_code' => '215', 'first_name' => 'Managed again', 'sur_name' => 'One']])->assertOk();
    }
}
