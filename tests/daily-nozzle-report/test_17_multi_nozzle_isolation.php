<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-17', 'Multi-Nozzle Isolation (Strict Per-Nozzle Partitioning)');

$test_date = '2029-02-17';

try {
    reset_test_date_data($connection, $test_date);

    // Ensure Nozzle #2 exists
    mysqli_query($connection, "INSERT INTO tbl_nozzles (id, name, tank_id, item_id, start_reading, status) 
        VALUES (2, 'Nozzle B', 1, 1, 0.00, 'active') 
        ON DUPLICATE KEY UPDATE deleted_at = NULL");

    // Common meter reading header
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 240000.00)");
    $mr_id = mysqli_insert_id($connection);

    // Detail for Nozzle #1: 400 Ltr @ Rs. 200 = Rs. 80,000
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', 1, 1000.00, 1400.00, 400.00, 0.00, 400.00, 200.00, 80000.00)");

    // Detail for Nozzle #2: 800 Ltr @ Rs. 200 = Rs. 160,000
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', 2, 2000.00, 2800.00, 800.00, 0.00, 800.00, 200.00, 160000.00)");

    // Cash for Nozzle #1: 400 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount) 
        VALUES (1, '$test_date', 1, 400.00, 200.00, 80000.00)");

    // Cash for Nozzle #2: 800 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount) 
        VALUES (2, '$test_date', 1, 800.00, 200.00, 160000.00)");

    // Query report strictly for Nozzle #1
    $data_noz1 = get_daily_nozzle_report_data($connection, 1, $test_date, 1);

    assert_eq($data_noz1['total_meter_litres'], 400.00, "Nozzle #1 meter volume must be strictly 400.00 Ltr");
    assert_eq($data_noz1['total_cash_litres'], 400.00, "Nozzle #1 cash volume must be strictly 400.00 Ltr");
    assert_eq(count($data_noz1['meter_rows']), 1, "Nozzle #1 should have exactly 1 meter row");

    // Query report strictly for Nozzle #2
    $data_noz2 = get_daily_nozzle_report_data($connection, 2, $test_date, 1);

    assert_eq($data_noz2['total_meter_litres'], 800.00, "Nozzle #2 meter volume must be strictly 800.00 Ltr");
    assert_eq($data_noz2['total_cash_litres'], 800.00, "Nozzle #2 cash volume must be strictly 800.00 Ltr");
    assert_eq(count($data_noz2['meter_rows']), 1, "Nozzle #2 should have exactly 1 meter row");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-17'));
