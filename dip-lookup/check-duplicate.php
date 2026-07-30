<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require '../include/config.php';

header('Content-Type: application/json');

if (isset($_REQUEST['dip_mm'])) {
    $dip_mm = mysqli_real_escape_string($connection, $_REQUEST['dip_mm']);
    $exclude_id = isset($_REQUEST['exclude_id']) ? mysqli_real_escape_string($connection, $_REQUEST['exclude_id']) : null;

    $sql = "SELECT id, dip_mm, dip_litre FROM tbl_dip_lookup WHERE dip_mm = '$dip_mm' AND deleted_at IS NULL";
    if ($exclude_id) {
        $sql .= " AND id != '$exclude_id'";
    }
    $sql .= " LIMIT 1";

    $result = mysqli_query($connection, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        echo json_encode([
            'exists' => true,
            'id' => $row['id'],
            'dip_mm' => $row['dip_mm'],
            'dip_litre' => $row['dip_litre']
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
} else {
    echo json_encode(['error' => 'Missing parameter']);
}
?>
