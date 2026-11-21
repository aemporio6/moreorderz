<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shared period component (R-F_SETUP-27) used by dashboard cards and reports: Today, This Week (rolling 7 days), This Month, Last Month, Last Three Months,
 * Last 6 Months, This Year, Custom range (From / To; a From not in yyyy-mm-dd → 1st of this month, a To → today; From > To swapped). Unknown value = This Month.
 * Defaults per card (Sales / Invoicing / Buying = This Month, Investors = This Year, Team = Today); the last choice is remembered per user per card.
 */
class Period
{
    public const OPTIONS = ['TODAY' => 'Today', 'THIS_WEEK' => 'This Week', 'THIS_MONTH' => 'This Month', 'LAST_MONTH' => 'Last Month', 'LAST_3_MONTHS' => 'Last Three Months', 'LAST_6_MONTHS' => 'Last 6 Months', 'THIS_YEAR' => 'This Year', 'CUSTOM' => 'Custom Range/Date'];
    public const DEFAULTS = ['sales' => 'THIS_MONTH', 'invoicing' => 'THIS_MONTH', 'buying' => 'THIS_MONTH', 'investors' => 'THIS_YEAR', 'team' => 'TODAY'];

    private static function ymd($v): ?string { $v = (string) $v; $d = \DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : null; }

    /** [key, label, from, to] – both dates inclusive. */
    public static function resolve(?string $key, ?string $from = null, ?string $to = null, ?string $today = null): array
    {
        $t = new \DateTimeImmutable($today ?: date('Y-m-d')); $d = fn (\DateTimeImmutable $x) => $x->format('Y-m-d');
        $key = strtoupper((string) $key); if (! isset(self::OPTIONS[$key])) $key = 'THIS_MONTH';
        [$f, $e] = match ($key) {
            'TODAY' => [$t, $t], 'THIS_WEEK' => [$t->modify('-7 days'), $t], 'THIS_MONTH' => [$t->modify('first day of this month'), $t],
            'LAST_MONTH' => [$t->modify('first day of last month'), $t->modify('last day of last month')], 'LAST_3_MONTHS' => [$t->modify('-3 months'), $t],
            'LAST_6_MONTHS' => [$t->modify('-6 months'), $t], 'THIS_YEAR' => [$t->setDate((int) $t->format('Y'), 1, 1), $t],
            'CUSTOM' => [new \DateTimeImmutable(self::ymd($from) ?? $d($t->modify('first day of this month'))), new \DateTimeImmutable(self::ymd($to) ?? $d($t))],
        };
        if ($f > $e) [$f, $e] = [$e, $f];
        return ['key' => $key, 'label' => self::OPTIONS[$key], 'from' => $d($f), 'to' => $d($e)];
    }

    /** The period of a card for a user: the one asked for (remembered), else the last one, else the card's default. */
    public static function forCard(?int $userId, string $card, ?string $key = null, ?string $from = null, ?string $to = null): array
    {
        $pk = 'period:' . $card;
        if ($key !== null && $key !== '') {
            $p = self::resolve($key, $from, $to);
            if ($userId) DB::table('t_user_column_prefs')->updateOrInsert(['fk_id_user' => $userId, 'page_key' => $pk], ['prefs_json' => json_encode(['key' => $p['key'], 'from' => $p['from'], 'to' => $p['to']]), 'hidden_columns' => '', 'updated_at' => now()]);
            return $p + ['options' => self::OPTIONS];
        }
        $last = $userId ? json_decode((string) DB::table('t_user_column_prefs')->where('fk_id_user', $userId)->where('page_key', $pk)->value('prefs_json'), true) : null;
        return ($last ? self::resolve($last['key'] ?? null, $last['from'] ?? null, $last['to'] ?? null) : self::resolve(self::DEFAULTS[$card] ?? 'THIS_MONTH')) + ['options' => self::OPTIONS];
    }
}
