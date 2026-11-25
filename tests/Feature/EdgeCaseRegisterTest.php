<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * R-H-60 edge-case register (RULES_H §22): every case of the brief §16 → the tests that prove it.
 * A case in GAPS has no test yet – it is listed honestly so nobody counts it as done. When a test is added, move the case from GAPS to CASES.
 */
class EdgeCaseRegisterTest extends TestCase
{
    public const CASES = [
        'Receiving more / less than ordered; damaged on arrival' => ['StockTest::test_damaged_on_arrival_is_kept_apart_and_opens_a_claim_task', 'SourcingAlertsTest::test_the_rule_example_grn_short_and_qc_failed_close_by_the_worker', 'HistoricalPurchaseTest::test_r10_receiving_section'],
        'SKU in several bins; bin emptied to zero' => ['SalesDispatchTest::test_short_pick_puts_the_rest_back_in_the_queue_and_asks_for_a_bin_count', 'CountTriggersTest::test_the_rule_example_pick_empties_w1_a_2_answer_no_makes_a_triggered_line'],
        'Order for a SKU with no stock' => ['SalesDispatchTest::test_short_at_intake_reserves_what_there_is_and_fills_itself_when_stock_arrives'],
        'Item not at the expected bin' => ['SalesDispatchTest::test_short_pick_puts_the_rest_back_in_the_queue_and_asks_for_a_bin_count'],
        'Duplicate scans; scanning the wrong barcode' => ['SalesDispatchTest::test_order_from_intake_to_month_confirm_with_stock_and_money_right_at_every_stop', 'ReturnGradesTest::test_r39_sku_scan_lists_lines_to_choose'],
        'Partially completed / abandoned pick lists' => ['SalesDispatchTest::test_abandoned_pick_list_releases_unpicked_lines_and_keeps_picked_units_in_dispatch'],
        'Label reprint' => ['PrintHttpTest::test_printers_screen_test_page_route_print_button_queue_reprint_and_roles', 'SalesDispatchTest::test_abandoned_pick_list_releases_unpicked_lines_and_keeps_picked_units_in_dispatch'],
        'Lost parcel; packed but not handed over by collection time' => ['LostParcelsTest::test_the_rule_example_handed_over_10_days_claim_7_paid_12_and_resend'],
        'Wrong / duplicate parcel at handover' => ['SalesDispatchTest::test_order_from_intake_to_month_confirm_with_stock_and_money_right_at_every_stop'],
        'Refund without return' => ['SaleLinesTest::test_refund_of_a_pending_line_with_the_nature_of_the_loss', 'Money2Test::test_refund_proposal_accept_books_the_refund_and_reject_needs_a_reason'],
        'Marketplace order edits (intake never doubles a line)' => ['SalesDispatchTest::test_intake_matches_the_product_and_price_period_reserves_and_never_makes_a_line_twice'],
        'API outage mid-sync; idempotent intake; retry' => ['SalesImportTest::test_no_sku_line_gets_a_stable_key_and_a_reimport_is_a_duplicate', 'EngineTest::test_failed_listener_leaves_the_event_for_retry', 'ConnectTest::test_keys_refused_marks_the_connection_and_opens_one_task'],
        'Changed listing SKUs; relisted items' => ['SalesDispatchTest::test_listing_sku_with_pack_quantity_reserves_the_real_units'],
        'Pool / investor / partner stock on one shelf' => ['StockTest::test_private_sale_takes_only_its_owner_and_pool_sale_never_takes_private_lots'],
        'Transferring ownership' => ['StockTest::test_ownership_transfer_splits_the_lot_and_keeps_the_fifo_date'],
        'Multipacks, bundles, variants' => ['ListingsMoneyFilesTest::test_h19_bundle_reserves_each_component_and_costs_the_sum', 'SalesDispatchTest::test_listing_sku_with_pack_quantity_reserves_the_real_units'],
        'Time zones, dispatch deadlines, bank holidays' => ['DispatchDeadlineTest::test_the_rule_example_friday_15_00_after_cutoff_monday_holiday_is_tuesday'],
        'Two users on the same stock; browser refresh mid-scan' => ['StockTest::test_two_pickers_at_the_same_moment_never_take_the_same_units', 'SalesDispatchTest::test_abandoned_pick_list_releases_unpicked_lines_and_keeps_picked_units_in_dispatch'],
    ];

    /** No test yet (R-H-60 stays PARTIAL until this list is empty). */
    public const GAPS = [
        'Wrong item; unlabelled cartons; mixed SKUs in one carton (receiving)',
        'Moving a SKU\'s home while orders are reserved',
        'Stock found physically but not in the system',
        'Order cancelled after picking (putback)',
        'Address change after label print',
        'Carrier takes fewer parcels than scanned',
        'Combined shipping (several orders, one parcel)',
        'Owner running out while another owner has stock',
        'SKUs retired but holding stock',
    ];

    public function test_every_edge_case_points_at_a_test_that_exists(): void
    {
        $this->assertSame(27, count(self::CASES) + count(self::GAPS), 'the register covers every case of RULES_H §22 (some rows split in two)');
        foreach (self::CASES as $case => $tests) foreach ($tests as $t) {
            [$class, $method] = explode('::', $t);
            $this->assertTrue(method_exists('Tests\\Feature\\' . $class, $method), "$case → $t does not exist");
        }
        $this->assertSame([], array_intersect(array_keys(self::CASES), self::GAPS));
    }
}
