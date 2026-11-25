<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\WarehouseCsv;
use App\Services\WarehouseSites;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-6 deactivate never delete (reason, open pick lines, rename → one label) and CSV import / export of locations and SKU homes (check, look-alike, undo). */
class WarehouseCsvTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function up(string $type, string $step, string $csv) { return $this->post("/api/warehouse/csv/$type/$step", ['file' => UploadedFile::fake()->createWithContent('f.csv', $csv)], ['Accept' => 'application/json']); }

    public function test_h6_deactivate_with_reason_and_csv_round_trip(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'WH1', 'location_code' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        WarehouseSites::manage(1, $wh, true);
        $form = RecordService::formOfTable('cat_inventory_locations');
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'A-01-1', 'location_code' => 'A-01-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'fk_id_site' => $wh, 'fk_id_parent' => $wh, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SKU-H6', 'product_name_vendor' => 'H6', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);

        // deactivation: an open pick line stops it, then a reason is asked, then it works; the row stays (never deleted)
        $line = DB::table('wh_pick_task_lines')->insertGetId(RecordService::fillNotNull('wh_pick_task_lines', ['fk_id_pick_task' => 1, 'line_no' => 1, 'fk_id_product' => $p, 'fk_id_from_location' => $bin, 'fk_id_to_location' => $bin, 'qty' => 1, 'line_status' => 'OPEN']));
        $e = $this->postJson("/api/record/$form/$bin", ['values' => ['f_active' => 0, 'deactivated_reason' => 'Shelf removed']])->assertStatus(422)->json('errors');
        $this->assertSame('Finish or release the 1 open pick line first.', collect($e)->flatten()->first());
        DB::table('wh_pick_task_lines')->where('id', $line)->update(['line_status' => 'DONE']);
        $data = ['f_active' => 0]; $old = (array) DB::table('cat_inventory_locations')->where('id', $bin)->first();
        $this->assertSame(['deactivated_reason' => 'Give the reason for deactivating.'], WarehouseSites::check($data, $old, $bin));
        $this->postJson("/api/record/$form/$bin", ['values' => ['f_active' => 0, 'deactivated_reason' => 'Shelf removed']])->assertOk();
        $this->assertSame([0, 'Shelf removed'], [(int) DB::table('cat_inventory_locations')->where('id', $bin)->value('f_active'), DB::table('cat_inventory_locations')->where('id', $bin)->value('deactivated_reason')]);
        $this->postJson("/api/record/$form/$bin", ['values' => ['f_active' => 1]])->assertOk();

        // a site counts what is under it
        Stock::receive(1, [['product' => $p, 'qty' => 3, 'location' => $bin, 'unit_cost' => 1.00]], 'GRN', 1);
        $data = ['f_active' => 0, 'deactivated_reason' => 'Closed']; $site = (array) DB::table('cat_inventory_locations')->where('id', $wh)->first(); $site['system_role'] = null;
        $this->assertSame(['f_active' => 'Move the 3 units first.'], WarehouseSites::check($data, $site, $wh));
        $this->assertTrue(WarehouseSites::hasHistory($bin));

        // export
        $csv = $this->get('/api/warehouse/csv/WH_LOCATIONS')->assertOk()->getContent();
        $this->assertStringContainsString("WH1,,BIN,A-01-1,A-01-1,", $csv);
        $this->assertStringNotContainsString('SYS-', $csv, 'system bins are not in the file');

        // check never writes; look-alike A011 ≈ A-01-1 is held back; a zone and its bin in one file; a bad row is listed
        $before = DB::table('cat_inventory_locations')->count();
        $file = "site,parent,kind,code,name,zone_type,bin_type,pick_seq,capacity_units,pickable,receivable,active,deactivated_reason,confirm_new\n"
            . "WH1,,ZONE,ZB,Zone B,,,5,,Y,Y,Y,,\nWH1,ZB,BIN,B-01-1,,,,6,40,Y,Y,Y,,\nWH1,,BIN,a 1-1,,,,,,,,,,\nWH1,,BIN,A-01-1,Front shelf,,,9,,,,Y,,\nNOPE,,BIN,X1,,,,,,,,,,\n";
        $c = $this->up('WH_LOCATIONS', 'check', $file)->assertOk()->json();
        $this->assertSame([3, 2, $before], [$c['rows'], $c['skipped'], DB::table('cat_inventory_locations')->count()]);
        $this->assertSame('A-01-1', $c['alike'][0]['looks_like']);
        $this->assertStringContainsString('Nothing was saved', $c['message']);

        $jobsBefore = DB::table('print_jobs')->count();
        $r = $this->up('WH_LOCATIONS', 'import', $file)->assertOk()->json();
        $this->assertSame([2, 1], [$r['created'], $r['updated']]);
        $zone = DB::table('cat_inventory_locations')->where('location_code', 'ZB')->first(); $nb = DB::table('cat_inventory_locations')->where('location_code', 'B-01-1')->first();
        $this->assertSame([(int) $zone->id, $wh, 40, 'B-01-1'], [(int) $nb->fk_id_parent, (int) $nb->fk_id_site, (int) $nb->capacity_units, $nb->barcode_value]);
        $this->assertSame('Front shelf', DB::table('cat_inventory_locations')->where('id', $bin)->value('location_name'), 'rename keeps the id');
        $this->assertTrue(DB::table('print_jobs')->count() === $jobsBefore + 1 || collect($r['log'])->contains(fn ($l) => str_contains($l, 'label')), 'rename queues one shelf label (or says why not)');
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 'cat_inventory_locations')->where('record_id', $bin)->where('action', 'UPDATE')->exists(), 'history shows old → new');

        // confirm_new = Y adds the look-alike
        $this->up('WH_LOCATIONS', 'import', "site,kind,code,confirm_new\nWH1,BIN,A1-1,Y\n")->assertOk()->assertJsonPath('created', 1);

        // SKU homes: check, import, export, undo
        $homes = "site,bin,sku,role,min_qty,max_qty\nWH1,B-01-1,SKU-H6,PRIMARY,2,20\nWH1,B-01-1,NOPE,PRIMARY,,\nWH1,B-01-1,SKU-H6,PRIMARY,1,5\n";
        $this->up('WH_SKU_HOMES', 'check', $homes)->assertOk()->assertJsonPath('rows', 1)->assertJsonPath('skipped', 2);
        $this->assertSame(0, DB::table('t_product_locations')->where('fk_id_product', $p)->where('fk_id_location', $nb->id)->count());
        $h = $this->up('WH_SKU_HOMES', 'import', $homes)->assertOk()->json();
        $this->assertSame(['PRIMARY', 2, 20], array_values((array) DB::table('t_product_locations')->where('fk_id_product', $p)->where('fk_id_location', $nb->id)->first(['home_role', 'min_qty', 'max_qty'])));
        $this->assertStringContainsString('WH1,B-01-1,SKU-H6,PRIMARY,2,20', $this->get('/api/warehouse/csv/WH_SKU_HOMES')->assertOk()->getContent());

        // undo of the locations batch: the bin with a home stays (deactivate instead), the rename goes back
        $u = $this->postJson('/api/warehouse/csv/undo/' . $r['job'])->assertOk()->json();
        $this->assertContains('B-01-1', $u['kept']);
        $this->assertSame('A-01-1', DB::table('cat_inventory_locations')->where('id', $bin)->value('location_name'));
        $this->postJson('/api/warehouse/csv/undo/' . $r['job'])->assertStatus(422);
        $this->postJson('/api/warehouse/csv/undo/' . $h['job'])->assertOk()->assertJsonPath('removed', 1);
        $this->assertSame(0, DB::table('t_product_locations')->where('fk_id_product', $p)->where('fk_id_location', $nb->id)->count());
    }
}
