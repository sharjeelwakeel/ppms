<?php
/**
 * Test Case 14: Volumetric Excess Detection (Surplus Settled Volume)
 *
 * Verifies that when settled volume across cash, credit, and card exceeds the net physical
 * meter volume, the system detects an excess variance (+ve variance).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-14', 'Volumetric Excess Detection');

$test_date = '2029-02-14';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter Reading: Start 1000.00, End 1100.00 -> Net Meter = 100.00 Ltr @ Rs. 200
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 20000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 1000.00, 1100.00, 100.00, 0.00, 100.00, 200.00, 20000.00)");

    // 2. Cash Sale accounts for 70.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle_id', '$test_date', 1, 70.00, 200.00, 14000.00, '$mr_id')");

    // 3. Card Sale accounts for 45.00 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, '$nozzle_id', 1, '$test_date', 45.00, 200.00, 9000.00, 0.00, 9000.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_meter_litres'], 100.00, 'Net meter volume should be 100.00 Ltr');
    assert_eq($data['total_settled_litres'], 115.00, 'Total settled volume should be 115.00 Ltr');
    assert_eq($data['volume_variance'], 15.00, 'Volumetric variance should be +15.00 Ltr (excess)');
    assert_eq($data['financial_variance'], 3000.00, 'Financial variance should be +Rs. 3,000.00 (excess)');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-14'));
