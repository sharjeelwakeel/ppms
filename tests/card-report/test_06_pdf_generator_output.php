<?php
/**
 * Test Case 06: PDF Companion HTML Generation & Header Verification
 *
 * Verifies that reports/generate-pdf-card-report.php renders valid HTML,
 * includes company branding, report title, KPI metrics, and settlement matrix.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['loggedInUser'] = 1;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-06', 'PDF Companion HTML Generation & Header Verification');

$test_date = '2029-07-15';

try {
    reset_test_date_data($connection, $test_date);

    // Insert a card transaction
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount) 
        VALUES 
        (1, 1, 1, '$test_date', 80.00, 250.00, 20000.00, 200.00, 19800.00)");

    $_GET['from_date']       = $test_date;
    $_GET['to_date']         = $test_date;
    $_GET['card_machine_id'] = 0;
    $_GET['shift_id']        = 0;

    ob_start();
    include 'd:/xampp/htdocs/ppms/reports/generate-pdf-card-report.php';
    $output = ob_get_clean();

    assert_true(!empty($output), "PDF output buffer must not be empty");
    assert_true(strpos($output, 'CARD SETTLEMENT REPORT') !== false, "Output must contain 'CARD SETTLEMENT REPORT' title");
    assert_true(strpos($output, 'POS Card Machine Performance Matrix') !== false, "Output must contain Machine Performance Matrix header");
    assert_true(strpos($output, 'Daily Shift Settlement Rollup') !== false, "Output must contain Shift Settlement Rollup header");
    assert_true(strpos($output, '20,000.00') !== false, "Output must contain Gross Sales value Rs. 20,000.00");
    assert_true(strpos($output, '19,800.00') !== false, "Output must contain Net Revenue value Rs. 19,800.00");
    assert_true(strpos($output, 'window.print') !== false, "Output must contain auto-print JavaScript trigger");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CARD-06'));
