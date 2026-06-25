<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require 'config.php';
if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    
    mysqli_begin_transaction($connection);
    try {
        // Fetch purchase_id of the payment
        $pay_res = mysqli_query($connection, "SELECT purchase_id FROM tbl_purchase_payments WHERE id = '$id' LIMIT 1");
        if ($pay_row = mysqli_fetch_assoc($pay_res)) {
            $purchase_id = $pay_row['purchase_id'];
            
            // Soft delete payment
            mysqli_query($connection, "UPDATE tbl_purchase_payments SET deleted_at = NOW() WHERE id = '$id'");
            
            // Fetch purchase total cost
            $purch_res = mysqli_query($connection, "SELECT quantity, price FROM tbl_purchases WHERE id = '$purchase_id' LIMIT 1");
            $purch_row = mysqli_fetch_assoc($purch_res);
            $total_cost = floatval($purch_row['quantity']) * floatval($purch_row['price']);
            
            // Fetch remaining payments sum
            $sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_purchase_payments WHERE purchase_id = '$purchase_id' AND deleted_at IS NULL");
            $sum_row = mysqli_fetch_assoc($sum_res);
            $total_paid = floatval($sum_row['total_paid'] ?? 0);
            
            // Recalculate status
            $new_status = 'unpaid';
            if ($total_paid >= $total_cost) {
                $new_status = 'paid';
            } else if ($total_paid > 0) {
                $new_status = 'in process';
            }
            
            mysqli_query($connection, "UPDATE tbl_purchases SET payment_status = '$new_status' WHERE id = '$purchase_id'");
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
