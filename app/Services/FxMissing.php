<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-26: a missing exchange rate is never 1.0 – Fx::rate refuses, and a task "Add rate <CCY> <date>" (FIN_FX_MISSING:<ccy>:<date>)
 * goes to Finance. The lookup usually runs inside a transaction that the refusal rolls back, so the task is noted and opened after it
 * (straight away when no transaction is open, else when the request ends). Adding a rate closes every open task of that currency on or after its date.
 */
class FxMissing
{
    private static array $pending = [];
    private static bool $hooked = false;

    public static function note(string $ccy, string $date, ?int $ent): void
    {
        self::$pending[strtoupper($ccy) . '|' . $date] = [strtoupper($ccy), $date, $ent ?? (int) Context::ent()];
        if (DB::transactionLevel() === 0) { self::flush(); return; }
        if (! self::$hooked && ! app()->runningUnitTests()) { self::$hooked = true; app()->terminating(fn () => self::flush()); }
    }

    /** Open the noted tasks (outside the transaction that failed). */
    public static function flush(): int
    {
        $n = 0;
        foreach (self::$pending as [$ccy, $date, $ent]) {
            if (DB::table('fin_fx_rates')->where('currency', $ccy)->where('rate_date', '<=', $date)->exists()) continue;
            if (TaskEngine::open('FIN_FX_MISSING', [$ccy, $date], "Add rate $ccy $date", ['ent' => $ent, 'fix_url' => '#/fx', 'description' => "No exchange rate for $ccy on or before $date. Amounts in $ccy cannot be turned into the company currency (shown as \"rate missing\"; the month cannot close)."])) $n++;
        }
        self::$pending = [];
        return $n;
    }

    /** A rate was added: close the tasks it answers (same currency, date on or after the rate's date). */
    public static function answered(string $ccy, string $rateDate): int
    {
        $n = 0; $ccy = strtoupper($ccy);
        foreach (DB::table('tasks')->where('dedupe_key', 'like', "FIN_FX_MISSING:$ccy:%")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) {
            $d = substr((string) $k, strlen("FIN_FX_MISSING:$ccy:"));
            if ($d >= $rateDate && TaskEngine::close('FIN_FX_MISSING', [$ccy, $d], "Rate $ccy added")) $n++;
        }
        return $n;
    }

    /** Hourly: close what a rate answers now (a rate may come from an import, not only from the form). */
    public static function sweep(): int
    {
        $n = 0;
        foreach (DB::table('tasks')->where('dedupe_key', 'like', 'FIN_FX_MISSING:%')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) {
            [, $ccy, $d] = array_pad(explode(':', (string) $k, 3), 3, '');
            if ($ccy && $d && DB::table('fin_fx_rates')->where('currency', $ccy)->where('rate_date', '<=', $d)->exists() && TaskEngine::close('FIN_FX_MISSING', [$ccy, $d], "Rate $ccy added")) $n++;
        }
        return $n;
    }
}
