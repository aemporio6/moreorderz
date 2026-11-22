<?php

namespace Tests\Feature;

use App\Services\AccCompliance as C;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-ACC-4 VAT, R-ACC-6 PAYE, R-ACC-7 Self Assessment, R-ACC-8 / 9 person rows, R-ACC-10 status machine – the spec examples. */
class AccCompliance2Test extends TestCase
{
    private function co(array $x = []): object { $id = DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', $x + ['company_name' => 'Test Trading Ltd', 'fk_id_ent' => 1, 'f_active' => 1])); return DB::table('cat_company_house')->where('id', $id)->first(); }
    private function s(array $x): object { return (object) ($x + ['vat_registered' => 0, 'vat_frequency' => 'Q', 'vat_stagger' => 1, 'vat_reg_date' => null, 'year_end_day' => 0, 'year_end_month' => 0, 'first_period_end' => null, 'paye_registered' => 0, 'paye_start_date' => null, 'paye_quarterly' => 0, 'eps_required' => 0, 'pay_frequency' => 'M', 'payday_day' => 28, 'first_payday' => null]); }

    public function test_r4_vat_stagger_dates(): void
    {
        $co = $this->co(['incorporate_date' => '2020-01-15']);
        $q1 = collect(C::vatRows($co, $this->s(['vat_registered' => 1, 'vat_stagger' => 1]), '2026-01-01', '2026-12-31'))->firstWhere(1, '2026-03-31');
        $this->assertSame(['2026-01-01', '2026-03-31', '2026-05-07'], $q1, 'stagger 1, period end 2026-03-31 → due 2026-05-07');
        $q2 = collect(C::vatRows($co, $this->s(['vat_registered' => 1, 'vat_stagger' => 2]), '2026-01-01', '2026-12-31'))->firstWhere(1, '2026-04-30');
        $this->assertSame('2026-06-07', $q2[2], 'stagger 2, end 2026-04-30 → due 2026-06-07');
        $this->assertSame(['03', '06', '09', '12'], collect(C::vatRows($co, $this->s(['vat_registered' => 1]), '2026-01-01', '2026-12-31'))->filter(fn ($r) => substr($r[1], 0, 4) === '2026')->map(fn ($r) => substr($r[1], 5, 2))->values()->all());
        $reg = C::vatRows($co, $this->s(['vat_registered' => 1, 'vat_reg_date' => '2026-02-10']), '2026-01-01', '2026-12-31');
        $this->assertSame('2026-02-10', $reg[0][0], 'a start before registration is clipped');
        $this->assertSame([], C::vatRows($co, $this->s([]), '2026-01-01', '2026-12-31'), 'not VAT registered → nothing');
    }

    public function test_r6_paye_monthly_quarterly_fps(): void
    {
        $co = $this->co(['incorporate_date' => '2020-01-15']);
        $m = C::payeRows($co, $this->s(['paye_registered' => 1, 'eps_required' => 1]), '2026-10-01', '2026-12-31');
        $this->assertContains(['PAYE_PAY', '2026-10-06', '2026-11-05', '2026-11-22'], $m, 'tax month ending 2026-11-05 → PAYE_PAY 2026-11-22');
        $this->assertContains(['PAYE_EPS', '2026-10-06', '2026-11-05', '2026-11-19'], $m, 'EPS 2026-11-19');
        $this->assertContains(['PAYE_FPS', null, null, '2026-11-28'], $m, 'FPS on payday 28');
        $q = C::payeRows($co, $this->s(['paye_registered' => 1, 'paye_quarterly' => 1]), '2026-06-01', '2026-12-31');
        $this->assertContains(['PAYE_PAY', '2026-04-06', '2026-07-05', '2026-07-22'], $q, 'quarterly: 2026-07-05 → period 2026-04-06 → 2026-07-05, due 2026-07-22');
        $this->assertSame([], array_filter(C::payeRows($co, $this->s(['paye_registered' => 1, 'pay_frequency' => 'W']), '2026-10-01', '2026-12-31'), fn ($r) => $r[0] === 'PAYE_FPS'), 'weekly without first payday → no FPS');
        $this->assertCount(13, array_filter(C::payeRows($co, $this->s(['paye_registered' => 1, 'pay_frequency' => 'W', 'first_payday' => '2026-10-02']), '2026-10-01', '2026-12-31'), fn ($r) => $r[0] === 'PAYE_FPS'));
    }

    public function test_r7_self_assessment_and_person_generation(): void
    {
        $ps = (object) ['sa_registered' => 1, 'poa_required' => 1, 'first_tax_year' => 0];
        $r = collect(C::saRows($ps, '2025-11-22', '2028-02-22'))->filter(fn ($x) => $x[1] === '2025-04-06')->mapWithKeys(fn ($x) => [$x[0] => $x[3]])->all();
        $this->assertSame(['SA_RETURN' => '2027-01-31', 'SA_POA1' => '2026-01-31', 'SA_POA2' => '2026-07-31'], $r, 'tax year 2025-26 → return 2027-01-31, POA1 2026-01-31, POA2 2026-07-31');
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'SA' . random_int(1000, 9999), 'first_name' => 'Sana', 'f_active' => 1]));
        DB::table('acc_person_settings')->insert(RecordService::fillNotNull('acc_person_settings', ['fk_id_ent' => 1, 'fk_id_account' => $acc, 'sa_registered' => 1, 'poa_required' => 1, 'first_tax_year' => 0]));
        $n = C::generate(1, null, '2026-11-22');
        $this->assertTrue(DB::table('acc_obligations')->where('gen_key', "P$acc|SA_RETURN|2026-04-05")->where('due_date', '2027-01-31')->exists(), 'person rows by gen_key P<id>|type|period end');
        $this->assertSame(0, C::generate(1, null, '2026-11-22'), 'run twice → 0');
        $this->assertGreaterThan(0, $n);
    }

    public function test_r10_status_machine_and_proof(): void
    {
        $this->actingAs($this->owner);
        $co = $this->co(['incorporate_date' => '2023-05-10']); C::generate(1, (int) $co->id, '2026-11-22');
        $pay = DB::table('acc_obligations')->where('entity_id', $co->id)->where('type_code', 'CT_PAYMENT')->first();
        $this->postJson("/api/compliance/{$pay->id}", ['status' => 'PAID'])->assertStatus(422)->assertJsonPath('message', 'Add the proof document first (the filing receipt or the payment proof).');
        Storage::fake('local');
        $this->post("/api/compliance/{$pay->id}/document", ['file' => UploadedFile::fake()->create('receipt.pdf', 20)], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse($this->postJson("/api/compliance/{$pay->id}", ['status' => 'FILED'])->assertOk()->json('done'), 'CT_PAYMENT (PAY) set FILED → still not done');
        $this->assertTrue($this->postJson("/api/compliance/{$pay->id}", ['status' => 'PAID'])->assertOk()->json('done'));
        $row = DB::table('acc_obligations')->where('id', $pay->id)->first();
        $this->assertSame([date('Y-m-d'), null, (int) $this->owner->id], [$row->paid_date, $row->filed_date, (int) $row->filed_by], 'PAID → paid date today; no filed date for a PAY type; filed_by once');
        $this->postJson("/api/compliance/{$pay->id}", ['due_date' => '2030-01-01'])->assertStatus(422);
        $this->assertGreaterThanOrEqual(3, DB::table('acc_obligation_log')->where('fk_id_obligation', $pay->id)->count(), 'every change logged');
        $this->assertTrue(C::isDone('FILE', 'FILED')); $this->assertTrue(C::isDone('PAY', 'NOT_REQUIRED')); $this->assertFalse(C::isDone('FILE_PAY', 'FILED'));
    }
}
