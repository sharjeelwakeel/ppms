<?php
require 'session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require 'config.php';
require 'permissions.php';

header('Content-Type: application/json');

if (!has_permission('card_sales', 'delete')) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized operation.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid settlement ID.']);
        exit;
    }

    $sql = "UPDATE tbl_card_sale_settlements SET deleted_at = NOW() WHERE id = '$id'";
    if (mysqli_query($connection, $sql)) {
        echo json_encode(['status' => 'success', 'message' => 'Settlement record deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($connection)]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
}

mysqli_close($connection);
?>
