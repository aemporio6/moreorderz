<?php

namespace Tests\Feature;

use App\Services\DispatchService as Dispatch;
use App\Services\Ledger;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesDispatchTest extends TestCase
{
    private int $p; private int $binA; private int $binB; private int $shop; private int $carrier; private int $mp;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->binA = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->binB = $f('cat_inventory_locations', ['location_name' => 'A-A-02', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 2]);
        $this->carrier = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier A', 'f_active' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->binA, 'fk_id_default_carrier' => $this->carrier, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 4, 'location' => $this->binA, 'unit_cost' => 4.00, 'received_date' => '2026-08-01']], 'GRN', 1);
        Stock::receive(1, [['product' => $this->p, 'qty' => 6, 'location' => $this->binB, 'unit_cost' => 5.00, 'received_date' => '2026-08-05']], 'GRN', 2);
        PriceService::set($this->p, $this->mp, 8, 12.99, '2026-08-01');
    }

    private function order(string $no, int $qty, array $x = []): int
    {
        return Sales::intake($this->shop, [['order_no' => $no, 'd_order' => '2026-08-10', 'sku' => 'BOWL-01', 'qty' => $qty, 'sale_rate' => 12.99, 'cust_name' => 'Test Buyer'] + $x])['ids'][0];
    }

    public function test_intake_matches_the_product_and_price_period_reserves_and_never_makes_a_line_twice(): void
    {
        $id = $this->order('ORD-1', 3);
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(['active', 38.97, 38.97, 'RESERVED'], [$s->sale_source, (float) $s->total_amount, (float) $s->total_amount_gbp, $s->wh_status]);
        $this->assertNotNull($s->fk_id_price_period);
        $this->assertSame(7, Stock::available($this->p), '3 of 10 are reserved');
        $again = Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => '2026-08-10', 'sku' => 'BOWL-01', 'qty' => 5, 'sale_rate' => 12.99]]);
        $this->assertSame([0, 1, $id], [$again['new'], $again['updated'], $again['ids'][0]]);
        $this->assertSame(1, DB::table('wh_reservations')->where('fk_id_sale', $id)->count());
        $this->assertSame(5, Stock::available($this->p));
        $un = Sales::intake($this->shop, [['order_no' => 'ORD-2', 'd_order' => '2026-08-10', 'sku' => 'NO-SUCH-SKU', 'qty' => 1, 'sale_rate' => 5]]);
        $this->assertSame(1, $un['unmatched']);
        $this->assertTrue(TaskEngine::isOpen('SAL_UNMATCHED', [$this->shop, '2026-08']));
    }

    public function test_listing_sku_with_pack_quantity_reserves_the_real_units(): void
    {
        DB::table('t_product_listings')->insert(RecordService::fillNotNull('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $this->p, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'marketplace_sku' => 'BOWL-2PACK', 'pack_qty' => 2, 'f_active' => 1, 'status' => 'ACTIVE']));
        $id = Sales::intake($this->shop, [['order_no' => 'ORD-9', 'd_order' => '2026-08-10', 'sku' => 'BOWL-2PACK', 'qty' => 2, 'sale_rate' => 22.00]])['ids'][0];
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame([4, 11.00, 44.00], [(int) $s->qty, (float) $s->sale_rate, (float) $s->total_amount], '2 two-packs = 4 units');
        $this->assertSame(6, Stock::available($this->p));
    }

    public function test_short_at_intake_reserves_what_there_is_and_fills_itself_when_stock_arrives(): void
    {
        $id = $this->order('ORD-1', 13);
        $r = DB::table('wh_reservations')->where('fk_id_sale', $id)->first();
        $this->assertSame([10, 3], [(int) $r->qty, (int) $r->qty_short]);
        $this->assertTrue(TaskEngine::isOpen('WH_ORDER_SHORT', [$id]));
        Stock::receive(1, [['product' => $this->p, 'qty' => 20, 'location' => $this->binA, 'unit_cost' => 4.5]], 'GRN', 3);
        \App\Services\EventBus::runQueue();          // the GRN_POSTED listener fills the short by itself (in the app it runs right after the save)
        $r = DB::table('wh_reservations')->where('fk_id_sale', $id)->first();
        $this->assertSame([13, 0], [(int) $r->qty, (int) $r->qty_short]);
        $this->assertFalse(TaskEngine::isOpen('WH_ORDER_SHORT', [$id]));
    }

    /** One order through the warehouse and the month end: reserve → pick list (two bins, walking order) → pick → pack → handover → Month Confirm. */
    public function test_order_from_intake_to_month_confirm_with_stock_and_money_right_at_every_stop(): void
    {
        $id = $this->order('ORD-1', 7);
        $task = Dispatch::buildPickList(1);
        $lines = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->orderBy('line_no')->get();
        $this->assertSame([[$this->binA, 4], [$this->binB, 3]], $lines->map(fn ($l) => [(int) $l->fk_id_from_location, (int) $l->qty])->all(), 'nearest bin first, never more than a bin holds');
        $this->assertNull(Dispatch::buildPickList(1), 'nothing left to put on a second list');
        try { Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop]); $this->fail('packed before pick'); } catch (\RuntimeException $e) { $this->assertStringContainsString('not fully picked', $e->getMessage()); }
        Dispatch::pick($lines[0]->id); $r = Dispatch::pick($lines[1]->id);
        $this->assertSame('PICKED', $r['reservation']);
        $dispatch = Stock::systemLocation(1, 'DISPATCH'); $shipped = Stock::systemLocation(1, 'SHIPPED');
        $this->assertSame([7, 3, 3], [Stock::balance($this->p, $dispatch), Stock::balance($this->p, $this->binB), Stock::available($this->p)]);
        try { Dispatch::pick($lines[0]->id); $this->fail('picked twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already done', $e->getMessage()); }

        $parcel = Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop, 'weight_g' => 900, 'tracking_no' => 'TRK123']);
        try { Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop]); $this->fail('packed twice'); } catch (\RuntimeException $e) { $this->assertStringContainsString('already packed', $e->getMessage()); }

        $other = DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier B', 'f_active' => 1]));
        $wrong = Dispatch::openHandover($other);
        $this->assertSame('WRONG_CARRIER', Dispatch::scan($wrong, 'TRK123')['result']);
        $ses = Dispatch::openHandover($this->carrier);
        $this->assertSame($ses, Dispatch::openHandover($this->carrier), 'one open session per carrier');
        $this->assertSame('UNKNOWN', Dispatch::scan($ses, 'XYZ')['result']);
        $ok = Dispatch::scan($ses, 'TRK123', 'op-1');
        $this->assertSame(['OK', 1, 1], [$ok['result'], $ok['scanned'], $ok['expected']]);
        $this->assertTrue(Dispatch::scan($ses, 'TRK123', 'op-1')['repeat'] ?? false, 'the same scan sent twice counts once');
        $this->assertSame('DUPLICATE', Dispatch::scan($ses, 'TRK123', 'op-2')['result']);
        $this->assertSame(7, Stock::balance($this->p, $dispatch), 'scanning ships nothing; closing does');
        $c = Dispatch::closeHandover($ses, 'Driver Dan');
        $this->assertSame(['shipped' => 1, 'left_ready' => 0, 'exceptions' => 1], $c);
        $this->assertSame([0, 7], [Stock::balance($this->p, $dispatch), Stock::balance($this->p, $shipped)]);
        $this->assertSame(['SHIPPED', 7], [DB::table('t_sales')->where('id', $id)->value('wh_status'), (int) DB::table('t_sales')->where('id', $id)->value('qty_shipped')]);
        $this->assertSame(0.00, Ledger::balance(1, '5000'), 'shipping books no cost; Month Confirm does');
        $this->assertSame(10, Stock::balance($this->p), 'shipped units are still ours on the books until Confirm');
        $this->assertTrue(TaskEngine::isOpen('WH_HANDOVER_EXCEPTION', [$ses]));

        $pre = Sales::confirmPreview($this->shop, '2026-08');
        $this->assertSame([1, 7, 90.93, [], []], [$pre['to_confirm'], $pre['units_out'], $pre['sales_total'], $pre['unshipped'], $pre['shortages']]);
        $res = Sales::confirmMonth($this->shop, '2026-08');
        $this->assertSame(['lines' => 1, 'units' => 7, 'sales' => 90.93, 'cost' => 31.00, 'profit_before_fees' => 59.93, 'backfilled' => 0], $res);      // 4 × 4.00 + 3 × 5.00, oldest lot first
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(['Confirmed', 31.00, 59.93, 1, 1], [$s->status, (float) $s->cost_gbp, (float) $s->profit, (int) $s->f_stock_deducted, (int) $s->f_locked]);
        $this->assertSame([3, 0], [Stock::balance($this->p), Stock::balance($this->p, $shipped)]);
        $this->assertSame([90.93, 31.00, 90.93], [-Ledger::balance(1, '4000'), Ledger::balance(1, '5000'), Ledger::balance(1, '1100', 'SHOP', $this->shop)]);
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        try { Sales::confirmMonth($this->shop, '2026-08'); $this->fail('confirmed twice'); } catch (\RuntimeException $e) { $this->assertSame('This month is already confirmed.', $e->getMessage()); }
        $late = Sales::intake($this->shop, [['order_no' => 'ORD-1', 'd_order' => '2026-08-10', 'sku' => 'BOWL-01', 'qty' => 99, 'sale_rate' => 1]]);
        $this->assertSame(7, (int) DB::table('t_sales')->where('id', $late['ids'][0])->value('qty'), 'a confirmed line is locked: a late file cannot change it');
    }

    public function test_short_pick_puts_the_rest_back_in_the_queue_and_asks_for_a_bin_count(): void
    {
        $id = $this->order('ORD-1', 4);
        $task = Dispatch::buildPickList(1);
        $line = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->first();
        $r = Dispatch::pick($line->id, 3);
        $this->assertSame(['picked' => 3, 'short' => 1, 'reservation' => 'OPEN'], $r);
        $this->assertTrue(TaskEngine::isOpen('WH_SHORT_PICK', [$this->binA, 'BOWL-01']));
        $task2 = Dispatch::buildPickList(1);
        $l2 = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task2)->first();
        $this->assertSame([1, $this->binA], [(int) $l2->qty, (int) $l2->fk_id_from_location], 'the missing unit comes back on the next list');
    }

    public function test_confirm_is_refused_for_unshipped_lines_unless_booked_as_shipped_and_for_shortages(): void
    {
        $this->order('ORD-1', 2);                                             // never picked or shipped in the tool
        try { Sales::confirmMonth($this->shop, '2026-08'); $this->fail('unshipped confirmed'); } catch (\RuntimeException $e) { $this->assertStringContainsString('were not shipped through the tool', $e->getMessage()); }
        $this->assertSame('Pending', DB::table('t_sales')->where('order_no', 'ORD-1')->value('status'));
        $this->assertTrue(TaskEngine::isOpen('WH_UNSHIPPED_LINES', [$this->shop . '-2026-08']));
        $r = Sales::confirmMonth($this->shop, '2026-08', true);
        $this->assertSame([2, 8.00, 1], [$r['units'], $r['cost'], $r['backfilled']]);
        $this->assertSame(8, Stock::balance($this->p));
        $this->assertFalse(TaskEngine::isOpen('WH_UNSHIPPED_LINES', [$this->shop . '-2026-08']));

        Sales::intake($this->shop, [['order_no' => 'ORD-5', 'd_order' => '2026-09-03', 'sku' => 'BOWL-01', 'qty' => 50, 'sale_rate' => 12.99]]);
        try { Sales::confirmMonth($this->shop, '2026-09', true); $this->fail('short confirmed'); } catch (\RuntimeException $e) { $this->assertSame('Not enough stock for 1 product(s). Nothing was confirmed.', $e->getMessage()); }
        $this->assertTrue(TaskEngine::isOpen('SAL_STOCK_SHORT', ['BOWL-01', $this->shop . '-2026-09']));
        $this->assertSame(8, Stock::balance($this->p), 'a refused Confirm changes nothing');
        try { Sales::confirmMonth($this->shop, '2026-12'); $this->fail(); } catch (\RuntimeException $e) { $this->assertSame('There are no sale lines to confirm in this month.', $e->getMessage()); }
    }

    public function test_return_before_confirm_comes_back_from_shipped_and_after_confirm_goes_back_into_its_lot(): void
    {
        $id = $this->order('ORD-1', 4);
        $task = Dispatch::buildPickList(1);
        foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->get() as $l) Dispatch::pick($l->id);
        Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop, 'tracking_no' => 'TRK9']);
        $ses = Dispatch::openHandover($this->carrier); Dispatch::scan($ses, 'TRK9'); Dispatch::closeHandover($ses);
        $shipped = Stock::systemLocation(1, 'SHIPPED');

        $r = Dispatch::receiveReturn(['code' => 'TRK9', 'qty' => 1, 'condition' => 'SELLABLE']);
        $this->assertSame([true, true, 0.0], [$r['matched'], $r['before_confirm'], $r['cost_back']]);
        $this->assertSame([3, 1], [Stock::balance($this->p, $shipped), Stock::balance($this->p, $this->binA)]);
        try { Dispatch::receiveReturn(['code' => 'TRK9', 'qty' => 9]); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('Only 3 unit(s)', $e->getMessage()); }

        $c = Sales::confirmMonth($this->shop, '2026-08', true);                 // 3 still out + 1 came back and is booked as shipped again = the line's 4
        $this->assertSame(4, $c['units']);
        $after = Dispatch::receiveReturn(['code' => 'ORD-1', 'qty' => 2, 'condition' => 'DAMAGED']);
        $this->assertSame([false, 8.00], [$after['before_confirm'], $after['cost_back']]);
        $this->assertSame(2, Stock::balance($this->p, Stock::systemLocation(1, 'DAMAGED')));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertTrue(TaskEngine::isOpen('WH_RETURN_REFUND_REVIEW', [$after['return']]));

        $un = Dispatch::receiveReturn(['code' => 'NOT-AN-ORDER']);
        $this->assertFalse($un['matched']);
        $this->assertTrue(TaskEngine::isOpen('WH_RETURN_UNMATCHED', [$un['intake']]));
    }

    public function test_cancel_before_pick_frees_the_stock(): void
    {
        $id = $this->order('ORD-1', 6);
        $this->assertSame(4, Stock::available($this->p));
        Dispatch::release($id, 'Buyer cancelled');
        $this->assertSame(10, Stock::available($this->p));
        $this->assertSame('RELEASED', DB::table('wh_reservations')->where('fk_id_sale', $id)->value('status'));
    }
}
