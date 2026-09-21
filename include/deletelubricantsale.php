<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('items', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

$invoice_id = intval($_POST['invoice_id'] ?? 0);
$id = intval($_POST['id'] ?? 0);

if ($invoice_id > 0) {
    mysqli_begin_transaction($connection);
    try {
        mysqli_query($connection, "UPDATE tbl_lubricant_sale_invoices SET deleted_at = NOW() WHERE id = '$invoice_id'");
        mysqli_query($connection, "UPDATE tbl_lubricant_sales SET deleted_at = NOW() WHERE invoice_id = '$invoice_id'");
        mysqli_commit($connection);
        echo 'Sale deleted.';
    } catch (Exception $e) {
        mysqli_rollback($connection);
        echo 'Error: ' . $e->getMessage();
    }
} elseif ($id > 0) {
    $chk_inv = mysqli_query($connection, "SELECT id FROM tbl_lubricant_sale_invoices WHERE id = '$id'");
    if ($chk_inv && mysqli_num_rows($chk_inv) > 0) {
        mysqli_begin_transaction($connection);
        try {
            mysqli_query($connection, "UPDATE tbl_lubricant_sale_invoices SET deleted_at = NOW() WHERE id = '$id'");
            mysqli_query($connection, "UPDATE tbl_lubricant_sales SET deleted_at = NOW() WHERE invoice_id = '$id'");
            mysqli_commit($connection);
            echo 'Sale deleted.';
        } catch (Exception $e) {
            mysqli_rollback($connection);
            echo 'Error: ' . $e->getMessage();
        }
    } else {
        $sql = "UPDATE tbl_lubricant_sales SET deleted_at = NOW() WHERE id = '$id'";
        if (mysqli_query($connection, $sql)) {
            echo 'Sale deleted.';
        } else {
            echo 'Error: ' . mysqli_error($connection);
        }
    }
}
mysqli_close($connection);
?>
