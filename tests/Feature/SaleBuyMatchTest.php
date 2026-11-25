<?php

namespace Tests\Feature;

use App\Services\CloseService;
use App\Services\Gen1Compare;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-80 audit check SALE_BUY_MATCH (line codes, summary codes, findings, Month Close) and R-A_SALES-56 supplier orders (BUY) file. */
class SaleBuyMatchTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function month(string $no = '202'): array
    {
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'SB' . random_int(100, 999), 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => $no, 'iso_branch' => 'S' . random_int(1000, 9999), 'shop_name' => 'Match shop', 'f_active' => 1]);
        return [$this->f('t_sale_buy_main', ['mon' => 12, 'yr' => 2023, 'd_yr_mon' => '2023-12-01', 'fk_id_branch' => $shop, 'f_sale_loaded' => 1]), $shop];
    }
    private function up(int $main, string $step, string $name, string $csv) { return $this->post("/api/gen1/$main/buy-file/$step", ['file' => UploadedFile::fake()->createWithContent($name, $csv)], ['Accept' => 'application/json']); }

    public function test_g80_line_codes_summary_codes_and_findings(): void
    {
        $this->actingAs($this->owner); [$main, $shop] = $this->month();
        $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Ali', 'iso_vendor' => 'G8' . random_int(10, 99), 'f_active' => 1]);
        $sale = fn ($o, $c, $a, $q, $p, $b) => $this->f('t_sale', ['fk_id_main' => $main, 'order_id' => $o, 'd_sale' => '2023-12-03', 'sku' => 'S', 'item_title' => 'Item', 'customer_name' => $c, 'cust_address' => $a, 'no_of_items' => $q, 'price' => $p, 'order_id_buy_in_sale' => $b]);
        $buy = fn ($o, $c, $a, $q, $p) => $this->f('t_buy', ['fk_id_main' => $main, 'fk_id_vendor' => $v, 'order_id' => $o, 'd_buy' => '2023-12-03', 'item_title' => 'Item', 'customer_name' => $c, 'cust_address' => $a, 'no_of_items' => $q, 'price' => $p]);
        $sale('E-1', 'John Smith', '12 High St', 2, 20, 'B-1'); $buy('B-1', 'John Smith', '12 HIGH ST, LONDON', 1, 7.5);      // the rule: sale qty 2, buy qty 1, same customer → QTY
        $sale('E-2', 'Amy Lee', '5 Park Rd', 1, 9, 'B-2'); $buy('B-2', 'Amy Lee', '5 PARK RD', 1, 4);                             // OK
        $sale('E-3', 'Omar Ali', '7 Mill Ln', 1, 9, 'B-3'); $buy('B-3', 'O. Ali', '7 MILL LN', 1, 4);                             // CUSTOMER
        $sale('E-4', 'Sara Khan', '1 Oak Av', 3, 9, 'B-4'); $buy('B-4', 'S Khan', '1 OAK AV', 1, 4);                              // QTY_CUSTOMER
        $sale('E-5', 'No Buy', '9 Elm St', 1, 9, '');                                                                              // BUYING_MISSING
        $d = $this->getJson("/api/gen1/$main/match")->assertOk()->json(); $r = collect($d['rows'])->keyBy('order');
        $this->assertSame(['QTY', 'OK', 'CUSTOMER', 'QTY_CUSTOMER', 'BUYING_MISSING'], [$r['E-1']['code'], $r['E-2']['code'], $r['E-3']['code'], $r['E-4']['code'], $r['E-5']['code']]);
        $this->assertSame(['Qty', 'Qty/Customer', 'Buying Missing'], [$r['E-1']['result'], $r['E-4']['result'], $r['E-5']['result']]);
        $this->assertSame([12.5, 9.0], [(float) $r['E-1']['price_diff'], (float) $r['E-5']['price_diff']], 'sale − buy');
        $this->assertSame(['BUYING_MISSING' => 1, 'QTY_CUSTOMER' => 1, 'CUSTOMER' => 1, 'QTY' => 1, 'OK' => 1], $d['counts']); $this->assertSame(4, $d['findings']);
        $this->assertNull($r['E-2']['fix']); $this->assertStringStartsWith("#/gen1/$main/", $r['E-5']['fix']);

        // summary: same customer name and the buy address containing the sale address → match; another customer name → Buying Missing
        $s = collect($this->getJson("/api/gen1/$main/summary")->assertOk()->json('rows'))->keyBy('customer');
        $this->assertSame(['QTY_DIFF', 'OK', 'BUYING_MISSING'], [$s['John Smith']['code'], $s['Amy Lee']['code'], $s['Omar Ali']['code']]);
        $this->assertSame('12 HIGH ST, LONDON', $s['John Smith']['buy_address'], 'address contained');
        $this->assertSame('Buying Missing', $s['Omar Ali']['result']);

        $f = Gen1Compare::findings($main); $this->assertCount(4 + 4, $f, '4 line findings + 4 summary findings (QTY_DIFF, 3 × BUYING_MISSING)');
        $this->assertSame(['id' => $main, 'lines' => 4, 'summary' => 4], Gen1Compare::closeCount($shop, '2023-12-01'));
        $this->assertNull(Gen1Compare::closeCount($shop, '2024-01-01'), 'a month without sale + buy lines is not judged');
        $this->assertArrayHasKey('PURCHASE_VS_SUPPLIER', CloseService::CHECKS);
        // a person without the money right sees no money columns
        $va = $this->makeUser('WAREHOUSE'); $x = $this->actingAs($va)->getJson("/api/gen1/$main/match"); if ($x->status() === 200) $this->assertArrayNotHasKey('price_diff', $x->json('rows')[0] ?? []);
    }

    public function test_a56_supplier_orders_buy_file(): void
    {
        $this->actingAs($this->owner); [$main] = $this->month('202');
        $iso = 'Q' . random_int(10, 99); $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Generic Supplier', 'iso_vendor' => $iso, 'f_active' => 1]);
        $ali = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'AliExpress', 'iso_vendor' => 'A' . $iso, 'f_active' => 1]);
        $csv = "Etsy shop,03.12.2023,B-1/77,Mug,John Smith,\"12 High St, Leeds\",2,7.50\nEtsy shop,2023-12-04,B-2,Cup,Amy Lee,5 Park Rd,1,4\nEtsy shop,05.12.23,B-3,,Omar Ali,7 Mill Ln,1,4\nEtsy shop,05.12.23,B-1,Mug again,John Smith,12 High St,1,7.5\nEtsy shop,06.12.23,B-4,Plate,Sara Khan,1 Oak Av,3,9.99\n";
        // the rule: an unknown supplier code is refused; so are a wrong shop and a missing BUY
        $this->up($main, 'check', '202_BUY_XX_Dec2023.csv', $csv)->assertStatus(422)->assertJsonPath('message', 'Valid Vendor Code should be at proper place');
        $this->up($main, 'check', "999_BUY_{$iso}_Dec2023.csv", $csv)->assertStatus(422);
        $this->up($main, 'check', "202_SELL_{$iso}_Dec2023.csv", $csv)->assertStatus(422);
        // check never writes
        $c = $this->up($main, 'check', "202_BUY_{$iso}_Dec2023.csv", $csv)->assertOk()->json();
        $this->assertSame([2, 3, 'GENERIC', "SUPPLIER_ORDERS:$iso", false], [$c['rows'], $c['skipped'], $c['layout'], $c['report_type'], $c['applied']]);
        $this->assertSame(['Date should be in format i.e [01.12.2023], issue in 1 records....', 'Empty [Item title] in 1 records....', '1 duplicates Order ID Found, skiiped...'], $c['notes']);
        $this->assertSame(0, DB::table('t_buy')->where('fk_id_main', $main)->count());
        $this->up($main, 'import', "202_BUY_{$iso}_Dec2023.csv", $csv)->assertOk()->assertJsonPath('applied', true);
        $b = DB::table('t_buy')->where('fk_id_main', $main)->orderBy('id')->get();
        $this->assertSame(['B-1', '2023-12-03', 'Mug', 'John Smith', '12 High St, Leeds', 2, 7.5, $v], [$b[0]->order_id, $b[0]->d_buy, $b[0]->item_title, $b[0]->customer_name, $b[0]->cust_address, (int) $b[0]->no_of_items, (float) $b[0]->price, (int) $b[0]->fk_id_vendor], 'order id = the text before "/"');
        $this->assertSame('GENERIC', DB::table('t_vendors')->where('id', $v)->value('buy_layout'), 'one mapping kept per supplier');
        // a second file of the same supplier replaces its lines; another supplier's lines stay
        $aliCsv = "DATE,ORDER ID,SKU,ITEM TITLE,CUSTOMER,ADDRESS,QUANTITY,BUY PRICE\n07.12.23,A-1,SKU1,,Zed Ali,3 Low Rd,,2.50\n07.12.23,A-1,SKU1,Twice,Zed Ali,3 Low Rd,1,2.50\n08.12.23,A-2,SKU2,Lamp,Bea Roy,4 Top Rd,2,5\n";
        $a = $this->up($main, 'import', "202_BUY_A{$iso}_Dec2023.csv", $aliCsv)->assertOk()->json();
        $this->assertSame(['ALIEXPRESS', 2, 1], [$a['layout'], $a['rows'], $a['skipped']], 'duplicates are skipped in the AliExpress layout too');
        $row = DB::table('t_buy')->where('fk_id_main', $main)->where('fk_id_vendor', $ali)->orderBy('id')->first(); $this->assertSame(['[EMPTY]', 1, 'SKU1'], [$row->item_title, (int) $row->no_of_items, $row->sku]);
        $this->up($main, 'import', "202_BUY_{$iso}_Dec2023.csv", "Etsy shop,09.12.2023,B-9,Bowl,New One,9 New St,1,3\n")->assertOk()->assertJsonPath('replaces', 2);
        $this->assertSame([1, 2], [DB::table('t_buy')->where('fk_id_main', $main)->where('fk_id_vendor', $v)->count(), DB::table('t_buy')->where('fk_id_main', $main)->where('fk_id_vendor', $ali)->count()]);
        // AliExpress: a bad date stops the whole import, nothing changes
        $this->up($main, 'import', "202_BUY_A{$iso}_Dec2023.csv", "DATE,ORDER ID,SKU,ITEM TITLE,CUSTOMER,ADDRESS,QUANTITY,BUY PRICE\n2023/12/07,A-5,S,T,C,A,1,2\n")->assertStatus(422)->assertJsonPath('message', 'Invalid Date Formt of Buy Date. i.e [31.01.23]');
        $this->assertSame(2, DB::table('t_buy')->where('fk_id_main', $main)->where('fk_id_vendor', $ali)->count());
        $this->assertSame('Generic Supplier', collect($this->getJson("/api/gen1/$main/buys")->json('rows'))->firstWhere('order', 'B-9')['buy_from']);
    }
}
