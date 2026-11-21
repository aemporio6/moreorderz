<?php

namespace Tests\Feature;

use App\Services\Gen1Compare;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-57: Gen-1 sale ↔ buy match summary (customer equal, buy address contains the sale address without spaces) and the two lists. */
class Gen1CompareTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_j_smith_12_high_st_matched(): void
    {
        $this->actingAs($this->owner);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'G1mp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '202', 'iso_branch' => 'G1', 'shop_name' => 'Etsy shop', 'f_active' => 1]);
        $main = $this->f('t_sale_buy_main', ['mon' => 12, 'yr' => 2023, 'fk_id_branch' => $shop, 'f_sale_loaded' => 1]);
        $v = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Supplier', 'sur_name' => 'GB', 'f_active' => 1]);
        $this->f('t_sale', ['fk_id_main' => $main, 'order_id' => 'E-1', 'd_sale' => '2023-12-03', 'sku' => 'MUG-1', 'item_title' => 'Mug', 'customer_name' => 'J SMITH', 'cust_address' => '12 HIGH ST', 'no_of_items' => 2, 'price' => 20]);
        $this->f('t_sale', ['fk_id_main' => $main, 'order_id' => 'E-2', 'd_sale' => '2023-12-04', 'sku' => 'MUG-2', 'item_title' => 'Cup', 'customer_name' => 'A KHAN', 'cust_address' => '5 PARK RD', 'no_of_items' => 1, 'price' => 9]);
        $this->f('t_buy', ['fk_id_main' => $main, 'fk_id_vendor' => $v, 'order_id' => 'B-1', 'd_buy' => '2023-12-03', 'sku' => 'MUG-1', 'item_title' => 'Mug', 'customer_name' => 'J Smith', 'cust_address' => '12HIGHST LEEDS', 'no_of_items' => 1, 'price' => 7.5]);
        $this->f('t_buy', ['fk_id_main' => $main, 'fk_id_vendor' => $v, 'order_id' => 'B-9', 'd_buy' => '2023-12-05', 'sku' => 'X', 'item_title' => 'Other', 'customer_name' => 'J SMITH', 'cust_address' => '99 OTHER LANE', 'no_of_items' => 1, 'price' => 3]);

        $d = $this->getJson("/api/gen1/$main/summary")->assertOk()->json();
        $r = collect($d['rows'])->keyBy('customer');
        $this->assertSame(1, $r['J SMITH']['buy_lines'], 'matched by customer and the address inside the buy address; the other address is not taken');
        $this->assertSame([2, 1, true, 'Qty differs: sale 2, buy 1'], [$r['J SMITH']['qty_sale'], $r['J SMITH']['qty_buy'], $r['J SMITH']['qty_differs'], $r['J SMITH']['audit']]);
        $this->assertSame([20.0, 7.5, 12.5], [(float) $r['J SMITH']['sale'], (float) $r['J SMITH']['buy'], (float) $r['J SMITH']['profit']]);
        $this->assertSame('12HIGHST LEEDS', $r['J SMITH']['buy_address']);
        $this->assertSame('No buy found for this customer and address', $r['A KHAN']['audit']);
        $this->assertSame([1, 2, 3, 1], [$d['totals']['matched'], $d['totals']['differences'], $d['totals']['qty_sale'], $d['totals']['qty_buy']]);
        $this->assertSame('12/2023', $d['header']['month']);

        $s = $this->getJson("/api/gen1/$main/sales")->assertOk()->json('rows');
        $this->assertSame(['E-1', 'MUG-1 Mug', 2, 20.0], [$s[0]['order'], $s[0]['item'], $s[0]['qty'], (float) $s[0]['price']]);
        $b = $this->getJson("/api/gen1/$main/buys")->assertOk()->json('rows');
        $this->assertSame(['Supplier GB', 'B-1', 1], [$b[0]['buy_from'], $b[0]['order'], $b[0]['items']]);
        $this->assertContains($main, array_map('intval', array_column($this->getJson('/api/gen1')->json('rows'), 'id')));

        // without the money right: no prices
        $m = Gen1Compare::summary($main, false); $this->assertArrayNotHasKey('sale', $m['rows'][0]); $this->assertArrayNotHasKey('profit', $m['totals']);
        $this->assertSame('12HIGHST', Gen1Compare::norm(' 12 high st '));
    }
}
