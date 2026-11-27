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

    /** R-D-71 + 27-11 sweep: secrets in columns no form showed are sealed in the vault; the record keeps only ••••; a list never shows them. */
    public function test_d71_hidden_secrets_are_sealed(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $v = $this->f('cat_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Local Co', 'vendor_password' => 'plain-secret-1', 'vendor_bank_details' => 'Sort 00-00-00 Acc 12345678', 'f_active' => 1]);
        \App\Services\Vault::sealExisting(['cat_vendors']);
        $row = DB::table('cat_vendors')->where('id', $v)->first();
        $this->assertTrue(\App\Services\Vault::isMasked($row->vendor_password)); $this->assertTrue(\App\Services\Vault::isMasked($row->vendor_bank_details));
        $this->assertSame(2, DB::table('sys_vault')->where('table_name', 'cat_vendors')->where('record_id', $v)->count());
        $this->assertStringNotContainsString('plain-secret-1', json_encode(RecordService::list('cat_vendors', ['cols' => ['vendor_name', 'vendor_password']])));
    }

    /** R-D-16 history in words (a chosen record by its name) and R-D-42 the product's purchases. */
    public function test_history_names_and_product_purchases(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $ty = $this->f('cat_product_type', ['fk_id_ent' => 1, 'product_type_name' => 'Lighting', 'iso_product_type' => 'LGT', 'f_active' => 1]);
        $ch = RecordService::labelChanges('t_product_vendor', ['fk_id_product_type' => [null, (string) $ty]]);
        $this->assertSame([null, 'Lighting'], $ch['fk_id_product_type']);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PH-1', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-PH-1', 'po_number' => 'PO-PH-1', 'po_date' => '2026-11-01', 'po_total' => 50, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $this->f('t_src_po_lines', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_product' => $p, 'sku' => 'PH-1', 'qty_ordered' => 20, 'unit_price' => 2.5, 'line_total' => 50, 'f_active' => 1]);
        $this->f('fin_lots', ['company_id' => 1, 'fk_id_product' => $p, 'sku' => 'PH-1', 'source' => 'OPENING', 'received_date' => '2026-11-02', 'qty' => 10, 'qty_left' => 10, 'unit_cost_gbp' => 3, 'lot_code' => 'LOT-PH-1']);
        $x = $this->getJson("/api/product/$p/purchases")->assertOk()->json();
        $this->assertSame(['PO-PH-1', 20], [$x['pos'][0]['no'], (int) $x['pos'][0]['qty']]); $this->assertSame('LOT-PH-1', $x['lots'][0]['lot']); $this->assertEquals(3, $x['summary']['avg_cost']);
        $this->assertArrayHasKey('field_labels', $this->getJson('/api/page/t_product_vendor/' . $p)->assertOk()->json());
    }

    /** R-F_SETUP-50: the company marked main is mirrored into the tenant row; only one main per tenant. */
    public function test_f50_tenant_row_mirrors_the_main_company(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $a = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Alpha Main Ltd', 'iso_code' => 'AML', 'registration_no' => '11111111', 'company_address' => '1 High St', 'f_tenant_main' => 1, 'f_active' => 1]);
        RecordService::tenantMirror($a); $this->assertSame(['Alpha Main Ltd', 'AML'], [DB::table('t_enterprise')->where('id', 1)->value('ent_name'), DB::table('t_enterprise')->where('id', 1)->value('iso_ent')]);
        $b = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Beta Main Ltd', 'iso_code' => 'BML', 'registration_no' => '22222222', 'f_tenant_main' => 1, 'f_active' => 1]);
        RecordService::tenantMirror($b);
        $this->assertSame(0, (int) DB::table('cat_company_house')->where('id', $a)->value('f_tenant_main'), 'the mark moves'); $this->assertSame('Beta Main Ltd', DB::table('t_enterprise')->where('id', 1)->value('ent_name'));
    }

    /** MR-0089: a short link of a record – not signed in → the sign-in, then the record; signed in → the record at once. */
    /** Owner 27-11: Share to MoreOrderz from WhatsApp / gallery / e-mail – the app is a share target; without the phone app nothing is kept. */
    public function test_share_target_in_the_manifest_and_a_safe_fallback(): void
    {
        $m = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame(['/share-in', 'POST', 'multipart/form-data'], [$m['share_target']['action'], $m['share_target']['method'], $m['share_target']['enctype']]);
        $this->assertStringContainsString("'/share-in'", (string) file_get_contents(public_path('sw.js')));
        $this->post('/share-in', ['text' => 'hello'])->assertRedirect('/#/import?share=missed');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('mo_intake')->where('source', 'SHARE')->count());
    }

    public function test_short_link_opens_the_record_after_sign_in(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-SL-1', 'po_number' => 'PO-SL-1', 'po_date' => '2026-11-01', 'po_total' => 1, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $r = $this->getJson('/api/short-link?url=' . urlencode("#/po/$po"))->assertOk()->json();
        $path = parse_url($r['url'], PHP_URL_PATH); $this->assertMatchesRegularExpression('~^/r/\d+$~', $path); $this->assertStringContainsString((string) $po, $r['opens']);
        $this->get($path)->assertRedirect('/' . $r['opens']);
        auth()->logout(); $this->get($path)->assertRedirect('/login');
        $this->getJson('/api/short-link?url=' . urlencode('#/home'))->assertStatus(401);
    }
}
