<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/cash_automation_helper.php';

$test_date = '2029-01-13';
$test_shift = 1;
$nozzle_id = 1;

test_header('TC-CASH-13', 'Conflict Detection AJAX Pre-Check Endpoint');

try {
    reset_test_date_data($connection, $test_date);

    // 1. Initial check on empty shift: must return null
    $empty_check = check_existing_cash_sale_for_shift($connection, $test_date, $test_shift, $nozzle_id);
    assert_true($empty_check === null, "Pre-check on empty shift must return NULL");

    // 2. Insert a cash record
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales 
    (sale_date, shift_id, nozzle_id, item_id, rate, amount, quantity, notes, is_manual_override)
    VALUES ('$test_date', '$test_shift', '$nozzle_id', 1, 200.00, 100000.00, 500.00, 'Test Note', 0)");

    // 3. Re-check: must detect existing record with payload
    $detected = check_existing_cash_sale_for_shift($connection, $test_date, $test_shift, $nozzle_id);

    assert_true(!empty($detected), "Existing record must be detected by helper");
    assert_eq(floatval($detected['quantity'] ?? 0), 500.00, "Detected quantity must match (500.00 Ltr)");
    assert_eq(floatval($detected['amount'] ?? 0), 100000.00, "Detected amount must match (Rs. 100,000.00)");
    assert_true(!empty($detected['nozzle_name']), "Detected payload must include nozzle name for modal prompt");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CASH-13'));
