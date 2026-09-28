<?php
/**
 * Test Case 05: Soft Delete Exclusion
 * PPMS (Petrol Pump Management System)
 *
 * Verifies that soft-deleted slips are strictly excluded from the customer bill.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/customer_monthly_bill_helper.php';

test_header('TC-CMB-05', 'Soft Delete Exclusion');

$cust_id = 9993;
$date = '2028-06-15';

try {
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$cust_id'");

    mysqli_query($connection, "INSERT INTO tbl_customers (id, name, status) VALUES ('$cust_id', 'Test Customer 9993', 'Active')");

    // Slip 1: Active
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, quantity, rate, amount, charge_amount, paid_amount, payment_status, deleted_at)
        VALUES 
        (1, '$date', '$date', 1, 'TEST-ACTIVE', 'Permanent Slip', '$cust_id', 20.00, 250.00, 5000.00, 5000.00, 0.00, 'Unpaid', NULL)");

    // Slip 2: Soft Deleted
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, quantity, rate, amount, charge_amount, paid_amount, payment_status, deleted_at)
        VALUES 
        (1, '$date', '$date', 1, 'TEST-DELETED', 'Permanent Slip', '$cust_id', 30.00, 250.00, 7500.00, 7500.00, 0.00, 'Unpaid', '2028-06-16 10:00:00')");

    $data = get_customer_monthly_bill_data($connection, $cust_id, '2028-06-01', '2028-06-30', '', ['status_filter' => 'outstanding']);

    assert_eq($data['total_coupons'], 1, "Only non-deleted voucher must be included");
    assert_eq($data['total_amount'], 5000.00, "Bill amount must only include active voucher");
    assert_eq($data['transactions'][0]['coupon'], 'TEST-ACTIVE', "Included voucher must be the active one");

} finally {
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$cust_id'");
}

exit(print_suite_summary('TC-CMB-05'));
