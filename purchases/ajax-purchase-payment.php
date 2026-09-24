<?php
require_once __DIR__ . '/../include/session.php';
header('Content-Type: application/json');

if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

// Auto-migrate tbl_purchase_payments deleted_at if missing
$chk_pp_del = mysqli_query($connection, "SHOW COLUMNS FROM tbl_purchase_payments LIKE 'deleted_at'");
if ($chk_pp_del && mysqli_num_rows($chk_pp_del) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_purchase_payments ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$action = trim($_REQUEST['action'] ?? '');

if ($action === 'get_history') {
    if (!has_permission('purchases', 'show')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
        exit;
    }

    $purchase_id = intval($_GET['purchase_id'] ?? 0);
    if ($purchase_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid purchase ID.']);
        exit;
    }

    $q_purch = "SELECT p.*, i.name AS item_name 
                FROM tbl_purchases p 
                LEFT JOIN tbl_items i ON p.item_id = i.id 
                WHERE p.id = '$purchase_id' AND p.deleted_at IS NULL 
                LIMIT 1";
    $res_purch = mysqli_query($connection, $q_purch);
    $purchase = mysqli_fetch_assoc($res_purch);

    if (!$purchase) {
        echo json_encode(['status' => 'error', 'message' => 'Purchase not found or deleted.']);
        exit;
    }

    $total_cost = floatval($purchase['quantity'] ?? 0) * floatval($purchase['price'] ?? 0);

    // Fetch payments
    $q_pay = "SELECT pay.*, b.name AS bank_name, b.account_number AS bank_account 
              FROM tbl_purchase_payments pay 
              LEFT JOIN tbl_banks b ON pay.bank_id = b.id 
              WHERE pay.purchase_id = '$purchase_id' AND (pay.deleted_at IS NULL OR pay.deleted_at = '0000-00-00 00:00:00') 
              ORDER BY pay.date DESC, pay.id DESC";
    $res_pay = mysqli_query($connection, $q_pay);

    $payments = [];
    $total_paid = 0.0;
    while ($r = mysqli_fetch_assoc($res_pay)) {
        $amt = floatval($r['amount']);
        $total_paid += $amt;
        $payments[] = [
            'id'           => intval($r['id']),
            'date'         => $r['date'],
            'date_fmt'     => date('d-m-Y', strtotime($r['date'])),
            'amount'       => $amt,
            'amount_fmt'   => number_format($amt, 2),
            'bank_name'    => $r['bank_name'] ?? 'N/A',
            'bank_account' => $r['bank_account'] ?? ''
        ];
    }

    $remaining_amount = max(0.00, $total_cost - $total_paid);

    echo json_encode([
        'status'           => 'success',
        'purchase'         => [
            'id'             => intval($purchase['id']),
            'item_name'      => $purchase['item_name'] ?? 'N/A',
            'invoice_number' => $purchase['invoice_number'] ?? 'N/A',
            'date_fmt'       => date('d-m-Y', strtotime($purchase['date'])),
            'quantity'       => floatval($purchase['quantity']),
            'price'          => floatval($purchase['price']),
            'payment_status' => $purchase['payment_status']
        ],
        'total_cost'       => $total_cost,
        'total_cost_fmt'   => number_format($total_cost, 2),
        'total_paid'       => $total_paid,
        'total_paid_fmt'   => number_format($total_paid, 2),
        'remaining_amount' => $remaining_amount,
        'remaining_fmt'    => number_format($remaining_amount, 2),
        'payments'         => $payments
    ]);
    exit;
}

if ($action === 'add_payment') {
    if (!has_permission('purchases', 'edit')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied to record payments.']);
        exit;
    }

    $purchase_id    = intval($_POST['purchase_id'] ?? 0);
    $payment_date   = mysqli_real_escape_string($connection, trim($_POST['payment_date'] ?? ''));
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $bank_id        = intval($_POST['bank_id'] ?? 0);

    if ($purchase_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid purchase selected.']);
        exit;
    }
    if (empty($payment_date)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid payment date.']);
        exit;
    }
    if ($bank_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a bank account.']);
        exit;
    }
    if ($payment_amount <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
        exit;
    }

    mysqli_begin_transaction($connection);
    try {
        // Fetch purchase total cost
        $q_purch = "SELECT quantity, price, payment_status FROM tbl_purchases WHERE id = '$purchase_id' AND deleted_at IS NULL LIMIT 1 FOR UPDATE";
        $res_purch = mysqli_query($connection, $q_purch);
        $purch_row = mysqli_fetch_assoc($res_purch);

        if (!$purch_row) {
            throw new Exception("Purchase record not found.");
        }

        $total_cost = floatval($purch_row['quantity']) * floatval($purch_row['price']);

        // Fetch current paid sum
        $q_sum = "SELECT SUM(amount) AS total_paid FROM tbl_purchase_payments WHERE purchase_id = '$purchase_id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
        $res_sum = mysqli_query($connection, $q_sum);
        $sum_row = mysqli_fetch_assoc($res_sum);
        $current_paid = floatval($sum_row['total_paid'] ?? 0);

        $remaining_balance = max(0.00, $total_cost - $current_paid);

        // Strict overpayment validation rule: Cannot pay more than remaining balance
        if ($remaining_balance <= 0) {
            throw new Exception("This purchase is already fully paid. No additional payments can be recorded.");
        }

        if ($payment_amount > ($remaining_balance + 0.0001)) {
            throw new Exception("Payment amount (Rs. " . number_format($payment_amount, 2) . ") cannot exceed the remaining balance of Rs. " . number_format($remaining_balance, 2) . ".");
        }

        // Insert payment record
        $ins = "INSERT INTO tbl_purchase_payments (purchase_id, date, amount, bank_id) 
                VALUES ('$purchase_id', '$payment_date', '$payment_amount', '$bank_id')";
        if (!mysqli_query($connection, $ins)) {
            throw new Exception("Error inserting payment: " . mysqli_error($connection));
        }

        // Recalculate new total paid and update status
        $new_total_paid = $current_paid + $payment_amount;
        $new_status = 'unpaid';
        if ($new_total_paid >= ($total_cost - 0.0001) && $total_cost > 0) {
            $new_status = 'paid';
        } else if ($new_total_paid > 0) {
            $new_status = 'in process';
        }

        $upd_status = "UPDATE tbl_purchases SET payment_status = '$new_status' WHERE id = '$purchase_id'";
        if (!mysqli_query($connection, $upd_status)) {
            throw new Exception("Error updating purchase status: " . mysqli_error($connection));
        }

        mysqli_commit($connection);

        $new_remaining = max(0.00, $total_cost - $new_total_paid);

        echo json_encode([
            'status'           => 'success',
            'message'          => 'Payment of Rs. ' . number_format($payment_amount, 2) . ' recorded successfully.',
            'new_status'       => $new_status,
            'total_paid'       => $new_total_paid,
            'total_paid_fmt'   => number_format($new_total_paid, 2),
            'remaining_amount' => $new_remaining,
            'remaining_fmt'    => number_format($new_remaining, 2)
        ]);
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
exit;
