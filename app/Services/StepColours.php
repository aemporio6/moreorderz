<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Step state and colour of a sourcing cycle from the data (R-B_SOURCING-16). The lists live in config/workflows.php.
 * pend (grey) · prog (amber) · done (green) · issue (red) · skipped (hatched). Active rows only; QC = the latest active row per PO.
 */
class StepColours
{
    public static function norm(?string $s): string { return strtoupper(str_replace([' ', '-'], '_', trim((string) $s))); }

    /** Live 26-11 'slow': the step rows of many cycles read once per table (the buying dashboard read ~40 tables per cycle). */
    private static ?array $pre = null;

    public static function preload(array $cycleIds, array $poIds): void
    {
        self::$pre = []; if (! $cycleIds) return; $sch = DB::getSchemaBuilder();
        foreach (config('workflows.sourcing_steps', []) as $groups) foreach ($groups as [$table, $link, $col]) {
            if (isset(self::$pre[$table])) continue;
            $hasCy = $sch->hasColumn($table, 'fk_id_cycle'); $hasPo = $sch->hasColumn($table, 'fk_id_po');
            $q = DB::table($table)->where('f_active', 1)->where(function ($w) use ($hasCy, $hasPo, $cycleIds, $poIds) { if ($hasCy) $w->orWhereIn('fk_id_cycle', $cycleIds); if ($hasPo) $w->orWhereIn('fk_id_po', $poIds ?: [0]); });
            if ($table === 't_src_landed_cost') $q->whereNull('superseded_by');
            self::$pre[$table] = $q->orderBy('id')->get(array_values(array_unique(array_filter(['id', $col, $hasCy ? 'fk_id_cycle' : null, $hasPo ? 'fk_id_po' : null, $table === 't_src_qc' ? 're_inspection_of' : null]))));
        }
    }

    public static function forget(): void { self::$pre = null; }

    /** The statuses of the active rows of one step group. */
    public static function statuses(int $cycleId, array $poIds, string $key): array
    {
        $out = [];
        foreach (config("workflows.sourcing_steps.$key", []) as [$table, $link, $col, $done, $bad]) {
            if (self::$pre !== null && isset(self::$pre[$table])) {      // preloaded: the same filter in memory
                $po = array_flip($poIds);
                $rows = self::$pre[$table]->filter(fn ($r) => $link === 'po' ? isset($po[(int) ($r->fk_id_po ?? 0)]) : ($link === 'cycle_or_po' ? ((int) ($r->fk_id_cycle ?? 0) === $cycleId || isset($po[(int) ($r->fk_id_po ?? 0)])) : (int) ($r->fk_id_cycle ?? 0) === $cycleId))->values();
                if ($table === 't_src_qc') $rows = collect(self::latestQc($rows));
                foreach ($rows as $r) { $out[] = [$col ? self::norm($r->$col) : null, $done, $bad]; }
                continue;
            }
            $q = DB::table($table)->where('f_active', 1);
            if ($link === 'po') $q->whereIn('fk_id_po', $poIds ?: [0]);
            elseif ($link === 'cycle_or_po') $q->where(fn ($w) => $w->where('fk_id_cycle', $cycleId)->orWhereIn('fk_id_po', $poIds ?: [0]));
            else $q->where('fk_id_cycle', $cycleId);
            if ($table === 't_src_landed_cost') $q->whereNull('superseded_by');
            $rows = $q->orderBy('id')->get(array_values(array_filter(['id', $col, $table === 't_src_qc' ? 're_inspection_of' : null, $table === 't_src_qc' ? 'fk_id_po' : null])));
            if ($table === 't_src_qc') $rows = self::latestQc($rows);
            foreach ($rows as $r) { $out[] = [$col ? self::norm($r->$col) : null, $done, $bad]; }
        }
        return $out;
    }

    /** Latest active QC row per PO: a row re-inspected by a later row is dropped; without a re-inspection link the latest row of the PO wins only over rows of the same chain. */
    private static function latestQc($rows): array
    {
        $replaced = []; foreach ($rows as $r) if ($r->re_inspection_of) $replaced[(int) $r->re_inspection_of] = true;
        return array_values(array_filter($rows->all(), fn ($r) => empty($replaced[(int) $r->id])));
    }

    public static function colour(array $statuses, bool $skipped): string
    {
        if (! $statuses) return $skipped ? 'skipped' : 'pend';
        foreach ($statuses as [$s, , $bad]) if ($s !== null && in_array($s, array_map([self::class, 'norm'], $bad), true)) return 'issue';
        foreach ($statuses as [$s, $done]) if ($done !== null && ! in_array($s, array_map([self::class, 'norm'], $done), true)) return 'prog';
        return 'done';
    }

    /** key => colour for every step group of a cycle. */
    public static function forCycle(int $cycleId, array $poIds, array $skipped): array
    {
        $out = []; foreach (array_keys(config('workflows.sourcing_steps')) as $k) $out[$k] = self::colour(self::statuses($cycleId, $poIds, $k), in_array($k, $skipped, true));
        return $out;
    }

    public static function legend(): array
    {
        $c = config('workflows.colours'); $l = config('workflows.labels');
        return array_map(fn ($k) => ['key' => $k, 'label' => $l[$k], 'colour' => $c[$k] ?? null], ['pend', 'prog', 'done', 'issue', 'skipped']);
    }
}
