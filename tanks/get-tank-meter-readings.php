<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
require '../include/config.php';

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
        
        $found_daily = false;
        $prev_rdg = $live_rdg;
        $current_rdg = $live_rdg;
        $net_sale = 0.00;

        // 1. Check if daily nozzle reading snapshot exists for this exact date & shift
        if (!empty($date_safe)) {
            $shift_clause = ($shift_id > 0) ? " AND shift_id = '$shift_id'" : "";
            $q_daily = mysqli_query($connection, "SELECT opening_reading, closing_reading, dispensed_litres 
                                                  FROM tbl_daily_nozzle_readings 
                                                  WHERE nozzle_id = '$nid' AND date = '$date_safe' $shift_clause 
                                                  ORDER BY shift_id DESC LIMIT 1");
            if ($q_daily && $r_daily = mysqli_fetch_assoc($q_daily)) {
                $found_daily = true;
                $prev_rdg    = floatval($r_daily['opening_reading']);
                $current_rdg = floatval($r_daily['closing_reading']);
                $net_sale    = floatval($r_daily['dispensed_litres']);
            }
        }

        // 2. If no daily snapshot for that date, find previous day's closing reading
        if (!$found_daily) {
            $current_rdg = $live_rdg;
            $prev_rdg = $live_rdg;

            // Look in tbl_daily_nozzle_readings for latest previous closing
            $q_prev_daily = mysqli_query($connection, "SELECT closing_reading 
                                                       FROM tbl_daily_nozzle_readings 
                                                       WHERE nozzle_id = '$nid' AND (date < '$date_safe' OR (date = '$date_safe' AND shift_id < '$shift_id')) 
                                                       ORDER BY date DESC, shift_id DESC LIMIT 1");
            if ($q_prev_daily && $r_p_daily = mysqli_fetch_assoc($q_prev_daily)) {
                $prev_rdg = floatval($r_p_daily['closing_reading']);
            } else {
                // Fallback to latest previous dip log
                $q_prev_dip = mysqli_query($connection, "SELECT ml.reading 
                                                         FROM tbl_tank_dip_meter_logs ml
                                                         INNER JOIN tbl_tank_dip_logs dl ON ml.dip_log_id = dl.id
                                                         WHERE dl.tank_id = '$tank_id' AND ml.nozzle_id = '$nid' AND dl.deleted_at IS NULL
                                                         ORDER BY dl.date DESC, dl.id DESC LIMIT 1");
                if ($q_prev_dip && $r_prev_dip = mysqli_fetch_assoc($q_prev_dip)) {
                    $prev_rdg = floatval($r_prev_dip['reading']);
                }
            }

            $net_sale = max(0.00, $current_rdg - $prev_rdg);
        }

        $total_usage += $net_sale;

        $nozzles[] = [
            'id'              => $nid,
            'name'            => $row['name'],
            'prev_reading'    => $prev_rdg,
            'current_reading' => $current_rdg,
            'net_sale'        => $net_sale,
            'from_daily'      => $found_daily
        ];
    }
    mysqli_stmt_close($stmt_nozzles);
}

echo json_encode([
    'success'     => true,
    'meter_found' => true,
    'has_nozzles' => !empty($nozzles),
    'total_usage' => $total_usage,
    'nozzles'     => $nozzles
]);
?>
