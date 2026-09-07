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

// Verify access to credit sales module
if (!has_permission('credit_sales', 'view') && !has_permission('credit_sales', 'add') && !has_permission('meter_readings', 'view')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {
    case 'get_price_for_date':
        $item_id     = intval($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
        $slip_date   = trim($_GET['slip_date'] ?? $_POST['slip_date'] ?? date('Y-m-d'));
        $policy      = trim($_GET['policy'] ?? $_POST['policy'] ?? 'Credit');

        if ($item_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid item ID.']);
            exit;
        }

        // Lookup price record from tbl_prices
        $price_row = get_price_for_date($connection, 'tbl_items', $item_id, $slip_date);
        $cash_rate = 0.00;
        $cred_rate = 0.00;

        if ($price_row) {
            $cash_rate = floatval($price_row['cash_rate']);
            $cred_rate = floatval($price_row['credit_rate']);
        } else {
            // Fallback to tbl_items active record
            $qi = mysqli_query($connection, "SELECT cash_rate, credit_rate FROM tbl_items WHERE id = '$item_id' LIMIT 1");
            if ($qi && $ir = mysqli_fetch_assoc($qi)) {
                $cash_rate = floatval($ir['cash_rate']);
                $cred_rate = floatval($ir['credit_rate']);
            }
        }

        $applicable = ($policy === 'Cash') ? $cash_rate : ($cred_rate > 0 ? $cred_rate : $cash_rate);

        echo json_encode([
            'status'          => 'success',
            'item_id'         => $item_id,
            'slip_date'       => $slip_date,
            'cash_rate'       => $cash_rate,
            'credit_rate'     => $cred_rate,
            'applicable_rate' => $applicable
        ]);
        exit;

    case 'find_temp_slip':
        $slip_no   = mysqli_real_escape_string($connection, trim($_GET['slip_no'] ?? $_POST['slip_no'] ?? ''));
        $slip_date = mysqli_real_escape_string($connection, trim($_GET['slip_date'] ?? $_POST['slip_date'] ?? ''));
        $cust_id   = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

        if (empty($slip_no)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a Temporary Slip No to search.']);
            exit;
        }

        $where = "cs.slip_type = 'Temporary Slip' 
                  AND (cs.is_returned = 0 OR cs.is_returned IS NULL) 
                  AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                  AND cs.slip_no = '$slip_no'";

        if (!empty($slip_date)) {
            $where .= " AND cs.slip_date = '$slip_date'";
        }
        if ($cust_id > 0) {
            $where .= " AND cs.account_number = '$cust_id'";
        }

        $sql = "SELECT cs.id, cs.slip_no, cs.slip_date, cs.quantity, cs.rate, cs.amount, 
                       cs.vehicle_number, cs.account_number,
                       c.name AS customer_name, n.item_id, i.name AS item_name
                FROM tbl_meter_reading_credit_sales cs
                LEFT JOIN tbl_customers c ON (cs.account_number = c.id)
                LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
                LEFT JOIN tbl_items i ON (n.item_id = i.id)
                WHERE $where
                ORDER BY cs.id DESC LIMIT 1";

        $res = mysqli_query($connection, $sql);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $qty  = floatval($row['quantity']);
            $rate = floatval($row['rate']);

            // Double check historical price for that date if stored rate was 0
            if ($rate <= 0 && intval($row['item_id']) > 0) {
                $pr = get_price_for_date($connection, 'tbl_items', intval($row['item_id']), $row['slip_date']);
                if ($pr) {
                    $rate = floatval($pr['credit_rate']) > 0 ? floatval($pr['credit_rate']) : floatval($pr['cash_rate']);
                }
            }

            echo json_encode([
                'status'         => 'success',
                'found'          => true,
                'temp_slip_id'   => intval($row['id']),
                'slip_no'        => $row['slip_no'],
                'slip_date'      => $row['slip_date'],
                'quantity'       => $qty,
                'rate'           => $rate,
                'value'          => round($qty * $rate, 2),
                'vehicle_number' => $row['vehicle_number'],
                'customer_name'  => $row['customer_name'] ?? 'Account #' . $row['account_number'],
                'item_name'      => $row['item_name'] ?? 'Fuel'
            ]);
        } else {
            echo json_encode([
                'status'  => 'not_found',
                'message' => 'No active, unsettled Temporary Slip found matching Slip #' . $slip_no . '.'
            ]);
        }
        exit;

    case 'list_unsettled_temp_slips':
        $cust_id = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        $where = "cs.slip_type = 'Temporary Slip' 
                  AND (cs.is_returned = 0 OR cs.is_returned IS NULL) 
                  AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')";
        if ($cust_id > 0) {
            $where .= " AND cs.account_number = '$cust_id'";
        }

        $sql = "SELECT cs.id, cs.slip_no, cs.slip_date, cs.quantity, cs.rate, cs.amount, 
                       cs.vehicle_number, cs.account_number,
                       c.name AS customer_name, i.name AS item_name
                FROM tbl_meter_reading_credit_sales cs
                LEFT JOIN tbl_customers c ON (cs.account_number = c.id)
                LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
                LEFT JOIN tbl_items i ON (n.item_id = i.id)
                WHERE $where
                ORDER BY cs.slip_date DESC, cs.id DESC LIMIT 20";

        $res = mysqli_query($connection, $sql);
        $slips = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $qty = floatval($r['quantity']);
                $rate = floatval($r['rate']);
                $r['value'] = round($qty * $rate, 2);
                $slips[] = $r;
            }
        }
        echo json_encode(['status' => 'success', 'slips' => $slips]);
        exit;

    case 'find_balance_slip':
        $slip_no      = mysqli_real_escape_string($connection, trim($_GET['slip_no'] ?? $_POST['slip_no'] ?? ''));
        $slip_date    = mysqli_real_escape_string($connection, trim($_GET['slip_date'] ?? $_POST['slip_date'] ?? ''));
        $cust_id      = intval($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
        $current_rate = floatval($_GET['current_rate'] ?? $_POST['current_rate'] ?? 0);

        if (empty($slip_no)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a Permanent Slip No to claim balance.']);
            exit;
        }

        $where = "cs.slip_type = 'Permanent Slip' 
                  AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                  AND cs.slip_no = '$slip_no'";

        if (!empty($slip_date)) {
            $where .= " AND cs.slip_date = '$slip_date'";
        }
        if ($cust_id > 0) {
            $where .= " AND cs.account_number = '$cust_id'";
        }

        $sql = "SELECT cs.id, cs.slip_no, cs.slip_date, cs.rate, cs.quantity, cs.issue_quantity,
                       cs.balance_1, cs.balance_2, cs.vehicle_number, cs.account_number,
                       c.name AS customer_name, i.name AS item_name
                FROM tbl_meter_reading_credit_sales cs
                LEFT JOIN tbl_customers c ON (cs.account_number = c.id)
                LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
                LEFT JOIN tbl_items i ON (n.item_id = i.id)
                WHERE $where
                ORDER BY cs.id DESC LIMIT 1";

        $res = mysqli_query($connection, $sql);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $bal1 = floatval($row['balance_1']);
            $bal2 = floatval($row['balance_2']);
            $total_balance = $bal1 + $bal2;
            $orig_rate = floatval($row['rate']);

            if ($total_balance <= 0) {
                // If balances are 0, check if issue_qty > qty
                $calc_bal = max(0, floatval($row['issue_quantity']) - floatval($row['quantity']));
                if ($calc_bal > 0) {
                    $total_balance = $calc_bal;
                    $bal1 = $calc_bal;
                }
            }

            if ($total_balance <= 0) {
                echo json_encode([
                    'status'  => 'not_found',
                    'message' => 'Slip #' . $slip_no . ' has no uncollected fuel balance (Balance is 0).'
                ]);
                exit;
            }

            // Total prepaid monetary credit on that slip
            $prepaid_money = round($total_balance * $orig_rate, 2);

            // Rate to adjust against
            $active_price = ($current_rate > 0) ? $current_rate : $orig_rate;

            // Adjusted litres based on current petrol price
            $adjusted_litres = ($active_price > 0) ? round($prepaid_money / $active_price, 2) : $total_balance;

            echo json_encode([
                'status'           => 'success',
                'found'            => true,
                'slip_id'          => intval($row['id']),
                'slip_no'          => $row['slip_no'],
                'slip_date'        => $row['slip_date'],
                'balance_1'        => $bal1,
                'balance_2'        => $bal2,
                'total_balance'    => $total_balance,
                'original_rate'    => $orig_rate,
                'prepaid_money'    => $prepaid_money,
                'current_rate'     => $active_price,
                'adjusted_litres'  => $adjusted_litres,
                'vehicle_number'   => $row['vehicle_number'],
                'customer_name'    => $row['customer_name'] ?? 'Account #' . $row['account_number'],
                'item_name'        => $row['item_name'] ?? 'Fuel'
            ]);
        } else {
            echo json_encode([
                'status'  => 'not_found',
                'message' => 'No Permanent Slip found matching Slip #' . $slip_no . '.'
            ]);
        }
        exit;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
}
