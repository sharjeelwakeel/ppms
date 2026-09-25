<?php
/**
 * Automated Test Case: TC-NOZ-07
 * Scenario: Standard Permanent Credit Sales Vouchers Aggregation
 * 
 * =========================================================================================
 * BUSINESS DOMAIN & REAL-WORLD PROBLEM EXPLANATION:
 * =========================================================================================
 * 1. Why does a "Permanent Slip" have both `quantity` and `issue_quantity`?
 *    A corporate customer issues an official fuel chit specifying an authorized quota
 *    (e.g., quantity = 100.00 Litres).
 *    When the vehicle pulls up to the pump, the vehicle's fuel tank might only accommodate
 *    80.00 Litres before reaching full capacity.
 * 
 * 2. Physical Dispensing vs Financial Quota:
 *    - Physical Fuel Pumped = `issue_quantity = 80.00 Litres`
 *      (This is the fuel that turned the nozzle totalizer wheels).
 *    - Remaining Balance = `balance_1 = 20.00 Litres`
 *      (Driver is given a balance chit to take the remaining 20 Litres on another day).
 *    - Billed Quota = `quantity = 100.00 Litres` @ Rs. 200 = Rs. 20,000.00.
 * 
 * 3. HOW THE TEST CASE & NOZZLE REPORT ACT:
 *    - The nozzle report must track:
 *      a) `total_credit_issued_litres = 80.00 Ltr` (for physical totalizer meter reconciliation).
 *      b) `total_credit_quota_litres = 100.00 Ltr` (for account quota tracking).
 *      c) `total_credit_amount = Rs. 20,000.00` (for financial ledger tracking).
 *    - The credit table displays [Permanent Slip] and [Remaining: 20.00 Ltr].
 * =========================================================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-07', 'Permanent Credit Sales Vouchers Aggregation');

$test_date = '2029-02-07';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // STEP 1: Insert standard permanent credit slip:
    // Quota = 100 Ltr, Issued = 80 Ltr, Balance = 20 Ltr @ Rs. 200 = Rs. 20,000.00
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, issue_quantity, balance_1) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'SLIP-701', 'Permanent Slip', 1, 'ABC-123', 100.00, 200.00, 20000.00, 20000.00, 80.00, 20.00)");

    // STEP 2: Execute Daily Nozzle Report Engine
    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    // STEP 3: Assertions
    assert_eq(count($data['credit_rows']), 1, "Should return 1 credit voucher record for Nozzle #1");
    assert_eq($data['total_credit_issued_litres'], 80.00, "Fuel issued to vehicle must equal 80.00 Ltr (nozzle physical throughput)");
    assert_eq($data['total_credit_quota_litres'], 100.00, "Billed quota litres must equal 100.00 Ltr (corporate voucher quota)");
    assert_eq($data['total_credit_amount'], 20000.00, "Credit amount must equal Rs. 20,000.00");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-07'));
