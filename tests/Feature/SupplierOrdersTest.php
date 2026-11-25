<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SupplierOrders;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-A_SALES-80 the rule test: a 67-column file is refused; the same order + ASIN loaded again is 1 row, not 2; VAT evidence kept. */
class SupplierOrdersTest extends TestCase
{
    public function test_rule_example(): void
    {
        $this->actingAs($this->owner);
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_no' => '7', 'shop_name' => 'Buy shop', 'iso_branch' => 'B7', 'f_active' => 1]));
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'KETTLE-1', 'product_name_vendor' => 'Kettle', 'f_active' => 1]));
        DB::table('t_product_listings')->insert(RecordService::fillNotNull('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $shop, 'asin' => 'B0KETTLE01']));
        $cols = SupplierOrders::columns(); $this->assertCount(68, $cols);
        $line = fn (string $asin, string $vatInv) => implode(',', array_map(fn ($c) => match ($c) { 'order_date' => '01/11/2026', 'order_id' => '202-1', 'ASIN' => $asin, 'item_qty' => '2', 'item_vat' => '3.20', 'vat_inv_no' => $vatInv, 'seller_vat_reg_no' => 'GB123', default => '' }, $cols));
        $csv = implode(',', $cols) . "\n" . $line('B0KETTLE01', 'INV-1') . "\n" . $line('B0OTHER999', '') . "\n";
        $up = fn ($name, $text) => UploadedFile::fake()->createWithContent($name, $text);
        $this->post('/api/supplier-orders/check', ['file' => $up('9.csv', $csv)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Kindly set valid File Name e.g. for Shop No. 1 set file name to [1.csv]');
        $short = implode(',', array_slice($cols, 0, 67)) . "\n";
        $this->post('/api/supplier-orders/check', ['file' => $up('7.csv', $short)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'No. of Columns not matched, see template');
        $c = $this->post('/api/supplier-orders/check', ['file' => $up('7-nov.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 1, 1, 0], [$c['rows'], $c['found'], $c['not_found'], DB::table('t_amazon_orders')->count()], 'the check writes nothing');
        $this->post('/api/supplier-orders/import', ['file' => $up('7-nov.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/supplier-orders/import', ['file' => $up('7.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, DB::table('t_amazon_orders')->where('fk_id_branch', $shop)->count(), 'order + ASIN again → replaced, not doubled');
        $this->assertSame(['INV-1', 'GB123', '3.20'], array_values((array) DB::table('t_amazon_orders')->where('ASIN', 'B0KETTLE01')->first(['vat_inv_no', 'seller_vat_reg_no', 'item_vat'])), 'input-VAT evidence kept');
    }
}
