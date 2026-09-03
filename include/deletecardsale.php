<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';
require_once 'nozzle_daily_sync.php';

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

    // Revert dispensed petrol litres from tbl_nozzles and tbl_daily_nozzle_readings
    $q_prev = mysqli_query($connection, "SELECT nozzle_id, shift_id, SUM(quantity) AS total_qty 
                                          FROM tbl_meter_reading_card_sales 
                                          WHERE sale_date = '$date' $shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                          GROUP BY nozzle_id, shift_id");
    if ($q_prev) {
        while ($p_row = mysqli_fetch_assoc($q_prev)) {
            $p_noz = intval($p_row['nozzle_id']);
            $p_shift = intval($p_row['shift_id']);
            $p_qty = floatval($p_row['total_qty']);
            if ($p_noz > 0 && $p_qty > 0) {
                mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = GREATEST(start_reading - $p_qty, 0.00) WHERE id = '$p_noz'");
                sync_nozzle_daily_card_sale_delta($connection, $date, $p_shift, $p_noz, -$p_qty);
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
    $q_single = mysqli_query($connection, "SELECT nozzle_id, quantity, sale_date, shift_id FROM tbl_meter_reading_card_sales WHERE id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_single && $s_row = mysqli_fetch_assoc($q_single)) {
        $s_noz   = intval($s_row['nozzle_id']);
        $s_qty   = floatval($s_row['quantity']);
        $s_date  = $s_row['sale_date'];
        $s_shift = intval($s_row['shift_id']);
        if ($s_noz > 0 && $s_qty > 0) {
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = GREATEST(start_reading - $s_qty, 0.00) WHERE id = '$s_noz'");
            sync_nozzle_daily_card_sale_delta($connection, $s_date, $s_shift, $s_noz, -$s_qty);
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
