<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Temporary file retention (R-G_CORE-75): the nightly job `mo:cleanup-temp` (02:30 company time, COMPANY_TIMEZONE), never on a page open.
 * - Held uploads (storage/app/ds_tmp – upload screens keep the file between preview and confirm): older than retention_import_upload_days (default 1).
 * - Generator files (storage/app/csv_convert – the CSV converter): older than retention_generator_days (default 0 = only the same day is kept).
 * - Old temp rows t_import_options / csv_generator_options (retired tables, if any rows are left) by the same rule.
 * Kept: saved mappings / formats (csv_generator_history), export templates, finished Import Center batches and their source files.
 * "Older than N days" = created before the start of (today − N + 1): with 1 an upload of 05 Nov is gone on the 06 Nov run; with 0 everything before the run goes.
 * Each run writes one sys_job_runs row (TEMP_CLEANUP) with the counts, shown on System › Health.
 */
class TempCleanup
{
    public const DIRS = ['import' => 'ds_tmp', 'generator' => 'csv_convert'];

    public static function cutoff(int $days, ?int $now = null): int
    {
        $now ??= time();
        return strtotime(date('Y-m-d', $now) . ' 00:00:00') - (max(0, $days) - 1) * 86400;
    }

    public static function run(?int $now = null): array
    {
        $now ??= time();
        $job = (int) DB::table('sys_job_runs')->insertGetId(['job_code' => 'TEMP_CLEANUP', 'started_at' => now(), 'run_status' => 'RUNNING']);
        try {
            $days = ['import' => (int) (Settings::get('retention_import_upload_days', 0, 1) ?? 1), 'generator' => (int) (Settings::get('retention_generator_days', 0, 0) ?? 0)];
            $out = ['uploads' => 0, 'generator' => 0, 'rows' => 0];
            foreach (self::DIRS as $kind => $dir) {
                $cut = self::cutoff($days[$kind], $now); $path = storage_path('app/' . $dir);
                foreach (glob($path . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < $cut && @unlink($f)) $out[$kind === 'import' ? 'uploads' : 'generator']++;
            }
            $out['uploads'] += BankImportCheck::sweep();      // R-FIN-24: checked bank files waiting under a token, older than 24 h
            $out['uploads'] += CsvConvert::cleanBatches();      // R-D-54: batch copies kept 30 days for undo
            foreach (['t_import_options' => 'import', 'csv_generator_options' => 'generator'] as $t => $kind)
                if (\Illuminate\Support\Facades\Schema::hasTable($t)) $out['rows'] += DB::table($t)->where('created_at', '<', date('Y-m-d H:i:s', self::cutoff($days[$kind], $now)))->delete();
            $text = "deleted {$out['uploads']} uploads, {$out['generator']} generator files" . ($out['rows'] ? ", {$out['rows']} old rows" : '');
            DB::table('sys_job_runs')->where('id', $job)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => $out['uploads'] + $out['generator'] + $out['rows'], 'error_text' => $text]);
            return $out + ['text' => $text];
        } catch (\Throwable $e) {
            DB::table('sys_job_runs')->where('id', $job)->update(['finished_at' => now(), 'run_status' => 'FAILED', 'error_text' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
    }

    /** The last run for System › Health. */
    public static function last(): ?array
    {
        $r = DB::table('sys_job_runs')->where('job_code', 'TEMP_CLEANUP')->orderByDesc('id')->first(['started_at', 'finished_at', 'run_status', 'items_done', 'error_text']);
        return $r ? ['at' => (string) $r->started_at, 'status' => $r->run_status, 'text' => $r->error_text, 'items' => (int) $r->items_done] : null;
    }
}
