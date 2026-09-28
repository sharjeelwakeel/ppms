<?php
/**
 * Test Case 07: PDF Companion HTML Generation & Print Layout Verification
 *
 * Verifies that reports/generate-pdf-card-balance-report.php renders clean,
 * error-free HTML containing:
 * - Station header branding & report title
 * - Period, Terminal, Shift & Status meta badges
 * - Executive 5-box KPI metrics (Gross, Net Expected, Amount Received, Balance Due, Recovery %)
 * - POS Card Machine Recovery & Outstanding Summary table
 * - Daily Shift Settlement Breakdown table
 * - Executive Signatures area and window.print() trigger
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['loggedInUser'] = 1;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-07', 'PDF Companion HTML Generation & Print Layout Verification');

$test_date = '2029-08-16';

try {
    reset_test_date_data($connection, $test_date);

    // 1. Insert Batch A (Partial): Net Rs. 14,955, Paid Rs. 10,000, Due Rs. 4,955
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-PDF-1', 15, 15000.00, 0.3000, 45.00, 14955.00, 10000.00, 'Partial')");

    // 2. Insert Batch B (Paid): Net Rs. 24,925, Paid Rs. 24,925, Due Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (2, '$test_date', 2, 'BATCH-PDF-2', 25, 25000.00, 0.3000, 75.00, 24925.00, 24925.00, 'Paid')");

    $_GET['from_date']       = $test_date;
    $_GET['to_date']         = $test_date;
    $_GET['card_machine_id'] = 0;
    $_GET['shift_id']        = 0;
    $_GET['status']          = 'all';

    ob_start();
    include 'd:/xampp/htdocs/ppms/reports/generate-pdf-card-balance-report.php';
    $output = ob_get_clean();

    assert_true(!empty($output), "PDF companion output buffer must not be empty");
    assert_true(strpos($output, 'CARD RECOVERY & BALANCE') !== false, "Output must contain 'CARD RECOVERY & BALANCE' badge");
    assert_true(strpos($output, 'POS Card Machine Recovery & Outstanding Summary') !== false, "Output must contain Machine Recovery Summary section");
    assert_true(strpos($output, 'Daily Shift Settlement Breakdown') !== false, "Output must contain Daily Shift Settlement Breakdown section");
    assert_true(strpos($output, 'Amount Received (Get)') !== false, "Output must contain 'Amount Received (Get)' KPI title");
    assert_true(strpos($output, 'Balance Due') !== false, "Output must contain 'Balance Due' KPI title");
    assert_true(strpos($output, '34,925.00') !== false, "Output must display total paid Rs. 34,925.00 (10,000 + 24,925)");
    assert_true(strpos($output, '4,955.00') !== false, "Output must display outstanding balance Rs. 4,955.00");
    assert_true(strpos($output, 'Approved By (Station Auditor') !== false, "Output must contain Auditor / Owner approval signature box");
    assert_true(strpos($output, 'window.print') !== false, "Output must contain window.print() action trigger");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-07'));
