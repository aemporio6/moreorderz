<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\FormAssist;
use App\Services\FormRules;
use App\Services\FormStandard;
use App\Services\RecordService;
use App\Services\Registry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The owner's form standard (21 Nov 2026) over EVERY form and EVERY dropdown field. */
class FormStandardTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_every_dropdown_of_every_form_is_central_and_has_add_or_a_stated_reason(): void
    {
        [$rows, $sum] = FormStandard::audit();
        $this->assertGreaterThan(1500, $sum['fields']);
        $this->assertSame([], array_values(array_map(fn ($r) => $r['form'] . '.' . $r['field'] . ' ' . $r['source'], array_filter($rows, fn ($r) => ! $r['central']))), 'no list kept inside a form');
        $this->assertSame([], array_values(array_map(fn ($r) => $r['form'] . '.' . $r['field'], array_filter($rows, fn ($r) => $r['add'] === 'none'))), 'every dropdown has + Add, or is fixed / made by the system with a reason');
        foreach ($rows as $r) if (in_array($r['add'], ['fixed', 'system'], true)) $this->assertNotEmpty($r['why'], $r['form'] . '.' . $r['field']);
        $this->assertSame(0, DB::table('sys_registry')->where('source', 'inline')->count());
        // every form's definition can be drawn, and its dropdowns carry what the screen needs
        foreach (DB::table('sys_registry')->distinct()->pluck('form_key') as $form) {
            $def = Registry::definition($form, 'OWNER');
            foreach ($def['sections'] as $s) foreach ($s['fields'] as $f) if (in_array($f['type'], ['select', 'multiselect'], true)) {
                $this->assertTrue($f['has_options'], "$form.{$f['name']}");
                $a = collect($rows)->first(fn ($r) => $r['form'] === $form && $r['field'] === $f['name']);
                if ($a && ! in_array($a['add'], ['fixed', 'system'], true) && ! $f['readonly']) $this->assertTrue((bool) ($f['add_form'] || $f['add_inline']), "$form.{$f['name']} shows + Add");
            }
        }
    }

    public function test_fixed_lists_take_no_new_value_and_open_lists_do(): void
    {
        $fixed = DB::table('sys_field_lists')->where('f_fixed', 1)->value('list_code'); $f = DB::table('sys_registry')->where('source', 'list:' . $fixed)->first();
        try { FormAssist::addOption($f->form_key, $f->field_name, 'Something new'); $this->fail('a fixed list takes no value'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('fixed by the system', $e->getMessage()); }
        $this->assertNull(FormAssist::addWay((array) $f));
        $open = DB::table('sys_registry')->where('form_key', 'party')->where('field_name', 'emergency_relation')->first();
        $this->assertSame(['inline' => true], FormAssist::addWay((array) $open));
        $r = FormAssist::addOption('party', 'emergency_relation', 'Cousin'); $this->assertSame('Cousin', $r[1]);
        $this->assertContains('Cousin', array_column(Registry::options((array) DB::table('sys_registry')->where('form_key', 'external_contact')->where('field_name', 'emergency_relation')->first(), 1), 1), 'one central list: added once, seen in every form');
    }

    public function test_long_lists_come_in_groups_with_readable_labels_and_narrowed_sources(): void
    {
        $type = (int) DB::table('cat_product_type')->insertGetId(RecordService::fillNotNull('cat_product_type', ['product_type_name' => 'Kitchen FS', 'f_active' => 1]));
        DB::table('t_product_vendors')->insert(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'FS-PAN-1', 'product_name_vendor' => 'Frying pan', 'fk_id_product_type' => $type, 'f_active' => 1]));
        $o = collect(Registry::options(['source' => 'table:t_product_vendors', 'type' => 'select'], 1, 'FS-PAN', 50))->first();
        $this->assertSame(['FS-PAN-1 · Frying pan', 'Kitchen FS'], [$o[1], $o[2]], 'SKU · name, in its product type group');
        $out = Registry::options(['source' => 'table:fin_categories?direction=OUT', 'type' => 'select'], 1, null, 500);
        $this->assertNotEmpty($out); $this->assertSame([], array_values(array_diff(array_column($out, 0), DB::table('fin_categories')->where('direction', 'OUT')->pluck('id')->map(fn ($x) => (string) $x)->all())), 'only OUT categories in an expense');
        $this->assertSame(['direction' => 'OUT'], FormStandard::sourcePreset('table:fin_categories?direction=OUT'), '+ Add from there makes an OUT category');
    }

    public function test_wrong_values_get_a_click_to_fix_suggestion(): void
    {
        $this->assertSame(['ok' => false, 'message' => 'Did you mean ali@gmail.com?', 'suggest' => 'ali@gmail.com'], FormRules::typed('email', 'Ali@Gmial.com'));
        $this->assertSame('https://www.shop.com', FormRules::typed('link', 'www.shop.com')['suggest']);
        $this->assertTrue(FormRules::typed('email', 'ali@gmail.com')['ok']);
        $mail = DB::table('sys_registry')->where('type', 'email')->where('secret', 0)->first();
        $this->assertContains($mail->field_name, FormRules::liveFields($mail->form_key), 'every e-mail field of every form is checked');
        $this->assertSame('a@yahoo.com', $this->getJson("/api/form/{$mail->form_key}/live?" . http_build_query(['field' => $mail->field_name, 'value' => 'a@yahooo.com']))->assertOk()->json('suggest'));
        // a dropdown: nothing matches the typed text → the closest names
        DB::table('cat_banks')->insert(RecordService::fillNotNull('cat_banks', ['fk_id_ent' => 1, 'bank_name' => 'Barclays Standard', 'bank_short_name' => 'BRS', 'f_active' => 1]));
        $f = (array) DB::table('sys_registry')->where('source', 'table:cat_banks')->where('type', 'select')->first();
        $this->assertContains('Barclays Standard', array_column(FormAssist::closest($f, 1, 'Barcleys'), 1));
    }

    public function test_the_create_menu_is_built_from_the_registry_and_a_record_lands_in_its_module(): void
    {
        $c = collect(FormStandard::createCatalogue('OWNER'))->pluck('key')->all();
        $want = DB::table('sys_registry')->whereIn('form_kind', ['module', 'library'])->whereNotNull('table_name')->where('table_name', '<>', '')->distinct()->pluck('form_key')
            ->reject(fn ($k) => DB::table('sys_forms')->where('form_key', $k)->where(fn ($w) => $w->where('f_in_create', 0)->orWhere('f_active', 0))->exists())->values()->all();
        $this->assertSame([], array_values(array_diff($want, $c)), 'every main form appears by itself');
        foreach (['cat_brands', 'cat_company_house', 'fin_category'] as $k) $this->assertContains($k, $c);
        $this->assertNotContains('grn_line', $c, 'a child line is made inside its parent');
        $this->assertSame(count($c), count($this->getJson('/api/boot')->assertOk()->json('create')));
        $id = RecordService::save('fin_category', ['code' => 'office rent fs', 'name' => 'Office rent FS', 'direction' => 'OUT', 'profit_role' => 'FIXED'])['id'];
        $this->assertSame('OFFICE_RENT_FS', DB::table('fin_categories')->where('id', $id)->value('code'), 'saved in its own table');
        $sys = DB::table('fin_categories')->where('f_system', 1)->first();
        try { RecordService::save('fin_category', ['direction' => $sys->direction === 'IN' ? 'OUT' : 'IN'], (int) $sys->id); $this->fail('a system category keeps its direction'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('direction', $e->errors()); }
    }

    public function test_a_person_dropdown_adds_with_its_role_and_the_owner_sets_who_sees_a_field(): void
    {
        $role = (int) DB::table('cat_account_types')->insertGetId(RecordService::fillNotNull('cat_account_types', ['fk_id_ent' => 0, 'account_type_name' => 'Handler FS', 'iso_account_type' => 'HFS' . random_int(10, 99), 'role_code' => 'HANDLERFS', 'f_active' => 1]));
        $this->assertSame(['roles' => (string) $role], FormStandard::partyPreset('party:HANDLERFS,VA'));
        $this->postJson('/api/permissions/field', ['role' => 'VA', 'form_key' => 'party', 'field_name' => 'emergency_relation', 'mode' => 'HIDDEN'])->assertOk();
        $names = fn () => collect(Registry::definition('party', 'VA')['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('name')->all();
        $this->assertSame(1, DB::table('mo_field_rules')->where(['role_code' => 'VA', 'form_key' => 'party', 'field_name' => 'emergency_relation', 'mode' => 'HIDDEN'])->count());
        $this->postJson('/api/permissions/field', ['role' => 'VA', 'form_key' => 'party', 'field_name' => 'emergency_relation', 'mode' => 'DEFAULT'])->assertOk();
        $this->assertSame(0, DB::table('mo_field_rules')->where(['role_code' => 'VA', 'form_key' => 'party', 'field_name' => 'emergency_relation'])->count(), 'Default removes the rule');
    }
}
