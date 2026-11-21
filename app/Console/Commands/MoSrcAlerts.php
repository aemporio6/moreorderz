<?php

namespace App\Console\Commands;

use App\Services\Context;
use App\Services\CycleAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The sourcing alert registry every 15 minutes (R-B_SOURCING-26): opens and auto-closes the SRC_* / STK_* tasks. */
class MoSrcAlerts extends Command
{
    protected $signature = 'mo:src-alerts';
    protected $description = 'Sourcing and stock alert tasks (opened and closed by their detectors)';

    public function handle(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null); $r = [];
            $run = \App\Services\JobLog::run('SRC_ALERTS', (int) $ent, function () use ($ent, &$r) { $r = CycleAlerts::sweep((int) $ent); \App\Services\LandedVersions::autoRerun((int) $ent);      // R-B_SOURCING-46 PROVISIONAL re-run after new costs (5 min debounce)
                return array_sum(array_column($r, 'opened')) + array_sum(array_column($r, 'closed')); });      // R-FIN-32 lock + run log
            $this->line("Company $ent: {$run['status']}, opened " . array_sum(array_column($r, 'opened')) . ', closed ' . array_sum(array_column($r, 'closed')));
        }
        $this->line('Stuck alerts (N6) queued: ' . \App\Services\AlertRules::stuckSweep());
        return 0;
    }
}
