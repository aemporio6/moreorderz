<?php

namespace Tests\Feature;

use App\Services\CartonCalc;
use App\Services\Choices;
use App\Services\FormRules;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-34 (carton figures) and RULES_G_CORE R-G_CORE-91 (typed HS / ISP codes, computed carton CBM). */
class CartonCalcTest extends TestCase
{
    private array $rule = ['total_planned_units' => 1030, 'units_per_carton' => 40, 'carton_l' => 50, 'carton_w' => 40, 'carton_h' => 30, 'unit_gross_weight' => 0.28, 'carton_tare_weight' => 0.8, 'actual_carton_gross_weight' => 0];

    public function test_the_rule_example(): void
    {
        $f = CartonCalc::calc($this->rule);
        $this->assertSame([25, 30, 26, 12.0, 312.0, 1.56], [$f['full_cartons'], $f['loose_units'], $f['carton_qty'], $f['carton_gross_weight'], $f['total_gross_weight'], $f['total_cbm']]);
        $f = CartonCalc::calc(['actual_carton_gross_weight' => 12.4] + $this->rule); $this->assertSame(322.4, $f['total_gross_weight']);      // a weighed carton replaces the calculated weight
        $f = CartonCalc::calc(['unit_l' => 20, 'unit_w' => 10, 'unit_h' => 5, 'unit_net_weight' => 0.25] + $this->rule); $this->assertSame([0.001, 0.2, 10.0, 257.5], [$f['unit_cbm'], $f['volumetric_weight'], $f['carton_net_weight'], $f['total_net_weight']]);
    }

    public function test_divisor_per_carrier_and_mode(): void
    {
        $this->assertSame(5000.0, CartonCalc::divisor(1)); Settings::set('VOLUMETRIC_DIVISOR', '-1', 1); $this->assertSame(5000.0, CartonCalc::divisor(1));      // ≤ 0 → 5000
        Settings::set('VOLUMETRIC_DIVISOR_AIR', '6000', 1); $this->assertSame(6000.0, CartonCalc::divisor(1, 'Air'));
        $c = DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', ['carrier_name' => 'DHL', 'volumetric_divisor' => 4000])); $this->assertSame(4000.0, CartonCalc::divisor(1, 'Air', $c));
    }

    public function test_the_carton_form_saves_the_calculated_figures_and_prints_marks(): void
    {
        $this->actingAs($this->owner); $key = RecordService::formOfTable('t_src_cartons');
        $id = RecordService::save($key, $this->rule + ['sku' => 'LAMP-1', 'unit_net_weight' => 0.25, 'full_cartons' => 999, 'total_cbm' => 9])['id'];      // typed figures for calculated fields are ignored
        $r = DB::table('t_src_cartons')->where('id', $id)->first();
        $this->assertSame([25.0, 30.0, 26.0, 312.0, 0.06, 1.56], [(float) $r->full_cartons, (float) $r->loose_units, (float) $r->carton_qty, (float) $r->total_gross_weight, (float) $r->carton_cbm, (float) $r->total_cbm]);
        RecordService::save($key, ['actual_carton_gross_weight' => 12.4], $id); $this->assertSame(322.4, (float) DB::table('t_src_cartons')->where('id', $id)->value('total_gross_weight'));
        $this->assertSame(1.56, FormRules::calc($key, $this->rule)['total_cbm']);      // the live figures while typing
        $m = CartonCalc::marks(1, $id); $this->assertCount(26, $m); $this->assertSame([1, 26, 40, 30], [$m[0]['n'], $m[0]['of'], $m[0]['units'], $m[25]['units']]); $this->assertSame([12.4, 0.06], [$m[0]['gross'], $m[0]['cbm']]);
        $def = \App\Services\Registry::definition($key, 'OWNER'); $ro = collect($def['sections'])->flatMap(fn ($s) => $s['fields'])->where('readonly', true)->pluck('name')->all();
        foreach (['full_cartons', 'carton_qty', 'total_cbm', 'carton_cbm'] as $f) $this->assertContains($f, $ro);
    }

    public function test_typed_hs_and_isp_codes_and_carton_cbm(): void
    {
        $this->actingAs($this->owner); $key = RecordService::formOfTable('t_src_products');
        $pv = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LMP', 'f_active' => 1]));
        $id = RecordService::save($key, ['product_name' => 'Lamp', 'fk_id_product' => $pv, 'hs_code' => '3924.90.00', 'carton_l' => 40, 'carton_w' => 30, 'carton_h' => 25])['id'];
        $r = DB::table('t_src_products')->where('id', $id)->first(); $this->assertSame(['39249000', 0.03], [$r->hs_code, (float) $r->carton_cbm]);      // L 40, W 30, H 25 → 0.0300
        try { RecordService::save($key, ['hs_code' => '12AB'], $id); $this->fail(); } catch (ValidationException $e) { $this->assertStringContainsString('6 to 10 digits', $e->errors()['hs_code'][0]); }
        $isp = RecordService::formOfTable('cat_ip_burgers');
        try { RecordService::save($isp, ['ip_burger_ip' => '1.2.3.4', 'isp_code' => 'ab-12']); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('isp_code', $e->errors()); }
        $i = RecordService::save($isp, ['ip_burger_ip' => '1.2.3.4', 'isp_code' => 'ab 12'])['id']; $this->assertSame('AB12', DB::table('cat_ip_burgers')->where('id', $i)->value('isp_code'));
        Choices::set('TYPED_CODE_CHECKS', 'OLD', 1); RecordService::save($key, ['hs_code' => '12AB'], $id); $this->assertSame('12AB', DB::table('t_src_products')->where('id', $id)->value('hs_code'));
        DB::table('t_src_products')->where('id', $id)->update(['category' => 'Lamps', 'hs_code' => '94052000']); $this->assertSame('94052000', \App\Services\FormAssist::chips($key, 'hs_code', ['category' => 'Lamps'])[0]['value']);      // suggestion from the same category
        Choices::set('TYPED_CODE_CHECKS', 'STRICT', 1); $l = FormRules::live($key, 'hs_code', '12', null); $this->assertSame([false, 'HS code must be 6 to 10 digits (dots and spaces are removed), for example 3924.90.00.'], [$l['ok'], $l['message']]);      // the live check while typing
    }
}
