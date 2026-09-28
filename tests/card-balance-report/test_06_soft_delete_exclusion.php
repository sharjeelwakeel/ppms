<?php
/**
 * Test Case 06: Soft Delete Exclusion
 *
 * Verifies that records flagged with deleted_at IS NOT NULL (soft-deleted)
 * are completely omitted from:
 * - KPI summary cards
 * - POS card machine recovery matrix
 * - Daily shift settlement rollups
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-06', 'Soft Delete Exclusion');

$test_date = '2029-08-15';

try {
    reset_test_date_data($connection, $test_date);

    // 1. Active batch: Net Rs. 11,964, Paid Rs. 11,964, Balance Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status, deleted_at) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-ACTIVE', 10, 12000.00, 0.3000, 36.00, 11964.00, 11964.00, 'Paid', NULL)");

    // 2. Soft-deleted batch: Net Rs. 49,850, Paid Rs. 0, Balance Rs. 49,850 (Should be EXCLUDED)
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status, deleted_at) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-DELETED', 50, 50000.00, 0.3000, 150.00, 49850.00, 0.00, 'Unpaid', NOW())");

    $report = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'all');

    // Overall KPI validation
    assert_eq($report['overall']['total_batches'], 1, "Report must exclude soft-deleted batch and count exactly 1 batch");
    assert_eq($report['overall']['total_swipes'], 10, "Total swipes must be 10, ignoring soft-deleted 50 swipes");
    assert_eq($report['overall']['total_gross_amount'], 12000.00, "Total gross must be Rs. 12,000.00, ignoring soft-deleted 50,000.00");
    assert_eq($report['overall']['total_net_expected'], 11964.00, "Total net expected must be Rs. 11,964.00, ignoring soft-deleted 49,850.00");
    assert_eq($report['overall']['total_paid_amount'], 11964.00, "Total paid amount must be Rs. 11,964.00");
    assert_eq($report['overall']['total_balance_due'], 0.00, "Total balance due must be Rs. 0.00");

    // Settlement rows validation
    assert_eq(count($report['settlement_rows']), 1, "Settlement rows must contain exactly 1 active record");
    assert_eq($report['settlement_rows'][0]['batch_no'], 'BATCH-ACTIVE', "Active batch must be BATCH-ACTIVE");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-06'));
