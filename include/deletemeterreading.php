<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

if (!has_permission('meter_readings', 'delete')) {
    echo 'Error: Unauthorized operation.';
    exit;
}

if (isset($_POST['id']) && !empty($_POST['id'])) {
    $id = mysqli_real_escape_string($connection, $_POST['id']);
    
    // 1. Revert nozzle meter readings from tbl_meter_reading_details
    $q_details = mysqli_query($connection, "SELECT nozzle_id, last_reading, current_reading, net_sale FROM tbl_meter_reading_details WHERE meter_reading_id = '$id'");
    if ($q_details) {
        while ($det = mysqli_fetch_assoc($q_details)) {
            $noz_id = intval($det['nozzle_id']);
            $last_r = floatval($det['last_reading']);
            $curr_r = floatval($det['current_reading']);
            $net_s  = floatval($det['net_sale']);
            if ($noz_id > 0) {
                // If nozzle is still at the closing reading, revert to last_reading; otherwise deduct net_sale
                mysqli_query($connection, "UPDATE tbl_nozzles 
                    SET start_reading = CASE 
                        WHEN ROUND(start_reading, 2) = ROUND($curr_r, 2) THEN $last_r 
                        ELSE GREATEST(start_reading - $net_s, 0.00) 
                    END 
                    WHERE id = '$noz_id'");
            }
        }
    }

    // 2. Find date and shift to clean up daily nozzle readings
    $q_del = mysqli_query($connection, "SELECT date, shift_id FROM tbl_meter_readings WHERE id = '$id' LIMIT 1");
    if ($q_del && $r_del = mysqli_fetch_assoc($q_del)) {
        $del_date = $r_del['date'];
        $del_shift = intval($r_del['shift_id']);
        mysqli_query($connection, "DELETE FROM tbl_daily_nozzle_readings WHERE date = '$del_date' AND shift_id = '$del_shift' AND source = 'meter_reading'");
    }

    $sql = "UPDATE tbl_meter_readings SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo 'Meter reading deleted.';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
