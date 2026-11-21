<?php

namespace App\Console\Commands;

use App\Services\Context;
use App\Services\JobLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** R-FIN-32 nightly finance plan (00:30): stock snapshot per company (R-FIN-51) – one lock, one run-log line per company. */
class MoFinanceNightly extends Command
{
    protected $signature = 'mo:finance-nightly';
    protected $description = 'Nightly finance jobs (stock snapshot) with lock and run log';

    public function handle(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null);
            $r = JobLog::run('FIN_NIGHTLY', (int) $ent, fn () => JobLog::financeNightly((int) $ent));
            $this->line("Company $ent: {$r['status']}" . (isset($r['items']) ? " ({$r['items']} SKU rows)" : ''));
        }
        return 0;
    }
}
