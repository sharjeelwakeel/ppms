<?php
require '../include/session.php';
header('Content-Type: application/json');

if (!userloggedin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require '../include/config.php';
require '../include/permissions.php';

if (!has_permission('cash_sales', 'delete')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

$res = mysqli_query($connection, "UPDATE tbl_meter_reading_cash_sales SET deleted_at = NOW() WHERE id = '$id'");
if ($res && mysqli_affected_rows($connection) > 0) {
    echo json_encode(['success' => true, 'message' => 'Cash sale transaction deleted successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Record not found or already deleted.']);
}
