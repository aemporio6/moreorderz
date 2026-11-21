<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\TaskEngine;
use App\Services\TaskScore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-7 who may approve, R-G_CORE-8 task score (weights as settings, R-G_CORE-51). */
class TaskScoreTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Photograph stock', 'priority' => 'NORMAL', 'status' => 'DONE', 'created_at' => now(), 'due_at' => '2026-11-03', 'source' => 'MANUAL', 'done_at' => now()]));
    }

    public function test_the_rule_examples_self_approval_refused_and_score_70_frozen(): void
    {
        $partner = $this->makeUser('PARTNER'); $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $other = $this->makeUser('VA');
        // R-G_CORE-7: a Partner who owns the task cannot approve it
        $own = $this->task(['owner_user_id' => $partner->id, 'assigned_by_user_id' => $mgr->id]);
        $this->actingAs($partner)->postJson("/api/tasks/$own/approve", ['quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: nobody can approve their own task - not even a Partner.');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'rework_count' => 1]);
        DB::table('task_evidence')->insert(RecordService::fillNotNull('task_evidence', ['task_id' => $t, 'type' => 'PHOTO', 'file_name' => 'p.jpg', 'created_at' => now()]));
        $this->actingAs($other)->postJson("/api/tasks/$t/approve", ['quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: only the person who assigned it, a Partner or a Manager can approve.');
        $this->actingAs($mgr)->postJson("/api/tasks/$t/approve", [])->assertStatus(422);      // quality required
        // R-G_CORE-8: due 03 Nov, approved 05 Nov as GOOD, proof present, 1 rework → 30 + 20 + 30 − 10 = 70 (amber)
        $p = TaskScore::approve($t, $mgr, 'GOOD', '2026-11-05');
        $this->assertSame([2, 30, 20, 30, -10, 70, 'mid'], [$p['late_days'], $p['on_time'], $p['proof'], $p['quality'], $p['rework'], $p['score'], $p['band']['band']]);
        $row = DB::table('tasks')->where('id', $t)->first();
        $this->assertSame(['APPROVED', 'GOOD', 70.0, $mgr->id], [$row->status, $row->quality, (float) $row->score, (int) $row->approved_by_user_id]);
        $this->assertSame('on time 30 + evidence 20 + quality 30 - rework 10', DB::table('task_history')->where('task_id', $t)->where('field', 'score')->value('reason'));
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'APPROVED')->where('user_id', $va->id)->where('channel', 'INAPP')->count(), 'owner gets tm_approved');
        // changing the weights later leaves 70 unchanged
        \App\Services\AlertRules::setThreshold($this->owner, 'score_q_good', '35');
        $this->assertSame(70.0, (float) DB::table('task_scores')->where('task_id', $t)->value('score'));
        $this->actingAs($mgr)->postJson("/api/tasks/$t/approve", ['quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: only a DONE task can be approved (this one is APPROVED).');
    }

    public function test_assigner_delegate_rework_voice_no_proof_system_score_and_person_score(): void
    {
        $tl = $this->makeUser('TL'); $va = $this->makeUser('VA'); $del = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $tl->id, 'due_at' => date('Y-m-d', strtotime('+2 day'))]);
        DB::table('task_evidence')->insert(RecordService::fillNotNull('task_evidence', ['task_id' => $t, 'type' => 'VOICE', 'file_name' => 'v.ogg', 'created_at' => now()]));
        $this->assertSame(['on_time' => 40, 'proof' => 0], array_intersect_key(TaskScore::parts(DB::table('tasks')->where('id', $t)->first(), 'GOOD'), array_flip(['on_time', 'proof'])), 'a voice note is not proof');
        $this->actingAs($del)->postJson("/api/tasks/$t/rework", ['reason' => 'Photo is blurry'])->assertStatus(422);
        DB::table('tm_delegations')->insert(['from_user_id' => $tl->id, 'to_user_id' => $del->id, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+1 day')), 'rights' => 'APPROVE', 'created_at' => now()]);
        $this->actingAs($del)->postJson("/api/tasks/$t/rework", ['reason' => 'Photo is blurry'])->assertOk();
        $this->assertSame(['REWORK', 1], [DB::table('tasks')->where('id', $t)->value('status'), (int) DB::table('tasks')->where('id', $t)->value('rework_count')]);
        DB::table('tasks')->where('id', $t)->update(['status' => 'DONE']);
        $this->actingAs($tl)->postJson("/api/tasks/$t/approve", ['quality' => 4])->assertOk()->assertJsonPath('score', 70);      // 40 + 0 + 40 − 10
        $d = $this->actingAs($va)->getJson('/api/tasks')->assertOk()->json('my_score');
        $this->assertSame([70, 'On time 1 of 1 · Proof 0 of 1 · Rework 1'], [$d['score'], $d['line']]);
        // a rule task closed by the system is scored as GOOD
        Context::set(1, $this->owner->id);
        TaskEngine::open('SAL_UNMATCHED', [991, '2026-11'], 'Unmatched lines', ['ent' => 1]);
        $rt = (int) DB::table('tasks')->where('dedupe_key', 'SAL_UNMATCHED:991:2026-11')->value('id');
        $this->actingAs($this->owner)->postJson("/api/tasks/$rt/approve", ['quality' => 'GOOD'])->assertStatus(422)->assertJsonPath('message', 'Refused: problem still present: Unmatched lines');
        TaskEngine::close('SAL_UNMATCHED', [991, '2026-11']);
        $this->assertSame('GOOD', DB::table('tasks')->where('id', $rt)->value('quality'));
        $this->assertNotNull(DB::table('task_scores')->where('task_id', $rt)->value('score'));
    }
}
