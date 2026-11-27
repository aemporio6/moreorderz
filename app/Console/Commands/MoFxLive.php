<?php

namespace App\Console\Commands;

use App\Services\FxLive;
use Illuminate\Console\Command;

/** Owner 27-11 09:33: bring today's exchange rates in by themselves (European Central Bank reference rates). */
class MoFxLive extends Command
{
    protected $signature = 'mo:fx-live';
    protected $description = 'Bring today\'s exchange rates in from the European Central Bank (a rate typed by hand is kept)';

    public function handle(): int
    {
        $r = FxLive::pull();
        if (! $r['ok']) { $this->error('Rates not brought in: ' . $r['error']); return 1; }
        $this->info("Rates of {$r['date']}: {$r['saved']} saved, {$r['kept']} typed by hand kept");
        return 0;
    }
}
