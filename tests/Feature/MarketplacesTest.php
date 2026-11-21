<?php

namespace Tests\Feature;

use App\Services\Marketplaces;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-68: marketplace master, its questions, the checklist per shop. */
class MarketplacesTest extends TestCase
{
    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    public function test_master_checks_iso_code_and_texts(): void
    {
        $this->actingAs($this->owner); $c = DB::table('cat_country')->value('id') ?: DB::table('cat_country')->insertGetId($this->fill('cat_country', ['country_code' => 44, 'country_name' => 'United Kingdom', 'f_active' => 1]));
        $ok = ['short' => ' ebay', 'full' => 'eBay UK', 'iso' => 'ebay', 'country' => $c, 'payout' => 'Weekly', 'email1' => 'Help@Ebay.test', 'address' => 'london', 'fee_pct' => '12.8'];
        $msg = function (array $d, ?int $id = null) { try { Marketplaces::save($d, $id, $this->owner); return 'ok'; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
        $this->assertSame('Short Name is required', $msg(['short' => ' '] + $ok)); $this->assertSame('Full Name is required', $msg(['full' => ''] + $ok)); $this->assertSame('Country is required', $msg(['country' => 0] + $ok)); $this->assertSame('ISO Code is required', $msg(['iso' => ' '] + $ok));
        $this->assertSame('ISO Code must be 2 to 10 letters or digits, for example EBAY.', $msg(['iso' => 'E'] + $ok)); $this->assertSame('ISO Code must be 2 to 10 letters or digits, for example EBAY.', $msg(['iso' => 'EB-AY'] + $ok)); $this->assertSame('Payout must be Daily, Weekly or Monthly.', $msg(['payout' => 'Yearly'] + $ok));
        $id = Marketplaces::save($ok, null, $this->owner)['id']; $m = DB::table('cat_marketplaces')->where('id', $id)->first();
        $this->assertSame(['Ebay', 'EBay UK', 'EBAY', 'Weekly', 'help@ebay.test', 'London', 12.8], [$m->marketplace_short, $m->marketplace_descr, $m->iso_code, $m->marketplace_payout, $m->email1, $m->address, (float) $m->fee_pct]);      // trimmed, first letter upper-case, ISO upper-case
        // a second marketplace with ISO "EBAY" is refused; editing the first one with its own code is fine
        $this->assertSame('This ISO code already exists. Please add another', $msg(['short' => 'Other', 'iso' => 'eBay'] + $ok)); $this->assertSame('ok', $msg(['full' => 'eBay United Kingdom'] + $ok, $id));
        $this->assertFalse(Marketplaces::isoFree('ebay')); $this->assertTrue(Marketplaces::isoFree('ebay', $id)); $this->getJson('/api/marketplaces/iso?iso=EBAY')->assertOk()->assertJsonPath('message', 'This ISO code already exists. Please add another');
        $n = Marketplaces::addCountry(' atlantis', $this->owner); $this->assertSame('Atlantis', $n['name']); $this->assertSame($n['id'], Marketplaces::addCountry('ATLANTIS', $this->owner)['id']);      // "+ New" next to Country, never twice
        $this->assertSame(12.8, Marketplaces::feeHint($id)); $this->getJson('/api/marketplaces')->assertOk()->assertJsonPath('can_edit', true);
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'va@demo.test', 'display_name' => 'V', 'role_code' => 'VA', 'f_active' => 1])));
        try { Marketplaces::save($ok, $id, $va); $this->fail('no error'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }
    }

    public function test_questions_sorted_and_the_checklist_of_a_shop(): void
    {
        $this->actingAs($this->owner); $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1])); $mp2 = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Temu', 'marketplace_descr' => 'Temu', 'f_active' => 1]));
        $shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1]));
        $q = Marketplaces::questions($mp); $this->assertSame(['Questions List for (Ebay)', 'No questions found.', []], [$q['title'], $q['empty'], $q['rows']]);
        $b = Marketplaces::saveQuestion($mp, 'Is the VAT number added?', null, $this->owner)['id']; $a = Marketplaces::saveQuestion($mp, 'Are the bank details verified?', null, $this->owner)['id']; $other = Marketplaces::saveQuestion($mp2, 'Temu only', null, $this->owner)['id'];
        $this->assertSame(['Are the bank details verified?', 'Is the VAT number added?'], array_column(Marketplaces::questions($mp)['rows'], 'question'));      // A–Z
        try { Marketplaces::saveQuestion($mp, ' ', null, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('Question is required', $e->getMessage()); }
        try { Marketplaces::saveQuestion($mp, 'is the vat number added?', null, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('This question is already in the list.', $e->getMessage()); }
        Marketplaces::saveQuestion($mp, 'Is the VAT number shown on the shop?', $b, $this->owner);
        // the questions are a checklist on each shop of that marketplace
        $c = Marketplaces::checklist($shop, $this->owner); $this->assertSame([2, 0, '0 of 2 done'], [$c['total'], $c['done'], $c['text']]);
        $c = Marketplaces::tick($shop, $a, true, 'checked with the bank', $this->owner); $this->assertSame(['1 of 2 done', true, 'checked with the bank'], [$c['text'], $c['rows'][0]['done'], $c['rows'][0]['note']]);
        try { Marketplaces::tick($shop, $other, true, null, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('does not belong to the marketplace of the shop', $e->getMessage()); }
        Marketplaces::tick($shop, $a, false, null, $this->owner); $this->assertSame(0, Marketplaces::checklist($shop, $this->owner)['done']);
        Marketplaces::deleteQuestion($mp, $a, $this->owner); $this->assertSame(1, Marketplaces::checklist($shop, $this->owner)['total']); $this->assertSame(0, DB::table('mo_question_answers')->where('fk_id_question', $a)->count());
        $this->getJson("/api/marketplaces/$mp/questions")->assertOk()->assertJsonCount(1, 'rows'); $this->postJson("/api/shops/$shop/checklist", ['question' => $b, 'done' => 1])->assertOk()->assertJsonPath('done', 1);
    }
}
