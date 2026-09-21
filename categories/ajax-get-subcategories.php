<?php
if (!headers_sent()) {
    header('Content-Type: application/json');
}

require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../include/config.php';

$categoryId = intval($_GET['category_id'] ?? 0);

if ($categoryId <= 0) {
    echo json_encode(['status' => 'success', 'subcategories' => []]);
    exit;
}

$query = "SELECT id, name FROM tbl_product_subcategories 
          WHERE category_id = '$categoryId' 
            AND status = 'Active' 
            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
          ORDER BY name ASC";

$res = mysqli_query($connection, $query);
$subs = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $subs[] = [
            'id'   => intval($row['id']),
            'name' => $row['name']
        ];
    }
}

echo json_encode([
    'status'        => 'success',
    'category_id'   => $categoryId,
    'subcategories' => $subs
]);
exit;
?>
