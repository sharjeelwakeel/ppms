<?php
/**
 * Test Case 10: Temporary Slip Handling (Direct Loan Dispensed Today)
 *
 * Verifies that when fuel is issued on a temporary loan chit ('Temporary Slip') today,
 * the dispensed fuel is properly aggregated into today's nozzle credit volume, marked
 * with the Loan badge, and included in settlement reconciliation.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-10', 'Temporary Slip Handling (Direct Loan Dispensed Today)');

$test_date = '2029-02-10';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Insert a temporary slip issued today
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'TMP-TODAY-01', 'Temporary Slip', 1, 'FD-9922', 40.00, 200.00, 8000.00, 8000.00, 40.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq(count($data['credit_rows']), 1, "Should return 1 credit record for today's temporary slip");
    $slip = $data['credit_rows'][0];
    assert_eq($slip['slip_type'], 'Temporary Slip', 'Slip type must be Temporary Slip');
    assert_eq(floatval($slip['issue_quantity']), 40.00, 'Physical volume dispensed through nozzle gun must be 40.00 Ltr');
    assert_eq(floatval($slip['amount']), 8000.00, 'Loan amount must be Rs. 8,000.00');
    assert_eq($data['total_credit_issued_litres'], 40.00, 'Total credit settled litres must include 40.00 Ltr');
    assert_eq($data['total_credit_amount'], 8000.00, 'Total credit revenue must include Rs. 8,000.00');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-10'));
