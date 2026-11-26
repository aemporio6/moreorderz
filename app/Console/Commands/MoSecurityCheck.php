<?php

namespace App\Console\Commands;

use App\Services\SecurityCheck;
use Illuminate\Console\Command;

/** MR-1547 security + slow-query self-check. */
class MoSecurityCheck extends Command
{
    protected $signature = 'mo:security-check {--rows=10000 : a table with at least this many rows is checked for missing indexes}';
    protected $description = 'Security settings and missing indexes of large tables (run on the live server)';

    public function handle(): int
    {
        $r = SecurityCheck::run((int) $this->option('rows'));
        foreach ($r['checks'] as $c) $this->line(($c['ok'] ? 'OK    ' : 'FIX   ') . $c['check'] . ($c['fix'] ? ' – ' . $c['fix'] : ''));
        foreach ($r['missing_indexes'] as $m) $this->line("      index missing: {$m['table']}.{$m['column']} ({$m['rows']} rows)");
        $this->info("{$r['passed']} of {$r['total']} checks passed.");
        return $r['passed'] === $r['total'] ? 0 : 1;
    }
}
