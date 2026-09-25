<?php
/**
 * Test Case 08: Balanced Slip Handling (Petrol on Balance - Prepaid)
 *
 * Verifies that when a driver claims uncollected fuel balance via a 'Balanced Slip',
 * the physical fuel dispensed (issue_quantity) correctly advances nozzle settled litres,
 * while the billed amount remains Rs. 0.00 without throwing reconciliation out of balance.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-08', 'Balanced Slip Handling (Petrol on Balance - Prepaid)');

$test_date = '2029-02-08';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Create a Balanced Slip
    // Customer claimed 25 Ltr remaining balance. Billed amount = 0.00
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity, balance_1) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'BAL-9901', 'Balanced Slip', 1, 'LHR-8888', 0.00, 200.00, 0.00, 0.00, 25.00, 0.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq(count($data['credit_rows']), 1, 'Should return 1 credit record for Balanced Slip');
    $slip = $data['credit_rows'][0];
    assert_eq($slip['slip_type'], 'Balanced Slip', 'Slip type must be explicitly identified as Balanced Slip');
    assert_eq(floatval($slip['issue_quantity']), 25.00, 'Physical fuel dispensed from nozzle must be 25.00 Ltr');
    assert_eq(floatval($slip['amount']), 0.00, 'Billed amount for balanced slip must be Rs. 0.00 (prepaid)');
    assert_eq($data['total_credit_issued_litres'], 25.00, 'Total credit litres settled for nozzle must include 25.00 Ltr of prepaid balanced fuel');
    assert_eq($data['total_credit_amount'], 0.00, 'Total credit revenue must remain Rs. 0.00');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-08'));
