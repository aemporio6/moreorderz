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

        \App\Services\Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);      // item scans are checked in PackTest (R-H-29)
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
        $this->assertSame(['picked' => 3, 'short' => 1, 'reservation' => 'OPEN', 'ask_empty' => null], $r);
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
        \App\Services\Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);      // item scans are checked in PackTest (R-H-29)
        Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop, 'tracking_no' => 'TRK9']);
        $ses = Dispatch::openHandover($this->carrier); Dispatch::scan($ses, 'TRK9'); Dispatch::closeHandover($ses);
        $shipped = Stock::systemLocation(1, 'SHIPPED');

        $r = Dispatch::receiveReturn(['code' => 'TRK9', 'qty' => 1, 'condition' => 'SELLABLE']);
        $this->assertSame([true, true, 0.0], [$r['matched'], $r['before_confirm'], $r['cost_back']]);
        $this->assertSame([3, 1], [Stock::balance($this->p, $shipped), Stock::balance($this->p, $this->binA)]);
        try { Dispatch::receiveReturn(['code' => 'TRK9', 'qty' => 9]); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('Only 3 unit(s)', $e->getMessage()); }

        $c = Sales::confirmMonth($this->shop, '2026-08', true);                 // 3 still out + 1 came back and is booked as shipped again = the line's 4
        $this->assertSame(4, $c['units']);
        $ask = Dispatch::receiveReturn(['code' => 'ORD-1', 'qty' => 2, 'condition' => 'DAMAGED']);
        $this->assertSame([true, 'Already received 1 of 4 – receive more?'], [$ask['confirm'], $ask['message']]); /* R-H-39 the same line again asks first */
        $after = Dispatch::receiveReturn(['code' => 'ORD-1', 'qty' => 2, 'condition' => 'DAMAGED', 'more' => true]);
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

    /** R-H-28: idle, abandoned (released), reassigned, resumed and reprinted pick lists. */
    public function test_abandoned_pick_list_releases_unpicked_lines_and_keeps_picked_units_in_dispatch(): void
    {
        foreach ([1, 2, 3, 4] as $n) $ids[$n] = $this->order("ORD-$n", 2);
        $task = Dispatch::buildPickList(1);
        $lines = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->orderBy('line_no')->get();
        $this->assertCount(4, $lines);
        Dispatch::pick($lines[0]->id); Dispatch::pick($lines[1]->id);
        $this->assertSame($lines[2]->id, \App\Services\PickLists::next($task)->id, 'a refresh resumes at the next unconfirmed line');
        $pPick = new \ReflectionMethod(\App\Services\PrintService::class, 'pPick');
        $html = $pPick->invoke(null, 1, $task);
        $this->assertStringContainsString('reprint, lines still to pick', $html);
        $this->assertSame(2, substr_count($html, 'ORD-3') + substr_count($html, 'ORD-4'), 'the reprint shows only the 2 lines still to pick');
        $this->assertStringNotContainsString('ORD-1', $html);

        $t = fn () => DB::table('wh_pick_tasks')->where('id', $task)->first();
        $this->assertSame('IN_PROGRESS', \App\Services\PickLists::state($t()));
        DB::table('wh_pick_tasks')->where('id', $task)->update(['last_activity_at' => now()->subMinutes(121)]);
        $this->assertSame('IDLE', \App\Services\PickLists::state($t()), 'not touched for WH_PICK_IDLE_MINUTES → idle');
        $this->assertSame(0, \App\Services\PickLists::sweep(1), 'idle is not yet abandoned');

        $u = $this->makeUser('OWNER'); \App\Services\Context::set(1, (int) $u->id); $p2 = $this->makeUser('STOCK');
        $this->assertStringContainsString('given to', \App\Services\PickLists::reassign($task, (int) $p2->id, $u)['message']);
        $this->assertSame((int) $p2->id, (int) $t()->assigned_to);

        DB::table('wh_pick_tasks')->where('id', $task)->update(['last_activity_at' => now()->subHours(9)]);
        $this->assertSame(1, \App\Services\PickLists::sweep(1));
        $this->assertSame('ABANDONED', $t()->status);
        $this->assertSame(['DONE', 'DONE', 'RELEASED', 'RELEASED'], DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->orderBy('line_no')->pluck('line_status')->all());
        $res = fn ($n) => DB::table('wh_reservations')->where('fk_id_sale', $ids[$n])->value('status');
        $this->assertSame(['PICKED', 'PICKED', 'OPEN', 'OPEN'], [$res(1), $res(2), $res(3), $res(4)], 'unpicked lines go back to OPEN – still reserved');
        $disp = \App\Services\StockService::systemLocation(1, 'DISPATCH');
        $this->assertSame(4, \App\Services\CountTriggers::inBin($this->p, $disp), 'picked units stay in DISPATCH for packing');
        $this->assertTrue(TaskEngine::isOpen('WH_PICK_ABANDONED', [$t()->task_code]));
        $this->assertSame('RESERVED', DB::table('t_sales')->where('id', $ids[3])->value('wh_status'));
        try { Dispatch::pick($lines[2]->id); $this->fail('a released line cannot be picked'); } catch (\RuntimeException $e) { $this->assertStringContainsString('released', $e->getMessage()); }
        try { $pPick->invoke(null, 1, $task); $this->fail('an abandoned list is not printed'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('abandoned', $e->getMessage()); }
        $next = Dispatch::buildPickList(1);
        $this->assertSame(['ORD-3', 'ORD-4'], DB::table('wh_pick_task_lines as l')->join('t_sales as s', 's.id', '=', 'l.fk_id_sale')->where('l.fk_id_pick_task', $next)->orderBy('l.line_no')->pluck('s.order_no')->all(), 'they join the next list');
        $this->assertSame(0, \App\Services\PickLists::sweep(1));
    }

    /** R-H-17 / R-H-60: order cancelled after pick → PUTBACK task; scanned back to the bin → RELEASED and available rises. */
    public function test_cancel_after_pick_opens_a_putback_and_releases_when_the_units_are_back(): void
    {
        $id = $this->order('ORD-1', 3);
        $task = Dispatch::buildPickList(1); Dispatch::pick(DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task)->value('id'));
        $disp = Stock::systemLocation(1, 'DISPATCH');
        $this->assertSame([3, 7], [Stock::balance($this->p, $disp), Stock::available($this->p)]);
        $this->assertSame('PUTBACK', Dispatch::release($id, 'Buyer cancelled'));
        $this->assertSame(['PUTBACK', 'PICKED'], [DB::table('t_sales')->where('id', $id)->value('wh_status'), DB::table('wh_reservations')->where('fk_id_sale', $id)->value('status')]);
        $this->assertTrue(TaskEngine::isOpen('WH_PUTBACK', [$id]));
        $this->assertSame(7, Stock::available($this->p), 'not free until the units are back on the shelf');
        \App\Services\Context::set(1, null);
        $this->assertSame(3, Dispatch::putback($id)['units']);
        $this->assertSame([0, 4, 10], [Stock::balance($this->p, $disp), Stock::balance($this->p, $this->binA), Stock::available($this->p)], 'back to the home bin A-A-01; available rises by 3');
        $this->assertSame(['RELEASED', 'RELEASED'], [DB::table('t_sales')->where('id', $id)->value('wh_status'), DB::table('wh_reservations')->where('fk_id_sale', $id)->value('status')]);
        $this->assertFalse(TaskEngine::isOpen('WH_PUTBACK', [$id]));
        try { Dispatch::putback($id); $this->fail('put back twice'); } catch (\RuntimeException $e) { $this->assertSame('Nothing to put back for this line.', $e->getMessage()); }
        \App\Services\Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);
        $id2 = $this->order('ORD-2', 1); $t2 = Dispatch::buildPickList(1); Dispatch::pick(DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t2)->value('id'));
        Dispatch::pack(['order_no' => 'ORD-2', 'branch' => $this->shop, 'weight_g' => 300, 'tracking_no' => 'TRK9']);
        try { Dispatch::release($id2, 'Buyer cancelled'); $this->fail('packed order released'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Void its label', $e->getMessage()); }
    }

    /** R-H-35 / R-H-60: the carrier took fewer than scanned → un-scan with a reason within WH_HANDOVER_REOPEN_MINUTES; later it is a lost parcel. */
    public function test_carrier_takes_fewer_unscan_within_the_window_back_to_ready(): void
    {
        \App\Services\Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1); \App\Services\Context::set(1, null);
        foreach (['ORD-1' => 'TRK1', 'ORD-2' => 'TRK2'] as $no => $trk) { $ids[$no] = $this->order($no, 1); }
        $t = Dispatch::buildPickList(1); foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t)->pluck('id') as $l) Dispatch::pick($l);
        $p1 = Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop, 'weight_g' => 300, 'tracking_no' => 'TRK1']); $p2 = Dispatch::pack(['order_no' => 'ORD-2', 'branch' => $this->shop, 'weight_g' => 300, 'tracking_no' => 'TRK2']);
        $ses = Dispatch::openHandover($this->carrier); Dispatch::scan($ses, 'TRK1', 'a'); Dispatch::scan($ses, 'TRK2', 'b');
        $this->assertSame(2, Dispatch::closeHandover($ses)['shipped']);
        $disp = Stock::systemLocation(1, 'DISPATCH'); $ship = Stock::systemLocation(1, 'SHIPPED');
        try { Dispatch::unscan($ses, $p2, 'x'); $this->fail('no reason'); } catch (\InvalidArgumentException $e) { $this->assertSame('Give the reason for the un-scan.', $e->getMessage()); }
        $this->assertStringContainsString('Ready again', Dispatch::unscan($ses, $p2, 'Driver left it on the bench')['message']);
        $this->assertSame(['READY', 'PACKED', 0, 'PICKED'], [DB::table('wh_parcels')->where('id', $p2)->value('status'), DB::table('t_sales')->where('id', $ids['ORD-2'])->value('wh_status'), (int) DB::table('t_sales')->where('id', $ids['ORD-2'])->value('qty_shipped'), DB::table('wh_reservations')->where('fk_id_sale', $ids['ORD-2'])->value('status')]);
        $this->assertSame([1, 1, 1], [Stock::balance($this->p, $disp), Stock::balance($this->p, $ship), (int) DB::table('wh_handover_sessions')->where('id', $ses)->value('scanned_count')]);
        DB::table('wh_handover_sessions')->where('id', $ses)->update(['closed_at' => now()->subMinutes(61)]);
        try { Dispatch::unscan($ses, $p1, 'Driver says not taken'); $this->fail('too late'); } catch (\RuntimeException $e) { $this->assertStringContainsString('report the parcel as lost', $e->getMessage()); }
        $ses2 = Dispatch::openHandover($this->carrier); Dispatch::scan($ses2, 'TRK2', 'c');
        $this->assertSame(1, Dispatch::closeHandover($ses2)['shipped'], 'the next session picks it up');
    }

    /** R-H-48 / R-H-60: found 4 units while MISSING holds 3 → 3 back from MISSING (owner kept) + 1 found-stock lot of DEFAULT_STOCK_OWNER. */
    public function test_found_stock_comes_back_from_missing_first_then_a_found_lot(): void
    {
        $miss = Stock::systemLocation(1, 'MISSING');
        Stock::transfer(1, $this->p, 3, $this->binB, $miss, 'Count loss', ['type_out' => 'count_loss', 'type_in' => 'count_loss', 'source_type' => 'COUNT']);
        DB::table('wh_missing_units')->insert(['fk_id_ent' => 1, 'fk_id_product' => $this->p, 'fk_id_count' => 0, 'fk_id_from_location' => $this->binB, 'qty' => 3, 'qty_open' => 3, 'created_at' => now()]);
        $owner = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1]));
        \App\Services\Settings::set('DEFAULT_STOCK_OWNER', (string) $owner, 1);
        DB::table('fin_lots')->where('fk_id_product', $this->p)->update(['landed_status' => 'FINAL']);
        $lots = DB::table('fin_lots')->where('fk_id_product', $this->p)->count();
        $r = Stock::adjust(1, $this->p, $this->binA, 4, 'Found – behind the shelf');
        $this->assertSame([3, 1], [$r['from_missing'], $r['new_lot']]);
        $this->assertSame([0, 8], [Stock::balance($this->p, $miss), Stock::balance($this->p, $this->binA)]);
        $lot = DB::table('fin_lots')->where('id', $r['lot'])->first();
        $this->assertSame([$lots + 1, $owner, 5.0], [DB::table('fin_lots')->where('fk_id_product', $this->p)->count(), (int) $lot->fk_id_owner, (float) $lot->unit_cost_gbp], 'new lot of the default owner at the last FINAL cost (5.00)');
        $this->assertSame(0, (int) DB::table('wh_missing_units')->where('fk_id_product', $this->p)->sum('qty_open'));
        Stock::adjust(1, $this->p, $this->binA, 1, 'Found one behind the shelf');
        $this->assertSame($lots + 1, DB::table('fin_lots')->where('fk_id_product', $this->p)->count(), 'a free-text reason is an ordinary adjustment');
    }

    /** R-D-9 / R-H-60: a retired (hidden) SKU keeps its bins and stock and cannot be deleted while it holds stock. */
    public function test_retired_sku_keeps_its_stock_and_cannot_be_deleted(): void
    {
        $u = $this->makeUser('OWNER'); \App\Services\Context::set(1, (int) $u->id);
        \App\Services\ProductArchive::hide($this->p, 'Retired line', $u);
        $this->assertSame(['HIDDEN', 4, 6, 10], [DB::table('t_product_vendors')->where('id', $this->p)->value('row_state'), Stock::balance($this->p, $this->binA), Stock::balance($this->p, $this->binB), Stock::balance($this->p)]);
        try { \App\Services\ProductArchive::delete($this->p, $u); $this->fail('deleted with stock'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('stock on BOWL-01', $e->getMessage()); }
    }

    /** R-H-32 / R-H-60: address change after the label → label voided automatically, the old tracking is blocked at handover, a new label makes the parcel Ready. */
    public function test_address_change_after_label_voids_it_and_the_old_tracking_is_refused_at_handover(): void
    {
        \App\Services\Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1); \App\Services\Context::set(1, null);
        $id = $this->order('ORD-1', 1); $t = Dispatch::buildPickList(1); Dispatch::pick(DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t)->value('id'));
        $p = Dispatch::pack(['order_no' => 'ORD-1', 'branch' => $this->shop, 'weight_g' => 300, 'tracking_no' => 'OLD-TRK']);
        $r = Dispatch::addressChanged($id, ['ship_to_address' => '2 New Street', 'ship_to_postcode' => 'AB1 2CD']);
        $this->assertSame([$p], $r['voided']);
        $this->assertStringContainsString('print a new label', $r['message']);
        $this->assertSame(['VOID', 'PACKED', 'OLD-TRK', '2 New Street'], [DB::table('wh_parcels')->where('id', $p)->value('label_status'), DB::table('wh_parcels')->where('id', $p)->value('status'), DB::table('wh_parcels')->where('id', $p)->value('voided_tracking'), DB::table('t_sales')->where('id', $id)->value('ship_to_address')]);
        $ses = Dispatch::openHandover($this->carrier);
        $x = Dispatch::scan($ses, 'OLD-TRK');
        $this->assertSame(['VOIDED', 'Voided label – do not send'], [$x['result'], $x['message']]);
        try { Dispatch::newLabel($p, 'OLD-TRK', 'ADDRESS_CHANGED'); $this->fail('old tracking reused'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('was voided', $e->getMessage()); }
        Dispatch::newLabel($p, 'NEW-TRK', 'ADDRESS_CHANGED');
        $this->assertSame('OK', Dispatch::scan($ses, 'NEW-TRK')['result']);
        $this->assertSame('VOIDED', Dispatch::scan($ses, 'OLD-TRK')['result'], 'the old label stays blocked');
        $c = Dispatch::closeHandover($ses);
        $this->assertSame([1, 2], [$c['shipped'], $c['exceptions']]);
        try { Dispatch::voidLabel($p, 'Too late'); $this->fail('voided after handover'); } catch (\RuntimeException $e) { $this->assertStringContainsString('left with the carrier', $e->getMessage()); }
        $this->assertStringContainsString('already left', Dispatch::addressChanged($id, ['ship_to_city' => 'Leeds'])['message']);
    }

    /** R-H-43 / R-H-16 / R-H-60: moving a SKU's home while orders are reserved – reservations hold no bin, the next pick list takes the new home. */
    public function test_moving_the_home_while_orders_are_reserved_keeps_the_reservations(): void
    {
        $this->actingAs($this->makeUser('OWNER'));
        $site = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'WH1', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]));
        DB::table('cat_inventory_locations')->whereIn('id', [$this->binA, $this->binB])->update(['fk_id_site' => $site]);
        \App\Services\SkuHomes::set(1, $this->p, $this->binA, 'PRIMARY');
        $id = $this->order('ORD-1', 3);
        $before = DB::table('wh_reservations')->where('fk_id_sale', $id)->first(['id', 'status', 'qty', 'qty_short']);
        \App\Services\SkuHomes::move(1, $this->p, $this->binB, true);
        $this->assertEquals($before, DB::table('wh_reservations')->where('fk_id_sale', $id)->first(['id', 'status', 'qty', 'qty_short']), 'the reservation is untouched');
        $this->assertSame([0, 10], [Stock::balance($this->p, $this->binA), Stock::balance($this->p, $this->binB)]);
        $t = Dispatch::buildPickList(1);
        $this->assertSame([[$this->binB, 3]], DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t)->get()->map(fn ($l) => [(int) $l->fk_id_from_location, (int) $l->qty])->all(), 'the bin is chosen at pick time – the new home');
    }

    /** R-H-55 anomaly alerts: each check opens its task and closes it when the problem is gone. */
    public function test_anomaly_alerts_open_and_close_their_tasks(): void
    {
        $A = \App\Services\WarehouseAlerts::class; $open = fn (string $c) => DB::table('tasks')->where('rule_code', $c)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count();
        $A::run(1);
        $this->assertSame([0, 0, 0, 0, 0], [$open('WH_RECON_MISMATCH'), $open('WH_ADJUST_UNUSUAL'), $open('WH_DISPATCH_DUE'), $open('WH_UNASSIGNED_STOCK'), $open('WH_HOLD_AGED')], 'clean books, no alert');
        $lot = DB::table('fin_lots')->where('fk_id_product', $this->p)->orderBy('id')->value('id');
        DB::table('fin_lots')->where('id', $lot)->increment('qty_left', 2);
        foreach (range(1, 4) as $n) Stock::adjust(1, $this->p, $this->binB, -1, "Damage $n");
        $id = $this->order('ORD-1', 1, ['dispatch_by' => date('Y-m-d') . ' 12:00:00']);
        DB::table('t_sales')->where('id', $id)->update(['dispatch_by' => date('Y-m-d') . ' 12:00:00']);
        $dam = Stock::systemLocation(1, 'DAMAGED');
        Stock::transfer(1, $this->p, 1, $this->binA, $dam, 'Damaged in the bin', ['source_type' => 'ADJUST']);
        DB::table('fin_lot_balances')->where('fk_id_location', $dam)->update(['updated_at' => now()->subDays(15)]);
        $A::run(1);
        $this->assertSame([1, 2, 1, 1], [$open('WH_RECON_MISMATCH'), $open('WH_ADJUST_UNUSUAL'), $open('WH_DISPATCH_DUE'), $open('WH_HOLD_AGED')], 'lot ≠ bins, 4 adjustments by one user and on one SKU (> 3), a line due today not packed, damaged units 15 days');
        $this->assertStringContainsString('BOWL-01 was adjusted 4 times in 7 days', (string) DB::table('tasks')->where('rule_code', 'WH_ADJUST_UNUSUAL')->pluck('title')->implode(' | '));
        DB::table('fin_lots')->where('id', $lot)->decrement('qty_left', 2);
        DB::table('t_sales')->where('id', $id)->update(['wh_status' => 'PACKED']);
        $A::run(1);
        $this->assertSame([0, 0, 1], [$open('WH_RECON_MISMATCH'), $open('WH_DISPATCH_DUE'), $open('WH_HOLD_AGED')], 'fixed problems close on the next sweep');
    }

    /** R-H-18 / R-H-15 / R-H-60: owner running out while another owner has stock → reserve only his units, short "stock belongs to another owner – cover from pool?". */
    public function test_owner_running_out_while_another_owner_has_stock_is_an_owner_rule_short(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $a = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Owner A', 'f_active' => 1]); $b = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Owner B', 'f_active' => 1]);
        $pp = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PRIV-01', 'product_name_vendor' => 'Private cup', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'private']);
        Stock::receive(1, [['product' => $pp, 'qty' => 2, 'location' => $this->binA, 'unit_cost' => 3.00, 'owner' => $a, 'received_date' => '2026-08-01'], ['product' => $pp, 'qty' => 5, 'location' => $this->binB, 'unit_cost' => 3.00, 'owner' => $b, 'received_date' => '2026-07-01']], 'GRN', 9);
        DB::table('fin_lots')->where('fk_id_product', $pp)->update(['visibility' => 'PRIVATE']);
        DB::table('t_branches')->where('id', $this->shop)->update(['fk_id_owner' => $a]);
        PriceService::set($pp, $this->mp, 8, 9.99, '2026-08-01');
        $id = Sales::intake($this->shop, [['order_no' => 'ORD-P', 'd_order' => '2026-08-10', 'sku' => 'PRIV-01', 'qty' => 4, 'sale_rate' => 9.99, 'cust_name' => 'Test Buyer']])['ids'][0];
        $r = DB::table('wh_reservations')->where('fk_id_sale', $id)->first();
        $this->assertSame(['PRIVATE', $a, 2, 2], [$r->owner_rule, (int) $r->fk_id_owner_seg, (int) $r->qty, (int) $r->qty_short], 'the shop owner A (not B, the oldest private lot) – only his 2 units');
        $this->assertSame([0, 7], [Stock::availableFor($pp, $a), Stock::available($pp) + 2], 'A has nothing left; 5 of B stay on the shelf');
        $this->assertStringContainsString('stock belongs to another owner – cover from pool?', (string) DB::table('tasks')->where('rule_code', 'WH_ORDER_SHORT')->where('dedupe_key', 'WH_ORDER_SHORT:' . $id)->value('title'));
        $t = Dispatch::buildPickList(1);
        $this->assertSame([[$this->binA, 2]], DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t)->get()->map(fn ($l) => [(int) $l->fk_id_from_location, (int) $l->qty])->all(), "nobody picks B's units silently");
    }
}
