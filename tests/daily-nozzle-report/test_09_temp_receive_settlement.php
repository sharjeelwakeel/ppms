<?php
/**
 * Test Case 09: Temp Receive Settlement (Wasoli Attached Loan Voucher)
 *
 * Verifies that when a driver settles an old temporary loan chit (wasoli) alongside
 * fresh fuel pumping, ONLY the fresh fuel pumped today (issue_quantity = 50.00 Ltr)
 * is counted against today's nozzle physical meter throughput, NOT the historic loan (wasoli = 30.00 Ltr).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-09', 'Temp Receive Settlement (Wasoli Attached Loan Voucher)');

$test_date = '2029-02-09';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // Insert today's permanent slip settling the loan chit + pumping 50 Ltr fresh fuel
    // Total voucher quantity = 80 Ltr (50 fresh + 30 wasoli). Amount = 16,000.
    // Physical fuel leaving nozzle gun TODAY is strictly 50 Ltr!
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity, wasoli, temp_slip_no) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'VOUCH-5501', 'Permanent Slip', 1, 'KHI-1122', 80.00, 200.00, 16000.00, 16000.00, 50.00, 30.00, 'TMP-HIST-01')");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq(count($data['credit_rows']), 1, "Should return 1 credit record for today's permanent slip");
    $slip = $data['credit_rows'][0];
    assert_eq($slip['temp_slip_no'], 'TMP-HIST-01', 'Voucher must link to settled loan slip number');
    assert_eq(floatval($slip['wasoli']), 30.00, 'Attached loan recovery (wasoli) must be recorded as 30.00 Ltr');
    assert_eq(floatval($slip['issue_quantity']), 50.00, 'Physical nozzle throughput today must STRICTLY be 50.00 Ltr (fresh fuel pumped)');
    assert_eq($data['total_credit_issued_litres'], 50.00, 'Daily report settled credit litres must be exactly 50.00 Ltr to match meter throughput');
    assert_eq($data['total_credit_amount'], 16000.00, 'Credit billed amount reflects full voucher value (Rs. 16,000.00)');

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-09'));
