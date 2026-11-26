<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-87 the opening as-of date of a company and its lock. Old stock comes in as opening lots on that date (mo:import-old);
 * a GRN makes lots only after the opening balances are LOCKED, and only when it is dated on / after the as-of date (an older receipt's
 * goods are already in the opening balances). The status lists that decide what counts as received, in production and in transit are
 * Reference Register lists (Setup › Lists): GRN_POSTED_STATUSES, PO_PRODUCTION_STATUSES, TRANSIT_STATUSES.
 */
class OpeningBalances
{
    public const LISTS = [
        'GRN_POSTED_STATUSES' => ['Receipts that make lots', ['POSTED' => 'Posted', 'CONFIRMED' => 'Confirmed', 'RECEIVED' => 'Received', 'COMPLETED' => 'Completed', 'ACTIVE' => 'active']],
        'PO_PRODUCTION_STATUSES' => ['PO lines in production', ['CONFIRMED' => 'Confirmed', 'PRODUCTION' => 'Production', 'IN_PRODUCTION' => 'In Production', 'READY' => 'Ready', 'QC' => 'QC']],
        'TRANSIT_STATUSES' => ['Goods in transit', ['SHIPPED' => 'Shipped', 'IN_TRANSIT' => 'In Transit', 'AT_PORT' => 'At Port', 'CUSTOMS' => 'Customs']],
    ];

    /** Codes of a status list (upper case); the built-in values when the list was never set up. */
    public static function statuses(string $list): array
    {
        $rows = DB::table('reference_items as i')->join('cat_references as r', 'r.id', '=', 'i.fk_reference_id')->where('r.ref_code', $list)->where('i.f_active', 1)->pluck('i.item_code')->all();
        $codes = array_values(array_unique(array_filter(array_map(fn ($x) => strtoupper(str_replace(' ', '_', trim((string) $x))), $rows))));
        return $codes ?: array_keys(self::LISTS[$list][1] ?? []);
    }

    public static function asOf(int $ent): ?string { $d = (string) Settings::get('OPENING_AS_OF_DATE', $ent, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null; }
    public static function locked(int $ent): bool { return Settings::get('OPENING_LOCKED', $ent, 'N') === 'Y'; }

    public static function state(int $ent): array
    {
        $d = self::asOf($ent);
        return ['as_of' => $d, 'locked' => self::locked($ent), 'opening_lots' => (int) DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->count(),
            'opening_value' => round((float) DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->sum(DB::raw('qty * unit_cost_gbp')), 2),
            'lists' => collect(self::LISTS)->map(fn ($x, $k) => ['code' => $k, 'name' => $x[0], 'values' => self::statuses($k)])->values()->all()];
    }

    /** The as-of date can change until the balances are locked. */
    public static function setAsOf(int $ent, string $date, object $u): array
    {
        Perm::need($u, 'money.approve');
        if (self::locked($ent)) throw new \InvalidArgumentException('The opening balances are locked – the as-of date cannot change any more.');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) throw new \InvalidArgumentException('Enter the as-of date (year-month-day).');
        $old = self::asOf($ent); Settings::set('OPENING_AS_OF_DATE', $date, $ent, (int) $u->id);
        Audit::log('sys_settings', null, 'UPDATE', ['setting' => 'OPENING_AS_OF_DATE', 'from' => $old, 'to' => $date], 'Opening balances', $ent);
        return ['message' => "Opening as-of date set to $date."] + self::state($ent);
    }

    /** Owner only, once: from now on GRNs dated on / after the as-of date make lots. */
    public static function lock(int $ent, object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner locks the opening balances.');
        if (! self::asOf($ent)) throw new \InvalidArgumentException('Set the opening as-of date first.');
        if (self::locked($ent)) throw new \InvalidArgumentException('The opening balances are already locked.');
        Settings::set('OPENING_LOCKED', 'Y', $ent, (int) $u->id);
        Audit::log('sys_settings', null, 'LOCK', ['setting' => 'OPENING_LOCKED', 'as_of' => self::asOf($ent)], 'Opening balances locked', $ent);
        return ['message' => 'Opening balances locked. Receipts dated on or after ' . self::asOf($ent) . ' now make lots.'] + self::state($ent);
    }

    /** The gate of postGrn: null = make lots; 'BEFORE' = dated before the as-of date (no lot); else the refusal text. */
    public static function gate(int $ent, string $grnDate): ?string
    {
        if (! ($d = self::asOf($ent))) return null;      // a company without an opening (no old data) works as always
        if (! self::locked($ent)) return 'Lock the opening balances first (Month close › Opening balances) – lots are made only after that.';
        return $grnDate < $d ? 'BEFORE' : null;
    }
}
