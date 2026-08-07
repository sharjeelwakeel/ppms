<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

// Enforce access check for deleting users
if (!has_permission('users', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    
    // Check if account is Primary / Super Admin
    $chk_sql = "SELECT a.id, a.type, a.username, r.name as role_name 
                FROM tbl_accounts a 
                LEFT JOIN tbl_roles r ON a.role_id = r.id 
                WHERE a.id = '$id' LIMIT 1";
    $chk_res = mysqli_query($connection, $chk_sql);
    if ($chk_res && ($acc = mysqli_fetch_assoc($chk_res))) {
        $isAdmin = ($acc['id'] == 1 || 
                    strtolower(trim($acc['type'] ?? '')) === 'admin' || 
                    strtolower(trim($acc['username'] ?? '')) === 'admin' || 
                    strtolower(trim($acc['role_name'] ?? '')) === 'admin');
        
        if ($isAdmin) {
            echo 'Error: Primary Admin account is protected and cannot be deleted.';
            exit;
        }
    }

    $sql = "UPDATE tbl_accounts SET deleted_at = NOW() WHERE id='$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'User deleted.';
    } else {
        echo "Error: " . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
