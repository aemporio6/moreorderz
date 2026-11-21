<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\DsMatch;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesReports;
use App\Services\ShopLabel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-50 (annual report), 51 (shops × months), 81 (the one shop label), 91 (drop-shipping minimum margin), saved VA share + Refresh VA % of 31. */
class SalesReportsTest extends TestCase
{
    private int $mp; private int $shop; private int $va;

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $own = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => 'Omar', 'middle_name' => 'K', 'sur_name' => 'Owner', 'f_active' => 1]));
        $this->va = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'account_code' => 'VA-7', 'first_name' => 'Vera', 'sur_name' => 'Seller', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fk_id_owner' => $own, 'fk_id_seller' => $this->va, 'fk_id_company' => 1]));
        DB::table('t_account_job_history')->insert($this->fill('t_account_job_history', ['fk_id_account' => $this->va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => '2020-01-01', 'share_percentage' => 10]));
    }

    private function batch(int $shop, int $m, float $fee, float $pct, string $status = 'Pending'): int
    {
        return DB::table('t_sale_imports')->insertGetId($this->fill('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $this->mp, 'import_nature' => 'Normal', 'file_name' => 'f.csv', 'period_month' => $m, 'period_year' => 2026, 'marketplace_fee' => $fee, 'va_share_percentage' => $pct, 'status' => $status]));
    }

    private function line(int $shop, int $batch, string $date, float $total, float $buy, float $profit, int $qty = 1, array $x = []): int
    {
        return DB::table('t_sales')->insertGetId($this->fill('t_sales', $x + ['fk_id_ent' => 1, 'fk_id_import' => $batch, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $this->mp, 'order_no' => uniqid('o'), 'd_order' => $date, 'sku' => 'S', 'qty' => $qty, 'sale_rate' => $total, 'total_amount' => $total, 'buy_amount' => $buy, 'profit' => $profit, 'status' => 'Pending', 'sale_source' => 'active']));
    }

    public function test_the_one_shop_label_and_its_cascade(): void
    {
        $this->world(); $comp = (string) DB::table('t_enterprise')->where('id', 1)->value('ent_name');
        $l = ShopLabel::list($this->owner); $this->assertSame("101A · Omar K Owner · $comp · Pets", $l[0]['label']); $this->assertSame('101A (Pets)', $l[0]['short']);
        $this->assertSame(['Vera Seller', 10.0], [$l[0]['va'], $l[0]['va_pct']]);      // choosing a shop gives its VA and that VA's commission %
        // two shops 101A on two marketplaces: the labels differ by the marketplace; an inactive one is marked and not offered for import
        $mp2 = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amazon', 'marketplace_descr' => 'Amazon', 'f_active' => 1]));
        DB::table('t_branches')->insert($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp2, 'shop_no' => '101A', 'shop_name' => 'Toys', 'iso_branch' => 'P2', 'f_active' => 0]));
        $l = ShopLabel::list($this->owner); $this->assertSame(['Amazon – 101A · Toys (inactive)', "Ebay – 101A · Omar K Owner · $comp · Pets"], array_column($l, 'label'));      // sorted by marketplace, then shop no; empty parts left out
        $this->assertSame(["101A · Omar K Owner · $comp · Pets"], array_column(ShopLabel::list($this->owner, ['active_only' => true]), 'label'));
        $this->assertCount(1, ShopLabel::list($this->owner, ['marketplace' => $mp2]));
        $this->getJson('/api/shops/labels?active_only=1')->assertOk()->assertJsonCount(1, 'shops');
    }

    public function test_annual_report_with_the_example_of_the_rule(): void
    {
        $this->world(); $b = $this->batch($this->shop, 7, 40, 10); $this->line($this->shop, $b, '2026-07-05', 2000, 1100, 900, 3); $this->line($this->shop, $b, '2026-07-20', 100, 200, -100, 1);
        $b2 = $this->batch($this->shop, 3, 0, 10); $this->line($this->shop, $b2, '2026-03-02', 50, 20, 30);
        $r = SalesReports::annual(['year' => 2026, 'shop' => $this->shop], $this->owner); $this->assertCount(12, $r['rows']); $jul = $r['rows'][6];
        // July profit 800, fee 40, VA 10 % → after fee 760, VA 76, Store 684
        $this->assertSame(['July', 2100.0, 1300.0, 4, 40.0, 800.0, 760.0, 76.0, 684.0, 684.0, 100.0], [$jul['month'], $jul['sale'], $jul['buy'], $jul['qty'], $jul['fee'], $jul['profit'], $jul['after_fee'], $jul['va_share'], $jul['store_share'], $jul['net'], $jul['loss']]);
        $this->assertSame(0.0, $r['rows'][0]['sale']);      // months without sales are zeros
        $t = $r['total']; $this->assertSame(['Year Total', 2150.0, 830.0, 79.0, 711.0], [$t['month'], $t['sale'], $t['profit'], $t['va_share'], $t['store_share']]);      // Year Total = Σ of the months, shares included
        $this->assertSame(['VA Share [10 %]', 'expected cost (price period)', 'Vera Seller', 90.0], [$r['va_label'], $r['basis'], $r['va']['name'], $r['va']['store_pct']]);
        $this->assertSame(76.0, (float) DB::table('t_sale_imports')->where('id', $b)->value('va_share_amount'));      // the share is saved on the batch
        // a confirmed batch keeps its saved share even when a line changes later; the report reads the saved amount
        DB::table('t_sale_imports')->where('id', $b)->update(['status' => 'Confirmed']); DB::table('t_sales')->where('fk_id_import', $b)->where('profit', 900)->update(['profit' => 1900]);
        $this->assertSame(76.0, SalesReports::annual(['year' => 2026, 'shop' => $this->shop], $this->owner)['rows'][6]['va_share']);
        // downloads use exactly the screen figures, in the rule's column order and file names
        $c = SalesReports::download('annual', 'csv', ['year' => 2026, 'shop' => $this->shop], $this->owner, '2026-08-02 10:15:00'); $this->assertSame('sale_export_of_2026_20260802101500.csv', $c['name']);
        $rows = array_map(fn ($x) => str_getcsv($x, ',', '"', '\\'), array_filter(explode("\n", substr($c['body'], 3)))); $this->assertSame(['Month', 'Sales Total', 'Buy Amount', 'Qty', 'Marketplace Fee', 'Profit', 'Store Share', 'VA Share', 'Net', 'Loss'], $rows[0]); $this->assertSame('Year Total', $rows[13][0]); $this->assertCount(14, $rows);
        $p = SalesReports::download('annual', 'pdf', ['year' => 2026, 'shop' => $this->shop], $this->owner, '2026-08-02 10:15:00'); $this->assertSame('sale_export_for_2026_20260802101500.pdf', $p['name']); $this->assertStringStartsWith('%PDF', $p['body']); $this->assertStringContainsString('class="g"', $p['html']); $this->assertStringContainsString('class="r"', $p['html']);
        $this->assertStringStartsWith('PK', SalesReports::download('annual', 'xlsx', ['year' => 2026], $this->owner)['body']);
        $this->getJson('/api/sales/reports/annual?year=2026&shop=' . $this->shop)->assertOk()->assertJsonPath('rows.6.profit', 1800)->assertJsonCount(11, 'lists.years');
        $this->assertSame((int) date('Y'), SalesReports::year(1999));
    }

    public function test_refresh_va_percent_is_owner_only_logged_and_refused_when_the_month_is_closed(): void
    {
        $this->world(); $b = $this->batch($this->shop, 7, 40, 5); $this->line($this->shop, $b, '2026-07-05', 1000, 200, 800);
        try { SalesReports::refreshVa($b, ' ', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('Write the reason.', $e->getMessage()); }
        $r = SalesReports::refreshVa($b, 'job history was corrected', $this->owner); $this->assertSame([10.0, 76.0], [$r['pct'], $r['amount']]);
        $x = DB::table('t_sale_imports')->where('id', $b)->first(); $this->assertSame([10.0, 76.0, $this->va], [(float) $x->va_share_percentage, (float) $x->va_share_amount, (int) $x->fk_id_va_account]);
        DB::table('aud_runs')->insert($this->fill('aud_runs', ['run_code' => 'T-1', 'fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'period_month' => '2026-07', 'version' => 1, 'status' => 'LOCKED']));
        try { SalesReports::refreshVa($b, 'again', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('This month is settled/locked – use a correction in the next open month.', $e->getMessage()); }
    }

    public function test_detailed_report_cell_equals_the_strip_of_the_list(): void
    {
        $this->world(); $s2 = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '099', 'shop_name' => 'Home', 'iso_branch' => 'P9', 'f_active' => 1]));
        $b = $this->batch($this->shop, 7, 0, 0); $this->line($this->shop, $b, '2026-07-05', 30, 12, 18, 2); $this->line($this->shop, $b, '2026-07-09', 10, 4, 6, 1); $this->line($this->shop, $b, '2026-08-01', 5, 1, 4); $this->line($s2, $this->batch($s2, 7, 0, 0), '2026-07-03', 7, 2, 5);
        $d = SalesReports::detailed(['year' => 2026], $this->owner); $this->assertSame(['099', '101A'], array_map(fn ($r) => explode(' ', $r['label'])[2], $d['rows']));      // sorted by marketplace, then shop no
        $row = collect($d['rows'])->firstWhere('shop', $this->shop); $cell = $row['cells'][7]; $strip = SaleLines::list(['marketplace' => $this->mp, 'shop' => $this->shop, 'from' => '2026-07-01', 'to' => '2026-07-31'], $this->owner)['totals'];
        $this->assertSame([$strip['sale'], $strip['buy'], $strip['qty'], $strip['profit']], [$cell['total'], $cell['purchase'], $cell['qty'], $cell['profit']]); $this->assertSame([45.0, 4, 28.0], [$row['annual']['total'], $row['annual']['qty'], $row['annual']['profit']]);
        $this->assertSame(['Omar K Owner', 'Vera Seller'], [$row['owner'], $row['va']]); $this->assertSame(47.0, $d['total'][7]['total']);
        $this->assertCount(1, SalesReports::detailed(['year' => 2026, 'va' => $this->va], $this->owner)['rows']);
        $p = SalesReports::download('detailed', 'pdf', ['year' => 2026], $this->owner, '2026-08-02 10:15:00'); $this->assertSame('sale_detail_export_for_2026_20260802101500.pdf', $p['name']); $this->assertStringStartsWith('%PDF', $p['body']);
        $c = SalesReports::download('detailed', 'csv', ['year' => 2026], $this->owner); $this->assertStringContainsString('"Jul Total","Jul Purchase","Jul Qty","Jul Profit"', $c['body']); $this->assertStringContainsString('"Annual Profit"', $c['body']);
        $this->getJson('/api/sales/reports/detailed?year=2026')->assertOk()->assertJsonCount(2, 'rows');
    }

    public function test_drop_shipping_minimum_margin_with_the_example_of_the_rule(): void
    {
        $this->world(); $shopB = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '202', 'shop_name' => 'B', 'iso_branch' => 'P3', 'f_active' => 1]));
        $this->assertSame('10', Choices::get('DS_MIN_MARGIN', 1)); $this->assertSame(['0', '5', '10', '12', '15', '20', '25', '30'], array_map('strval', array_keys(Choices::LIST['DS_MIN_MARGIN'][3])));
        DB::table('t_branches')->where('id', $this->shop)->update(['ds_min_margin_pct' => 15]);      // company 10 %, shop A override 15 %
        $ds = ['sale_source' => 'ds']; $open = fn (int $id) => DB::table('tasks')->where('dedupe_key', "SAL_DS_MARG:$id")->whereNotIn('status', ['DONE', 'CANCELLED', 'APPROVED'])->exists();
        $a = $this->line($this->shop, 0, '2026-07-05', 100, 88, 12, 1, $ds); $b = $this->line($shopB, 0, '2026-07-05', 100, 88, 12, 1, $ds); $c = $this->line($shopB, 0, '2026-07-05', 100, 102, -2, 1, $ds);
        $this->assertSame([12.0, 12.0, -2.0], [DsMatch::marginTask($a), DsMatch::marginTask($b), DsMatch::marginTask($c)]);
        $this->assertSame([true, false, true], [$open($a), $open($b), $open($c)]);      // 12 % on shop A → task; the same line on shop B → none; −2 % on any shop → task
        Choices::setShop('DS_MIN_MARGIN', $shopB, '20', 1); \App\Services\Settings::flush(); DsMatch::marginTask($b); $this->assertTrue($open($b));
        // accepted with a note: the task closes and stays closed
        DsMatch::acceptMargin($a, 'agreed with the owner', $this->owner); $this->assertFalse($open($a)); DsMatch::marginTask($a); $this->assertFalse($open($a));
        $this->assertSame('margin accepted: agreed with the owner', DB::table('t_sales')->where('id', $a)->value('ds_note'));
    }
}
