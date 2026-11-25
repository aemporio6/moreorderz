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
        if (! $r) { FxMissing::note($currency, $date, $ent); throw new \RuntimeException("No exchange rate for $currency on or before $date. Add it under Money › FX rates."); }      // R-FIN-26: never 1.0 – a task Add rate
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

    /** R-B_SOURCING-32 the line under a money row's rate: "Rate table x · difference y (base z)". The typed bank / Wise rate wins; base = round(amount × rate, 2). */
    public static function rateNote(string $currency, string $date, float $amount, ?float $typed = null, ?int $ent = null): array
    {
        $table = self::rate($currency, $date, $ent); $used = $typed && $typed > 0 ? $typed : $table;
        $base = round($amount * $used, 2); $diff = round($base - round($amount * $table, 2), 2);
        return ['table' => $table, 'rate' => $used, 'base' => $base, 'difference' => $diff, 'text' => 'Rate table ' . rtrim(rtrim(number_format($table, 6, '.', ''), '0'), '.') . ' · difference ' . number_format($diff, 2) . ' (base ' . number_format($base, 2) . ')'];
    }

    /** R-B_SOURCING-32 a money row saved by a form: an empty rate takes the rate table of the row's date (never 1.0 by guess), base = round(amount × rate, 2). */
    public const MONEY_ROWS = ['t_src_payments' => ['payment_amount', 'payment_date'], 't_src_freight_costs' => ['amount', 'cost_date'], 't_src_investments' => ['invest_amount', 'invest_date']];
    public static function fillRow(string $table, array $data, array $old, ?int $ent = null): array
    {
        if (! isset(self::MONEY_ROWS[$table])) return $data; [$amtCol, $dateCol] = self::MONEY_ROWS[$table]; $row = $data + $old;
        if (! array_key_exists($amtCol, $data) && ! array_key_exists('exchange_rate', $data) && ! array_key_exists('fk_id_currency', $data) && ! array_key_exists($dateCol, $data)) return $data;      // nothing that moves the base was changed
        $amt = (float) ($row[$amtCol] ?? 0); $cur = self::code(isset($row['fk_id_currency']) ? (int) $row['fk_id_currency'] : null, $ent); $date = (string) ($row[$dateCol] ?? '') ?: date('Y-m-d');
        $typed = array_key_exists('exchange_rate', $data) ? (float) $data['exchange_rate'] : null;
        $rate = $typed && $typed > 0 ? $typed : self::rate($cur, $date, $ent);
        return array_merge($data, ['exchange_rate' => $rate, 'base_amount' => round($amt * $rate, 2)]);
    }

    public static function set(string $currency, string $date, float $rate, string $source = 'MANUAL'): void
    {
        if ($rate <= 0) throw new \InvalidArgumentException('A rate must be more than zero.');
        DB::table('fin_fx_rates')->updateOrInsert(['rate_date' => $date, 'currency' => strtoupper($currency)], ['rate_to_gbp' => $rate, 'source' => $source]);
        FxMissing::answered($currency, $date);
    }
}
