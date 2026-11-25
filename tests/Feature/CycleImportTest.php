<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-14 the rule test: 5 rows, 2 cycle_refs → 2 cycles, 5 products; one unknown supplier → 1 warning, the product saved without supplier. */
class CycleImportTest extends TestCase
{
    public function test_rule_example_check_import_and_undo(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_suppliers')->insert(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-NB1', 'supplier_name' => 'Ningbo Housewares Co', 'f_active' => 1]));
        $csv = "\xEF\xBB\xBFCycle_Ref,cycle_date,priority,status,notes,supplier_name,brand,product_name,variation,sku,quantity,unit_cost,spec_notes\n"
            . "A,2026-12-01,,,,Ningbo,,Mug,Black,,\"1,000\",1.20,\nA,2026-12-01,,,,Ningbo,,Lid,,,1000,0.30,\nA,2026-12-01,,,,Nobody Ltd,,Spoon,,,200,0.10,\n"
            . "B,,High,Open,Spring,Ningbo,,Bowl,,,300,2.00,\nB,,,,,,,Plate,,,300,1.50,\n,,,,,,,Ghost,,,1,1,\n";
        $file = fn () => UploadedFile::fake()->createWithContent('cycles.csv', $csv);
        $before = DB::table('t_src_cycles')->count();
        $c = $this->post('/api/cycles/csv/check', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 5, 1], [$c['cycles'], $c['products'], $c['skipped']]);
        $this->assertSame(["Line 4: supplier 'Nobody Ltd' not found - left empty", 'Line 7 skipped (cycle_ref / product_name empty)'], $c['log']);
        $this->assertSame($before, DB::table('t_src_cycles')->count(), 'the check writes nothing');
        $r = $this->post('/api/cycles/csv/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('Done: 2 cycles, 5 products created.', $r['message']);
        $a = DB::table('t_src_cycles')->where('order_ref', 'A')->first();
        $this->assertSame(['FULL', 'Draft', 'Normal', '2026-12-01'], [$a->cycle_type, $a->status, $a->priority, substr((string) $a->sourcing_date, 0, 10)]);
        $p = DB::table('t_src_cycle_products')->where('fk_id_cycle', $a->id)->orderBy('id')->get();
        $this->assertSame([1000.0, true, null], [(float) $p[0]->target_qty, $p[0]->fk_id_supplier !== null, $p[2]->fk_id_supplier], 'commas removed; unknown supplier left empty');
        $this->assertSame('High', DB::table('t_src_cycles')->where('order_ref', 'B')->value('priority'));
        $this->post('/api/cycles/csv/check', ['file' => UploadedFile::fake()->createWithContent('x.csv', "cycle_ref,sku\nA,1\n")], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Missing required column: product_name');
        $u = $this->postJson("/api/cycles/csv/{$r['job']}/undo")->assertOk()->json();
        $this->assertSame([2, $before], [$u['removed'], DB::table('t_src_cycles')->count()]);
    }
}
