<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/session.php';
if (!userloggedin()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    } else {
        header('Location: ../login.php');
    }
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';

if (!has_permission('accounts', 'delete') && !has_permission('items', 'delete') && !has_permission('credit_sales', 'delete')) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Permission denied. Unauthorized operation.']);
    } else {
        echo "<script>alert('Permission denied.'); window.location.href='../accounts/product-payment-history.php';</script>";
    }
    exit;
}

$paymentId = intval($_REQUEST['id'] ?? $_POST['payment_id'] ?? 0);
if ($paymentId <= 0) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
    } else {
        header('Location: ../accounts/product-payment-history.php?error=invalid_id');
    }
    exit;
}

mysqli_begin_transaction($connection);

try {
    // 1. Fetch payment to ensure it exists and is active
    $q_pay = mysqli_query($connection, "SELECT id, receipt_no, total_amount FROM tbl_product_payments WHERE id = '$paymentId' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') FOR UPDATE");
    if (!$q_pay || mysqli_num_rows($q_pay) == 0) {
        throw new Exception("Product payment record not found or already deleted.");
    }
    $payment = mysqli_fetch_assoc($q_pay);

    // 2. Fetch all allocations for this payment
    $q_alloc = mysqli_query($connection, "SELECT id, invoice_id, allocated_amount 
                                          FROM tbl_product_payment_allocations 
                                          WHERE payment_id = '$paymentId' 
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          FOR UPDATE");
    
    if ($q_alloc) {
        while ($alloc = mysqli_fetch_assoc($q_alloc)) {
            $invId     = intval($alloc['invoice_id']);
            $cutAmount = floatval($alloc['allocated_amount']);

            // Revert paid_amount and payment_status on the lubricant credit sale invoice
            $q_inv = mysqli_query($connection, "SELECT charge_amount, paid_amount FROM tbl_lubricant_sale_invoices WHERE id = '$invId' FOR UPDATE");
            if ($q_inv && ($inv = mysqli_fetch_assoc($q_inv))) {
                $oldPaid   = floatval($inv['paid_amount']);
                $chargeAmt = floatval($inv['charge_amount']);
                $newPaid   = max(0.00, round($oldPaid - $cutAmount, 2));
                
                if ($newPaid <= 0.00) {
                    $newStatus = 'Unpaid';
                } elseif ($newPaid < $chargeAmt) {
                    $newStatus = 'Partial';
                } else {
                    $newStatus = 'Paid';
                }

                mysqli_query($connection, "UPDATE tbl_lubricant_sale_invoices 
                                           SET paid_amount = '$newPaid', payment_status = '$newStatus' 
                                           WHERE id = '$invId'");
            }
        }
    }

    // 3. Soft delete payment allocations
    mysqli_query($connection, "UPDATE tbl_product_payment_allocations SET deleted_at = NOW() WHERE payment_id = '$paymentId' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");

    // 4. Soft delete master payment
    mysqli_query($connection, "UPDATE tbl_product_payments SET deleted_at = NOW() WHERE id = '$paymentId'");

    mysqli_commit($connection);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'success',
            'message' => 'Product payment receipt <strong>' . htmlspecialchars($payment['receipt_no']) . '</strong> (Rs. ' . number_format($payment['total_amount'], 2) . ') has been deleted and invoice balances rolled back.'
        ]);
        exit;
    } else {
        header('Location: ../accounts/product-payment-history.php?msg=deleted');
        exit;
    }

} catch (Exception $e) {
    mysqli_rollback($connection);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    } else {
        echo "<script>alert('" . addslashes($e->getMessage()) . "'); window.location.href='../accounts/product-payment-history.php';</script>";
        exit;
    }
}
