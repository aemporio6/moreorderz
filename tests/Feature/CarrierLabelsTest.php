<?php

namespace Tests\Feature;

use App\Services\CarrierLabels;
use App\Services\Context;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Carrier label PDF import (R-A_SALES-85). The labels are made up; layout like the owner's UK tracked label (tracking 2 letters + 9 digits + GB, service code text). */
class CarrierLabelsTest extends TestCase
{
    private int $shop; private int $car; private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Storage::fake('local');
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 50, 'location' => $bin, 'unit_cost' => 1.00]], 'GRN', 1); PriceService::set($p, $mp, 8, 5.00, '2026-01-01');
        $this->car = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'UK Post (test)', 'f_active' => 1, 'tracking_pattern' => '/\b[A-Z]{2}\d{9}GB\b/', 'label_pdf_layout' => 'ONE_PER_PAGE']);
        DB::table('carrier_service_map')->insert(['fk_id_mp_profile' => $mp, 'service_text' => 'TSS01', 'fk_id_carrier' => $this->car, 'fk_id_car_pkg_svc' => 0, 'f_active' => 1]);
        $d = date('Y-m-d');
        foreach ([['ORD-1001', 'AB1 2CD'], ['ORD-1002', 'ZZ9 9ZZ'], ['ORD-1003', 'EX17 2EJ'], ['ORD-1004', 'EX17 2EJ']] as [$no, $pc])
            Sales::intake($this->shop, [['order_no' => $no, 'd_order' => $d, 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 5, 'ship_to_postcode' => $pc, 'ship_to_name' => 'Made Up']]);
        $this->dir = sys_get_temp_dir() . '/mo_lbl_' . getmypid(); @mkdir($this->dir);
    }

    /** A PDF of label pages; each page: sender block, service code, tracking, delivery block. */
    private function pdf(array $labels): string
    {
        $html = '<html><body style="font-family:DejaVu Sans;font-size:11px">' . implode('<div style="page-break-after:always"></div>', array_map(fn ($l) => '<div>FROM: Demo Warehouse, 1 Depot Road, Town, WA1 1AA</div><div style="font-size:16px">' . $l['service'] . '</div><div>' . $l['track'] . '</div>'
            . ($l['ref'] ? '<div>Ref: ' . $l['ref'] . '</div>' : '') . '<div>TO: Made Up Person</div><div>9 Some Street</div><div>Town</div><div>' . $l['pc'] . '</div>', $labels)) . '</body></html>';
        $d = new Dompdf(); $d->setPaper([0, 0, 288, 432]); $d->loadHtml($html); $d->render();
        $p = $this->dir . '/labels_' . md5($html) . '.pdf'; file_put_contents($p, $d->output()); return $p;
    }

    public function test_label_pages_are_matched_by_order_reference_or_postcode_and_the_rest_waits_for_a_person(): void
    {
        if (! is_executable('/usr/bin/pdfseparate') || ! is_executable('/usr/bin/pdftotext')) $this->markTestSkipped('poppler-utils not installed');
        $pdf = $this->pdf([['service' => 'TSS01', 'track' => 'VX123456789GB', 'ref' => 'ORD-1001', 'pc' => 'AB1 2CD'], ['service' => 'TSS01', 'track' => 'VX223456789GB', 'ref' => '', 'pc' => 'ZZ9 9ZZ'],
            ['service' => 'TSS01', 'track' => 'VX323456789GB', 'ref' => '', 'pc' => 'EX17 2EJ'], ['service' => 'TRACKED 48 LL', 'track' => 'VX423456789GB', 'ref' => 'ORD-1004', 'pc' => 'EX17 2EJ']]);
        $text = CarrierLabels::read(CarrierLabels::text(CarrierLabels::pages($pdf, $this->dir . '/p')[0]), '/\b[A-Z]{2}\d{9}GB\b/', ['TSS01'], ['ORD-1001']);
        $this->assertSame(['VX123456789GB', 'TSS01', 'AB1 2CD', 'ORD-1001'], [$text['tracking'], $text['service'], $text['postcode'], $text['order']], 'the sender postcode at the top is not taken – the delivery one is');
        $wh = $this->makeUser('WAREHOUSE');
        $r = $this->actingAs($wh)->post('/api/labels/import', ['carrier' => $this->car, 'shop' => $this->shop, 'file' => new UploadedFile($pdf, 'labels.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([4, 3, 1, 1], [$r['pages'], $r['matched'], $r['set_aside'], $r['unmapped_service']]);
        $this->assertSame(['ORDER_REF' => 2, 'POSTCODE' => 1], $r['by']);
        $line = DB::table('t_sales')->where('order_no', 'ORD-1001')->first();
        $this->assertSame(['VX123456789GB', 'TSS01', 'READY'], [$line->tracking_no, $line->carrier_service_text, $line->label_status]);
        $this->assertSame('VX223456789GB', DB::table('t_sales')->where('order_no', 'ORD-1002')->value('tracking_no'), 'matched by postcode: one open order there');
        $wait = DB::table('mo_label_pages')->where('status', 'SET_ASIDE')->first();
        $this->assertSame(['VX323456789GB', 'EX17', 2], [$wait->tracking_no, $wait->postcode_out, count(json_decode($wait->candidates_json, true))], 'two orders for EX17 2EJ when page 3 was read – a person picks');
        $this->assertNull(DB::table('mo_label_pages')->whereNotNull('postcode_out')->whereRaw('CHAR_LENGTH(postcode_out) > 4')->value('id'), 'only the outward postcode is kept');
        $this->assertSame('%PDF', substr($this->actingAs($wh)->get('/api/labels/file/' . $line->label_file)->assertOk()->getContent(), 0, 4), 'the carrier\'s page is stored unchanged');
        // the same PDF again is refused
        $this->actingAs($wh)->post('/api/labels/import', ['carrier' => $this->car, 'shop' => $this->shop, 'file' => new UploadedFile($pdf, 'labels.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonFragment(['message' => 'Already imported: this exact PDF was loaded before.']);
        // a person picks; an order that has a label already is refused
        $o4 = (int) DB::table('t_sales')->where('order_no', 'ORD-1004')->value('id'); $o3 = (int) DB::table('t_sales')->where('order_no', 'ORD-1003')->value('id');
        $this->actingAs($wh)->postJson("/api/labels/{$wait->id}/pick", ['sale' => $o4])->assertStatus(422);
        $this->actingAs($wh)->postJson("/api/labels/{$wait->id}/pick", ['sale' => $o3])->assertOk();
        $this->assertSame('VX323456789GB', DB::table('t_sales')->where('id', $o3)->value('tracking_no'));
        // undo the file: every link off, the PDF may be loaded again
        $this->actingAs($wh)->postJson("/api/labels/batch/{$r['batch']}/undo")->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/api/labels/batch/{$r['batch']}/undo")->assertOk();
        $this->assertSame(0, DB::table('t_sales')->whereNotNull('tracking_no')->count());
        $this->actingAs($this->owner)->post('/api/labels/import', ['carrier' => $this->car, 'shop' => $this->shop, 'file' => new UploadedFile($pdf, 'labels.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, Stock::balance((int) DB::table('t_product_vendors')->where('SKU_VENDOR', 'BOWL-01')->value('id')) - 50, 'labels never move stock');
    }
}
