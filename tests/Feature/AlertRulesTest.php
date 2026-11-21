<?php

namespace Tests\Feature;

use App\Services\AlertRules;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-49 alert rules catalogue N1–N14 + MANUAL + APPROVED, and R-G_CORE-51 thresholds with history. */
class AlertRulesTest extends TestCase
{
    private function task(array $f): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $f + ['fk_id_ent' => 1, 'title' => 'Chase supplier PI', 'owner_user_id' => $this->owner->id, 'assigned_by_user_id' => $this->owner->id, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => '2026-11-20', 'source' => 'MANUAL']));
    }

    public function test_catalogue_is_complete_sorted_and_locked_rules_cannot_be_switched_off(): void
    {
        $this->actingAs($this->owner);
        $d = $this->getJson('/api/alerts')->assertOk()->json();
        $this->assertSame(['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7', 'N8', 'N9', 'N10', 'N11', 'N12', 'N13', 'N14', 'MANUAL', 'APPROVED'], array_column($d['rules'], 'code'));
        $n13 = collect($d['rules'])->firstWhere('code', 'N13'); $this->assertSame(['tm_digest', 'tm_team_digest'], array_values(array_unique(array_column($n13['texts'], 'key'))));
        $this->assertTrue($d['can_edit']);
        $this->postJson('/api/alerts/rules/MANUAL', ['is_active' => 0])->assertStatus(422)->assertJsonFragment(['message' => 'Refused: MANUAL is always WhatsApp + in-app and cannot be switched off.']);
        $this->postJson('/api/alerts/rules/APPROVED', ['channels' => ['INAPP']])->assertStatus(422);
        $this->postJson('/api/alerts/rules/N7', ['channels' => ['EMAIL']])->assertOk();
        $this->assertSame(['EMAIL'], json_decode(AlertRules::rule('N7')->channels_json, true));
        $this->postJson('/api/alerts/rules/N7', ['is_active' => 1, 'channels' => []])->assertStatus(422);

        $va = $this->makeUser('VA'); $this->actingAs($va);
        $this->postJson('/api/alerts/rules/N6', ['is_active' => 0])->assertStatus(422)->assertJsonFragment(['message' => 'Refused: only a Partner can change alert rules.']);
        $this->assertFalse($this->getJson('/api/alerts')->json('can_edit'));
    }

    public function test_rule_off_sends_nothing_and_stuck_repeats_on_the_rule_days(): void
    {
        $this->actingAs($this->owner);
        $mgr = $this->makeUser('MANAGER');
        $t = $this->task(['status' => 'STUCK', 'stuck_on_type' => 'OTHER', 'stuck_on_text' => 'supplier PI', 'stuck_since' => '2026-11-01 10:00:00']);
        $this->postJson('/api/alerts/rules/N6', ['is_active' => 0])->assertOk();
        $this->assertSame(0, AlertRules::stuckSweep(new \DateTime('2026-11-06 12:00')));      // stuck 5 days, N6 off → nothing
        $this->assertSame(0, DB::table('tm_messages')->where('task_id', $t)->count());

        $this->postJson('/api/alerts/rules/N6', ['is_active' => 1, 'channels' => ['INAPP']])->assertOk();
        $days = [];
        foreach (range(2, 9) as $d) { $date = sprintf('2026-11-%02d 12:00', $d); if (AlertRules::stuckSweep(new \DateTime($date))) $days[] = $d; AlertRules::stuckSweep(new \DateTime($date)); }
        $this->assertSame([4, 6, 8], $days);      // stuck_alert_days 3, stuck_repeat_days 2 → 04, 06, 08 Nov only; a second run the same day sends nothing
        $msgs = DB::table('tm_messages')->where('task_id', $t)->get();
        $this->assertSame([$mgr->id], $msgs->pluck('user_id')->unique()->values()->all());      // Managers, never the owner
        $this->assertStringContainsString('is stuck on supplier PI for 3 day(s)', $msgs->first()->text);
    }

    public function test_send_channels_blocked_skip_and_texts_per_language(): void
    {
        $this->actingAs($this->owner);
        $dep = $this->task([]); $t = $this->task(['depends_on_task_id' => $dep]);
        $vars = ['first_name' => 'Asha', 'task_ref' => 'T-0012', 'title' => 'Chase PI', 'due' => '22 Nov', 'link' => 'x'];
        $this->assertSame([], AlertRules::send('N3', $this->owner->id, $vars, $t, 'N3:' . $t . ':2026-11-22'));      // blocked task skipped by N3
        $ids = AlertRules::send('N1', $this->owner->id, $vars, $t, 'N1:' . $t . ':' . $this->owner->id);
        $this->assertCount(2, $ids);
        $wa = DB::table('tm_messages')->whereIn('id', $ids)->where('channel', 'WHATSAPP')->first();
        $this->assertSame(['FAILED', 'No WhatsApp number – add it on Alerts & Messages'], [$wa->status, $wa->error]);
        $this->assertSame('Hi Asha, new task T-0012: Chase PI. Due 22 Nov. x Reply OK to acknowledge.', $wa->text);
        $this->assertSame([], AlertRules::send('N1', $this->owner->id, $vars, $t, 'N1:' . $t . ':' . $this->owner->id));      // never twice

        $this->postJson('/api/alerts/texts/tm_new_task/ur', ['body' => 'Salam {first_name}, naya kaam {task_ref}: {title}.'])->assertStatus(422)->assertJsonFragment(['message' => 'Refused: keep the same parameters as the template – missing {due}, {link}.']);
        $this->postJson('/api/alerts/texts/tm_new_task/ur', ['body' => 'Salam {first_name}, naya kaam {task_ref}: {title}, {due} tak. {link} OK likh kar jawab dein.'])->assertOk();
        $this->assertSame('Salam Asha, naya kaam T-0012: Chase PI, 22 Nov tak. x OK likh kar jawab dein.', AlertRules::render('tm_new_task', $vars, 'ur')['text']);
        $this->assertSame('en', AlertRules::render('tm_late', ['days_late' => 2], 'ur')['lang']);      // no Urdu text yet → English
    }

    public function test_thresholds_validate_and_keep_history(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/alerts/thresholds/late_time', ['value' => '9am'])->assertStatus(422)->assertJsonFragment(['message' => 'Refused: use HH:MM']);
        $this->postJson('/api/alerts/thresholds/late_time', ['value' => '09:30'])->assertOk();
        $this->assertSame('09:30', AlertRules::threshold('late_time'));
        $h = collect($this->getJson('/api/alerts')->json('thresholds'))->firstWhere('key', 'late_time')['history'];
        $this->assertSame(['09:00', '09:30'], [$h[0]['old'], $h[0]['new']]);
        $this->postJson('/api/alerts/thresholds/weekly_report', ['value' => 'mon 9:00'])->assertStatus(422);
        $this->postJson('/api/alerts/thresholds/weekly_report', ['value' => 'tue 10:00'])->assertOk();
        $this->assertSame('TUE 10:00', AlertRules::threshold('weekly_report'));
        $this->postJson('/api/alerts/thresholds/stuck_alert_days', ['value' => '12345'])->assertStatus(422);
    }
}
