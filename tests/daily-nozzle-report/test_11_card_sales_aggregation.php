<?php
/**
 * Test Case 11: Card Sales Aggregation & Terminal Tracking
 *
 * Verifies that POS/Card transactions for the nozzle on the target date are correctly
 * aggregated by volume and revenue, with banking terminal metadata.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-11', 'Card Sales Aggregation & Terminal Tracking');

$test_date = '2029-02-11';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Insert 2 card sales for nozzle 1 on report date
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, '$nozzle_id', 1, '$test_date', 15.00, 200.00, 3000.00, 0.00, 3000.00),
        (1, '$nozzle_id', 2, '$test_date', 25.00, 200.00, 5000.00, 0.00, 5000.00)");

    // Insert a card sale for nozzle 2 (must be excluded from nozzle 1 report)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 2, 1, '$test_date', 50.00, 200.00, 10000.00, 0.00, 10000.00)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 0);

    assert_eq(count($data['card_rows']), 2, 'Should return exactly 2 card sale records for nozzle 1');
    assert_eq($data['total_card_litres'], 40.00, 'Card volume should total 40.00 Ltr (15.00 + 25.00)');
    assert_eq($data['total_card_amount'], 8000.00, 'Card revenue should total Rs. 8,000.00 (3,000 + 5,000)');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-11'));
