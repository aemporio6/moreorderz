<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-32 cron plan with a lock and a run log: every scheduled job runs through here. One lock per job – a second run while the first
 * still runs exits "LOCKED" and is logged; every run leaves one line in sys_job_runs (start, end, items, error). CLI only.
 */
class JobLog
{
    /**
     * R-G_CORE-110 every scheduled job (routes/console.php) and how often it should run (minutes). A job whose last run started more than
     * 2 × its interval + 10 minutes ago is "late" on System › Health (and in the Owner exceptions digest).
     */
    public const PLAN = [
        'TASK_WORKER' => ['Task worker + messages (mo:events)', 1], 'SRC_ALERTS' => ['Sourcing alerts (mo:src-alerts)', 15], 'DISPATCH_WATCH' => ['Dispatch watch (mo:dispatch-watch)', 15],
        'PULL' => ['Marketplace pulls + Drive folders (mo:pull)', 60], 'FIN_HOURLY' => ['Finance hourly (mo:finance-hourly)', 60],
        'FIN_NIGHTLY' => ['Finance nightly (mo:finance-nightly)', 1440], 'NIGHTLY' => ['Nightly jobs (mo:nightly)', 1440], 'TEMP_CLEANUP' => ['Temporary files clean-up (mo:cleanup-temp)', 1440],
        'FIN_MONTHLY' => ['Finance monthly (mo:finance-monthly)', 44640],
    ];

    /** Every planned job with its last run and whether it is late. */
    public static function overview(?\DateTimeInterface $now = null): array
    {
        $now = $now ? \Carbon\Carbon::instance(\DateTime::createFromInterface($now)) : now(); $any = DB::table('sys_job_runs')->exists(); $out = [];
        foreach (self::PLAN as $code => [$label, $every]) {
            $last = DB::table('sys_job_runs')->where('job_code', $code)->orderByDesc('id')->first(['started_at', 'finished_at', 'run_status', 'items_done', 'error_text']);
            $limit = $every * 2 + 10; $age = $last ? (int) floor(($now->getTimestamp() - strtotime((string) $last->started_at)) / 60) : null;
            $late = $last ? $age > $limit : $any;      // never ran while the scheduler runs other jobs = late too
            $out[] = ['code' => $code, 'label' => $label, 'every_min' => $every, 'last' => $last ? (string) $last->started_at : null, 'status' => $last->run_status ?? null, 'items' => (int) ($last->items_done ?? 0), 'error' => $last->error_text ?? null, 'late' => $late];
        }
        return $out;
    }

    /** Plain sentences for System › Health. */
    public static function late(?\DateTimeInterface $now = null): array
    {
        $w = [];
        foreach (self::overview($now) as $j) {
            if ($j['late']) $w[] = $j['last'] ? "Job {$j['label']} is late: last run {$j['last']}, it should run every " . self::every($j['every_min']) . '.' : "Job {$j['label']} has never run – is the server's schedule:run cron line in place?";
            elseif ($j['status'] === 'FAILED') $w[] = "Job {$j['label']} failed at {$j['last']}: " . mb_substr((string) $j['error'], 0, 160);
        }
        return $w;
    }

    private static function every(int $m): string { return $m < 60 ? "$m minute" . ($m === 1 ? '' : 's') : ($m < 1440 ? ($m / 60) . ' hour' . ($m === 60 ? '' : 's') : ($m < 44640 ? 'day' : 'month')); }

    /** Minutes after which a RUNNING line is taken as crashed (its lock is free again). */
    public const STALE_MIN = 120;

    /** Run $fn (returns a count or null) under the job's lock; returns the run row. */
    public static function run(string $code, ?int $ent, callable $fn): array
    {
        $busy = DB::table('sys_job_runs')->where('job_code', $code)->where('run_status', 'RUNNING')->where('started_at', '>', now()->subMinutes(self::STALE_MIN))->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))->exists();
        if ($busy) { $id = DB::table('sys_job_runs')->insertGetId(['job_code' => $code, 'fk_id_ent' => $ent, 'started_at' => now(), 'finished_at' => now(), 'run_status' => 'LOCKED', 'items_done' => 0]); return ['id' => $id, 'status' => 'LOCKED']; }
        $id = DB::table('sys_job_runs')->insertGetId(['job_code' => $code, 'fk_id_ent' => $ent, 'started_at' => now(), 'run_status' => 'RUNNING']);
        try {
            $n = $fn();
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => is_numeric($n) ? (int) $n : 0]);
            return ['id' => $id, 'status' => 'OK', 'items' => is_numeric($n) ? (int) $n : 0];
        } catch (\Throwable $e) {
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'FAILED', 'error_text' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
            return ['id' => $id, 'status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    /**
     * R-FIN-32 nightly finance job (00:30): the stock snapshot of the day (R-FIN-51). GRN → lots needs no sync (a GRN post is the only lot maker,
     * R-FIN-53) and product periods are not stored (the profit list is built live, R-FIN-54), so the snapshot is the whole nightly plan.
     */
    public static function financeNightly(int $ent): int { return StockSnapshot::build($ent); }

    /** Hourly finance jobs (minute 0): auto-match every bank account, the unmatched / stale tasks, rates that answer FX tasks. */
    public static function financeHourly(int $ent): int
    {
        $n = 0;
        foreach (DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->pluck('id') as $b) $n += MoneyService::autoMatch($ent, (int) $b);
        $n += FinanceMonthly::missingTasks($ent);      // R-FIN-50 FIN_REPORT_MISSING per shop (before the general stale check, so a shop gets one task)
        MoneyService::unmatchedTasks($ent); MoneyService::staleTasks($ent); $n += FxMissing::sweep();
        $a = FinanceAlerts::run($ent); $n += $a['raised'] + $a['resolved'];      // R-FIN-66 the ten finance alerts
        return $n;
    }
}
