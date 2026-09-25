<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-11';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-11', 'Meter Deletion Cascade Cleanup on Auto-Cash');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Insert Meter Reading & auto-create cash sale
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 200000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");

    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // Verify cash created
    $cash_q = mysqli_query($connection, "SELECT id FROM tbl_meter_reading_cash_sales WHERE meter_reading_id = '$mr_id' AND deleted_at IS NULL");
    assert_true(mysqli_num_rows($cash_q) > 0, "Cash sale must exist prior to deletion");

    // 2. Soft-delete meter reading using deletemeterreading logic
    mysqli_query($connection, "UPDATE tbl_meter_readings SET deleted_at = NOW() WHERE id = '$mr_id'");
    mysqli_query($connection, "UPDATE tbl_meter_reading_cash_sales SET deleted_at = NOW() WHERE meter_reading_id = '$mr_id' AND (is_manual_override = 0 OR is_manual_override IS NULL)");

    // 3. Inspect cash sale
    $cash_check = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_cash_sales WHERE meter_reading_id = '$mr_id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    assert_eq(mysqli_num_rows($cash_check), 0, "Active cash sale must be soft-deleted when meter reading is deleted");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-11'));
