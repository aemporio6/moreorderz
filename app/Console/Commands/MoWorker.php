<?php

namespace App\Console\Commands;

use App\Services\EventBus;
use Illuminate\Console\Command;

class MoWorker extends Command
{
    protected $signature = 'mo:events';
    protected $description = 'Run waiting and failed events again (scheduled every minute)';

    public function handle(): int
    {
        $this->info(EventBus::runQueue() . ' events processed');
        $x = \App\Services\SaleView::runJobs(); if ($x) $this->info("$x export(s) prepared");
        if ((int) date('i') % 10 === 0) { $rp = \App\Services\TaskRepeat::run(); if (array_sum($rp)) $this->info("repeats created: {$rp['created']}, blocked: {$rp['blocked']}"); }      // R-G_CORE-26 / 35 every 10th minute
        $w = \App\Services\TaskWeekly::run(); if ($w) $this->info('weekly task report sent');      // R-G_CORE-44
        $ta = \App\Services\TaskAlerts::run(); if (array_sum($ta)) $this->info('timed alerts: ' . json_encode(array_filter($ta)));      // R-G_CORE-50 N2 N3 N5 N6 N7 N12 + R-G_CORE-27 STALE
        $d = \App\Services\TaskDigest::run(); if ($d) $this->info("$d digest(s) queued");      // R-G_CORE-45 at each person's digest time
        return 0;
    }
}
