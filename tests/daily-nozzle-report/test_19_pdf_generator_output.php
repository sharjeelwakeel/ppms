<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['loggedInUser'] = 1;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-19', 'PDF Generator Clean Output & Domain Badges Rendering');

$test_date = '2029-02-19';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter Reading
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 20000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 5000.00, 5100.00, 100.00, 0.00, 100.00, 200.00, 20000.00)");

    // 2. Balanced Slip
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity, ref_slip_no) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'BAL-99', 'Balanced Slip', 1, 'V-BAL', 40.00, 200.00, 8000.00, 0.00, 40.00, 'ORIG-10')");

    // 3. Temp Receive Slip
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity, wasoli, temp_slip_no) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'PERM-55', 'Permanent Slip', 1, 'V-PERM', 60.00, 200.00, 12000.00, 16000.00, 60.00, 20.00, 'TMP-77')");

    // Set GET parameters for PDF generation
    $_GET['nozzle_id'] = $nozzle_id;
    $_GET['date'] = $test_date;
    $_GET['shift_id'] = 0;

    ob_start();
    require 'd:/xampp/htdocs/ppms/reports/generate-pdf-nozzle-report.php';
    $output = ob_get_clean();

    assert_true(!empty($output), "PDF generator should produce non-empty HTML output");
    assert_true(strpos($output, 'DAILY NOZZLE PERFORMANCE') !== false, "Output must contain report title");
    assert_true(strpos($output, 'BAL-99') !== false, "Output must contain Balanced slip #BAL-99");
    assert_true(strpos($output, 'Balanced') !== false, "Output must display Balanced badge");
    assert_true(strpos($output, '(Prepaid)') !== false, "Output must display (Prepaid) badge for balanced slip");
    assert_true(strpos($output, 'Settled #TMP-77') !== false, "Output must display Settled loan badge for temp receive wasoli");
    assert_true(strpos($output, 'PERM-55') !== false, "Output must contain permanent slip #PERM-55");
    assert_true(stripos($output, 'NET NOZZLE OPERATING YIELD') !== false, "Output must contain Net Operating Yield metric");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-19'));
?>
