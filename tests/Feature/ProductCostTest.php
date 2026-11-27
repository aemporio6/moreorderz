<?php

namespace Tests\Feature;

use App\Services\ProductCost;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MR-0940: one landing-price rule – own → latest lot → parent → raw price, with where it came from. */
class ProductCostTest extends TestCase
{
    private function p(array $x): int
    {
        static $n = 0; $n++;
        return (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PC-' . $n . '-' . uniqid(), 'product_name_vendor' => 'Cost test ' . $n]));
    }

    public function test_landing_price_rule(): void
    {
        $parent = $this->p(['landing_price' => 4.5, 'raw_price' => 2]);
        $own = $this->p(['landing_price' => 3.25, 'raw_price' => 1, 'f_id_parent' => $parent]);
        $child = $this->p(['landing_price' => 0, 'raw_price' => 1, 'f_id_parent' => $parent]);
        $lotted = $this->p(['landing_price' => 0, 'raw_price' => 1, 'f_id_parent' => $parent]);
        $raw = $this->p(['landing_price' => 0, 'raw_price' => 1.2]);
        $none = $this->p(['landing_price' => 0, 'raw_price' => 0]);
        foreach ([[2.0, '2026-01-01'], [2.75, '2026-02-01']] as [$c, $d]) DB::table('fin_lots')->insert(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'X', 'fk_id_product' => $lotted, 'source' => 'GRN', 'received_date' => $d, 'qty' => 5, 'qty_left' => 5, 'unit_cost_gbp' => $c, 'created_at' => now()]));
        $m = ProductCost::many([$own, $child, $lotted, $raw, $none]);
        $this->assertSame(['value' => 3.25, 'from' => 'own'], $m[$own]);
        $this->assertSame(['value' => 4.5, 'from' => 'parent'], $m[$child]);
        $this->assertSame(['value' => 2.75, 'from' => 'lot'], $m[$lotted]);
        $this->assertSame(['value' => 1.2, 'from' => 'raw'], $m[$raw]);
        $this->assertSame('none', $m[$none]['from']);
        $this->assertSame(2.75, ProductCost::value($lotted));
        $row = collect(\App\Services\InventoryHub::fill(DB::table('t_product_vendors')->where('id', $child)->get(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name', 'product_img', 'f_parent', 'f_id_parent', 'e_product_type as mode', 'stock', 'min_level', 'sale_price', 'landing_price', 'raw_price', 'icc_status', 'icc_stock_out'])->all(), 1, null))->first();
        $this->assertSame(4.5, $row['cost']); $this->assertSame('parent', $row['cost_from']);
    }
}
