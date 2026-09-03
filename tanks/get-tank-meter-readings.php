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

if ($tank_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Tank ID is required.']);
    exit;
}

// Fetch all active nozzles attached to this tank directly from tbl_nozzles
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
        $current_rdg = floatval($row['start_reading']); // Always taken from tbl_nozzles
        $prev_rdg = $current_rdg; // Default to current if no previous dip log
        
        // Find latest previous dip log reading for this nozzle
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

        $net_sale = max(0.00, $current_rdg - $prev_rdg);
        $total_usage += $net_sale;

        $nozzles[] = [
            'id' => $nid,
            'name' => $row['name'],
            'prev_reading' => $prev_rdg,
            'current_reading' => $current_rdg,
            'net_sale' => $net_sale
        ];
    }
    mysqli_stmt_close($stmt_nozzles);
}

echo json_encode([
    'success' => true,
    'meter_found' => true,
    'has_nozzles' => !empty($nozzles),
    'total_usage' => $total_usage,
    'nozzles' => $nozzles
]);
?>
