<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-02';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-02', 'Test Fuel (Calibration) Deduction Handling');

try {
    reset_test_date_data($connection, $test_date);

    // Opening: 0.00, Closing: 1005.00, Test Reading: 5.00 -> Net Sale: 1000.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1005.00, 1005.00, 5.00, 1000.00, 200.00, 200000.00)");

    sync_shift_cash_sales($connection, $test_date, $test_shift);

    $q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");
    $cash = mysqli_fetch_assoc($q);

    assert_true(!empty($cash), "Cash sale record must exist");
    assert_eq(floatval($cash['quantity'] ?? 0), 1000.00, "Cash quantity must equal NET sale (1000.00 Ltr), not gross meter diff (1005.00 Ltr)");
    assert_eq(floatval($cash['amount'] ?? 0), 200000.00, "Cash amount must equal net sale * price (Rs. 200,000.00)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-02'));
