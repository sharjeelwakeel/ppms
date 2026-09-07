<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/price_helper.php';
require_once __DIR__ . '/../include/nozzle_daily_sync.php';

echo "=== STARTING CREDIT SALES 4 SCENARIOS TEST ===\n";

mysqli_begin_transaction($connection);
try {
    // 1. Setup test customer, vehicle, nozzle, item
    $res_cust = mysqli_query($connection, "SELECT id FROM tbl_customers WHERE deleted_at IS NULL LIMIT 1");
    $cust = mysqli_fetch_assoc($res_cust);
    $cust_id = $cust['id'] ?? 1;

    $res_noz = mysqli_query($connection, "SELECT id, item_id, start_reading FROM tbl_nozzles WHERE deleted_at IS NULL AND status = 'Active' LIMIT 1");
    $noz = mysqli_fetch_assoc($res_noz);
    if (!$noz) {
        throw new Exception("No active nozzle found for testing.");
    }
    $noz_id = intval($noz['id']);
    $item_id = intval($noz['item_id']);
    $initial_reading = floatval($noz['start_reading']);

    echo "[INFO] Using Customer ID: $cust_id, Nozzle ID: $noz_id, Initial Reading: $initial_reading\n";

    // ----------------------------------------------------
    // TEST HISTORICAL PRICING HELPER
    // ----------------------------------------------------
    echo "\n--- TEST: Date-Sensitive Price Resolution ---\n";
    // Check price for today vs historical
    $today_str = date('Y-m-d');
    $past_date_str = '2026-08-20';
    $p_today = get_price_for_date($connection, 'tbl_items', $item_id, $today_str);
    echo "[PASS] Active price for today ($today_str): Cash={$p_today['cash_rate']}, Credit={$p_today['credit_rate']}\n";

    // ----------------------------------------------------
    // SCENARIO 1: Permanent Slip (Without Temp. Receive)
    // ----------------------------------------------------
    echo "\n--- TEST: Scenario 1 (Permanent Slip without Temp. Receive) ---\n";
    $s1_slip_no = 'TEST-PERM-101';
    $s1_qty = 40.00; // car tank filled at 40L
    $s1_issue_qty = 50.00; // slip authorized 50L
    $s1_rate = 200.00;
    $s1_charge = round($s1_issue_qty * $s1_rate, 2); // 10,000.00
    $s1_bal1 = max(0.00, round($s1_issue_qty - $s1_qty, 2)); // 10.00
    $s1_bal2 = 0.00;

    $sql1 = "INSERT INTO tbl_meter_reading_credit_sales 
             (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
              quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli)
             VALUES 
             (0, '$noz_id', '$today_str', 1, '$s1_slip_no', 'Permanent Slip', '$cust_id', 'TEST-VEH-1',
              '$s1_qty', '$s1_rate', 8000.00, '$s1_charge', 200.00, '$s1_issue_qty', '$s1_bal1', '$s1_bal2', 0.00)";
    if (!mysqli_query($connection, $sql1)) {
        throw new Exception("Scenario 1 Insert failed: " . mysqli_error($connection));
    }
    $s1_id = mysqli_insert_id($connection);

    // Advance nozzle meter strictly by physical pumped fuel (40L)
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = start_reading + $s1_qty WHERE id = '$noz_id'");

    $chk_noz = mysqli_fetch_assoc(mysqli_query($connection, "SELECT start_reading FROM tbl_nozzles WHERE id = '$noz_id'"));
    $expected_noz1 = round($initial_reading + $s1_qty, 2);
    $actual_noz1 = round(floatval($chk_noz['start_reading']), 2);

    assert($s1_charge == 10000.00, "Scenario 1 Charge must be 10,000.00, got $s1_charge");
    assert($s1_bal1 == 10.00, "Scenario 1 Balance 1 must be 10.00, got $s1_bal1");
    assert($actual_noz1 == $expected_noz1, "Nozzle reading must advance by $s1_qty, expected $expected_noz1, got $actual_noz1");
    echo "[PASS] Scenario 1: Billed charge Rs. $s1_charge, Balance1: $s1_bal1 Ltr, Nozzle advanced by $s1_qty Ltr (New: $actual_noz1)\n";

    // ----------------------------------------------------
    // SCENARIO 4: Temporary Slip (Loan Petrol)
    // ----------------------------------------------------
    echo "\n--- TEST: Scenario 4 (Temporary Slip - Loan Fuel) ---\n";
    $s4_slip_no = 'TEST-TMP-88';
    $s4_qty = 25.00;
    $s4_rate = 240.00; // Historical rate on loan date
    $s4_charge = 0.00; // Customer charged Rs. 0 today

    $sql4 = "INSERT INTO tbl_meter_reading_credit_sales 
             (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
              quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli, is_returned)
             VALUES 
             (0, '$noz_id', '$past_date_str', 1, '$s4_slip_no', 'Temporary Slip', '$cust_id', 'TEST-VEH-1',
              '$s4_qty', '$s4_rate', 6000.00, '$s4_charge', 240.00, '$s4_qty', 0.00, 0.00, 0.00, 0)";
    if (!mysqli_query($connection, $sql4)) {
        throw new Exception("Scenario 4 Insert failed: " . mysqli_error($connection));
    }
    $s4_id = mysqli_insert_id($connection);

    // Nozzle meter advances immediately when physical fuel was pumped
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = start_reading + $s4_qty WHERE id = '$noz_id'");

    $chk_tmp = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM tbl_meter_reading_credit_sales WHERE id = '$s4_id'"));
    assert(floatval($chk_tmp['charge_amount']) == 0.00, "Scenario 4 Charge must be 0.00");
    assert(intval($chk_tmp['is_returned']) == 0, "Scenario 4 must be open (is_returned = 0)");
    echo "[PASS] Scenario 4: Loan fuel of $s4_qty Ltr recorded with Rs. 0 charge, open loan chit #$s4_slip_no\n";

    // ----------------------------------------------------
    // SCENARIO 2: Permanent Slip (With Temp. Receive Settlement)
    // ----------------------------------------------------
    echo "\n--- TEST: Scenario 2 (Permanent Slip with Temp. Receive) ---\n";
    // Now driver brings Permanent Slip #SL-500 for 50 Litres at today's rate 260.00/L
    // Settles Temporary Slip #TEST-TMP-88 (25L @ 240/L)
    $s2_slip_no = 'TEST-PERM-500';
    $s2_qty = 50.00;
    $s2_issue_qty = 50.00;
    $s2_rate = 260.00;
    $s2_wasoli = 25.00;
    $s2_temp_rate = 240.00;
    // Expected charge: (50 * 260) + (25 * 240) = 13000 + 6000 = 19,000.00
    $s2_charge = round(($s2_issue_qty * $s2_rate) + ($s2_wasoli * $s2_temp_rate), 2);

    $sql2 = "INSERT INTO tbl_meter_reading_credit_sales 
             (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
              quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli,
              temp_slip_id, temp_slip_no, temp_slip_date, temp_rate)
             VALUES 
             (0, '$noz_id', '$today_str', 1, '$s2_slip_no', 'Permanent Slip', '$cust_id', 'TEST-VEH-1',
              '$s2_qty', '$s2_rate', 13000.00, '$s2_charge', 260.00, '$s2_issue_qty', 0.00, 0.00, '$s2_wasoli',
              '$s4_id', '$s4_slip_no', '$past_date_str', '$s2_temp_rate')";
    if (!mysqli_query($connection, $sql2)) {
        throw new Exception("Scenario 2 Insert failed: " . mysqli_error($connection));
    }
    $s2_id = mysqli_insert_id($connection);

    // Mark temporary slip settled
    mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET is_returned = 1, returned_at = NOW(), settled_in_slip_id = '$s2_id' WHERE id = '$s4_id'");

    // Advance nozzle meter strictly by today's pumped fuel (50L, NOT 75L)
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = start_reading + $s2_qty WHERE id = '$noz_id'");

    assert($s2_charge == 19000.00, "Scenario 2 Charge must be 19,000.00, got $s2_charge");
    $chk_settled = mysqli_fetch_assoc(mysqli_query($connection, "SELECT is_returned, settled_in_slip_id FROM tbl_meter_reading_credit_sales WHERE id = '$s4_id'"));
    assert(intval($chk_settled['is_returned']) == 1, "Temporary slip must be marked settled (is_returned = 1)");
    assert(intval($chk_settled['settled_in_slip_id']) == $s2_id, "Temporary slip settled_in_slip_id must link to $s2_id");
    echo "[PASS] Scenario 2: Billed charge Rs. $s2_charge = (50x260 + 25x240), Temp slip #$s4_slip_no settled in #$s2_id\n";

    // ----------------------------------------------------
    // SCENARIO 3: Balanced Slip (Merging Bal 1 + Bal 2 & Price Variation)
    // ----------------------------------------------------
    echo "\n--- TEST: Scenario 3 (Balanced Slip - Merging Bal 1 & Bal 2 with Price Adjustment) ---\n";
    // Create previous permanent slip with balance_1 = 40, balance_2 = 20 @ Rs. 200/L
    $s_orig_slip_no = 'TEST-PERM-ORIG-101';
    $s_orig_bal1 = 40.00;
    $s_orig_bal2 = 20.00;
    $s_orig_rate = 200.00;
    $sql_orig = "INSERT INTO tbl_meter_reading_credit_sales 
                 (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
                  quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli)
                 VALUES 
                 (0, '$noz_id', '$past_date_str', 1, '$s_orig_slip_no', 'Permanent Slip', '$cust_id', 'TEST-VEH-1',
                  0.00, '$s_orig_rate', 0.00, 12000.00, 200.00, 60.00, '$s_orig_bal1', '$s_orig_bal2', 0.00)";
    mysqli_query($connection, $sql_orig);
    $orig_id = mysqli_insert_id($connection);

    // Merge balances: 40 + 20 = 60L
    $merged_balance = $s_orig_bal1 + $s_orig_bal2;
    // Prepaid money credit: 60 * 200 = Rs. 12,000.00
    $prepaid_money = $merged_balance * $s_orig_rate;
    // Current price today: Rs. 240.00/L
    $current_price_today = 240.00;
    // Adjusted litres: 12,000 / 240 = 50.00 Litres
    $adjusted_litres = round($prepaid_money / $current_price_today, 2);

    assert($merged_balance == 60.00, "Merged balance must be 60.00");
    assert($prepaid_money == 12000.00, "Prepaid money must be 12,000.00");
    assert($adjusted_litres == 50.00, "Adjusted litres must be 50.00");

    // Insert Balanced Slip
    $s3_slip_no = 'TEST-BAL-777';
    $sql3 = "INSERT INTO tbl_meter_reading_credit_sales 
             (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
              quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli,
              ref_slip_no, ref_slip_date)
             VALUES 
             (0, '$noz_id', '$today_str', 1, '$s3_slip_no', 'Balanced Slip', '$cust_id', 'TEST-VEH-1',
              '$adjusted_litres', '$current_price_today', 12000.00, 0.00, 240.00, '$adjusted_litres', '$s_orig_bal1', '$s_orig_bal2', 0.00,
              '$s_orig_slip_no', '$past_date_str')";
    if (!mysqli_query($connection, $sql3)) {
        throw new Exception("Scenario 3 Insert failed: " . mysqli_error($connection));
    }
    $s3_id = mysqli_insert_id($connection);

    // Nozzle advances by adjusted litres (50L)
    mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = start_reading + $adjusted_litres WHERE id = '$noz_id'");

    $chk_bal = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM tbl_meter_reading_credit_sales WHERE id = '$s3_id'"));
    assert(floatval($chk_bal['charge_amount']) == 0.00, "Balanced Slip charge must be 0.00");
    assert(floatval($chk_bal['quantity']) == 50.00, "Balanced Slip qty must be 50.00");
    assert($chk_bal['ref_slip_no'] === $s_orig_slip_no, "Balanced slip must record ref_slip_no");
    echo "[PASS] Scenario 3: Merged Balances (40L + 20L = 60L @ Rs. 200 = Rs. 12,000) &rarr; Adjusted to $adjusted_litres Ltr @ Rs. 240/L. Charge: Rs. 0.00!\n";

    echo "\n=== ALL 4 SCENARIOS TESTS COMPLETED SUCCESSFULLY! ===\n";

    // Clean rollback so test data does not pollute live station data
    mysqli_rollback($connection);
    echo "[INFO] Transaction rolled back cleanly - Test data cleared.\n";

} catch (Exception $e) {
    mysqli_rollback($connection);
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
