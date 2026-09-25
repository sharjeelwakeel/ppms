<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-12';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-12', 'Manual Override Protection on Cash Sales');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter: 1,000 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");

    // Auto-create initial cash
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // 2. User manually overrides cash sale to 750.00 Ltr
    mysqli_query($connection, "UPDATE tbl_meter_reading_cash_sales SET quantity = 750.00, amount = 150000.00, is_manual_override = 1 WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");

    // 3. Someone later adds a Credit Slip of 100.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
    (slip_no, slip_date, sale_date, shift_id, nozzle_id, account_number, vehicle_number, quantity, issue_quantity, rate, amount)
    VALUES ('CR-TEST-12', '$test_date', '$test_date', '$test_shift', '$nozzle_id', 1, 'ABC-123', 100.00, 100.00, 200.00, 20000.00)");

    // 4. Background sync executes
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // 5. Inspect cash sale: manual override must be preserved!
    $q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");
    $cash = mysqli_fetch_assoc($q);

    assert_eq(floatval($cash['quantity'] ?? 0), 750.00, "Cash quantity must remain 750.00 (Protected from auto-sync overwrite)");
    assert_eq(floatval($cash['amount'] ?? 0), 150000.00, "Cash amount must remain Rs. 150,000.00 (Protected)");
    assert_eq(intval($cash['is_manual_override'] ?? 0), 1, "is_manual_override flag must remain 1");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-12'));
