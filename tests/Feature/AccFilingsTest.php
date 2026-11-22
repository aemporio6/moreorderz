<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccCompliance;
use App\Services\AccFilings as F;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-ACC-11 types, R-ACC-12 reminder digest, R-ACC-13 overview, R-ACC-14 manual items, R-ACC-15 documents, R-ACC-19 lists – the spec tests. */
class AccFilingsTest extends TestCase
{
    private function co(string $name, array $x = []): int { return (int) DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', $x + ['company_name' => $name, 'fk_id_ent' => 1, 'f_active' => 1])); }
    private function person(string $first): int { return (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'P' . random_int(10000, 99999), 'first_name' => $first, 'f_active' => 1])); }
    private function item(int $co, string $due, array $x = []): int { return (int) DB::table('acc_obligations')->insertGetId($x + ['fk_id_ent' => 1, 'entity_type' => 'COMPANY', 'entity_id' => $co, 'fk_type' => 0, 'type_code' => 'CT600', 'area' => 'CT', 'completion' => 'FILE', 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 0, 'title' => 'Test item', 'created_at' => now()]); }

    public function test_r11_types_reminder_days_and_other(): void
    {
        $this->assertSame('30,7', F::days('7,30,7,400'));
        $this->assertSame('30,7', F::saveType(1, 'CT600', ['reminder_days' => '7,30,7,400'], $this->owner)['reminder_days'], 'reminder "7,30,7,400" → saved "30,7"');
        $this->assertSame('30,7', F::types(1)['CT600']->reminder_days, 'the enterprise copy wins');
        $this->assertSame('30,14,7,1', DB::table('acc_obligation_types')->where('fk_id_ent', 0)->where('type_code', 'CT600')->value('reminder_days'), 'the seeded row is left alone');
        $this->assertSame(1, F::saveType(1, 'OTHER', ['f_active' => 0], $this->owner)['f_active'], 'OTHER is always active');
        $this->expectExceptionMessage('Write the name.'); F::saveType(1, 'VAT_RETURN', ['type_name' => ' '], $this->owner);
    }

    public function test_r12_digest_once_per_day(): void
    {
        F::saveType(1, 'CT600', ['reminder_days' => '30,14'], $this->owner);
        $co = $this->co('Digest Test Ltd'); DB::table('acc_company_settings')->insert(RecordService::fillNotNull('acc_company_settings', ['fk_id_ent' => 1, 'fk_id_company' => $co, 'f_tracked' => 0]));
        $id = $this->item($co, '2026-12-06', ['fk_assigned' => $this->owner->id]);
        $this->assertSame('Digest Test Ltd: Test item – 06-12-2026 (due in 14 days)', F::line('Digest Test Ltd', 'Test item', '2026-12-06', 14));
        F::reminders(1, '2026-11-22');
        $msgs = DB::table('tm_messages')->where('rule_code', 'N17')->where('user_id', $this->owner->id)->where('channel', 'INAPP')->get();
        $this->assertCount(1, $msgs, 'type days 30,14 and an item due in 14 days → one message');
        $this->assertStringContainsString('Digest Test Ltd: Test item – 06-12-2026 (due in 14 days)', $msgs[0]->text);
        $this->assertTrue(DB::table('acc_reminder_log')->where('fk_id_obligation', $id)->where('remind_key', 'D14')->exists());
        $this->assertSame(0, F::reminders(1, '2026-11-22'), 'same day re-run → none');
        $this->assertSame(1, DB::table('tm_messages')->where('rule_code', 'N17')->where('user_id', $this->owner->id)->where('channel', 'INAPP')->count());
        $this->assertSame('Digest Test Ltd: Test item – 06-12-2026 (OVERDUE by 2 days)', F::line('Digest Test Ltd', 'Test item', '2026-12-06', -2));
    }

    public function test_r13_calendar_tabs_and_summary(): void
    {
        $co = $this->co('Calendar Ltd');
        foreach (['2026-11-01', '2026-11-30', '2026-12-01', '2026-10-15'] as $d) $this->item($co, $d);
        $done = $this->item($co, '2026-11-10', ['status' => 'FILED', 'f_done' => 1, 'filed_date' => '2026-11-09']);
        $o = F::overview(1, ['entity' => "C$co", 'ym' => '2026-11', 'tab' => 'all'], '2026-11-22');
        $this->assertSame([1, 10, 30], array_keys($o['calendar']['items']), '?ym=2026-11 shows every item due in November (done ones too)');
        $this->assertSame(2, F::overview(1, ['entity' => "C$co", 'tab' => 'overdue'], '2026-11-22')['total']);
        $this->assertSame(1, F::overview(1, ['entity' => "C$co", 'tab' => 'done'], '2026-11-22')['total']);
        $s = collect($o['summary'])->firstWhere('area', 'CT');
        $this->assertSame([2, 4, 1, '2026-11-30'], [$s['overdue'], $s['open'], $s['done_month'], $s['next_due']]);
        $this->assertSame(10, F::overview(1, ['entity' => "C$co", 'tab' => 'all', 'per' => 3], '2026-11-22')['per'], 'page size at least 10');
        $this->assertSame(25, F::overview(1, ['entity' => "C$co"], '2026-11-22')['per'], 'default 25');
        $this->assertCount(1, F::overview(1, ['q' => 'Calendar', 'tab' => 'done'], '2026-11-22')['rows'], 'text search on the company');
    }

    public function test_r14_manual_items(): void
    {
        $this->actingAs($this->owner);
        $co = $this->co('Manual Ltd', ['incorporate_date' => '2023-05-10']); AccCompliance::generate(1, $co, '2026-11-22');
        $auto = DB::table('acc_obligations')->where('entity_id', $co)->where('type_code', 'ACCOUNTS')->value('id');
        $this->deleteJson("/api/compliance/$auto")->assertStatus(422)->assertJsonPath('message', 'This item was made by the rules – it cannot be deleted; set it to Not required with a reason.');
        $this->postJson("/api/compliance/$auto", ['status' => 'NOT_REQUIRED'])->assertStatus(422)->assertJsonPath('message', 'Write the reason why this filing is not required.');
        $this->postJson("/api/compliance/$auto", ['status' => 'NOT_REQUIRED', 'reason' => 'Dormant company'])->assertOk();
        $this->postJson('/api/compliance/items', ['entity_type' => 'COMPANY', 'entity_id' => $co, 'type_code' => 'OTHER'])->assertStatus(422);
        $va = User::create(['login_id' => 'va@test', 'password_hash' => bcrypt('x-password-1'), 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->postJson('/api/compliance/items', ['entity_type' => 'COMPANY', 'entity_id' => $co, 'type_code' => 'OTHER', 'title' => 'Bank letter', 'due_date' => '2026-12-31', 'fk_assigned' => $va->id])->assertStatus(422)->assertJsonPath('message', 'Choose an accountant of the Hub (a login that may change filings).');
        $id = $this->postJson('/api/compliance/items', ['entity_type' => 'COMPANY', 'entity_id' => $co, 'type_code' => 'OTHER', 'title' => 'Bank letter', 'due_date' => '2026-12-31', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'fk_assigned' => $this->owner->id])->assertOk()->json('id');
        $this->assertSame(0, (int) DB::table('acc_obligations')->where('id', $id)->value('f_auto'));
        $this->deleteJson("/api/compliance/$id")->assertOk(); $this->assertFalse(DB::table('acc_obligations')->where('id', $id)->exists());
    }

    public function test_r15_documents(): void
    {
        $this->actingAs($this->owner); Storage::fake('local');
        $co = $this->co('Docs Ltd'); $it = $this->item($co, '2026-12-01');
        $this->post("/api/compliance/$it/document", ['file' => UploadedFile::fake()->create('setup.pdf', 5, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'The file is not a real .pdf (it is application/x-msdownload) – refused.');
        $this->post("/api/compliance/$it/document", ['file' => UploadedFile::fake()->create('tool.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/compliance/$it/document", ['file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $doc = DB::table('acc_documents')->where('fk_id_obligation', $it)->first();
        $this->assertMatchesRegularExpression('#^1/[0-9a-f]{32}\.pdf$#', $doc->stored_name, '<enterprise>/<md5>.<ext>');
        $r = $this->get("/api/compliance/documents/{$doc->id}")->assertOk();
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control')); $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));
        $this->post('/api/compliance/documents', ['entity_type' => 'COMPANY', 'entity_id' => $co, 'doc_title' => 'VAT certificate', 'file' => UploadedFile::fake()->create('vat.csv', 2, 'text/csv')], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(2, $this->getJson("/api/compliance/documents?entity=C$co")->json('rows'));
        $this->assertCount(1, $this->getJson("/api/compliance/documents?entity=C$co&q=certificate")->json('rows'));
        $this->deleteJson("/api/compliance/documents/{$doc->id}")->assertOk();
        $this->assertSame(1, (int) DB::table('acc_documents')->where('id', $doc->id)->value('f_deleted'), 'soft delete');
        $this->assertTrue(DB::table('acc_obligation_log')->where('fk_id_obligation', $it)->where('details', 'like', 'proof removed%')->exists(), 'logged on the item');
        $this->get("/api/compliance/documents/{$doc->id}")->assertNotFound();
    }

    public function test_r19_lists(): void
    {
        $p = $this->person('Zara'); $a = $this->co('Alpha Trading Ltd', ['fk_director_1' => $p, 'vat_no' => 'GB123']); $b = $this->co('Beta Trading Ltd', ['fk_director_2' => $p]);
        $rows = array_values(array_filter(F::people(1, [])['rows'], fn ($r) => $r['id'] === $p));
        $this->assertCount(1, $rows, 'a director of 2 companies appears once');
        $this->assertSame(['Alpha Trading Ltd', 'Beta Trading Ltd'], $rows[0]['companies'], '… with both company names');
        $this->item($a, '2026-11-01');
        $c = F::companies(1, ['q' => 'GB123'], '2026-11-22')['rows'];
        $this->assertSame([[$a, 1, true]], array_map(fn ($r) => [$r['id'], $r['overdue'], $r['tracked']], $c), 'search by VAT number; overdue count; tracked by default');
        $this->assertContains($a, array_column(F::companies(1, ['filter' => 'overdue'], '2026-11-22')['rows'], 'id'));
        $this->assertNotContains($b, array_column(F::companies(1, ['filter' => 'overdue'], '2026-11-22')['rows'], 'id'));
    }
}
