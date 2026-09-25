<?php
/**
 * Test Case 12: Perfect Volumetric Reconciliation (Zero Variance)
 *
 * Verifies that when net meter dispensed volume equals the exact sum of
 * Cash + Credit + Card settled volumes, volumetric difference is 0.00 Ltr
 * and the reconciliation status is balanced.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-12', 'Perfect Volumetric Reconciliation (Zero Variance)');

$test_date = '2029-02-12';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter Reading: Start 1000.00, End 1100.00, Test 5.00 -> Net Meter = 95.00 Ltr @ Rs. 200
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 19000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 1000.00, 1100.00, 100.00, 5.00, 95.00, 200.00, 19000.00)");

    // 2. Cash Sale: 45.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle_id', '$test_date', 1, 45.00, 200.00, 9000.00, '$mr_id')");

    // 3. Credit Sale: 30.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'SLIP-BAL-01', 'Permanent Slip', 1, 'ABC-123', 30.00, 200.00, 6000.00, 6000.00, 30.00)");

    // 4. Card Sale: 20.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, '$nozzle_id', 1, '$test_date', 20.00, 200.00, 4000.00, 0.00, 4000.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_meter_litres'], 95.00, 'Net meter volume must be 95.00 Ltr');
    assert_eq($data['total_settled_litres'], 95.00, 'Total settled volume must be 95.00 Ltr');
    assert_eq($data['volume_variance'], 0.00, 'Volumetric variance must be exactly 0.00 Ltr');
    assert_eq($data['financial_variance'], 0.00, 'Financial variance must be exactly Rs. 0.00');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-12'));
