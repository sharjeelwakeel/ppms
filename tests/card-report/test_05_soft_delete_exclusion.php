<?php
/**
 * Test Case 05: Soft-Deleted Records Exclusion Audit
 *
 * Verifies that card transactions marked as soft-deleted (deleted_at IS NOT NULL)
 * are completely excluded from the Card Machine Report totals.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_report_helper.php';

test_header('TC-CARD-05', 'Soft-Deleted Records Exclusion Audit');

$test_date = '2029-07-14';

try {
    reset_test_date_data($connection, $test_date);

    // Active record: 50 Ltr @ Rs. 200 = Rs. 10,000 (Fee: 100, Net: 9,900)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount, deleted_at) 
        VALUES 
        (1, 1, 1, '$test_date', 50.00, 200.00, 10000.00, 100.00, 9900.00, NULL)");

    // Soft-deleted record: 100 Ltr @ Rs. 200 = Rs. 20,000 (MUST BE EXCLUDED)
    mysqli_query($connection, "INSERT INTO tbl_meter_reading_card_sales 
        (card_machine_id, nozzle_id, shift_id, sale_date, quantity, rate, amount, service_charges, net_amount, deleted_at) 
        VALUES 
        (1, 1, 1, '$test_date', 100.00, 200.00, 20000.00, 200.00, 19800.00, NOW())");

    $report = get_card_report_data($connection, $test_date, $test_date, 0, 0);
    $ov = $report['overall'];

    assert_eq($ov['total_swipes'], 1, "Total swipes must exclude soft-deleted record (count = 1)");
    assert_eq($ov['total_volume'], 50.00, "Volume must exclude soft-deleted record (50.00 Ltr)");
    assert_eq($ov['total_gross_amount'], 10000.00, "Gross amount must exclude soft-deleted record (Rs. 10,000.00)");
    assert_eq($ov['total_service_charges'], 100.00, "Service fee must exclude soft-deleted record (Rs. 100.00)");
    assert_eq($ov['total_net_revenue'], 9900.00, "Net revenue must exclude soft-deleted record (Rs. 9,900.00)");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CARD-05'));
