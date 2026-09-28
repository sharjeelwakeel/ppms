<?php
/**
 * Test Case 01: Date Range Boundary Filtering
 *
 * Verifies that the Card Balance Report strictly includes settlement batches
 * within the specified date range and excludes batches falling outside.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-01', 'Date Range Boundary Filtering');

$date_in  = '2029-08-05';
$date_out = '2029-08-25';

try {
    reset_test_date_data($connection, $date_in);
    reset_test_date_data($connection, $date_out);

    // Batch 1 (In range): Gross Rs. 10,000, Fee Rs. 100, Net Rs. 9,900, Paid Rs. 9,900
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$date_in', 1, 'BATCH-01-IN', 10, 10000.00, 1.0000, 100.00, 9900.00, 9900.00, 'Paid')");

    // Batch 2 (Out of range): Gross Rs. 15,000, Fee Rs. 150, Net Rs. 14,850, Paid Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$date_out', 1, 'BATCH-01-OUT', 15, 15000.00, 1.0000, 150.00, 14850.00, 0.00, 'Unpaid')");

    $report = get_card_balance_report_data($connection, '2029-08-01', '2029-08-10', 0, 0, 'all');
    $ov = $report['overall'];

    assert_eq($ov['total_batches'], 1, "Should return exactly 1 batch within date range");
    assert_eq($ov['total_gross_amount'], 10000.00, "Gross card sales must be Rs. 10,000.00");
    assert_eq($ov['total_net_expected'], 9900.00, "Net expected must be Rs. 9,900.00");
    assert_eq($ov['total_paid_amount'], 9900.00, "Amount received (get) must be Rs. 9,900.00");
    assert_eq($ov['total_balance_due'], 0.00, "Balance due must be Rs. 0.00");
    assert_eq(count($report['settlement_rows']), 1, "Settlement rows must contain 1 record");

} finally {
    reset_test_date_data($connection, $date_in);
    reset_test_date_data($connection, $date_out);
}

exit(print_suite_summary('TC-CBR-01'));
