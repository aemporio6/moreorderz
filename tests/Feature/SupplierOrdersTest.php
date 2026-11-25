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
    /** R-A_SALES-80 improved: a line whose order id is in a sale's buy note fills that sale's buy; the other lines of a known ASIN become a Quick buy (stock), once. */
    public function test_lines_match_sales_and_stock_buys_become_a_quick_buy(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_no' => '8', 'shop_name' => 'DS shop', 'iso_branch' => 'B8', 'f_active' => 1]));
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LAMP-9', 'product_name_vendor' => 'Lamp', 'f_active' => 1]));
        DB::table('t_product_listings')->insert(RecordService::fillNotNull('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $shop, 'asin' => 'B0LAMP0009']));
        $sale = DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $p, 'order_no' => 'E-77', 'sku' => 'LAMP-9', 'qty' => 1, 'sale_rate' => 20, 'total_amount' => 20, 'd_order' => '2026-11-02', 'status' => 'Pending', 'ds_note' => 'AMZ : 205-1 : 02-11-26 : 1 : 9.50']));
        $cols = SupplierOrders::columns();
        $line = fn ($order, $asin, $qty, $net) => implode(',', array_map(fn ($c) => match ($c) { 'order_date' => '2026-11-02', 'order_id' => $order, 'ASIN' => $asin, 'item_qty' => $qty, 'item_net_total' => $net, 'currency' => 'GBP', default => '' }, $cols));
        $csv = implode(',', $cols) . "\n" . $line('205-1', 'B0LAMP0009', '1', '9.50') . "\n" . $line('205-2', 'B0LAMP0009', '10', '80.00') . "\n" . $line('205-3', 'B0NOTOURS1', '1', '5.00') . "\n";
        $this->post('/api/supplier-orders/import', ['file' => UploadedFile::fake()->createWithContent('8.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $chk = $this->getJson("/api/supplier-orders/match?shop=$shop")->assertOk()->json();
        $this->assertSame(['matched' => 1, 'stock' => 1, 'unknown' => 1], $chk['counts']);
        $this->assertSame(0.0, (float) DB::table('t_sales')->where('id', $sale)->value('buy_amount'), 'the check writes nothing');
        $this->postJson('/api/supplier-orders/match', ['shop' => $shop])->assertOk();
        $this->assertSame([9.5, 9.5, '2026-11-02', 'supplier_order'], [(float) DB::table('t_sales')->where('id', $sale)->value('buy_amount'), (float) DB::table('t_sales')->where('id', $sale)->value('buy_rate'), (string) DB::table('t_sales')->where('id', $sale)->value('d_buy'), DB::table('t_sales')->where('id', $sale)->value('cost_source')]);
        $sup = DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-AB1', 'supplier_name' => 'Amazon Business', 'f_active' => 1]));
        $loc = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        $q = $this->postJson('/api/supplier-orders/quick-buy', ['shop' => $shop, 'order' => '205-2', 'supplier' => $sup, 'location' => $loc])->assertOk()->json();
        $this->assertSame([10, 8.0], [(int) DB::table('fin_lots')->where('fk_id_product', $p)->sum('qty'), round((float) DB::table('fin_lots')->where('fk_id_product', $p)->value('unit_cost_gbp'), 2)], 'stock received at the order\'s cost');
        $this->assertSame('QUICK', DB::table('t_src_cycles')->where('id', $q['cycle'])->value('cycle_type'));
        $this->postJson('/api/supplier-orders/quick-buy', ['shop' => $shop, 'order' => '205-2', 'supplier' => $sup, 'location' => $loc])->assertStatus(422);
        $this->postJson('/api/supplier-orders/quick-buy', ['shop' => $shop, 'order' => '205-1', 'supplier' => $sup, 'location' => $loc])->assertStatus(422)->assertJsonPath('message', 'Order 205-1 has no stock line (its lines are matched to sales or their ASIN is on no listing).');
    }
}
