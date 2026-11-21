<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\FinanceMonthly;
use App\Services\ProductProfitList;
use App\Services\RecordService;
use App\Services\StockService;
use App\Services\StockSnapshot;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-51 stock snapshot, R-FIN-54 products & stock profit list, R-FIN-50 monthly summary + missing report, R-FIN-32 nightly / monthly jobs. */
class FinanceSnapshotTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function loc(string $kind): int { return $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => "Snap $kind " . uniqid(), 'location_code' => substr($kind, 0, 3) . random_int(1000, 9999), 'kind' => $kind, 'f_active' => 1, 'f_sellable' => 1, 'created_at' => now()]); }
    private function product(string $sku): int { return $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'f_active' => 1]); }

    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_r51_unit_counted_in_one_place_and_past_date_from_the_ledger(): void
    {
        $wh = $this->loc('BIN'); $fba = $this->loc('FBA'); $p = $this->product('SNAP-1');
        StockService::receive(1, [['product' => $p, 'qty' => 50, 'location' => $wh, 'unit_cost' => 2, 'received_date' => date('Y-m-d', strtotime('-10 days'))]], 'OPENING', null, ['lot_source' => 'OPENING']);
        StockService::transfer(1, $p, 20, $wh, $fba, 'To FBA');
        StockSnapshot::build(1);
        $r = DB::table('fin_stock_snapshots')->where('company_id', 1)->where('snapshot_date', date('Y-m-d'))->where('sku', 'SNAP-1')->first();
        $this->assertSame([30, 20], [(int) $r->units_on_hand_warehouse, (int) $r->units_at_amazon], 'the rule\'s test: on hand 30 + FBA 20 = 50 (not 70)');
        $this->assertSame([2.0, 100.0], [(float) $r->landed_cost_per_unit, (float) $r->value_sellable]);
        // yesterday: before the transfer → all 50 in the warehouse (ledger on that date, not today's place)
        $y = collect(StockSnapshot::rows(1, date('Y-m-d', strtotime('-1 day'))))->firstWhere('sku', 'SNAP-1');
        $this->assertSame([50, 0], [(int) $y['units_on_hand_warehouse'], (int) $y['units_at_amazon']]);
        // before the lot arrived: nothing
        $this->assertNull(collect(StockSnapshot::rows(1, date('Y-m-d', strtotime('-20 days'))))->firstWhere('sku', 'SNAP-1'));
        // rebuild replaces the day's rows (one row per day + SKU)
        StockSnapshot::build(1); $this->assertSame(1, DB::table('fin_stock_snapshots')->where('snapshot_date', date('Y-m-d'))->where('sku', 'SNAP-1')->count());
        // never sold, holds value → DEAD; cards
        $v = $this->getJson('/api/money/stock-snapshot')->assertOk()->json();
        $this->assertSame('DEAD', collect($v['rows'])->firstWhere('sku', 'SNAP-1')['flag']);
        $this->assertGreaterThanOrEqual(20, $v['cards']['fba']['units']);
        $this->getJson('/api/money/stock-snapshot?date=' . date('Y-m-d', strtotime('+1 day')))->assertStatus(422);
    }

    public function test_r54_losing_money_filters_on_the_summed_sku_profit(): void
    {
        $wh = $this->loc('BIN');
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'PPmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'PP1', 'iso_branch' => 'PP' . random_int(100, 999), 'shop_name' => 'PP shop', 'f_active' => 1]);
        $sale = function (int $p, string $sku, float $amount, float $cost) use ($shop) {
            $id = $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $p, 'order_no' => 'PP-' . uniqid(), 'sku' => $sku, 'd_order' => date('Y-m-d'), 'qty' => 1, 'total_amount' => $amount, 'total_amount_gbp' => $amount, 'status' => 'Confirmed', 'sale_source' => 'active']);
            if ($cost > 0) $this->f('fin_lot_consumption', ['lot_id' => 0, 'sku' => $sku, 'qty' => 1, 'unit_cost_gbp' => $cost, 'consumed_on' => date('Y-m-d'), 'fk_id_sale' => $id]);
        };
        $win = $this->product('PP-WIN'); $lose = $this->product('PP-LOSE'); $mixed = $this->product('PP-MIX');
        $sale($win, 'PP-WIN', 50, 10); $sale($lose, 'PP-LOSE', 10, 30);
        $sale($mixed, 'PP-MIX', 5, 20); $sale($mixed, 'PP-MIX', 60, 20);      // one losing line, the SKU wins overall (+25)
        $sale($win, 'PP-WIN', 40, 0);                                         // a line without a lot cost
        $all = ProductProfitList::list(1, ['q' => 'PP-']);
        $by = collect($all['rows'])->keyBy('sku');
        $this->assertSame([80.0, -20.0, 25.0], [$by['PP-WIN']['profit'], $by['PP-LOSE']['profit'], $by['PP-MIX']['profit']]);
        $this->assertSame([1, 62.5, 38.5], [$by['PP-WIN']['cost_missing'], $by['PP-MIX']['roi_pct'], $by['PP-MIX']['margin_pct']], 'cost missing units; ROI = 25 ÷ 40; margin = 25 ÷ 65');
        // the rule's test: "losing money" shows only profit < 0 – on the summed SKU, so PP-MIX stays out
        $l = ProductProfitList::list(1, ['q' => 'PP-', 'losing' => 1]);
        $this->assertSame(['PP-LOSE'], array_column($l['rows'], 'sku')); $this->assertSame(-20.0, $l['total']['profit']);
        $this->assertSame(['PP-WIN'], array_column(ProductProfitList::list(1, ['q' => 'PP-', 'cost_missing' => 1])['rows'], 'sku'));
        $this->assertSame(['PP-LOSE', 'PP-MIX', 'PP-WIN'], array_column(ProductProfitList::list(1, ['q' => 'PP-', 'sort' => 'profit_asc'])['rows'], 'sku'));
        $this->getJson('/api/money/product-profit?q=PP-&losing=1')->assertOk()->assertJsonPath('rows.0.sku', 'PP-LOSE');
        $this->assertSame([], array_values(array_filter(ProductProfitList::list(1)['rows'], fn ($r) => str_starts_with($r['name'], 'Overheads'))), 'overheads are not a SKU row');
        // stock on hand + stock days from the snapshot of the To date: 30 in stock, 2 sold in 1 day → 15 days
        StockService::receive(1, [['product' => $win, 'qty' => 30, 'location' => $wh, 'unit_cost' => 1]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $w = collect(ProductProfitList::list(1, ['q' => 'PP-WIN', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')])['rows'])->firstWhere('sku', 'PP-WIN');
        $this->assertSame([30, 15], [$w['on_hand'], $w['stock_days']]);
    }

    public function test_r50_monthly_summary_once_per_partner_and_missing_report_task_closes_itself(): void
    {
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Mona', 'sur_name' => 'Monthly', 'whatsapp_number' => '+44 7700 900777', 'f_active' => 1]);
        $q = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Nora', 'sur_name' => 'Nonumber', 'f_active' => 1]);
        foreach ([$p, $q] as $who) $this->f('fin_investor_agreements', ['investor_id' => $who, 'company_id' => 1, 'partner_type' => 'SLEEPING', 'scope' => 'POOL', 'effective_from' => '2026-01-01', 'profit_share_pct' => 10, 'management_fee_pct' => 0, 'status' => 'ACTIVE', 'created_at' => now()]);
        foreach ([['CAPITAL_IN', 1000], ['PROFIT', 300], ['WITHDRAWAL', -100]] as [$t, $a]) $this->f('fin_investor_ledger', ['investor_id' => $p, 'company_id' => 1, 'entry_date' => '2026-10-01', 'type' => $t, 'amount' => $a, 'created_at' => now()]);
        // the rule's test: run twice on 1 Nov → one message per partner
        FinanceMonthly::summaries('2026-11-01'); $second = FinanceMonthly::summaries('2026-11-01');
        $this->assertSame(0, $second['sent'] + $second['not_sent']);
        $this->assertSame(1, DB::table('tm_messages')->where('event_key', "MONTHLY:2026-11:$p")->count(), 'one queue (R-G_CORE-46)');
        $this->assertSame(1, DB::table('tm_messages')->where('template_key', 'MONTHLY_SUMMARY')->where('link_id', $p)->count());
        $this->assertStringContainsString('Monthly summary: balance £200.00, profit to date £300.00. Open your Partner Account for details.', DB::table('tm_messages')->where('event_key', "MONTHLY:2026-11:$p")->value('text'));
        $this->assertSame('FAILED', DB::table('tm_messages')->where('event_key', "MONTHLY:2026-11:$q")->value('status'), 'no number: logged as not sent, not tried again');
        $this->assertSame(1, FinanceMonthly::summaries('2026-12-01')['sent'], 'next month: a new message');

        // missing report: September still OPEN, 32 days after it ended, no later period → task; the next settlement closes it
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'MRmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'MR1', 'iso_branch' => 'MR' . random_int(100, 999), 'shop_name' => 'Missing shop', 'f_active' => 1]);
        $this->f('aud_runs', ['run_code' => 'MR-' . uniqid(), 'fk_id_ent' => 1, 'fk_id_branch' => $shop, 'period_month' => '2026-09', 'status' => 'OPEN', 'created_at' => now()]);
        FinanceMonthly::missingTasks(1, '2026-11-01');
        $t = DB::table('tasks')->where('dedupe_key', "FIN_REPORT_MISSING:$shop")->first();
        $this->assertSame('Upload the next settlement report for Missing shop (last ended 2026-09-30)', $t->title);
        $this->assertSame([], array_keys(FinanceMonthly::missing(1, '2026-10-10')), 'within 17 days: no task yet');
        $this->f('mo_settlements', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'statement_id' => 'MR-NEXT', 'statement_date' => '2026-10-14', 'currency' => 'GBP', 'payable' => 10, 'status' => 'LOADED']);
        FinanceMonthly::missingTasks(1, '2026-11-01');
        $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'), 'closes itself when the next settlement arrives (new)');
    }

    public function test_r32_nightly_and_monthly_jobs_lock_and_log(): void
    {
        $this->artisan('mo:finance-nightly')->assertSuccessful();
        $this->assertSame('OK', DB::table('sys_job_runs')->where('job_code', 'FIN_NIGHTLY')->where('fk_id_ent', 1)->orderByDesc('id')->value('run_status'));
        $this->assertTrue(DB::table('fin_stock_snapshots')->where('snapshot_date', date('Y-m-d'))->exists() || DB::table('fin_lots')->where('company_id', 1)->where('qty_left', '>', 0)->doesntExist());
        // a second run while the first still runs exits LOCKED and is logged
        DB::table('sys_job_runs')->insert(['job_code' => 'FIN_NIGHTLY', 'fk_id_ent' => 1, 'started_at' => now(), 'run_status' => 'RUNNING']);
        $this->artisan('mo:finance-nightly')->assertSuccessful();
        $this->assertSame('LOCKED', DB::table('sys_job_runs')->where('job_code', 'FIN_NIGHTLY')->where('fk_id_ent', 1)->orderByDesc('id')->value('run_status'));
        $this->artisan('mo:finance-monthly')->assertSuccessful();
        $this->assertSame('OK', DB::table('sys_job_runs')->where('job_code', 'FIN_MONTHLY')->orderByDesc('id')->value('run_status'));
        $expr = fn (string $cmd) => collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, $cmd))?->expression;
        $this->assertSame(['30 0 * * *', '0 8 1 * *'], [$expr('mo:finance-nightly'), $expr('mo:finance-monthly')], 'nightly 00:30, monthly 1st 08:00');
    }
}
