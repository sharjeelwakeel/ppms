<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-15', 'Nozzle-Specific Expense Isolation');

$test_date = '2029-02-15';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Expense for Nozzle #1: Hose Pipe Replacement = Rs. 1,500.00
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, nozzle_id, expense_type_id, amount, notes, created_by) 
        VALUES ('$test_date', 1, 1, 1500.00, 'Hose pipe replacement for Nozzle 1', 1)");

    // 2. Expense for Nozzle #2: Seal replacement = Rs. 2,000.00
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, nozzle_id, expense_type_id, amount, notes, created_by) 
        VALUES ('$test_date', 2, 1, 2000.00, 'Nozzle 2 seal replacement', 1)");

    // 3. General Station Expense (nozzle_id = NULL): Generator Diesel = Rs. 5,000.00
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, nozzle_id, expense_type_id, amount, notes, created_by) 
        VALUES ('$test_date', NULL, 1, 5000.00, 'Station Generator Diesel', 1)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 0);

    assert_eq(count($data['expense_rows']), 1, "Should return strictly 1 expense entry for Nozzle #1");
    assert_eq($data['total_nozzle_expenses'], 1500.00, "Total nozzle expenses must equal Rs. 1,500.00 (excluding Nozzle #2 and general expenses)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-15'));
