<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('card_machines', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    $sql = "UPDATE tbl_card_machines SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'deleted';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
