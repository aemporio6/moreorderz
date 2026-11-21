<?php

namespace Tests\Feature;

use App\Services\BulkJobs;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-39: job history is a sub-list – checks in the old order with old texts, overlap guard, currency default, soft delete. */
class JobHistoryTest extends TestCase
{
    private int $acc; private int $ebay; private string $k = 't_account_job_history';

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $eur = DB::table('cat_currency')->where('currency_code', 'EUR')->value('id') ?: $f('cat_currency', ['currency_code' => 'EUR', 'currency_name' => 'Euro', 'f_active' => 1]);
        $de = $f('cat_country', ['country_name' => 'Germany', 'iso_code' => 'DE', 'fk_id_default_currency' => $eur, 'f_active' => 1]);
        $this->acc = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'J' . random_int(100, 999), 'first_name' => 'Jo', 'sur_name' => 'Va', 'f_active' => 1, 'current_nationality' => $de]);
        $this->ebay = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'f_active' => 1]);
    }

    private function err(array $d, ?int $id = null): array { try { RecordService::save($this->k, $d, $id); return []; } catch (ValidationException $e) { return array_map(fn ($m) => $m[0], $e->errors()); } }

    public function test_the_rule_example_overlap_guard(): void
    {
        $this->world();
        RecordService::save($this->k, ['fk_id_account' => $this->acc, 'fk_id_marketplace' => $this->ebay, 'd_job_start_date' => '2025-01-01', 'd_end_date' => '2025-12-31', 'share_percentage' => 10]);
        RecordService::save($this->k, ['fk_id_account' => $this->acc, 'fk_id_marketplace' => $this->ebay, 'd_job_start_date' => '2026-01-01', 'share_percentage' => 15]);
        $this->assertSame(2, DB::table($this->k)->where('fk_id_account', $this->acc)->count());      // both kept
        $this->assertSame('Overlaps the row from 01-01-2025.', $this->err(['fk_id_account' => $this->acc, 'fk_id_marketplace' => $this->ebay, 'd_job_start_date' => '2025-06-01'])['d_job_start_date']);
    }

    public function test_checks_defaults_and_soft_delete(): void
    {
        $this->world();
        $this->assertSame(['fk_id_marketplace' => 'Select Marketplace.', 'd_job_start_date' => 'Start Date is required.'], array_intersect_key($this->err(['fk_id_account' => $this->acc]), ['fk_id_marketplace' => 1, 'd_job_start_date' => 1]));
        $base = ['fk_id_account' => $this->acc, 'fk_id_marketplace' => $this->ebay, 'd_job_start_date' => '2026-03-01'];
        $this->assertSame('End date must be on or after the start date.', $this->err($base + ['d_end_date' => '2026-02-01'])['d_end_date']);
        $this->assertSame('Share % goes in steps of 0.5 (for example 12.5).', $this->err($base + ['share_percentage' => 12.3])['share_percentage']);
        $this->assertArrayHasKey('pound_exchange_rate', $this->err($base + ['pound_exchange_rate' => 0]));
        $other = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz', 'f_active' => 1]));
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $other, 'shop_no' => '9J', 'shop_name' => 'J', 'iso_branch' => 'J9', 'f_active' => 1]));
        $this->assertSame('This shop belongs to another marketplace.', $this->err($base + ['fk_id_branch' => $shop])['fk_id_branch']);
        $id = RecordService::save($this->k, $base + ['share_percentage' => 12.5, 'salary' => 850.50])['id']; $r = DB::table($this->k)->where('id', $id)->first();
        $this->assertSame(['EUR', 12.5, 850.5], [DB::table('cat_currency')->where('id', $r->fk_id_currency)->value('currency_code'), (float) $r->share_percentage, (float) $r->salary]);      // the person's country currency; salary as money
        $p = BulkJobs::preview($this->k, $this->owner, 'DELETE', [$id]); $this->assertSame(1, $p['will_change']); BulkJobs::apply($this->k, $this->owner, 'DELETE', [$id]);
        $this->assertSame(0, (int) DB::table($this->k)->where('id', $id)->value('f_active'));      // really deleted (soft, with history) – the old delete never removed the row
        $this->assertSame([], $this->getJson("/api/people/{$this->acc}/jobs")->assertOk()->json('rows'));
    }
}
