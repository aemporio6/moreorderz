<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-32 cron plan with a lock and a run log: every scheduled job runs through here. One lock per job – a second run while the first
 * still runs exits "LOCKED" and is logged; every run leaves one line in sys_job_runs (start, end, items, error). CLI only.
 */
class JobLog
{
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

    /** Hourly finance jobs (minute 0): auto-match every bank account, the unmatched / stale tasks, rates that answer FX tasks. */
    public static function financeHourly(int $ent): int
    {
        $n = 0;
        foreach (DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->pluck('id') as $b) $n += MoneyService::autoMatch($ent, (int) $b);
        MoneyService::unmatchedTasks($ent); MoneyService::staleTasks($ent); $n += FxMissing::sweep();
        $a = FinanceAlerts::run($ent); $n += $a['raised'] + $a['resolved'];      // R-FIN-66 the ten finance alerts
        return $n;
    }
}
