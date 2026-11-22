<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\EventBus;
use App\Services\EventCatalogue;
use App\Services\JobLog;
use App\Services\RecordService;
use App\Services\TaskTeam;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-95 event catalogue (retired aliases, self-check), R-G_CORE-110 every scheduled job in one run log + Health "late", R-G_CORE-16 HR calendar. */
class PlatformChecksTest extends TestCase
{
    public function test_r95_retired_alias_refused_mapped_and_listed(): void
    {
        try { EventBus::fire('GRN_SAVED', 'wh_grn', 1); $this->fail('a retired name is refused'); } catch (\InvalidArgumentException $e) { $this->assertSame('Unknown event – use GRN_POSTED', $e->getMessage()); }
        $tpl = DB::table('task_templates')->insertGetId(['name' => 'Old GRN checks', 'linked_module' => 'wh_grn', 'trigger_event' => 'GRN_SAVED', 'is_active' => 1]);
        $this->assertGreaterThanOrEqual(1, EventCatalogue::migrateStored());
        $this->assertSame('GRN_POSTED', DB::table('task_templates')->where('id', $tpl)->value('trigger_event'), 'a template with trigger GRN_SAVED migrates to GRN_POSTED');
        $this->assertSame([], EventCatalogue::check(), 'every raise, listener and template trigger of the code is in the catalogue');
        $dir = sys_get_temp_dir() . '/mo-evcheck-' . uniqid(); mkdir($dir);
        file_put_contents("$dir/X.php", "<?php EventBus::fire('MONTH_CLOSE', 'x', 1); EventBus::listen('NO_SUCH_EVENT', fn () => null);");
        $w = EventCatalogue::check($dir);
        $this->assertContains('Event MONTH_CLOSE is a retired name (raised in X.php) – use MONTH_LOCKED.', $w);
        $this->assertContains('Event NO_SUCH_EVENT is not in the event catalogue (listened to in X.php).', $w);
        unlink("$dir/X.php"); rmdir($dir);
    }

    public function test_r110_every_job_one_log_and_late_on_health(): void
    {
        DB::table('sys_job_runs')->delete();
        $now = new \DateTimeImmutable('2026-11-22 10:00:00');
        $this->assertSame([], JobLog::late($now), 'no run at all yet → nothing to say');
        foreach (JobLog::PLAN as $code => [$l, $every]) DB::table('sys_job_runs')->insert(['job_code' => $code, 'started_at' => '2026-11-22 09:59:00', 'finished_at' => '2026-11-22 09:59:30', 'run_status' => 'OK', 'items_done' => 1]);
        $this->assertSame([], JobLog::late($now));
        DB::table('sys_job_runs')->where('job_code', 'SRC_ALERTS')->update(['started_at' => '2026-11-22 09:10:00']);
        DB::table('sys_job_runs')->where('job_code', 'FIN_HOURLY')->update(['run_status' => 'FAILED', 'error_text' => 'bank feed down']);
        $w = JobLog::late($now);
        $this->assertContains('Job Sourcing alerts (mo:src-alerts) is late: last run 2026-11-22 09:10:00, it should run every 15 minutes.', $w);
        $this->assertContains('Job Finance hourly (mo:finance-hourly) failed at 2026-11-22 09:59:00: bank feed down', $w);
        DB::table('sys_job_runs')->where('job_code', 'PULL')->delete();
        $this->assertContains("Job Marketplace pulls + Drive folders (mo:pull) has never run – is the server's schedule:run cron line in place?", JobLog::late($now));
        $this->artisan('mo:pull', ['--no-drive' => true])->assertExitCode(0);
        $this->assertSame('OK', DB::table('sys_job_runs')->where('job_code', 'PULL')->value('run_status'), 'mo:pull now leaves its run-log line');
        DB::table('sys_job_runs')->where('job_code', 'SRC_ALERTS')->update(['started_at' => now()->subHour()]);
        $h = $this->actingAs($this->owner)->getJson('/api/health')->assertOk()->json();
        $this->assertSame(array_keys(JobLog::PLAN), array_column($h['jobs'], 'code'));
        $this->assertNotEmpty(array_filter($h['warnings'], fn ($x) => str_contains($x, 'Sourcing alerts')));
    }

    public function test_r16_hr_calendar_month_grid_and_choice(): void
    {
        $acc = fn ($n) => DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'HC' . random_int(10000, 99999), 'first_name' => $n, 'f_active' => 1]));
        $ali = $acc('Ali'); $sara = $acc('Sara');
        DB::table('user_absence')->insert(RecordService::fillNotNull('user_absence', ['user_id' => $ali, 'cover_user_id' => $sara, 'from_date' => '2026-11-10', 'to_date' => '2026-11-14', 'created_at' => now()]));
        $c = TaskTeam::calendar('2026-11');
        $this->assertSame(['November 2026', 30, 7], [$c['title'], count($c['days']), $c['days'][0]['dow']], '1 Nov 2026 is a Sunday');
        $this->assertSame([['who' => 'Ali', 'cover' => 'Sara']], $c['days'][10]['away'], '11 Nov: Ali away, cover Sara');
        $this->assertSame([], $c['days'][14]['away'], '15 Nov: back');
        $this->assertSame(['10 Nov – 14 Nov (cover Sara)'], $c['people'][0]['spells']);
        $this->actingAs($this->makeUser('MANAGER'))->getJson('/api/tasks/absence-calendar?month=2026-11')->assertOk()->assertJsonPath('title', 'November 2026');
        $this->actingAs($this->makeUser('VA'))->getJson('/api/tasks/absence-calendar')->assertStatus(422);
        Choices::set('ABSENCE_CALENDAR_ROLES', 'ALL', 1);
        $this->actingAs($this->makeUser('VA'))->getJson('/api/tasks/absence-calendar')->assertOk();
    }
}
