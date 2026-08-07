<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    $sql = "UPDATE tbl_staff_roles SET deleted_at = NOW() WHERE id='$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Staff designation deleted.';
    } else {
        echo "Error: " . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
