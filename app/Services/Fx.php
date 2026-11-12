<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** One source for exchange rates (fin_fx_rates: units of base currency for 1 unit of the currency, by date). */
class Fx
{
    public static function base(?int $ent = null): string
    {
        return (string) (Settings::get('BASE_CURRENCY', $ent, 'GBP') ?: 'GBP');
    }

    /** Rate to the base currency on a date: the latest rate on or before it. Same currency = 1. No rate = a clear error. */
    public static function rate(string $currency, string $date, ?int $ent = null): float
    {
        $currency = strtoupper($currency);
        if ($currency === self::base($ent)) return 1.0;
        $r = DB::table('fin_fx_rates')->where('currency', $currency)->where('rate_date', '<=', $date)->orderByDesc('rate_date')->value('rate_to_gbp');
        if (! $r) throw new \RuntimeException("No exchange rate for $currency on or before $date. Add it under Money › FX rates.");
        return (float) $r;
    }

    public static function toBase(float $amount, string $currency, string $date, ?int $ent = null): float
    {
        return round($amount * self::rate($currency, $date, $ent), 2);
    }

    public static function code(?int $currencyId, ?int $ent = null): string
    {
        return $currencyId ? strtoupper((string) (DB::table('cat_currency')->where('id', $currencyId)->value('currency_code') ?: self::base($ent))) : self::base($ent);
    }

    public static function set(string $currency, string $date, float $rate, string $source = 'MANUAL'): void
    {
        if ($rate <= 0) throw new \InvalidArgumentException('A rate must be more than zero.');
        DB::table('fin_fx_rates')->updateOrInsert(['rate_date' => $date, 'currency' => strtoupper($currency)], ['rate_to_gbp' => $rate, 'source' => $source]);
    }
}
