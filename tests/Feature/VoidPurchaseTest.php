<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-43 void a purchase: only an unconsumed hand-made lot; the lot stays (VOID); sold / moved units refuse it. */
class VoidPurchaseTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_d43_void_refused_when_sold_and_done_when_unconsumed(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'VD-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]); $bin2 = $this->f('cat_inventory_locations', ['location_name' => 'VD-2', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'VD-' . uniqid(), 'product_name_vendor' => 'Void me', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $lot = fn (int $qty) => Stock::receive(1, [['product' => $p, 'qty' => $qty, 'location' => $bin, 'unit_cost' => 2.00]], 'OPENING', null, ['lot_source' => 'OPENING'])['lots'][0] ?? (int) DB::table('fin_lots')->where('fk_id_product', $p)->max('id');
        // the rule: purchase 100, 30 sold → refused "30 units already sold – return or adjust first"
        $l1 = $lot(100);
        Stock::adjust(1, $p, $bin, -30, 'test: 30 units leave the lot');
        $this->postJson("/api/stock/lots/$l1/void", ['reason' => 'entered twice'])->assertStatus(422)->assertJsonPath('message', '30 units already sold – return or adjust first');
        $this->assertSame('ACTIVE', DB::table('fin_lots')->where('id', $l1)->value('lot_status'));
        // an unconsumed purchase: voided – the lot stays as a record, the units and their value leave stock
        $l2 = $lot(40); $stock = Stock::balance($p); $val = Stock::value(1, $p); $moves = DB::table('t_stock_movements')->where('fk_id_lot', $l2)->count();
        $this->postJson("/api/stock/lots/$l2/void", ['reason' => ''])->assertStatus(422);
        $r = $this->postJson("/api/stock/lots/$l2/void", ['reason' => 'entered twice'])->assertOk()->json(); $this->assertSame([40, 80.0], [$r['qty'], (float) $r['value']]);
        $row = DB::table('fin_lots')->where('id', $l2)->first(); $this->assertSame(['VOID', 0, 40, 'entered twice'], [$row->lot_status, (int) $row->qty_left, (int) $row->qty, $row->void_reason]);
        $this->assertSame([$stock - 40, round($val - 80, 2), $moves + 1], [Stock::balance($p), round(Stock::value(1, $p), 2), DB::table('t_stock_movements')->where('fk_id_lot', $l2)->count()]);
        $this->assertStringStartsWith("Reversal of voided purchase #$l2", (string) DB::table('t_stock_movements')->where('fk_id_lot', $l2)->orderByDesc('id')->value('notes'));
        $sc = Stock::selfCheck(1); $this->assertSame([0, true], [$sc['mismatch'], $sc['value_matches_ledger']], 'products = lots, stock value = ledger 1200 after the void');
        $this->postJson("/api/stock/lots/$l2/void", ['reason' => 'again'])->assertStatus(422)->assertJsonPath('message', 'This purchase is already void.');
        // moved units → refused; a PO lot is not voided here; no right → 403
        $p3 = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'VM-' . uniqid(), 'product_name_vendor' => 'Moved', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $l3 = (int) Stock::receive(1, [['product' => $p3, 'qty' => 10, 'location' => $bin, 'unit_cost' => 2.00]], 'OPENING', null, ['lot_source' => 'OPENING'])['lots'][0]; Stock::transfer(1, $p3, 4, $bin, $bin2, 'move');
        $this->assertStringContainsString('already moved or counted', $this->postJson("/api/stock/lots/$l3/void", ['reason' => 'x'])->assertStatus(422)->json('message'));
        $l4 = (int) Stock::receive(1, [['product' => $p, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2.00]], 'GRN', 1)['lots'][0];
        $this->assertStringContainsString('came from a purchase order', $this->postJson("/api/stock/lots/$l4/void", ['reason' => 'x'])->assertStatus(422)->json('message'));
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh)->postJson("/api/stock/lots/$l4/void", ['reason' => 'x'])->assertStatus(403);
    }
}
