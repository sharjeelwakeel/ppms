<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['loggedInUser'] = 1;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-20', 'Station-Wide All-Nozzles Performance & Reconciliation Audit (nozzle_id = 0)');

$test_date = '2029-02-20';
$nozzle1_id = 1;
$nozzle2_id = 2;

try {
    reset_test_date_data($connection, $test_date);

    // Nozzle 1: 500 Ltr @ Rs. 200 = Rs. 100,000 (Cash: 300L/Rs.60,000, Credit: 200L/Rs.40,000)
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 100000.00)");
    $mr1_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr1_id', '$nozzle1_id', 1000.00, 1500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle1_id', '$test_date', 1, 300.00, 200.00, 60000.00, '$mr1_id')");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales (nozzle_id, sale_date, shift_id, slip_no, slip_type, account_number, vehicle_number, rate, quantity, issue_quantity, amount) 
        VALUES ('$nozzle1_id', '$test_date', 1, 'SLIP-NOZ1', 'Permanent Slip', 1, 'LES-111', 200.00, 200.00, 200.00, 40000.00)");

    // Nozzle 2: 700 Ltr @ Rs. 250 = Rs. 175,000 (Cash: 400L/Rs.100,000, Card: 300L/Rs.75,000)
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 175000.00)");
    $mr2_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr2_id', '$nozzle2_id', 2000.00, 2700.00, 700.00, 0.00, 700.00, 250.00, 175000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, meter_reading_id) 
        VALUES ('$nozzle2_id', '$test_date', 1, 400.00, 250.00, 100000.00, '$mr2_id')");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales (nozzle_id, sale_date, shift_id, card_machine_id, rate, quantity, amount, service_charges, net_amount) 
        VALUES ('$nozzle2_id', '$test_date', 1, 1, 250.00, 300.00, 75000.00, 750.00, 74250.00)");

    // Expenses: Nozzle 1 expense Rs. 1,000, General Station expense Rs. 2,000
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, expense_type_id, nozzle_id, amount, payment_method) 
        VALUES ('$test_date', 1, '$nozzle1_id', 1000.00, 'Cash')");
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, expense_type_id, nozzle_id, amount, payment_method) 
        VALUES ('$test_date', 1, NULL, 2000.00, 'Cash')");

    // Execute station report
    $station = get_daily_station_report_data($connection, $test_date, 0);

    assert_true($station['is_station_summary'], "Should be in station summary mode");
    assert_eq($station['station_meter_litres'], 1200.00, "Station meter litres must be 500 + 700 = 1,200.00 Ltr");
    assert_eq($station['station_meter_revenue'], 275000.00, "Station meter revenue must be 100,000 + 175,000 = Rs. 275,000.00");
    assert_eq($station['station_cash_litres'], 700.00, "Station cash litres must be 300 + 400 = 700.00 Ltr");
    assert_eq($station['station_cash_amount'], 160000.00, "Station cash amount must be 60,000 + 100,000 = Rs. 160,000.00");
    assert_eq($station['station_credit_litres'], 200.00, "Station credit litres must be 200.00 Ltr");
    assert_eq($station['station_credit_amount'], 40000.00, "Station credit amount must be Rs. 40,000.00");
    assert_eq($station['station_card_litres'], 300.00, "Station card litres must be 300.00 Ltr");
    assert_eq($station['station_card_amount'], 75000.00, "Station card amount must be Rs. 75,000.00");
    assert_eq($station['station_settled_litres'], 1200.00, "Total settled litres must be 700 + 200 + 300 = 1,200.00 Ltr");
    assert_eq($station['station_volume_variance'], 0.00, "Volume variance must be exactly 0.00 Ltr");
    assert_eq($station['station_financial_variance'], 0.00, "Financial variance must be exactly Rs. 0.00");
    assert_eq($station['station_status'], 'Balanced', "Station status must be Balanced");
    assert_eq($station['station_nozzle_expenses'], 1000.00, "Nozzle-specific expenses must be Rs. 1,000.00");
    assert_eq($station['station_general_expenses'], 2000.00, "General expenses must be Rs. 2,000.00");
    assert_eq($station['station_total_expenses'], 3000.00, "Total station expenses must be Rs. 3,000.00");
    assert_eq($station['station_net_yield'], 272000.00, "Net Station Yield must be 275,000 - 3,000 = Rs. 272,000.00");

    // Test PDF rendering with nozzle_id = 0
    $_GET['nozzle_id'] = '0';
    $_GET['shift_id']  = '0';
    $_GET['date']      = $test_date;
    $_SESSION['loggedInUser'] = 1;

    ob_start();
    include 'd:/xampp/htdocs/ppms/reports/generate-pdf-nozzle-report.php';
    $pdf_html = ob_get_clean();

    assert_true(!empty($pdf_html), "PDF output for station summary must be non-empty");
    assert_true(strpos($pdf_html, 'STATION-WIDE DAILY NOZZLE SUMMARY &amp; AUDIT REPORT') !== false, "PDF must contain station summary title");
    assert_true(strpos($pdf_html, 'Dispensing Nozzles Comparative Audit Matrix') !== false, "PDF must render the comparative audit matrix");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-20'));
