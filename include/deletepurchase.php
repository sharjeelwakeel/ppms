<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require 'config.php';
if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    $sql = "UPDATE tbl_purchases SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'deleted';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
