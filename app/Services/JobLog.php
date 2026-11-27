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
        'FIN_NIGHTLY' => ['Finance nightly (mo:finance-nightly)', 1440], 'NIGHTLY' => ['Nightly jobs (mo:nightly)', 1440], 'ACC_REMINDERS' => ['Filing reminders (Tax & filings, with mo:nightly)', 1440], 'TEMP_CLEANUP' => ['Temporary files clean-up (mo:cleanup-temp)', 1440],
        'FIN_MONTHLY' => ['Finance monthly (mo:finance-monthly)', 44640],
        'BACKUP' => ['Database backup (mo:backup)', 1440], 'BACKUP_DRILL' => ['Restore drill (mo:backup --drill)', 44640],
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
        // found on live 27-11: a 15-minute job that was stopped from outside (no error, no end) kept its lock for 2 hours. A run is taken as stopped after
        // 2 × its interval (at least 10, at most STALE_MIN minutes); the stopped line is marked FAILED, so Health names it and the job runs again.
        $stale = min(self::STALE_MIN, max(10, 2 * (int) (self::PLAN[$code][1] ?? self::STALE_MIN)));
        DB::table('sys_job_runs')->where('job_code', $code)->where('run_status', 'RUNNING')->where('started_at', '<=', now()->subMinutes($stale))->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))
            ->update(['run_status' => 'FAILED', 'finished_at' => now(), 'error_text' => "Stopped without finishing (no end after $stale minutes – the server ended the process); the next run went ahead."]);
        $busy = DB::table('sys_job_runs')->where('job_code', $code)->where('run_status', 'RUNNING')->where('started_at', '>', now()->subMinutes($stale))->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))->exists();
        if ($busy) { $id = DB::table('sys_job_runs')->insertGetId(['job_code' => $code, 'fk_id_ent' => $ent, 'started_at' => now(), 'finished_at' => now(), 'run_status' => 'LOCKED', 'items_done' => 0]); return ['id' => $id, 'status' => 'LOCKED']; }
        $id = DB::table('sys_job_runs')->insertGetId(['job_code' => $code, 'fk_id_ent' => $ent, 'started_at' => now(), 'run_status' => 'RUNNING']);
        try {
            $n = $fn();
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => is_numeric($n) ? (int) $n : 0]);
            return ['id' => $id, 'status' => 'OK', 'items' => is_numeric($n) ? (int) $n : 0];
        } catch (\Throwable $e) {
            DB::table('sys_job_runs')->where('id', $id)->update(['finished_at' => now(), 'run_status' => 'FAILED', 'error_text' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
            EventBus::sysError(['job', $code], 'Scheduled job failed: ' . (self::PLAN[$code][0] ?? $code), $e->getMessage(), (int) ($ent ?? 0));      // MR-1508 nightly step
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
        // Rule 5 "never stuck" (found on live 27-11: one missing CNY rate stopped every hourly step): each step runs on its own; a step that fails is named
        // in the run log while the others still do their work; a missing exchange rate becomes the FX task (FxMissing) instead of a stop.
        $n = 0; $err = [];
        $step = function (string $name, callable $fn) use (&$n, &$err) { try { $n += (int) $fn(); } catch (\Throwable $e) { $err[] = "$name: " . mb_substr($e->getMessage(), 0, 200); } };
        foreach (DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->pluck('id') as $b) $step('bank match #' . $b, fn () => MoneyService::autoMatch($ent, (int) $b));
        $step('missing reports', fn () => FinanceMonthly::missingTasks($ent));      // R-FIN-50 FIN_REPORT_MISSING per shop (before the general stale check, so a shop gets one task)
        $step('unmatched tasks', fn () => MoneyService::unmatchedTasks($ent) && 0); $step('stale tasks', fn () => MoneyService::staleTasks($ent) && 0); $step('missing rates', fn () => FxMissing::sweep());
        $step('finance alerts', function () use ($ent) { $a = FinanceAlerts::run($ent); return $a['raised'] + $a['resolved']; });      // R-FIN-66 the ten finance alerts
        if ($err) throw new \RuntimeException(count($err) . ' step(s) could not finish (the others ran): ' . implode(' · ', $err));
        return $n;
    }
}
