<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('tanks', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    $sql = "UPDATE tbl_tanks SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Tank deleted.';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
