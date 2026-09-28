<?php
/**
 * Test Case 01: Card Report Filter Defaults & Date Range Filtering
 *
 * Verifies that the report respects the date boundary filter (from_date to to_date)
 * and excludes card sales occurring outside the date range.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-01', 'Card Report Filter Defaults & Date Range Filtering');

$date_in_range  = '2029-07-10';
$date_out_range = '2029-07-20';

try {
    reset_test_date_data($connection, $date_in_range);
    reset_test_date_data($connection, $date_out_range);

    // Insert sale within target date range
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$date_in_range', 50.00, 200.00, 10000.00, 100.00, 9900.00)");

    // Insert sale OUTSIDE target date range
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$date_out_range', 30.00, 200.00, 6000.00, 60.00, 5940.00)");

    // Query for date range: 2029-07-01 to 2029-07-15
    $report = get_card_report_data($connection, '2029-07-01', '2029-07-15', 0, 0);

    assert_eq($report['overall']['total_swipes'], 1, "Should return exactly 1 transaction in range");
    assert_eq($report['overall']['total_volume'], 50.00, "Volume must be 50.00 Ltr (excluding out-of-range sale)");
    assert_eq($report['overall']['total_gross_amount'], 10000.00, "Gross amount must be Rs. 10,000.00");
    assert_eq($report['overall']['total_net_revenue'], 9900.00, "Net revenue must be Rs. 9,900.00");

} finally {
    reset_test_date_data($connection, $date_in_range);
    reset_test_date_data($connection, $date_out_range);
}

exit(print_suite_summary('TC-CARD-01'));
