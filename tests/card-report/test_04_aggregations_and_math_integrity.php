<?php
/**
 * Test Case 04: Mathematical Integrity of Aggregations & Yield Calculations
 *
 * Verifies that Gross Sales, Bank Fee Deductions, and Net Revenue
 * satisfy strict invariant equations:
 * Net Revenue = Gross Sales - Service Fee
 * Effective Payout % = (Net Revenue / Gross Sales) * 100
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-04', 'Mathematical Integrity of Aggregations & Yield Calculations');

$test_date = '2029-07-13';

try {
    reset_test_date_data($connection, $test_date);

    // Row 1: 100 Ltr @ Rs. 200 = Rs. 20,000 (Fee: Rs. 300, Net: Rs. 19,700)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$test_date', 100.00, 200.00, 20000.00, 300.00, 19700.00)");

    // Row 2: 50 Ltr @ Rs. 200 = Rs. 10,000 (Fee: Rs. 150, Net: Rs. 9,850)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$test_date', 50.00, 200.00, 10000.00, 150.00, 9850.00)");

    $report = get_card_report_data($connection, $test_date, $test_date, 0, 0);
    $ov = $report['overall'];

    assert_eq($ov['total_volume'], 150.00, "Total fuel volume must equal 150.00 Ltr");
    assert_eq($ov['total_swipes'], 2, "Total swipe count must equal 2");
    assert_eq($ov['total_gross_amount'], 30000.00, "Gross card sales must equal Rs. 30,000.00");
    assert_eq($ov['total_service_charges'], 450.00, "Bank service charges must equal Rs. 450.00");
    assert_eq($ov['total_net_revenue'], 29550.00, "Net card revenue must equal Rs. 29,550.00 (30,000 - 450)");
    assert_eq($ov['effective_fee_percentage'], 1.50, "Effective fee percentage must equal 1.50%");
    assert_eq($ov['effective_payout_percentage'], 98.50, "Effective payout percentage must equal 98.50%");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CARD-04'));
