<?php
/**
 * Test Case 03: Shift Filter Isolation (Both / Morning / Evening)
 *
 * Verifies that filtering by shift isolates Morning (shift_id = 1) vs Evening (shift_id = 2),
 * and shift_id = 0 retrieves consolidated multi-shift settlements accurately.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-03', 'Shift Filter Isolation (Both / Morning / Evening)');

$test_date = '2029-08-12';

try {
    reset_test_date_data($connection, $test_date);

    // 1. Shift 1 (Morning) batch: Net Rs. 9,970, Paid Rs. 5,000, Balance Rs. 4,970
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-SH1', 10, 10000.00, 0.3000, 30.00, 9970.00, 5000.00, 'Partial')");

    // 2. Shift 2 (Evening) batch: Net Rs. 24,925, Paid Rs. 24,925, Balance Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 2, 'BATCH-SH2', 25, 25000.00, 0.3000, 75.00, 24925.00, 24925.00, 'Paid')");

    // Test Morning Shift Isolation (shift_id = 1)
    $rep_morn = get_card_balance_report_data($connection, $test_date, $test_date, 0, 1, 'all');
    assert_eq($rep_morn['overall']['total_batches'], 1, "Morning filter must return 1 batch");
    assert_eq($rep_morn['overall']['total_net_expected'], 9970.00, "Morning net expected must be Rs. 9,970.00");
    assert_eq($rep_morn['overall']['total_paid_amount'], 5000.00, "Morning paid amount must be Rs. 5,000.00");
    assert_eq($rep_morn['overall']['total_balance_due'], 4970.00, "Morning balance due must be Rs. 4,970.00");
    assert_eq(count($rep_morn['settlement_rows']), 1, "Morning settlement rows count must be 1");
    assert_eq($rep_morn['settlement_rows'][0]['shift_id'], 1, "Settlement row shift_id must be 1");

    // Test Evening Shift Isolation (shift_id = 2)
    $rep_eve = get_card_balance_report_data($connection, $test_date, $test_date, 0, 2, 'all');
    assert_eq($rep_eve['overall']['total_batches'], 1, "Evening filter must return 1 batch");
    assert_eq($rep_eve['overall']['total_net_expected'], 24925.00, "Evening net expected must be Rs. 24,925.00");
    assert_eq($rep_eve['overall']['total_paid_amount'], 24925.00, "Evening paid amount must be Rs. 24,925.00");
    assert_eq($rep_eve['overall']['total_balance_due'], 0.00, "Evening balance due must be Rs. 0.00");
    assert_eq(count($rep_eve['settlement_rows']), 1, "Evening settlement rows count must be 1");
    assert_eq($rep_eve['settlement_rows'][0]['shift_id'], 2, "Settlement row shift_id must be 2");

    // Test Both Shifts Consolidated (shift_id = 0)
    $rep_both = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'all');
    assert_eq($rep_both['overall']['total_batches'], 2, "Consolidated report must return 2 batches");
    assert_eq($rep_both['overall']['total_net_expected'], 34895.00, "Consolidated net expected must be Rs. 34,895.00 (9,970 + 24,925)");
    assert_eq($rep_both['overall']['total_paid_amount'], 29925.00, "Consolidated paid amount must be Rs. 29,925.00 (5,000 + 24,925)");
    assert_eq($rep_both['overall']['total_balance_due'], 4970.00, "Consolidated balance due must be Rs. 4,970.00 (4,970 + 0)");
    assert_eq(count($rep_both['settlement_rows']), 2, "Consolidated settlement rows count must be 2");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-03'));
