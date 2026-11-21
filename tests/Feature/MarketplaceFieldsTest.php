<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\MarketplaceFields;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-67: marketplace field builder – slug name fixed on edit, types, select options, soft delete keeps values, product values per tab. */
class MarketplaceFieldsTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_rename_bullet_1_keeps_field_name_and_values(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); \Illuminate\Support\Facades\Storage::fake('local');
        $amz = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amazon', 'f_active' => 1]);
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'MF-1', 'product_name_vendor' => 'Field item', 'f_active' => 1]);

        $this->getJson("/api/mp-fields/name?mp=$amz&label=" . urlencode('Bullet 1'))->assertOk()->assertJsonPath('name', 'amazon_bullet_1');
        $b1 = $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Bullet 1', 'field_type' => 'text'])->assertOk()->assertJsonPath('field_name', 'amazon_bullet_1')->json('id');
        $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Bullet 1!', 'field_type' => 'text'])->assertJsonPath('field_name', 'amazon_bullet_1_2', 'taken → numeric suffix');
        $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => '', 'field_type' => 'text'])->assertStatus(422)->assertJsonPath('errors.field_label.0', 'Field label is required');
        $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Colour', 'field_type' => 'select', 'field_options' => ''])->assertStatus(422)->assertJsonPath('errors.field_options.0', 'provide options for the Select field');
        $col = $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Colour', 'field_type' => 'select', 'field_options' => "Red\nBlue\n\nRed"])->assertOk()->json('id');
        $this->assertSame('["Red","Blue"]', DB::table('cat_marketplace_fields')->where('id', $col)->value('field_options'), 'one per line → JSON, no blanks or repeats');
        $this->assertSame('["S","M"]', MarketplaceFields::options('S, M'), 'comma-separated');
        $gift = $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Gift wrap', 'field_type' => 'checkbox'])->json('id');
        $price = $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'List price', 'field_type' => 'decimal', 'f_required' => 1])->json('id');
        $sheet = $this->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'Safety sheet', 'field_type' => 'file'])->json('id');

        // product values, saved per tab
        $this->postJson("/api/products/$prod/mp-fields/$amz", ['values' => [$b1 => 'Soft cotton', $col => 'Green']])->assertStatus(422)->assertJsonPath("errors.$col.0", 'Colour: choose one of the list');
        $this->postJson("/api/products/$prod/mp-fields/$amz", ['values' => [$b1 => 'Soft cotton', $col => 'Blue', $gift => '']])->assertStatus(422)->assertJsonPath("errors.$price.0", 'List price is required');
        $pdf = UploadedFile::fake()->create('sheet.pdf', 10, 'application/pdf');
        $this->post("/api/products/$prod/mp-fields/$amz", ['values' => [$b1 => 'Soft cotton', $col => 'Blue', $gift => '', $price => '12.5'], 'files' => [$sheet => $pdf]], ['Accept' => 'application/json'])->assertOk();
        $v = DB::table('t_product_marketplace_values')->where('fk_id_product', $prod)->pluck('field_value', 'fk_id_field');
        $this->assertSame(['Soft cotton', 'Blue', '0', '12.50'], [$v[$b1], $v[$col], $v[$gift], $v[$price]], 'checkbox hidden 0, decimal 0.01');
        $this->assertStringStartsWith('uploads/marketplace_files/', (string) $v[$sheet]); $file = $v[$sheet];
        $this->postJson("/api/products/$prod/mp-fields/$amz", ['values' => [$price => '13']])->assertOk();
        $this->assertSame($file, DB::table('t_product_marketplace_values')->where('fk_id_field', $sheet)->value('field_value'), 'no new file chosen → the current file is kept');
        $this->assertSame('Soft cotton', DB::table('t_product_marketplace_values')->where('fk_id_field', $b1)->value('field_value'), 'a field not sent keeps its value');

        // the rule example: rename "Bullet 1" → "Bullet point 1" → field_name stays amazon_bullet_1, the values are still shown
        $this->postJson("/api/mp-fields/$b1", ['field_label' => 'Bullet point 1'])->assertOk()->assertJsonPath('field_name', 'amazon_bullet_1');
        $tab = collect($this->getJson("/api/products/$prod/mp-fields")->assertOk()->json('tabs'))->firstWhere('id', $amz);
        $f1 = collect($tab['fields'])->firstWhere('id', $b1);
        $this->assertSame(['Bullet point 1', 'amazon_bullet_1', 'Soft cotton'], [$f1['label'], $f1['name'], $f1['value']]);
        $this->assertSame('Soft cotton', MarketplaceFields::exportColumns($amz, [$prod])[$prod]['amazon_bullet_1'], 'feeds the channel export');

        // soft delete keeps the product values
        $this->postJson("/api/mp-fields/$col/delete")->assertOk();
        $this->assertSame(0, (int) DB::table('cat_marketplace_fields')->where('id', $col)->value('f_active'));
        $this->assertSame('Blue', DB::table('t_product_marketplace_values')->where('fk_id_field', $col)->value('field_value'));
        $this->assertNull(collect($this->getJson("/api/mp-fields?mp=$amz")->json('fields'))->firstWhere('id', $col));

        // the generic form follows the same rule
        $this->postJson("/api/record/marketplace_field/$b1", ['values' => ['field_label' => 'Bullet one', 'field_name' => 'hacked']])->assertOk();
        $this->assertSame('amazon_bullet_1', DB::table('cat_marketplace_fields')->where('id', $b1)->value('field_name'));
        // rights
        $this->actingAs($this->makeUser('VA'))->postJson('/api/mp-fields', ['fk_id_marketplace' => $amz, 'field_label' => 'X', 'field_type' => 'text'])->assertStatus(403);
        $this->actingAs($this->makeUser('WAREHOUSE'))->getJson("/api/products/$prod/mp-fields")->assertStatus(403);
    }
}
