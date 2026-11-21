<?php

namespace Tests\Feature;

use App\Services\Perm;
use App\Services\RecordService;
use App\Services\SalesCard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-26: the Sales card – marketplace + period filters, rows, imports waiting, last sale, by channel / by VA with drill-down. */
class SalesCardTest extends TestCase
{
    private int $mp; private int $mp2;
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function sale(string $no, string $date, float $qty, string $status = 'Confirmed', array $x = []): int
    {
        return $this->f('t_sales', $x + ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'order_no' => $no, 'd_order' => $date, 'qty' => $qty, 'status' => $status, 'total_amount' => 10 * $qty, 'total_amount_gbp' => 10 * $qty, 'profit' => 2 * $qty, 'sale_source' => 'active', 'sku' => 'SC-1', 'created_by' => $this->owner->id, 'created_at' => now()]);
    }
    private function row(array $c, string $label): ?string { return collect($c['rows'])->firstWhere('label', $label)['figure'] ?? null; }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'SalesMP', 'marketplace_descr' => 'Sales card MP', 'f_active' => 1]);
        $this->mp2 = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'OtherMP', 'marketplace_descr' => 'Other MP', 'f_active' => 1]);
    }

    public function test_the_rule_example_three_orders_today_one_cancelled(): void
    {
        $t = '2026-11-21';
        $this->sale('A-1', $t, 2); $this->sale('A-2', $t, 3); $this->sale('A-3', $t, 5, 'Cancelled');
        $this->sale('A-2', $t, 1, 'Confirmed', ['sku' => 'SC-2']);      // a second line of order A-2: still one order, its units count
        $c = SalesCard::card(1, $this->mp, '2026-11-01', '2026-11-30', true, $t);
        $this->assertSame('2 · 6 units', $this->row($c, 'Sales today'));
    }

    public function test_every_row_the_filters_and_the_drill_down(): void
    {
        $va = $this->makeUser('VA'); $t = '2026-11-21';
        $this->sale('B-1', $t, 1); $this->sale('B-2', '2026-11-16', 2, 'Pending', ['created_by' => $va->id]); $this->sale('B-3', '2026-11-03', 4, 'Refunded');
        $this->sale('B-4', '2026-10-30', 3);      // last month: outside the period, inside "last sale"? no – B-1 is later
        $this->sale('C-1', $t, 7, 'Confirmed', ['fk_id_marketplace' => $this->mp2]);
        $this->f('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'fk_id_branch' => 1, 'status' => 'Pending', 'period_month' => 11, 'period_year' => 2026, 'imported_at' => now()]);
        $c = SalesCard::card(1, $this->mp, '2026-11-01', '2026-11-30', true, $t);
        $this->assertSame('2 · 3 units', $this->row($c, 'Sales this week'), 'rolling: order date ≥ today − 7');
        $this->assertSame('2 · 3 units', $this->row($c, 'Orders (period)'), 'Refunded left out, October outside the period');
        $this->assertSame('30.00', $this->row($c, 'Sales value')); $this->assertSame('6.00', $this->row($c, 'Profit'));
        $this->assertSame('1 imports · 1 orders', $this->row($c, 'Imports waiting'));
        $this->assertSame('21 Nov 2026', $this->row($c, 'Last sale'));
        $this->assertSame([['name' => 'SalesMP', 'figure' => '2 · 3 units', 'link' => "#/sales?marketplace={$this->mp}&from=2026-11-01&to=2026-11-30&status=open"]], $c['by_channel']);
        $this->assertEqualsCanonicalizing([$this->owner->display_name, $va->display_name], array_column($c['by_va'], 'name'), 'by VA = created by');
        $this->assertStringContainsString("created_by={$va->id}", collect($c['by_va'])->firstWhere('name', $va->display_name)['link']);
        // without see money: no value / profit rows
        $this->assertNull($this->row(SalesCard::card(1, $this->mp, '2026-11-01', '2026-11-30', false, $t), 'Sales value'));
        // All marketplaces: both channels, the other one first (more units do not matter, orders do: 2 vs 1 → SalesMP first)
        $all = SalesCard::card(1, null, '2026-11-01', '2026-11-30', true, $t);
        $this->assertSame(['SalesMP', 'OtherMP'], array_slice(array_column(array_filter($all['by_channel'], fn ($x) => in_array($x['name'], ['SalesMP', 'OtherMP'], true)), 'name'), 0, 2));
        // an empty period
        $e = SalesCard::card(1, $this->mp, '2020-01-01', '2020-01-31', true, $t);
        $this->assertSame([[], []], [$e['by_channel'], $e['by_va']]); $this->assertSame(['No sales in period', 'No entries in period'], [$e['empty_channel'], $e['empty_va']]);
        // the drill-down: the sales list with the VA filter and no marketplace
        $this->actingAs($this->owner);
        $rows = $this->getJson("/api/sales?created_by={$va->id}&from=2026-11-01&to=2026-11-30&status=open")->assertOk()->json('rows');
        $this->assertSame(['B-2'], array_column($rows, 'order_no'));
    }

    public function test_over_http_with_the_rights(): void
    {
        $this->actingAs($this->owner);
        $d = $this->getJson("/api/buying/card/sales?mp={$this->mp}")->assertOk()->json('data');
        $this->assertSame('Sales', $d['title']); $this->assertSame('THIS_MONTH', $d['period']['key']); $this->assertSame($this->mp, $d['marketplace']);
        $u = $this->makeUser('SOURCING'); $this->actingAs($u);
        if (! Perm::has($u, 'orders.SEE')) $this->getJson('/api/buying/card/sales')->assertStatus(403);
        else $this->assertNull(collect($this->getJson('/api/buying/card/sales')->json('data.rows'))->firstWhere('label', 'Sales value'));
    }
}
