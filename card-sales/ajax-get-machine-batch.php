<?php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/card_helper.php';

if (!has_permission('card_sales', 'show') && !has_permission('card_sales', 'add') && !has_permission('card_sales', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
    exit;
}

$saleDate = trim($_GET['sale_date'] ?? date('Y-m-d'));
$shiftId  = intval($_GET['shift_id'] ?? 0);

if (empty($saleDate) || strtotime($saleDate) === false) {
    $saleDate = date('Y-m-d');
}

$batches = get_shift_machine_batches($connection, $saleDate, $shiftId);

echo json_encode([
    'status'    => 'success',
    'sale_date' => $saleDate,
    'shift_id'  => $shiftId,
    'batches'   => $batches
]);
