<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** R-D-13 coloured tags: one multi-select Tags field, Tag 1–3 kept in step, coloured chips + tag filter, colour picker / hex check. */
class TagChipsTest extends TestCase
{
    public function test_d13_fragile_red_chip_filter_and_tag_1_3_in_step(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $fr = RecordService::save('cat_tags', ['tag_name' => 'Fragile', 'tag_color' => 'DC3545', 'f_active' => 1])['id'];
        $this->assertSame('#dc3545', DB::table('cat_tags')->where('id', $fr)->value('tag_color'), 'hex kept as #rrggbb');
        try { RecordService::save('cat_tags', ['tag_name' => 'Bad', 'tag_color' => 'red', 'f_active' => 1]); $this->fail('not a hex colour'); } catch (ValidationException $e) { $this->assertStringContainsString('#rrggbb', json_encode($e->errors())); }
        // R-D-69: "fragile " when "Fragile" exists → refused; names Title Case; brand "acme" → "Acme", unique per company
        try { RecordService::save('cat_tags', ['tag_name' => 'fragile ', 'tag_color' => '#000000', 'f_active' => 1]); $this->fail('duplicate tag'); } catch (ValidationException $e) { $this->assertSame('Tag name already exist', $e->errors()['tag_name'][0]); }
        $g = RecordService::save('cat_tags', ['tag_name' => '  big  box ', 'tag_color' => '#00aa00', 'f_active' => 1])['id']; $this->assertSame('Big Box', DB::table('cat_tags')->where('id', $g)->value('tag_name'));
        try { RecordService::save('cat_tags', ['tag_name' => 'No Colour', 'f_active' => 1]); $this->fail('colour required'); } catch (ValidationException $e) { $this->assertArrayHasKey('tag_color', $e->errors()); }
        $ch = (int) DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Acme Holdings Ltd']));
        $br = RecordService::save('cat_brands', ['brand_name' => ' acme', 'fk_id_company_house' => $ch, 'f_active' => 1])['id']; $this->assertSame('Acme', DB::table('cat_brands')->where('id', $br)->value('brand_name'));
        try { RecordService::save('cat_brands', ['brand_name' => 'ACME', 'fk_id_company_house' => $ch, 'f_active' => 1]); $this->fail('brand twice'); } catch (ValidationException $e) { $this->assertStringContainsString('already exists for this company', $e->errors()['brand_name'][0]); }
        $t = []; foreach (['Heavy', 'Gift', 'Seasonal'] as $n) $t[] = (int) DB::table('cat_tags')->insertGetId(RecordService::fillNotNull('cat_tags', ['fk_id_ent' => 1, 'tag_name' => $n, 'tag_color' => '#123456', 'f_active' => 1]));
        $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TAG-13', 'product_name_vendor' => 'Glass vase', 'f_active' => 1, 'e_product_type' => 'pool']));
        $form = RecordService::formOfTable('t_product_vendors');
        RecordService::save($form, ['tag_ids' => [$fr, $t[0], $t[1], $t[2]]], $p);
        $row = DB::table('t_product_vendors')->where('id', $p)->first();
        $this->assertSame([implode(',', [$fr, $t[0], $t[1], $t[2]]), $fr, $t[0], $t[1]], [$row->tag_ids, (int) $row->f_tag1, (int) $row->f_tag2, (int) $row->f_tag3], 'Tag 1–3 = the first three tags');
        // the list: chip painted in the tag colour; filter Tag = Fragile shows the product
        $r = $this->getJson("/api/products?per=50&q=TAG-13&tag=$fr")->assertOk()->json();
        $this->assertSame([$p], array_column($r['rows'], 'id'));
        $this->assertSame(['name' => 'Fragile', 'color' => '#dc3545'], array_intersect_key($r['rows'][0]['tags'][0], array_flip(['name', 'color'])));
        $this->assertSame([], $this->getJson('/api/products?per=50&q=TAG-13&tag=' . ($t[2] + 1000))->json('rows'));
        $this->assertSame('#dc3545', collect($r['tag_list'])->firstWhere('id', $fr)['color']);
        // the dropdown paints each option in its colour
        $o = $this->getJson("/api/options/$form/" . rawurlencode("tags[]"))->assertOk()->json(); $this->assertSame('#dc3545', $o['colors'][(string) $fr] ?? null);
    }
}
