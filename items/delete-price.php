<?php
require_once '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/price_helper.php';

header('Content-Type: application/json');

// RBAC check: allow user with items delete or edit permission
if (!has_permission('items', 'delete') && !has_permission('items', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied: unauthorized operation.']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid price ID provided.']);
    exit;
}

if (soft_delete_price($connection, $id)) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Price record soft-deleted successfully.'
    ]);
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Failed to soft delete price record. It may have already been deleted.'
    ]);
}
