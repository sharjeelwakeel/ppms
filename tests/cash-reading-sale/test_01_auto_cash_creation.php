<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-01';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-01', 'Standard Auto-Cash Generation from Meter Reading');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Insert Meter Reading with Net Sale = 1,000 Ltr, Rate = 200
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");

    // 2. Trigger auto cash sync
    $sync_res = sync_shift_cash_sales($connection, $test_date, $test_shift);
    assert_true($sync_res, "sync_shift_cash_sales() should return true");

    // 3. Inspect generated cash sale
    $q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");
    $cash = mysqli_fetch_assoc($q);

    assert_true(!empty($cash), "Cash sale record must be created in tbl_meter_reading_cash_sales");
    assert_eq(floatval($cash['quantity'] ?? 0), 1000.00, "Cash litres must match net meter sale (1000.00 Ltr)");
    assert_eq(floatval($cash['amount'] ?? 0), 200000.00, "Cash amount must match net sale * rate (Rs. 200,000.00)");
    assert_eq(floatval($cash['rate'] ?? 0), 200.00, "Cash rate must equal fuel price (Rs. 200.00)");
    assert_eq(intval($cash['is_manual_override'] ?? -1), 0, "is_manual_override must be 0 (auto-generated)");
    assert_true(strpos($cash['notes'] ?? '', 'Auto-calculated') !== false, "Notes must indicate auto-calculated origin");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-01'));
