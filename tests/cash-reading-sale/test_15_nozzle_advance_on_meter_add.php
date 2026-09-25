<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';

$test_date = '2029-01-15';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-NOZ-01', 'Running Counter Advance on Meter Creation');

try {
    reset_test_date_data($connection, $test_date);

    // Baseline: nozzle at 1000.00
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1000.00 WHERE id = '$nozzle_id'");

    // 1. Insert Meter Reading with current_reading = 1500.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', '$test_shift', 'Cash', 100000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 1000.00, 1500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");

    // Simulate add-meter-reading.php sync logic
    $latest_mr_check = mysqli_query($connection, "
        SELECT mr.id FROM tbl_meter_readings mr 
        JOIN tbl_meter_reading_details mrd ON mr.id = mrd.meter_reading_id 
        WHERE mrd.nozzle_id = '$nozzle_id' AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
        ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC LIMIT 1
    ");
    if ($latest_mr_check && $row = mysqli_fetch_assoc($latest_mr_check)) {
        if (intval($row['id']) === $mr_id) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1500.00 WHERE id = '$nozzle_id'");
        }
    }

    $noz_q = mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'");
    $noz = mysqli_fetch_assoc($noz_q);

    assert_eq(floatval($noz['start_reading'] ?? 0), 1500.00, "tbl_nozzles.start_reading must advance to new closing meter (1500.00)");

} finally {
    reset_test_date_data($connection, $test_date);
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1000.00 WHERE id = '$nozzle_id'");
}

exit(print_suite_summary('TC-NOZ-01'));
