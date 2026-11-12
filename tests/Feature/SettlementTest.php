<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\MoneyService as Money;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SettlementService as Settle;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    private int $shop; private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $f('cat_marketplaces', ['fk_id_ent' => 1]), 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1]);
        $this->dir = sys_get_temp_dir() . '/mo_tt_' . getmypid(); if (! is_dir($this->dir)) mkdir($this->dir);
    }

    /** A small workbook in the TikTok layout, made up for the test: 2 statements paid by 1 payment, a fee, a refund and a chargeback. */
    private function workbook(array $change = []): string
    {
        $head = ['Statement date', 'Statement ID', 'Payment ID', 'Status', 'Currency', 'Type', 'Order/adjustment ID', 'SKU ID', 'Quantity', 'Product name', 'SKU name', 'Order created date', 'Order shipment date', 'Order delivery date',
            'Total settlement amount', 'Net sales', 'Gross sales', 'Gross sales refund', 'Seller discount', 'Seller discount refund', 'Shipping', 'Customer-paid shipping fee', 'TikTok Shop shipping fee', 'Fees', 'TikTok Shop commission fee', 'Affiliate Commission',
            'Brand new fee', 'Adjustment amount', 'Adjustment reason', 'Related order ID  '];
        $row = fn ($st, $type, $order, $tot, $net, $gross, $refund, $ship, $cps, $tsf, $fees, $com, $aff, $new, $adj, $reason) => ['2026/09/10', $st, 'PAY1', 'Paid', 'GBP', $type, $order, 'SKU1', $type === 'Order' ? '1' : '/', 'Bowl', 'Default', '2026/09/01', '/', '/',
            (string) $tot, (string) $net, (string) $gross, (string) $refund, '0', '0', (string) $ship, (string) $cps, (string) $tsf, (string) $fees, (string) $com, (string) $aff, (string) $new, (string) $adj, $reason, $order];
        $rows = [
            $row('ST1', 'Order', 'ORD-1', 20.49, 20.00, 20.00, 0, 2.99, 3.99, -1.00, -2.50, -1.80, -0.50, -0.20, 0, '/'),
            $row('ST1', 'Order', 'ORD-2', 0, 0, 15.00, -15.00, 0, 0, 0, 0, 0, 0, 0, 0, '/'),
            $row('ST2', 'Chargeback', 'ADJ-1', -6.00, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, -6.00, 'Chargeback'),
        ];
        $st = [['Statement date', 'Statement ID', 'Payment ID', 'Status', 'Total settlement amount', 'Net sales', 'Shipping', 'Fees', 'Adjustments', 'Reserve Amount', 'Payable amount'],
            ['2026/09/10', 'ST1', 'PAY1', 'Paid', '20.49', '20', '2.99', '-2.5', '0', '0', '20.49'], ['2026/09/10', 'ST2', 'PAY1', 'Paid', '-6', '0', '0', '0', '-6', '0', '-6']];
        $pay = [['Payment initiation date', 'Payment ID', 'Payment amount', 'Payment completion date', 'Status', 'Bank account', 'Notes'], ['2026/09/10', 'PAY1', '14.49', '2026/09/12', 'Paid', '********1234', 'N1']];
        $rep = [[null, 'Time period', null, null, null, '2026/09/01-2026/09/30'], [null, 'Currency', null, null, null, 'GBP'], [null, 'Total settlement amount', null, null, null, '14.49'],
            [null, '', 'Net sales', null, null, '20'], [null, null, null, 'Gross sales', null, '35'], [null, null, null, 'Gross sales refund', null, '-15'],
            [null, null, 'Shipping', null, null, '2.99'], [null, null, null, 'Customer-paid shipping fee', null, '3.99'], [null, null, null, 'TikTok Shop shipping fee', null, '-1'],
            [null, null, 'Fees', null, null, '-2.5'], [null, null, null, 'TikTok Shop commission fee', null, '-1.8'], [null, null, null, 'Affiliate Commission', null, '-0.5'], [null, null, null, 'Brand new fee', null, '-0.2'],
            [null, null, 'Adjustments', null, null, '-6'], [null, null, null, 'Chargeback', null, '-6']];
        if (isset($change['row'])) $rows[0][14] = $change['row'];
        if (isset($change['payment'])) $pay[1][2] = $change['payment'];
        $wb = new Spreadsheet(); $wb->removeSheetByIndex(0);
        foreach (['Order details' => array_merge([$head], $rows), 'Statements' => $st, 'Reports' => $rep, 'Payments' => $pay] as $name => $data) {
            $ws = $wb->createSheet(); $ws->setTitle($name);
            foreach ($data as $r => $cells) foreach ($cells as $c => $v) if ($v !== null) $ws->setCellValueExplicit([$c + 1, $r + 1], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $path = $this->dir . '/tt_' . md5(json_encode($change)) . '.xlsx'; (new Xlsx($wb))->save($path);
        return $path;
    }

    public function test_a_file_that_does_not_add_up_is_refused_whole(): void
    {
        try { Settle::importTikTok(1, $this->shop, $this->workbook(['row' => '20.50']), 'bad.xlsx'); $this->fail('row identity'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('total ≠ net sales + shipping + fees + adjustment', $e->getMessage()); }
        try { Settle::importTikTok(1, $this->shop, $this->workbook(['payment' => '14.50']), 'bad.xlsx'); $this->fail('payment'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('but the payment is 14.50', $e->getMessage()); }
        $this->assertSame(0, DB::table('mo_settlements')->count() + DB::table('fin_settlement_lines')->count());
    }

    public function test_load_book_and_receive_the_payout_from_file_to_bank_to_the_penny(): void
    {
        // an order entered and confirmed in the tool first: the shop owes us 20.00 for ORD-1 and 15.00 for ORD-2
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        DB::table('t_branches')->where('id', $this->shop)->update(['fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $bin, 'unit_cost' => 4]], 'GRN', 1);
        Sales::intake($this->shop, [['order_no' => 'ORD-2', 'd_order' => '2026-09-01', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 15]]);

        $path = $this->workbook();
        $r = Settle::importTikTok(1, $this->shop, $path, 'tt.xlsx');
        $this->assertSame([2, 0, 'OK', 1, 1, ['brand new fee']], [$r['statements_new'], $r['rows_of_statements_not_in_file'], $r['hub_check'], $r['full_refunds'], $r['refund_proposals'], $r['new_columns']]);
        $this->assertSame(['FULL', 15.0, 'PROPOSED'], (array) DB::table('t_sale_refund_proposals')->selectRaw('refund_kind, amount + 0 as a, status')->get()->map(fn ($x) => [$x->refund_kind, (float) $x->a, $x->status])[0]);
        $again = Settle::importTikTok(1, $this->shop, $path, 'tt.xlsx');
        $this->assertSame([0, 2, 0, 0], [$again['statements_new'], $again['statements_already'], $again['lines_new'], $again['refund_proposals']], 'the same file twice changes nothing');
        $this->assertSame(0.0, Ledger::balance(1, '1110', null, $this->shop), 'loading books nothing');

        $this->assertSame(2, Settle::postAll(1, $this->shop));
        $this->assertSame([-20.0, 14.49], [Ledger::balance(1, '1100', null, $this->shop), Ledger::balance(1, '1110', null, $this->shop)]);
        $this->assertSame([2.0, 0.5, -2.99, 6.0], [Ledger::balance(1, '5100'), Ledger::balance(1, '5300'), Ledger::balance(1, '5200'), Ledger::balance(1, '5400')],
            'commission 1.80 + new fee 0.20 → fees; affiliate → advertising; shipping net 3.99 − 1.00 is income of 2.99; chargeback → refunds');
        try { Settle::post(1, (int) DB::table('mo_settlements')->value('id')); $this->fail('twice'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already booked', $e->getMessage()); }

        // the bank: one line of 14.49 pays both statements
        $bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main ••1234', 'currency' => 'GBP']);
        $imp = Money::importStatement(1, $bank, DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id'), "Date,Description,Reference,Amount,Balance,Transaction ID\n12/09/2026,TIKTOK PAYOUT,N1,14.49,,P1\n");
        $this->assertSame(1, $imp['matched']);
        $this->assertSame([14.49, 0.0, 2], [Ledger::balance(1, '1000', null, $bank), Ledger::balance(1, '1110', null, $this->shop), DB::table('mo_settlements')->where('status', 'PAID')->count()]);
        $st = DB::table('mo_settlements')->orderBy('id')->first();
        try { Settle::unpost(1, (int) $st->id); $this->fail('paid'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('matched to a bank line', $e->getMessage()); }
        Money::unmatch(1, (int) DB::table('fin_bank_transactions')->value('id'));
        $this->assertSame([0.0, 14.49, 2], [Ledger::balance(1, '1000', null, $bank), Ledger::balance(1, '1110', null, $this->shop), DB::table('mo_settlements')->where('status', 'POSTED')->count()]);
        // by hand: a person picks the payout for the line
        $line = (int) DB::table('fin_bank_transactions')->value('id');
        try { Money::matchPayout(1, $line, $this->shop, 'NOPE'); $this->fail('wrong payout'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not the same amount', $e->getMessage()); }
        Money::matchPayout(1, $line, $this->shop, 'PAY1');
        $this->assertSame([14.49, 0.0], [Ledger::balance(1, '1000', null, $bank), Ledger::balance(1, '1110', null, $this->shop)]);
        Money::unmatch(1, $line);
        Settle::unpost(1, (int) $st->id);
        $this->assertSame('LOADED', DB::table('mo_settlements')->where('id', $st->id)->value('status'));
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
        $bal = collect(Settle::shopBalances(1))->firstWhere('shop_id', $this->shop);
        $this->assertSame(-6.0, $bal['on_the_way'], 'only the chargeback statement is still booked');
    }

    /** The owner's three real files (not in the public repository). Proves GT-T1, GT-T2, GT-T3 to the penny when the files are on this machine. */
    public function test_the_three_owner_files_pass_every_check_to_the_penny(): void
    {
        $dir = getenv('MO_TT_FILES') ?: '/home/claude/rebuild/VCommerce_Build_Pack_v1.7/23_Test_Data/tiktok_settlement';
        if (! is_file("$dir/shopA_settlement_2026-08-03_2026-10-31.xlsx")) $this->markTestSkipped('Owner test files are not on this machine (set MO_TT_FILES).');
        $want = ['A' => [303.21, 227.94, 129.52, -54.25, 0.0, 59, 28], 'B' => [315.19, 310.09, 76.75, -71.65, 0.0, 28, 20], 'C' => [5106.97, 3432.89, 2636.01, -955.80, -6.13, 833, 89]];
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        foreach ($want as $k => [$tot, $net, $ship, $fees, $adj, $rows, $sts]) {
            $path = "$dir/shop{$k}_settlement_2026-08-03_2026-10-31.xlsx";
            $x = Settle::readTikTok($path); $rep = $x['report'];
            $this->assertSame([$tot, $net, $ship, $fees, $adj], [$rep['total'], $rep['net_sales'], $rep['shipping'], $rep['fees'], $rep['adjustments']], "Shop $k period totals");
            $this->assertSame($tot, round($net + $ship + $fees + $adj, 2), "Shop $k identity");
            $this->assertSame([[], [], $rows, $sts, 'OK', $sts], [$x['row_errors'], $x['statement_errors'], count($x['rows']), count($x['statements']), $x['hub_check'], $x['statements_with_all_rows']], "Shop $k checks");
            foreach ($x['checks'] as $c) $this->assertTrue($c['ok'], "Shop $k: {$c['text']}");
            $this->assertSame($tot, $x['statements_total'], "Shop $k: statements together = the marketplace's period total");
            // load and book it all: the books must show exactly the file
            $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => 1, 'shop_no' => "T$k", 'iso_branch' => "T$k", 'shop_name' => "TikTok $k", 'f_active' => 1]);
            $r = Settle::importTikTok(1, $shop, $path, "shop$k.xlsx");
            $this->assertSame([$sts, 'OK'], [$r['statements_new'], $r['hub_check']]);
            $this->assertSame($sts, Settle::postAll(1, $shop));
            $this->assertSame(-$net, Ledger::balance(1, '1100', null, $shop), "Shop $k: net sales cleared");
            $this->assertSame($tot, round(Ledger::balance(1, '1110', null, $shop) + Ledger::balance(1, '1120', null, $shop), 2), "Shop $k: payouts on the way = total settlement");
            $costs = (float) DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->where('j.source_type', 'SETTLEMENT')->whereIn('j.source_id', DB::table('mo_settlements')->where('fk_id_branch', $shop)->pluck('id'))
                ->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.code', ['5100', '5200', '5300', '5400'])->selectRaw('SUM(l.credit - l.debit) v')->value('v');
            $this->assertSame(round($ship + $fees + $adj, 2), round($costs, 2), "Shop $k: shipping + fees + adjustments in the cost accounts");
        }
        if (isset($x)) {   // Shop C adjustments by bucket: chargeback −6.97, reimbursement +1.50, fulfilment warehouse fee −0.66
            $by = DB::table('fin_settlement_lines')->where('branch_id', $shop)->whereIn('amount_type', ['TT_CHARGEBACK', 'TT_ADJUSTMENT', 'TT_FULFIL_STORAGE'])->groupBy('amount_type')->selectRaw('amount_type, SUM(amount) v')->pluck('v', 'amount_type');
            $this->assertSame([-6.97, 1.5, -0.66], [(float) $by['TT_CHARGEBACK'], (float) $by['TT_ADJUSTMENT'], (float) $by['TT_FULFIL_STORAGE']]);
        }
        $tb = Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }
}
