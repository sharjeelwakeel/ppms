<?php
require_once '../include/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/price_helper.php';

header('Content-Type: application/json');

$table_name = !empty($_GET['table_name']) ? trim($_GET['table_name']) : 'tbl_items';
$table_id   = intval($_GET['table_id'] ?? 0);

if ($table_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid table ID']);
    exit;
}

$history = get_price_history($connection, $table_name, $table_id);
echo json_encode([
    'status' => 'success',
    'table_name' => $table_name,
    'table_id' => $table_id,
    'data' => $history
]);
