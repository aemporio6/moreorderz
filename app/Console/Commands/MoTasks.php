<?php

namespace App\Console\Commands;

use App\Services\TaskWorker;
use Illuminate\Console\Command;

/** R-G_CORE-35 the task worker: `mo:tasks` (all steps, as the every-minute job) or one step – `mo:tasks triggers`, `mo:tasks repeats`, `mo:tasks send`. */
class MoTasks extends Command
{
    protected $signature = 'mo:tasks {step? : triggers | repeats | send}';
    protected $description = 'Task worker: repeats, event triggers, timed alerts, message dispatch (one log line per run)';

    public function handle(): int
    {
        $step = $this->argument('step');
        if ($step !== null && ! in_array($step, ['triggers', 'repeats', 'send'], true)) { $this->error('Unknown step – use triggers, repeats or send.'); return 1; }
        $r = TaskWorker::run(null, $step);
        $this->line($r['line']);
        return $r['status'] === 'FAILED' ? 1 : 0;
    }
}
