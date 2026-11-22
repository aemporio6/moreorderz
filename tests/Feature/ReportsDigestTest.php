<?php

namespace Tests\Feature;

use App\Services\AlertRules;
use App\Services\Choices;
use App\Services\RecordService;
use App\Services\TaskDigest;
use App\Services\TaskWeekly;
use App\Services\TmQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-G_CORE-44 weekly report PDF as a WhatsApp document, R-G_CORE-40 monthly leaderboard to Partners, R-G_CORE-45 Owner exceptions digest. */
class ReportsDigestTest extends TestCase
{
    private function person(string $role, string $name): object
    {
        $u = $this->makeUser($role);
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'RD' . random_int(10000, 99999), 'first_name' => $name, 'f_active' => 1, 'wa_enabled' => 1, 'email_enabled' => 1, 'whatsapp_number' => '+92300' . random_int(1000000, 9999999)]));
        DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $a, 'display_name' => $name]); return $u->fresh();
    }
    private function connection(): void
    {
        DB::table('api_connections')->whereIn('adapter', ['WHATSAPP_CLOUD', 'WHATSAPP_360', 'WHATSAPP_LOG'])->update(['status' => 'OFF']);
        DB::table('api_connections')->insert(RecordService::fillNotNull('api_connections', ['fk_id_ent' => 1, 'adapter' => 'WHATSAPP_LOG', 'provider' => 'WHATSAPP', 'label' => 'log', 'status' => 'CONNECTED', 'settings_json' => '{}']));
    }
    private function inWindow(object $u): void
    {
        DB::table('wa_inbound')->insert(RecordService::fillNotNull('wa_inbound', ['fk_id_ent' => 1, 'provider_msg_id' => 'in-' . uniqid(), 'phone' => '92300', 'fk_id_account' => $u->fk_id_account, 'body' => 'hi', 'status' => 'NEW', 'received_at' => now()]));
    }

    public function test_r44_weekly_pdf_goes_as_whatsapp_document_inside_the_window(): void
    {
        $this->assertSame(['EMAIL', 'WHATSAPP', 'INAPP'], json_decode(DB::table('tm_alert_rules')->where('code', 'N14')->value('channels_json'), true));
        Storage::fake('local'); Storage::disk('local')->put('weekly_reports/tasks_2026-W45.pdf', '%PDF-1.4 test');
        $in = $this->person('PARTNER', 'Pia'); $out = $this->person('PARTNER', 'Pete'); $this->inWindow($in); $this->connection();
        foreach ([$in, $out] as $u) {
            $ids = AlertRules::send('N14', $u->id, ['first_name' => $u->display_name, 'week' => '2026-11-02 – 2026-11-08', 'done' => 3, 'late' => 1, 'stuck' => 0, 'link' => ''], null, 'N14:2026-W45');
            DB::table('tm_messages')->whereIn('id', $ids)->where('channel', 'WHATSAPP')->update(['attachment' => 'weekly_reports/tasks_2026-W45.pdf', 'subject' => 'Weekly task report']);
        }
        DB::table('wa_templates')->where('code', 'tm_weekly_report')->update(['approval_status' => 'APPROVED', 'f_active' => 1]);
        Http::fake(); TmQueue::dispatch();
        $note = fn ($u) => DB::table('tm_messages')->where('user_id', $u->id)->where('rule_code', 'N14')->where('channel', 'WHATSAPP')->first();
        $this->assertSame(['SENT', 'log provider · PDF sent as document'], [$note($in)->status, $note($in)->note]);
        $this->assertStringContainsString('PDF not sent – outside the 24-hour window', (string) $note($out)->note . (string) $note($out)->error);
        Http::assertNothingSent();
    }

    public function test_r40_monthly_leaderboard_on_the_first_to_partners_once(): void
    {
        $p = $this->person('PARTNER', 'Paula'); $va = $this->person('VA', 'Vera'); $mgr = $this->person('MANAGER', 'Max');
        DB::table('task_scores')->insert(['task_id' => DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'x', 'owner_user_id' => $va->id, 'category_id' => 1, 'priority' => 'NORMAL', 'status' => 'APPROVED', 'created_at' => now(), 'due_at' => '2026-10-10', 'source' => 'MANUAL']),
            'user_id' => $va->id, 'score' => 84, 'on_time_pts' => 40, 'evidence_pts' => 20, 'quality_pts' => 30, 'rework_pts' => 0, 'closed_at' => '2026-10-10 10:00:00']);
        $tz = new \DateTimeZone('Europe/London');
        $this->assertSame(0, TaskWeekly::runMonthly(new \DateTimeImmutable('2026-11-02 09:30', $tz)), 'not the 1st');
        $this->assertSame(0, TaskWeekly::runMonthly(new \DateTimeImmutable('2026-11-01 08:30', $tz)), 'before the time');
        $this->assertGreaterThanOrEqual(1, TaskWeekly::runMonthly(new \DateTimeImmutable('2026-11-01 09:30', $tz)));
        $this->assertSame(0, TaskWeekly::runMonthly(new \DateTimeImmutable('2026-11-01 09:40', $tz)), 'once (key N15:2026-10)');
        $mail = DB::table('tm_messages')->where('user_id', $p->id)->where('event_key', 'like', 'N15:2026-10|%')->where('channel', 'EMAIL')->first();
        $this->assertStringContainsString('Task leaderboard October 2026', $mail->text); $this->assertStringContainsString('Vera', $mail->text);
        $this->assertFalse(DB::table('tm_messages')->where('user_id', $mgr->id)->where('event_key', 'like', 'N15:%')->exists(), 'to the Partners, not the Managers');
        $this->assertStringContainsString('best Vera (84)', (string) DB::table('tm_messages')->where('user_id', $p->id)->where('event_key', 'like', 'N15:2026-10|%')->where('channel', 'INAPP')->value('text'));
    }

    public function test_r45_owner_exceptions_digest_default_owner_and_choice(): void
    {
        $o = $this->person('OWNER', 'Omar'); $pt = $this->person('PARTNER', 'Pam');
        foreach (range(1, 3) as $i) DB::table('tasks')->insert(['fk_id_ent' => 1, 'title' => 'Stock below minimum', 'rule_code' => 'STOCK_LOW', 'owner_user_id' => $o->id, 'category_id' => 1, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => $i === 1 ? '2026-11-01' : '2026-12-30', 'source' => 'RULE']);
        DB::table('tm_messages')->insert(['user_id' => $pt->id, 'channel' => 'EMAIL', 'direction' => 'OUT', 'event_key' => 'X:' . uniqid(), 'text' => 'x', 'status' => 'FAILED', 'failed_at' => now(), 'created_at' => now(), 'rule_code' => 'N5']);
        $txt = TaskDigest::exceptions($o->id, '2026-11-22');
        $this->assertStringStartsWith('Exceptions today, Omar – Sun 22 Nov:', $txt);
        $this->assertStringContainsString("SYSTEM FOUND (3)\n- 3× Stock below minimum (1 late)", $txt);
        $this->assertStringContainsString('MESSAGES FAILED (24 h) (1)', $txt);
        $this->assertSame(['OWNER'], Choices::list('EXCEPTIONS_DIGEST_ROLES', 1));
        $prev = fn ($u) => TaskDigest::preview($u, $u->id)['exceptions'];
        $this->assertNotNull($prev($o)); $this->assertNull($prev($pt), 'default: Owner only');
        Choices::set('EXCEPTIONS_DIGEST_ROLES', 'OWNER,PARTNER', 1);
        $this->assertNotNull($prev($pt->fresh()), 'the other option: Partners get it too');
        DB::table('t_account')->where('id', $o->fk_id_account)->update(['timezone' => 'Europe/London', 'digest_time' => '08:00:00']);
        TaskDigest::run(new \DateTimeImmutable('2026-11-23 08:20:00', new \DateTimeZone('UTC')));
        $this->assertTrue(DB::table('tm_messages')->where('user_id', $o->id)->where('event_key', 'like', 'N16:' . $o->id . ':2026-11-23|%')->exists(), 'sent with the team digest, key N16:{user}:{date}');
    }
}
