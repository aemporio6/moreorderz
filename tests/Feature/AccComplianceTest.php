<?php

namespace Tests\Feature;

use App\Services\AccCompliance as C;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-ACC-1 / 2 / 3 / 5 / 8 / 9 compliance dates – the spec examples (UK law as written in the rules). */
class AccComplianceTest extends TestCase
{
    private function co(array $x = []): object { $id = DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', $x + ['company_name' => 'Test Trading Ltd', 'fk_id_ent' => 1, 'f_active' => 1])); return DB::table('cat_company_house')->where('id', $id)->first(); }

    public function test_add_months_clamps_to_month_end(): void
    {
        $this->assertSame(['2026-02-28', '2024-02-29', '2025-11-28', '2023-05-31'], [C::addMonths('2025-05-31', 9), C::addMonths('2023-05-31', 9), C::addMonths('2026-02-28', -3), C::addMonths('2024-05-31', -12)]);
    }

    public function test_r1_r2_r3_year_end_periods_accounts_and_ct(): void
    {
        $co = $this->co(['incorporate_date' => '2023-05-10']);
        $this->assertSame([31, 5], C::yearEnd($co, null), 'no settings → ye 31 May (incorporation month, month end)');
        $p = C::periods($co, null, '2026-12-31');
        $this->assertSame(['start' => '2023-05-10', 'end' => '2024-05-31', 'first' => true], $p[0], 'first period 2023-05-10 → 2024-05-31');
        $this->assertSame(['2024-06-01', '2025-05-31'], [$p[1]['start'], $p[1]['end']]);
        $this->assertSame('2025-02-10', C::accountsDue($p[0], $co), 'first long period → due 2025-02-10 (21 months from incorporation)');
        $this->assertSame('2026-02-28', C::accountsDue($p[1], $co), 'second period to 2025-05-31 → 2026-02-28');
        $ct = C::ctPeriods($p[0]);
        $this->assertSame([['2023-05-10', '2024-05-09', '2025-02-10', '2025-05-31'], ['2024-05-10', '2024-05-31', '2025-03-01', '2025-05-31']], array_map(fn ($c) => [$c['start'], $c['end'], $c['pay'], $c['ct600']], $ct), 'CT1 + CT2, both CT600 2025-05-31');
        $frd = $this->co(['incorporate_date' => '2023-05-10', 'file_return_date' => '2026-02-20']);
        $this->assertSame('2026-02-20', C::accountsDue(C::periods($frd, null, '2026-12-31')[1], $frd), 'a File Return Date within 45 days wins');
    }

    public function test_r5_confirmation_statement(): void
    {
        $co = $this->co(['incorporate_date' => '2020-01-15']);
        $s = (object) ['cs_review_date' => '2026-05-10', 'cs_date_is' => null, 'year_end_day' => 0, 'year_end_month' => 0, 'first_period_end' => null, 'track_from' => null];
        $cs = collect(C::confirmationStatements($co, $s, '2025-11-22', '2027-02-22'))->firstWhere('end', '2026-05-10');
        $this->assertSame(['start' => '2025-05-11', 'end' => '2026-05-10', 'due' => '2026-05-24'], $cs, 'cs_review_date 2026-05-10 → due 2026-05-24, period 2025-05-11 → 2026-05-10');
        $ch = $this->co(['incorporate_date' => '2020-01-15', 'confirmation_statement_date' => '2026-03-01']);
        $this->assertSame('2026-03-01', C::confirmationStatements($ch, null, '2025-01-01', '2027-12-31')[0]['end'], 'the Companies House date is the next statement – earlier years skipped');
    }

    public function test_r8_r9_window_track_from_generate_idempotent_rebuild(): void
    {
        $co = $this->co(['incorporate_date' => '2023-05-10']);
        $n = C::generate(1, (int) $co->id, '2026-11-22');
        $this->assertGreaterThan(0, $n);
        $this->assertSame(0, C::generate(1, (int) $co->id, '2026-11-22'), 'run twice → 0 new rows');
        $keys = DB::table('acc_obligations')->where('entity_id', $co->id)->pluck('due_date', 'gen_key')->all();
        $this->assertSame('2026-02-28', $keys["C{$co->id}|ACCOUNTS|2025-05-31"]);
        $this->assertTrue(collect($keys)->every(fn ($d) => $d >= '2025-11-22'), 'window: due ≥ today − 12 months');
        $id = DB::table('acc_obligations')->where('gen_key', "C{$co->id}|ACCOUNTS|2025-05-31")->value('id');
        DB::table('acc_obligations')->where('id', $id)->update(['status' => 'FILED', 'f_done' => 1, 'submission_ref' => 'CH-1']);
        DB::table('acc_company_settings')->insert(RecordService::fillNotNull('acc_company_settings', ['fk_id_ent' => 1, 'fk_id_company' => $co->id, 'f_tracked' => 1, 'track_from' => '2026-06-01']));
        $msg = C::rebuild(1, (int) $co->id, '2026-11-22');
        $this->assertMatchesRegularExpression('/^\d+ removed, \d+ added$/', $msg);
        $this->assertSame('FILED', DB::table('acc_obligations')->where('id', $id)->value('status'), 'a FILED row is untouched by rebuild');
        $this->assertFalse(DB::table('acc_obligations')->where('entity_id', $co->id)->where('status', 'PENDING')->where('due_date', '<', '2026-06-01')->exists(), 'track_from removes rows due before it');
        DB::table('acc_company_settings')->where('fk_id_company', $co->id)->update(['f_tracked' => 0]);
        $this->assertSame(0, C::generate(1, (int) $co->id, '2027-06-01'), 'a company not tracked is skipped');
        $this->actingAs($this->owner)->getJson('/api/compliance')->assertOk()->assertJsonStructure(['stale', 'rows', 'companies']);
    }
}
