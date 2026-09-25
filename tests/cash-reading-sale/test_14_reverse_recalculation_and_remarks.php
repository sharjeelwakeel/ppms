<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-14';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-14', 'Reverse Recalculation & Remarks Audit on Manual Cash Edit');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter: opening=1000, closing=1500 (Net: 500 Ltr, Price: 200, Amt: 100,000)
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total, remarks) VALUES ('$test_date', '$test_shift', 'Cash', 100000.00, 'Original Shift Note')");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
    VALUES ('$mr_id', '$nozzle_id', 1000.00, 1500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");

    // Auto-create initial cash (500 Ltr)
    sync_shift_cash_sales($connection, $test_date, $test_shift);

    // 2. User manually updates Cash Sale quantity to 600.00 Ltr (+100 Ltr) in edit-cash-sale.php
    mysqli_query($connection, "UPDATE tbl_meter_reading_cash_sales SET quantity = 600.00, amount = 120000.00, is_manual_override = 1 WHERE sale_date = '$test_date' AND shift_id = '$test_shift' AND nozzle_id = '$nozzle_id'");

    // 3. Trigger recalculate_meter_reading_from_sales
    $recalc = recalculate_meter_reading_from_sales($connection, $test_date, $test_shift, $nozzle_id, "Cash Sale #1 update");
    assert_true($recalc, "recalculate_meter_reading_from_sales() should return true");

    // 4. Inspect tbl_meter_reading_details
    $det_q = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_details WHERE meter_reading_id = '$mr_id' AND nozzle_id = '$nozzle_id'");
    $det = mysqli_fetch_assoc($det_q);

    assert_eq(floatval($det['current_reading'] ?? 0), 1600.00, "Current reading must advance by +100 to 1,600.00 Ltr");
    assert_eq(floatval($det['net_sale'] ?? 0), 600.00, "Net sale must advance to 600.00 Ltr");
    assert_eq(floatval($det['amount'] ?? 0), 120000.00, "Detail line amount must advance to Rs. 120,000.00");

    // 5. Inspect tbl_meter_readings
    $mr_q = mysqli_query($connection, "SELECT grand_total, remarks FROM tbl_meter_readings WHERE id = '$mr_id'");
    $mr = mysqli_fetch_assoc($mr_q);

    assert_eq(floatval($mr['grand_total'] ?? 0), 120000.00, "Header grand total must update to Rs. 120,000.00");
    assert_true(strpos($mr['remarks'] ?? '', 'Current reading recalculated') !== false, "Remarks must contain audit trail message");
    assert_true(strpos($mr['remarks'] ?? '', '+100.00 Ltr') !== false, "Remarks must specify exact volume difference (+100.00 Ltr)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-14'));
