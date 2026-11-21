<?php

namespace Tests\Feature;

use App\Services\AlertRules;
use App\Services\PersonChannels;
use App\Services\RecordService;
use App\Services\TaskCreate;
use App\Services\TaskDigest;
use App\Services\TaskFlow;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-3 priority + N1 through quiet hours, R-G_CORE-45 daily digest, R-G_CORE-47 quiet hours, R-G_CORE-48 channels per person – the spec examples. */
class TaskChannelsTest extends TestCase
{
    /** A login with its own person row (time zone, quiet hours, digest time). */
    private function person(string $role, array $acc = []): object
    {
        $u = $this->makeUser($role);
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', $acc + ['fk_id_ent' => 1, 'account_code' => 'CH' . random_int(10000, 99999), 'first_name' => $role . 'X', 'f_active' => 1, 'wa_enabled' => 1, 'email_enabled' => 1, 'weekly_task_limit' => 6]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a]); $u->fk_id_account = $a;
        return $u;
    }
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Check stock', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '']));
    }

    public function test_r48_channels_per_person_spec_example(): void
    {
        $mgr = $this->person('MANAGER'); $b = $this->person('VA', ['whatsapp_number' => '+923001234567']);
        $old = (array) DB::table('t_account')->where('id', $b->fk_id_account)->first();
        $this->actingAs($mgr);
        $d = ['whatsapp_number' => '0044 7700 900123']; $this->assertSame('Refused: WhatsApp number must be in international form, e.g. +447700900123 or +923001234567.', PersonChannels::check($d, $old, (int) $b->fk_id_account)['whatsapp_number']);
        $d = ['whatsapp_number' => '+44 7700-900123']; $this->assertSame([], PersonChannels::check($d, $old, (int) $b->fk_id_account)); $this->assertSame('+447700900123', $d['whatsapp_number']);
        $d = ['wa_enabled' => 0]; $this->assertSame('Refused: only a Partner can switch a channel on or off for someone.', PersonChannels::check($d, $old, (int) $b->fk_id_account)['wa_enabled']);
        $d = ['quiet_from' => '22:00']; $this->assertSame('Refused: give both quiet-hours times, or neither.', PersonChannels::check($d, $old, (int) $b->fk_id_account)['quiet_to']);
        $d = ['quiet_from' => '10pm', 'quiet_to' => '08:00']; $this->assertSame('Refused: times must look like 22:00.', PersonChannels::check($d, $old, (int) $b->fk_id_account)['quiet_from']);
        $d = ['weekly_task_limit' => 120]; $this->assertSame('Weekly task limit is a whole number from 1 to 99.', PersonChannels::check($d, $old, (int) $b->fk_id_account)['weekly_task_limit']);
        $this->actingAs($b);      // a VA: his own times yes, other people's no
        $d = ['quiet_from' => '22:00', 'quiet_to' => '08:00']; $this->assertSame([], PersonChannels::check($d, $old, (int) $b->fk_id_account));
        $d = ['quiet_from' => '22:00', 'quiet_to' => '08:00']; $this->assertSame('Refused: only a Partner or Manager can edit channels.', PersonChannels::check($d, (array) DB::table('t_account')->where('id', $mgr->fk_id_account)->first(), (int) $mgr->fk_id_account)['_form']);
        $this->assertSame('+44 7•• ••• 123', PersonChannels::mask('+447700900123'));
        $page = json_encode($this->actingAs($mgr)->getJson('/api/page/party/' . $b->fk_id_account)->assertOk()->json(), JSON_UNESCAPED_UNICODE);      // record page: masked to others
        $this->assertStringContainsString('+92 3•• ••• 567', $page); $this->assertStringNotContainsString('923001234567', $page);
    }

    public function test_r47_quiet_hours_and_r3_urgent_n1_goes_through(): void
    {
        $mgr = $this->person('MANAGER'); $va = $this->person('VA', ['whatsapp_number' => '+923001234567', 'timezone' => 'Asia/Karachi', 'quiet_from' => '22:00', 'quiet_to' => '08:00']);
        $acc = PersonChannels::account($va->id);
        // 23:30 PKT = 18:30 UTC → send_after 08:00 PKT next day = 03:00 UTC
        $this->assertSame(['2026-11-07 03:00:00', 'after 08:00 (quiet hours)'], PersonChannels::quietUntil($acc, new \DateTimeImmutable('2026-11-06 18:30:00', new \DateTimeZone('UTC'))));
        $this->assertNull(PersonChannels::quietUntil($acc, new \DateTimeImmutable('2026-11-06 06:00:00', new \DateTimeZone('UTC'))), '11:00 PKT is not quiet');
        // R-G_CORE-3: the four priorities are kept; the Notify level is derived
        $this->assertSame(['URGENT', 'NORMAL', 'NORMAL', 'NORMAL'], array_map([TaskFlow::class, 'level'], ['URGENT', 'HIGH', 'NORMAL', 'LOW']));
        // now: quiet hours around the current Karachi time, so a NORMAL task waits and an URGENT one goes at once
        $pk = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Karachi'));
        DB::table('t_account')->where('id', $va->fk_id_account)->update(['quiet_from' => $pk->modify('-1 hour')->format('H:i'), 'quiet_to' => $pk->modify('+1 hour')->format('H:i')]);
        $end = PersonChannels::quietUntil(PersonChannels::account($va->id)); $this->assertNotNull($end);
        $u = TaskCreate::create(1, ['title' => 'Customer call back', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'priority' => 'URGENT'], $mgr)['id'];
        $n = TaskCreate::create(1, ['title' => 'Tidy shelf', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'priority' => 'NORMAL'], $mgr)['id'];
        $wa = fn ($t) => DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'N1')->where('channel', 'WHATSAPP')->first();
        $this->assertSame(['QUEUED', null], [$wa($u)->status, $wa($u)->send_after], 'URGENT N1 at once');
        $this->assertSame($end, [(string) $wa($n)->send_after, $wa($n)->note], 'NORMAL waits until the end of quiet hours');
        $this->assertNull(DB::table('tm_messages')->where('task_id', $n)->where('rule_code', 'N1')->where('channel', 'INAPP')->value('send_after'), 'the bell is never delayed');
        $this->assertSame('HIGH', DB::table('tasks')->where('id', TaskCreate::create(1, ['title' => 'x', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'priority' => 'HIGH'], $mgr)['id'])->value('priority'));
        // channel switched off → no WhatsApp row (bell still there)
        DB::table('t_account')->where('id', $va->fk_id_account)->update(['wa_enabled' => 0]);
        $o = TaskCreate::create(1, ['title' => 'Off', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'priority' => 'URGENT'], $mgr)['id'];
        $this->assertNull($wa($o)); $this->assertSame(1, DB::table('tm_messages')->where('task_id', $o)->where('channel', 'INAPP')->count());
    }

    public function test_r45_digest_eight_lines_and_sent_at_local_time(): void
    {
        $a = $this->person('VA', ['timezone' => 'Asia/Karachi', 'digest_time' => '08:00']);
        DB::table('t_users')->where('id', $a->id)->update(['display_name' => 'Asad Khan']);
        for ($i = 1; $i <= 10; $i++) $this->task(['owner_user_id' => $a->id, 'title' => "Late job $i", 'due_at' => '2026-11-0' . min(5, $i % 5 + 1) . ' 23:59:59']);
        $this->task(['owner_user_id' => $a->id, 'title' => 'Today job', 'due_at' => '2026-11-06 23:59:59']);
        $txt = TaskDigest::text($a->id, '2026-11-06');
        $this->assertStringStartsWith("Good morning Asad – your tasks for Fri 06 Nov:\nLATE (10)\n- T-", $txt);
        $this->assertStringContainsString("- ... and 2 more\nDUE TODAY (1)\n- T-", $txt);
        $this->assertSame(8, substr_count(explode("DUE TODAY", $txt)[0], '- T-'));
        $this->assertStringContainsString("\nOpen My Tasks: ", $txt);
        // sent at 08:00 Karachi = 03:00 UTC, not at 02:59, once per local day, catch-up 3 h
        $sent = fn () => DB::table('tm_messages')->where('user_id', $a->id)->where('rule_code', 'N13')->where('channel', 'INAPP')->count();
        TaskDigest::run(new \DateTimeImmutable('2026-11-06 02:59:00', new \DateTimeZone('UTC'))); $this->assertSame(0, $sent());
        TaskDigest::run(new \DateTimeImmutable('2026-11-06 03:00:00', new \DateTimeZone('UTC'))); $this->assertSame(1, $sent());
        TaskDigest::run(new \DateTimeImmutable('2026-11-06 04:00:00', new \DateTimeZone('UTC'))); $this->assertSame(1, $sent(), 'once a day');
        $this->assertSame("N13:{$a->id}:2026-11-06|u{$a->id}", DB::table('tm_messages')->where('user_id', $a->id)->where('rule_code', 'N13')->value('event_key'));
        // nothing due → no digest unless digest_send_empty = 1; preview always shows the text
        $b = $this->person('VA'); DB::table('tm_settings')->where('setting_key', 'digest_send_empty')->update(['setting_value' => '0']);
        $this->assertNull(TaskDigest::text($b->id, '2026-11-06'));
        DB::table('tm_settings')->where('setting_key', 'digest_send_empty')->update(['setting_value' => '1']);
        $this->assertStringContainsString("Nothing due. Well done.", (string) TaskDigest::text($b->id, '2026-11-06'));
        $this->assertStringContainsString('Nothing due. Well done.', TaskDigest::preview($b, $b->id)['text']);
        try { TaskDigest::preview($b, $a->id); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: you can preview only your own digest.', $e->getMessage()); }
        $mgr = $this->person('MANAGER'); $p = TaskDigest::preview($mgr, $a->id); $this->assertNull($p['team']);
        $this->assertStringStartsWith('Team today', (string) TaskDigest::preview($mgr, $mgr->id)['team']);
    }
}
