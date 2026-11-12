<?php

namespace App\Console\Commands;

use App\Services\OldImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MoImportOld extends Command
{
    protected $signature = 'mo:import-old {--from= : name of the database that holds the old tool\'s data (same server)} {--dry : count only, write nothing} {--mask : hide personal details (development copies)} {--only=* : only these tables} {--force : run although the new database already has companies} {--transactions : part 2 – history tables, one sales list, opening stock (run after the master data)} {--opening-date= : date of the opening stock lots (default today)}';
    protected $description = 'Import the old tool\'s data into the new tables and print the reconciliation report (part 1: master data).';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        if ($from === '') { $this->error('Give the old database: --from=name'); return 1; }
        $started = DB::table('mo_import_report')->where('f_dry', 0)->exists();
        if (! $this->option('dry') && ! $started && ! $this->option('force') && DB::table('t_enterprise')->exists()) {
            $this->error('The new database already has companies and no import was run on it before. The import keeps the old ids, so it must start on a fresh install (php artisan mo:install on an empty database). Use --dry to only count.'); return 1;
        }
        if ($this->option('transactions')) {
            if (! DB::table('mo_import_report')->where('f_dry', 0)->where('plan', 'COPY')->exists() && ! $this->option('dry')) { $this->error('Import the master data first (run this command without --transactions).'); return 1; }
            try { $x = OldImport::transactions($from, ['dry' => (bool) $this->option('dry'), 'mask' => (bool) $this->option('mask'), 'opening_date' => $this->option('opening-date') ?: null]); }
            catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
            $this->table(['What', 'Plan', 'Old rows', 'Imported', 'Already there', 'Merged', 'Left out', 'Note'], array_map(fn ($r) => [$r['old_table'], $r['plan'], $r['old_rows'], $r['imported'], $r['already_there'], $r['merged'], $r['failed'], mb_substr((string) $r['note'], 0, 60)], $x['rows']));
            $dir = storage_path('app/import'); if (! is_dir($dir)) mkdir($dir, 0775, true);
            $f = fopen("$dir/{$x['run']}.csv", 'w'); fputcsv($f, ['what', 'plan', 'old_rows', 'imported', 'already_there', 'merged', 'left_out', 'left_out_columns', 'masked_columns', 'note']);
            foreach ($x['rows'] as $r) fputcsv($f, [$r['old_table'], $r['plan'], $r['old_rows'], $r['imported'], $r['already_there'], $r['merged'], $r['failed'], $r['left_out_columns'], $r['masked_columns'], $r['note']]); fclose($f);
            $this->info("Run {$x['run']} done. Report saved: storage/app/import/{$x['run']}.csv (names, counts and totals only).");
            return 0;
        }
        try { $x = OldImport::run($from, ['dry' => (bool) $this->option('dry'), 'mask' => (bool) $this->option('mask'), 'only' => $this->option('only')]); }
        catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
        $this->table(['Old table', 'Plan', 'Old rows', 'Imported', 'Already there', 'Merged', 'Failed', 'Note'], array_map(fn ($r) => [$r['old_table'], $r['plan'], $r['old_rows'], $r['imported'], $r['already_there'], $r['merged'], $r['failed'], mb_substr((string) $r['note'], 0, 70)], $x['rows']));
        $t = $x['totals'];
        $this->info("Run {$x['run']}: {$t['tables']} tables, {$t['old_rows']} old rows. To copy now {$t['to_copy']} → imported {$t['imported']}, already there {$t['already_there']}, duplicates merged {$t['merged']}, failed {$t['failed']}. Later (part 2) {$t['later']}. Retired {$t['retired']}. Tool's own {$t['tool']}.");
        $dir = storage_path('app/import'); if (! is_dir($dir)) mkdir($dir, 0775, true);
        $f = fopen("$dir/{$x['run']}.csv", 'w'); fputcsv($f, ['old_table', 'plan', 'old_rows', 'imported', 'already_there', 'merged', 'failed', 'left_out_columns', 'masked_columns', 'note']);
        foreach ($x['rows'] as $r) fputcsv($f, [$r['old_table'], $r['plan'], $r['old_rows'], $r['imported'], $r['already_there'], $r['merged'], $r['failed'], $r['left_out_columns'], $r['masked_columns'], $r['note']]); fclose($f);
        $this->line("Report saved: storage/app/import/{$x['run']}.csv (it holds table names and counts only, no data rows).");
        return $t['failed'] ? 2 : 0;
    }
}
