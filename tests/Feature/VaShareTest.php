<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Fx;
use App\Services\JobPay;
use App\Services\RecordService;
use App\Services\SalesImport;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-40: VA % valid on the batch period end (shop row first), frozen; "No VA share set"; per-line commission as information; salary in GBP; Team engagements. */
class VaShareTest extends TestCase
{
    private int $va; private int $mp; private object $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->va = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'VA' . random_int(100, 999), 'first_name' => 'Sana', 'sur_name' => 'Ali', 'f_active' => 1]);
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayV', 'f_active' => 1]);
        $sid = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => 'V1', 'iso_branch' => 'V1', 'shop_name' => 'Shop V', 'f_active' => 1, 'fk_id_seller' => $this->va]);
        $this->shop = DB::table('t_branches')->where('id', $sid)->first();
    }

    private function job(array $x): int
    {
        return DB::table('t_account_job_history')->insertGetId(RecordService::fillNotNull('t_account_job_history', $x + ['fk_id_account' => $this->va, 'fk_id_marketplace' => $this->mp, 'f_active' => 1]));
    }

    public function test_the_rule_example_valid_on_period_end_frozen_shop_row_first_and_salary_in_gbp(): void
    {
        $this->job(['d_job_start_date' => '2025-01-01', 'd_end_date' => '2025-12-31', 'share_percentage' => 10]);
        $this->job(['d_job_start_date' => '2026-01-01', 'share_percentage' => 15]);
        $this->assertSame(10.0, SalesImport::vaOf($this->shop, 8, 2025)['va_share_percentage'], 'Aug 2025 → 10 %');
        $this->assertSame(15.0, SalesImport::vaOf($this->shop, 2, 2026)['va_share_percentage'], 'Feb 2026 → 15 %');
        $this->assertSame(0, SalesImport::vaOf($this->shop, 2, 2026)['va_share_missing']);

        // a row for the shop wins over a row with no shop (even an older one)
        $this->job(['d_job_start_date' => '2025-06-01', 'share_percentage' => 12, 'fk_id_branch' => $this->shop->id]);
        $this->assertSame(12.0, SalesImport::vaOf($this->shop, 8, 2025)['va_share_percentage']);

        // frozen on the batch: a later row does not change a saved batch; VA share = (profit − fee) × % = 900 × 10 % = 90, store % 90, net 810
        $b = DB::table('t_sale_imports')->insertGetId(RecordService::fillNotNull('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop->id, 'fk_id_marketplace' => $this->mp, 'period_month' => 8, 'period_year' => 2025, 'marketplace_fee' => 100, 'status' => 'Pending'] + SalesImport::vaOf($this->shop, 8, 2025)));
        DB::table('t_sale_imports')->where('id', $b)->update(['va_share_percentage' => 10]);      // the batch as imported before the shop row existed
        $this->job(['d_job_start_date' => '2025-08-01', 'share_percentage' => 30]);
        $this->assertSame(10.0, (float) DB::table('t_sale_imports')->where('id', $b)->value('va_share_percentage'));
        $share = round((1000 - 100) * 10 / 100, 2); $this->assertSame([90.0, 810.0], [$share, 900 - $share]);

        // salary 100,000 PKR at rate 350 → £285.71; no £ rate → month-end FX, never 1.0
        $j = $this->job(['d_job_start_date' => '2026-01-01', 'share_percentage' => 5, 'salary' => 100000, 'pound_exchange_rate' => 350, 'fk_id_currency' => DB::table('cat_currency')->where('currency_code', 'PKR')->value('id') ?? DB::table('cat_currency')->insertGetId(['currency_code' => 'PKR', 'currency_name' => 'Rupee', 'f_active' => 1])]);
        $row = DB::table('t_account_job_history')->where('id', $j)->first();
        $this->assertSame(285.71, JobPay::salaryGbp($row, 1, '2026-03-15'));
        DB::table('t_account_job_history')->where('id', $j)->update(['pound_exchange_rate' => 0]); Fx::set('PKR', '2026-03-01', 0.0028);
        $this->assertSame(280.0, JobPay::salaryGbp(DB::table('t_account_job_history')->where('id', $j)->first(), 1, '2026-03-15'), 'month-end FX: 100,000 × 0.0028');

        // job history card: GBP salary + totals; Team engagements report + xlsx
        $d = $this->getJson("/api/people/{$this->va}/jobs")->assertOk()->json();
        $this->assertArrayHasKey('salary_gbp', $d['rows'][0]); $this->assertGreaterThanOrEqual(1, $d['totals']['running']);
        $rep = $this->getJson('/api/reports/team_engagements?from=2026-03-01&to=2026-03-31')->assertOk()->json();
        $this->assertSame(['Sana Ali', 'eBayV'], [$rep['rows'][0]['name'], $rep['rows'][0]['marketplace']]);
        $this->assertSame(280.0, (float) collect($rep['rows'])->firstWhere('salary_gbp', 280.0)['salary_gbp']);
        $x = $this->get('/api/reports/team_engagements/xlsx?to=2026-03-31')->assertOk();
        $this->assertStringContainsString('team_engagements_2026-03.xlsx', (string) $x->headers->get('Content-Disposition'));
    }

    public function test_no_va_share_set_when_no_valid_row(): void
    {
        $this->job(['d_job_start_date' => '2026-01-01', 'share_percentage' => 15]);
        $v = SalesImport::vaOf($this->shop, 8, 2025);
        $this->assertSame([0.0, 1], [$v['va_share_percentage'], $v['va_share_missing']], 'no row valid on 31 Aug 2025 → 0 % and the warning chip');
    }
}
