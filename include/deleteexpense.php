<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

// Enforce access check for deleting expenses
check_access('expenses', 'delete');

if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, trim($_GET['id']));
    $sql = "UPDATE tbl_expenses SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        header('Location: ../expenses/expenses-list.php?msg=deleted');
        exit;
    } else {
        header('Location: ../expenses/expenses-list.php?msg=error');
        exit;
    }
} else {
    header('Location: ../expenses/expenses-list.php');
    exit;
}
?>
