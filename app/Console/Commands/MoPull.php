<?php

namespace App\Console\Commands;

use App\Services\Connect\Connections;
use App\Services\Connect\DriveFolders;
use App\Services\Context;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Pulls orders and money from every marketplace connection with an API, and looks into watched Drive folders (hourly). */
class MoPull extends Command
{
    protected $signature = 'mo:pull {--no-drive : skip the watched Drive folders}';
    protected $description = 'Pull orders and money from marketplace APIs; check watched Drive folders';

    public function handle(): int
    {
        $r = \App\Services\JobLog::run('PULL', null, function () { $n = 0; $this->pull($n); return $n; });      // R-G_CORE-110 one lock, one run-log line
        return $r['status'] === 'FAILED' ? 1 : 0;
    }

    private function pull(int &$n): void
    {
        foreach (Connections::pullAll() as [$label, $what, $r]) $this->line("$label · $what · " . (! empty($r['ok']) ? ($r['lines'] ?? 0) . ' line(s), ' . ($r['new'] ?? 0) . ' new' : 'FAILED: ' . ($r['error'] ?? '')));
        if (! $this->option('no-drive')) foreach (DB::table('imp_drive_folders')->where('f_active', 1)->distinct()->pluck('fk_id_ent') as $ent) {
            Context::set((int) $ent, 0);
            try { $x = DriveFolders::check(); $this->line("Drive (company $ent): {$x['found']} new file(s), {$x['loaded']} loaded, {$x['waiting']} waiting" . ($x['errors'] ? ' – ' . implode(' | ', $x['errors']) : '')); }
            catch (\InvalidArgumentException $e) { $this->line("Drive (company $ent): " . $e->getMessage()); }
        }
        $n = DB::table('sys_job_runs')->where('job_code', 'like', 'API_PULL_%')->where('started_at', '>=', now()->subMinutes(5))->sum('items_done');
        $n = (int) $n;
    }
}
