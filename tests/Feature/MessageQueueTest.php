<?php

namespace Tests\Feature;

use App\Services\AlertRules;
use App\Services\MessageLog;
use App\Services\RecordService;
use App\Services\TmQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-46 one queue (never twice, claim, retry, forward-only), R-G_CORE-53 providers + template register, R-G_CORE-54 message log – spec examples. */
class MessageQueueTest extends TestCase
{
    private function person(string $role, array $acc = []): object
    {
        $u = $this->makeUser($role);
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', $acc + ['fk_id_ent' => 1, 'account_code' => 'MQ' . random_int(10000, 99999), 'first_name' => $role . 'Q', 'f_active' => 1, 'wa_enabled' => 1, 'email_enabled' => 1, 'whatsapp_number' => '+92300' . random_int(1000000, 9999999)]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a]); $u->fk_id_account = $a;
        return $u;
    }
    private function task(int $owner, string $title = 'Pay supplier'): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => $title, 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'source' => 'MANUAL', 'owner_user_id' => $owner, 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '']));
    }
    private function connection(string $adapter): int
    {
        DB::table('api_connections')->whereIn('adapter', ['WHATSAPP_CLOUD', 'WHATSAPP_360', 'WHATSAPP_LOG'])->update(['status' => 'OFF']);
        $id = DB::table('api_connections')->insertGetId(RecordService::fillNotNull('api_connections', ['fk_id_ent' => 1, 'adapter' => $adapter, 'provider' => 'WHATSAPP', 'label' => $adapter, 'status' => 'CONNECTED', 'settings_json' => '{}']));
        if ($adapter === 'WHATSAPP_360') \App\Services\Vault::store('api_connections', $id, ['token_ref' => json_encode(['api_key' => 'test-key-not-real'])]);
        return $id;
    }

    public function test_r46_same_event_twice_one_row_retry_then_failed_and_forward_only(): void
    {
        $va = $this->person('VA'); $t = $this->task($va->id);
        AlertRules::send('N5', $va->id, ['first_name' => 'Va', 'task_ref' => 'T-1', 'title' => 'x', 'days' => 1, 'link' => ''], $t, "N5:$t:20261106");
        AlertRules::send('N5', $va->id, ['first_name' => 'Va', 'task_ref' => 'T-1', 'title' => 'x', 'days' => 1, 'link' => ''], $t, "N5:$t:20261106");
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'N5')->where('channel', 'WHATSAPP')->count(), 'the same N5 event twice → one row');
        DB::table('wa_inbound')->insert(RecordService::fillNotNull('wa_inbound', ['fk_id_ent' => 1, 'provider_msg_id' => 'in-' . uniqid(), 'phone' => '92300', 'fk_id_account' => $va->fk_id_account, 'body' => 'hi', 'status' => 'NEW', 'received_at' => now()]));      // inside the 24-hour window
        $this->connection('WHATSAPP_360');
        Http::fake(['waba-v2.360dialog.io/*' => Http::response(['error' => ['message' => 'Service down']], 503)]);
        $id = (int) DB::table('tm_messages')->where('task_id', $t)->where('channel', 'WHATSAPP')->value('id');
        $now = new \DateTimeImmutable();
        for ($i = 1; $i <= 4; $i++) { TmQueue::dispatch(50, $now); $now = $now->modify('+11 minutes'); }
        $m = DB::table('tm_messages')->where('id', $id)->first();
        $this->assertSame(['FAILED', 4], [$m->status, (int) $m->retry_count], 'provider down → FAILED after the 3rd retry');
        $this->assertNotSame('', (string) $m->error);
        // forward only: READ then a late DELIVERED is ignored
        DB::table('tm_messages')->where('id', $id)->update(['status' => 'SENT', 'provider_id' => 'wamid.X1']);
        $this->assertTrue(TmQueue::receipt('wamid.X1', 'READ'));
        $this->assertFalse(TmQueue::receipt('wamid.X1', 'DELIVERED'));
        $this->assertSame('READ', DB::table('tm_messages')->where('id', $id)->value('status'));
        // claim: a row another run already took (SENDING) is not sent again
        DB::table('tm_messages')->where('id', $id)->update(['status' => 'SENDING']);
        $this->assertSame(0, TmQueue::dispatch(50)['sent']);
    }

    public function test_r53_log_provider_and_templates_outside_the_window(): void
    {
        $va = $this->person('VA'); $t = $this->task($va->id);
        $this->connection('WHATSAPP_LOG');
        AlertRules::send('N5', $va->id, ['first_name' => 'Va', 'task_ref' => 'T-1', 'title' => 'x', 'days' => 1, 'link' => ''], $t, "N5:$t:a");
        $tpl = DB::table('wa_templates')->where('code', 'tm_late')->first(); $this->assertNotNull($tpl, 'task keys seeded in the register');
        TmQueue::dispatch();
        $m = DB::table('tm_messages')->where('task_id', $t)->where('channel', 'WHATSAPP')->first();
        $this->assertSame(['FAILED', 'Outside the 24-hour window – template needed'], [$m->status, $m->error], 'no approved template outside the window');
        DB::table('wa_templates')->where('code', 'tm_late')->update(['approval_status' => 'APPROVED']);
        AlertRules::send('N5', $va->id, ['first_name' => 'Va', 'task_ref' => 'T-1', 'title' => 'x', 'days' => 1, 'link' => ''], $t, "N5:$t:b");
        Http::fake(); TmQueue::dispatch();
        $m = DB::table('tm_messages')->where('task_id', $t)->where('channel', 'WHATSAPP')->orderByDesc('id')->first();
        $this->assertSame(['SENT', 'log provider'], [$m->status, $m->note], 'provider = log → SENT with note "log provider"');
        Http::assertNothingSent();
        // the register checks its ordered params
        $d = ['params_json' => 'first_name, task_ref']; $this->assertSame('Parameters must be a list of names, e.g. ["first_name","task_ref"].', \App\Services\FormRules::check('wa_template', $d, [], (int) $tpl->id)['params_json'] ?? null);
    }

    public function test_r54_log_masks_amounts_for_a_va_and_filters(): void
    {
        $partner = $this->person('PARTNER'); $va = $this->person('VA'); $t = $this->task($va->id);
        DB::table('tm_messages')->insert(['user_id' => $va->id, 'task_id' => $t, 'rule_code' => 'MANUAL', 'channel' => 'INAPP', 'direction' => 'OUT', 'template_key' => 'tm_reminder', 'event_key' => 'X:' . uniqid(), 'text' => 'Amount £120 is due, see https://example.com/pay', 'status' => 'SENT', 'created_at' => now()]);
        $row = MessageLog::forTask($t, $va)['rows'][0];
        $this->assertSame('Amount £••• is due, see', $row['text'], 'VA sees £•••, links removed');
        $this->assertSame('Amount £120 is due, see', MessageLog::rows($partner, ['task' => $t])['rows'][0]['text']);
        $this->actingAs($va)->getJson('/api/messages/log')->assertStatus(403);
        $r = $this->actingAs($partner)->getJson('/api/messages/log?channel=INAPP&person=' . $va->id)->assertOk()->json();
        $this->assertSame(25, $r['per']); $this->assertSame('In-app', $r['rows'][0]['channel']);
        $this->assertSame(10, $this->actingAs($va)->getJson("/api/tasks/$t/messages")->assertOk()->json('per'));
        $this->assertStringContainsString('Time,Direction,Channel', $this->actingAs($partner)->get('/api/messages/log.csv')->assertOk()->getContent());
    }

    public function test_r46_every_engine_goes_through_the_one_door(): void
    {
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'OD' . random_int(10000, 99999), 'first_name' => 'Door', 'f_active' => 1,
            'whatsapp_number' => '+44770' . random_int(1000000, 9999999), 'timezone' => 'UTC', 'quiet_from' => '00:00', 'quiet_to' => '23:59']));      // quiet almost all day
        $w = \App\Services\Connect\Messenger::queueWa(1, $acc, '447700900999', 'CAPITAL_CALL', ['name' => 'Door', 'amount' => '£500.00', 'due' => '01 Dec', 'link' => 'l'], 'fin_capital_calls', 7, 'CC:7:' . $acc);
        $again = \App\Services\Connect\Messenger::queueWa(1, $acc, '447700900999', 'CAPITAL_CALL', ['name' => 'Door', 'amount' => '£500.00', 'due' => '01 Dec', 'link' => 'l'], 'fin_capital_calls', 7, 'CC:7:' . $acc);
        $this->assertSame($w, $again, 'the same event key → one row');
        $m = DB::table('tm_messages')->find($w);
        $this->assertSame(['WHATSAPP', 'QUEUED', 'fin_capital_calls', 7, $acc], [$m->channel, $m->status, $m->link_table, (int) $m->link_id, (int) $m->fk_id_account]);
        $this->assertNotNull($m->send_after, 'the person\'s quiet hours hold an engine message too');
        $r = \App\Services\Connect\Messenger::queueText(1, $acc, '447700900999', 'Thanks');
        $this->assertNull(DB::table('tm_messages')->where('id', $r)->value('send_after'), 'a reply is never held');
        $this->connection('WHATSAPP_LOG'); TmQueue::dispatch();
        $this->assertSame(['FAILED', 'Outside the 24-hour window – template needed'], [DB::table('tm_messages')->where('id', $r)->value('status'), DB::table('tm_messages')->where('id', $r)->value('error')], 'free text only inside the window');
        $n = \App\Services\Connect\Messenger::notSent(1, $acc, null, 'EMAIL', 'STATEMENT', 'Your statement', 'No email on file');
        $this->assertSame(['FAILED', 'No email on file'], [DB::table('tm_messages')->where('id', $n)->value('status'), DB::table('tm_messages')->where('id', $n)->value('error')]);
        $this->assertSame(0, DB::table('wa_outbound')->count() + DB::table('email_outbound')->count() + DB::table('fin_messages')->count(), 'the old outboxes are not written any more');
    }
}
