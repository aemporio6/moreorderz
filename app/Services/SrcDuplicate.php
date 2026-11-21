<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Duplicate guard: supplier + order reference (R-B_SOURCING-7).
 * An active cycle with the same order_ref (trimmed, case-insensitive) that has a PO of the same supplier = duplicate.
 * Works on the cycle form, the PO form, Direct / Full PO (createPo with a supplier PI number), Quick buy and imports.
 * "Save anyway" needs a reason; the reason goes into history as DUPLICATE_FORCED.
 */
class SrcDuplicate
{
    public static function clean(?string $ref): string { return trim((string) $ref); }

    /** The existing cycle (id, cycle_code) for this supplier + reference, or null. */
    public static function existing(int $ent, ?int $supplierId, ?string $ref, ?int $exceptCycle = null): ?object
    {
        $ref = self::clean($ref);
        if (! $supplierId || $ref === '') return null;
        return DB::table('t_src_cycles as c')->where('c.fk_id_ent', $ent)->where('c.f_active', 1)->whereRaw('TRIM(c.order_ref) = ?', [$ref])
            ->when($exceptCycle, fn ($q) => $q->where('c.id', '<>', $exceptCycle))
            ->whereExists(fn ($q) => $q->from('t_src_po as p')->whereColumn('p.fk_id_cycle', 'c.id')->where('p.fk_id_supplier', $supplierId)->where('p.f_active', 1))
            ->orderBy('c.id')->first(['c.id', 'c.cycle_code']);
    }

    /** Any supplier of the cycle's POs (or the given extra one) already has this reference on another cycle. */
    public static function forCycle(int $ent, ?int $cycleId, ?string $ref, ?int $extraSupplier = null): ?object
    {
        $sups = $cycleId ? DB::table('t_src_po')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->pluck('fk_id_supplier')->map(fn ($x) => (int) $x)->all() : [];
        if ($extraSupplier) $sups[] = $extraSupplier;
        foreach (array_unique(array_filter($sups)) as $s) if ($x = self::existing($ent, $s, $ref, $cycleId)) return $x;
        return null;
    }

    public static function message(object $x): string { return "Supplier + order reference already exists as $x->cycle_code. Save anyway?"; }

    /**
     * Stop a duplicate unless it is forced with a reason. Returns the existing cycle when it was forced (log it with forced()), null when there is none.
     * @throws SrcDuplicateException
     */
    public static function guard(?object $existing, ?string $reason): ?object
    {
        if (! $existing) return null;
        $reason = trim((string) $reason);
        if ($reason === '') throw new SrcDuplicateException($existing, self::message($existing));
        if (mb_strlen($reason) < 3) throw new SrcDuplicateException($existing, 'Say why it is saved anyway (at least 3 letters).');
        return $existing;
    }

    /** History line DUPLICATE_FORCED on the new / saved cycle. */
    public static function forced(int $cycleId, object $existing, string $reason, ?int $ent = null): void
    {
        Audit::log('t_src_cycles', $cycleId, 'DUPLICATE_FORCED', ['same supplier + order reference as' => $existing->cycle_code], trim($reason), $ent);
    }

    /** Live check for screens: "Already exists as SC-…" or nothing. */
    public static function live(int $ent, ?int $supplierId, ?string $ref, ?int $cycleId = null): array
    {
        $x = $supplierId ? self::existing($ent, $supplierId, $ref, $cycleId) : self::forCycle($ent, $cycleId, $ref);
        return $x ? ['ok' => false, 'message' => "Already exists as $x->cycle_code", 'existing' => ['id' => (int) $x->id, 'code' => $x->cycle_code]] : ['ok' => true];
    }
}
