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
        return 0;
    }
}
