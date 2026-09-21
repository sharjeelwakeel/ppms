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

if (!has_permission('accounts', 'add') && !has_permission('accounts', 'edit') && !has_permission('card_sales', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied. You do not have access to record settlement deposits.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Self-healing schema checks
$chk_p = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_settlement_payments'");
if ($chk_p && mysqli_num_rows($chk_p) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_settlement_payments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_date` DATE NOT NULL,
      `card_machine_id` INT(11) NOT NULL,
      `bank_id` INT(11) NOT NULL,
      `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `filter_from_date` DATE DEFAULT NULL,
      `filter_to_date` DATE DEFAULT NULL,
      `transaction_ref` VARCHAR(128) DEFAULT NULL,
      `remarks` TEXT DEFAULT NULL,
      `created_by` INT(11) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_machine` (`card_machine_id`),
      KEY `idx_payment_date` (`payment_date`),
      KEY `idx_bank` (`bank_id`),
      KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

$chk_a = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_settlement_payment_allocations'");
if ($chk_a && mysqli_num_rows($chk_a) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_settlement_payment_allocations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_id` INT(11) NOT NULL,
      `settlement_id` INT(11) NOT NULL,
      `allocated_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_payment` (`payment_id`),
      KEY `idx_settlement` (`settlement_id`),
      KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

$chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'paid_amount'");
if ($chk_col && mysqli_num_rows($chk_col) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER net_amount");
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN payment_status ENUM('Unpaid', 'Partial', 'Paid') NOT NULL DEFAULT 'Unpaid' AFTER paid_amount, ADD INDEX idx_payment_status (payment_status)");
}

// Extract inputs
$cardMachineId = intval($_POST['card_machine_id'] ?? 0);
$bankId        = intval($_POST['bank_id'] ?? 0);
$paymentDate   = trim($_POST['payment_date'] ?? '');
$amount        = round(floatval($_POST['amount'] ?? 0), 2);
$transRef      = trim($_POST['transaction_ref'] ?? '');
$remarks       = trim($_POST['remarks'] ?? '');
$filterFrom    = trim($_POST['filter_from_date'] ?? '');
$filterTo      = trim($_POST['filter_to_date'] ?? '');

// Input Validations
if ($cardMachineId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid POS card machine.']);
    exit;
}

if ($bankId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a destination bank account from Bank Master.']);
    exit;
}

// Verify bank exists
$q_bank = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE id = '$bankId' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
if (!$q_bank || mysqli_num_rows($q_bank) == 0) {
    echo json_encode(['status' => 'error', 'message' => 'The selected bank account was not found in Bank Master.']);
    exit;
}
$bankInfo = mysqli_fetch_assoc($q_bank);

if ($amount <= 0.00) {
    echo json_encode(['status' => 'error', 'message' => 'Deposit amount received must be greater than zero.']);
    exit;
}

if (empty($paymentDate) || strtotime($paymentDate) === false) {
    $paymentDate = date('Y-m-d');
}

$userId = intval($_SESSION['loggedInUser'] ?? 0);

// Begin atomic transaction
mysqli_begin_transaction($connection);

try {
    // 1. Build open settlement batches query with row locking (FOR UPDATE)
    $where_clauses = [
        "card_machine_id = '$cardMachineId'",
        "payment_status != 'Paid'",
        "(net_amount - paid_amount) > 0.00",
        "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
    ];

    if (!empty($filterFrom) && !empty($filterTo)) {
        $f_safe = mysqli_real_escape_string($connection, $filterFrom);
        $t_safe = mysqli_real_escape_string($connection, $filterTo);
        $where_clauses[] = "settlement_date BETWEEN '$f_safe' AND '$t_safe'";
    } elseif (!empty($filterFrom)) {
        $f_safe = mysqli_real_escape_string($connection, $filterFrom);
        $where_clauses[] = "settlement_date >= '$f_safe'";
    } elseif (!empty($filterTo)) {
        $t_safe = mysqli_real_escape_string($connection, $filterTo);
        $where_clauses[] = "settlement_date <= '$t_safe'";
    }

    $where_sql = implode(' AND ', $where_clauses);
    $q_batches = "SELECT id, batch_no, settlement_date, net_amount, paid_amount 
                  FROM tbl_card_sale_settlements 
                  WHERE $where_sql 
                  ORDER BY settlement_date ASC, id ASC FOR UPDATE";
    $res_batches = mysqli_query($connection, $q_batches);

    // Fallback: If date-filtered batches returned none, look for any open batches for this machine
    if (!$res_batches || mysqli_num_rows($res_batches) == 0) {
        $q_fallback = "SELECT id, batch_no, settlement_date, net_amount, paid_amount 
                       FROM tbl_card_sale_settlements 
                       WHERE card_machine_id = '$cardMachineId' 
                         AND payment_status != 'Paid'
                         AND (net_amount - paid_amount) > 0.00
                         AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                       ORDER BY settlement_date ASC, id ASC FOR UPDATE";
        $res_batches = mysqli_query($connection, $q_fallback);
    }

    if (!$res_batches || mysqli_num_rows($res_batches) == 0) {
        mysqli_rollback($connection);
        echo json_encode(['status' => 'error', 'message' => 'No outstanding settlement batches found for this POS card machine.']);
        exit;
    }

    $batches_to_settle = [];
    $total_outstanding = 0.00;
    while ($b = mysqli_fetch_assoc($res_batches)) {
        $b_net  = floatval($b['net_amount']);
        $b_paid = floatval($b['paid_amount']);
        $b_due  = max(0.00, round($b_net - $b_paid, 2));
        if ($b_due > 0.00) {
            $b['due_amount'] = $b_due;
            $batches_to_settle[] = $b;
            $total_outstanding += $b_due;
        }
    }

    $total_outstanding = round($total_outstanding, 2);

    // Overpayment check (Strictly enforce no extra pay)
    if ($amount > $total_outstanding) {
        mysqli_rollback($connection);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Deposit amount (Rs. ' . number_format($amount, 2) . ') cannot exceed the total outstanding balance (Rs. ' . number_format($total_outstanding, 2) . ').'
        ]);
        exit;
    }

    // 2. Insert Master Bank Deposit Voucher (tbl_card_settlement_payments)
    $f_from_sql  = !empty($filterFrom) ? "'" . mysqli_real_escape_string($connection, $filterFrom) . "'" : "NULL";
    $f_to_sql    = !empty($filterTo) ? "'" . mysqli_real_escape_string($connection, $filterTo) . "'" : "NULL";
    $trans_sql   = !empty($transRef) ? "'" . mysqli_real_escape_string($connection, $transRef) . "'" : "NULL";
    $remarks_sql = !empty($remarks) ? "'" . mysqli_real_escape_string($connection, $remarks) . "'" : "NULL";
    $p_date_sql  = "'" . mysqli_real_escape_string($connection, $paymentDate) . "'";

    $ins_payment = "INSERT INTO tbl_card_settlement_payments 
                    (payment_date, card_machine_id, bank_id, total_amount, filter_from_date, filter_to_date, transaction_ref, remarks, created_by, created_at)
                    VALUES 
                    ($p_date_sql, '$cardMachineId', '$bankId', '$amount', $f_from_sql, $f_to_sql, $trans_sql, $remarks_sql, '$userId', NOW())";

    if (!mysqli_query($connection, $ins_payment)) {
        throw new Exception("Error saving bank deposit voucher: " . mysqli_error($connection));
    }

    $paymentId = mysqli_insert_id($connection);

    // 3. FIFO Allocation Engine Across Settlement Batches
    $remaining = $amount;
    $settled_count = 0;
    $partial_count = 0;

    foreach ($batches_to_settle as $batch) {
        if ($remaining <= 0.00) {
            break;
        }

        $b_id       = intval($batch['id']);
        $b_net      = floatval($batch['net_amount']);
        $b_old_paid = floatval($batch['paid_amount']);
        $b_due      = floatval($batch['due_amount']);

        $alloc = min($remaining, $b_due);
        $alloc = round($alloc, 2);

        if ($alloc > 0.00) {
            // Record allocation bridge
            $ins_alloc = "INSERT INTO tbl_card_settlement_payment_allocations 
                          (payment_id, settlement_id, allocated_amount, created_at)
                          VALUES ('$paymentId', '$b_id', '$alloc', NOW())";
            if (!mysqli_query($connection, $ins_alloc)) {
                throw new Exception("Error recording allocation for Batch #{$batch['batch_no']}: " . mysqli_error($connection));
            }

            // Update batch's paid_amount and payment_status
            $new_paid = round($b_old_paid + $alloc, 2);
            $new_status = ($new_paid >= $b_net) ? 'Paid' : 'Partial';

            $upd_batch = "UPDATE tbl_card_sale_settlements 
                          SET paid_amount = '$new_paid', payment_status = '$new_status' 
                          WHERE id = '$b_id'";
            if (!mysqli_query($connection, $upd_batch)) {
                throw new Exception("Error updating payment status for Batch #{$batch['batch_no']}: " . mysqli_error($connection));
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

    $msg = "Bank deposit of <strong>Rs. " . number_format($amount, 2) . "</strong> credited to <strong>" . htmlspecialchars($bankInfo['name']) . "</strong> successfully.<br>";
    if ($settled_count > 0) {
        $msg .= "• <strong>$settled_count</strong> batch(es) fully settled.<br>";
    }
    if ($partial_count > 0) {
        $msg .= "• <strong>$partial_count</strong> batch(es) partially settled.";
    }

    echo json_encode([
        'status'       => 'success',
        'message'      => $msg,
        'payment_id'   => $paymentId,
        'payment_date' => $paymentDate,
        'total_amount' => $amount,
        'bank_name'    => $bankInfo['name'] . ' (' . $bankInfo['account_number'] . ')'
    ]);

} catch (Exception $e) {
    mysqli_rollback($connection);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
