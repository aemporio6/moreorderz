<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Decisions;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MR-1443 … MR-1450 the decision engine: each rule fires on its own case, shows the money and one click to the form. */
class DecisionsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function icc(int $pid, array $r): void
    {
        DB::table('icc_results')->insert(RecordService::fillNotNull('icc_results', $r + ['fk_id_product' => $pid, 'run_at' => now(), 'monthly_used' => 300, 'daily' => 10, 'cycle_days' => 60, 'order_qty' => 900, 'goods' => 2700, 'avail' => 100,
            'cover_days' => 20, 'status' => 'RED', 'order_by' => date('Y-m-d', strtotime('-3 days')), 'stock_out' => date('Y-m-d', strtotime('+20 days')), 'late_days' => 50, 'lost_profit' => 1500, 'compare_json' => '[]']));
    }

    public function test_b1443_every_rule_with_money_and_one_click(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $today = date('Y-m-d');
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'Dc1443', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'DC' . substr(uniqid(), -5), 'iso_branch' => 'DC', 'shop_name' => 'Decision Shop', 'f_active' => 1]);
        $p = fn (string $sku, array $x = []) => $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'sale_price' => 20, 'landing_price' => 6]);
        // ORDER_NOW + SWITCH_MODE: red, SEA now, AIR cheaper in total
        $a = $p('DC-A'); $this->icc($a, ['compare_json' => json_encode([['mode' => 'AIR', 'freight' => 900, 'lost_profit' => 0, 'total' => 900], ['mode' => 'SEA', 'freight' => 200, 'lost_profit' => 1500, 'total' => 1700]])]);
        DB::table('icc_inputs')->insert(['fk_id_product' => $a, 'fk_id_enterprise' => 1, 'ship_mode' => 'SEA']);
        // RAISE_PRICE: plan 30 a month, sold 60 in 30 days, cover < cycle
        $b = $p('DC-B'); $this->icc($b, ['status' => 'GREEN', 'monthly_used' => 30, 'daily' => 1, 'cover_days' => 40, 'cycle_days' => 60, 'lost_profit' => 0, 'order_by' => date('Y-m-d', strtotime('+200 days'))]);
        $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $b, 'sku' => 'DC-B', 'qty' => 60, 'd_order' => date('Y-m-d', strtotime('-5 days')), 'order_no' => 'O-B']);
        // PAUSE_LISTING: margin after fees under the floor (20 × 0.72 − 19 − 1.4 < 0)
        $c = $p('DC-C', ['landing_price' => 19]); $this->icc($c, ['status' => 'GREEN', 'monthly_used' => 50, 'cover_days' => 60, 'cycle_days' => 60, 'lost_profit' => 0, 'order_by' => date('Y-m-d', strtotime('+200 days'))]);
        // LOWER_PRICE: 400 days of cover
        $d = $p('DC-D'); $this->icc($d, ['status' => 'GREEN', 'monthly_used' => 30, 'daily' => 1, 'avail' => 400, 'cover_days' => 400, 'cycle_days' => 60, 'lost_profit' => 0, 'order_by' => date('Y-m-d', strtotime('+300 days'))]);
        // CHASE_PAYMENT: an invoice 10 days past due
        $this->f('t_invoices', ['fk_id_ent' => 1, 'invoice_no' => 'INV-DC-1', 'invoice_type' => 'VA', 'd_invoice' => date('Y-m-d', strtotime('-40 days')), 'd_due' => date('Y-m-d', strtotime('-10 days')), 'currency' => 'GBP', 'total' => 480, 'amount_paid' => 80, 'status' => 'PART_PAID', 'recipient_json' => json_encode(['name' => 'Acme Retail'])]);
        // CANCEL_SUB: a paid monthly subscription linked to no shop
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'DC' . random_int(1000, 9999), 'first_name' => 'Dee', 'f_active' => 1]);
        $item = $this->f('cat_subscription_items', ['item_name' => 'Repricer tool', 'f_active' => 1]);
        $this->f('t_account_subscriptions', ['fk_id_account' => $acc, 'fk_item_id' => $item, 'f_active' => 1, 'fk_id_bank' => 0, 'fk_id_bank_account' => 0, 'subscription_link' => '', 'billing_cycle' => 'MONTHLY', 'price' => 25]);
        $all = Decisions::all(1); $by = collect($all['items'])->groupBy('code');
        $this->assertSame([], $all['skipped'], json_encode($all['skipped']));
        $o = $by['ORDER_NOW']->firstWhere('title', 'Order 900 × DC-A · Item DC-A');
        $this->assertNotNull($o); $this->assertSame(1500.0, $o['amount']); $this->assertSame("#/direct-po?product=$a&qty=900", $o['go']); $this->assertStringContainsString('2,700.00 cash needed', $o['money']);
        $s = $by['SWITCH_MODE']->first(); $this->assertSame(800.0, $s['amount']); $this->assertSame("#/icc/$a?mode=AIR", $s['go']);
        $r = $by['RAISE_PRICE']->first(); $this->assertStringContainsString('DC-B', $r['title']); $this->assertSame(60.0, $r['amount']);      // 60 units × 20 × 5 %
        $this->assertStringContainsString('DC-C', $by['PAUSE_LISTING']->first()['title']);
        $l = $by['LOWER_PRICE']->first(); $this->assertStringContainsString('DC-D', $l['title']); $this->assertSame(37.2, $l['amount']);      // (400 − 90) × 6 × 2 %
        $c2 = $by['CHASE_PAYMENT']->first(); $this->assertSame(400.0, $c2['amount']); $this->assertStringStartsWith('https://wa.me/?text=', $c2['go']); $this->assertStringContainsString('INV-DC-1', $c2['text']);
        $cs = $by['CANCEL_SUB']->first(); $this->assertSame(300.0, $cs['amount']); $this->assertStringContainsString('Repricer tool', $cs['title']); $this->assertSame('#/subscriptions', $cs['go']);
        $this->assertGreaterThanOrEqual($all['items'][1]['amount'], $all['items'][0]['amount'], 'highest money first');
        // the page: right needed; a filter by code
        $this->assertSame(['SWITCH_MODE'], array_values(array_unique(array_column($this->getJson('/api/decisions?code=SWITCH_MODE')->assertOk()->json('items'), 'code'))));
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh); \App\Services\Perm::flush();
        if (! \App\Services\Perm::has($wh, 'money.SEE') && ! \App\Services\Perm::has($wh, 'buying.SEE')) $this->getJson('/api/decisions')->assertStatus(403);
    }
}
