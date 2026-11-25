<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-33 live formulas of the step forms: read-only fields worked out on the server from the row itself, on every save
 * (whatever the form posted for them is replaced). The same function is used by the form preview (formulas endpoint).
 */
class StepFormulas
{
    /** table → the computed columns (shown read-only on the form). */
    public const COMPUTED = [
        't_src_grn' => ['shortage_qty', 'damage_pct'], 't_src_grn_lines' => ['missing_qty'], 't_src_production' => ['accepted_qty', 'variance_qty', 'progress_pct'], 't_src_qc' => ['defect_rate'],
        't_src_transfers' => ['qty_in_transit'], 't_src_customs' => ['duty_amount'], 't_src_po_lines' => ['line_total'],
    ];

    /** the columns the formulas read: a save that touches none of them leaves the computed columns as they are */
    public const INPUTS = ['t_src_grn' => ['expected_units', 'units_received', 'damaged_units'], 't_src_grn_lines' => ['expected_qty', 'received_qty'], 't_src_production' => ['produced_qty', 'rejected_qty', 'planned_qty', 'fk_id_po'],
        't_src_qc' => ['qty_inspected', 'qty_failed'], 't_src_transfers' => ['qty_dispatched', 'qty_received'], 't_src_customs' => ['customs_value', 'duty_rate'], 't_src_po_lines' => ['qty_ordered', 'unit_price', 'discount', 'other_charges']];

    private static function n(array $r, string $k): float { return (float) ($r[$k] ?? 0); }

    /** The computed values of a row (the posted values + what is stored). */
    public static function of(string $table, array $r): array
    {
        $n = fn ($k) => self::n($r, $k);
        switch ($table) {
            case 't_src_grn': return ['shortage_qty' => max(0.0, $n('expected_units') - $n('units_received')), 'damage_pct' => $n('units_received') > 0 ? round($n('damaged_units') / $n('units_received') * 100, 2) : 0.0];
            case 't_src_grn_lines': return ['missing_qty' => max(0.0, $n('expected_qty') - $n('received_qty'))];
            case 't_src_production':
                $ordered = isset($r['fk_id_po']) && $r['fk_id_po'] ? (float) DB::table('t_src_po_lines')->where('fk_id_po', $r['fk_id_po'])->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhere('line_type', 'PRODUCT'))->sum('qty_ordered') : 0.0;
                return ['accepted_qty' => $n('produced_qty') - $n('rejected_qty'), 'variance_qty' => $n('produced_qty') - $n('planned_qty'), 'progress_pct' => $ordered > 0 ? round($n('produced_qty') / $ordered * 100, 2) : 0.0];
            case 't_src_qc': return ['defect_rate' => $n('qty_inspected') > 0 ? round($n('qty_failed') / $n('qty_inspected') * 100, 2) : 0.0];
            case 't_src_transfers': return ['qty_in_transit' => max(0.0, $n('qty_dispatched') - $n('qty_received'))];
            case 't_src_customs': return ['duty_amount' => round($n('customs_value') * $n('duty_rate') / 100, 2)];
            case 't_src_po_lines': return ['line_total' => round(PoLines::amount($r['qty_ordered'] ?? 0, $r['unit_price'] ?? 0) - $n('discount') + $n('other_charges'), 2)];
        }
        return [];
    }

    /** RecordService: the computed columns of the saved row, from posted + stored values (only columns the table has). */
    public static function apply(string $table, array $data, array $old): array
    {
        if (! isset(self::COMPUTED[$table]) || (! array_intersect(array_keys($data), self::INPUTS[$table]) && $old)) return $data;
        foreach (self::of($table, $data + $old) as $k => $v) if (Registry::hasColumn($table, $k)) $data[$k] = $v;
        return $data;
    }
}
