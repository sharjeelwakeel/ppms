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
    $sql = "UPDATE tbl_lubricant_products SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        mysqli_query($connection, "UPDATE tbl_prices SET deleted_at = NOW(), is_active = 0 WHERE table_name = 'tbl_lubricant_products' AND table_id = '$id'");
        echo 'Product deleted.';
    } else {
        echo 'Error: ' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
