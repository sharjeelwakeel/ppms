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
    if (isset($_POST['shift_id']) && intval($_POST['shift_id']) > 0) {
        $shift_id = intval($_POST['shift_id']);
        $shift_clause = " AND shift_id = '$shift_id'";
    }

    // Revert dispensed petrol litres from tbl_nozzles
    $q_prev = mysqli_query($connection, "SELECT nozzle_id, SUM(quantity) AS total_qty 
                                          FROM tbl_meter_reading_card_sales 
                                          WHERE sale_date = '$date' $shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          GROUP BY nozzle_id");
    if ($q_prev) {
        while ($p_row = mysqli_fetch_assoc($q_prev)) {
            $p_noz = intval($p_row['nozzle_id']);
            $p_qty = floatval($p_row['total_qty']);
            if ($p_noz > 0 && $p_qty > 0) {
                mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = GREATEST(start_reading - $p_qty, 0.00) WHERE id = '$p_noz'");
            }
        }
    }

    $sql = "UPDATE tbl_meter_reading_card_sales SET deleted_at = NOW() WHERE sale_date = '$date' $shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    if (mysqli_query($connection, $sql)) {
        echo 'Card sales for ' . htmlspecialchars($date) . ' deleted successfully.';
    } else {
        echo 'error: ' . mysqli_error($connection);
    }
} elseif (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = intval($_POST['id']);
    // Revert dispensed petrol litres for single transaction
    $q_single = mysqli_query($connection, "SELECT nozzle_id, quantity FROM tbl_meter_reading_card_sales WHERE id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_single && $s_row = mysqli_fetch_assoc($q_single)) {
        $s_noz = intval($s_row['nozzle_id']);
        $s_qty = floatval($s_row['quantity']);
        if ($s_noz > 0 && $s_qty > 0) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = GREATEST(start_reading - $s_qty, 0.00) WHERE id = '$s_noz'");
        }
    }
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
