<?php
/**
 * Test Case 02: Data Aggregation & Mathematical Integrity
 * PPMS (Petrol Pump Management System)
 *
 * Verifies that fuel volumes, fuel subtotals, product subtotals,
 * and category breakdown summaries are 100% mathematically correct.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/customer_monthly_bill_helper.php';

test_header('TC-CMB-02', 'Data Aggregation & Category Summary');

$test_cust = 9992;
$from = '2026-08-01';
$to   = '2026-08-31';

try {
    // 1. Ensure test customer exists
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_lubricant_sale_invoices WHERE customer_id = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$test_cust'");

    mysqli_query($connection, "INSERT INTO tbl_customers (id, name, status) VALUES ('$test_cust', 'The Islamia University of Bahawalpur', 'Active')");

    // 2. Ensure item 'Action+ Diesel' exists
    $item_res = mysqli_query($connection, "SELECT id FROM tbl_items WHERE name = 'Action+ Diesel' LIMIT 1");
    if ($item_res && mysqli_num_rows($item_res) > 0) {
        $item_id = mysqli_fetch_assoc($item_res)['id'];
    } else {
        mysqli_query($connection, "INSERT INTO tbl_items (name, cash_rate, credit_rate, purchase_rate, unit) VALUES ('Action+ Diesel', 390.00, 395.48, 380.00, 'Ltr')");
        $item_id = mysqli_insert_id($connection);
    }

    $nozzle_res = mysqli_query($connection, "SELECT id FROM tbl_nozzles WHERE item_id = '$item_id' LIMIT 1");
    if ($nozzle_res && mysqli_num_rows($nozzle_res) > 0) {
        $nozzle_id = mysqli_fetch_assoc($nozzle_res)['id'];
    } else {
        mysqli_query($connection, "INSERT INTO tbl_nozzles (name, tank_id, item_id, status) VALUES ('Test Nozzle Diesel', 1, '$item_id', 'Active')");
        $nozzle_id = mysqli_insert_id($connection);
    }

    // 3. Ensure Category 'Deo 6000 4L' and product exist
    $cat_res = mysqli_query($connection, "SELECT id FROM tbl_product_categories WHERE name = 'Deo 6000 4L' LIMIT 1");
    if ($cat_res && mysqli_num_rows($cat_res) > 0) {
        $cat_id = mysqli_fetch_assoc($cat_res)['id'];
    } else {
        mysqli_query($connection, "INSERT INTO tbl_product_categories (name, status) VALUES ('Deo 6000 4L', 'Active')");
        $cat_id = mysqli_insert_id($connection);
    }

    $lub_res = mysqli_query($connection, "SELECT id FROM tbl_lubricant_products WHERE category_id = '$cat_id' LIMIT 1");
    if ($lub_res && mysqli_num_rows($lub_res) > 0) {
        $lub_id = mysqli_fetch_assoc($lub_res)['id'];
    } else {
        mysqli_query($connection, "INSERT INTO tbl_lubricant_products (name, category_id, cash_rate, credit_rate, purchase_rate, price) VALUES ('Deo 6000 Can', '$cat_id', 5900.00, 5980.00, 5500.00, 5980.00)");
        $lub_id = mysqli_insert_id($connection);
    }

    // 4. Insert 6 Diesel slips
    $fuel_slips = [
        ['date' => '2026-08-03', 'slip_no' => '1624', 'qty' => 58.00, 'rate' => 395.48, 'amount' => 22937.84],
        ['date' => '2026-08-03', 'slip_no' => '1623', 'qty' => 62.00, 'rate' => 395.48, 'amount' => 24519.76],
        ['date' => '2026-08-07', 'slip_no' => '8354', 'qty' => 30.00, 'rate' => 385.46, 'amount' => 11563.80],
        ['date' => '2026-08-12', 'slip_no' => '8363', 'qty' => 61.00, 'rate' => 385.45, 'amount' => 23512.45],
        ['date' => '2026-08-21', 'slip_no' => '8422', 'qty' => 52.00, 'rate' => 367.89, 'amount' => 19130.28],
        ['date' => '2026-08-27', 'slip_no' => '8426', 'qty' => 62.00, 'rate' => 375.04, 'amount' => 23252.48]
    ];
    foreach ($fuel_slips as $fs) {
        mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
            (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, paid_amount, payment_status)
            VALUES 
            ('$nozzle_id', '{$fs['date']}', '{$fs['date']}', 1, '{$fs['slip_no']}', 'Permanent Slip', '$test_cust', 'BRM 2779', {$fs['qty']}, {$fs['rate']}, {$fs['amount']}, {$fs['amount']}, 0.00, 'Unpaid')");
    }

    // 5. Insert 1 Lubricant invoice (8384)
    mysqli_query($connection, "INSERT INTO tbl_lubricant_sale_invoices 
        (invoice_no, date, shift_id, payment_type, slip_type, slip_no, slip_date, customer_id, vehicle_number, total_items, total_quantity, total_amount, charge_amount, paid_amount, payment_status)
        VALUES 
        ('LUB-TEST-8384', '2026-08-27', 1, 'Credit', 'Permanent Slip', '8384', '2026-08-27', '$test_cust', 'BRM 2779', 1, 1.50, 8970.00, 8970.00, 0.00, 'Unpaid')");
    $inv_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_lubricant_sales 
        (invoice_id, invoice_no, product_id, quantity, rate, amount, payment_type, date, shift_id)
        VALUES 
        ('$inv_id', 'LUB-TEST-8384', '$lub_id', 1.50, 5980.00, 8970.00, 'Credit', '2026-08-27', 1)");

    $bill_data = get_customer_monthly_bill_data($connection, $test_cust, $from, $to, 'BRM 2779', [
        'status_filter' => 'outstanding',
        'bill_no'       => '64343'
    ]);

    assert_eq($bill_data['total_coupons'], 7, "Total coupons must equal 7");
    assert_eq($bill_data['total_amount'], 133886.61, "Total bill amount must equal Rs. 133,886.61");
    assert_eq($bill_data['vehicle_display'], 'BRM 2779', "Vehicle must display BRM 2779");
    assert_eq($bill_data['bill_no'], '64343', "Bill number must equal 64343");

    // Verify that the lubricant item shows the category name 'Deo 6000 4L'
    $lub_transaction = null;
    foreach ($bill_data['transactions'] as $t) {
        if ($t['coupon'] === '8384') {
            $lub_transaction = $t;
            break;
        }
    }
    assert_true($lub_transaction !== null, "Lubricant transaction #8384 must be present in bill");
    assert_eq($lub_transaction['description'], 'Deo 6000 4L', "Lubricant line item description must display Category Name 'Deo 6000 4L'");

    // Check Diesel summary
    $diesel = $bill_data['category_summary']['Diesel'];
    assert_eq($diesel['quantity'], 325.00, "Total diesel quantity must equal 325 Ltr");
    assert_eq($diesel['amount'], 124916.61, "Diesel subtotal must equal Rs. 124,916.61");

    // Check Others summary
    $others = $bill_data['category_summary']['Others'];
    assert_eq($others['amount'], 8970.00, "Others (lubricants) subtotal must equal Rs. 8,970.00");

    // Check grand total calculation
    $computed_total = $diesel['amount'] + $others['amount'];
    assert_eq($computed_total, $bill_data['total_amount'], "Sum of categories must match total bill amount");

} finally {
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE account_number = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_lubricant_sales WHERE invoice_no = 'LUB-TEST-8384'");
    mysqli_query($connection, "DELETE FROM tbl_lubricant_sale_invoices WHERE customer_id = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$test_cust'");
}

exit(print_suite_summary('TC-CMB-02'));
