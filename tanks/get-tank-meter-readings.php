<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
require '../include/config.php';

header('Content-Type: application/json');

$tank_id = isset($_REQUEST['tank_id']) ? intval($_REQUEST['tank_id']) : 0;
$date = isset($_REQUEST['date']) ? trim($_REQUEST['date']) : '';
$shift_id = isset($_REQUEST['shift_id']) ? intval($_REQUEST['shift_id']) : 0;

if ($tank_id <= 0 || empty($date) || $shift_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Tank ID, Date, and Shift are required parameters.']);
    exit;
}

// 1. Fetch all active nozzles attached to this tank with previous readings
$nozzles = [];
$nozzle_ids = [];
$stmt_nozzles = mysqli_prepare($connection, "SELECT id, name, start_reading FROM tbl_nozzles WHERE tank_id = ? AND status = 'Active' ORDER BY id ASC");
if ($stmt_nozzles) {
    mysqli_stmt_bind_param($stmt_nozzles, "i", $tank_id);
    mysqli_stmt_execute($stmt_nozzles);
    $res_nozzles = mysqli_stmt_get_result($stmt_nozzles);
    while ($row = mysqli_fetch_assoc($res_nozzles)) {
        $nid = intval($row['id']);
        
        // Find latest previous dip log reading for this nozzle
        $prev_rdg = floatval($row['start_reading']);
        $stmt_prev_m = mysqli_prepare($connection, "SELECT ml.reading 
            FROM tbl_tank_dip_meter_logs ml
            INNER JOIN tbl_tank_dip_logs dl ON ml.dip_log_id = dl.id
            WHERE dl.tank_id = ? AND ml.nozzle_id = ? AND dl.deleted_at IS NULL
            ORDER BY dl.date DESC, dl.id DESC LIMIT 1");
        if ($stmt_prev_m) {
            mysqli_stmt_bind_param($stmt_prev_m, "ii", $tank_id, $nid);
            mysqli_stmt_execute($stmt_prev_m);
            $res_prev_m = mysqli_stmt_get_result($stmt_prev_m);
            if ($r_prev_m = mysqli_fetch_assoc($res_prev_m)) {
                $prev_rdg = floatval($r_prev_m['reading']);
            }
            mysqli_stmt_close($stmt_prev_m);
        }

        $nozzles[$nid] = [
            'id' => $nid,
            'name' => $row['name'],
            'prev_reading' => $prev_rdg,
            'current_reading' => $prev_rdg,
            'net_sale' => 0.00
        ];
        $nozzle_ids[] = $nid;
    }
    mysqli_stmt_close($stmt_nozzles);
}

if (empty($nozzle_ids)) {
    echo json_encode([
        'success' => true,
        'meter_found' => false,
        'has_nozzles' => false,
        'message' => 'No active nozzles are attached to this tank.'
    ]);
    exit;
}

// 2. Check if a meter reading record exists in tbl_meter_readings for date & shift_id
$stmt_mr = mysqli_prepare($connection, "SELECT id FROM tbl_meter_readings WHERE date = ? AND shift_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
$meter_reading_id = 0;

if ($stmt_mr) {
    mysqli_stmt_bind_param($stmt_mr, "si", $date, $shift_id);
    mysqli_stmt_execute($stmt_mr);
    $res_mr = mysqli_stmt_get_result($stmt_mr);
    if ($row_mr = mysqli_fetch_assoc($res_mr)) {
        $meter_reading_id = intval($row_mr['id']);
    }
    mysqli_stmt_close($stmt_mr);
}

if ($meter_reading_id === 0) {
    echo json_encode([
        'success' => true,
        'meter_found' => false,
        'has_nozzles' => true,
        'message' => 'You don\'t add meter reading for date (' . htmlspecialchars($date) . ') and shift. Please add manually or complete meter readings.',
        'nozzles' => array_values($nozzles)
    ]);
    exit;
}

// 3. Fetch details for attached nozzles from tbl_meter_reading_details
$total_usage = 0.00;
$in_clause = implode(',', array_map('intval', $nozzle_ids));

$sql_details = "SELECT nozzle_id, last_reading, current_reading, net_sale 
                FROM tbl_meter_reading_details 
                WHERE meter_reading_id = ? AND nozzle_id IN ($in_clause)";

$stmt_det = mysqli_prepare($connection, $sql_details);
if ($stmt_det) {
    mysqli_stmt_bind_param($stmt_det, "i", $meter_reading_id);
    mysqli_stmt_execute($stmt_det);
    $res_det = mysqli_stmt_get_result($stmt_det);
    while ($row_det = mysqli_fetch_assoc($res_det)) {
        $nid = intval($row_det['nozzle_id']);
        if (isset($nozzles[$nid])) {
            $last_r = floatval($row_det['last_reading']);
            $curr_r = floatval($row_det['current_reading']);
            $net_s = max(0.00, $curr_r - $last_r);

            $nozzles[$nid]['prev_reading'] = $last_r;
            $nozzles[$nid]['current_reading'] = $curr_r;
            $nozzles[$nid]['net_sale'] = $net_s;

            $total_usage += $net_s;
        }
    }
    mysqli_stmt_close($stmt_det);
}

echo json_encode([
    'success' => true,
    'meter_found' => true,
    'has_nozzles' => true,
    'meter_reading_id' => $meter_reading_id,
    'total_usage' => $total_usage,
    'nozzles' => array_values($nozzles)
]);
?>
