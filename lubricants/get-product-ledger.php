<?php
require '../include/session.php';
if (!userloggedin()) {
    echo '<tr><td colspan="6" class="text-center text-danger">Session expired. Please log in again.</td></tr>';
    exit;
}
require '../include/config.php';

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    echo '<tr><td colspan="6" class="text-center text-danger">Invalid Product ID.</td></tr>';
    exit;
}

// Fetch Purchases
$purchases_query = mysqli_query($connection, "
    SELECT id, quantity, purchase_price AS rate, (quantity * purchase_price) AS amount, date, 'Purchase' AS type, CONCAT('Payment Status: ', UPPER(payment_status)) AS details 
    FROM tbl_lubricant_purchases 
    WHERE product_id = $product_id
");
$history = [];
if ($purchases_query) {
    while ($row = mysqli_fetch_assoc($purchases_query)) {
        $row['sort_date'] = $row['date'] . '_pur_' . $row['id'];
        $history[] = $row;
    }
}

// Fetch Sales
$sales_query = mysqli_query($connection, "
    SELECT id, quantity, rate, amount, date, 'Sale' AS type, CONCAT(payment_type, ' Sale', IF(details != '', CONCAT(' (', details, ')'), '')) AS details 
    FROM tbl_lubricant_sales 
    WHERE product_id = $product_id
");
if ($sales_query) {
    while ($row = mysqli_fetch_assoc($sales_query)) {
        $row['sort_date'] = $row['date'] . '_sal_' . $row['id'];
        $history[] = $row;
    }
}

// Sort by date descending
usort($history, function($a, $b) {
    return strcmp($b['sort_date'], $a['sort_date']);
});

if (count($history) == 0) {
    echo '<tr><td colspan="6" class="text-center text-muted">No transaction history found for this product.</td></tr>';
    exit;
}

$cn = 1;
foreach ($history as $row) {
    $date = date("d-m-Y", strtotime($row['date']));
    $typeBadge = ($row['type'] == 'Purchase') ? 'badge-success' : 'badge-info';
    $qtyIn = ($row['type'] == 'Purchase') ? number_format($row['quantity'], 2) : '—';
    $qtyOut = ($row['type'] == 'Sale') ? number_format($row['quantity'], 2) : '—';
    
    echo '
        <tr>
            <td class="text-center text-muted font-weight-bold">' . $cn++ . '</td>
            <td>' . $date . '</td>
            <td class="text-center"><span class="badge ' . $typeBadge . ' px-2 py-1" style="font-size:11px;">' . $row['type'] . '</span></td>
            <td class="text-right text-success font-weight-bold">' . $qtyIn . '</td>
            <td class="text-right text-danger font-weight-bold">' . $qtyOut . '</td>
            <td class="text-right">' . number_format($row['rate'], 2) . '</td>
            <td class="text-right font-weight-bold" style="background:#f8f9fa;">' . number_format($row['amount'], 2) . '</td>
            <td class="text-muted" style="font-size:11.5px;">' . htmlspecialchars($row['details']) . '</td>
        </tr>
    ';
}
?>
