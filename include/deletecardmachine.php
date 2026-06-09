<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require 'config.php';
if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $sql = "DELETE FROM tbl_card_machines WHERE id='$id'";
    mysqli_query($connection, $sql);
}
?>
