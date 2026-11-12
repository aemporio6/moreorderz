<?php

namespace App\Console\Commands;

use App\Services\OldImport;
use Illuminate\Console\Command;

/** Field-by-field check of the old-data import (after mo:import-old). Results: screen Import history and table mo_import_fields. */
class MoImportCompare extends Command
{
    protected $signature = 'mo:import-compare {--from= : name of the database that holds the old tool\'s data (same server)} {--only=* : only these tables}';
    protected $description = 'Compare the old tool\'s data with the imported data, table by table and column by column';

    public function handle(): int
    {
        if (! $this->option('from')) { $this->error('Give --from=<old database name>.'); return 1; }
        try { $x = OldImport::compareFields((string) $this->option('from'), ['only' => $this->option('only')]); }
        catch (\Throwable $e) { $this->error($e->getMessage()); return 1; }
        $this->line("Run {$x['run']}: {$x['tables']} table(s), {$x['columns']} column(s) – OK {$x['ok']}, masked {$x['masked']}, to look at {$x['diff']}");
        foreach (array_filter($x['rows'], fn ($r) => $r['status'] === 'DIFF') as $r) $this->line("  DIFF {$r['old_table']}.{$r['column_name']}: old filled {$r['old_filled']}, new filled {$r['new_filled']}, differ {$r['differ']} of {$r['rows_both']}");
        return 0;
    }
}
