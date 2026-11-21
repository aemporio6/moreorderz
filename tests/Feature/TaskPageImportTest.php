<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskCreate;
use App\Services\TaskFlow;
use App\Services\TaskImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-34 task CSV import, R-G_CORE-6 proof switch + Partner-only fields, R-G_CORE-41 task record page – the spec examples. */
class TaskPageImportTest extends TestCase
{
    private function due(int $d = 3): string { return date('Y-m-d', strtotime("+$d days")); }

    public function test_r34_three_rows_row3_waits_for_row1_and_reimport_is_refused(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); DB::table('t_users')->where('id', $va->id)->update(['display_name' => 'Imran Q' . $va->id]);
        $who = 'Imran Q' . $va->id; $d = $this->due(); $d2 = date('d/m/Y', strtotime('+4 days'));
        $csv = "ref;title;category;owner;due_at;priority;status;checklist;evidence_required;depends_on_ref\nR1;Count A1;Stock;$who;$d;NORMAL;TODO;Count|Photo;yes;\nR2;Label A1;Stock;$who;$d2;HIGH;DOING;;no;\nR3;Recount A1;Stock;$who;$d;;TODO;;yes;R1\n";
        $this->actingAs($mgr);
        $chk = TaskImport::run($mgr, 'tasks.csv', "\xEF\xBB\xBF" . $csv, false);
        $this->assertTrue($chk['ok']); $this->assertSame(0, DB::table('tasks')->where('import_ref', 'like', 'csv:R%')->count(), 'check changes nothing');
        $r = TaskImport::run($mgr, 'tasks.csv', $csv, true);
        $this->assertSame(3, $r['imported']);
        $ids = DB::table('tasks')->whereIn('import_ref', ['csv:R1', 'csv:R2', 'csv:R3'])->orderBy('id')->pluck('id')->all();
        $t3 = DB::table('tasks')->where('id', $ids[2])->first();
        $this->assertSame($ids[0], (int) $t3->depends_on_task_id);
        $this->assertSame('BLOCKED', \App\Services\TaskHealth::of($t3)['code'], 'T-0103 BLOCKED');
        $this->assertSame(['DOING', 0], [DB::table('tasks')->where('id', $ids[1])->value('status'), (int) DB::table('tasks')->where('id', $ids[1])->value('evidence_required')]);
        $this->assertSame(2, DB::table('task_checklist')->where('task_id', $ids[0])->count());
        $this->assertSame(0, DB::table('tm_messages')->whereIn('task_id', $ids)->where('rule_code', 'N1')->count(), 'quiet import by default');
        $again = TaskImport::run($mgr, 'tasks.csv', $csv, true);
        $this->assertFalse($again['ok']); $this->assertSame(0, $again['imported']);
        $this->assertSame(['already imported (ref csv:R1)', 'already imported (ref csv:R2)', 'already imported (ref csv:R3)'], array_column($again['rows'], 'text'));
        // all-or-nothing on the server, boss only, required columns, row limit, undo by batch
        $bad = TaskImport::run($mgr, 'x.csv', "title,category,owner,due_at\nGood one,Stock,$who,$d\nBad one,Nope,$who,$d\n", true);
        $this->assertFalse($bad['ok']); $this->assertFalse(DB::table('tasks')->where('title', 'Good one')->exists(), 'nothing saved when one row is wrong');
        foreach ([[$va, 'tasks.csv', $csv, 'Refused: only a Partner or Manager can import tasks.'], [$mgr, 'tasks.csv', "title,owner,due_at\nx,y,z", 'Column "category" is missing. Download the template to see the format.'],
            [$mgr, 'tasks.txt', $csv, 'The file must be a .csv file.'], [$mgr, 'big.csv', "title,category,owner,due_at\n" . str_repeat("a,Stock,b,2030-01-01\n", 1001), 'More than 1000 rows - split the file.']] as [$u, $n, $b, $msg])
            { try { TaskImport::run($u, $n, $b, false); $this->fail($msg); } catch (\InvalidArgumentException $e) { $this->assertSame($msg, $e->getMessage()); } }
        $u = TaskImport::undo($r['batch'], $mgr);
        $this->assertSame(3, $u['cancelled']); $this->assertSame('import undone', DB::table('task_history')->where('task_id', $ids[0])->where('new_value', 'CANCELLED')->value('reason'));
        $this->assertStringStartsWith("ref,title,category,owner,due_at", TaskImport::template());
    }

    public function test_r6_proof_switch_and_partner_only_fields(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $p = $this->makeUser('PARTNER');
        $base = ['title' => 'Check shelf', 'owner' => $va->id, 'due' => $this->due()];
        try { TaskCreate::create(1, $base + ['evidence' => 0], $va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner or Manager may switch "evidence required" off.', $e->getMessage()); }
        try { TaskCreate::create(1, $base + ['evidence' => 0], $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: give a reason for switching evidence off.', $e->getMessage()); }
        $id = TaskCreate::create(1, $base + ['evidence' => 0, 'evidence_reason' => 'internal check'], $mgr)['id'];
        $h = DB::table('task_history')->where('task_id', $id)->where('field', 'evidence_required')->first();
        $this->assertSame([$mgr->id, 'switched off at create by MANAGER: internal check'], [(int) $h->user_id, $h->reason]);
        $this->assertSame(1, (int) DB::table('tasks')->where('id', TaskCreate::create(1, $base, $mgr)['id'])->value('evidence_required'), 'proof ON by default');
        try { TaskCreate::create(1, $base + ['amount' => 50], $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner may enter an amount.', $e->getMessage()); }
        try { TaskCreate::create(1, $base + ['confidential' => 1], $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner may mark a task confidential.', $e->getMessage()); }
        $pid = TaskCreate::create(1, $base + ['amount' => 50, 'confidential' => 1], $p)['id'];
        $this->assertSame([50.0, 1], [(float) DB::table('tasks')->where('id', $pid)->value('amount_gbp'), (int) DB::table('tasks')->where('id', $pid)->value('is_confidential')]);
        // later: off with a reason (boss), back on without one
        $t = TaskCreate::create(1, $base, $mgr)['id'];
        try { TaskActions::proofOff($t, $va, 'x'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Refused: only a Partner or Manager', $e->getMessage()); }
        TaskActions::proofOff($t, $mgr, 'photo not possible'); $this->assertSame(0, (int) DB::table('tasks')->where('id', $t)->value('evidence_required'));
        TaskActions::proofOn($t, $mgr); $this->assertSame(1, (int) DB::table('tasks')->where('id', $t)->value('evidence_required'));
    }

    public function test_r41_owner_on_phone_sees_seen_and_start_then_camera_and_done(): void
    {
        Storage::fake('local');
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $id = TaskCreate::create(1, ['title' => 'Photo of bay 3', 'owner' => $va->id, 'due' => $this->due(), 'checklist' => "Clean\nPhoto"], $mgr)['id'];
        $p = TaskActions::page($id, $va);
        $this->assertTrue($p['can']['ack']); $this->assertContains('DOING', $p['task']['actions'], 'TODO → Seen + Start');
        $this->assertNotContains('DONE', $p['task']['actions']);
        $this->assertSame('Yes', $p['facts']['Proof required']); $this->assertNull($p['panel']['ack']);
        TaskActions::acknowledge($id, $va); TaskFlow::move($id, $va, 'DOING');
        $p = TaskActions::page($id, $va);
        $this->assertFalse($p['can']['ack']); $this->assertTrue($p['can']['work'], 'Camera shown while DOING'); $this->assertContains('DONE', $p['task']['actions'], 'Mark done');
        // the tabs work on the web: tick, proof, comment
        foreach (DB::table('task_checklist')->where('task_id', $id)->pluck('id') as $c) TaskActions::tick((int) $c, $va, true);
        TaskActions::addProof($id, $va, UploadedFile::fake()->image('bay3.jpg'), null, null);
        TaskActions::comment($id, $va, 'Bay 3 is clean');
        $this->assertSame([], TaskFlow::missing(DB::table('tasks')->where('id', $id)->first()), 'checklist done + photo = nothing missing');
        $p = TaskActions::page($id, $va);
        $this->assertSame(['PHOTO', 1], [$p['proof'][0]['type'], count($p['comments'])]);
        // banners: blocked, stuck, absent
        $dep = TaskCreate::create(1, ['title' => 'First', 'owner' => $va->id, 'due' => $this->due()], $mgr)['id'];
        $w = TaskCreate::create(1, ['title' => 'Second', 'owner' => $va->id, 'due' => $this->due(), 'depends_on' => $dep], $mgr)['id'];
        $this->assertContains('BLOCKED', array_column(TaskActions::page($w, $mgr)['banners'], 'code'));
        $this->actingAs($va)->getJson("/api/tasks/$id/page")->assertOk()->assertJsonPath('task.ref', TaskFlow::ref($id));
        $this->actingAs($this->makeUser('VA'))->getJson("/api/tasks/$id/page")->assertStatus(404);
    }
}
