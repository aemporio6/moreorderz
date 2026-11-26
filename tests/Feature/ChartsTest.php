<?php

namespace Tests\Feature;

use App\Services\Charts;
use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-103 charts from the same calculation as the figures; View data; a click → the filtered list. */
class ChartsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_g103_sales_bar_equals_the_sales_month_total_and_links_to_that_month(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Chart103', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'C103', 'iso_branch' => 'C103', 'shop_name' => 'Chart shop', 'f_active' => 1]);
        $m = date('Y-m', strtotime(date('Y-m-01') . ' -2 month')); $sale = fn (string $day, float $amt, float $profit, int $lock) => $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'order_no' => 'C-' . uniqid(), 'd_order' => "$m-$day", 'sku' => 'S', 'qty' => 1, 'total_amount' => $amt, 'profit' => $profit, 'status' => 'Confirmed', 'sale_source' => 'active', 'f_locked' => $lock, 'created_at' => now()]);
        $sale('05', 120.50, 20, 1); $sale('20', 79.50, 10, 1);
        $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month')); $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'order_no' => 'C-open', 'd_order' => "$last-03", 'sku' => 'S', 'qty' => 1, 'total_amount' => 10, 'profit' => 3, 'status' => 'Pending', 'sale_source' => 'active', 'f_locked' => 0, 'created_at' => now()]);
        // the rule: the month's bar = the month total of the Sales list; a click opens the Sales list of that month
        $list = $this->getJson('/api/sales?' . http_build_query(['marketplace' => $mp, 'from' => "$m-01", 'to' => date('Y-m-t', strtotime("$m-01")), 'remember' => 0]))->assertOk()->json('totals.sale');
        $c = $this->getJson('/api/charts/home?months=12')->assertOk()->json('charts'); $s = collect($c)->firstWhere('key', 'sales'); $ser = collect($s['series'])->firstWhere('name', 'Chart103');
        $i = array_search(date('M y', strtotime("$m-01")), $s['labels'], true); $this->assertSame(12, count($s['labels'])); $this->assertSame(200.0, (float) $list);
        $this->assertSame((float) $list, (float) $ser['values'][$i], 'the bar height is the Sales month total');
        $this->assertSame('#/sales?' . http_build_query(['marketplace' => $mp, 'from' => "$m-01", 'to' => date('Y-m-t', strtotime("$m-01"))]), $ser['links'][$i]);
        // the profit line shows only months that are locked; it needs the cost right
        $this->assertSame(30.0, (float) $s['line']['values'][$i]); $this->assertNull($s['line']['values'][$i + 1], 'a month with an unlocked line has no profit point');
        $va = $this->makeUser('VA'); \Illuminate\Support\Facades\DB::table('t_users')->where('id', $va->id)->update(['fk_id_ent' => 1]);
        $vs = collect(Charts::home(1, $va, [])['charts'])->firstWhere('key', 'sales'); $wh2 = $this->makeUser('WAREHOUSE'); $ws = collect(Charts::home(1, $wh2, [])['charts'])->firstWhere('key', 'sales'); if ($ws) $this->assertSame(\App\Services\Perm::has($wh2, 'orders.MONEY'), $ws['line'] !== null, 'the profit line follows the cost right');
        if ($vs) $this->assertSame(\App\Services\Perm::has($va, 'orders.MONEY'), $vs['line'] !== null);
        $this->assertSame(\App\Services\Perm::has($va, 'stock.MONEY'), collect(Charts::home(1, $va, [])['charts'])->firstWhere('key', 'stock') !== null, 'stock value only with the money right');
        // a shop filter narrows the same way as the Sales list
        $other = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'C104', 'iso_branch' => 'C104', 'shop_name' => 'Other', 'f_active' => 1]);
        $this->assertSame([], collect($this->getJson("/api/charts/home?shop=$other")->json('charts'))->firstWhere('key', 'sales')['series']);
    }

    public function test_g103_stock_cash_icc_payouts_tasks_cycles_charts(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Chart WH', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CH-1', 'product_name_vendor' => 'Chart', 'f_active' => 1, 'e_product_type' => 'pool', 'icc_status' => 'RED', 'icc_run_at' => now()]);
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 10, 'location' => $wh, 'unit_cost' => 2.5]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $st = Charts::stock(1, $this->owner); $this->assertSame(\App\Services\StockService::value(1), round(array_sum(array_map(fn ($x) => $x['values'][0], $st['series'])), 2), 'the parts add up to "Stock at cost"');
        $w = collect($st['series'])->firstWhere('name', 'Warehouse'); $this->assertSame('#/stock/lots?kind=WAREHOUSE', $w['links'][0]);
        $this->assertContains($p, collect($this->getJson('/api/stock/lots?kind=WAREHOUSE')->json('rows'))->pluck('product_id')->all()); $this->assertSame([], $this->getJson('/api/stock/lots?kind=TRANSIT')->json('rows'));
        $icc = Charts::icc(1, $this->owner); $this->assertSame(1, $icc['series'][0]['values'][0]); $this->assertSame([$p], array_column($this->getJson('/api/products?per=50&q=CH-1&icc=RED')->json('rows'), 'id'));
        $this->assertSame([], array_column($this->getJson('/api/products?per=50&q=CH-1&icc=OK')->json('rows'), 'id'));
        $cash = Charts::cash(1, $this->owner); $fc = \App\Services\CashForecast::build(1, 13); $this->assertSame(array_column($fc['rows'], 'balance'), $cash['series'][0]['values']);
        $money = $this->getJson('/api/charts/money')->assertOk()->json('charts'); $this->assertSame(['payouts', 'cash'], array_column($money, 'key'));
        $this->assertCount(2, $money[0]['series']); $this->assertStringStartsWith('#/settlements?month=', $money[0]['series'][0]['links'][0]);
        // tasks: open per category, late apart; a click opens the list with that chip and category
        DB::table('tasks')->insert(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'Late chart task', 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('-3 days')) . ' 23:59:59', 'source' => 'MANUAL', 'owner_user_id' => $this->owner->id, 'category_id' => 1, 'due_changes' => 0, 'evidence_required' => 0]));
        $t = $this->getJson('/api/charts/tasks')->assertOk()->json('charts.0'); $late = collect($t['series'])->firstWhere('name', 'Late'); $this->assertGreaterThanOrEqual(1, array_sum($late['values']));
        $this->assertStringContainsString('chip=late', $late['links'][0]);
        $b = $this->getJson('/api/charts/buying')->assertOk()->json('charts.0'); $this->assertSame('cycles', $b['key']); $this->assertSame(count($b['labels']), count($b['series'][0]['values'])); $this->assertStringStartsWith('#/buying?stage=', $b['series'][0]['links'][0]);
        $va = $this->makeUser('VA'); $this->actingAs($va)->getJson('/api/charts/money')->assertStatus(403);
    }
}
