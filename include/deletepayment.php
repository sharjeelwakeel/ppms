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

if (!has_permission('accounts', 'delete') && !has_permission('credit_sales', 'delete')) {
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
    $q_pay = mysqli_query($connection, "SELECT id, receipt_no, total_amount FROM tbl_customer_payments WHERE id = '$paymentId' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') FOR UPDATE");
    if (!$q_pay || mysqli_num_rows($q_pay) == 0) {
        throw new Exception("Payment record not found or already deleted.");
    }
    $payment = mysqli_fetch_assoc($q_pay);

    // 2. Fetch all allocations for this payment
    $q_alloc = mysqli_query($connection, "SELECT id, credit_sale_id, allocated_amount 
                                          FROM tbl_customer_payment_allocations 
                                          WHERE payment_id = '$paymentId' 
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          FOR UPDATE");
    
    if ($q_alloc) {
        while ($alloc = mysqli_fetch_assoc($q_alloc)) {
            $slipId   = intval($alloc['credit_sale_id']);
            $cutAmount = floatval($alloc['allocated_amount']);

            // Revert paid_amount and payment_status on the credit sale slip
            $q_slip = mysqli_query($connection, "SELECT charge_amount, paid_amount FROM tbl_meter_reading_credit_sales WHERE id = '$slipId' FOR UPDATE");
            if ($q_slip && ($slip = mysqli_fetch_assoc($q_slip))) {
                $oldPaid = floatval($slip['paid_amount']);
                $newPaid = max(0.00, round($oldPaid - $cutAmount, 2));
                $newStatus = ($newPaid <= 0.00) ? 'Unpaid' : 'Partial';

                mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales 
                                           SET paid_amount = '$newPaid', payment_status = '$newStatus' 
                                           WHERE id = '$slipId'");
            }
        }
    }

    // 3. Soft delete payment allocations
    mysqli_query($connection, "UPDATE tbl_customer_payment_allocations SET deleted_at = NOW() WHERE payment_id = '$paymentId' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");

    // 4. Soft delete master payment
    mysqli_query($connection, "UPDATE tbl_customer_payments SET deleted_at = NOW() WHERE id = '$paymentId'");

    mysqli_commit($connection);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Payment receipt <strong>' . htmlspecialchars($payment['receipt_no']) . '</strong> (Rs. ' . number_format($payment['total_amount'], 2) . ') has been soft deleted and slip balances have been rolled back successfully.'
    ]);

} catch (Exception $e) {
    mysqli_rollback($connection);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
