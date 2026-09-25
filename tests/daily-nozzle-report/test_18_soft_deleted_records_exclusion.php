<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/nozzle_report_helper.php';

test_header('TC-NOZ-18', 'Soft-Deleted Records Exclusion Audit');

$test_date = '2029-02-18';
$nozzle_id = 1;

try {
    reset_test_date_data($connection, $test_date);

    // 1. Insert Active Meter Reading: 500 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total) VALUES ('$test_date', 1, 'Cash', 100000.00)");
    $mr_act_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_act_id', '$nozzle_id', 1000.00, 1500.00, 500.00, 0.00, 500.00, 200.00, 100000.00)");

    // 2. Insert Soft-Deleted Meter Reading: 999 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total, deleted_at) VALUES ('$test_date', 1, 'Cash', 199800.00, NOW())");
    $mr_del_id = mysqli_insert_id($connection);
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_details (meter_reading_id, nozzle_id, last_reading, current_reading, sale_reading, test_reading, net_sale, price, amount) 
        VALUES ('$mr_del_id', '$nozzle_id', 1500.00, 2499.00, 999.00, 0.00, 999.00, 200.00, 199800.00)");

    // 3. Active Cash: 500 Ltr, Deleted Cash: 300 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount) 
        VALUES ('$nozzle_id', '$test_date', 1, 500.00, 200.00, 100000.00)");
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_cash_sales (nozzle_id, sale_date, shift_id, quantity, rate, amount, deleted_at) 
        VALUES ('$nozzle_id', '$test_date', 1, 300.00, 200.00, 60000.00, NOW())");

    // 4. Deleted Credit: 200 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_credit_sales 
        (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, issue_quantity, deleted_at) 
        VALUES 
        ('$nozzle_id', '$test_date', '$test_date', 1, 'DEL-01', 'Permanent Slip', 1, 'DEL-99', 200.00, 200.00, 40000.00, 200.00, NOW())");

    // 5. Deleted Card: 100 Ltr
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (nozzle_id, sale_date, shift_id, card_machine_id, quantity, rate, amount, deleted_at) 
        VALUES 
        ('$nozzle_id', '$test_date', 1, 1, 100.00, 200.00, 20000.00, NOW())");

    // 6. Deleted Expense: Rs. 9,999
    mysqli_query($connection, "INSERT INTO tbl_expenses (expense_date, nozzle_id, expense_type_id, amount, description, created_by, deleted_at) 
        VALUES ('$test_date', 1, 1, 9999.00, 'Deleted expense', 1, NOW())");

    $data = get_daily_nozzle_report_data($connection, $nozzle_id, $test_date, 1);

    assert_eq($data['total_meter_litres'], 500.00, "Meter volume must exclude soft-deleted meter reading (500.00 Ltr)");
    assert_eq($data['total_cash_litres'], 500.00, "Cash volume must exclude soft-deleted cash record (500.00 Ltr)");
    assert_eq($data['total_credit_issued_litres'], 0.00, "Credit volume must exclude soft-deleted credit slip (0.00 Ltr)");
    assert_eq($data['total_card_litres'], 0.00, "Card volume must exclude soft-deleted card swipe (0.00 Ltr)");
    assert_eq($data['total_nozzle_expenses'], 0.00, "Expenses must exclude soft-deleted expense (Rs. 0.00)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-NOZ-18'));
