<?php
/**
 * Test Case 01: Outstanding vs Paid Slips Filtering
 * PPMS (Petrol Pump Management System)
 *
 * Verifies that the Customer Monthly Bill strictly excludes paid slips
 * when status_filter is 'outstanding' (default), and includes them when 'all'.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/customer_monthly_bill_helper.php';

test_header('TC-CMB-01', 'Outstanding vs Paid Slips Filtering');

$cust_id = 9991;
$date = '2028-05-10';

try {
    // Clean up any test records
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$cust_id'");

    // Insert dummy customer
    mysqli_query($connection, "INSERT INTO tbl_customers (id, name, status) VALUES ('$cust_id', 'Test Customer 9991', 'Active')");

    // Slip 1: Unpaid (Due Rs. 5,000)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, quantity, rate, amount, charge_amount, paid_amount, payment_status)
        VALUES 
        (1, '$date', '$date', 1, 'TEST-UNPAID', 'Permanent Slip', '$cust_id', 20.00, 250.00, 5000.00, 5000.00, 0.00, 'Unpaid')");

    // Slip 2: Paid (Due Rs. 0)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, quantity, rate, amount, charge_amount, paid_amount, payment_status)
        VALUES 
        (1, '$date', '$date', 1, 'TEST-PAID', 'Permanent Slip', '$cust_id', 10.00, 250.00, 2500.00, 2500.00, 2500.00, 'Paid')");

    // Slip 3: Partial (Charge Rs. 4,000, Paid Rs. 1,500, Due Rs. 2,500)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, quantity, rate, amount, charge_amount, paid_amount, payment_status)
        VALUES 
        (1, '$date', '$date', 1, 'TEST-PARTIAL', 'Permanent Slip', '$cust_id', 16.00, 250.00, 4000.00, 4000.00, 1500.00, 'Partial')");

    // Test Outstanding Only Bill
    $out_data = get_customer_monthly_bill_data($connection, $cust_id, '2028-05-01', '2028-05-31');
    assert_eq($out_data['total_coupons'], 2, "Bill must return exactly 2 vouchers (1 unpaid + 1 partial)");
    assert_eq($out_data['total_amount'], 7500.00, "Outstanding bill amount must be Rs. 5,000 + Rs. 2,500 = Rs. 7,500.00");
    
    // Check that paid slip is completely absent
    $coupons = array_column($out_data['transactions'], 'coupon');
    assert_true(!in_array('TEST-PAID', $coupons), "Paid slip must NEVER appear on the bill");
    assert_true(in_array('TEST-UNPAID', $coupons), "Unpaid slip must appear on the bill");
    assert_true(in_array('TEST-PARTIAL', $coupons), "Partial slip must appear on the bill with balance due");

} finally {
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$cust_id'");
}

exit(print_suite_summary('TC-CMB-01'));
