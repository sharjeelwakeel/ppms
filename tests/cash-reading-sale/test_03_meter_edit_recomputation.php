<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-03';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-03', 'Meter Reading Edit Recomputation');

try {
    reset_test_date_data($connection, $test_date);

    // Initial: 1,000 Ltr Net Sale
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");

    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // Operator edits closing reading to 1,200 Ltr (Net: 1,200 Ltr, Amt: 240,000)
    mysqli_query($connection, "UPDATE tbl_meter_reading_details SET current_reading = 1200.00, sale_reading = 1200.00, net_sale = 1200.00, amount = 240000.00 WHERE meter_reading_id = '$mr_id' AND nozzle_id = '$nozzle_id'");
    mysqli_query($connection, "UPDATE tbl_meter_readings SET grand_total = 240000.00 WHERE id = '$mr_id'");

    // Re-sync
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    $q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");
    $cash = mysqli_fetch_assoc($q);

    assert_eq(floatval($cash['quantity'] ?? 0), 1200.00, "Cash quantity must update to edited net sale (1200.00 Ltr)");
    assert_eq(floatval($cash['amount'] ?? 0), 240000.00, "Cash amount must update to 1200 * 200 (Rs. 240,000.00)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-03'));
