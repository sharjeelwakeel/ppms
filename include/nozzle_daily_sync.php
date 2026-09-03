<?php
/**
 * Daily Nozzle Meter Readings Synchronization Helper
 * Maintains day-to-day nozzle meter records in tbl_daily_nozzle_readings
 * whenever transactions are added, edited, or deleted.
 */

if (!function_exists('sync_nozzle_daily_meter_reading')) {
    function sync_nozzle_daily_meter_reading($connection, $date, $shift_id, $nozzle_id, $last_reading, $current_reading, $net_sale) {
        $nozzle_id = intval($nozzle_id);
        $shift_id  = intval($shift_id);
        $date_safe = mysqli_real_escape_string($connection, $date);
        $last_r    = floatval($last_reading);
        $curr_r    = floatval($current_reading);
        $net_s     = floatval($net_sale);

        // Fetch tank_id
        $tank_id = 0;
        $q_t = mysqli_query($connection, "SELECT tank_id FROM tbl_nozzles WHERE id = '$nozzle_id' LIMIT 1");
        if ($q_t && $r_t = mysqli_fetch_assoc($q_t)) {
            $tank_id = intval($r_t['tank_id']);
        }

        $sql = "INSERT INTO tbl_daily_nozzle_readings 
                (date, shift_id, nozzle_id, tank_id, opening_reading, closing_reading, dispensed_litres, source)
                VALUES 
                ('$date_safe', '$shift_id', '$nozzle_id', '$tank_id', '$last_r', '$curr_r', '$net_s', 'meter_reading')
                ON DUPLICATE KEY UPDATE
                opening_reading = VALUES(opening_reading),
                closing_reading = VALUES(closing_reading),
                dispensed_litres = VALUES(dispensed_litres),
                source = 'meter_reading'";
        return mysqli_query($connection, $sql);
    }
}

if (!function_exists('sync_nozzle_daily_card_sale_delta')) {
    function sync_nozzle_daily_card_sale_delta($connection, $date, $shift_id, $nozzle_id, $delta_qty) {
        $nozzle_id = intval($nozzle_id);
        $shift_id  = intval($shift_id);
        $date_safe = mysqli_real_escape_string($connection, $date);
        $delta_qty = floatval($delta_qty);

        if ($nozzle_id <= 0 || $delta_qty == 0) return true;

        // Fetch nozzle info
        $tank_id = 0;
        $current_live_reading = 0.00;
        $q_t = mysqli_query($connection, "SELECT tank_id, start_reading FROM tbl_nozzles WHERE id = '$nozzle_id' LIMIT 1");
        if ($q_t && $r_t = mysqli_fetch_assoc($q_t)) {
            $tank_id = intval($r_t['tank_id']);
            $current_live_reading = floatval($r_t['start_reading']);
        }

        // Check if daily record exists
        $q_ex = mysqli_query($connection, "SELECT id, opening_reading, closing_reading, dispensed_litres 
                                            FROM tbl_daily_nozzle_readings 
                                            WHERE date = '$date_safe' AND shift_id = '$shift_id' AND nozzle_id = '$nozzle_id' LIMIT 1");
        if ($q_ex && $row = mysqli_fetch_assoc($q_ex)) {
            $new_closing = max(0.00, floatval($row['closing_reading']) + $delta_qty);
            $new_dispensed = max(0.00, floatval($row['dispensed_litres']) + $delta_qty);
            $sql = "UPDATE tbl_daily_nozzle_readings 
                    SET closing_reading = '$new_closing', dispensed_litres = '$new_dispensed' 
                    WHERE id = '{$row['id']}'";
            return mysqli_query($connection, $sql);
        } else {
            $open_r  = max(0.00, $current_live_reading - $delta_qty);
            $close_r = $current_live_reading;
            $disp_l  = max(0.00, $delta_qty);
            $sql = "INSERT INTO tbl_daily_nozzle_readings 
                    (date, shift_id, nozzle_id, tank_id, opening_reading, closing_reading, dispensed_litres, source)
                    VALUES 
                    ('$date_safe', '$shift_id', '$nozzle_id', '$tank_id', '$open_r', '$close_r', '$disp_l', 'card_sale')";
            return mysqli_query($connection, $sql);
        }
    }
}

if (!function_exists('sync_nozzle_daily_dip_reading')) {
    function sync_nozzle_daily_dip_reading($connection, $date, $shift_id, $nozzle_id, $tank_id, $current_reading, $prev_reading) {
        $nozzle_id = intval($nozzle_id);
        $shift_id  = intval($shift_id);
        $tank_id   = intval($tank_id);
        $date_safe = mysqli_real_escape_string($connection, $date);
        $curr_r    = floatval($current_reading);
        $prev_r    = floatval($prev_reading);
        $disp_l    = max(0.00, $curr_r - $prev_r);

        $sql = "INSERT INTO tbl_daily_nozzle_readings 
                (date, shift_id, nozzle_id, tank_id, opening_reading, closing_reading, dispensed_litres, source)
                VALUES 
                ('$date_safe', '$shift_id', '$nozzle_id', '$tank_id', '$prev_r', '$curr_r', '$disp_l', 'manual_dip')
                ON DUPLICATE KEY UPDATE
                closing_reading = VALUES(closing_reading),
                dispensed_litres = VALUES(dispensed_litres),
                source = 'manual_dip'";
        return mysqli_query($connection, $sql);
    }
}
?>
