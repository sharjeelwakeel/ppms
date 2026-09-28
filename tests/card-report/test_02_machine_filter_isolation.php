<?php
/**
 * Test Case 02: Card Machine Filter Isolation & Matrix Breakdown
 *
 * Verifies that selecting a specific card machine isolates that terminal's sales,
 * while selecting card_machine_id = 0 aggregates all terminals into the matrix.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-02', 'Card Machine Filter Isolation & Matrix Breakdown');

$test_date = '2029-07-11';

try {
    reset_test_date_data($connection, $test_date);

    // Insert sale for Machine 1: 40 Ltr @ Rs. 200 = Rs. 8,000 (Fee = Rs. 80, Net = Rs. 7,920)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$test_date', 40.00, 200.00, 8000.00, 80.00, 7920.00)");

    // Insert sale for Machine 2: 60 Ltr @ Rs. 200 = Rs. 12,000 (Fee = Rs. 120, Net = Rs. 11,880)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (2, 1, 1, '$test_date', 60.00, 200.00, 12000.00, 120.00, 11880.00)");

    // 1. Isolated query for Machine 1 only
    $rep_m1 = get_card_report_data($connection, $test_date, $test_date, 1, 0);

    assert_eq($rep_m1['overall']['total_swipes'], 1, "Machine 1 report must have exactly 1 swipe");
    assert_eq($rep_m1['overall']['total_gross_amount'], 8000.00, "Machine 1 gross amount must be Rs. 8,000.00");
    assert_eq(count($rep_m1['machine_matrix']), 1, "Machine matrix must contain only 1 machine row");

    // 2. Combined query for All Machines (card_machine_id = 0)
    $rep_all = get_card_report_data($connection, $test_date, $test_date, 0, 0);

    assert_eq($rep_all['overall']['total_swipes'], 2, "All machines report must have 2 swipes");
    assert_eq($rep_all['overall']['total_volume'], 100.00, "Total volume must be 40 + 60 = 100.00 Ltr");
    assert_eq($rep_all['overall']['total_gross_amount'], 20000.00, "Total gross amount must be 8,000 + 12,000 = Rs. 20,000.00");
    assert_eq($rep_all['overall']['total_net_revenue'], 19800.00, "Total net revenue must be 7,920 + 11,880 = Rs. 19,800.00");
    assert_eq(count($rep_all['machine_matrix']), 2, "Machine matrix must contain 2 separate machine rows");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CARD-02'));
