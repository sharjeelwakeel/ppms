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

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    $sql = "UPDATE tbl_items SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        // Soft delete associated price records in tbl_prices
        mysqli_query($connection, "
            UPDATE tbl_prices 
            SET deleted_at = NOW(), is_active = 0 
            WHERE table_name = 'tbl_items' 
              AND table_id = '$id' 
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ");
        echo 'Item deleted.';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
