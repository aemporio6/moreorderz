<?php

/*
 * Sourcing cycle step colours (R-B_SOURCING-16). Per step group: the tables that hold its records, the status column, and the done / bad lists.
 * Colour from the data (active rows only, f_active = 1):
 *   grey  = Not started (no active row)          hatched = SKIPPED for this cycle type (and no row)
 *   red   = Issue: any row with a status in bad   green   = Completed: every row's status in done
 *   amber = In progress: rows, not all done
 * 'done' => null means "a row is enough" (the table has no status of its own). Lists are compared upper-case with spaces as "_".
 * QC: only the latest active QC row per PO counts (a row re-inspected by a later row via re_inspection_of is replaced by it).
 */
return [
    'sourcing_steps' => [
        'rfq' => [['t_src_rfq', 'cycle', 'status', ['ACCEPTED'], []]],
        'samples' => [['t_src_samples', 'cycle', 'approval_status', ['APPROVED'], ['REJECTED']]],
        'po' => [['t_src_po', 'cycle', 'status', ['CONFIRMED', 'SENT', 'IN_PRODUCTION', 'SHIPPED', 'PARTLY_RECEIVED', 'RECEIVED', 'CLOSED'], ['DISPUTED', 'ON_HOLD']]],
        'pay' => [['t_src_payment_terms', 'po', 'status', ['PAID'], ['OVERDUE']], ['t_src_payments', 'cycle', 'status', ['PAID', 'POSTED', 'MATCHED'], ['FAILED', 'REVERSED']]],
        'production' => [['t_src_production', 'cycle', 'status', ['COMPLETED', 'DONE', 'FINISHED', 'READY'], ['DELAYED', 'ON_HOLD', 'STOPPED']]],
        'qc' => [['t_src_qc', 'cycle', 'qc_result', ['PASSED', 'PASS'], ['FAILED', 'FAIL']]],
        'allocation' => [['t_src_allocations', 'cycle', 'status', null, ['OVER_ALLOCATED']]],
        'shipment' => [['t_src_shipments', 'cycle_or_po', 'status', ['DELIVERED', 'ARRIVED'], ['DELAYED', 'LOST', 'DAMAGED']]],
        'freight' => [['t_src_freight', 'cycle', null, null, []]],
        'customs' => [['t_src_customs', 'cycle', 'status', ['CLEARED', 'RELEASED'], ['HELD', 'REJECTED', 'SEIZED']]],
        'grn' => [['t_src_grn', 'cycle', 'status', ['POSTED', 'CLOSED', 'RECEIVED'], ['DISCREPANCY']]],
        'landed_cost' => [['t_src_inventory_receipts', 'cycle', null, null, []], ['t_src_landed_cost', 'po', 'landed_status', ['FINAL'], []]],
    ],
    'colours' => ['pend' => '#6c757d', 'prog' => '#ffc107', 'done' => '#28a745', 'issue' => '#dc3545'],
    'labels' => ['pend' => 'Not started', 'prog' => 'In progress', 'done' => 'Completed', 'issue' => 'Issue', 'skipped' => 'Skipped'],
];
