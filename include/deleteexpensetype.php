<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

// Enforce access check for deleting expense types
check_access('expenses', 'delete');

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // Check if this is a system-protected category (e.g. Nozzle Expense)
    $chk_sys = mysqli_query($connection, "SELECT is_system, name FROM tbl_expense_types WHERE id = '$id' LIMIT 1");
    $sys_row = mysqli_fetch_assoc($chk_sys);

    if ($sys_row && ($sys_row['is_system'] == 1 || strtolower(trim($sys_row['name'])) === 'nozzle expense')) {
        header('Location: ../expenses/expense-types-list.php?msg=system_protected');
        exit;
    }

    $sql = "UPDATE tbl_expense_types SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        header('Location: ../expenses/expense-types-list.php?msg=deleted');
        exit;
    } else {
        header('Location: ../expenses/expense-types-list.php?msg=error');
        exit;
    }
} else {
    header('Location: ../expenses/expense-types-list.php');
    exit;
}
?>
