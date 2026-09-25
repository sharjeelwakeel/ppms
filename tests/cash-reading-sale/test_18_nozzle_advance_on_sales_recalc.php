<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-18';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-NOZ-04', 'Running Counter Advance on Reverse Sales Recalculation');

try {
    reset_test_date_data($connection, $test_date);

    // Initial baseline: 1,000.00, closing: 1,500.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 100000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 1000.00, 1500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1500.00 WHERE id = '$nozzle_id'");

    // Initial cash
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // Operator manually increases cash sale to 600.00 Ltr (+100 Ltr)
    mysqli_query($connection, "UPDATE tbl_meter_reading_cash_sales SET quantity = 600.00, amount = 120000.00, is_manual_override = 1 WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");

    // Trigger recalculation
    recalculate_meter_reading_from_sales($connection, $test_date, $test_shift, $nozzle_id, "Cash Sale edit");

    $noz_q = mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'");
    $noz = mysqli_fetch_assoc($noz_q);

    assert_eq(floatval($noz['start_reading'] ?? 0), 1600.00, "tbl_nozzles.start_reading must advance to recalculated current reading (1600.00)");

} finally {
    reset_test_date_data($connection, $test_date);
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1000.00 WHERE id = '$nozzle_id'");
}

exit(print_suite_summary('TC-NOZ-04'));
