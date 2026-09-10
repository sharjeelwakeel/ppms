<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('card_sales', 'delete') && !has_permission('meter_readings', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['date']) && !empty($_POST['date'])) {
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $shift_clause = "";
    $shift_id = 0;
    if (isset($_POST['shift_id']) && intval($_POST['shift_id']) > 0) {
        $shift_id = intval($_POST['shift_id']);
        $shift_clause = " AND shift_id = '$shift_id'";
    }

    $sql = "UPDATE tbl_meter_reading_card_sales SET deleted_at = NOW() WHERE sale_date = '$date' $shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    if (mysqli_query($connection, $sql)) {
        echo 'Card sales for ' . htmlspecialchars($date) . ' deleted successfully.';
    } else {
        echo 'error: ' . mysqli_error($connection);
    }
} elseif (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    $sql = "UPDATE tbl_meter_reading_card_sales SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Card sale transaction deleted successfully.';
    } else {
        echo 'error: ' . mysqli_error($connection);
    }
} else {
    echo 'Error: Missing parameters.';
}
mysqli_close($connection);
?>
