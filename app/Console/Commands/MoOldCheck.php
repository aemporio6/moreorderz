<?php

namespace App\Console\Commands;

use App\Services\OldToolCheck;
use Illuminate\Console\Command;

/** Owner 27-11 01:30: count the old tool's tables against what came over (read only); the result shows in Setup › Old tool check. */
class MoOldCheck extends Command
{
    protected $signature = 'mo:old-check {--from= : name of the database that holds the old tool\'s data (same server)}';
    protected $description = 'Count every old table with rows against what came over (read only)';

    public function handle(): int
    {
        if (! $this->option('from')) { $this->error('Give --from=<old database name>.'); return 1; }
        try { $x = OldToolCheck::count((string) $this->option('from')); } catch (\Throwable $e) { $this->error($e->getMessage()); return 1; }
        $p = OldToolCheck::page();
        $this->line(count($x['tables']) . ' old tables with rows · still to bring: ' . $p['data_open'] . ' · see Setup › Old tool check');
        return 0;
    }
}
