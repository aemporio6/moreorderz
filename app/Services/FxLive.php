<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Owner 27-11 09:33: exchange rates come in by themselves from an authoritative live source – no typing, no key.
 * Source: the European Central Bank daily reference rates (about 30 currencies incl. CNY, USD, EUR, GBP, MYR; published each working day
 * about 16:00 CET). Turned into "units of the base currency for 1 unit" and saved per date in fin_fx_rates (source ECB).
 * A rate typed by hand for a date is never overwritten. Every money row keeps (locks) the rate of its own date – a later rate never
 * changes an earlier stock lot, payment or investment; the next one takes the next rate, and the difference shows.
 */
class FxLive
{
    public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    public const WATCH = ['CNY', 'USD', 'EUR'];

    /** Fetch and save today's rates. Returns ['date' => , 'saved' => n, 'kept' => n (typed by hand)] – never throws. */
    public static function pull(?string $xml = null): array
    {
        try {
            $xml ??= (string) Http::timeout(20)->retry(2, 2000)->get(self::URL)->throw()->body();
            if (! preg_match("/time=['\"](\d{4}-\d{2}-\d{2})['\"]/", $xml, $d)) return ['ok' => false, 'error' => 'No date in the rate file'];
            preg_match_all("/currency=['\"]([A-Z]{3})['\"]\s+rate=['\"]([0-9.]+)['\"]/", $xml, $m, PREG_SET_ORDER);
            $perEur = ['EUR' => 1.0]; foreach ($m as $x) if ((float) $x[2] > 0) $perEur[$x[1]] = (float) $x[2];
            $base = Fx::base(); if (! isset($perEur[$base])) return ['ok' => false, 'error' => "The base currency $base is not in the rate file"];
            $saved = 0; $kept = 0;
            foreach ($perEur as $cur => $r) {
                if ($cur === $base) continue;
                $row = DB::table('fin_fx_rates')->where('rate_date', $d[1])->where('currency', $cur)->first();
                if ($row && strtoupper((string) $row->source) !== 'ECB') { $kept++; continue; }      // typed by hand wins
                Fx::set($cur, $d[1], round($perEur[$base] / $r, 6), 'ECB'); $saved++;
            }
            Settings::set('FX_LIVE_LAST', now()->toDateTimeString() . ' · ' . $d[1], 0);
            return ['ok' => true, 'date' => $d[1], 'saved' => $saved, 'kept' => $kept];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** The thin strip on the top bar: 1 CNY = x GBP, 1 USD = …, with the date and the move since the rate before. */
    public static function strip(?int $ent = null): array
    {
        $base = Fx::base($ent); $out = [];
        foreach (self::WATCH as $c) { if ($c === $base) continue;
            $two = DB::table('fin_fx_rates')->where('currency', $c)->orderByDesc('rate_date')->limit(2)->get(['rate_date', 'rate_to_gbp', 'source']);
            if (! count($two)) continue; $now = (float) $two[0]->rate_to_gbp; $prev = isset($two[1]) ? (float) $two[1]->rate_to_gbp : null;
            $out[] = ['cur' => $c, 'rate' => $now, 'per_base' => $now > 0 ? round(1 / $now, 4) : null, 'date' => (string) $two[0]->rate_date, 'source' => (string) $two[0]->source,
                'move' => $prev ? round(($now - $prev) / $prev * 100, 2) : null]; }
        return ['base' => $base, 'rates' => $out];
    }
}
