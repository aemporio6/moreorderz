<?php

namespace Tests\Feature;

use App\Services\CloseService;
use App\Services\EventBus;
use App\Services\RecordService;
use App\Services\TaskWorker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-25 PERIOD_OPENED (a shop-month opens → its templates, once per shop and month) and R-G_CORE-35 the task worker job – spec examples. */
class TaskWorkerTest extends TestCase
{
    private function shop(string $name): int
    {
        return DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_name' => $name, 'shop_no' => 'T' . random_int(1000, 9999), 'iso_branch' => 'T' . uniqid(), 'f_active' => 1]));
    }

    public function test_r25_period_opened_fires_the_month_template_per_shop(): void
    {
        $this->makeUser('VA');
        DB::table('t_branches')->update(['f_active' => 0]);      // only the two shops of the example
        $ebay = $this->shop('eBay test'); $tiktok = $this->shop('TikTok test');
        $tpl = DB::table('task_templates')->insertGetId(['name' => 'Month close ' . uniqid(), 'linked_module' => 'SHOP', 'trigger_event' => 'PERIOD_OPENED', 'is_active' => 1]);
        DB::table('task_template_items')->insert(['template_id' => $tpl, 'title' => 'Load the money file', 'owner_role' => 'VA', 'offset_days' => 3, 'sort' => 10, 'is_active' => 1]);
        $this->assertSame(2, CloseService::openMonth(new \DateTimeImmutable('2026-12-01 00:05:00'))); EventBus::runQueue();
        $rows = DB::table('tasks')->where('template_id', $tpl)->orderBy('linked_record_id')->get();
        $this->assertSame([[$ebay, '2026-12', 'SHOP'], [$tiktok, '2026-12', 'SHOP']], $rows->map(fn ($t) => [(int) $t->linked_record_id, $t->reference_id, $t->linked_module])->all(), 'tasks on the eBay and on the TikTok shop');
        $this->assertSame(1, DB::table('sys_events')->where('event_code', 'PERIOD_OPENED')->where('entity', 'aud_runs')->where('entity_id', CloseService::run(1, $ebay, '2026-12')->id)->count());
        $this->assertSame(0, CloseService::openMonth(new \DateTimeImmutable('2026-12-15 10:00:00')), 'a month opens once'); EventBus::runQueue();
        $this->assertSame(2, DB::table('tasks')->where('template_id', $tpl)->count());
        CloseService::openMonth(new \DateTimeImmutable('2027-01-01 00:01:00')); EventBus::runQueue();
        $this->assertSame(['2026-12', '2026-12', '2027-01', '2027-01'], DB::table('tasks')->where('template_id', $tpl)->orderBy('reference_id')->pluck('reference_id')->all(), 'each month opens again for each shop');
        // a first check of a month nobody opened yet, and a correction placeholder, open it the same way
        CloseService::open(1, $ebay, '2027-03', 'placeholder for a correction'); EventBus::runQueue();
        $this->assertSame(1, DB::table('tasks')->where('template_id', $tpl)->where('reference_id', '2027-03')->count());
        $this->assertStringContainsString('created 1 tasks', (string) DB::table('tm_trigger_seen')->where('event', 'PERIOD_OPENED')->where('record_id', CloseService::run(1, $ebay, '2027-03')->id)->value('result'));
    }

    public function test_r35_worker_lock_order_and_one_log_line(): void
    {
        DB::table('sys_job_runs')->insert(['job_code' => TaskWorker::JOB, 'started_at' => now(), 'run_status' => 'RUNNING']);      // a run of this minute still busy
        $r = TaskWorker::run(new \DateTimeImmutable('2026-11-06 10:20:00'));
        $this->assertSame(['LOCKED', '10:20 another run is still busy - skipped'], [$r['status'], $r['line']]);
        $this->assertSame('10:20 another run is still busy - skipped', DB::table('sys_job_runs')->where('job_code', TaskWorker::JOB)->where('run_status', 'LOCKED')->value('note'));
        DB::table('sys_job_runs')->where('job_code', TaskWorker::JOB)->where('run_status', 'RUNNING')->update(['run_status' => 'OK', 'finished_at' => now()]);
        $r = TaskWorker::run(new \DateTimeImmutable('2026-11-06 10:20:00'));
        $this->assertSame('OK', $r['status']);
        $this->assertMatchesRegularExpression('/^10:20 repeats created: \d+, auto tasks: [A-Z_]+ \d+ tasks(, [A-Z_]+ \d+ tasks)*, reminders queued: \d+, messages sent: \d+, \d+\.\ds$/', $r['line'], 'old log line, in the order repeats → triggers → schedule → dispatch');
        $this->assertSame($r['line'], DB::table('sys_job_runs')->where('job_code', TaskWorker::JOB)->orderByDesc('id')->value('note'), 'one line per run');
        // CLI sub-commands
        $this->assertSame(0, Artisan::call('mo:tasks', ['step' => 'send'])); $this->assertMatchesRegularExpression('/^\d\d:\d\d messages sent: \d+, \d+\.\ds$/', trim(Artisan::output()));
        $this->assertSame(0, Artisan::call('mo:tasks', ['step' => 'repeats'])); $this->assertStringContainsString('repeats created: ', Artisan::output());
        $this->assertSame(1, Artisan::call('mo:tasks', ['step' => 'nonsense']));
        // shown on System › Health
        $owner = $this->makeUser('OWNER');
        $this->assertNotEmpty($this->actingAs($owner)->getJson('/api/health')->assertOk()->json('task_worker'));
    }
}
