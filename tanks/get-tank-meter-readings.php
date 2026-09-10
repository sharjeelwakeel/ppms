<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
require_once __DIR__ . '/../include/config.php';

header('Content-Type: application/json');

$tank_id  = isset($_REQUEST['tank_id']) ? intval($_REQUEST['tank_id']) : 0;
$date     = isset($_REQUEST['date']) ? trim($_REQUEST['date']) : '';
$shift_id = isset($_REQUEST['shift_id']) ? intval($_REQUEST['shift_id']) : 0;

if ($tank_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Tank ID is required.']);
    exit;
}

$date_safe = mysqli_real_escape_string($connection, $date);

// Fetch all active nozzles attached to this tank
$nozzles = [];
$total_usage = 0.00;
$all_found = true;

$stmt_nozzles = mysqli_prepare($connection, "SELECT id, name, start_reading 
    FROM tbl_nozzles 
    WHERE tank_id = ? AND status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
    ORDER BY id ASC");

if ($stmt_nozzles) {
    mysqli_stmt_bind_param($stmt_nozzles, "i", $tank_id);
    mysqli_stmt_execute($stmt_nozzles);
    $res_nozzles = mysqli_stmt_get_result($stmt_nozzles);
    
    while ($row = mysqli_fetch_assoc($res_nozzles)) {
        $nid = intval($row['id']);
        $live_rdg = floatval($row['start_reading']);
        
        $found_meter_reading = false;
        $prev_rdg = $live_rdg;
        $current_rdg = $live_rdg;
        $net_sale = 0.00;

        // 1. Look directly in tbl_meter_reading_details for this exact date & shift
        if (!empty($date_safe) && $shift_id > 0) {
            $q_mr = mysqli_query($connection, "SELECT mrd.last_reading, mrd.current_reading, mrd.net_sale 
                                               FROM tbl_meter_reading_details mrd
                                               INNER JOIN tbl_meter_readings mr ON (mrd.meter_reading_id = mr.id)
                                               WHERE mrd.nozzle_id = '$nid' 
                                                 AND mr.date = '$date_safe' 
                                                 AND mr.shift_id = '$shift_id' 
                                                 AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                                               ORDER BY mr.id DESC, mrd.id DESC LIMIT 1");
            if ($q_mr && ($r_mr = mysqli_fetch_assoc($q_mr))) {
                $found_meter_reading = true;
                $prev_rdg    = floatval($r_mr['last_reading']);
                $current_rdg = floatval($r_mr['current_reading']);
                $net_sale    = floatval($r_mr['net_sale']);
            }
        }

        // 2. If no shift meter reading found for this exact date/shift, fallback to previous shift closing
        if (!$found_meter_reading) {
            $all_found = false;
            $prev_rdg = $live_rdg;

            // Check latest previous shift closing reading from tbl_meter_reading_details
            if (!empty($date_safe)) {
                $q_prev_mr = mysqli_query($connection, "SELECT mrd.current_reading 
                                                        FROM tbl_meter_reading_details mrd
                                                        INNER JOIN tbl_meter_readings mr ON (mrd.meter_reading_id = mr.id)
                                                        WHERE mrd.nozzle_id = '$nid' 
                                                          AND (mr.date < '$date_safe' OR (mr.date = '$date_safe' AND mr.shift_id < '$shift_id'))
                                                          AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
                                                        ORDER BY mr.date DESC, mr.shift_id DESC, mr.id DESC LIMIT 1");
                if ($q_prev_mr && ($r_p_mr = mysqli_fetch_assoc($q_prev_mr))) {
                    $prev_rdg = floatval($r_p_mr['current_reading']);
                } else {
                    // Fallback to latest previous dip log
                    $q_prev_dip = mysqli_query($connection, "SELECT ml.reading 
                                                             FROM tbl_tank_dip_meter_logs ml
                                                             INNER JOIN tbl_tank_dip_logs dl ON ml.dip_log_id = dl.id
                                                             WHERE dl.tank_id = '$tank_id' AND ml.nozzle_id = '$nid' AND dl.deleted_at IS NULL
                                                             ORDER BY dl.date DESC, dl.id DESC LIMIT 1");
                    if ($q_prev_dip && ($r_prev_dip = mysqli_fetch_assoc($q_prev_dip))) {
                        $prev_rdg = floatval($r_prev_dip['reading']);
                    }
                }
            }

            $current_rdg = $prev_rdg;
            $net_sale    = 0.00;
        }

        $total_usage += $net_sale;

        $nozzles[] = [
            'id'                 => $nid,
            'name'               => $row['name'],
            'prev_reading'       => $prev_rdg,
            'current_reading'    => $current_rdg,
            'net_sale'           => $net_sale,
            'from_meter_reading' => $found_meter_reading
        ];
    }
    mysqli_stmt_close($stmt_nozzles);
}

echo json_encode([
    'success'     => true,
    'has_nozzles' => !empty($nozzles),
    'all_found'   => $all_found,
    'total_usage' => $total_usage,
    'nozzles'     => $nozzles
]);
?>
