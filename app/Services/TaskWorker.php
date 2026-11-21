<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-35 the task worker job: every minute under a lock (a second run while the first is busy is skipped and logged),
 * in this order: repeats (every 10th minute) → event-trigger catch-up (R-G_CORE-25) → schedule (R-G_CORE-50 timed alerts, N13 digest, N14 weekly)
 * → dispatch up to 200 queued messages (R-G_CORE-46). One log line per run in sys_job_runs (job TASK_WORKER), shown on System › Health.
 * The month opening (PERIOD_OPENED, 06_ENGINES §D) runs in the same job so a new shop-month fires its templates within the minute.
 */
class TaskWorker
{
    public const JOB = 'TASK_WORKER';
    public const BUSY = 'another run is still busy - skipped';

    /** One run. $only: null = all steps; 'triggers' / 'repeats' / 'send' = one step (CLI sub-commands). Returns ['status', 'line']. */
    public static function run(?\DateTimeInterface $now = null, ?string $only = null): array
    {
        $now = $now ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable();
        $busy = DB::table('sys_job_runs')->where('job_code', self::JOB)->where('run_status', 'RUNNING')->where('started_at', '>', now()->subMinutes(JobLog::STALE_MIN))->exists();
        $line = fn (string $txt) => $now->format('H:i') . ' ' . $txt;
        if ($busy) {
            DB::table('sys_job_runs')->insert(['job_code' => self::JOB, 'started_at' => now(), 'finished_at' => now(), 'run_status' => 'LOCKED', 'items_done' => 0, 'note' => $line(self::BUSY)]);
            return ['status' => 'LOCKED', 'line' => $line(self::BUSY)];
        }
        $id = DB::table('sys_job_runs')->insertGetId(['job_code' => self::JOB, 'started_at' => now(), 'run_status' => 'RUNNING']);
        $t0 = microtime(true); $seen = (int) DB::table('tm_trigger_seen')->max('id');
        try {
            $parts = []; $items = 0;
            if ($only === null || $only === 'repeats') {
                $rp = ['created' => 0, 'blocked' => 0];
                if ($only === 'repeats' || (int) $now->format('i') % 10 === 0) $rp = TaskRepeat::run();
                $parts[] = "repeats created: {$rp['created']}"; $items += $rp['created'];
            }
            if ($only === null || $only === 'triggers') {
                CloseService::openMonth($now);      // a new shop-month → PERIOD_OPENED
                EventBus::runQueue();      // catch-up: events whose listeners have not run yet (templates fire once per record)
                $fired = DB::table('tm_trigger_seen')->where('id', '>', $seen)->where('record_id', '>', 0)->groupBy('event')->selectRaw('event, SUM(template_tasks) n')->pluck('n', 'event');
                $parts[] = 'auto tasks: ' . ($fired->isEmpty() ? 'PO_CONFIRMED 0 tasks' : $fired->map(fn ($n, $e) => "$e $n tasks")->implode(', ')); $items += (int) $fired->sum();
            }
            if ($only === null) {
                $before = (int) DB::table('tm_messages')->max('id');
                TaskAlerts::run($now); TaskDigest::run($now); TaskWeekly::run($now);
                $q = (int) DB::table('tm_messages')->where('id', '>', $before)->where('channel', '<>', 'INAPP')->count();
                $parts[] = "reminders queued: $q";
            }
            if ($only === null || $only === 'send') { $d = TmQueue::dispatch(200, $now); $parts[] = "messages sent: {$d['sent']}"; $items += $d['sent']; }
            $txt = $line(implode(', ', $parts) . ', ' . number_format(microtime(true) - $t0, 1) . 's');
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => $items, 'note' => mb_substr($txt, 0, 500)]);
            return ['status' => 'OK', 'line' => $txt];
        } catch (\Throwable $e) {
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'FAILED', 'error_text' => mb_substr($e->getMessage(), 0, 1000), 'note' => $line('failed: ' . mb_substr($e->getMessage(), 0, 200))]);
            report($e);
            return ['status' => 'FAILED', 'line' => $line('failed: ' . $e->getMessage())];
        }
    }

    /** The last runs for System › Health. */
    public static function log(int $n = 20): array
    {
        return DB::table('sys_job_runs')->where('job_code', self::JOB)->orderByDesc('id')->limit($n)->get(['started_at', 'run_status', 'note', 'error_text'])->map(fn ($r) => (array) $r)->all();
    }
}
