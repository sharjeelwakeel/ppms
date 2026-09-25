<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-16', 'Net Nozzle Operating Yield Calculation');

$test_date = '2029-02-16';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Meter Reading: 500 Ltr @ Rs. 200 = Gross Revenue Rs. 100,000.00
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 100000.00)");
    $mr_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_id', '$nozzle_id', 13000.00, 13500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");

    // 2. Nozzle Maintenance Expense = Rs. 1,500.00
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, nozzle_id, expense_type_id, amount, notes, created_by) 
        VALUES ('$test_date', 1, 1, 1500.00, 'Seal maintenance', 1)");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_meter_revenue'], 100000.00, "Gross meter revenue must be Rs. 100,000.00");
    assert_eq($data['total_nozzle_expenses'], 1500.00, "Nozzle maintenance expense must be Rs. 1,500.00");
    // Net Yield = Gross Revenue - Nozzle Expenses
    assert_eq($data['net_nozzle_yield'], 98500.00, "Net Operating Yield must equal Rs. 98,500.00 (100,000 - 1,500)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-16'));
