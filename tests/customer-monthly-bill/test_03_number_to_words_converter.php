<?php
/**
 * Test Case 03: Number to Words Converter
 * PPMS (Petrol Pump Management System)
 *
 * Verifies exact string format for voucher English currency representation.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../test_helper.php';
require_once 'd:/xampp/htdocs/ppms/include/customer_monthly_bill_helper.php';

test_header('TC-CMB-03', 'Number to Words Currency Converter');

// Case 1: Exact amount from voucher 133886.61
$words1 = convert_number_to_rupees_words(133886.61);
$expected1 = "( RUPEES ONE HUNDRED THIRTY-THREE THOUSAND EIGHT HUNDRED EIGHTY-SIX AND 61 / 100 Only )";
assert_eq($words1, $expected1, "Voucher amount 133,886.61 must match official voucher wording exactly");

// Case 2: Round amount without paisas
$words2 = convert_number_to_rupees_words(50000);
$expected2 = "( RUPEES FIFTY THOUSAND ONLY )";
assert_eq($words2, $expected2, "Integer amount 50,000 must produce round rupee wording");

// Case 3: Small amount with single paisa
$words3 = convert_number_to_rupees_words(150.05);
$expected3 = "( RUPEES ONE HUNDRED FIFTY AND 05 / 100 Only )";
assert_eq($words3, $expected3, "Amount with small paisa must format padded cents");

exit(print_suite_summary('TC-CMB-03'));
