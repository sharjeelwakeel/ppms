<?php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

if (!has_permission('accounts', 'delete') && !has_permission('card_sales', 'delete')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied. Unauthorized operation.']);
    exit;
}

$paymentId = intval($_POST['payment_id'] ?? 0);
if ($paymentId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
    exit;
}

mysqli_begin_transaction($connection);

try {
    // 1. Fetch payment to ensure it exists and is active
    $q_pay = mysqli_query($connection, "SELECT id, total_amount, payment_date, card_machine_id, payment_mode, bank_id 
                                        FROM tbl_card_revenue_payments 
                                        WHERE id = '$paymentId' 
                                          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                        FOR UPDATE");
    if (!$q_pay || mysqli_num_rows($q_pay) == 0) {
        throw new Exception("Card revenue voucher not found or already deleted.");
    }
    $payment = mysqli_fetch_assoc($q_pay);

    // 2. Fetch all allocations for this payment
    $q_alloc = mysqli_query($connection, "SELECT id, settlement_id, allocated_amount 
                                          FROM tbl_card_revenue_payment_allocations 
                                          WHERE payment_id = '$paymentId' 
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          FOR UPDATE");
    
    if ($q_alloc) {
        while ($alloc = mysqli_fetch_assoc($q_alloc)) {
            $settlementId = intval($alloc['settlement_id']);
            $cutAmount    = floatval($alloc['allocated_amount']);

            // Revert revenue_paid_amount and revenue_payment_status on the settlement batch
            $q_batch = mysqli_query($connection, "SELECT revenue_amount, revenue_paid_amount 
                                                  FROM tbl_card_sale_settlements 
                                                  WHERE id = '$settlementId' 
                                                  FOR UPDATE");
            if ($q_batch && ($batch = mysqli_fetch_assoc($q_batch))) {
                $oldPaid   = floatval($batch['revenue_paid_amount']);
                $revAmt    = floatval($batch['revenue_amount']);
                $newPaid   = max(0.00, round($oldPaid - $cutAmount, 2));
                $newStatus = ($newPaid <= 0.00) ? 'Unpaid' : 'Partial';

                mysqli_query($connection, "UPDATE tbl_card_sale_settlements 
                                           SET revenue_paid_amount = '$newPaid', revenue_payment_status = '$newStatus' 
                                           WHERE id = '$settlementId'");
            }
        }
    }

    // 3. Soft delete payment allocations
    mysqli_query($connection, "UPDATE tbl_card_revenue_payment_allocations 
                               SET deleted_at = NOW() 
                               WHERE payment_id = '$paymentId' 
                                 AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");

    // 4. Soft delete master payment voucher
    mysqli_query($connection, "UPDATE tbl_card_revenue_payments 
                               SET deleted_at = NOW() 
                               WHERE id = '$paymentId'");

    mysqli_commit($connection);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Revenue collection voucher #<strong>' . $paymentId . '</strong> (Rs. ' . number_format($payment['total_amount'], 2) . ') has been soft deleted and revenue balances have been rolled back successfully.'
    ]);

} catch (Exception $e) {
    mysqli_rollback($connection);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
