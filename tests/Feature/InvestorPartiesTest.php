<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\InvestorParties;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-80: old investor ids are Companies House ids → company parties (with directors and share %), never the person with the same number; clashes and unknown ids go to review. */
class InvestorPartiesTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_id_7_maps_to_alpha_ltd_never_j_smith_and_a_settled_period_is_listed(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $dir = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Dina', 'sur_name' => 'Director', 'f_active' => 1]);
        // the same number in both tables: company "Alpha Ltd" and person "J. Smith"
        $n = max((int) DB::table('cat_company_house')->max('id'), (int) DB::table('t_account')->max('id')) + 7;
        DB::table('cat_company_house')->insert(RecordService::fillNotNull('cat_company_house', ['id' => $n, 'fk_id_ent' => 1, 'company_name' => 'Alpha Ltd', 'iso_code' => 'AL' . random_int(100, 999), 'fk_director_1' => $dir, 'director_1_share' => 60, 'f_active' => 1]));
        DB::table('t_account')->insert(RecordService::fillNotNull('t_account', ['id' => $n, 'fk_id_ent' => 1, 'first_name' => 'J.', 'sur_name' => 'Smith', 'f_active' => 1]));
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'INV-1', 'product_name_vendor' => 'Investor item', 'f_active' => 1]);
        DB::table('t_product_investors')->insert(['fk_id_product' => $prod, 'fk_id_account' => $n, 'created_at' => now()]);
        DB::table('t_product_investors')->insert(['fk_id_product' => $prod, 'fk_id_account' => $n + 50000, 'created_at' => now()]);      // matches no company
        $per = $this->f('t_inv_periods', ['fk_id_ent' => 1]); $this->f('t_inv_period_investors', ['fk_id_period' => $per, 'fk_id_investor_account' => $n]);

        $dry = $this->getJson('/api/investor-map')->assertOk()->json();
        $this->assertSame(['Alpha Ltd'], array_column($dry['mapped'], 'company'));
        $this->assertCount(2, $dry['review'], 'the unknown id and the settled-period clash');
        $this->assertSame(0, DB::table('t_product_investors')->whereNotNull('fk_id_party')->count(), 'a dry run changes nothing');

        $this->postJson('/api/investor-map')->assertOk();
        $party = (int) DB::table('t_product_investors')->where('fk_id_account', $n)->value('fk_id_party');
        $this->assertNotSame($n, $party, 'never the person J. Smith');
        $this->assertSame(['C', 'Alpha Ltd'], [DB::table('t_account')->where('id', $party)->value('l_party_type'), DB::table('t_party_profile')->where('fk_id_account', $party)->value('company_name')]);
        $this->assertEquals([$dir, 60], [(int) DB::table('t_party_directors')->where('fk_id_party', $party)->value('fk_id_director'), (float) DB::table('t_party_directors')->where('fk_id_party', $party)->value('share_pct')]);
        $review = $this->getJson('/api/investor-map')->json('waiting_review');
        $this->assertCount(2, $review); $this->assertStringContainsString('settled period', collect($review)->firstWhere('old_id', $n)['why']);
        $this->assertSame('0 mapped, 0 for review. 0 changes.', $this->postJson('/api/investor-map')->json('message'), 'a second run changes nothing');

        // the picker: "Company – directors (share %)"; save = upsert of unique ids > 0, persons refused
        $opt = collect($this->getJson("/api/products/$prod/investors")->assertOk()->json('options'))->firstWhere(0, (string) $party);
        $this->assertSame('Alpha Ltd – Dina Director (60%)', $opt[1]);
        $this->postJson("/api/products/$prod/investors", ['ids' => [$party, $party, 0]])->assertOk();
        $this->assertSame([$party], InvestorParties::ofProduct($prod));
        $this->postJson("/api/products/$prod/investors", ['ids' => [$n]])->assertStatus(422);
        $this->actingAs($this->makeUser('VA'))->getJson("/api/products/$prod/investors")->assertStatus(403);
    }
}
