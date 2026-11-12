<?php

namespace Tests\Feature;

use App\Services\DispatchService as Dispatch;
use App\Services\LocationGenerator;
use App\Services\PrintService as Prints;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrintTest extends TestCase
{
    private int $th; private int $a4; private int $roll; private int $roll96; private int $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->th = DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $this->a4 = DB::table('printer_profiles')->where('code', 'SETUP_A4_1')->value('id');
        $m = DB::table('label_media')->pluck('id', 'code');
        [$this->roll, $this->roll96, $this->plain] = [$m['SETUP_ROLL_100X150'], $m['SETUP_ROLL_96X136'], $m['SETUP_A4_PLAIN']];
    }

    private function pass(int $printer, int $media): void { Prints::calibrate(1, $printer, $media, true); }

    /** Page sizes in mm read from the PDF itself. */
    private function pages(string $file): array
    {
        $pdf = file_get_contents(storage_path('app/' . $file));
        $this->assertStringStartsWith('%PDF', $pdf);
        preg_match_all('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m, PREG_SET_ORDER);
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $n);
        return ['count' => count($n[0]), 'w' => round($m[0][1] * 25.4 / 72, 1), 'h' => round($m[0][2] * 25.4 / 72, 1)];
    }

    private function parcel(): array
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'location_code' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $car = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier A', 'f_active' => 1]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fk_id_default_carrier' => $car, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $bin, 'unit_cost' => 4]], 'GRN', 1);
        $sale = Sales::intake($shop, [['order_no' => 'ORD-1', 'd_order' => date('Y-m-d'), 'sku' => 'BOWL-01', 'qty' => 2, 'sale_rate' => 12.99, 'cust_name' => 'Private Buyer Name', 'ship_to_address' => '1 Secret Street', 'tracking_no' => 'TRK1']])['ids'][0];
        $task = Dispatch::buildPickList(1);
        foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->pluck('id') as $l) Dispatch::pick($l);
        $parcel = Dispatch::pack(['branch' => $shop, 'order_no' => 'ORD-1', 'tracking_no' => 'TRK1', 'carrier' => $car]);
        return ['parcel' => (int) $parcel, 'task' => (int) $task, 'bin' => $bin, 'carrier' => $car];
    }

    public function test_setup_rows_route_each_job_kind_and_a_job_needs_the_test_page_first(): void
    {
        $this->assertSame($this->th, (int) Prints::route(1, 'PACKING_SLIP')->fk_id_printer_profile);
        $this->assertSame($this->a4, (int) Prints::route(1, 'PICK_SHEET')->fk_id_printer_profile);
        $x = $this->parcel();
        try { Prints::print(1, 'PACKING_SLIP', [$x['parcel']]); $this->fail('must ask for the test page'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('test page', $e->getMessage()); }
        $this->assertSame(0, DB::table('print_jobs')->count(), 'a refused job leaves nothing behind');
        $t = Prints::testPage(1, $this->th, $this->roll);
        $this->assertSame('PRINTED', $t['status']);
        $this->assertSame(['count' => 1, 'w' => 100.0, 'h' => 150.0], $this->pages($t['file']));
        $this->assertSame(0, DB::table('label_print_log')->count(), 'a test page is not a label');
        $this->assertFalse(Prints::tested($this->th, $this->roll), 'printing the test page is not passing it');
        $this->pass($this->th, $this->roll);
        $this->assertSame('PRINTED', Prints::print(1, 'PACKING_SLIP', [$x['parcel']])['status']);
    }

    public function test_packing_slip_is_made_at_the_exact_size_of_the_loaded_label_without_the_buyer_address(): void
    {
        $x = $this->parcel(); $this->pass($this->th, $this->roll); $this->pass($this->th, $this->roll96);
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['copies' => 2]);
        $this->assertSame(['count' => 2, 'w' => 100.0, 'h' => 150.0], $this->pages($j['file']));
        $log = DB::table('label_print_log')->where('fk_id_print_job', $j['job'])->orderBy('copy_no')->get();
        $this->assertSame([1, 2], $log->pluck('copy_no')->map(fn ($v) => (int) $v)->all(), 'one log row per copy');
        $this->assertSame([$x['parcel'], 'PARCEL', null], [(int) $log[0]->fk_id_parcel, $log[0]->entity_type, $log[0]->reprint_reason]);
        $html = (new \ReflectionMethod(Prints::class, 'pSlip'))->invoke(null, 1, $x['parcel'], 100.0);
        $this->assertStringNotContainsString('Secret Street', $html); $this->assertStringNotContainsString('Private Buyer', $html);
        Settings::set('WH_PACKSLIP_SHOW_ADDRESS', 'Y', 1);
        $this->assertStringContainsString('Secret Street', (new \ReflectionMethod(Prints::class, 'pSlip'))->invoke(null, 1, $x['parcel'], 100.0));
        // same job on the other roll: a setting (route), not a rebuild
        Prints::setRoute(1, 'PACKING_SLIP', $this->th, $this->a4, $this->roll96);
        $j2 = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'DAMAGED_LABEL']);
        $this->assertSame(['count' => 1, 'w' => 96.0, 'h' => 136.0], $this->pages($j2['file']));
    }

    public function test_a_second_print_needs_a_reason_and_copy_numbers_go_on(): void
    {
        $x = $this->parcel(); $this->pass($this->th, $this->roll);
        Prints::print(1, 'PACKING_SLIP', [$x['parcel']]);
        try { Prints::print(1, 'PACKING_SLIP', [$x['parcel']]); $this->fail('reason needed'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        try { Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'BECAUSE']); $this->fail('reason from the list'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('list', $e->getMessage()); }
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'PRINTER_JAM']);
        $row = DB::table('label_print_log')->where('fk_id_print_job', $j['job'])->first();
        $this->assertSame([2, 'PRINTER_JAM'], [(int) $row->copy_no, $row->reprint_reason]);
        $again = Prints::reprint(1, $j['job'], 'DAMAGED_LABEL');
        $this->assertSame(3, (int) DB::table('label_print_log')->where('fk_id_print_job', $again['job'])->value('copy_no'));
        $this->assertSame(3, DB::table('print_jobs')->where('status', 'PRINTED')->count());
    }

    public function test_printer_in_repair_asks_then_uses_the_backup_and_without_a_backup_the_job_fails_with_a_task(): void
    {
        $x = $this->parcel(); $this->pass($this->th, $this->roll); $this->pass($this->a4, $this->plain); $this->pass($this->a4, $this->roll);
        DB::table('printer_profiles')->where('id', $this->th)->update(['printer_status' => 'REPAIR']);
        $ask = Prints::print(1, 'PACKING_SLIP', [$x['parcel']]);
        $this->assertSame($this->a4, $ask['ask']['backup_id']);
        $this->assertSame(0, DB::table('print_jobs')->count(), 'asking prints nothing');
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['use_backup' => true]);
        $this->assertSame('PRINTED', $j['status']);
        $this->assertSame($this->a4, (int) DB::table('print_jobs')->where('id', $j['job'])->value('fk_id_printer_profile'));
        $this->assertStringContainsString('backup', $j['note']);
        Settings::set('PRINT_FAILOVER', 'AUTO', 1);
        $this->assertSame('PRINTED', Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'OTHER'])['status']);
        // backup out as well → FAILED, no log row, one task per printer per day
        DB::table('printer_profiles')->where('id', $this->a4)->update(['printer_status' => 'OFFLINE']);
        $logs = DB::table('label_print_log')->count();
        $f = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'OTHER']);
        $this->assertSame('FAILED', $f['status']);
        $this->assertSame($logs, DB::table('label_print_log')->count());
        $this->assertTrue(TaskEngine::isOpen('WH_PRINT_FAILED', [$this->th, date('Y-m-d')]));
        Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'OTHER']);
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'WH_PRINT_FAILED')->count(), 'no second task for the same printer and day');
        $this->assertSame(1, DB::table('sys_events')->where('event_code', 'PRINT_FAILED')->where('entity_id', $f['job'])->count());
        // printer back → the next good job closes the task
        DB::table('printer_profiles')->where('id', $this->th)->update(['printer_status' => 'ACTIVE']);
        Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'OTHER']);
        $this->assertFalse(TaskEngine::isOpen('WH_PRINT_FAILED', [$this->th, date('Y-m-d')]));
    }

    public function test_bin_labels_one_job_for_all_new_bins_on_a_sheet_with_start_cell_or_one_per_roll_label(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $wh = $f('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->pass($this->a4, $this->plain); $this->pass($this->th, $this->roll);
        $ids = LocationGenerator::run(1, ['parent' => $wh, 'zone' => 'A', 'levels' => 5, 'positions' => 8], true)['ids'];
        $this->assertCount(40, $ids);
        $j = Prints::auto(1, 'WH_AUTOPRINT_SHELF', 'LOCATION', $ids, 'AUTO_SHELF');
        $this->assertSame(['PRINTED', 40], [$j['status'], $j['labels']]);
        $this->assertSame(['count' => 2, 'w' => 210.0, 'h' => 297.0], $this->pages($j['file']), '40 labels at 24 per sheet = 2 sheets');
        $this->assertSame(40, DB::table('label_print_log')->where('fk_id_print_job', $j['job'])->count());
        $this->assertSame('AUTO_SHELF', DB::table('print_jobs')->where('id', $j['job'])->value('print_trigger'));
        try { Prints::print(1, 'LOCATION', [$ids[0]]); $this->fail('reason needed'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        $one = Prints::print(1, 'LOCATION', array_slice($ids, 0, 3), ['reason' => 'LOCATION_CHANGED', 'start_cell' => 23]);
        $this->assertSame(2, $this->pages($one['file'])['count'], 'cells 23 and 24, then a new sheet');
        $rollJob = Prints::print(1, 'LOCATION', array_slice($ids, 0, 3), ['reason' => 'OTHER', 'printer' => $this->th, 'media' => $this->roll]);
        $this->assertSame(['count' => 3, 'w' => 100.0, 'h' => 150.0], $this->pages($rollJob['file']));
        Settings::set('WH_AUTOPRINT_SHELF', 'N', 1);
        $this->assertNull(Prints::auto(1, 'WH_AUTOPRINT_SHELF', 'LOCATION', $ids, 'AUTO_SHELF'));
    }

    public function test_pick_sheet_and_handover_sheet_print_on_a4_and_auto_print_never_blocks_the_work(): void
    {
        $x = $this->parcel();
        $this->assertNull(Prints::auto(1, 'WH_AUTOPRINT_MANIFEST', 'MANIFEST', [1], 'AUTO_MANIFEST'), 'printer not tested yet: skipped, nothing thrown');
        $this->pass($this->a4, $this->plain);
        $p = Prints::print(1, 'PICK_SHEET', [$x['task']]);
        $this->assertSame(['count' => 1, 'w' => 210.0, 'h' => 297.0], $this->pages($p['file']));
        $s = Dispatch::openHandover($x['carrier']);
        Dispatch::scan($s, 'TRK1', 'op-1');
        Dispatch::closeHandover($s, 'Driver D');
        $m = Prints::auto(1, 'WH_AUTOPRINT_MANIFEST', 'MANIFEST', [$s], 'AUTO_MANIFEST');
        $this->assertSame('PRINTED', $m['status']);
        $this->assertSame(210.0, $this->pages($m['file'])['w']);
    }

    public function test_fit_check_refuses_with_the_reason_and_calibration_has_limits(): void
    {
        $x = $this->parcel();
        try { Prints::setRoute(1, 'PICK_SHEET', $this->th, null, $this->plain); $this->fail('A4 does not fit a 108 mm printer'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('larger than', $e->getMessage()); }
        try { Prints::setRoute(1, 'PICK_SHEET', $this->th, null, $this->roll); $this->fail('pick sheet needs A4'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('needs at least', $e->getMessage()); }
        try { Prints::calibrate(1, $this->th, $this->roll, true, ['scale_pct' => 104]); $this->fail('scale limit'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('± 3', $e->getMessage()); }
        Prints::calibrate(1, $this->th, $this->roll, true, ['scale_pct' => 98.5, 'offset_x_mm' => 1.5, 'offset_y_mm' => -1, 'rotate_deg' => 180]);
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']]);
        $this->assertSame(['count' => 1, 'w' => 100.0, 'h' => 150.0], $this->pages($j['file']), 'a correction moves the content, never the page size');
        // a media wider than the print width but printable turned by 90°
        $wide = DB::table('label_media')->insertGetId(['fk_id_ent' => 1, 'code' => 'T_150X100', 'name' => 'Label 150 × 100', 'media_kind' => 'ROLL', 'width_mm' => 150, 'height_mm' => 100, 'f_active' => 1]);
        $tpl = DB::table('label_templates')->where('code', 'PACKSLIP_FLOW')->first();
        $this->assertSame([100.0, 150.0, true], Prints::fit($tpl, DB::table('printer_profiles')->where('id', $this->th)->first(), DB::table('label_media')->where('id', $wide)->first()));
    }

    public function test_replace_printer_moves_every_route_in_one_step_and_an_adapter_not_built_fails_cleanly(): void
    {
        $x = $this->parcel();
        $new = DB::table('printer_profiles')->insertGetId(['fk_id_ent' => 1, 'code' => 'PR-NEW', 'name' => 'New thermal of another make', 'printer_kind' => 'THERMAL', 'connection' => 'USB', 'paper_format' => '100X150_MM',
            'max_print_width_mm' => 104, 'max_print_length_mm' => 250, 'f_active' => 1, 'printer_status' => 'ACTIVE', 'fk_id_adapter' => DB::table('print_adapters')->where('code', 'DRIVER_PDF')->value('id')]);
        $this->assertSame(1, Prints::replace(1, $this->th, $new));
        $this->assertSame($new, (int) Prints::route(1, 'PACKING_SLIP')->fk_id_printer_profile);
        $old = DB::table('printer_profiles')->where('id', $this->th)->first();
        $this->assertSame(['RETIRED', $new], [$old->printer_status, (int) $old->fk_id_replaced_by]);
        try { Prints::print(1, 'PACKING_SLIP', [$x['parcel']]); $this->fail('new printer needs its own test'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('test page', $e->getMessage()); }
        $this->pass($new, $this->roll);
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']]);
        $this->assertSame(['count' => 1, 'w' => 100.0, 'h' => 150.0], $this->pages($j['file']), 'same label, same size, different printer');
        DB::table('printer_profiles')->where('id', $new)->update(['fk_id_adapter' => DB::table('print_adapters')->where('code', 'RAW_TSPL')->value('id')]);
        $f = Prints::print(1, 'PACKING_SLIP', [$x['parcel']], ['reason' => 'OTHER']);
        $this->assertSame('FAILED', $f['status']);
        $this->assertStringContainsString('network address', $f['error'], 'a raw label printer without its address cannot be reached: the job fails with the reason');
    }

    public function test_reported_problem_fails_the_job_and_offers_the_backup(): void
    {
        $x = $this->parcel(); $this->pass($this->th, $this->roll);
        $j = Prints::print(1, 'PACKING_SLIP', [$x['parcel']]);
        $r = Prints::reportProblem(1, $j['job'], 'Label came out blank');
        $this->assertSame(['FAILED', $this->a4], [$r['status'], $r['backup']['id']]);
        $this->assertSame('Label came out blank', DB::table('print_jobs')->where('id', $j['job'])->value('error_text'));
        $this->assertTrue(TaskEngine::isOpen('WH_PRINT_FAILED', [$this->th, date('Y-m-d')]));
    }
}
