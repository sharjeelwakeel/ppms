<?php
/**
 * Test Case 05: Status Filter Isolation ('all', 'outstanding', 'paid', 'unpaid', 'partial')
 *
 * Verifies that filtering by settlement payment status isolates:
 * - 'outstanding': only unpaid and partially paid batches with balance > 0
 * - 'paid': only fully cleared batches
 * - 'unpaid': only batches with zero payments
 * - 'partial': only partially recovered batches
 * - 'all': all batches regardless of settlement status
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/card_balance_report_helper.php';

test_header('TC-CBR-05', 'Status Filter Isolation (All, Outstanding, Paid, Unpaid, Partial)');

$test_date = '2029-08-14';

try {
    reset_test_date_data($connection, $test_date);

    // 1. Batch Paid (Net: 10,000, Paid: 10,000, Due: 0)
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-ST-PAID', 10, 10000.00, 0.0000, 0.00, 10000.00, 10000.00, 'Paid')");

    // 2. Batch Partial (Net: 15,000, Paid: 10,000, Due: 5,000)
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-ST-PART', 15, 15000.00, 0.0000, 0.00, 15000.00, 10000.00, 'Partial')");

    // 3. Batch Unpaid (Net: 8,000, Paid: 0, Due: 8,000)
    mysqli_query($connection, "INSERT INTO tbl_card_sale_settlements 
        (card_machine_id, settlement_date, shift_id, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, paid_amount, payment_status) 
        VALUES 
        (1, '$test_date', 1, 'BATCH-ST-UNPD', 8, 8000.00, 0.0000, 0.00, 8000.00, 0.00, 'Unpaid')");

    // 1. Filter: 'outstanding' (Should capture Partial + Unpaid = 2 batches, Rs. 13,000 due)
    $rep_out = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'outstanding');
    assert_eq($rep_out['overall']['total_batches'], 2, "Outstanding filter must return 2 batches");
    assert_eq($rep_out['overall']['total_net_expected'], 23000.00, "Outstanding net expected must be Rs. 23,000.00");
    assert_eq($rep_out['overall']['total_balance_due'], 13000.00, "Outstanding balance due must be Rs. 13,000.00");
    assert_eq(count($rep_out['settlement_rows']), 2, "Outstanding settlement rows count must be 2");

    // 2. Filter: 'paid' (Should capture only Paid = 1 batch)
    $rep_paid = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'paid');
    assert_eq($rep_paid['overall']['total_batches'], 1, "Paid filter must return 1 batch");
    assert_eq($rep_paid['overall']['total_net_expected'], 10000.00, "Paid net expected must be Rs. 10,000.00");
    assert_eq($rep_paid['overall']['total_balance_due'], 0.00, "Paid balance due must be Rs. 0.00");
    assert_eq($rep_paid['settlement_rows'][0]['batch_no'], 'BATCH-ST-PAID', "Paid row batch must be BATCH-ST-PAID");

    // 3. Filter: 'unpaid' (Should capture only Unpaid = 1 batch)
    $rep_unpd = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'unpaid');
    assert_eq($rep_unpd['overall']['total_batches'], 1, "Unpaid filter must return 1 batch");
    assert_eq($rep_unpd['overall']['total_net_expected'], 8000.00, "Unpaid net expected must be Rs. 8,000.00");
    assert_eq($rep_unpd['overall']['total_balance_due'], 8000.00, "Unpaid balance due must be Rs. 8,000.00");
    assert_eq($rep_unpd['settlement_rows'][0]['batch_no'], 'BATCH-ST-UNPD', "Unpaid row batch must be BATCH-ST-UNPD");

    // 4. Filter: 'partial' (Should capture only Partial = 1 batch)
    $rep_part = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'partial');
    assert_eq($rep_part['overall']['total_batches'], 1, "Partial filter must return 1 batch");
    assert_eq($rep_part['overall']['total_net_expected'], 15000.00, "Partial net expected must be Rs. 15,000.00");
    assert_eq($rep_part['overall']['total_balance_due'], 5000.00, "Partial balance due must be Rs. 5,000.00");
    assert_eq($rep_part['settlement_rows'][0]['batch_no'], 'BATCH-ST-PART', "Partial row batch must be BATCH-ST-PART");

    // 5. Filter: 'all' (Should capture all 3 batches)
    $rep_all = get_card_balance_report_data($connection, $test_date, $test_date, 0, 0, 'all');
    assert_eq($rep_all['overall']['total_batches'], 3, "All filter must return 3 batches");
    assert_eq($rep_all['overall']['total_net_expected'], 33000.00, "All net expected must be Rs. 33,000.00");
    assert_eq($rep_all['overall']['total_paid_amount'], 20000.00, "All paid amount must be Rs. 20,000.00");
    assert_eq($rep_all['overall']['total_balance_due'], 13000.00, "All balance due must be Rs. 13,000.00");

} finally {
    reset_test_date_data($connection, $test_date);
}

exit(print_suite_summary('TC-CBR-05'));
