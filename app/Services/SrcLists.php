<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-52 the sourcing lists (cat_src_lov): values that the rules rely on are system values – they must exist and cannot be
 * deleted or moved to another list (renaming the label is allowed). ensure() adds a missing one and says which it added.
 */
class SrcLists
{
    public const SYSTEM = ['PRIORITY' => ['Normal'], 'SOURCING_STATUS' => ['Draft', 'Open', 'Completed'], 'ALLOCATION_STATUS' => ['Completed'], 'CUSTOMS_STATUS' => ['Cleared', 'Held'],
        'COST_TYPE' => ['Ocean Freight', 'Agent Fee', 'Other'], 'RECEIVING_QC_STATUS' => ['Accepted'], 'POSTING_STATUS' => ['Posted', 'Pending Approval'], 'LANDING_STATUS' => ['Completed', 'Closed', 'Received', 'Discrepancy'],
        'INVENTORY_STATUS' => ['Posted', 'Pending Approval', 'Available'], 'INVESTMENT_STATUS' => ['Received'], 'TERM_STATUS' => ['Pending', 'Partially Paid', 'Paid'], 'PAYMENT_TYPE' => ['Deposit', 'Balance', 'Final'],
        'PAYMENT_STATUS' => ['Paid', 'Pending'], 'PLATFORM_ALLOC_STATUS' => ['Fulfilled', 'Damaged', 'Quarantine'], 'PO_STATUS' => ['Confirmed', 'Completed'], 'PRODUCTION_STATUS' => ['Completed', 'Delayed'],
        'QC_RESULT' => ['Passed', 'Failed'], 'RFQ_STATUS' => ['Sent', 'Accepted'], 'SAMPLE_STATUS' => ['Approved', 'Rejected'], 'SHIPPING_MODE' => ['Sea', 'Air', 'Rail', 'Road'],
        'SHIPMENT_STATUS' => ['In Transit', 'Arrived', 'Delivered', 'Delayed'], 'TRANSFER_STATUS' => ['Received', 'Cancelled']];

    public static function isSystem(string $group, string $value): bool { return in_array(mb_strtolower(trim($value)), array_map('mb_strtolower', self::SYSTEM[strtoupper(trim($group))] ?? []), true); }

    /** Adds the missing system values of a company (flagged f_system) and flags the ones already there. Returns "GROUP: value" of each added one. */
    public static function ensure(int $ent): array
    {
        $added = [];
        foreach (self::SYSTEM as $g => $vals) foreach ($vals as $i => $v) {
            $row = DB::table('cat_src_lov')->where('fk_id_ent', $ent)->where('lov_group', $g)->whereRaw('LOWER(lov_value) = ?', [mb_strtolower($v)])->first();
            if ($row) { if (! (int) $row->f_system || ! (int) $row->f_active) DB::table('cat_src_lov')->where('id', $row->id)->update(['f_system' => 1, 'f_active' => 1]); continue; }
            DB::table('cat_src_lov')->insert(RecordService::fillNotNull('cat_src_lov', ['fk_id_ent' => $ent, 'lov_group' => $g, 'lov_value' => $v, 'lov_order' => 900 + $i, 'f_active' => 1, 'f_system' => 1, 'created_at' => now()]));
            $added[] = "$g: $v";
        }
        return $added;
    }

    /** The form may rename a system value, never delete it (inactive) or move it to another list. */
    public static function guard(array $data, array $old): ?string
    {
        if (empty($old) || ! (int) ($old['f_system'] ?? 0)) return null;
        if (array_key_exists('f_active', $data) && ! (int) $data['f_active']) return 'This value is used by the rules – it cannot be deleted. You may rename it.';
        if (array_key_exists('lov_group', $data) && strtoupper((string) $data['lov_group']) !== strtoupper((string) $old['lov_group'])) return 'This value is used by the rules – it stays in its list. You may rename it.';
        return null;
    }
}
