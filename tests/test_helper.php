<?php
/**
 * Test Helper Functions & CLI Assertion Engine
 */

$test_passed_count = 0;
$test_failed_count = 0;
$test_start_time   = microtime(true);

function test_header($id, $title) {
    echo "\n------------------------------------------------------------\n";
    echo ">> [RUNNING] {$id}: {$title}\n";
    echo "------------------------------------------------------------\n";
}

function assert_eq($actual, $expected, $desc = '') {
    global $test_passed_count, $test_failed_count;
    if ($actual == $expected) {
        echo "  [PASS] {$desc} (Value: " . var_export($actual, true) . ")\n";
        $test_passed_count++;
        return true;
    } else {
        echo "  [FAIL] {$desc}\n";
        echo "         Expected: " . var_export($expected, true) . "\n";
        echo "         Actual  : " . var_export($actual, true) . "\n";
        $test_failed_count++;
        return false;
    }
}

function assert_true($condition, $desc = '') {
    global $test_passed_count, $test_failed_count;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $test_passed_count++;
        return true;
    } else {
        echo "  [FAIL] {$desc} (Expected condition to be TRUE)\n";
        $test_failed_count++;
        return false;
    }
}

function reset_test_date_data($connection, $test_date) {
    $date_safe = mysqli_real_escape_string($connection, $test_date);
    
    // Clean up test meter readings & details
    $mr_q = mysqli_query($connection, "SELECT id FROM tbl_meter_readings WHERE date = '$date_safe'");
    if ($mr_q) {
        while ($r = mysqli_fetch_assoc($mr_q)) {
            $m_id = $r['id'];
            mysqli_query($connection, "DELETE FROM tbl_meter_reading_details WHERE meter_reading_id = '$m_id'");
        }
    }
    mysqli_query($connection, "DELETE FROM tbl_meter_readings WHERE date = '$date_safe'");
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_cash_sales WHERE sale_date = '$date_safe'");
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE sale_date = '$date_safe' OR slip_date = '$date_safe'");
    mysqli_query($connection, "DELETE FROM tbl_meter_reading_card_sales WHERE sale_date = '$date_safe'");
    mysqli_query($connection, "DELETE FROM tbl_expenses WHERE expense_date = '$date_safe'");
}

function print_suite_summary($suite_name = 'Test Suite') {
    global $test_passed_count, $test_failed_count, $test_start_time;
    $duration = round(microtime(true) - $test_start_time, 4);

    echo "\n============================================================\n";
    echo "SUMMARY FOR: {$suite_name}\n";
    echo "============================================================\n";
    echo "Total Assertions : " . ($test_passed_count + $test_failed_count) . "\n";
    echo "Passed           : {$test_passed_count}\n";
    echo "Failed           : {$test_failed_count}\n";
    echo "Execution Time   : {$duration} seconds\n";
    echo "============================================================\n";

    if ($test_failed_count > 0) {
        echo "RESULT: FAIL (Some assertions did not pass)\n\n";
        return 1;
    } else {
        echo "RESULT: ALL TESTS PASSED SUCCESSFULLY! [OK]\n\n";
        return 0;
    }
}
