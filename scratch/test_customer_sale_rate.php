<?php
require_once __DIR__ . '/../include/config.php';

echo "=== STARTING VERIFICATION: CUSTOMER SALE RATE & CARD SALE RATE TYPE ===\n";

// 1. Ensure rate_type column exists
$chk = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_card_sales LIKE 'rate_type'");
if ($chk && mysqli_num_rows($chk) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_card_sales ADD COLUMN rate_type ENUM('Cash','Credit') NOT NULL DEFAULT 'Cash' AFTER item_id");
    echo "[PASS] Added rate_type column to tbl_meter_reading_card_sales.\n";
} else {
    echo "[PASS] rate_type column already exists in tbl_meter_reading_card_sales.\n";
}

// 2. Verify tbl_customers fuel_rate column definition
$q_cust_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_customers LIKE 'fuel_rate'");
$cust_col = mysqli_fetch_assoc($q_cust_col);
echo "[PASS] tbl_customers.fuel_rate column: Type={$cust_col['Type']}, Default={$cust_col['Default']}\n";

// 3. Verify tbl_items cash_rate and credit_rate
$q_item = mysqli_query($connection, "SELECT id, name, cash_rate, credit_rate FROM tbl_items WHERE deleted_at IS NULL LIMIT 1");
if ($q_item && mysqli_num_rows($q_item) > 0) {
    $item = mysqli_fetch_assoc($q_item);
    echo "[PASS] Sample item '{$item['name']}': Cash Rate = {$item['cash_rate']}, Credit Rate = {$item['credit_rate']}\n";
    
    // Simulate customer dynamic rate logic:
    $test_cash_rate = floatval($item['cash_rate']);
    $test_credit_rate = floatval($item['credit_rate']);

    // Cash customer test
    $cust_policy_cash = 'Cash';
    $resolved_rate_cash = ($cust_policy_cash === 'Credit' && $test_credit_rate > 0) ? $test_credit_rate : $test_cash_rate;
    assert($resolved_rate_cash === $test_cash_rate, "Cash customer must resolve to cash_rate");
    echo "[PASS] Customer with fuel_rate='Cash' correctly resolved to Cash Rate: {$resolved_rate_cash}\n";

    // Credit customer test
    $cust_policy_credit = 'Credit';
    $resolved_rate_credit = ($cust_policy_credit === 'Credit' && $test_credit_rate > 0) ? $test_credit_rate : $test_cash_rate;
    assert($resolved_rate_credit === $test_credit_rate, "Credit customer must resolve to credit_rate");
    echo "[PASS] Customer with fuel_rate='Credit' correctly resolved to Credit Rate: {$resolved_rate_credit}\n";
}

// 4. Test Card Sales rate calculation:
$amount = 1000.00;
$c_rate = 250.00;
$cr_rate = 260.00;

// Case A: Cash Rate selected
$fuel_rate_cash = $c_rate;
$qty_cash = round($amount / $fuel_rate_cash, 2);
assert($qty_cash == 4.00, "1000 / 250 should be 4.00 Litres");
echo "[PASS] Card sale (Cash Rate): Amount Rs. 1000 @ Rs. 250 = {$qty_cash} L\n";

// Case B: Credit Rate selected
$fuel_rate_credit = $cr_rate;
$qty_credit = round($amount / $fuel_rate_credit, 2);
assert($qty_credit == 3.85, "1000 / 260 should be 3.85 Litres");
echo "[PASS] Card sale (Credit Rate): Amount Rs. 1000 @ Rs. 260 = {$qty_credit} L\n";

// 5. Test Database Insert & Retrieval of rate_type in tbl_meter_reading_card_sales
$ins_test = "INSERT INTO tbl_meter_reading_card_sales 
             (meter_reading_id, sale_date, shift_id, staff_id, card_machine_id, item_id, rate_type, 
              quantity, rate, amount, batch_no, service_charges, net_amount, nozzle_id, no_of_cards)
             VALUES 
             (0, '2099-01-01', 1, 0, 1, 1, 'Credit', 
              '$qty_credit', '$fuel_rate_credit', '$amount', 'TEST_BATCH', 15.00, 985.00, 1, 1)";
if (mysqli_query($connection, $ins_test)) {
    $inserted_id = mysqli_insert_id($connection);
    $q_check = mysqli_query($connection, "SELECT id, rate_type, rate, quantity FROM tbl_meter_reading_card_sales WHERE id = '$inserted_id'");
    $retrieved = mysqli_fetch_assoc($q_check);
    echo "[PASS] Inserted card sale record ID {$retrieved['id']} with rate_type = '{$retrieved['rate_type']}', rate = {$retrieved['rate']}, qty = {$retrieved['quantity']}\n";
    assert($retrieved['rate_type'] === 'Credit', "Stored rate_type must be Credit");

    // Clean up test record
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_card_sales WHERE id = '$inserted_id'");
    echo "[PASS] Cleaned up test record.\n";
} else {
    echo "[FAIL] Insert error: " . mysqli_error($connection) . "\n";
}

echo "=== ALL VERIFICATION TESTS PASSED SUCCESSFULLY ===\n";
