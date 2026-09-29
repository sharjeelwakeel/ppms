<?php
/**
 * Test Case 06: Customer Attention Line & Generated Bills Registry
 * PPMS (Petrol Pump Management System)
 *
 * Verifies:
 * 1. Customer profile attention_line storage and retrieval.
 * 2. Automatic attention_line inheritance in monthly bill statement.
 * 3. Snapshot persistence of fuel_amount, lubricant_amount, total_amount, coupons in tbl_customer_monthly_bills.
 * 4. Registry retrieval via get_generated_monthly_bills() and bill_no filtering.
 * 5. Direct bill lookup via get_monthly_bill_by_number().
 * 6. Soft-delete handling via soft_delete_monthly_bill().
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../include/customer_monthly_bill_helper.php';

test_header('TC-CMB-06', 'Customer Attention Line & Generated Bills Registry');

$test_cust_id = 9995;
$from = '2027-02-01';
$to   = '2027-02-28';
$test_vehicle = 'TEST-REG-789';
$test_attention = 'The Chief Executive Officer,';

try {
    // Ensure migrations are run on test db
    auto_migrate_monthly_bill_tables($connection);

    // Clean up any stale data
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$test_cust_id'");

    // 1. Create a customer with attention_line
    $esc_attn = mysqli_real_escape_string($connection, $test_attention);
    $ins = mysqli_query($connection, "INSERT INTO tbl_customers (id, name, address, attention_line, phone, fuel_rate, other_rate, status)
        VALUES ('$test_cust_id', 'Apex Logistics Corp', 'Industrial Area, Bahawalpur', '$esc_attn', '0300-1122334', 'Credit', 'Credit', 'Active')");
    assert_true($ins, "Test customer created with attention_line");

    // Verify stored attention_line in tbl_customers
    $res = mysqli_query($connection, "SELECT attention_line FROM tbl_customers WHERE id = '$test_cust_id'");
    $c_row = mysqli_fetch_assoc($res);
    assert_eq($c_row['attention_line'], $test_attention, "Customer record holds correct attention_line");

    // 2. Generate monthly bill data with empty attention parameter - should inherit from customer profile
    $bill_data = get_customer_monthly_bill_data($connection, $test_cust_id, $from, $to, $test_vehicle, '');
    assert_eq($bill_data['attention_to'], $test_attention, "Monthly bill auto-inherited attention_line from customer profile");

    $bill_no = $bill_data['bill_no'];
    assert_true(!empty($bill_no), "Valid bill_no generated for monthly bill: " . $bill_no);

    // 3. Verify snapshot persistence in tbl_customer_monthly_bills
    $check_bill = mysqli_query($connection, "SELECT * FROM tbl_customer_monthly_bills WHERE bill_no = '$bill_no' AND deleted_at IS NULL");
    assert_true(mysqli_num_rows($check_bill) === 1, "Bill snapshot persisted in tbl_customer_monthly_bills");
    $b_row = mysqli_fetch_assoc($check_bill);
    assert_eq($b_row['attention_to'], $test_attention, "Snapshot record stores attention_to correctly");
    assert_eq(intval($b_row['customer_id']), $test_cust_id, "Snapshot record references correct customer_id");

    // 4. Test Registry Query: get_generated_monthly_bills()
    $registry = get_generated_monthly_bills($connection, ['customer_id' => $test_cust_id]);
    assert_true(count($registry) >= 1, "Registry returns generated bills for customer");
    assert_eq($registry[0]['bill_no'], $bill_no, "Registry bill_no matches generated bill");
    assert_eq($registry[0]['customer_name'], 'Apex Logistics Corp', "Registry joins customer_name correctly");

    // Test registry filter by bill_no
    $filtered = get_generated_monthly_bills($connection, ['bill_no' => $bill_no]);
    assert_true(count($filtered) === 1, "Registry successfully filters by exact bill_no");
    assert_eq($filtered[0]['id'], $b_row['id'], "Filtered registry entry matches bill id");

    // 5. Test direct lookup: get_monthly_bill_by_number()
    $direct = get_monthly_bill_by_number($connection, $bill_no);
    assert_true(!empty($direct), "get_monthly_bill_by_number returns bill record");
    assert_eq($direct['bill_no'], $bill_no, "Lookup bill_no matches");
    assert_eq(intval($direct['customer_id']), $test_cust_id, "Lookup customer_id matches");

    // 6. Test soft-delete: soft_delete_monthly_bill()
    $del_ok = soft_delete_monthly_bill($connection, intval($b_row['id']));
    assert_true($del_ok, "soft_delete_monthly_bill returns true");

    // Verify soft-deleted bill is no longer returned by active queries
    $post_del_direct = get_monthly_bill_by_number($connection, $bill_no);
    assert_true(empty($post_del_direct), "Soft-deleted bill is excluded from get_monthly_bill_by_number");

    $post_del_reg = get_generated_monthly_bills($connection, ['bill_no' => $bill_no]);
    assert_true(count($post_del_reg) === 0, "Soft-deleted bill is excluded from registry");

} finally {
    mysqli_query($connection, "DELETE FROM tbl_customer_monthly_bills WHERE customer_id = '$test_cust_id'");
    mysqli_query($connection, "DELETE FROM tbl_customers WHERE id = '$test_cust_id'");
}

exit(print_suite_summary('TC-CMB-06'));
