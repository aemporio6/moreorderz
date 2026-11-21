<?php

namespace Tests\Feature;

use App\Services\Connect\Connections;
use App\Services\RecordService;
use App\Services\TaskFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RULES_G_CORE R-G_CORE-52 WhatsApp inbound commands, on the one task state table R-G_CORE-1 and the Done gate R-G_CORE-5.
 */
class WaCommandsTest extends TestCase
{
    private int $n = 0;
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function send(string $from, array $msg): void
    {
        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [$msg + ['from' => $from, 'id' => 'wamid.IN' . (++$this->n)]]]]]]]]);
        $this->call('POST', '/wa/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'appsec')], $body)->assertOk();
    }
    private function last(): object { return DB::table('wa_inbound')->orderByDesc('id')->first(); }
    private function reply(): string { return (string) DB::table('tm_messages')->where('template_key', 'FREE_TEXT')->orderByDesc('id')->value('text'); }      // replies go through the one queue (R-G_CORE-46)
    private function person(string $role, string $phone): \App\Models\User
    {
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'W' . random_int(1000, 9999), 'first_name' => $role, 'f_active' => 1, 'whatsapp_number' => $phone]);
        $u = $this->makeUser($role); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $acc, 'display_name' => "$role 1"]); return $u->fresh();
    }

    public function test_the_rule_example_done_needs_proof_photo_then_done_waits_approval_and_approve_4(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner);
        Connections::save(['adapter' => 'WHATSAPP_CLOUD', 'secrets' => ['phone_number_id' => '1055', 'access_token' => 'tok', 'app_secret' => 'appsec', 'verify_token' => 'vt']]);
        Http::fake(['graph.facebook.com/v21.0/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/1', 'mime_type' => 'image/jpeg', 'file_size' => 5]),
            'graph.facebook.com/v21.0/MEDIA2' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/2', 'mime_type' => 'application/x-msdownload', 'file_size' => 5]),
            'lookaside.fbsbx.com/*' => Http::response('JPEG!', 200, ['Content-Type' => 'image/jpeg'])]);
        $owner = $this->person('VA', '+44 7700-900 111'); $boss = $this->person('MANAGER', '447700900222');
        $task = $this->f('tasks', ['fk_id_ent' => 1, 'title' => 'Photograph the shelf', 'owner_user_id' => $owner->id, 'assigned_by_user_id' => $boss->id, 'status' => 'TODO', 'source' => 'MANUAL', 'priority' => 'NORMAL', 'evidence_required' => 1, 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('+2 days'))]);
        $ref = TaskFlow::ref($task);
        DB::table('wa_outbound')->insert(['fk_id_ent' => 1, 'fk_id_account' => $owner->fk_id_account, 'phone' => '447700900111', 'template_code' => 'TASK_NEW', 'body' => 'x', 'link_table' => 'tasks', 'link_id' => $task, 'status' => 'SENT', 'provider_msg_id' => 'wamid.TASK']);

        // unknown number → IGNORED, no reply
        $this->send('449999999999', ['type' => 'text', 'text' => ['body' => 'DONE']]);
        $this->assertSame(['IGNORED', 'IGNORED', 'Unknown number - ignored (R13)'], [$this->last()->action, $this->last()->status, $this->last()->note]);
        $this->assertSame(0, DB::table('tm_messages')->where('template_key', 'FREE_TEXT')->count());
        // no task named
        $this->send('447700900111', ['type' => 'text', 'text' => ['body' => 'DONE']]);
        $this->assertSame('NO_TASK', $this->last()->action); $this->assertStringStartsWith('Which task? Reply to the task message', $this->reply());

        // owner replies DONE with no proof → refused, the TODO task is not moved
        $this->send('447700900111', ['type' => 'text', 'text' => ['body' => 'done'], 'context' => ['id' => 'wamid.TASK']]);
        $this->assertSame(['REFUSED_DONE', 'RECEIVED'], [$this->last()->action, $this->last()->status]);
        $this->assertSame('Not marked done: attach a photo or file first (send it here as a reply to the task message).', $this->reply());
        $this->assertSame('TODO', DB::table('tasks')->where('id', $task)->value('status'));
        // a file type that is not accepted → MEDIA_FAILED
        $this->send('447700900111', ['type' => 'document', 'document' => ['id' => 'MEDIA2', 'filename' => 'x.exe'], 'context' => ['id' => 'wamid.TASK']]);
        $this->assertSame('MEDIA_FAILED', $this->last()->action);
        // a photo → proof; then "T-00nn DONE" in the text → Waiting approval (TODO started first), not APPROVED
        $this->send('447700900111', ['type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg'], 'context' => ['id' => 'wamid.TASK']]);
        $this->assertSame('EVIDENCE_PHOTO', $this->last()->action); $this->assertSame("Photo saved as proof on $ref. Reply DONE when the task is finished.", $this->reply());
        $ev = DB::table('task_evidence')->where('task_id', $task)->first(); $this->assertSame(['PHOTO', 'WHATSAPP'], [$ev->type, $ev->via]); Storage::disk('local')->assertExists($ev->file_path);
        $this->send('447700900111', ['type' => 'text', 'text' => ['body' => "$ref DONE"]]);
        $this->assertSame(['DONE', 'ACTED'], [$this->last()->action, $this->last()->status]);
        $this->assertSame("$ref marked done - waiting for approval by MANAGER 1.", $this->reply());
        $this->assertSame('DONE', DB::table('tasks')->where('id', $task)->value('status'));
        $this->assertSame(['DOING', 'DONE'], DB::table('task_history')->where('task_id', $task)->where('field', 'status')->orderBy('id')->pluck('new_value')->all(), 'history shows the start and the done');

        // the owner cannot approve his own task; the approver replies "APPROVE 4" → APPROVED quality EXCELLENT
        $this->send('447700900111', ['type' => 'text', 'text' => ['body' => "$ref APPROVE 4"]]);
        $this->assertSame('REFUSED', $this->last()->action);
        $this->send('447700900222', ['type' => 'text', 'text' => ['body' => "$ref approve 4"]]);
        $this->assertSame(['APPROVE', "$ref approved (EXCELLENT)."], [$this->last()->action, $this->reply()]);
        $this->assertSame(['APPROVED', 'EXCELLENT'], array_values((array) DB::table('tasks')->where('id', $task)->first(['status', 'quality'])));
    }

    public function test_stuck_comment_not_found_ok_ambiguous_and_approval_ok(): void
    {
        $this->actingAs($this->owner);
        Connections::save(['adapter' => 'WHATSAPP_CLOUD', 'secrets' => ['phone_number_id' => '1055', 'access_token' => 'tok', 'app_secret' => 'appsec', 'verify_token' => 'vt']]);
        $va = $this->person('VA', '447700900333'); $other = $this->person('VA', '447700900444');
        $mk = fn (int $owner, string $title) => $this->f('tasks', ['fk_id_ent' => 1, 'title' => $title, 'owner_user_id' => $owner, 'status' => 'TODO', 'source' => 'MANUAL', 'priority' => 'NORMAL', 'created_at' => now(), 'due_at' => date('Y-m-d')]);
        $t1 = $mk($va->id, 'Ring the supplier'); $t2 = $mk($va->id, 'Check the parcel'); $theirs = $mk($other->id, 'Not yours');
        foreach ([$t1, $t2] as $i => $t) DB::table('tm_messages')->insert(['fk_id_ent' => 1, 'fk_id_account' => $va->fk_id_account, 'user_id' => $va->id, 'task_id' => $t, 'channel' => 'WHATSAPP', 'direction' => 'OUT', 'to_address' => '447700900333', 'template_key' => 'tm_new_task', 'text' => 'x', 'event_key' => "N1:$t", 'status' => 'SENT', 'provider_id' => "wamid.T$i", 'retry_count' => 0, 'created_at' => now()]);      // task alerts in the one queue

        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => 'STUCK'], 'context' => ['id' => 'wamid.T0']]);
        $this->assertSame('Say who or what it is stuck on, e.g. "STUCK waiting for supplier PI".', $this->reply());
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => 'STUCK waiting for supplier PI'], 'context' => ['id' => 'wamid.T0']]);
        $t = DB::table('tasks')->where('id', $t1)->first(); $this->assertSame(['STUCK', 'OTHER', 'waiting for supplier PI'], [$t->status, $t->stuck_on_type, $t->stuck_on_text]);
        $this->assertSame('STUCK: waiting for supplier PI', DB::table('task_comments')->where('task_id', $t1)->orderByDesc('id')->value('text'));
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => 'The shop opens at 10'], 'context' => ['id' => 'wamid.T1']]);
        $this->assertSame(['COMMENT', 'Comment added to ' . TaskFlow::ref($t2) . '.'], [$this->last()->action, $this->reply()]);
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => TaskFlow::ref($theirs) . ' START']]);
        $this->assertSame(['NOT_FOUND', 'Task ' . TaskFlow::ref($theirs) . ' not found for you.'], [$this->last()->action, $this->reply()]);
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => 'start'], 'context' => ['id' => 'wamid.T1']]);
        $this->assertSame(['DOING', TaskFlow::ref($t2) . ' is now In progress.'], [DB::table('tasks')->where('id', $t2)->value('status'), $this->reply()]);

        // an OK that could mean two tasks → numbered question, nothing done until the number
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => 'OK']]);
        $this->assertSame('CHOOSE', $this->last()->action); $this->assertStringStartsWith('Which one? 1 ' . TaskFlow::ref($t1), $this->reply()); $this->assertStringEndsWith('Reply 1 or 2.', $this->reply());
        $this->assertNull(DB::table('tasks')->where('id', $t1)->value('acknowledged_at'));
        $this->send('447700900333', ['type' => 'text', 'text' => ['body' => '2']]);
        $this->assertSame('ACK', $this->last()->action); $this->assertNotNull(DB::table('tasks')->where('id', $t2)->value('acknowledged_at')); $this->assertNull(DB::table('tasks')->where('id', $t1)->value('acknowledged_at'));

        // OK to an approval sent to this number (the manager) → decided with the web rules
        $mgr = $this->person('MANAGER', '447700900555');
        $ap = $this->f('sys_approvals', ['fk_id_ent' => 1, 'action_code' => 'TEST_WA', 'entity' => 'x', 'entity_id' => 1, 'amount_gbp' => 50, 'requested_by' => $va->id, 'requested_at' => now(), 'status' => 'PENDING']);
        \App\Services\Connect\Messenger::queueWa(1, $mgr->fk_id_account, '447700900555', 'APPROVAL', [], 'sys_approvals', $ap); DB::table('tm_messages')->where('link_table', 'sys_approvals')->where('link_id', $ap)->update(['status' => 'SENT', 'provider_id' => 'wamid.AP']);      // through the one door
        $this->send('447700900555', ['type' => 'text', 'text' => ['body' => 'NO'], 'context' => ['id' => 'wamid.AP']]);
        $this->assertSame('REFUSED', $this->last()->action, 'NO needs a reason, as on the web'); $this->assertSame('PENDING', DB::table('sys_approvals')->where('id', $ap)->value('status'));
        $this->send('447700900555', ['type' => 'text', 'text' => ['body' => 'OK']]);
        $this->assertSame(['APPROVAL_OK', 'APPROVED'], [$this->last()->action, DB::table('sys_approvals')->where('id', $ap)->value('status')]);
        $this->assertSame($mgr->id, (int) DB::table('sys_approvals')->where('id', $ap)->value('decided_by'));
        // a webhook sent twice is read once
        $before = DB::table('wa_inbound')->count(); $this->n--; $this->send('447700900555', ['type' => 'text', 'text' => ['body' => 'OK']]); $this->assertSame($before, DB::table('wa_inbound')->count());
    }

    public function test_status_machine_and_done_gate_on_the_web(): void
    {
        $this->actingAs($this->owner);
        $va = $this->makeUser('VA');
        $t = $this->f('tasks', ['fk_id_ent' => 1, 'title' => 'T-gate', 'owner_user_id' => $va->id, 'status' => 'TODO', 'source' => 'MANUAL', 'priority' => 'NORMAL', 'evidence_required' => 1, 'created_at' => now(), 'due_at' => date('Y-m-d')]);
        foreach ([1, 0, 0] as $i => $done) $this->f('task_checklist', ['task_id' => $t, 'text' => "Item $i", 'is_done' => $done, 'sort' => $i]);
        DB::table('task_evidence')->insert(['task_id' => $t, 'type' => 'VOICE', 'uploaded_by' => $va->id, 'via' => 'APP', 'created_at' => now()]);
        $this->actingAs($va)->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertStatus(422)->assertJsonPath('message', 'Refused: a TODO task cannot be marked done - start it first.');
        $this->postJson("/api/tasks/$t/status", ['status' => 'DOING'])->assertOk()->assertJsonPath('label', 'In progress');
        $this->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertStatus(422)->assertJsonPath('message', 'Refused: 2 checklist items are not ticked yet.');
        DB::table('task_checklist')->where('task_id', $t)->update(['is_done' => 1]);
        $this->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertStatus(422)->assertJsonPath('message', 'Refused: a voice note is not evidence. Attach a screenshot, photo, file, link or reference first.');
        $row = collect($this->getJson('/api/tasks')->json('tasks'))->firstWhere('id', $t);
        $this->assertSame(['STUCK', 'DONE'], $row['actions']); $this->assertCount(1, $row['missing'], 'the Done button shows what is missing');
        DB::table('task_evidence')->insert(['task_id' => $t, 'type' => 'PHOTO', 'uploaded_by' => $va->id, 'via' => 'APP', 'created_at' => now()]);
        $this->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertOk()->assertJsonPath('label', 'Waiting approval');
        $this->postJson("/api/tasks/$t/status", ['status' => 'DOING'])->assertStatus(422)->assertJsonPath('message', 'Refused: a DONE task cannot be started.');
        $this->postJson("/api/tasks/$t/status", ['status' => 'CANCELLED', 'note' => 'not needed'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/api/tasks/$t/status", ['status' => 'CANCELLED', 'note' => 'not needed'])->assertOk()->assertJsonPath('label', 'Cancelled');
        $this->assertSame(1, DB::table('tasks')->where('id', $t)->count(), 'tasks are never deleted');
    }
}
