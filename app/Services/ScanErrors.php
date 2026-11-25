<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** R-H-59 #6: every refused scan (wrong bin, wrong item) is kept per user – written outside the refused transaction so the refusal itself never undoes it. */
class ScanErrors
{
    private static array $pending = [];

    /** $defer = true inside a transaction that is about to be refused: kept until flush() runs after it. */
    public static function add(int $ent, string $kind, ?int $ref, ?string $expected, string $scanned, bool $defer = false): void
    {
        self::$pending[] = ['fk_id_ent' => $ent, 'user_id' => (int) (Context::userId() ?? 0), 'kind' => $kind, 'ref_id' => $ref, 'expected' => $expected !== null ? mb_substr($expected, 0, 100) : null, 'scanned' => mb_substr($scanned, 0, 100), 'created_at' => now()];
        if (! $defer) self::flush();
    }

    public static function flush(): void
    {
        if (! self::$pending) return;
        $rows = self::$pending; self::$pending = [];
        DB::table('wh_scan_errors')->insert($rows);
    }
}
