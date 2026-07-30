<?php
require 'session.php';
if (!userloggedin()) {
    echo "Unauthorized access.";
    exit;
}
require 'config.php';

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $stmt = mysqli_prepare($connection, "UPDATE tbl_tank_dip_logs SET deleted_at = NOW() WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        if (mysqli_stmt_execute($stmt)) {
            echo "Success";
        } else {
            echo "Error deleting record: " . mysqli_error($connection);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo "Error preparing statement.";
    }
}
?>
