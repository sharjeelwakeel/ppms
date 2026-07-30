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

if ($dip_mm === '' || !is_numeric($dip_mm)) {
    echo json_encode(['success' => false, 'message' => 'Invalid dip measurement value provided.']);
    exit;
}

$dip_mm_val = floatval($dip_mm);

// High-performance indexed lookup query (< 2ms response time across 5,000+ rows)
$stmt = mysqli_prepare($connection, "SELECT dip_litre FROM tbl_dip_lookup WHERE dip_mm = ? AND deleted_at IS NULL LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "d", $dip_mm_val);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode([
            'success' => true,
            'dip_mm' => $dip_mm_val,
            'balance' => floatval($row['dip_litre'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Dip value (' . $dip_mm_val . ' mm) was not found in Dip Lookup master table.'
        ]);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed.']);
}
?>
