<?php
/**
 * Test Case 02: Card Machine Filter Isolation & Terminal Matrix Breakdown
 *
 * Verifies that filtering by a specific card machine isolates its settlement
 * batches, amount received, and outstanding balance, while card_machine_id = 0
 * aggregates all terminals into the machine recovery matrix.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-02', 'Card Machine Filter Isolation & Terminal Matrix Breakdown');

$test_date = '2029-08-11';

try {
    reset_test_date_data($connection, $test_date);

    // Batch A: Machine 1 (Partial) - Net: Rs. 9,970, Paid: Rs. 5,000, Balance: Rs. 4,970
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-M1', 12, 10000.00, 0.3000, 30.00, 9970.00, 5000.00, 'Partial')");

    // Batch B: Machine 2 (Paid) - Net: Rs. 19,940, Paid: Rs. 19,940, Balance: Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (2, '$test_date', 1, 'BATCH-M2', 20, 20000.00, 0.3000, 60.00, 19940.00, 19940.00, 'Paid')");

    // 1. Isolated query for Machine 1
    $rep_m1 = get_card_balance_report_data($connection, $test_date, $test_date, 1, 0, 'all');

    assert_eq($rep_m1['overall']['total_batches'], 1, "Machine 1 report must have exactly 1 batch");
    assert_eq($rep_m1['overall']['total_net_expected'], 9970.00, "Machine 1 net expected must be Rs. 9,970.00");
    assert_eq($rep_m1['overall']['total_paid_amount'], 5000.00, "Machine 1 amount get must be Rs. 5,000.00");
    assert_eq($rep_m1['overall']['total_balance_due'], 4970.00, "Machine 1 balance due must be Rs. 4,970.00");
    assert_eq(count($rep_m1['machine_matrix']), 1, "Machine matrix must contain 1 machine row");

    // 2. All Machines query (card_machine_id = 0)
    $rep_all = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'all');

    assert_eq($rep_all['overall']['total_batches'], 2, "All machines report must have 2 batches");
    assert_eq($rep_all['overall']['total_net_expected'], 29910.00, "All machines net expected must be Rs. 29,910.00 (9,970 + 19,940)");
    assert_eq($rep_all['overall']['total_paid_amount'], 24940.00, "Total amount get must be Rs. 24,940.00 (5,000 + 19,940)");
    assert_eq($rep_all['overall']['total_balance_due'], 4970.00, "Total balance due must be Rs. 4,970.00 (4,970 + 0)");
    assert_eq(count($rep_all['machine_matrix']), 2, "Machine matrix must contain 2 separate machine rows");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-02'));
