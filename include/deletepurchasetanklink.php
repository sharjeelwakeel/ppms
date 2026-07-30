<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    $query = "DELETE FROM tbl_purchase_tank_links WHERE id = '$id'";
    if (mysqli_query($connection, $query)) {
        echo 'deleted';
    } else {
        echo 'error: ' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
