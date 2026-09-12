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
    $id = intval($_POST['id']);
    
    // Fetch nozzle details before marking deleted
    $q_details = mysqli_query($connection, "SELECT nozzle_id, last_reading FROM tbl_meter_reading_details WHERE meter_reading_id = '$id'");
    $affected_nozzles = [];
    if ($q_details) {
        while ($det = mysqli_fetch_assoc($q_details)) {
            $noz_id = intval($det['nozzle_id']);
            if ($noz_id > 0) {
                $affected_nozzles[$noz_id] = floatval($det['last_reading']);
            }
        }
    }

    // Soft delete the meter reading header
    $sql = "UPDATE tbl_meter_readings SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        // For each affected nozzle, sync start_reading to the latest active meter reading
        foreach ($affected_nozzles as $noz_id => $baseline_last_reading) {
            $latest_q = mysqli_query($connection, "
                SELECT mrd.current_reading 
                FROM tbl_meter_reading_details mrd
                JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
                WHERE mrd.nozzle_id = '$noz_id'
                  AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC
                LIMIT 1
            ");
            if ($latest_q && mysqli_num_rows($latest_q) > 0) {
                $latest_row = mysqli_fetch_assoc($latest_q);
                $new_start = floatval($latest_row['current_reading']);
            } else {
                // If no active meter readings remain, revert to baseline opening reading
                $new_start = $baseline_last_reading;
            }
            mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = '$new_start' WHERE id = '$noz_id'");
        }

        echo 'Meter reading deleted.';
    } else {
        echo 'error:' . mysqli_error($connection);
    }
}
mysqli_close($connection);
?>
