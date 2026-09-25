<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-01', 'Mandatory Nozzle Selection & Filter Routing');

$test_date = '2029-02-01';

try {
    reset_test_date_data($connection, $test_date);

    // Call report data with nozzle_id = 0 (single nozzle helper returns empty structure)
    $data = get_daily_nozzle_report_data($connection, 0, $test_date, 0);

    assert_true(empty($data['selected_nozzle']), "Selected nozzle must be null/empty when nozzle_id = 0");
    assert_eq(count($data['meter_rows']), 0, "Meter rows must be empty when nozzle_id = 0");
    assert_eq(count($data['cash_rows']), 0, "Cash rows must be empty when nozzle_id = 0");
    assert_eq(count($data['credit_rows']), 0, "Credit rows must be empty when nozzle_id = 0");
    assert_eq(count($data['card_rows']), 0, "Card rows must be empty when nozzle_id = 0");
    assert_eq(count($data['expense_rows']), 0, "Expense rows must be empty when nozzle_id = 0");
    assert_eq($data['total_meter_litres'], 0.00, "Total meter litres must be 0.00");
    assert_eq($data['total_settled_litres'], 0.00, "Total settled litres must be 0.00");

    // Call station report data (All nozzles station view)
    $station_data = get_daily_station_report_data($connection, $test_date, 0);
    assert_true($station_data['is_station_summary'], "Station report data must have is_station_summary = true");
    assert_true(isset($station_data['nozzle_matrix']), "Station report data must contain nozzle_matrix");
    assert_true(isset($station_data['product_summaries']), "Station report data must contain product_summaries");
    assert_eq($station_data['station_meter_litres'], 0.00, "Clean state station meter litres must be 0.00");
    assert_eq($station_data['station_status'], 'Balanced', "Clean state station status must be Balanced");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-01'));
