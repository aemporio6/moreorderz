<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dispatch deadline and priority (R-H-24, decision W-5).
 * dispatch_by: from the marketplace file / API when given; else order time + the marketplace profile's handling days counted in working days of the
 * site's country calendar (weekends WH_WEEKEND_DAYS + cal_non_working_days loaded per country – no dates in code), with the profile's dispatch_cutoff_time
 * (orders after the cut-off count from the next working day). The start day is day 1; the deadline is the end of the last handling day.
 * Orders board priority: overdue → due today before the carrier collection time → due today → later; then express first; then order time.
 */
class DispatchDeadline
{
    /** The country of the site that ships for the shop (its main location, else the company country). */
    public static function countryOf(int $ent, ?int $shop): ?int
    {
        $loc = $shop ? DB::table('t_branches')->where('id', $shop)->value('fk_id_main_location') : null;
        if ($loc) { $l = DB::table('cat_inventory_locations')->where('id', $loc)->first(['fk_id_country', 'fk_id_site']);
            if ($l && $l->fk_id_country) return (int) $l->fk_id_country;
            if ($l && $l->fk_id_site && ($c = DB::table('cat_inventory_locations')->where('id', $l->fk_id_site)->value('fk_id_country'))) return (int) $c; }
        $code = strtoupper((string) (Settings::get('COMPANY_COUNTRY', $ent) ?: 'GB'));
        return ($id = DB::table('cat_country')->whereIn(DB::raw('UPPER(iso_code)'), [$code, $code === 'GB' ? 'UK' : $code])->value('id')) ? (int) $id : null;
    }

    public static function working(int $ent, ?int $country, string $day): bool
    {
        $weekend = array_map('intval', array_filter(explode(',', (string) (Settings::get('WH_WEEKEND_DAYS', $ent) ?: '6,7'))));
        if (in_array((int) date('N', strtotime($day)), $weekend, true)) return false;
        return ! ($country && DB::table('cal_non_working_days')->where('fk_id_country', $country)->where('d_day', $day)->where(fn ($w) => $w->whereNull('fk_id_ent')->orWhere('fk_id_ent', 0)->orWhere('fk_id_ent', $ent))->exists());
    }

    private static function nextWorking(int $ent, ?int $country, string $day, bool $includeToday): string
    {
        $d = $includeToday ? $day : date('Y-m-d', strtotime($day . ' +1 day'));
        for ($i = 0; $i < 60 && ! self::working($ent, $country, $d); $i++) $d = date('Y-m-d', strtotime($d . ' +1 day'));
        return $d;
    }

    /** dispatch_by for an order placed at $orderAt (Y-m-d H:i[:s]) in a shop. Null when the shop has no profile with handling days. */
    public static function compute(int $ent, int $shop, string $orderAt): ?string
    {
        $prof = DB::table('t_branches as b')->leftJoin('mp_profiles as p', 'p.id', '=', 'b.fk_id_mp_profile')->where('b.id', $shop)->first(['p.handling_days', 'p.dispatch_cutoff_time']);
        if (! $prof || $prof->handling_days === null) return null;
        $country = self::countryOf($ent, $shop); $day = substr($orderAt, 0, 10); $time = strlen($orderAt) > 10 ? substr($orderAt, 11, 8) : '00:00:00';
        $after = $prof->dispatch_cutoff_time && strcmp(str_pad($time, 8, ':00'), substr((string) $prof->dispatch_cutoff_time, 0, 8)) > 0;
        $d = $after || ! self::working($ent, $country, $day) ? self::nextWorking($ent, $country, $day, false) : $day;
        for ($n = 1; $n < max(1, (int) $prof->handling_days); $n++) $d = self::nextWorking($ent, $country, $d, false);
        return $d . ' 23:59:59';
    }

    /** Board priority: 0 overdue, 1 due today before the collection time, 2 due today, 3 later / none. */
    public static function bucket(?string $dispatchBy, ?string $cutoff, ?string $now = null): int
    {
        $now ??= date('Y-m-d H:i:s');
        if (! $dispatchBy) return 3;
        if ($dispatchBy < $now) return 0;
        if (substr($dispatchBy, 0, 10) !== substr($now, 0, 10)) return 3;
        return $cutoff && substr($now, 11, 8) < substr($cutoff, 0, 8) ? 1 : 2;
    }

    /** Sort board rows: bucket, then express first, then order time. */
    public static function sort(array $rows, ?string $now = null): array
    {
        $cut = DB::table('t_branches as b')->leftJoin('t_carriers as c', 'c.id', '=', 'b.fk_id_default_carrier')->pluck('c.collection_cutoff_time', 'b.id');
        $labels = ['Overdue', 'Due today before collection', 'Due today', 'Later'];
        foreach ($rows as &$r) { $r = (array) $r; $b = self::bucket($r['dispatch_by'] ?? null, $cut[$r['fk_id_branch']] ?? null, $now); $r['priority'] = $b; $r['priority_label'] = $labels[$b];
            $r['express'] = (bool) preg_match('/express|next.?day|24/i', (string) ($r['carrier_service_text'] ?? '')); }
        unset($r);
        usort($rows, fn ($a, $b) => [$a['priority'], ! $a['express'], $a['order_at'] ?? $a['d_order'], $a['id']] <=> [$b['priority'], ! $b['express'], $b['order_at'] ?? $b['d_order'], $b['id']]);
        return $rows;
    }
}
