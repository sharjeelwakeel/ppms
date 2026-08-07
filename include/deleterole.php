<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    // Check if role is Admin
    $check_sql = "SELECT name FROM tbl_roles WHERE id = '$id' LIMIT 1";
    $check_res = mysqli_query($connection, $check_sql);
    if ($check_res && ($row = mysqli_fetch_assoc($check_res))) {
        if ($id == 1 || strtolower(trim($row['name'])) === 'admin') {
            echo 'Error: System Admin role is protected and cannot be deleted.';
            exit;
        }
    }

    $sql = "DELETE FROM tbl_roles WHERE id='$id'";
    if (mysqli_query($connection, $sql)) {
        mysqli_query($connection, "DELETE FROM tbl_role_permissions WHERE role_id='$id'");
        echo 'Role deleted.';
    } else {
        echo "Error: " . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
