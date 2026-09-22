<?php
require_once '../include/session.php';
header('Content-Type: application/json');

if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/price_helper.php';

// Verify access to items/products module
if (!has_permission('items', 'show') && !has_permission('items', 'add') && !has_permission('items', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {
    case 'get_customer_vehicles':
        $customer_id = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid customer ID.']);
            exit;
        }

        // Fetch customer policy (other_rate: Cash or Credit)
        $q_cust = mysqli_query($connection, "SELECT id, name, other_rate, status FROM tbl_customers WHERE id = '$customer_id' AND deleted_at IS NULL LIMIT 1");
        $cust = mysqli_fetch_assoc($q_cust);
        if (!$cust) {
            echo json_encode(['status' => 'error', 'message' => 'Customer not found.']);
            exit;
        }

        // Fetch active vehicles registered to this customer
        $vehicles = [];
        $q_veh = mysqli_query($connection, "SELECT id, reg_number, vehicle_name, fuel_limit FROM tbl_customer_vehicles WHERE customer_id = '$customer_id' AND status = 'Active' AND deleted_at IS NULL ORDER BY reg_number ASC");
        if ($q_veh) {
            while ($v = mysqli_fetch_assoc($q_veh)) {
                $vehicles[] = [
                    'id'           => intval($v['id']),
                    'reg_number'   => $v['reg_number'],
                    'vehicle_name' => $v['vehicle_name'] ?? '',
                    'fuel_limit'   => floatval($v['fuel_limit'])
                ];
            }
        }

        echo json_encode([
            'status'     => 'success',
            'customer'   => [
                'id'         => intval($cust['id']),
                'name'       => $cust['name'],
                'other_rate' => $cust['other_rate'] ?? 'Credit'
            ],
            'vehicles'   => $vehicles
        ]);
        exit;

    case 'get_product_price_for_date':
        $product_id = intval($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
        $slip_date  = trim($_GET['slip_date'] ?? $_POST['slip_date'] ?? date('Y-m-d'));
        $policy     = trim($_GET['policy'] ?? $_POST['policy'] ?? 'Credit');

        if ($product_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid product ID.']);
            exit;
        }

        // Lookup price record from tbl_prices for that date
        $price_row = get_price_for_date($connection, 'tbl_lubricant_products', $product_id, $slip_date);
        $cash_rate = 0.00;
        $cred_rate = 0.00;

        if ($price_row) {
            $cash_rate = floatval($price_row['cash_rate']);
            $cred_rate = floatval($price_row['credit_rate']);
        } else {
            // Fallback to tbl_lubricant_products active record
            $qp = mysqli_query($connection, "SELECT price, cash_rate, credit_rate FROM tbl_lubricant_products WHERE id = '$product_id' LIMIT 1");
            if ($qp && $pr = mysqli_fetch_assoc($qp)) {
                $cash_rate = floatval($pr['cash_rate'] ?? $pr['price'] ?? 0);
                $cred_rate = floatval($pr['credit_rate'] ?? $pr['price'] ?? 0);
            }
        }

        $applicable = ($policy === 'Cash') ? $cash_rate : ($cred_rate > 0 ? $cred_rate : $cash_rate);

        echo json_encode([
            'status'          => 'success',
            'product_id'      => $product_id,
            'slip_date'       => $slip_date,
            'cash_rate'       => $cash_rate,
            'credit_rate'     => $cred_rate,
            'applicable_rate' => $applicable
        ]);
        exit;

    case 'get_batch_product_prices_for_date':
        $raw_ids = $_GET['product_ids'] ?? $_POST['product_ids'] ?? [];
        if (!is_array($raw_ids)) {
            $raw_ids = explode(',', strval($raw_ids));
        }
        $slip_date = trim($_GET['slip_date'] ?? $_POST['slip_date'] ?? date('Y-m-d'));
        $policy    = trim($_GET['policy'] ?? $_POST['policy'] ?? 'Credit');

        $rates = [];
        foreach ($raw_ids as $pid) {
            $pid = intval($pid);
            if ($pid <= 0) continue;

            $price_row = get_price_for_date($connection, 'tbl_lubricant_products', $pid, $slip_date);
            $cash_rate = 0.00;
            $cred_rate = 0.00;

            if ($price_row) {
                $cash_rate = floatval($price_row['cash_rate']);
                $cred_rate = floatval($price_row['credit_rate']);
            } else {
                $qp = mysqli_query($connection, "SELECT price, cash_rate, credit_rate FROM tbl_lubricant_products WHERE id = '$pid' LIMIT 1");
                if ($qp && $pr = mysqli_fetch_assoc($qp)) {
                    $cash_rate = floatval($pr['cash_rate'] ?? $pr['price'] ?? 0);
                    $cred_rate = floatval($pr['credit_rate'] ?? $pr['price'] ?? 0);
                }
            }

            $applicable = ($policy === 'Cash') ? $cash_rate : ($cred_rate > 0 ? $cred_rate : $cash_rate);
            $rates[$pid] = [
                'applicable_rate' => $applicable,
                'cash_rate'       => $cash_rate,
                'credit_rate'     => $cred_rate
            ];
        }

        echo json_encode([
            'status'    => 'success',
            'slip_date' => $slip_date,
            'policy'    => $policy,
            'rates'     => $rates
        ]);
        exit;

    case 'find_balance_slips':
        $customer_id = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        $search_slip = mysqli_real_escape_string($connection, trim($_GET['slip_no'] ?? $_POST['slip_no'] ?? ''));

        if ($customer_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Please select a customer first.']);
            exit;
        }

        $where = "inv.customer_id = '$customer_id' 
                  AND inv.slip_type = 'Permanent Slip' 
                  AND sal.balance_quantity > 0 
                  AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
                  AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')";

        if (!empty($search_slip)) {
            $where .= " AND (inv.slip_no LIKE '%$search_slip%' OR inv.invoice_no LIKE '%$search_slip%')";
        }

        $sql = "SELECT inv.id AS invoice_id, inv.invoice_no, inv.slip_no, inv.slip_date, inv.date AS sale_date, inv.vehicle_number,
                       sal.id AS sale_id, sal.product_id, sal.quantity, sal.issue_quantity, sal.balance_quantity,
                       sal.rate AS original_rate, p.name AS product_name, COALESCE(c.name, '') AS category_name,
                       COALESCE((
                           SELECT SUM(bsal.quantity)
                           FROM tbl_lubricant_sales bsal
                           JOIN tbl_lubricant_sale_invoices binv ON (bsal.invoice_id = binv.id)
                           WHERE binv.slip_type = 'Balanced Slip'
                             AND (binv.ref_slip_no = inv.slip_no OR binv.ref_slip_no = inv.invoice_no)
                             AND bsal.product_id = sal.product_id
                             AND (binv.deleted_at IS NULL OR binv.deleted_at = '0000-00-00 00:00:00')
                             AND (bsal.deleted_at IS NULL OR bsal.deleted_at = '0000-00-00 00:00:00')
                       ), 0) AS claimed_quantity
                FROM tbl_lubricant_sale_invoices inv
                JOIN tbl_lubricant_sales sal ON inv.id = sal.invoice_id
                JOIN tbl_lubricant_products p ON sal.product_id = p.id
                LEFT JOIN tbl_product_categories c ON p.category_id = c.id
                WHERE $where
                ORDER BY inv.slip_date DESC, inv.id DESC";

        $res = mysqli_query($connection, $sql);
        $slips = [];

        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $remBalance = intval($r['balance_quantity']) - intval($r['claimed_quantity']);
                if ($remBalance <= 0) {
                    continue; // Skip lines whose balance has already been claimed & returned
                }
                $invId = intval($r['invoice_id']);
                if (!isset($slips[$invId])) {
                    $slips[$invId] = [
                        'invoice_id'     => $invId,
                        'invoice_no'     => $r['invoice_no'],
                        'slip_no'        => $r['slip_no'] ?: $r['invoice_no'],
                        'slip_date'      => $r['slip_date'] ?: $r['sale_date'],
                        'vehicle_number' => $r['vehicle_number'] ?: '—',
                        'items'          => []
                    ];
                }
                $catBadge = !empty($r['category_name']) ? ' [' . $r['category_name'] . ']' : '';
                $slips[$invId]['items'][] = [
                    'sale_id'          => intval($r['sale_id']),
                    'product_id'       => intval($r['product_id']),
                    'product_name'     => $r['product_name'] . $catBadge,
                    'voucher_quantity' => intval($r['quantity']),
                    'issue_quantity'   => intval($r['issue_quantity']),
                    'balance_quantity' => $remBalance,
                    'original_rate'    => floatval($r['original_rate']) // Historical rate locked
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'slips'  => array_values($slips)
        ]);
        exit;

    case 'find_temp_slips':
        $customer_id = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        $search_slip = mysqli_real_escape_string($connection, trim($_GET['slip_no'] ?? $_POST['slip_no'] ?? ''));

        if ($customer_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Please select a customer first.']);
            exit;
        }

        $where = "inv.customer_id = '$customer_id' 
                  AND inv.slip_type = 'Temporary Slip' 
                  AND (inv.is_returned = 0 OR inv.is_returned IS NULL) 
                  AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
                  AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')";

        if (!empty($search_slip)) {
            $where .= " AND (inv.slip_no LIKE '%$search_slip%' OR inv.invoice_no LIKE '%$search_slip%')";
        }

        $sql = "SELECT inv.id AS invoice_id, inv.invoice_no, inv.slip_no, inv.slip_date, inv.date AS loan_date, inv.vehicle_number, inv.total_amount,
                       sal.id AS sale_id, sal.product_id, sal.quantity AS loan_quantity, sal.rate AS temp_rate, sal.amount AS item_amount,
                       p.name AS product_name, COALESCE(c.name, '') AS category_name
                FROM tbl_lubricant_sale_invoices inv
                JOIN tbl_lubricant_sales sal ON inv.id = sal.invoice_id
                JOIN tbl_lubricant_products p ON sal.product_id = p.id
                LEFT JOIN tbl_product_categories c ON p.category_id = c.id
                WHERE $where
                ORDER BY inv.slip_date ASC, inv.id ASC";

        $res = mysqli_query($connection, $sql);
        $slips = [];

        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $invId = intval($r['invoice_id']);
                if (!isset($slips[$invId])) {
                    $slips[$invId] = [
                        'invoice_id'     => $invId,
                        'invoice_no'     => $r['invoice_no'],
                        'slip_no'        => $r['slip_no'] ?: $r['invoice_no'],
                        'loan_date'      => $r['slip_date'] ?: $r['loan_date'],
                        'vehicle_number' => $r['vehicle_number'] ?: '—',
                        'total_amount'   => floatval($r['total_amount']),
                        'items'          => []
                    ];
                }
                $catBadge = !empty($r['category_name']) ? ' [' . $r['category_name'] . ']' : '';
                $slips[$invId]['items'][] = [
                    'sale_id'       => intval($r['sale_id']),
                    'product_id'    => intval($r['product_id']),
                    'product_name'  => $r['product_name'] . $catBadge,
                    'loan_quantity' => intval($r['loan_quantity']),
                    'temp_rate'     => floatval($r['temp_rate']), // Historical rate locked
                    'item_amount'   => floatval($r['item_amount'])
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'slips'  => array_values($slips)
        ]);
        exit;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action requested.']);
        exit;
}
