<?php

namespace Tests\Feature;

use App\Services\DispatchService as Dispatch;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\Settings;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-39 return scan intake with grades, R-H-40 unmatched → matched later, R-H-41 refund review and supplier claim – the spec examples. */
class ReturnGradesTest extends TestCase
{
    private int $p; private int $home; private int $shop; private int $carrier; private int $mp; private int $grn;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LAMP-01', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->home = $f('cat_inventory_locations', ['location_name' => 'W1-A-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $other = $f('cat_inventory_locations', ['location_name' => 'W9-Z-9', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 9]);
        DB::table('t_product_locations')->insert(RecordService::fillNotNull('t_product_locations', ['fk_id_product' => $this->p, 'fk_id_location' => $this->home, 'home_role' => 'PRIMARY']));
        $this->carrier = $f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier A', 'f_active' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $other, 'fk_id_default_carrier' => $this->carrier, 'fulfilment_channel' => 'SELF']);
        $this->grn = $f('t_src_grn', ['fk_id_ent' => 1, 'grn_number' => 'GRN-7', 'grn_date' => '2026-08-01', 'status' => 'POSTED']);
        $f('t_src_grn_lines', ['fk_id_grn' => $this->grn, 'fk_id_product' => $this->p, 'sku' => 'LAMP-01', 'received_qty' => 10, 'damaged_qty' => 0, 'missing_qty' => 0]);
        Stock::receive(1, [['product' => $this->p, 'qty' => 10, 'location' => $other, 'unit_cost' => 2.00, 'received_date' => '2026-08-01']], 'GRN', $this->grn);
        PriceService::set($this->p, $this->mp, 8, 12.99, '2026-08-01');
        Settings::set('WH_SCAN_CONFIRM_PACK', 'N', 1);
    }

    private function shipped(string $no, int $qty, string $trk): int
    {
        $id = Sales::intake($this->shop, [['order_no' => $no, 'd_order' => '2026-08-10', 'sku' => 'LAMP-01', 'qty' => $qty, 'sale_rate' => 12.99]])['ids'][0];
        $t = Dispatch::buildPickList(1); foreach (DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $t)->get() as $l) Dispatch::pick($l->id);
        Dispatch::pack(['order_no' => $no, 'branch' => $this->shop, 'tracking_no' => $trk]);
        $s = Dispatch::openHandover($this->carrier); Dispatch::scan($s, $trk); Dispatch::closeHandover($s);
        return $id;
    }

    public function test_r39_scan_tracking_grade_home_bin_and_receive_more(): void
    {
        $this->actingAs($this->owner);
        $id = $this->shipped('T-2001', 4, 'TRKP2');
        $r = Dispatch::receiveReturn(['code' => 'TRKP2', 'qty' => 2, 'grade' => 'RESELLABLE_NEW']);
        $this->assertSame([true, true, 'RESELLABLE_NEW', $this->home], [$r['matched'], $r['before_confirm'], $r['grade'], $r['restock_location']], 'scan tracking of the parcel → the order; as new → the home bin');
        $this->assertSame(2, Stock::balance($this->p, $this->home), 'W1-A-1 +2 from SHIPPED');
        $again = Dispatch::receiveReturn(['code' => 'TRKP2', 'qty' => 1, 'grade' => 'RESELLABLE_NEW']);
        $this->assertSame('Already received 2 of 4 – receive more?', $again['message']);
        $o = Dispatch::receiveReturn(['code' => 'T-2001', 'qty' => 1, 'grade' => 'RESELLABLE_OPENED', 'more' => true]);
        $this->assertSame(Stock::systemLocation(1, 'RETURNS'), $o['restock_location'], 'opened → RETURNS to check first');
        $d = Dispatch::receiveReturn(['code' => 'T-2001', 'qty' => 1, 'grade' => 'DAMAGED', 'more' => true, 'op_id' => 'op-r1']);
        $this->assertSame(Stock::systemLocation(1, 'DAMAGED'), $d['restock_location']);
        $this->assertTrue(Dispatch::receiveReturn(['code' => 'T-2001', 'qty' => 1, 'grade' => 'DAMAGED', 'more' => true, 'op_id' => 'op-r1'])['repeat'], 'the same op twice books once');
        $this->assertSame(['RESELLABLE_NEW', 'MISSING_ITEM'], [Dispatch::grade('SELLABLE'), Dispatch::grade('MISSING')], 'old names still read');
        try { Dispatch::grade('BROKEN'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Choose the grade of the return from the list.', $e->getMessage()); }
        $this->assertSame(4, (int) DB::table('t_sale_returns')->where('fk_id_sale', $id)->sum('qty'));
    }

    public function test_r39_sku_scan_lists_lines_to_choose(): void
    {
        $this->actingAs($this->owner);
        $this->shipped('E-1', 1, 'TRKE1'); $this->shipped('E-2', 1, 'TRKE2');
        $r = Dispatch::receiveReturn(['code' => 'LAMP-01']);
        $this->assertSame(['E-2', 'E-1'], array_column($r['choose'], 'order_no'), 'an item scan → recent orders of that SKU to pick from');
        $this->assertFalse(DB::table('t_sale_returns')->exists(), 'nothing booked before the choice');
    }

    public function test_r40_unmatched_then_matched_later(): void
    {
        $this->actingAs($this->owner);
        $this->shipped('E-1005', 1, 'TRK1005');
        $un = Dispatch::receiveReturn(['code' => 'ABC']);
        $this->assertFalse($un['matched']); $this->assertFalse(DB::table('t_sale_returns')->exists(), 'no stock movement');
        $this->assertTrue(TaskEngine::isOpen('WH_RETURN_UNMATCHED', [$un['intake']]));
        $before = Stock::balance($this->p, Stock::systemLocation(1, 'RETURNS'));
        $m = $this->postJson('/api/returns', ['intake' => $un['intake'], 'code' => 'E-1005', 'grade' => 'RESELLABLE_OPENED'])->assertOk()->json();
        $this->assertTrue($m['matched']); $this->assertSame('BOOKED', DB::table('wh_return_intakes')->where('id', $un['intake'])->value('status'));
        $this->assertSame($before + 1, Stock::balance($this->p, Stock::systemLocation(1, 'RETURNS')), 'matched later → booked, RETURNS +1');
        $this->assertFalse(TaskEngine::isOpen('WH_RETURN_UNMATCHED', [$un['intake']]));
    }

    public function test_r41_supplier_fault_claim_line_and_refund_review(): void
    {
        $this->actingAs($this->owner);
        $id = $this->shipped('T-3001', 1, 'TRK3001');
        $r = Dispatch::receiveReturn(['code' => 'TRK3001', 'qty' => 1, 'grade' => 'SUPPLIER_FAULT']);
        $this->assertSame(['grn' => $this->grn, 'grn_code' => 'GRN-7', 'units' => 1, 'amount' => 2.0], array_diff_key($r['claim'], ['lot' => 1]), 'claim line 1 unit £2.00 on GRN-7');
        $this->assertSame(Stock::systemLocation(1, 'QUARANTINE'), $r['restock_location'], 'QUARANTINE +1');
        $g = DB::table('t_src_grn')->where('id', $this->grn)->first(); $this->assertSame(['OPEN', 1.0, 2.0], [$g->claim_status, (float) $g->claim_units, (float) $g->claim_amount]);
        $this->assertTrue(TaskEngine::isOpen('WH_RETURN_REFUND_REVIEW', [$r['return']]), 'no refund on the order → refund review to the VA');
        $id2 = $this->shipped('T-3002', 1, 'TRK3002');
        DB::table('t_sales')->where('id', $id2)->update(['refunded_at' => now()]);
        $r2 = Dispatch::receiveReturn(['code' => 'TRK3002', 'qty' => 1, 'grade' => 'RESELLABLE_NEW']);
        $this->assertFalse($r2['refund_review']); $this->assertFalse(TaskEngine::isOpen('WH_RETURN_REFUND_REVIEW', [$r2['return']]), 'a refunded order needs no review');
    }
}
