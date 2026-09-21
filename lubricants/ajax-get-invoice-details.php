<?php
require_once '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
require_once '../include/config.php';
require_once '../include/permissions.php';

header('Content-Type: application/json');

$invoice_id = intval($_GET['invoice_id'] ?? 0);
$invoice_no = mysqli_real_escape_string($connection, trim($_GET['invoice_no'] ?? ''));

if ($invoice_id <= 0 && empty($invoice_no)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid invoice identifier']);
    exit;
}

$where_inv = ($invoice_id > 0) ? "inv.id = '$invoice_id'" : "inv.invoice_no = '$invoice_no'";
$q_inv = mysqli_query($connection, "
    SELECT inv.*, cm.name AS card_machine_name, b.name AS bank_name, b.account_number AS bank_account
    FROM tbl_lubricant_sale_invoices inv 
    LEFT JOIN tbl_card_machines cm ON inv.card_machine_id = cm.id
    LEFT JOIN tbl_banks b ON inv.bank_id = b.id
    WHERE $where_inv AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00') 
    LIMIT 1
");

if (!$q_inv || mysqli_num_rows($q_inv) === 0) {
    // Check if there are legacy sales with this invoice_no or id in tbl_lubricant_sales
    $fallback_where = ($invoice_id > 0) ? "invoice_id = '$invoice_id' OR id = '$invoice_id'" : "invoice_no = '$invoice_no'";
    $q_sal = mysqli_query($connection, "
        SELECT sal.*, p.name AS product_name 
        FROM tbl_lubricant_sales sal 
        LEFT JOIN tbl_lubricant_products p ON sal.product_id = p.id 
        WHERE ($fallback_where) AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')
    ");

    if ($q_sal && mysqli_num_rows($q_sal) > 0) {
        $items = [];
        $tot_qty = 0;
        $tot_amt = 0;
        $first_row = null;

        while ($r = mysqli_fetch_assoc($q_sal)) {
            if (!$first_row) $first_row = $r;
            $tot_qty += intval($r['quantity']);
            $tot_amt += floatval($r['amount']);
            $items[] = [
                'id'           => intval($r['id']),
                'product_id'   => intval($r['product_id']),
                'product_name' => $r['product_name'] ?? 'Product #' . $r['product_id'],
                'quantity'     => intval($r['quantity']),
                'rate'         => floatval($r['rate']),
                'amount'       => floatval($r['amount'])
            ];
        }

        echo json_encode([
            'status' => 'success',
            'invoice' => [
                'id'             => intval($first_row['invoice_id'] ?? $first_row['id']),
                'invoice_no'     => !empty($first_row['invoice_no']) ? $first_row['invoice_no'] : 'SALE-#' . $first_row['id'],
                'date'           => $first_row['date'],
                'payment_type'   => $first_row['payment_type'],
                'details'        => $first_row['details'] ?? '',
                'total_items'    => count($items),
                'total_quantity' => $tot_qty,
                'total_amount'   => $tot_amt
            ],
            'items' => $items
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invoice not found']);
    exit;
}

$invoice = mysqli_fetch_assoc($q_inv);
$inv_id = intval($invoice['id']);

$q_items = mysqli_query($connection, "
    SELECT sal.*, p.name AS product_name 
    FROM tbl_lubricant_sales sal 
    LEFT JOIN tbl_lubricant_products p ON sal.product_id = p.id 
    WHERE sal.invoice_id = '$inv_id' 
      AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00') 
    ORDER BY sal.id ASC
");

$items = [];
if ($q_items) {
    while ($r = mysqli_fetch_assoc($q_items)) {
        $items[] = [
            'id'           => intval($r['id']),
            'product_id'   => intval($r['product_id']),
            'product_name' => $r['product_name'] ?? 'Product #' . $r['product_id'],
            'quantity'     => intval($r['quantity']),
            'rate'         => floatval($r['rate']),
            'amount'       => floatval($r['amount'])
        ];
    }
}

echo json_encode([
    'status'  => 'success',
    'invoice' => [
        'id'                => $inv_id,
        'invoice_no'        => $invoice['invoice_no'],
        'date'              => $invoice['date'],
        'payment_type'      => $invoice['payment_type'],
        'details'           => $invoice['details'] ?? '',
        'total_items'       => intval($invoice['total_items'] ?? count($items)),
        'total_quantity'    => intval($invoice['total_quantity']),
        'total_amount'      => floatval($invoice['total_amount']),
        'card_machine_id'   => intval($invoice['card_machine_id'] ?? 0),
        'card_machine_name' => $invoice['card_machine_name'] ?? null,
        'bank_id'           => intval($invoice['bank_id'] ?? 0),
        'bank_name'         => $invoice['bank_name'] ?? null,
        'bank_account'      => $invoice['bank_account'] ?? null
    ],
    'items'   => $items
]);
