<?php

namespace App\Console\Commands;

use App\Services\Backups;
use App\Services\JobLog;
use Illuminate\Console\Command;

/** MR-0095 daily database backup (mo:backup) and the monthly restore drill (mo:backup --drill). */
class MoBackup extends Command
{
    protected $signature = 'mo:backup {--drill : test the newest backup instead of making one}';
    protected $description = 'Daily database backup (14 daily + 12 monthly kept) or the monthly restore drill';

    public function handle(): int
    {
        $drill = (bool) $this->option('drill'); $out = null;
        $r = JobLog::run($drill ? 'BACKUP_DRILL' : 'BACKUP', null, function () use ($drill, &$out) { $out = $drill ? Backups::drill() : Backups::make(); return $drill ? $out['tables'] : 1; });
        if ($r['status'] !== 'OK') { $this->error(($drill ? 'Restore drill' : 'Backup') . ': ' . $r['status'] . (isset($r['error']) ? ' – ' . $r['error'] : '')); return 1; }
        $this->info($drill ? "Restore drill passed: {$out['file']} – {$out['tables']} tables (live {$out['live_tables']}), complete." : "Backup made: $out");
        return 0;
    }
}
