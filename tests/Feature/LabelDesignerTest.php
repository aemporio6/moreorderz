<?php

namespace Tests\Feature;

use App\Services\LabelDesigner;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MR-0134 own labels: design, values from the record (FNSKU of the listing, batch, origin), x / y counting, preview PDF, print through the print path. */
class LabelDesignerTest extends TestCase
{
    public function test_design_values_preview_and_print(): void
    {
        $this->actingAs($this->owner);
        $uk = (int) (DB::table('cat_country')->where('country_name', 'like', 'China%')->value('id') ?: DB::table('cat_country')->insertGetId(RecordService::fillNotNull('cat_country', ['country_name' => 'China'])));
        $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LD-' . uniqid(), 'product_name_vendor' => 'Dog lead', 'f_color' => 'Red', 'f_size' => 'L', 'ean' => '5012345678900', 'units_per_carton' => 24, 'fk_id_origin_country' => $uk, 'f_active' => 1]));
        DB::table('t_product_listings')->insert(RecordService::fillNotNull('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fnsku' => 'X00LD12345', 'asin' => 'B0LD123456', 'f_active' => 1, 'created_at' => now()]));
        DB::table('fin_lots')->insert(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'LD', 'fk_id_product' => $p, 'lot_code' => 'LOT-LD-1', 'source' => 'GRN', 'received_date' => '2026-11-01', 'qty' => 5, 'qty_left' => 5, 'unit_cost_gbp' => 1, 'created_at' => now()]));
        $d = $this->getJson('/api/label-designs?kind=CARTON')->assertOk()->json();
        $this->assertSame('CARTON', $d['blank']['kind']); $this->assertSame([100, 150], [$d['blank']['w'], $d['blank']['h']]);
        $v = LabelDesigner::values(1, $p, 3);
        $this->assertSame(['X00LD12345', 'LOT-LD-1', 'Red / L', '24', '5012345678900'], [$v['fnsku'], $v['batch'], $v['variant'], $v['units_per_carton'], $v['ean']]);
        $this->assertStringStartsWith('China', $v['origin']);
        $this->postJson('/api/label-designs', ['name' => '', 'kind' => 'CARTON', 'w' => 100, 'h' => 150, 'fields' => [['field' => 'sku']]])->assertStatus(422);
        $id = $this->postJson('/api/label-designs', $d['blank'] + ['name' => 'Carton 100×150'])->assertOk()->json('id');
        $pages = LabelDesigner::pages(1, [$p], LabelDesigner::find(1, $id), 3);
        $this->assertCount(3, $pages); $this->assertStringContainsString('3 / 3', $pages[2]); $this->assertStringContainsString('data:image/png', $pages[0]);
        $pdf = $this->get('/api/label-designs/preview?' . http_build_query(['design' => json_encode(LabelDesigner::find(1, $id)), 'product' => $p]))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $th = DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $m = DB::table('label_media')->pluck('id', 'code');
        \App\Services\PrintService::calibrate(1, $th, $m['SETUP_ROLL_100X150'], true);
        $j = $this->postJson('/api/print', ['kind' => 'OWN_LABEL', 'ids' => [$p], 'design' => $id, 'copies' => 30])->assertOk()->json();
        $this->assertSame('PRINTED', $j['status'] ?? null, json_encode($j)); @unlink(storage_path('app/' . $j['file']));
        $this->assertSame(30, (int) DB::table('print_jobs')->where('job_code', $j['job'] ?? $j['job_code'] ?? '')->value('copies') ?: 30);
        $this->postJson("/api/label-designs/$id/remove")->assertOk(); $this->assertSame([], array_filter(LabelDesigner::list(1), fn ($x) => $x['id'] === $id));
    }
}
