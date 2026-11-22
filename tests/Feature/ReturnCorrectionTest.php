<?php

namespace Tests\Feature;

use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-88 / R-FIN-47: a return into the same lot – open sale month: the sale's cost goes down there; LOCKED month: a negative correction in the next open month. */
class ReturnCorrectionTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function sale(): array
    {
        $this->actingAs($this->owner);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1]);
        $bin = $this->f('cat_inventory_locations', ['location_name' => 'R-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'R1', 'iso_branch' => 'R1', 'shop_name' => 'Return shop', 'f_active' => 1, 'fk_id_main_location' => $bin, 'fulfilment_channel' => 'SELF']);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'RET-1', 'product_name_vendor' => 'Return mug', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Stock::receive(1, [['product' => $p, 'qty' => 5, 'location' => $bin, 'unit_cost' => 2.10, 'received_date' => '2026-08-01']], 'GRN', 1);
        PriceService::set($p, $mp, 2, 9.99, '2026-08-01');
        $id = Sales::intake($shop, [['order_no' => '12-345', 'd_order' => '2026-08-10', 'sku' => 'RET-1', 'qty' => 2, 'sale_rate' => 9.99]])['ids'][0];
        Sales::confirmMonth($shop, '2026-08', true);
        return [$id, $shop, $p];
    }

    public function test_open_month_cost_goes_down(): void
    {
        [$id] = $this->sale();
        $this->assertSame(4.20, (float) DB::table('t_sales')->where('id', $id)->value('cost_gbp'));
        Stock::returnIn(1, $id, 2, 'SELLABLE');
        $this->assertSame(0.0, (float) DB::table('t_sales')->where('id', $id)->value('cost_gbp'), 'COGS −£4.20 in that month');
        $this->assertSame(0, DB::table('fin_cogs_corrections')->where('source_type', 'RETURN')->count());
    }

    public function test_locked_month_correction_forward(): void
    {
        [$id, $shop] = $this->sale();
        DB::table('aud_runs')->insert(RecordService::fillNotNull('aud_runs', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'period_month' => '2026-08', 'status' => 'LOCKED']));
        Stock::returnIn(1, $id, 2, 'SELLABLE');
        $c = DB::table('fin_cogs_corrections')->where('source_type', 'RETURN')->where('source_id', $id)->first();
        $this->assertSame(['2026-08', '2026-09', -4.20], [$c->source_month, $c->target_month, (float) $c->amount], 'LOCKED month → correction −£4.20 in the next open month');
        $this->assertSame(4.20, (float) DB::table('t_sales')->where('id', $id)->value('cost_gbp'), 'the locked month keeps its figure');
    }
}
