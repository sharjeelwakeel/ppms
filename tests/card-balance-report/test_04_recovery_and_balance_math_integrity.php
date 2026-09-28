<?php
/**
 * Test Case 04: Recovery & Balance Mathematical Integrity
 *
 * Verifies mathematical formulas:
 * - Net Expected = Gross Sales - Service Charges
 * - Balance Due = Net Expected - Paid Amount ("How Much Balance")
 * - Recovery Rate % = (Paid Amount / Net Expected) * 100
 * - Across Fully Paid, Partially Paid, and Zero-Paid (Unpaid) settlements.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-04', 'Recovery & Balance Mathematical Integrity');

$test_date = '2029-08-13';

try {
    reset_test_date_data($connection, $test_date);

    // 1. Batch 1: Fully Paid
    // Gross: Rs. 10,000, MDR: Rs. 30 (0.3%), Net Expected: Rs. 9,970, Paid: Rs. 9,970, Due: Rs. 0
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-MATH-1', 10, 10000.00, 0.3000, 30.00, 9970.00, 9970.00, 'Paid')");

    // 2. Batch 2: Partial Payment
    // Gross: Rs. 20,000, MDR: Rs. 60 (0.3%), Net Expected: Rs. 19,940, Paid: Rs. 12,000, Due: Rs. 7,940
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-MATH-2', 20, 20000.00, 0.3000, 60.00, 19940.00, 12000.00, 'Partial')");

    // 3. Batch 3: Unpaid
    // Gross: Rs. 15,000, MDR: Rs. 30 (0.2%), Net Expected: Rs. 14,970, Paid: Rs. 0, Due: Rs. 14,970
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (2, '$test_date', 2, 'BATCH-MATH-3', 15, 15000.00, 0.2000, 30.00, 14970.00, 0.00, 'Unpaid')");

    $report = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'all');
    $ov = $report['overall'];

    // Overall Totals
    assert_eq($ov['total_batches'], 3, "Total batches must equal 3");
    assert_eq($ov['total_swipes'], 45, "Total swipes must equal 45 (10 + 20 + 15)");
    assert_eq($ov['total_gross_amount'], 45000.00, "Total gross amount must be Rs. 45,000.00");
    assert_eq($ov['total_service_charges'], 120.00, "Total service charges must be Rs. 120.00");
    assert_eq($ov['total_net_expected'], 44880.00, "Total net expected must be Rs. 44,880.00 (45,000 - 120)");
    assert_eq($ov['total_paid_amount'], 21970.00, "Total amount get must be Rs. 21,970.00 (9,970 + 12,000 + 0)");
    assert_eq($ov['total_balance_due'], 22910.00, "Total balance due must be Rs. 22,910.00 (0 + 7,940 + 14,970)");

    // Accounting Equality: Net Expected == Paid + Balance Due
    $sum_check = round($ov['total_paid_amount'] + $ov['total_balance_due'], 2);
    assert_eq($sum_check, $ov['total_net_expected'], "Accounting balance: Paid + Balance Due must equal Net Expected");

    // Recovery Rate % = (21,970 / 44,880) * 100 = 48.95%
    $expected_rec_pct = round((21970.00 / 44880.00) * 100, 2);
    assert_eq($ov['recovery_rate_pct'], $expected_rec_pct, "Recovery percentage must be 48.95%");

    // Verify Individual Settlement Rows
    $rows_by_batch = [];
    foreach ($report['settlement_rows'] as $row) {
        $rows_by_batch[$row['batch_no']] = $row;
    }

    assert_eq($rows_by_batch['BATCH-MATH-1']['balance_due'], 0.00, "Batch 1 balance due must be 0.00");
    assert_eq($rows_by_batch['BATCH-MATH-1']['payment_status'], 'Paid', "Batch 1 status must be Paid");

    assert_eq($rows_by_batch['BATCH-MATH-2']['balance_due'], 7940.00, "Batch 2 balance due must be 7,940.00");
    assert_eq($rows_by_batch['BATCH-MATH-2']['payment_status'], 'Partial', "Batch 2 status must be Partial");

    assert_eq($rows_by_batch['BATCH-MATH-3']['balance_due'], 14970.00, "Batch 3 balance due must be 14,970.00");
    assert_eq($rows_by_batch['BATCH-MATH-3']['payment_status'], 'Unpaid', "Batch 3 status must be Unpaid");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-04'));
