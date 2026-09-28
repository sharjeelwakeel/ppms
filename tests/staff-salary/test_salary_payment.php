<?php
/**
 * Test Suite: Attendance-Based Cash Salary Disbursement & Voucher System
 * Tests against isolated 'ppms_test' database.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../include/salary_payment_helper.php';

test_header('SAL-01', 'Auto-migration of tbl_staff_salary_payments');
auto_migrate_salary_payment_tables($connection);

$res_tbl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_staff_salary_payments'");
assert_true($res_tbl && mysqli_num_rows($res_tbl) > 0, "tbl_staff_salary_payments table exists in ppms_test");

test_header('SAL-02', 'English Currency Conversion for Salary Slips');
$w1 = convert_salary_amount_to_words(20400.00);
assert_eq($w1, "( RUPEES TWENTY THOUSAND FOUR HUNDRED ONLY )", "20,400.00 converted to words");

$w2 = convert_salary_amount_to_words(850.50);
assert_eq($w2, "( RUPEES EIGHT HUNDRED FIFTY AND 50 / 100 Only )", "850.50 converted to words with paisa");

test_header('SAL-03', 'Staff Seeding, Weekly Holiday & Option A Global Paid Leaves Calculation');
$test_first_name = "TestEmp_" . time();
$test_daily_rate = 850.00;

// Set default global paid leaves to 2 (Option A)
set_global_paid_leaves($connection, 2);
$current_global_quota = get_global_paid_leaves($connection);
assert_eq($current_global_quota, 2, "Option A global paid leaves limit verified at 2 days/month");

// Create test staff role if missing
$r_chk = mysqli_query($connection, "SELECT id FROM tbl_staff_roles WHERE name = 'Cashier Test' LIMIT 1");
if ($r_chk && $r_row = mysqli_fetch_assoc($r_chk)) {
    $role_id = $r_row['id'];
} else {
    mysqli_query($connection, "INSERT INTO tbl_staff_roles (name) VALUES ('Cashier Test')");
    $role_id = mysqli_insert_id($connection);
}

// Insert test staff with weekly_off = 'Sunday'
mysqli_query($connection, "INSERT INTO tbl_staff (first_name, last_name, role_id, joining_date, shift_id, salary, phone, weekly_off) 
                           VALUES ('$test_first_name', 'Automated', '$role_id', '2026-01-01', 1, '$test_daily_rate', '03001234567', 'Sunday')");
$test_staff_id = mysqli_insert_id($connection);
assert_true($test_staff_id > 0, "Seeded test staff ID: {$test_staff_id}");

// Clean previous attendance for test period
$test_month = 10;
$test_year = 2026;
mysqli_query($connection, "DELETE FROM tbl_staff_attendance WHERE staff_id = '$test_staff_id'");

// Insert attendance:
// 18 Present days (2026-10-01 to 2026-10-18)
for ($d = 1; $d <= 18; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    mysqli_query($connection, "INSERT INTO tbl_staff_attendance (staff_id, date, status) VALUES ('$test_staff_id', '$date', 'Present')");
}
// 2 Late days (2026-10-19 to 2026-10-20)
for ($d = 19; $d <= 20; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    mysqli_query($connection, "INSERT INTO tbl_staff_attendance (staff_id, date, status) VALUES ('$test_staff_id', '$date', 'Late')");
}
// 4 Holiday days (2026-10-21 to 2026-10-24) -> Counted as paid days (Weekly Holiday)
for ($d = 21; $d <= 24; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    mysqli_query($connection, "INSERT INTO tbl_staff_attendance (staff_id, date, status) VALUES ('$test_staff_id', '$date', 'Holiday')");
}
// 3 Leave days (2026-10-25 to 2026-10-27) -> Cap is 2, so 2 are paid, 1 is unpaid
for ($d = 25; $d <= 27; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    mysqli_query($connection, "INSERT INTO tbl_staff_attendance (staff_id, date, status) VALUES ('$test_staff_id', '$date', 'Leave')");
}
// 2 Absent days (2026-10-28 to 2026-10-29) -> Unpaid
for ($d = 28; $d <= 29; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    mysqli_query($connection, "INSERT INTO tbl_staff_attendance (staff_id, date, status) VALUES ('$test_staff_id', '$date', 'Absent')");
}

// Calculate records
$records = get_staff_monthly_salary_records($connection, $test_month, $test_year, $test_staff_id);
assert_true(!empty($records), "Calculated record fetched for staff ID: {$test_staff_id}");

$emp_rec = $records[0];
assert_eq($emp_rec['weekly_off'], 'Sunday', "Weekly off is Sunday");
assert_eq($emp_rec['count_present'], 18, "Present days count = 18");
assert_eq($emp_rec['count_late'], 2, "Late days count = 2");
assert_eq($emp_rec['count_holiday'], 4, "Weekly Holiday count = 4 (paid)");
assert_eq($emp_rec['count_leave'], 3, "Leave days taken = 3");
assert_eq($emp_rec['paid_leaves'], 2, "Paid leaves granted under Option A cap = 2");
assert_eq($emp_rec['count_absent'], 2, "Absent days count = 2 (unpaid)");
assert_eq($emp_rec['days_worked'], 26, "Total paid days = 26 (18 Present + 2 Late + 4 Holiday + 2 Paid Leaves)");
assert_eq($emp_rec['calculated_salary'], 22100.00, "Calculated cash salary = 26 * 850 = 22,100.00");
assert_true(!$emp_rec['is_paid'], "Initial status is Unpaid");

// Test Option A dynamic update to 3 leaves:
set_global_paid_leaves($connection, 3);
$records_dynamic = get_staff_monthly_salary_records($connection, $test_month, $test_year, $test_staff_id);
assert_eq($records_dynamic[0]['paid_leaves'], 3, "Dynamic update: Paid leaves now = 3");
assert_eq($records_dynamic[0]['days_worked'], 27, "Dynamic update: Total paid days now = 27");
assert_eq($records_dynamic[0]['calculated_salary'], 22950.00, "Dynamic update: Salary now = 27 * 850 = 22,950.00");

// Revert global cap back to 2 for subsequent tests
set_global_paid_leaves($connection, 2);

test_header('SAL-04', 'Cash Salary Disbursement Execution');
$payment_res = process_cash_salary_payment(
    $connection, 
    $test_staff_id, 
    $test_month, 
    $test_year, 
    '2026-10-31', 
    'Unit test cash disbursement', 
    1
);

assert_eq($payment_res['status'], 'success', "Salary payment processed successfully");
assert_true(!empty($payment_res['payment_id']), "Payment ID generated");
assert_true(strpos($payment_res['voucher_no'], 'SAL-202610-') === 0, "Voucher format matches SAL-202610-XXXX: {$payment_res['voucher_no']}");
assert_eq($payment_res['paid_amount'], 22100.00, "Disbursed cash amount matches 22,100.00");

// Verify status in get_staff_monthly_salary_records
$records_after = get_staff_monthly_salary_records($connection, $test_month, $test_year, $test_staff_id);
assert_true($records_after[0]['is_paid'], "Status now reflects Paid");
assert_eq($records_after[0]['voucher_no'], $payment_res['voucher_no'], "Voucher number matched");

test_header('SAL-05', 'Duplicate Payment Prevention Invariant');
// Attempt duplicate payment for the same staff and month
$dup_res = process_cash_salary_payment(
    $connection, 
    $test_staff_id, 
    $test_month, 
    $test_year, 
    '2026-10-31', 
    'Duplicate attempt', 
    1
);
assert_eq($dup_res['status'], 'error', "Duplicate payment correctly blocked");
assert_true(strpos($dup_res['message'], 'already been disbursed') !== false, "Error message informs of existing voucher");

test_header('SAL-06', 'Salary Disbursement History & Audit Trail');
$history = get_salary_payment_history($connection, $test_month, $test_year, $test_staff_id);
assert_true(count($history) === 1, "Exactly 1 payment voucher in history for this staff and month");
assert_eq($history[0]['payment_mode'], 'Cash', "Payment mode is strictly 100% Cash");
assert_eq($history[0]['paid_amount'], 22100.00, "History recorded amount is 22,100.00");

test_header('SAL-07', 'Payment Reversal / Rollback to Unpaid');
$revert_res = revert_salary_payment($connection, $payment_res['payment_id']);
assert_eq($revert_res['status'], 'success', "Payment reverted successfully");

$records_reverted = get_staff_monthly_salary_records($connection, $test_month, $test_year, $test_staff_id);
assert_true(!$records_reverted[0]['is_paid'], "Status successfully reset back to Unpaid after soft delete");

// Clean up test data
mysqli_query($connection, "DELETE FROM tbl_staff_attendance WHERE staff_id = '$test_staff_id'");
mysqli_query($connection, "DELETE FROM tbl_staff_salary_payments WHERE staff_id = '$test_staff_id'");
mysqli_query($connection, "DELETE FROM tbl_staff WHERE id = '$test_staff_id'");

print_suite_summary('Attendance-Based Cash Salary Disbursement & Voucher System');

if ($test_failed_count > 0) {
    exit(1);
} else {
    exit(0);
}
