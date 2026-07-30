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
$exclude_id = isset($_REQUEST['exclude_id']) ? intval($_REQUEST['exclude_id']) : 0;

if ($tank_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Tank ID is required.']);
    exit;
}

// Fetch tank default storage capacity
$storage_capacity = 0.00;
$stmt_tank = mysqli_prepare($connection, "SELECT storage_capacity FROM tbl_tanks WHERE id = ? LIMIT 1");
if ($stmt_tank) {
    mysqli_stmt_bind_param($stmt_tank, "i", $tank_id);
    mysqli_stmt_execute($stmt_tank);
    $res_tank = mysqli_stmt_get_result($stmt_tank);
    if ($row_tank = mysqli_fetch_assoc($res_tank)) {
        $storage_capacity = floatval($row_tank['storage_capacity']);
    }
    mysqli_stmt_close($stmt_tank);
}

// Fetch latest active previous dip log
$sql_prev = "SELECT book_balance, per_dip_gain_loss, accumulative_pmg, balance 
             FROM tbl_tank_dip_logs 
             WHERE tank_id = ? AND deleted_at IS NULL ";
if ($exclude_id > 0) {
    $sql_prev .= " AND id != " . intval($exclude_id) . " ";
}
$sql_prev .= " ORDER BY date DESC, id DESC LIMIT 1";

$stmt_prev = mysqli_prepare($connection, $sql_prev);
if ($stmt_prev) {
    mysqli_stmt_bind_param($stmt_prev, "i", $tank_id);
    mysqli_stmt_execute($stmt_prev);
    $res_prev = mysqli_stmt_get_result($stmt_prev);
    if ($row_prev = mysqli_fetch_assoc($res_prev)) {
        echo json_encode([
            'success' => true,
            'has_prev' => true,
            'prev_book_balance' => floatval($row_prev['book_balance']),
            'prev_per_dip_gain_loss' => floatval($row_prev['per_dip_gain_loss']),
            'prev_accumulative_pmg' => floatval($row_prev['accumulative_pmg']),
            'prev_balance' => floatval($row_prev['balance']),
            'storage_capacity' => $storage_capacity
        ]);
        mysqli_stmt_close($stmt_prev);
        exit;
    }
    mysqli_stmt_close($stmt_prev);
}

echo json_encode([
    'success' => true,
    'has_prev' => false,
    'prev_book_balance' => 0.00,
    'prev_per_dip_gain_loss' => 0.00,
    'prev_accumulative_pmg' => 0.00,
    'prev_balance' => 0.00,
    'storage_capacity' => $storage_capacity
]);
?>
