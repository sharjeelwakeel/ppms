<?php
/**
 * Test Case 13: Volumetric Shortage Detection (Unaccounted Fuel)
 *
 * Verifies that when physical net meter dispensed volume exceeds the total volume
 * accounted for in Cash, Credit, and Card settlements, the system detects a shortage
 * variance (negative variance).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-13', 'Volumetric Shortage Detection');

$test_date = '2029-02-13';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter Reading: Start 1000.00, End 1100.00, Test 0.00 -> Net Meter = 100.00 Ltr @ Rs. 200
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 20000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 1000.00, 1100.00, 100.00, 0.00, 100.00, 200.00, 20000.00)");

    // 2. Cash Sale accounts for only 50.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle_id', '$test_date', 1, 50.00, 200.00, 10000.00, '$mr_id')");

    // 3. Credit Sale accounts for 30.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'SLIP-SHORT-01', 'Permanent Slip', 1, 'XYZ-999', 30.00, 200.00, 6000.00, 6000.00, 30.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_meter_litres'], 100.00, 'Net meter volume should be 100.00 Ltr');
    assert_eq($data['total_settled_litres'], 80.00, 'Total settled volume should be 80.00 Ltr');
    assert_eq($data['volume_variance'], -20.00, 'Volumetric variance should be -20.00 Ltr (shortage)');
    assert_eq($data['financial_variance'], -4000.00, 'Financial variance should be -Rs. 4,000.00 (shortage)');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-13'));
