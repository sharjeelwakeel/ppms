<?php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login again.']);
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

if (!has_permission('accounts', 'add') && !has_permission('accounts', 'edit') && !has_permission('credit_sales', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied. You do not have access to record payments.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$customerId    = intval($_POST['customer_id'] ?? 0);
$paymentDate   = trim($_POST['payment_date'] ?? '');
$amount        = round(floatval($_POST['amount'] ?? 0), 2);
$paymentMode   = trim($_POST['payment_mode'] ?? 'Cash');
$bankId        = intval($_POST['bank_id'] ?? 0);
$transRef      = trim($_POST['transaction_ref'] ?? '');
$chequeNo      = trim($_POST['cheque_no'] ?? '');
$chequeDate    = trim($_POST['cheque_date'] ?? '');
$remarks       = trim($_POST['remarks'] ?? '');

$filterFromDate = trim($_POST['filter_from_date'] ?? '');
$filterToDate   = trim($_POST['filter_to_date'] ?? '');
$filterShiftId  = intval($_POST['filter_shift_id'] ?? 0);
$filterVehicle  = trim($_POST['filter_vehicle_number'] ?? '');

// Input Validations
if ($customerId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Valid customer account is required.']);
    exit;
}
if ($amount <= 0.00) {
    echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
    exit;
}
if (empty($paymentDate) || strtotime($paymentDate) === false) {
    $paymentDate = date('Y-m-d');
}

$allowedModes = ['Cash', 'Online Payment', 'Cheque'];
if (!in_array($paymentMode, $allowedModes)) {
    $paymentMode = 'Cash';
}

if ($paymentMode === 'Online Payment' && $bankId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid bank account for online payment.']);
    exit;
}

if ($paymentMode === 'Cheque' && empty($chequeNo)) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter the cheque number.']);
    exit;
}

$userId = intval($_SESSION['loggedInUser'] ?? 0);

// Begin atomic transaction
mysqli_begin_transaction($connection);

try {
    // 1. Build slip query matching the payment filter context with row locking (FOR UPDATE)
    $where_clauses = [
        "account_number = '$customerId'",
        "slip_type = 'Permanent Slip'",
        "charge_amount > 0",
        "payment_status != 'Paid'",
        "(charge_amount - paid_amount) > 0.00",
        "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
    ];

    if (!empty($filterFromDate) && !empty($filterToDate)) {
        $f_safe = mysqli_real_escape_string($connection, $filterFromDate);
        $t_safe = mysqli_real_escape_string($connection, $filterToDate);
        $where_clauses[] = "slip_date BETWEEN '$f_safe' AND '$t_safe'";
    } elseif (!empty($filterFromDate)) {
        $f_safe = mysqli_real_escape_string($connection, $filterFromDate);
        $where_clauses[] = "slip_date >= '$f_safe'";
    } elseif (!empty($filterToDate)) {
        $t_safe = mysqli_real_escape_string($connection, $filterToDate);
        $where_clauses[] = "slip_date <= '$t_safe'";
    }

    if ($filterShiftId > 0) {
        $where_clauses[] = "shift_id = '$filterShiftId'";
    }

    if (!empty($filterVehicle)) {
        $v_safe = mysqli_real_escape_string($connection, $filterVehicle);
        $where_clauses[] = "vehicle_number LIKE '%$v_safe%'";
    }

    $where_sql = implode(' AND ', $where_clauses);
    $q_slips = "SELECT id, slip_no, slip_date, charge_amount, paid_amount 
                FROM tbl_meter_reading_credit_sales 
                WHERE $where_sql 
                ORDER BY slip_date ASC, id ASC FOR UPDATE";
    $res_slips = mysqli_query($connection, $q_slips);

    if (!$res_slips || mysqli_num_rows($res_slips) == 0) {
        // Fallback: If filtered slips had no due, check customer's overall unpaid slips
        $q_fallback = "SELECT id, slip_no, slip_date, charge_amount, paid_amount 
                       FROM tbl_meter_reading_credit_sales 
                       WHERE account_number = '$customerId' 
                         AND slip_type = 'Permanent Slip'
                         AND charge_amount > 0
                         AND payment_status != 'Paid'
                         AND (charge_amount - paid_amount) > 0.00
                         AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                       ORDER BY slip_date ASC, id ASC FOR UPDATE";
        $res_slips = mysqli_query($connection, $q_fallback);
    }

    if (!$res_slips || mysqli_num_rows($res_slips) == 0) {
        mysqli_rollback($connection);
        echo json_encode(['status' => 'error', 'message' => 'No outstanding slips found for this customer.']);
        exit;
    }

    $slips_to_settle = [];
    $total_outstanding = 0.00;
    while ($s = mysqli_fetch_assoc($res_slips)) {
        $s_chg  = floatval($s['charge_amount']);
        $s_paid = floatval($s['paid_amount']);
        $s_due  = max(0.00, round($s_chg - $s_paid, 2));
        if ($s_due > 0.00) {
            $s['due_amount'] = $s_due;
            $slips_to_settle[] = $s;
            $total_outstanding += $s_due;
        }
    }

    $total_outstanding = round($total_outstanding, 2);

    // Overpayment check (Strictly enforce no extra pay as requested)
    if ($amount > $total_outstanding) {
        mysqli_rollback($connection);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Payment amount (Rs. ' . number_format($amount, 2) . ') cannot exceed the total due balance (Rs. ' . number_format($total_outstanding, 2) . ').'
        ]);
        exit;
    }

    // 2. Generate Receipt Number: RCP-YYYYMM-XXXX
    $prefix_rcp = 'RCP-' . date('Ym', strtotime($paymentDate)) . '-';
    $q_last = mysqli_query($connection, "SELECT receipt_no FROM tbl_customer_payments WHERE receipt_no LIKE '$prefix_rcp%' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $next_num = 1;
    if ($q_last && ($r_last = mysqli_fetch_assoc($q_last))) {
        $last_seq = intval(substr($r_last['receipt_no'], strlen($prefix_rcp)));
        $next_num = $last_seq + 1;
    }
    $receiptNo = $prefix_rcp . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    // 3. Insert Master Payment Record
    $f_from_sql    = !empty($filterFromDate) ? "'" . mysqli_real_escape_string($connection, $filterFromDate) . "'" : "NULL";
    $f_to_sql      = !empty($filterToDate) ? "'" . mysqli_real_escape_string($connection, $filterToDate) . "'" : "NULL";
    $f_vehicle_sql = !empty($filterVehicle) ? "'" . mysqli_real_escape_string($connection, $filterVehicle) . "'" : "NULL";
    $bank_id_sql   = ($paymentMode === 'Online Payment' && $bankId > 0) ? "'$bankId'" : "NULL";
    $trans_ref_sql = !empty($transRef) ? "'" . mysqli_real_escape_string($connection, $transRef) . "'" : "NULL";
    $cheque_no_sql = ($paymentMode === 'Cheque' && !empty($chequeNo)) ? "'" . mysqli_real_escape_string($connection, $chequeNo) . "'" : "NULL";
    $cheque_dt_sql = ($paymentMode === 'Cheque' && !empty($chequeDate)) ? "'" . mysqli_real_escape_string($connection, $chequeDate) . "'" : "NULL";
    $remarks_sql   = !empty($remarks) ? "'" . mysqli_real_escape_string($connection, $remarks) . "'" : "NULL";

    $ins_payment = "INSERT INTO tbl_customer_payments 
                    (receipt_no, customer_id, payment_date, total_amount, payment_mode, bank_id, 
                     transaction_ref, cheque_no, cheque_date, filter_from_date, filter_to_date, 
                     filter_shift_id, filter_vehicle_number, remarks, created_by, created_at)
                    VALUES 
                    ('$receiptNo', '$customerId', '$paymentDate', '$amount', '$paymentMode', $bank_id_sql,
                     $trans_ref_sql, $cheque_no_sql, $cheque_dt_sql, $f_from_sql, $f_to_sql, 
                     '$filterShiftId', $f_vehicle_sql, $remarks_sql, '$userId', NOW())";

    if (!mysqli_query($connection, $ins_payment)) {
        throw new Exception("Error saving payment voucher: " . mysqli_error($connection));
    }

    $paymentId = mysqli_insert_id($connection);

    // 4. FIFO Cutting / Allocation Engine
    $remaining = $amount;
    $settled_count = 0;
    $partial_count = 0;

    foreach ($slips_to_settle as $slip) {
        if ($remaining <= 0.00) {
            break;
        }

        $s_id  = intval($slip['id']);
        $s_chg = floatval($slip['charge_amount']);
        $s_old_paid = floatval($slip['paid_amount']);
        $s_due = floatval($slip['due_amount']);

        $alloc = min($remaining, $s_due);
        $alloc = round($alloc, 2);

        if ($alloc > 0.00) {
            // Record allocation
            $ins_alloc = "INSERT INTO tbl_customer_payment_allocations 
                          (payment_id, credit_sale_id, allocated_amount, created_at)
                          VALUES ('$paymentId', '$s_id', '$alloc', NOW())";
            if (!mysqli_query($connection, $ins_alloc)) {
                throw new Exception("Error creating payment allocation for Slip #{$slip['slip_no']}: " . mysqli_error($connection));
            }

            // Update slip's paid_amount and payment_status
            $new_paid = round($s_old_paid + $alloc, 2);
            $new_status = ($new_paid >= $s_chg) ? 'Paid' : 'Partial';

            $upd_slip = "UPDATE tbl_meter_reading_credit_sales 
                         SET paid_amount = '$new_paid', payment_status = '$new_status' 
                         WHERE id = '$s_id'";
            if (!mysqli_query($connection, $upd_slip)) {
                throw new Exception("Error updating slip payment status for Slip #{$slip['slip_no']}: " . mysqli_error($connection));
            }

            if ($new_status === 'Paid') {
                $settled_count++;
            } else {
                $partial_count++;
            }

            $remaining = round($remaining - $alloc, 2);
        }
    }

    // Commit all operations atomically
    mysqli_commit($connection);

    $msg = "Payment of <strong>Rs. " . number_format($amount, 2) . "</strong> recorded successfully.<br>";
    if ($settled_count > 0) {
        $msg .= "• <strong>$settled_count</strong> slip(s) fully settled.<br>";
    }
    if ($partial_count > 0) {
        $msg .= "• <strong>$partial_count</strong> slip(s) partially settled.";
    }

    echo json_encode([
        'status'     => 'success',
        'message'    => $msg,
        'payment_id' => $paymentId,
        'receipt_no' => $receiptNo
    ]);

} catch (Exception $e) {
    mysqli_rollback($connection);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
