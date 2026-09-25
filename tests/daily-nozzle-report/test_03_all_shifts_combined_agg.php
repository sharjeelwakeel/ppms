<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-03', 'All Shifts Combined Aggregation (shift_id = 0)');

$test_date = '2029-02-03';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Shift 1: Meter Net Sale = 600 Ltr, Cash = 600 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 120000.00)");
    $mr1_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr1_id', '$nozzle_id', 1000.00, 1600.00, 600.00, 0.00, 600.00, 200.00, 120000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle_id', '$test_date', 1, 600.00, 200.00, 120000.00, '$mr1_id')");

    // Shift 2: Meter Net Sale = 400 Ltr, Cash = 400 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 2, 'Cash', 80000.00)");
    $mr2_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr2_id', '$nozzle_id', 1600.00, 2000.00, 400.00, 0.00, 400.00, 200.00, 80000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle_id', '$test_date', 2, 400.00, 200.00, 80000.00, '$mr2_id')");

    // Query report with shift_id = 0 (All Shifts)
    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 0);

    assert_eq(count($data['meter_rows']), 2, "Should return 2 meter readings across both shifts");
    assert_eq($data['total_meter_litres'], 1000.00, "Combined meter volume must equal 1000.00 Ltr (600 + 400)");
    assert_eq($data['total_meter_revenue'], 200000.00, "Combined meter revenue must equal Rs. 200,000.00");
    assert_eq($data['min_opening_meter'], 1000.00, "Opening meter must be minimum across day (1000.00)");
    assert_eq($data['max_closing_meter'], 2000.00, "Closing meter must be maximum across day (2000.00)");
    assert_eq($data['total_cash_litres'], 1000.00, "Combined cash litres must be 1000.00 Ltr");
    assert_eq($data['total_cash_amount'], 200000.00, "Combined cash amount must be Rs. 200,000.00");
    assert_eq($data['volume_variance'], 0.00, "Volumetric variance must be 0.00");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-03'));
