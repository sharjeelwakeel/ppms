<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-06', 'Cash Sales Aggregation');

$test_date = '2029-02-06';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Insert 2 cash sale records for Nozzle #1
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, notes) 
        VALUES ('$nozzle_id', '$test_date', 1, 350.00, 200.00, 70000.00, 'Morning cash')");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, notes) 
        VALUES ('$nozzle_id', '$test_date', 1, 150.00, 200.00, 30000.00, 'Afternoon cash')");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq(count($data['cash_rows']), 2, "Should return 2 cash entries");
    assert_eq($data['total_cash_litres'], 500.00, "Total cash litres must equal 500.00 Ltr (350 + 150)");
    assert_eq($data['total_cash_amount'], 100000.00, "Total cash amount must equal Rs. 100,000.00 (70,000 + 30,000)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-06'));
