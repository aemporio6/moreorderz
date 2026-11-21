<?php

namespace App\Console\Commands;

use App\Services\Context;
use App\Services\JobLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** R-FIN-32 hourly finance plan: auto-match, money tasks, FX tasks – one lock, one run-log line per company. */
class MoFinanceHourly extends Command
{
    protected $signature = 'mo:finance-hourly';
    protected $description = 'Hourly finance jobs (auto-match, unmatched / stale tasks, missing-rate tasks) with lock and run log';

    public function handle(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null);
            $r = JobLog::run('FIN_HOURLY', (int) $ent, fn () => JobLog::financeHourly((int) $ent));
            $this->line("Company $ent: {$r['status']}" . (isset($r['items']) ? " ({$r['items']} matched / closed)" : ''));
        }
        return 0;
    }
}
