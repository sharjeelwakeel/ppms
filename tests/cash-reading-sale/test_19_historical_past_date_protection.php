<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$nozzle_id = 1;
$day1 = '2029-01-19';
$day2 = '2029-01-20';

test_header('TC-NOZ-05', 'Historical / Past-Date Protection on Live Nozzle Counter');

try {
    reset_test_date_data($connection, $day1);
    reset_test_date_data($connection, $day2);

    // 1. Day 1 (Past Date): Closing counter = 1,000.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$day1', 1, 'Cash', 100000.00)");
    $mr1_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr1_id', '$nozzle_id', 0.00, 1000.00, 1000.00, 0.00, 1000.00, 200.00, 100000.00)");
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1000.00 WHERE id = '$nozzle_id'");

    // 2. Day 2 (Latest Date): Closing counter = 2,000.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$day2', 1, 'Cash', 200000.00)");
    $mr2_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr2_id', '$nozzle_id', 1000.00, 2000.00, 1000.00, 0.00, 1000.00, 200.00, 200000.00)");
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 2000.00 WHERE id = '$nozzle_id'");

    $initial_live = mysqli_fetch_assoc(mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'"));
    assert_eq(floatval($initial_live['start_reading'] ?? 0), 2000.00, "Initial live counter at Day 2 must be 2,000.00 Ltr");

    // 3. User goes back to Day 1 (PAST DATE) and edits Day 1 closing reading to 1,100.00
    mysqli_query($connection, "UPDATE tbl_meter_reading_details SET current_reading = 1100.00 WHERE meter_reading_id = '$mr1_id' AND nozzle_id = '$nozzle_id'");

    // Execute safe edit-meter-reading sync logic
    $latest_check = mysqli_query($connection, "
        SELECT mr.id FROM tbl_meter_readings mr 
        JOIN tbl_meter_reading_details mrd ON mr.id = mrd.meter_reading_id 
        WHERE mrd.nozzle_id = '$nozzle_id' AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
        ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC LIMIT 1
    ");
    if ($latest_check && $row = mysqli_fetch_assoc($latest_check)) {
        if (intval($row['id']) === $mr1_id) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1100.00 WHERE id = '$nozzle_id'");
        }
    }

    $live_after_past_meter_edit = mysqli_fetch_assoc(mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'"));
    assert_eq(floatval($live_after_past_meter_edit['start_reading'] ?? 0), 2000.00, "Live counter MUST REMAIN 2,000.00 when editing past-day meter reading (PROTECTED)");

    // 4. User edits Cash on Day 1 (PAST DATE)
    recalculate_meter_reading_from_sales($connection, $day1, 1, $nozzle_id, "Day 1 Cash edit");
    $live_after_past_cash_edit = mysqli_fetch_assoc(mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'"));
    assert_eq(floatval($live_after_past_cash_edit['start_reading'] ?? 0), 2000.00, "Live counter MUST REMAIN 2,000.00 when editing past-day cash sales (PROTECTED)");

    // 5. User edits Day 2 (LATEST DATE) closing meter to 2,200.00
    mysqli_query($connection, "UPDATE tbl_meter_reading_details SET current_reading = 2200.00 WHERE meter_reading_id = '$mr2_id' AND nozzle_id = '$nozzle_id'");

    $latest_check = mysqli_query($connection, "
        SELECT mr.id FROM tbl_meter_readings mr 
        JOIN tbl_meter_reading_details mrd ON mr.id = mrd.meter_reading_id 
        WHERE mrd.nozzle_id = '$nozzle_id' AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
        ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC LIMIT 1
    ");
    if ($latest_check && $row = mysqli_fetch_assoc($latest_check)) {
        if (intval($row['id']) === $mr2_id) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 2200.00 WHERE id = '$nozzle_id'");
        }
    }

    $live_after_latest_edit = mysqli_fetch_assoc(mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$nozzle_id'"));
    assert_eq(floatval($live_after_latest_edit['start_reading'] ?? 0), 2200.00, "Live counter MUST ADVANCE to 2,200.00 when editing latest meter reading");

} finally {
    reset_test_date_data($connection, $day1);
    reset_test_date_data($connection, $day2);
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = 1000.00 WHERE id = '$nozzle_id'");
}

exit(print_suite_summary('TC-NOZ-05'));
