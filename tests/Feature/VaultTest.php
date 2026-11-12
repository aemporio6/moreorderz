<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\Context;
use App\Services\Rights;
use App\Services\Vault;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Round 2 part 5: secrets in the vault – encrypted, masked on every screen, revealed only with the right (logged), CVV / PIN never kept, cards 4 digits. */
class VaultTest extends TestCase
{
    private function person(): int
    {
        return (int) DB::table('t_account')->insertGetId(\App\Services\RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'V' . random_int(1000, 9999), 'first_name' => 'Vault', 'sur_name' => 'Test', 'f_active' => 1]));
    }

    public function test_a_saved_bank_record_keeps_secrets_only_in_the_vault_cards_four_digits_and_never_cvv_or_pin(): void
    {
        $p = $this->person();
        $id = $this->actingAs($this->owner)->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'account_no' => '12345678', 'card_number' => '4111 1111 1111 1234',
            'card_cvv' => '987', 'memorable_word' => 'bluebird', 'web_login_pass' => 'S3cret-pass']])->assertOk()->json('id');
        $row = (array) DB::table('t_account_banks')->find($id);
        $this->assertSame('•••• 5678', $row['account_no']);
        $this->assertSame('•••• 1234', $row['card_number']);
        $this->assertSame('1234', $row['card_last4']);
        $this->assertNull($row['card_cvv'], 'CVV is never stored');
        $this->assertSame('••••', $row['memorable_word']);
        $cells = DB::table('sys_vault')->where(['table_name' => 't_account_banks', 'record_id' => $id])->pluck('cipher', 'column_name');
        $this->assertEqualsCanonicalizing(['account_no', 'memorable_word', 'web_login_pass'], $cells->keys()->all(), 'the card number is not in the vault either: only 4 digits are kept');
        $everything = json_encode(DB::table('sys_vault')->get()) . json_encode(DB::table('sys_audit_log')->where('table_name', 't_account_banks')->get()) . json_encode($row);
        foreach (['12345678', '4111', 'bluebird', 'S3cret-pass', '987'] as $plain) $this->assertStringNotContainsString($plain, $everything, "$plain must not be readable anywhere");
        // the screen shows masks, for the Owner too
        $v = $this->actingAs($this->owner)->getJson("/api/record/t_account_bank/$id")->assertOk();
        $this->assertSame('••••', $v->json('values.memorable_word'));
        $this->assertContains('memorable_word', $v->json('vault'));
        $this->assertContains('card_cvv', $v->json('never'));
        // reveal: the Owner sees it, logged SHOWN without the value
        $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->assertOk()->assertJsonPath('value', 'bluebird');
        $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'account_no'])->assertOk()->assertJsonPath('value', '12345678');
        $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'card_number'])->assertStatus(422);
        $log = DB::table('sys_audit_log')->where(['table_name' => 't_account_banks', 'record_id' => $id, 'action' => 'REVEAL'])->pluck('changes_json')->map(fn ($j) => json_decode($j, true));
        $this->assertSame(['SHOWN', 'SHOWN'], $log->pluck('result')->all());
        $this->assertStringNotContainsString('bluebird', $log->toJson());
        // saving the form back unchanged keeps the secret; "-" removes it; a new value replaces it
        $this->actingAs($this->owner)->postJson("/api/record/t_account_bank/$id", ['values' => ['fk_id_account' => $p, 'memorable_word' => '••••', 'account_no' => '•••• 5678', 'web_login_pass' => '-']])->assertOk();
        $this->assertSame('bluebird', $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->json('value'));
        $this->assertSame('12345678', $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'account_no'])->json('value'));
        $this->assertFalse(DB::table('sys_vault')->where(['table_name' => 't_account_banks', 'record_id' => $id, 'column_name' => 'web_login_pass'])->exists());
        $this->actingAs($this->owner)->postJson("/api/record/t_account_bank/$id", ['values' => ['fk_id_account' => $p, 'memorable_word' => 'redrobin']])->assertOk();
        $this->assertSame('redrobin', $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->json('value'));
    }

    public function test_reveal_needs_the_right_and_every_refusal_is_logged(): void
    {
        $p = $this->person();
        $id = $this->actingAs($this->owner)->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'memorable_word' => 'bluebird']])->assertOk()->json('id');
        $mgr = $this->makeUser('MANAGER');
        if (! Rights::can('MANAGER', 'VIEW', 't_account_bank')) $this->markTestSkipped('The Manager does not open bank records in this menu.');
        $this->actingAs($mgr)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->assertStatus(403)->assertJsonMissing(['value' => 'bluebird']);
        $this->assertSame('DENIED', json_decode(DB::table('sys_audit_log')->where(['table_name' => 't_account_banks', 'record_id' => $id, 'action' => 'REVEAL'])->orderByDesc('id')->value('changes_json'), true)['result']);
        $this->assertSame('••••', $this->actingAs($mgr)->getJson("/api/record/t_account_bank/$id")->json('values.memorable_word'));
        // the Owner lets Managers reveal
        Context::set(1, $this->owner->id); Choices::set('VAULT_REVEAL', 'OWNER_MANAGER', 1);
        $this->actingAs($mgr)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->assertOk()->assertJsonPath('value', 'bluebird');
    }

    public function test_old_plain_values_are_sealed_once_and_stay_readable_by_reveal(): void
    {
        $p = $this->person();
        $id = DB::table('t_account_banks')->insertGetId(\App\Services\RecordService::fillNotNull('t_account_banks', ['fk_id_account' => $p, 'account_no' => 'GB-0099887766', 'card_number' => '5500000000004444', 'atm_pin' => '1234', 'memorable_word' => 'oldword', 'f_active' => 1]));
        $before = Vault::kinds('t_account_banks');
        $this->assertSame(['VAULT', 'CARD', 'NEVER'], [$before['account_no'], $before['card_number'], $before['atm_pin']]);
        $x = collect(Vault::sealExisting(['t_account_banks']))->firstWhere('table', 't_account_banks');
        $this->assertGreaterThanOrEqual(2, $x['sealed']);
        $row = DB::table('t_account_banks')->find($id);
        $this->assertSame(['•••• 7766', '•••• 4444', null, '••••'], [$row->account_no, $row->card_number, $row->atm_pin, $row->memorable_word]);
        $this->assertSame([], Vault::sealExisting(['t_account_banks']), 'a second run finds nothing');
        $this->actingAs($this->owner);
        $this->assertSame('oldword', $this->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->json('value'));
    }

    public function test_accounts_dashboard_counts_shows_what_expires_and_lists_reveals(): void
    {
        $p = $this->person(); DB::table('t_account')->where('id', $p)->update(['current_passport_expires' => date('d/m/Y', strtotime('+10 days'))]);
        $id = $this->actingAs($this->owner)->postJson('/api/record/t_account_bank', ['values' => ['fk_id_account' => $p, 'memorable_word' => 'x1', 'card_number' => '4000 0000 0000 0002', 'valid_upto' => date('Y-m-d', strtotime('-3 days'))]])->assertOk()->json('id');
        $this->actingAs($this->owner)->postJson("/api/reveal/t_account_bank/$id", ['field' => 'memorable_word'])->assertOk();
        $d = $this->actingAs($this->owner)->getJson('/api/accounts-dash')->assertOk();
        $due = collect($d->json('due'))->where('person_id', $p);
        $this->assertSame(['Bank card •••• 0002' => 'EXPIRED', 'Passport' => 'SOON'], $due->pluck('state', 'what')->all(), 'oldest date first');
        $this->assertSame('memorable_word', $d->json('reveals.0.field'));
        $this->assertGreaterThanOrEqual(1, collect($d->json('tiles'))->firstWhere('label', 'Bank accounts')['n']);
        $this->actingAs($this->makeUser('VA'))->getJson('/api/accounts-dash')->assertStatus(403);
        $this->assertSame('#/accounts', \App\Http\Controllers\AppController::routeOf((object) ['screen_no' => '12.1', 'forms' => '']));
    }
}
