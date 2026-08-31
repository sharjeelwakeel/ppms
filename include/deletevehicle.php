<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

// Enforce access check for deleting vehicles
if (!has_permission('vehicles', 'delete')) {
    echo 'unauthorized';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    $query = "UPDATE tbl_customer_vehicles SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $query)) {
        echo 'deleted';
    } else {
        echo 'error: ' . mysqli_error($connection);
    }
} else {
    echo 'invalid_id';
}
mysqli_close($connection);
?>
