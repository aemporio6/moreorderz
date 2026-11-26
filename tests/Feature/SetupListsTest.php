<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Old tool lines 27-11: R-D-70 type / trend codes, R-F_SETUP-37 the company list with its directors and its tax link. */
class SetupListsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_d70_type_and_trend_codes(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $id = RecordService::save('cat_product_type', ['product_type_name' => '  Kit  set ', 'iso_product_type' => 'kit', 'fk_id_ent' => 1])['id'];
        $this->assertSame(['Kit set', 'KIT'], array_values((array) DB::table('cat_product_type')->where('id', $id)->first(['product_type_name', 'iso_product_type'])));
        try { RecordService::save('cat_product_type', ['product_type_name' => 'Kits', 'iso_product_type' => 'Kit', 'fk_id_ent' => 1]); $this->fail('same code'); }
        catch (\Throwable $e) { $this->assertStringContainsString('already used', json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage())); }
        try { RecordService::save('cat_product_trends', ['product_trend_name' => 'Summer', 'iso_product_trend' => '', 'fk_id_ent' => 1]); $this->fail('no code'); }
        catch (\Throwable $e) { $this->assertStringContainsString('Enter the code', json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage())); }
    }

    public function test_f37_company_list_shows_directors_and_tax_link(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $d1 = $this->f('t_account', ['first_name' => 'Ayesha', 'sur_name' => 'Khan', 'f_active' => 1]); $d2 = $this->f('t_account', ['first_name' => 'Bilal', 'sur_name' => 'Raza', 'f_active' => 1]);
        $co = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Test Trading Ltd', 'registration_no' => '12345678', 'iso_code' => 'TTL', 'fk_director_1' => $d1, 'director_1_share' => 60, 'fk_director_2' => $d2, 'director_2_share' => 40, 'f_active' => 1]);
        $l = RecordService::list('cat_company_house', ['per_page' => 200]);
        $this->assertSame(['directors', 'tax'], array_slice(array_column(array_filter($l['columns'], fn ($c) => ! empty($c['computed']) && $c['name'] !== 'company'), 'name'), 0, 2));
        $row = collect($l['rows'])->firstWhere('id', $co);
        $this->assertSame('Ayesha Khan 60%, Bilal Raza 40%', $row['directors']); $this->assertSame('#/tax?company=' . $co, $row['tax']);
        $this->getJson('/api/tax?company=' . $co)->assertOk()->assertJsonPath('company.name', 'Test Trading Ltd');
    }
}
