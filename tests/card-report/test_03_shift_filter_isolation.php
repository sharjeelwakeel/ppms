<?php
/**
 * Test Case 03: Shift Filter Isolation (Morning vs. Evening vs. Both)
 *
 * Verifies that the shift filter properly isolates Morning shift (shift_id = 1),
 * Evening shift (shift_id = 2), or combines both shifts when shift_id = 0.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-03', 'Shift Filter Isolation (Morning vs. Evening vs. Both)');

$test_date = '2029-07-12';

try {
    reset_test_date_data($connection, $test_date);

    // Morning shift sale (shift_id = 1): 50 Ltr @ Rs. 200 = Rs. 10,000 (Fee: 100, Net: 9,900)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$test_date', 50.00, 200.00, 10000.00, 100.00, 9900.00)");

    // Evening shift sale (shift_id = 2): 70 Ltr @ Rs. 200 = Rs. 14,000 (Fee: 140, Net: 13,860)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 2, '$test_date', 70.00, 200.00, 14000.00, 140.00, 13860.00)");

    // 1. Filter: Morning Shift (shift_id = 1)
    $rep_morning = get_card_report_data($connection, $test_date, $test_date, 0, 1);
    assert_eq($rep_morning['overall']['total_swipes'], 1, "Morning report must contain 1 swipe");
    assert_eq($rep_morning['overall']['total_volume'], 50.00, "Morning volume must be 50.00 Ltr");
    assert_eq($rep_morning['overall']['total_gross_amount'], 10000.00, "Morning gross must be Rs. 10,000.00");

    // 2. Filter: Evening Shift (shift_id = 2)
    $rep_evening = get_card_report_data($connection, $test_date, $test_date, 0, 2);
    assert_eq($rep_evening['overall']['total_swipes'], 1, "Evening report must contain 1 swipe");
    assert_eq($rep_evening['overall']['total_volume'], 70.00, "Evening volume must be 70.00 Ltr");
    assert_eq($rep_evening['overall']['total_gross_amount'], 14000.00, "Evening gross must be Rs. 14,000.00");

    // 3. Filter: Both Shifts (shift_id = 0)
    $rep_both = get_card_report_data($connection, $test_date, $test_date, 0, 0);
    assert_eq($rep_both['overall']['total_swipes'], 2, "Both shifts report must contain 2 swipes");
    assert_eq($rep_both['overall']['total_volume'], 120.00, "Both shifts volume must be 50 + 70 = 120.00 Ltr");
    assert_eq($rep_both['overall']['total_gross_amount'], 24000.00, "Both shifts gross must be 10,000 + 14,000 = Rs. 24,000.00");
    assert_eq($rep_both['overall']['total_net_revenue'], 23760.00, "Both shifts net revenue must be 9,900 + 13,860 = Rs. 23,760.00");
    assert_eq(count($rep_both['shift_summary']), 2, "Shift summary must have 2 rollup rows (Morning and Evening)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CARD-03'));
