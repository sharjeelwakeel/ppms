<?php
/**
 * Test Case 04: Bill Number Persistence & Auto-Increment
 * PPMS (Petrol Pump Management System)
 *
 * Verifies that bill numbers persist for the same customer/date range,
 * and auto-increment sequentially starting from 64343.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/customer_monthly_bill_helper.php';

test_header('TC-CMB-04', 'Bill Number Persistence & Auto-Increment');

$test_cust = 9992;
$from = '2027-01-01';
$to   = '2027-01-31';

try {
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust'");

    // First call: assigns next sequential bill number
    $bill_no1 = get_or_create_monthly_bill_no($connection, $test_cust, $from, $to, 'LEA 123');
    assert_true(is_numeric($bill_no1) && intval($bill_no1) >= 64343, "Bill number must be >= 64343");

    // Second call for same range: should return identical bill number
    $bill_no2 = get_or_create_monthly_bill_no($connection, $test_cust, $from, $to, 'LEA 123');
    assert_eq($bill_no1, $bill_no2, "Bill number must persist across multiple calls for same period");

    // Update with custom bill number
    $custom = "BILL-7777";
    $bill_no3 = get_or_create_monthly_bill_no($connection, $test_cust, $from, $to, 'LEA 123', $custom);
    assert_eq($bill_no3, $custom, "Custom bill number must update and be returned");

} finally {
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust'");
}

exit(print_suite_summary('TC-CMB-04'));
