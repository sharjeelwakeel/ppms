<?php
require_once 'session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
    exit;
}
require_once 'config.php';
require_once 'permissions.php';

header('Content-Type: application/json');

if (!has_permission('meter_readings', 'edit') && !has_permission('reports', 'show')) {
    echo json_encode(['status' => 'error', 'message' => 'Access denied: You do not have permission to return slips.']);
    exit;
}

if (!isset($_POST['slip_id']) || empty($_POST['slip_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Slip ID is required.']);
    exit;
}

$slipId = intval($_POST['slip_id']);
$status = isset($_POST['status']) ? intval($_POST['status']) : 1;

// Verify slip exists and is a Temporary Slip
$chk_query = mysqli_query($connection, "SELECT id, slip_no, slip_type, is_returned FROM tbl_meter_reading_credit_sales WHERE id = '$slipId' LIMIT 1");
if (!$chk_query || !($slip = mysqli_fetch_assoc($chk_query))) {
    echo json_encode(['status' => 'error', 'message' => 'Credit slip not found.']);
    exit;
}

if ($slip['slip_type'] !== 'Temporary Slip') {
    echo json_encode(['status' => 'error', 'message' => 'Only Temporary Slips can be marked as returned.']);
    exit;
}

if ($status === 1) {
    $sql = "UPDATE tbl_meter_reading_credit_sales SET is_returned = 1, returned_at = NOW() WHERE id = '$slipId'";
    $msg = "Temporary Slip #{$slip['slip_no']} marked as Returned / Received successfully.";
} else {
    $sql = "UPDATE tbl_meter_reading_credit_sales SET is_returned = 0, returned_at = NULL WHERE id = '$slipId'";
    $msg = "Temporary Slip #{$slip['slip_no']} reverted back to Pending.";
}

if (mysqli_query($connection, $sql)) {
    echo json_encode([
        'status'      => 'success',
        'slip_id'     => $slipId,
        'is_returned' => $status,
        'message'     => $msg
    ]);
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . mysqli_error($connection)
    ]);
}

mysqli_close($connection);
?>
