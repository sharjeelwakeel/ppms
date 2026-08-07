<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
require '../include/config.php';

header('Content-Type: application/json');

$dip_mm = isset($_REQUEST['dip_mm']) ? trim($_REQUEST['dip_mm']) : '';
$capacity = isset($_REQUEST['capacity']) ? trim($_REQUEST['capacity']) : (isset($_REQUEST['tank_capacity']) ? trim($_REQUEST['tank_capacity']) : '23500');

if ($dip_mm === '' || !is_numeric($dip_mm)) {
    echo json_encode(['success' => false, 'message' => 'Invalid dip measurement value provided.']);
    exit;
}

$dip_mm_val = floatval($dip_mm);
$capacity_val = floatval($capacity);

// High-performance indexed lookup query matching dip_mm AND tank_capacity
$stmt = mysqli_prepare($connection, "SELECT dip_litre FROM tbl_dip_lookup WHERE dip_mm = ? AND tank_capacity = ? AND deleted_at IS NULL LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "dd", $dip_mm_val, $capacity_val);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode([
            'success' => true,
            'dip_mm' => $dip_mm_val,
            'capacity' => $capacity_val,
            'balance' => floatval($row['dip_litre'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Dip value (' . $dip_mm_val . ' mm) for ' . number_format($capacity_val) . ' Ltrs tank was not found in Dip Lookup table.'
        ]);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed.']);
}
?>
