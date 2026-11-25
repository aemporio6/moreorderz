<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Gen1Files;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-A_SALES-58 month header, R-A_SALES-54 Gen-1 SALE sheet, R-A_SALES-55 Etsy orders report. */
class Gen1FilesTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function shop(string $no): int
    {
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'ET' . random_int(100, 999), 'f_active' => 1]);
        return $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => $no, 'iso_branch' => 'E' . random_int(1000, 9999), 'shop_name' => 'Etsy ' . $no, 'f_active' => 1]);
    }
    private function up(int $main, string $step, string $name, string $csv) { return $this->post("/api/gen1/$main/sale-file/$step", ['file' => UploadedFile::fake()->createWithContent($name, $csv)], ['Accept' => 'application/json']); }

    public function test_a58_header_create_flags_actions_and_cascade_delete(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->assertSame(['mon' => 11, 'yr' => 2026, 'up_to' => false], Gen1Files::defaultMonth(1, '2026-11-26'));
        $this->assertSame(['mon' => 10, 'yr' => 2026, 'up_to' => false], Gen1Files::defaultMonth(2, '2026-11-26'));
        $this->assertSame(['mon' => 11, 'yr' => 2025, 'up_to' => true], Gen1Files::defaultMonth(3, '2026-01-15'), 'two months back crosses the year');
        $shop = $this->shop('202'); $iso = 'H' . random_int(10, 99); $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Head Supplier', 'iso_vendor' => $iso, 'f_active' => 1]);
        $m = $this->getJson('/api/gen1/meta?flag=2')->assertOk()->json(); $this->assertContains($shop, array_column($m['shops'], 'id')); $this->assertContains($iso, array_column($m['suppliers'], 'code'));
        $this->postJson('/api/gen1', ['shop' => $shop, 'mon' => 13, 'yr' => 2023])->assertStatus(422);
        $r = $this->postJson('/api/gen1', ['shop' => $shop, 'mon' => 12, 'yr' => 2023, 'supplier' => $v, 'sale_fee' => '12.50', 'expenses' => '3', 'expenses_detail' => 'Stamps'])->assertOk()->json(); $main = (int) $r['id'];
        $this->postJson('/api/gen1', ['shop' => $shop, 'mon' => 12, 'yr' => 2023])->assertStatus(422)->assertJsonPath('message', 'This shop already has a header for that month.');
        $h = $this->getJson("/api/gen1/$main/head")->assertOk()->json();
        $this->assertSame([false, false, 'Head Supplier', 12.5, 3.0, 'Stamps', '12/2023'], [$h['sale_loaded'], $h['summary_generated'], $h['supplier'], (float) $h['sale_fee'], (float) $h['expenses'], $h['expenses_detail'], $h['month']]);
        // the rule: create a header for 202 Dec 2023 → import SALE → Sale loaded = Yes
        $this->up($main, 'import', '202_SALE_December2023.csv', "Etsy 202,03.12.2023,E-1,Mug,John Smith,12 High St,2,£20.00,$iso:B-1\n")->assertOk();
        $this->assertTrue($this->getJson("/api/gen1/$main/head")->json('sale_loaded'));
        $this->f('t_buy', ['fk_id_main' => $main, 'fk_id_vendor' => $v, 'order_id' => 'B-1', 'd_buy' => '2023-12-03', 'item_title' => 'Mug', 'customer_name' => 'John Smith', 'cust_address' => '12 HIGH ST', 'no_of_items' => 2, 'price' => 7]);
        $this->assertSame([['code' => $iso, 'name' => 'Head Supplier', 'n' => 1]], $this->getJson("/api/gen1/$main/head")->json('buy_suppliers'), 'See Buying + supplier ISO list');
        // Delete Sale: lines go, flag back to No, buying stays
        $this->postJson("/api/gen1/$main/sale-remove")->assertOk()->assertJsonPath('removed', 1);
        $this->assertSame([0, 1, 0], [DB::table('t_sale')->where('fk_id_main', $main)->count(), DB::table('t_buy')->where('fk_id_main', $main)->count(), (int) DB::table('t_sale_buy_main')->where('id', $main)->value('f_sale_loaded')]);
        // deleting the header cascades to sale and buy lines
        $this->up($main, 'import', '202_SALE_December2023.csv', "Etsy 202,03.12.2023,E-1,Mug,John Smith,12 High St,2,£20.00,$iso:B-1\n")->assertOk();
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/gen1/$main/delete")->assertStatus(403); $this->actingAs($this->owner);
        $this->postJson("/api/gen1/$main/delete")->assertOk();
        $this->assertSame([0, 0, 0], [DB::table('t_sale_buy_main')->where('id', $main)->count(), DB::table('t_sale')->where('fk_id_main', $main)->count(), DB::table('t_buy')->where('fk_id_main', $main)->count()]);
    }

    public function test_a54_sale_sheet(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $s202 = $this->shop('202'); $s203 = $this->shop('203'); $iso = 'S' . random_int(10, 99); $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Sheet Supplier', 'iso_vendor' => $iso, 'f_active' => 1]);
        $m202 = (int) $this->postJson('/api/gen1', ['shop' => $s202, 'mon' => 12, 'yr' => 2023])->json('id'); $m203 = (int) $this->postJson('/api/gen1', ['shop' => $s203, 'mon' => 12, 'yr' => 2023])->json('id');
        $csv = "Etsy 202,03.12.2023,E-1,Mug,John Smith,12 High St,2,£20.00,[x] $iso:B-1 {late parcel}\n"
            . "Etsy 202,04.12.23,E-2,Cup,Amy Lee,5 Park Rd,1,9.00,$iso:B-2\n"
            . "Etsy 202,30.11.2023,E-0,Old,Nov Person,1 Past St,1,5.00,$iso:B-0\n"
            . "Etsy 202,05.12.2023,E-3,,No Title,1 Lane,1,5.00,$iso:B-3\n"
            . "Etsy 202,06.12.2023,E-4,Bowl,Sara Khan,,1,5.00,$iso:B-4\n";
        // the rule: the file on a header of shop 203 → refused; on shop 202 Dec 2023 → loaded
        $this->up($m203, 'check', '202_SALE_December2023.csv', $csv)->assertStatus(422)->assertJsonPath('message', 'Shop no not matched, 202<=>203');
        $this->up($m202, 'check', '202_BUY_December2023.csv', $csv)->assertStatus(422);
        $c = $this->up($m202, 'check', '202_SALE_December2023.csv', $csv)->assertOk()->json();
        $this->assertSame(['GEN1_SALE_SHEET', 2, 2, false], [$c['report_type'], $c['rows'], $c['skipped'], $c['applied']]);
        $this->assertSame(['1 records not loaded due to out of Month', 'Empty [Item title] in 1 records....', 'Empty [Address] in 1 records....'], $c['notes']);
        $this->assertSame('E-0', $c['set_aside'][0]['order']); $this->assertSame(0, DB::table('t_sale')->where('fk_id_main', $m202)->count(), 'the check writes nothing');
        $this->up($m202, 'import', '202_SALE_December2023.csv', $csv)->assertOk()->assertJsonPath('applied', true);
        $r = DB::table('t_sale')->where('fk_id_main', $m202)->orderBy('id')->get();
        $this->assertSame(['2023-12-03', 'E-1', 'Mug', 'John Smith', '12 High St', 2, 20.0, $v, 'B-1', 'late parcel', "$iso:B-1"], [$r[0]->d_sale, $r[0]->order_id, $r[0]->item_title, $r[0]->customer_name, $r[0]->cust_address, (int) $r[0]->no_of_items, (float) $r[0]->price, (int) $r[0]->fk_id_vendor, $r[0]->order_id_buy_in_sale, $r[0]->reason_by_va, $r[0]->item_note]);
        $h = $this->getJson("/api/gen1/$m202/head")->json(); $this->assertTrue($h['sale_loaded']); $this->assertCount(1, $h['set_aside'], 'the set-aside panel keeps the out-of-month rows');
        // a note that is not SUPPLIER:ref stops the import, the earlier lines stay
        $this->up($m202, 'import', '202_SALE_December2023.csv', "Etsy 202,03.12.2023,E-9,Mug,John Smith,12 High St,1,5,just words\n")->assertStatus(422);
        $this->assertStringStartsWith('Invalid Item Notes', $this->up($m202, 'import', '202_SALE_December2023.csv', "Etsy 202,03.12.2023,E-9,Mug,John Smith,12 High St,1,5,ZZ9:B-1\n")->json('message'));
        $this->assertSame(2, DB::table('t_sale')->where('fk_id_main', $m202)->count());
        // a second file replaces the lines
        $this->up($m202, 'import', '202_SALE_Dec.csv', "Etsy 202,07.12.2023,E-7,Plate,New One,9 New St,1,3.50,$iso:B-7\n")->assertOk()->assertJsonPath('replaces', 2);
        $this->assertSame(['E-7'], DB::table('t_sale')->where('fk_id_main', $m202)->pluck('order_id')->all());
    }

    public function test_a55_etsy_orders_report(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $shop = $this->shop('202'); $main = (int) $this->postJson('/api/gen1', ['shop' => $shop, 'mon' => 12, 'yr' => 2023])->json('id');
        $local = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Own stock', 'iso_vendor' => 'INV' . random_int(10, 99), 'f_local' => 1, 'f_active' => 1]);
        $sku = 'OWN-' . random_int(1000, 9999);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => 'Own mug', 'fk_id_vendor' => $local, 'f_parent' => 1, 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        // the rule: a file without QUANTITY → "Column [QUANTITY] missing"
        $this->up($main, 'check', '202_SALE_Dec.csv', "DATE,ORDER ID,SKU,ITEM TITLE,CUSTOMER,ADDRESS,SALE PRICE\n03.12.2023,E-1,X,Mug,John,1 St,5\n")->assertStatus(422)->assertJsonPath('message', 'Column [QUANTITY] missing');
        $this->assertSame('Column [SKU] missing · Column [QUANTITY] missing', $this->up($main, 'check', '202_SALE_Dec.csv', "DATE,ORDER ID,ITEM TITLE,CUSTOMER,ADDRESS,SALE PRICE\n03.12.2023,E-1,Mug,John,1 St,5\n")->json('message'));
        $head = "Sale Price,Quantity,Address,Customer,Item Title,SKU,Order ID,Date\n";
        $this->up($main, 'check', '202_SALE_Dec.csv', $head . "5,1,1 St,John,Mug,X,E-1,2023-12-03\n")->assertStatus(422);      // the date must be d.m.y – the import stops
        $csv = $head . "20.00,2,12 High St,John Smith,Mug,DS-1,E-1,03.12.2023\n" . "9.00,1,5 Park Rd,Amy Lee,Own mug,$sku,E-2,04.12.2023\n" . "5.00,1,1 Past St,Nov Person,Old,DS-2,E-0,30.11.2023\n" . "5.00,,1 Lane,No Qty,Thing,DS-3,E-3,05.12.2023\n" . "5.00,1,1 Lane,,Thing,DS-4,E-4,05.12.2023\n";
        $c = $this->up($main, 'check', '202_SALE_Dec.csv', $csv)->assertOk()->json();
        $this->assertSame(['ETSY_ORDERS', 1, 1, 2], [$c['report_type'], $c['rows'], $c['own_stock'], $c['skipped']]);
        $this->assertSame(['1 records not loaded due to out of Month', 'Empty [Customer] in 1 records....', 'Empty [Quantity] in 1 records....'], $c['notes']);
        $sales = DB::table('t_sales')->where('fk_id_branch', $shop)->count();
        $this->up($main, 'import', '202_SALE_Dec.csv', $csv)->assertOk();
        $this->assertSame(['E-1'], DB::table('t_sale')->where('fk_id_main', $main)->pluck('order_id')->all());
        $own = DB::table('t_sales')->where('fk_id_branch', $shop)->where('order_no', 'E-2')->first();
        $this->assertSame([$sales + 1, $p, 1, 9.0], [DB::table('t_sales')->where('fk_id_branch', $shop)->count(), (int) $own->fk_product_id, (int) $own->qty, (float) $own->total_amount], 'own stock = a sale line of the tool');
        $this->assertSame(0, DB::table('t_buy')->where('fk_id_main', $main)->count(), 'no made-up buy line for own stock');
        $this->assertSame(0, (int) $own->f_stock_deducted, 'stock and cost are taken at Month Confirm');
    }
}
