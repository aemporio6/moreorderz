<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * MR-0095 / MR-1543 backups: a daily database dump (gzip) under storage/app/backups, 14 daily + 12 monthly kept (RPO 24 h);
 * a monthly restore drill reads the newest dump end to end and compares its tables with the live database – the result is a run line
 * (BACKUP / BACKUP_DRILL in sys_job_runs) shown on System › Health. The database password goes to mysqldump through its environment, never on the command line or in a file.
 * Copying the files off the server (offsite) is a server step – docs/GO_LIVE_ITEMS.md.
 */
class Backups
{
    public static function dir(): string { $d = storage_path('app/backups'); if (! is_dir($d)) mkdir($d, 0750, true); return $d; }

    /** Make today's dump; returns the file name. */
    public static function make(?string $day = null): string
    {
        $c = config('database.connections.' . config('database.default'));
        $file = self::dir() . '/mo-' . ($day ?? date('Ymd-His')) . '.sql.gz';
        $p = new Process(['bash', '-c', 'set -o pipefail; mysqldump --single-transaction --quick --routines --no-tablespaces -h "$H" -P "$PORT" -u "$U" "$DB" | gzip -6 > "$OUT"'], null,
            ['H' => $c['host'], 'PORT' => (string) ($c['port'] ?? 3306), 'U' => $c['username'], 'DB' => $c['database'], 'MYSQL_PWD' => (string) $c['password'], 'OUT' => $file]);
        $p->setTimeout(1800); $p->run();
        if (! $p->isSuccessful() || ! is_file($file) || filesize($file) < 100) { @unlink($file); throw new \RuntimeException('Backup failed: ' . mb_substr(trim($p->getErrorOutput()) ?: 'empty dump', 0, 300)); }
        @chmod($file, 0640);
        self::prune();
        return basename($file);
    }

    /** Keep the newest 14 dumps and the first dump of each of the last 12 months. */
    public static function prune(): int
    {
        $files = self::files(); $keep = array_slice(array_column($files, 'name'), 0, 14); $months = [];
        foreach (array_reverse($files) as $f) { $m = substr($f['date'], 0, 7); if (! isset($months[$m])) $months[$m] = $f['name']; }
        $keep = array_merge($keep, array_slice(array_values(array_reverse($months, true)), 0, 12)); $n = 0;
        foreach ($files as $f) if (! in_array($f['name'], $keep, true)) { @unlink(self::dir() . '/' . $f['name']); $n++; }
        return $n;
    }

    /** Dumps newest first. */
    public static function files(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/mo-*.sql.gz') ?: [] as $p) $out[] = ['name' => basename($p), 'bytes' => filesize($p), 'date' => date('Y-m-d H:i', filemtime($p))];
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Restore drill: the newest dump is read end to end (gzip intact, it ends with the dump-complete line) and its tables are compared with the live ones. */
    public static function drill(): array
    {
        $f = self::files()[0] ?? null; if (! $f) throw new \RuntimeException('Restore drill: there is no backup to test.');
        $fh = gzopen(self::dir() . '/' . $f['name'], 'r'); if (! $fh) throw new \RuntimeException('Restore drill: the backup cannot be opened.');
        $tables = []; $rows = 0; $last = '';
        while (($line = gzgets($fh, 1 << 20)) !== false) {
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) $tables[] = $m[1];
            elseif (str_starts_with($line, 'INSERT INTO')) $rows++;
            if (trim($line) !== '') $last = $line;
        }
        $ok = gzeof($fh); gzclose($fh);
        $live = array_map(fn ($t) => (string) array_values((array) $t)[0], \Illuminate\Support\Facades\DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        $missing = array_values(array_diff($live, $tables));
        $complete = $ok && str_contains($last, 'Dump completed');
        $r = ['file' => $f['name'], 'tables' => count($tables), 'live_tables' => count($live), 'missing' => array_slice($missing, 0, 20), 'insert_blocks' => $rows, 'complete' => $complete, 'passed' => $complete && ! $missing];
        if (! $r['passed']) throw new \RuntimeException('Restore drill failed for ' . $f['name'] . ': ' . (! $complete ? 'the dump is cut off' : count($missing) . ' table(s) missing (' . implode(', ', array_slice($missing, 0, 5)) . ')'));
        return $r;
    }

    /** System › Health: last backup, last drill, files kept. */
    public static function status(): array
    {
        $q = fn (string $c) => \Illuminate\Support\Facades\DB::table('sys_job_runs')->where('job_code', $c)->orderByDesc('id')->first(['started_at', 'run_status', 'error_text']);
        $b = $q('BACKUP'); $d = $q('BACKUP_DRILL'); $files = self::files();
        $warn = [];
        if (! $b || $b->run_status !== 'OK' || strtotime((string) $b->started_at) < time() - 26 * 3600) $warn[] = $b ? 'The last backup is older than a day or failed.' : 'No backup has been made yet.';
        if (! $d || $d->run_status !== 'OK' || strtotime((string) $d->started_at) < time() - 35 * 86400) $warn[] = $d ? 'The monthly restore drill is late or failed.' : 'No restore drill yet.';
        return ['last_backup' => $b, 'last_drill' => $d, 'files' => array_slice($files, 0, 30), 'kept' => count($files), 'bytes' => array_sum(array_column($files, 'bytes')), 'warnings' => $warn];
    }
}
