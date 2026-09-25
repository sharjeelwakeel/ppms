<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-05', 'Meter Volume, Test Reading Deduction & Gross Revenue Audit');

$test_date = '2029-02-05';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Insert reading: Opening = 5,000, Closing = 6,010, Test = 10, Net = 1,000 Ltr @ Rs. 250
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 250000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 5000.00, 6010.00, 1010.00, 10.00, 1000.00, 250.00, 250000.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_test_litres'], 10.00, "Test reading must equal 10.00 Ltr");
    assert_eq($data['total_meter_litres'], 1000.00, "Net physical meter sale must equal 1000.00 Ltr");
    assert_eq($data['total_meter_revenue'], 250000.00, "Gross meter revenue must equal Rs. 250,000.00");
    assert_eq($data['min_opening_meter'], 5000.00, "Opening meter counter must be 5000.00");
    assert_eq($data['max_closing_meter'], 6010.00, "Closing meter counter must be 6010.00");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-05'));
