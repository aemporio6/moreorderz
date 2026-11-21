<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-22: security questions per provider – lists, no question twice, NULL when empty, "Questions set n/6". */
class SecurityQuestionsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_bank_x_list_a_to_z_and_no_question_twice(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SQ' . random_int(100, 999), 'first_name' => 'Zara', 'f_active' => 1]);
        $x = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Bank X', 'bank_short_name' => 'BX', 'f_active' => 1]); $y = $this->f('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Bank Y', 'bank_short_name' => 'BY', 'f_active' => 1]);
        $q = fn (string $type, int $o, string $t) => $this->f('sys_security_questions', ['owner_type' => $type, 'owner_id' => $o, 'question' => $t, 'f_active' => 1]);
        $q1 = $q('BANK', $x, 'Mother maiden name?'); $q2 = $q('BANK', $x, 'First school?'); $q3 = $q('BANK', $x, 'Best friend?'); $q('BANK', $y, 'Other bank question?');
        foreach (range(1, 6) as $i) {
            $o = $this->getJson("/api/options/t_account_bank/fk_question_id$i?" . http_build_query(['parent' => ['fk_bank_id' => $x]]))->assertOk()->json('options');
            $this->assertSame(['Best friend?', 'First school?', 'Mother maiden name?'], array_column($o, 1), "list $i: only bank X questions, A–Z");
        }
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'SQM', 'f_active' => 1]); $q('MARKETPLACE', $mp, 'Alpha?'); $q('MARKETPLACE', $mp, 'Zulu?');
        $this->assertSame(['Zulu?', 'Alpha?'], array_column($this->getJson('/api/options/t_account_marketplace/fk_question_id1?' . http_build_query(['parent' => ['fk_id_marketplace' => $mp]]))->json('options'), 1), 'marketplace list Z–A (old)');
        $pp = $q('PAYPAL', 0, 'PayPal set question?');
        $this->assertSame([(string) $pp], array_column($this->getJson('/api/options/t_account_paypal/fk_question_id1')->json('options'), 0), 'PayPal: the shared set without choosing anything');

        // the same question in slots 1 and 2 → "Already used in question 1"
        $this->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'fk_bank_id' => $x, 'account_no' => '12345678', 'fk_question_id1' => $q1, 'answer1' => 'Smith', 'fk_question_id2' => $q1, 'answer2' => 'Smith']])
            ->assertStatus(422)->assertJsonPath('errors.fk_question_id2.0', 'Already used in question 1');
        $id = $this->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'fk_bank_id' => $x, 'account_no' => '12345678', 'fk_question_id1' => $q1, 'answer1' => 'Smith', 'fk_question_id2' => $q2, 'answer2' => 'Oak School',
            'fk_question_id3' => $q3, 'answer3' => 'Ali', 'fk_question_id4' => '0']])->assertOk()->json('id');
        $row = DB::table('t_account_banks')->where('id', $id)->first();
        $this->assertNull($row->fk_question_id4, 'an empty question is NULL (old 0)');
        $this->assertStringStartsWith('•', (string) $row->answer1, 'answers are secrets: masked in the column, kept in the vault');
        // health on the person: "Questions set 3/6"; e-mail 3 free-text pairs, no duplicate
        $this->postJson('/api/record/t_account_email', ['values' => ['fk_id_account' => $p, 'email' => 'zara@example.test', 'question1' => 'Pet?', 'ans1' => 'Tom', 'question2' => 'pet?', 'ans2' => 'Tom']])->assertStatus(422)->assertJsonPath('errors.question2.0', 'Already used in question 1');
        $this->postJson('/api/record/t_account_email', ['values' => ['fk_id_account' => $p, 'email' => 'zara@example.test', 'question1' => 'Pet?', 'ans1' => 'Tom', 'question2' => 'Street?', 'ans2' => 'High St', 'question3' => 'Town?', 'ans3' => 'Leeds']])->assertOk();
        $h = collect($this->getJson("/api/people/$p/security-questions")->assertOk()->json('rows'))->keyBy('kind');
        $this->assertSame(['Questions set 3/6', '••5678', false], [$h['Bank account']['text'], $h['Bank account']['label'], $h['Bank account']['complete']]);
        $this->assertSame(['Questions set 3/3', true], [$h['E-mail']['text'], $h['E-mail']['complete']]);
    }
}
