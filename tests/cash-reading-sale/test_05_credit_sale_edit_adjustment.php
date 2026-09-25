<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-05';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-05', 'Credit Sale Edit Adjustment on Cash Remainder');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter: 1,000 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");

    // 2. Initial Credit: 300 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
    (slip_no, slip_date, sale_date, shift_id, nozzle_id, account_number, vehicle_number, quantity, issue_quantity, rate, amount)
    VALUES ('CR-TEST-05', '$test_date', '$test_date', '$test_shift', '$nozzle_id', 1, 'ABC-123', 300.00, 300.00, 200.00, 60000.00)");
    $cr_id = mysqli_insert_id($connection);

    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // 3. User edits Credit Slip to 450 Ltr
    mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET quantity = 450.00, issue_quantity = 450.00, amount = 90000.00 WHERE id = '$cr_id'");

    // Re-sync
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    $q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");
    $cash = mysqli_fetch_assoc($q);

    assert_eq(floatval($cash['quantity'] ?? 0), 550.00, "Cash quantity must adjust to 1000 - 450 = 550.00 Ltr");
    assert_eq(floatval($cash['amount'] ?? 0), 110000.00, "Cash amount must adjust to 550 * 200 = Rs. 110,000.00");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-05'));
