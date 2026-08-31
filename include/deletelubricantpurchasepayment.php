<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

// Enforce access check
if (!has_permission('items', 'edit') && !has_permission('items', 'delete')) {
    echo 'unauthorized';
    exit;
}

// Auto-migrate tbl_lubricant_purchase_payments if missing deleted_at
$chk_lpp_del = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_purchase_payments LIKE 'deleted_at'");
if ($chk_lpp_del && mysqli_num_rows($chk_lpp_del) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_purchase_payments ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    mysqli_begin_transaction($connection);
    try {
        // Fetch purchase_id of the payment
        $pay_res = mysqli_query($connection, "SELECT purchase_id FROM tbl_lubricant_purchase_payments WHERE id = '$id' LIMIT 1");
        if ($pay_row = mysqli_fetch_assoc($pay_res)) {
            $purchase_id = intval($pay_row['purchase_id']);
            
            // Soft delete payment
            mysqli_query($connection, "UPDATE tbl_lubricant_purchase_payments SET deleted_at = NOW() WHERE id = '$id'");
            
            // Fetch purchase total cost
            $purch_res = mysqli_query($connection, "SELECT quantity, purchase_price FROM tbl_lubricant_purchases WHERE id = '$purchase_id' LIMIT 1");
            $purch_row = mysqli_fetch_assoc($purch_res);
            $total_cost = floatval($purch_row['quantity'] ?? 0) * floatval($purch_row['purchase_price'] ?? 0);
            
            // Fetch remaining payments sum
            $sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_lubricant_purchase_payments WHERE purchase_id = '$purchase_id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $sum_row = mysqli_fetch_assoc($sum_res);
            $total_paid = floatval($sum_row['total_paid'] ?? 0);
            
            // Recalculate status
            $new_status = 'unpaid';
            if ($total_paid >= $total_cost && $total_cost > 0) {
                $new_status = 'paid';
            } else if ($total_paid > 0) {
                $new_status = 'in process';
            }
            
            mysqli_query($connection, "UPDATE tbl_lubricant_purchases SET payment_status = '$new_status' WHERE id = '$purchase_id'");
        }
        mysqli_commit($connection);
        echo 'deleted';
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo 'error:' . $e->getMessage();
    }
}
mysqli_close($connection);
?>
