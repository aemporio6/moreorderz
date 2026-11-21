<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MoCleanupTemp extends Command
{
    protected $signature = 'mo:cleanup-temp';
    protected $description = 'Nightly temporary file retention (R-G_CORE-75): held uploads and generator files older than the retention settings';

    public function handle(): int
    {
        $this->line(\App\Services\TempCleanup::run()['text']);
        return 0;
    }
}
