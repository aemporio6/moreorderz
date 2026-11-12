<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\MarketplaceFiles as MF;
use App\Services\MoneyService as Money;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SettlementService as Settle;
use App\Services\StockService as Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Money files of Amazon, eBay and "any other table" (OnBuy, Temu). All files here are made up, in the layout of the real ones. */
class MarketplaceFilesTest extends TestCase
{
    private int $shop; private int $p; private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 100, 'location' => $bin, 'unit_cost' => 4.00, 'received_date' => '2026-08-01']], 'GRN', 1);
        PriceService::set($this->p, $mp, 8, 20.00, '2026-08-01');
        $this->dir = sys_get_temp_dir() . '/mo_mf_' . getmypid(); @mkdir($this->dir);
    }

    private function file(string $name, string $text): string { $p = $this->dir . '/' . $name; file_put_contents($p, $text); return $p; }

    private function amazon(float $total = 4.14): string
    {
        $h = "settlement-id\tsettlement-start-date\tsettlement-end-date\tdeposit-date\ttotal-amount\tcurrency\ttransaction-type\torder-id\tmerchant-order-id\tadjustment-id\tshipment-id\tmarketplace-name\tamount-type\tamount-description\tamount\tfulfillment-id\tposted-date\tposted-date-time\torder-item-code\tmerchant-order-item-id\tmerchant-adjustment-item-id\tsku\tquantity-purchased\tpromotion-id";
        $l = fn (string $txn, string $order, string $type, string $desc, string $amt, string $sku = 'BOWL-01', string $q = '') => "777001\t\t\t\t\t\t$txn\t$order\t\t\t\tAmazon.co.uk\t$type\t$desc\t$amt\tAFN\t2026-09-05\t2026-09-05 10:11:12 UTC\t\t\t\t$sku\t$q\t";
        return implode("\n", [$h, "777001\t2026-09-01 00:00:00 UTC\t2026-09-14 00:00:00 UTC\t2026-09-16 00:00:00 UTC\t" . number_format($total, 2, '.', '') . "\tGBP\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t",
            $l('Order', 'AMZ-1', 'ItemPrice', 'Principal', '20.00', 'BOWL-01', '1'), $l('Order', 'AMZ-1', 'ItemPrice', 'Shipping', '2.00'), $l('Order', 'AMZ-1', 'Promotion', 'Shipping', '-2.00'),
            $l('Order', 'AMZ-1', 'ItemFees', 'Commission', '-3.06'), $l('Order', 'AMZ-1', 'ItemFees', 'FBAPerUnitFulfillmentFee', '-2.50'),
            $l('Refund', 'AMZ-0', 'ItemPrice', 'Principal', '-10.00'), $l('Refund', 'AMZ-0', 'ItemFees', 'RefundCommission', '-0.30'), $l('Refund', 'AMZ-0', 'ItemFees', 'Commission', '1.50'),
            $l('ServiceFee', '', 'Cost of Advertising', 'TransactionTotalAmount', '-4.00', ''), $l('other-transaction', '', 'other-transaction', 'Storage Fee', '-1.00', ''), $l('other-transaction', '', 'other-transaction', 'Current Reserve Amount', '-5.00', ''),
            $l('other-transaction', '', 'other-transaction', 'Previous Reserve Amount Balance', '3.00', ''), $l('other-transaction', '', 'FBA Inventory Reimbursement', 'WAREHOUSE_DAMAGE', '6.00', ''), $l('other-transaction', '', 'Mystery', 'New thing', '-0.50', '')]) . "\n";
    }

    private function ebay(bool $more = false): string
    {
        $pre = "\u{FEFF}--,--,--,--\nNotes\nGross transaction amount: The total payment for a transaction before any fees or credits are applied.\n\nTransaction report\nSeller,made-up-seller\nStart date,01/09/2026 00:00:00 am BST\nEnd date,30/09/2026 23:59:59 pm BST\n";
        $h = "Transaction creation date,Type,Order number,Legacy order ID,Buyer username,Buyer name,Post to city,Post to province/region/state,Post to postcode,Post to country,Net amount,Payout currency,Payout date,Payout ID,Payout method,Payout status,Reason for hold,Item ID,Transaction ID,Item title,Custom label,Quantity,Item subtotal,Postage and packaging,Seller collected tax,eBay collected tax,Seller specified VAT rate,Final value fee – fixed,Final value fee – variable,Regulatory operating fee,Very high 'item not as described' fee,Below standard performance fee,International fee,Gross transaction amount,Transaction currency,Exchange rate,Reference ID,Description";
        $row = fn (string $date, string $type, string $order, string $net, string $pid, string $pdate, string $gross = '--', array $fees = ['--', '--', '--'], string $desc = '--', string $qty = '--') => implode(',', [$date, $type, $order, $order, 'buyer1', 'Made Up', 'Town', '--', 'AB1 2CD', 'GB', $net, 'GBP', $pdate, $pid, 'Bank *0001', $pid === '--' ? 'Processing' : 'Funds sent', '--', '1', '2', '"Dog bowl, steel"', 'BOWL-01', $qty, $gross, '0', '--', '--', '20%', $fees[0], $fees[1], $fees[2], '--', '--', '--', $gross === '--' ? $net : $gross, 'GBP', '--', 'REF', $desc]);
        $rows = [$row('5 Sep 2026', 'Order', 'EB-1', '17.32', '9001', '7 Sep 2026', '20', ['-0.36', '-2.26', '-0.06'], '--', '1'), $row('5 Sep 2026', 'Other fee', 'EB-1', '-1.20', '9001', '7 Sep 2026', '--', ['--', '--', '--'], 'Promoted Listings - General fee'),
            $row('6 Sep 2026', 'Refund', 'EB-1', '-4.40', '9001', '7 Sep 2026', '-5', ['--', '0.6', '--'], '--', '1'), $row('6 Sep 2026', 'Postage label', '--', '-2.95', '9001', '7 Sep 2026', '--', ['--', '--', '--'], 'Royal Mail label'),
            $row('7 Sep 2026', 'Payout', '--', '-8.77', '9001', '7 Sep 2026'), $row('20 Sep 2026', 'Order', 'EB-2', '34.64', '9002', '22 Sep 2026', '40', ['-0.72', '-4.52', '-0.12'], '--', '2'),
            $row('21 Sep 2026', 'Loyalty bonus', '--', '1.00', '9002', '22 Sep 2026', '--', ['--', '--', '--'], 'Something new'), $row('30 Sep 2026', 'Order', 'EB-3', '17.32', '--', '--', '20', ['-0.36', '-2.26', '-0.06'], '--', '1')];
        if ($more) $rows = [$row('1 Oct 2026', 'Other fee', '--', '-0.64', '9002', '22 Sep 2026', '--', ['--', '--', '--'], 'Shop subscription fee')];
        return $pre . $h . "\n" . implode("\n", $rows) . "\n";
    }

    public function test_helpers_read_money_and_dates_of_every_style(): void
    {
        $this->assertSame([95.0, 1234.56, 1234.56, -3.5, 0.0, -7.25, 1200.0, 12.5], [MF::num('95,00'), MF::num('1.234,56'), MF::num('£1,234.56'), MF::num('-3.50'), MF::num('--'), MF::num('(7.25)'), MF::num('1,200'), MF::num('12,5')]);
        $this->assertSame('2026-09-02', MF::day('2 Sept 2026 10:00:00 UTC'));
        $this->assertSame(['2026-04-30', '2026-09-16', '2026-10-01', '2026-09-28', '2026-03-05', '2026-10-31'], [MF::day('30 Apr 2026'), MF::day('2026-09-16 00:00:00 UTC'), MF::day('01.10.2026 00:00:11 UTC'), MF::day('Sep 28, 2026, 3:35 am PKT(UTC+5)'), MF::day('5th Mar 2026'), MF::day('31/10/2026')]);
    }

    public function test_amazon_settlement_loads_only_when_it_adds_up_and_books_every_amount_type_to_its_account(): void
    {
        try { MF::import(1, $this->shop, $this->file('amz_bad.txt', $this->amazon(9.99)), 'amz_bad.txt'); $this->fail('total'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('nothing was loaded', $e->getMessage()); }
        $this->assertSame(0, DB::table('mo_settlements')->count());
        $r = MF::import(1, $this->shop, $this->file('amz.txt', $this->amazon()), 'amz.txt');
        $this->assertSame(['AMAZON_V2', 1, 14, ['Mystery / New thing'], false], [$r['format'], $r['statements_new'], $r['lines_new'], $r['new_columns'], $r['all_ok']], 'an unknown type is loaded, named and makes the check yellow');
        $s = DB::table('mo_settlements')->where('statement_id', '777001')->first();
        $this->assertSame(['4.14', '10.00', '2026-09-14', '2026-09-16', 'AMAZON_SETTLEMENT'], [$s->total, $s->net_sales, $s->statement_date, $s->payout_date, $s->report_type]);
        $again = MF::import(1, $this->shop, $this->file('amz.txt', $this->amazon()), 'amz.txt');
        $this->assertSame([0, 14, 0], [$again['lines_new'], $again['lines_already'], $again['statements_new']], 'the same file twice adds nothing');
        Settle::post(1, (int) $s->id);
        $bal = fn (string $a, bool $shop = true) => Ledger::balance(1, $a, null, $shop ? $this->shop : null);
        $this->assertSame([-10.0, 4.14, 2.0, 4.0, 3.5, -6.0], [$bal('1100'), $bal('1110'), $bal('1120'), $bal('5300'), Ledger::balance(1, '5200'), Ledger::balance(1, '4100')], 'sales settled, payout on the way, reserve, ads, fulfilment + storage, reimbursement');
        $this->assertSame(2.36, Ledger::balance(1, '5100'), 'commission 3.06 + 0.30 − 1.50 + unknown 0.50');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // the payout is found for the bank matching by its exact amount
        $this->assertCount(1, Settle::payoutCandidates(1, 4.14, 'GBP', '2026-09-16', 3));
    }

    private function amazonTxn(): string
    {
        $pre = "\"Includes Amazon Marketplace, Fulfilment by Amazon (FBA), and Amazon Webstore transactions\"\n\"All amounts in GBP, unless specified\"\n\"Definitions:\"\n\"Date/Time: Posted date/time of the transaction\"\n";
        $h = '"date/time","settlement id","type","order id","sku","description","quantity","marketplace","fulfilment","order city","order state","order postal","tax collection model","product sales","product sales tax","postage credits","shipping credits tax","gift wrap credits","giftwrap credits tax","promotional rebates","promotional rebates tax","marketplace withheld tax","selling fees","fba fees","other transaction fees","other","total","Transaction status","Transaction Release Date"';
        $row = fn (string $date, string $sid, string $type, string $order, string $desc, array $a, string $total) => '"' . implode('","', [$date, $sid, $type, $order, $order ? 'BOWL-01' : '', $desc, $order ? '1' : '', 'amazon.co.uk', 'Amazon', 'Town', '', 'AB1 2CD', '', ...array_map(fn ($k) => $a[$k] ?? '0', ['ps', 'pst', 'pc', 'sct', 'gw', 'gwt', 'pr', 'prt', 'mwt', 'sf', 'fba', 'otf', 'oth']), $total, 'Released', $date]) . '"';
        return $pre . $h . "\n" . implode("\n", [
            $row('1 Sept 2026 10:00:00 UTC', '5001', 'Order', 'AMZ-1', 'Dog bowl', ['ps' => '20.00', 'pc' => '2.00', 'pr' => '-2.00', 'sf' => '-3.06', 'fba' => '-2.50'], '14.44'),
            $row('2 Sept 2026 10:00:00 UTC', '5001', 'Refund', 'AMZ-0', 'Dog bowl', ['ps' => '-10.00', 'sf' => '1.20'], '-8.80'),
            $row('3 Sept 2026 10:00:00 UTC', '5001', 'FBA Inventory Fee', '', 'Fulfilment by Amazon (FBA) Inventory Storage Fee', ['oth' => '-1.00'], '-1.00'),
            $row('3 Sept 2026 11:00:00 UTC', '5001', 'Adjustment', '', 'FBA Inventory Reimbursement - Lost:Warehouse', ['oth' => '6.00'], '6.00'),
            $row('4 Sept 2026 10:00:00 UTC', '5001', 'Amazon Fees', '', 'Deal participation fee', ['otf' => '-4.00'], '-4.00'),
            $row('5 Sept 2026 10:00:00 UTC', '5001', 'Service Fee', '', 'Subscription', ['oth' => '-30.00'], '-30.00'),
            $row('8 Sept 2026 09:00:00 UTC', '5002', 'Transfer', '', 'To account ending with: 526', ['oth' => '23.36'], '23.36'),      // made up: settlement 5001 ends below zero, so its "payout" is money taken back
            $row('8 Sept 2026 09:30:00 UTC', '5002', 'Order', 'AMZ-2', 'Dog bowl', ['ps' => '1,020.00', 'sf' => '-153.00', 'fba' => '-2.50'], '864.50'),
            $row('9 Sept 2026 09:30:00 UTC', '5002', 'Mystery type', '', 'New thing', ['oth' => '-0.50'], '-0.50'),
        ]) . "\n";
    }

    public function test_amazon_date_range_report_ties_each_payout_to_the_settlement_before_it(): void
    {
        $x = MF::read($this->file('CustomTransaction.csv', $this->amazonTxn()), 'csv');
        $this->assertSame(['AMAZON_TXN', 2, [], ['Mystery type / other / New thing']], [$x['format'], count($x['statements']), $x['errors'], $x['unknown']]);
        $this->assertSame(['2026-09-08', '*526', null], [$x['statements']['5001']['payout_date'], $x['statements']['5001']['bank'], $x['statements']['5002']['payout_date']], 'the Transfer line among the lines of 5002 pays settlement 5001 (−23.36 = its lines)');
        $r = MF::import(1, $this->shop, $this->file('CustomTransaction.csv', $this->amazonTxn()), 'CustomTransaction.csv');
        $a = DB::table('mo_settlements')->where('statement_id', '5001')->first(); $b = DB::table('mo_settlements')->where('statement_id', '5002')->first();
        $this->assertSame(['-23.36', '10.00', '864.00', '1020.00', 'AMAZON_TRANSACTION'], [$a->payable, $a->net_sales, $b->payable, $b->net_sales, $a->report_type], '1,020.00 with a thousands comma is read right');
        $this->assertFalse($r['all_ok']); $this->assertStringContainsString('1 of 2 settlement(s) are tied to a payout', $r['checks'][1]['detail']);
        Settle::postAll(1, $this->shop);
        $this->assertSame([840.64, 1.0, 4.0, -6.0, 5.0], [Ledger::balance(1, '1110', null, $this->shop), Ledger::balance(1, '5200') - 5.0, Ledger::balance(1, '5300', null, $this->shop), Ledger::balance(1, '4100'), Ledger::balance(1, '5200') - 1.0]);
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // the same settlement from the other Amazon report is refused, so nothing counts twice
        $v2 = str_replace('777001', '5001', $this->amazon());
        try { MF::import(1, $this->shop, $this->file('v2.txt', $v2), 'v2.txt'); $this->fail('twice'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already loaded from another kind of report', $e->getMessage()); }
        // a line that does not add up to its own total stops the whole file
        try { MF::import(1, $this->shop, $this->file('bad.csv', str_replace('"14.44"', '"15.44"', $this->amazonTxn())), 'bad.csv'); $this->fail('sum'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('do not add up', $e->getMessage()); }
    }

    public function test_ebay_report_groups_by_payout_makes_refund_proposals_and_takes_late_lines_of_a_payout(): void
    {
        $sale = Sales::intake($this->shop, [['order_no' => 'EB-1', 'd_order' => '2026-09-05', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 20]])['ids'][0];
        Sales::confirmMonth($this->shop, '2026-09', true);
        $r = MF::import(1, $this->shop, $this->file('Transaction_report.csv', $this->ebay()), 'Transaction_report.csv');
        $this->assertSame(['EBAY', 2, ['Loyalty bonus'], 1, 17.32, 1], [$r['format'], $r['statements_new'], $r['new_columns'], $r['skipped']['rows'], $r['skipped']['amount'], $r['refund_proposals']]);
        $this->assertTrue($r['checks'][0]['ok']); $this->assertTrue($r['checks'][1]['ok'], 'payout line 8.77 = the lines of payout 9001');
        $a = DB::table('mo_settlements')->where('statement_id', 'EBAY-9001')->first(); $b = DB::table('mo_settlements')->where('statement_id', 'EBAY-9002')->first();
        $this->assertSame(['8.77', '15.00', '-2.95', '35.64', '2026-09-07'], [$a->payable, $a->net_sales, $a->shipping, $b->payable, $a->payout_date]);
        $this->assertSame(0, DB::table('fin_settlement_lines')->where('order_id', 'EB-3')->count(), 'a line without a payout waits for the next file');
        $p = DB::table('t_sale_refund_proposals')->where('fk_id_sale', $sale)->first();
        $this->assertSame(['PARTIAL', '5.00'], [$p->refund_kind, $p->amount]);
        $this->assertSame(2, Settle::postAll(1, $this->shop));
        $this->assertSame([44.41, -35.0], [Ledger::balance(1, '1110', null, $this->shop), Ledger::balance(1, '1100', null, $this->shop)], '20 confirmed − 55 settled: EB-2 is not entered in the tool');
        Settle::decideRefund(1, (int) $p->id, true);
        $this->assertSame([5.0, 5.0], [Ledger::balance(1, '5400'), (float) DB::table('t_sales')->where('id', $sale)->value('refund_loss_amount')], 'a part refund from the file is booked with its amount');
        // next month's file brings one more line of payout 9002: the payout is opened again and booked with all its lines
        $r2 = MF::import(1, $this->shop, $this->file('Transaction_report_oct.csv', $this->ebay(true)), 'Transaction_report_oct.csv');
        $this->assertSame([1, 1, 0], [$r2['lines_new'], $r2['statements_reopened'], $r2['statements_new']]);
        $b = DB::table('mo_settlements')->where('statement_id', 'EBAY-9002')->first();
        $this->assertSame(['LOADED', '35.00'], [$b->status, $b->payable]);
        Settle::post(1, (int) $b->id);
        $this->assertSame(43.77, Ledger::balance(1, '1110', null, $this->shop));
        // a payout already matched to the bank is never changed
        DB::table('mo_settlements')->where('id', $a->id)->update(['status' => 'PAID']);
        $late = str_replace('Royal Mail label', 'Royal Mail label 2', $this->ebay());
        $r3 = MF::import(1, $this->shop, $this->file('late.csv', $late), 'late.csv');
        $this->assertSame([0, false], [$r3['lines_new'], $r3['all_ok']]); $this->assertStringContainsString('NOT loaded', end($r3['checks'])['detail']);
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_any_other_table_is_read_by_its_column_names_and_refuses_what_is_not_a_money_file(): void
    {
        $onbuy = "OnBuy remittance (made up)\n\nOrder ID,Date,Product,Qty,Selling Price,OnBuy Fee,VAT on OnBuy Fee,Refund,Total\nT6A,5th Sep 2026,Bowl,1,£32.00,4.80,0.96,0,26.24\nT6B,6th Sep 2026,Bowl,2,£20.00,3.00,0.60,20.00,-3.60\n";
        try { MF::import(1, $this->shop, $this->file('onbuy.csv', $onbuy), 'onbuy.csv', ['platform' => 'ONBUY']); $this->fail('ref'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('payout reference', $e->getMessage()); }
        $r = MF::import(1, $this->shop, $this->file('onbuy.csv', $onbuy), 'onbuy.csv', ['platform' => 'ONBUY', 'reference' => 'REM-0915', 'payout_date' => '2026-09-15']);
        $this->assertSame(['MAPPED', 1, true], [$r['format'], $r['statements_new'], $r['all_ok']]);
        $s = DB::table('mo_settlements')->where('statement_id', 'ONBUY-REM-0915')->first();
        $this->assertSame(['22.64', '32.00', '2026-09-15', 'ONBUY_FILE'], [$s->payable, $s->net_sales, $s->payout_date, $s->report_type], 'fees written as plus figures are read as costs because only then the rows add up to Total');
        $this->assertContains(['OnBuy Fee', 'Commission / selling fees – read as a cost (minus)'], $r['mapping']); $this->assertSame([], $r['unused']);
        // VAT on fees follows the company's VAT choice
        $this->actingAs($this->owner)->postJson('/api/choices', ['key' => 'VAT_REGISTERED', 'value' => '1'])->assertOk();
        Settle::post(1, (int) $s->id);
        $this->assertSame([1.56, 7.8, 22.64], [Ledger::balance(1, '1400'), Ledger::balance(1, '5100'), Ledger::balance(1, '1110', null, $this->shop)]);
        // long format with its own settlement number
        $temu = "Settlement ID,Order ID,Transaction type,Amount,Settlement time\nB77,PO-1,Order payment,12.00,2026-09-10\nB77,PO-1,Platform commission,-1.20,2026-09-10\nB77,PO-2,Refund,-6.00,2026-09-11\nB77,,Strange line,-0.10,2026-09-11\n";
        $t = MF::import(1, $this->shop, $this->file('temu_funds.csv', $temu), 'temu_funds.csv', ['platform' => 'TEMU']);
        $this->assertSame([1, 4, ['Strange line']], [$t['statements_new'], $t['lines_new'], $t['new_columns']]);
        $this->assertSame(['4.70', '6.00'], [DB::table('mo_settlements')->where('statement_id', 'TEMU-B77')->value('payable'), DB::table('mo_settlements')->where('statement_id', 'TEMU-B77')->value('net_sales')]);
        // Temu's order list is not a money file
        $orders = "Order ID,order status,Order item ID,SKU ID,quantity purchased,goods base price,order settlement status,retail price total\nPO-1,Delivered,1-1,55,1,1.80,Settled,2.00\n";
        try { MF::import(1, $this->shop, $this->file('temu.csv', $orders), 'temu.csv', ['platform' => 'TEMU', 'reference' => 'X']); $this->fail('orders'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('order list', $e->getMessage()); }
        try { MF::import(1, $this->shop, $this->file('junk.csv', "a,b\n1,2\n"), 'junk.csv'); $this->fail('junk'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('No header line', $e->getMessage()); }
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_upload_over_the_api_finds_the_format_and_keeps_roles(): void
    {
        $fin = $this->makeUser('FINANCE'); $wh = $this->makeUser('WAREHOUSE');
        $up = fn (string $name, string $text) => UploadedFile::fake()->createWithContent($name, $text);
        $this->actingAs($wh)->post('/api/settlements/import', ['shop' => $this->shop, 'file' => $up('a.txt', $this->amazon())], ['Accept' => 'application/json'])->assertStatus(403);
        $this->actingAs($fin)->post('/api/settlements/import', ['shop' => $this->shop, 'file' => $up('a.txt', $this->amazon())], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('format', 'AMAZON_V2')->assertJsonPath('statements_new', 1);
        $this->actingAs($fin)->post('/api/settlements/import', ['shop' => $this->shop, 'file' => $up('e.csv', $this->ebay())], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('format', 'EBAY');
        $this->actingAs($fin)->post('/api/settlements/import', ['shop' => $this->shop, 'file' => $up('bad.txt', $this->amazon(1.00))], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($fin)->post('/api/settlements/import', ['shop' => $this->shop, 'file' => $up('x.pdf', 'x')], ['Accept' => 'application/json'])->assertStatus(422);
        $d = $this->actingAs($fin)->getJson('/api/settlements')->assertOk()->json();
        $this->assertCount(3, $d['rows']); $this->assertCount(2, $d['periods']);
    }
}
