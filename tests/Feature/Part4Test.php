<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\CashForecast;
use App\Services\Context;
use App\Services\Fx;
use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\OrderFiles;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SourcingExtras as X;
use App\Services\StockService as Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Round 2 part 4: marketplaces' order lists, bank file from Excel, bank line split, cash forecast. All files are made up (headers as in the owner's real files). */
class Part4Test extends TestCase
{
    private int $shop; private int $p; private int $p2; private int $bin; private int $bank; private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'ALX-127', 'product_name_vendor' => 'Tyre wax', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->p2 = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'B178-BELT', 'product_name_vendor' => 'Seat belt', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 50, 'location' => $this->bin, 'unit_cost' => 1.00], ['product' => $this->p2, 'qty' => 50, 'location' => $this->bin, 'unit_cost' => 1.00]], 'GRN', 1);
        PriceService::set($this->p, $mp, 8, 5.00, '2026-01-01'); PriceService::set($this->p2, $mp, 8, 5.00, '2026-01-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 1000, 'opening_date' => '2026-08-01']);
        $this->dir = sys_get_temp_dir() . '/mo_p4_' . getmypid(); @mkdir($this->dir);
    }

    private function file(string $name, string $text): string { $p = $this->dir . '/' . $name; file_put_contents($p, $text); return $p; }

    private function ebay(): string
    {
        $h = '"Sales record number","Order number","Buyer username","Buyer name","Buyer email","Buyer note",Buyer address 1,Buyer address 2,Buyer city,"Buyer county","Buyer postcode","Buyer country",Post to name,"Post to phone",Post to address 1,Post to address 2,Post to city,"Post to county","Post to postcode","Post to country","Item number","Item title",Custom label,"Sold via Promoted listings","Quantity","Sold for","Postage and packaging","Total price","Sale date","Paid on date","Post by date","Tracking number","Transaction ID"';
        $row = fn (array $v) => implode(',', array_map(fn ($x) => '"' . $x . '"', $v));
        $b = fn ($rec, $no, $name, $item, $title, $sku, $qty, $sold, $post, $total, $date) => $row([$rec, $no, 'user', $name, '', '', '1 Road', '', 'Town', '', 'AB1 2CD', 'United Kingdom', $name, '07000000000', '1 Road', 'Flat 2', 'Town', '', 'AB1 2CD', 'United Kingdom', $item, $title, $sku, 'No', $qty, $sold, $post, $total, $date, $date, 'Oct-03-26', '', '1']);
        return "\u{FEFF}" . str_repeat(',', 40) . "\n" . $h . "\n" . implode("\n", [
            $b('101', '06-15377-74741', 'Made Up One', '1682925695', 'Tyre wax', 'ALX-127', '2', '£2.83', '£0.00', '£5.66', 'Oct-31-26'),
            $b('102', '07-11111-22222', 'Made Up Two', '', '', '', '', '', '£1.50', '£11.50', 'Oct-30-26'),                                      // head line of an order with two items
            $row(['102', '07-11111-22222', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '1682925695', 'Tyre wax', 'ALX-127', 'No', '1', '£5.00', '', '', '', '', '', 'TRK1', '2']),
            $row(['102', '07-11111-22222', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '999', 'Unknown thing', 'NOT-A-SKU', 'No', '1', '£5.00', '', '', '', '', '', '', '3']),
            '', '"3 record(s) downloaded","from Oct-01-26 to Oct-31-26"']) . "\n";
    }

    private function temu(): string
    {
        $h = 'Order ID,order status,Fulfillment mode,Logistics service suggestion,Order item ID,order item status,Logistics status,product name by customer order,product name,variation,product type,contribution sku,SKU ID,quantity purchased,quantity to ship,quantity shipped,quantity canceled,recipient name,recipient first name,recipient last name,recipient phone number,ship address 1,ship address 2,ship address 3,district,ship city,ship state,ship postal code (Must be shipped to the following zip code.),ship country,purchase date,latest shipping time,latest delivery time,iPhone serial number,virtual email,activity goods base price,goods base price,tracking number,carrier,order settlement status,keep proof of shipment before delivery,product tax total,product rate,shipping tax rate,shipping tax total,retail price total,shipping cost,Discount from Temu,Discount from seller,Upload POD,Shipping template,Shipping address';
        $r = fn ($no, $item, $status, $sku, $qty, $cancel, $base, $retail) => implode(',', [$no, $status, 'Seller fulfillment', '', $item, $status, '', '"Seat belt"', '"Seat belt"', 'Black/2', '', $sku, '141227114678218', $qty, '0', $qty, $cancel, '***', '', '', '+**', '***', '', '', '', '***', '', 'SS13 2HE', 'United Kingdom', '"Sep 28, 2026, 3:35 am PKT(UTC+5)"', '"Sep 30, 2026, 4:29 am PKT(UTC+5)"', '"Oct 3, 2026, 3:59 am PKT(UTC+5)"', '', '', $base, $base, '89VGL892', 'Yodel', 'Settled', '', '0.40', '20000000', '20000000', '0.33', $retail, '1.67', '', '', '', 'VAL DISPATCH', 'Warehouse']);
        return $h . "\n" . implode("\n", [$r('PO-210-1', '210-1', 'Delivered', 'B178-BELT', '2', '0', ' 1.80', ' 4.00'), $r('PO-210-2', '210-2', 'Cancelled', 'B178-BELT', '1', '1', ' 1.80', ' 2.00')]) . "\n";
    }

    public function test_ebay_orders_report_loads_items_of_multi_item_orders_and_skips_the_footer(): void
    {
        $x = OrderFiles::read($this->file('eBay-OrdersReport.csv', $this->ebay()), 'csv');
        $this->assertSame(['EBAY_ORDERS', 3], [$x['format'], count($x['lines'])]);
        $this->assertSame(['06-15377-74741', '2026-10-31', 'ALX-127', 2, 2.83, 'GB', '2026-10-03'], [$x['lines'][0]['order_no'], $x['lines'][0]['d_order'], $x['lines'][0]['sku'], $x['lines'][0]['qty'], $x['lines'][0]['sale_rate'], $x['lines'][0]['ship_to_country_code'], $x['lines'][0]['dispatch_by']]);
        $this->assertSame(['07-11111-22222', '2026-10-30', 'Made Up Two', '1 Road, Flat 2', 'TRK1'], [$x['lines'][1]['order_no'], $x['lines'][1]['d_order'], $x['lines'][1]['ship_to_name'], $x['lines'][1]['ship_to_address'], $x['lines'][1]['tracking_no']], 'item lines take the date and address of their order\'s head line');
        $va = $this->makeUser('VA'); $wh = $this->makeUser('WAREHOUSE');
        $up = fn () => UploadedFile::fake()->createWithContent('eBay-OrdersReport.csv', $this->ebay());
        $this->actingAs($wh)->post('/api/orders/import', ['shop' => $this->shop, 'file' => $up()], ['Accept' => 'application/json'])->assertStatus(422);
        $r = $this->actingAs($va)->post('/api/orders/import', ['shop' => $this->shop, 'file' => $up()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 3, 1], [$r['orders'], $r['new'], $r['unmatched']]);
        $this->assertSame(3, Stock::available($this->p) === 47 ? 3 : 0, 'the 3 units of ALX-127 are reserved');
        $again = $this->actingAs($va)->post('/api/orders/import', ['shop' => $this->shop, 'file' => $up()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([0, 3], [$again['new'], $again['updated']], 'loading the same file twice adds nothing');
        $this->assertSame(47, Stock::available($this->p));
    }

    /** The exact layout of the owner's real eBay orders report (April 2026): empty first line, full header, a line of "" , dates like 30-Apr-26, amounts with £, a footer. All values made up. */
    public function test_the_real_ebay_orders_report_layout_is_read_line_by_line(): void
    {
        $h = '"Sales record number","Order number","Buyer username","Buyer name","Buyer email","Buyer note",Buyer address 1,Buyer address 2,Buyer city,"Buyer county","Buyer postcode","Buyer country","Buyer tax identifier name","Buyer tax identifier value",Post to name,"Post to phone",Post to address 1,Post to address 2,Post to city,"Post to county","Post to postcode","Post to country","Item number","Item title",Custom label,"Sold via Promoted listings","Quantity","Sold for","Postage and packaging",Item location,"Item postcode","Item country","Included VAT rate","eBay Collect And Remit Tax Rate","eBay Collect And Remit Tax Type","eBay reference name",eBay reference value,Tax status,"Seller collected tax","eBay collected tax","Buyer Protection fee","Electronic waste recycling fee","Mattress recycling fee","Battery recycling fee","White goods disposal tax","Tyre recycling fee","Additional fee","Lumber fee","Prepaid wireless fee","Road improvement and food delivery fee","Total price","eBay collected tax and fees included in total","Payment method","Sale date","Paid on date","Post by date","Minimum estimated delivery date","Maximum estimated delivery date","Dispatched on date","Feedback left","Feedback received","My item note","PayPal transaction ID","Delivery service","Tracking number","Transaction ID",Variation details,"Global Shipping Programme","Global Shipping Reference ID","Click and Collect","Click and Collect Reference Number","eBay Plus","Authenticity verification programme","Authenticity verification status","Authenticity verification outcome reason","eBay Fulfilment Programme",Tax city,"Tax county","Tax postcode","Tax country","eBay International Shipping"';
        $n = count(str_getcsv($h));
        $row = function (array $set) use ($h, $n) { $cols = str_getcsv($h); $v = array_fill(0, $n, ''); foreach ($set as $k => $x) $v[array_search($k, $cols, true)] = $x; return '"' . implode('","', $v) . '"'; };
        $line = fn ($rec, $no, $sku, $qty, $sold, $date) => $row(['Sales record number' => $rec, 'Order number' => $no, 'Buyer username' => 'user' . $rec, 'Buyer name' => '--', 'Post to name' => '--', 'Post to address 1' => '--', 'Post to city' => 'Town', 'Post to postcode' => 'AB1 2CD', 'Post to country' => 'United Kingdom',
            'Item number' => '397755668205', 'Item title' => 'Made up item', 'Custom label' => $sku, 'Quantity' => $qty, 'Sold for' => $sold, 'Postage and packaging' => '£0.00', 'Total price' => '£3.30', 'Sale date' => $date, 'Paid on date' => $date, 'Post by date' => '11-May-26', 'Delivery service' => 'Other courier (3 to 5 days)', 'Tracking number' => 'GV263374163GB', 'Transaction ID' => '10081441010019']);
        $csv = "\u{FEFF}" . str_repeat(',', 40) . "\n" . $h . "\n" . '"' . implode('","', array_fill(0, $n, '')) . '"' . "\n"
            . implode("\n", [$line('891', '19-14558-57530', 'ALX-127', '1', '£2.99', '30-Apr-26'), $line('889', '15-14531-54300', 'B178-BELT', '2', '£3.10', '22-Apr-26')]) . "\n\n2,record(s) downloaded,\nSeller ID : made-up-seller\n";
        $x = OrderFiles::read($this->file('APRIL eBay-OrdersReport.csv', $csv), 'csv');
        $this->assertSame(['EBAY_ORDERS', 2, []], [$x['format'], count($x['lines']), $x['skipped']]);
        $this->assertSame(['19-14558-57530', '2026-04-30', 'ALX-127', 1, 2.99, 'GB', '2026-05-11', 'GV263374163GB'], [$x['lines'][0]['order_no'], $x['lines'][0]['d_order'], $x['lines'][0]['sku'], $x['lines'][0]['qty'], $x['lines'][0]['sale_rate'], $x['lines'][0]['ship_to_country_code'], $x['lines'][0]['dispatch_by'], $x['lines'][0]['tracking_no']]);
        $this->assertSame(['2026-04-22', 2, 3.1], [$x['lines'][1]['d_order'], $x['lines'][1]['qty'], $x['lines'][1]['sale_rate']]);
        $this->assertSame('', $x['lines'][0]['ship_to_name'], 'eBay writes -- where it hides the buyer\'s name: it is read as empty');
    }

    public function test_temu_order_list_skips_cancelled_lines_and_the_sale_value_follows_the_choice(): void
    {
        $x = OrderFiles::read($this->file('temu.csv', $this->temu()), 'csv', ['temu_value' => 'BASE']);
        $this->assertSame(['TEMU_ORDERS', 1, 1.8, '2026-09-28', 'GB', 'SS13 2HE'], [$x['format'], count($x['lines']), $x['lines'][0]['sale_rate'], $x['lines'][0]['d_order'], $x['lines'][0]['ship_to_country_code'], $x['lines'][0]['ship_to_postcode']]);
        $this->assertStringContainsString('cancelled', $x['skipped'][0]);
        $this->assertSame(2.0, OrderFiles::read($this->file('temu.csv', $this->temu()), 'csv', ['temu_value' => 'RETAIL'])['lines'][0]['sale_rate'], 'retail 4.00 ÷ 2 units');
        $this->postJson('/api/choices', ['key' => 'TEMU_SALE_VALUE', 'value' => 'RETAIL'])->assertOk();
        $r = OrderFiles::import(1, $this->shop, $this->file('temu.csv', $this->temu()), 'temu.csv');
        $this->assertSame([1, 1, 0], [$r['orders'], $r['new'], $r['unmatched']]);
        $this->assertSame('4.00', DB::table('t_sales')->where('order_no', 'PO-210-1')->value('total_amount'));
        // a table that is no order list is refused plainly
        try { OrderFiles::read($this->file('x.csv', "a,b,c\n1,2,3\n"), 'csv'); $this->fail('junk'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not an order list', $e->getMessage()); }
        $m = OrderFiles::read($this->file('other.csv', "Order ID,Date,SKU,Quantity,Total\nX-1,2026-10-02,ALX-127,3,12.00\n"), 'csv');
        $this->assertSame(['MAPPED', 4.0, 3], [$m['format'], $m['lines'][0]['sale_rate'], $m['lines'][0]['qty']]);
    }

    public function test_bank_file_from_excel_is_read_like_the_csv_with_excel_dates(): void
    {
        $profile = (int) DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
        $ss = new Spreadsheet(); $s = $ss->getActiveSheet();
        $s->fromArray([['Date', 'Description', 'Amount', 'Transaction ID'], [46280, 'AMAZON PAYMENTS', 1234.56, 'T-1'], ['16/09/2026', 'ROYAL MAIL', -12.5, 'T-2']], null, 'A1');   // 46280 = 15 Sep 2026 as an Excel day
        $path = $this->dir . '/stmt.xlsx'; (new Xlsx($ss))->save($path);
        $fin = $this->makeUser('FINANCE');
        $r = $this->actingAs($fin)->post("/api/bank/{$this->bank}/import", ['profile' => $profile, 'file' => new UploadedFile($path, 'stmt.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, []], [$r['new'], $r['errors']]);
        $this->assertSame(['2026-09-15', '1234.56'], [DB::table('fin_bank_transactions')->where('bank_txn_id', 'T-1')->value('txn_date'), DB::table('fin_bank_transactions')->where('bank_txn_id', 'T-1')->value('amount')]);
        $this->assertSame(0, $this->actingAs($fin)->post("/api/bank/{$this->bank}/import", ['profile' => $profile, 'file' => new UploadedFile($path, 'stmt.xlsx', null, null, true)], ['Accept' => 'application/json'])->json('new'), 'the same file twice adds nothing');
    }

    public function test_one_bank_line_split_over_freight_customs_and_a_supplier_term_and_undo_takes_it_all_back(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Local Supplier', 'f_active' => 1]);
        $po = Buy::createPo(['supplier' => $sup, 'currency' => 'GBP', 'date' => '2026-09-01', 'lines' => [['product' => $this->p, 'qty' => 100, 'unit_price' => 3.00]]]); Buy::confirmPo($po);
        $term = (int) DB::table('t_src_payment_terms')->where('fk_id_po', $po)->where('term_no', 1)->value('id');                       // deposit 30 % = 90
        $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-09-10']);
        $cus = X::saveCustoms(1, ['shipment' => $ship, 'customs_value' => 300, 'currency' => 'GBP', 'duty_rate' => 10, 'import_vat' => 66]);   // duty 30 + VAT 66
        $line = DB::table('fin_bank_transactions')->insertGetId(['batch_id' => 0, 'company_id' => 1, 'bank_account_id' => $this->bank, 'txn_date' => '2026-09-12', 'description' => 'ONE PAYMENT', 'amount' => -400.00, 'currency' => 'GBP', 'amount_gbp' => -400.00, 'match_status' => 'UNMATCHED', 'bank_txn_id' => 'SPLIT-1']);
        $parts = [['kind' => 'CATEGORY', 'category' => 'FREIGHT', 'amount' => 214], ['kind' => 'CUSTOMS', 'customs' => $cus, 'amount' => 96, 'import_vat' => 66], ['kind' => 'SUPPLIER', 'term' => $term, 'amount' => 90]];
        $fin = $this->makeUser('FINANCE'); $git = Ledger::balance(1, '1210');
        $this->actingAs($fin)->postJson("/api/bank-lines/$line/split", ['parts' => [['kind' => 'CATEGORY', 'category' => 'FREIGHT', 'amount' => 100], ['kind' => 'SUPPLIER', 'term' => $term, 'amount' => 90]]])->assertStatus(422)->assertJsonFragment(['message' => 'The parts add up to 190.00, the bank line is 400.00. They must be equal.']);
        $this->actingAs($fin)->postJson("/api/bank-lines/$line/split", ['parts' => [['kind' => 'CATEGORY', 'category' => 'FREIGHT', 'amount' => 210], ['kind' => 'SUPPLIER', 'term' => $term, 'amount' => 190]]])->assertStatus(422);   // more than the term
        $this->actingAs($fin)->postJson("/api/bank-lines/$line/split", ['parts' => $parts])->assertOk()->assertJsonPath('parts', 3);
        $this->assertSame([214.0, 30.0, 66.0, 90.0, 600.0], [Ledger::balance(1, '5200'), round(Ledger::balance(1, '1210') - $git, 2), Ledger::balance(1, '1400'), Ledger::balance(1, '1300', null, $sup), Ledger::balance(1, '1000', null, $this->bank)], 'freight 214 · duty 30 into goods on the way · import VAT 66 apart · supplier deposit 90 · bank 1,000 − 400');
        $this->assertSame(['PAID', 'PAID', 3], [DB::table('t_src_customs')->where('id', $cus)->value('pay_status'), DB::table('t_src_payment_terms')->where('id', $term)->value('status'), DB::table('fin_bank_line_splits')->where('fk_id_bank_line', $line)->count()]);
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // undo: every part back
        $this->actingAs($fin)->postJson("/api/bank-lines/$line/unmatch")->assertOk();
        $this->assertSame([0.0, 0.0, 0.0, 1000.0, 'UNPAID', 'DUE', 0], [Ledger::balance(1, '5200'), Ledger::balance(1, '1400'), Ledger::balance(1, '1300', null, $sup) + (Ledger::balance(1, '1210') - $git), Ledger::balance(1, '1000', null, $this->bank),
            DB::table('t_src_customs')->where('id', $cus)->value('pay_status'), DB::table('t_src_payment_terms')->where('id', $term)->value('status'), DB::table('fin_bank_line_splits')->where('fk_id_bank_line', $line)->count()]);
        $this->assertSame('UNMATCHED', DB::table('fin_bank_transactions')->where('id', $line)->value('match_status'));
    }

    public function test_cash_forecast_puts_payouts_terms_and_customs_into_their_weeks(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Local Supplier', 'f_active' => 1]);
        $po = Buy::createPo(['supplier' => $sup, 'currency' => 'GBP', 'date' => date('Y-m-d'), 'lines' => [['product' => $this->p, 'qty' => 100, 'unit_price' => 2.00]]]); Buy::confirmPo($po);   // deposit 60 now, balance 140 in 30 days
        DB::table('mo_settlements')->insert(['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'EBAY_TRANSACTION', 'statement_id' => 'EBAY-1', 'statement_date' => date('Y-m-d'), 'payout_date' => date('Y-m-d', strtotime('+8 day')), 'currency' => 'GBP', 'payable' => 500, 'status' => 'POSTED', 'created_at' => now()]);
        $x = CashForecast::build(1, 8);
        $this->assertSame(1000.0, $x['bank_now']);
        $byWhat = collect($x['items'])->keyBy('what');
        $this->assertSame([0, 60.0], [$byWhat->first(fn ($i) => str_contains($i['what'], 'Deposit'))['week'], $byWhat->first(fn ($i) => str_contains($i['what'], 'Deposit'))['amount']]);
        $this->assertGreaterThanOrEqual(1, $byWhat->first(fn ($i) => str_contains($i['what'], 'Payout'))['week']);
        $this->assertSame(1300.0, end($x['rows'])['balance'], '1,000 + 500 − 60 − 140');
        $this->getJson('/api/cash-forecast?weeks=4')->assertOk()->assertJsonCount(5, 'rows');
        $this->actingAs($this->makeUser('WAREHOUSE'))->getJson('/api/cash-forecast')->assertStatus(403);
    }
}
