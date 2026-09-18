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

if (!has_permission('card_sales', 'show') && !has_permission('card_sales', 'add') && !has_permission('card_sales', 'edit')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
    exit;
}

$machineId = intval($_GET['card_machine_id'] ?? 0);
$batchNo   = trim($_GET['batch_no'] ?? '');
$saleDate  = trim($_GET['sale_date'] ?? '');
$shiftId   = intval($_GET['shift_id'] ?? 0);

if ($machineId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing card machine.']);
    exit;
}

// Fetch machine details (fee % and revenue charge)
$q_mach = mysqli_query($connection, "SELECT id, name, charges_percentage, revenue_charge 
                                     FROM tbl_card_machines 
                                     WHERE id = '$machineId' AND deleted_at IS NULL 
                                     LIMIT 1");
$mach = mysqli_fetch_assoc($q_mach);
if (!$mach) {
    echo json_encode(['status' => 'error', 'message' => 'Card machine not found.']);
    exit;
}

$fee_pct = floatval($mach['charges_percentage'] ?? 0);
$rev_pct = floatval($mach['revenue_charge'] ?? 0);

// Fetch all distinct available batches for this machine (and date/shift if provided) for convenient datalist
$batchWhere = "mrcs.card_machine_id = '$machineId' AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00') AND mrcs.batch_no IS NOT NULL AND TRIM(mrcs.batch_no) != ''";
if (!empty($saleDate) && strtotime($saleDate) !== false) {
    $dateSafe = mysqli_real_escape_string($connection, $saleDate);
    $batchWhere .= " AND mrcs.sale_date = '$dateSafe'";
}
if ($shiftId > 0) {
    $batchWhere .= " AND mrcs.shift_id = '$shiftId'";
}

$avail_batches = [];
$q_batches = mysqli_query($connection, "SELECT mrcs.batch_no, mrcs.sale_date, mrcs.shift_id, sh.name AS shift_name, 
                                               COUNT(mrcs.id) AS row_count, COALESCE(SUM(mrcs.amount), 0) AS total_amount
                                        FROM tbl_meter_reading_card_sales mrcs
                                        LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
                                        WHERE $batchWhere
                                        GROUP BY mrcs.batch_no, mrcs.sale_date, mrcs.shift_id
                                        ORDER BY mrcs.sale_date DESC, mrcs.shift_id DESC, mrcs.batch_no DESC");
if ($q_batches && mysqli_num_rows($q_batches) > 0) {
    while ($rb = mysqli_fetch_assoc($q_batches)) {
        $avail_batches[] = [
            'batch_no'         => $rb['batch_no'],
            'sale_date'        => $rb['sale_date'],
            'shift_id'         => intval($rb['shift_id']),
            'shift_name'       => $rb['shift_name'] ?? ('Shift #' . $rb['shift_id']),
            'row_count'        => intval($rb['row_count']),
            'total_amount'     => floatval($rb['total_amount']),
            'total_amount_fmt' => number_format(floatval($rb['total_amount']), 2)
        ];
    }
} else {
    // Fallback: fetch recent distinct batches for this machine across recent records
    $q_recent = mysqli_query($connection, "SELECT mrcs.batch_no, mrcs.sale_date, mrcs.shift_id, sh.name AS shift_name,
                                                   COUNT(mrcs.id) AS row_count, COALESCE(SUM(mrcs.amount), 0) AS total_amount
                                            FROM tbl_meter_reading_card_sales mrcs
                                            LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
                                            WHERE mrcs.card_machine_id = '$machineId' 
                                              AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')
                                              AND mrcs.batch_no IS NOT NULL AND TRIM(mrcs.batch_no) != ''
                                            GROUP BY mrcs.batch_no, mrcs.sale_date, mrcs.shift_id
                                            ORDER BY mrcs.sale_date DESC, mrcs.shift_id DESC, mrcs.batch_no DESC
                                            LIMIT 25");
    if ($q_recent) {
        while ($rb = mysqli_fetch_assoc($q_recent)) {
            $avail_batches[] = [
                'batch_no'         => $rb['batch_no'],
                'sale_date'        => $rb['sale_date'],
                'shift_id'         => intval($rb['shift_id']),
                'shift_name'       => $rb['shift_name'] ?? ('Shift #' . $rb['shift_id']),
                'row_count'        => intval($rb['row_count']),
                'total_amount'     => floatval($rb['total_amount']),
                'total_amount_fmt' => number_format(floatval($rb['total_amount']), 2)
            ];
        }
    }
}

// If no batch_no requested yet, return available batches list
if (empty($batchNo)) {
    echo json_encode([
        'status'            => 'success',
        'machine_id'        => $machineId,
        'machine_name'      => $mach['name'],
        'charges_percentage'=> $fee_pct,
        'available_batches' => $avail_batches,
        'matched'           => false,
        'card_count'        => 0,
        'total_amount'      => 0.00,
        'total_difference'  => 0.00,
        'after_adding_revenue' => 0.00,
        'after_deduction_charge' => 0.00,
        'total'             => 0.00,
        'items'             => []
    ]);
    exit;
}

// If batch_no is provided, fetch matching transaction rows
$batchSafe = mysqli_real_escape_string($connection, $batchNo);
$itemWhere = "mrcs.card_machine_id = '$machineId' 
              AND mrcs.batch_no = '$batchSafe' 
              AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')";

if (!empty($saleDate) && strtotime($saleDate) !== false) {
    $dateSafe = mysqli_real_escape_string($connection, $saleDate);
    $itemWhere .= " AND mrcs.sale_date = '$dateSafe'";
}
if ($shiftId > 0) {
    $itemWhere .= " AND mrcs.shift_id = '$shiftId'";
}

$sql_items = "SELECT mrcs.*,
                     n.name AS nozzle_name,
                     i.name AS item_name,
                     sh.name AS shift_name
              FROM tbl_meter_reading_card_sales mrcs
              LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
              LEFT JOIN tbl_items i ON (mrcs.item_id = i.id)
              LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
              WHERE $itemWhere
              ORDER BY mrcs.sale_date ASC, mrcs.shift_id ASC, mrcs.id ASC";

$res_items = mysqli_query($connection, $sql_items);
if (!$res_items || mysqli_num_rows($res_items) === 0) {
    // Fallback: match without date/shift filter so batch entered on different date/shift can be reconciled
    $fallbackWhere = "mrcs.card_machine_id = '$machineId' 
                      AND mrcs.batch_no = '$batchSafe' 
                      AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')";
    $sql_fallback = "SELECT mrcs.*,
                            n.name AS nozzle_name,
                            i.name AS item_name,
                            sh.name AS shift_name
                     FROM tbl_meter_reading_card_sales mrcs
                     LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
                     LEFT JOIN tbl_items i ON (mrcs.item_id = i.id)
                     LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
                     WHERE $fallbackWhere
                     ORDER BY mrcs.sale_date ASC, mrcs.shift_id ASC, mrcs.id ASC";
    $res_items = mysqli_query($connection, $sql_fallback);
}
$items = [];
$total_amount     = 0.00;
$total_difference = 0.00;
$detected_date    = '';
$detected_shift_id = 0;
$detected_shift_name = '';

if ($res_items) {
    while ($row = mysqli_fetch_assoc($res_items)) {
        $amt  = floatval($row['amount']);
        $diff = floatval($row['difference']);
        $schg = floatval($row['service_charges']);
        $net  = floatval($row['net_amount']);

        $total_amount     += $amt;
        $total_difference += $diff;

        if (empty($detected_date)) {
            $detected_date       = $row['sale_date'];
            $detected_shift_id   = intval($row['shift_id']);
            $detected_shift_name = $row['shift_name'] ?? ('Shift #' . $row['shift_id']);
        }

        $items[] = [
            'id'              => intval($row['id']),
            'sale_date'       => $row['sale_date'],
            'shift_id'        => intval($row['shift_id']),
            'shift_name'      => $row['shift_name'] ?? ('Shift #' . $row['shift_id']),
            'nozzle_name'     => $row['nozzle_name'] ?? 'N/A',
            'item_name'       => $row['item_name'] ?? 'N/A',
            'trace_no'        => $row['trace_no'] ?? '',
            'amount'          => $amt,
            'amount_fmt'      => number_format($amt, 2),
            'difference'      => $diff,
            'difference_fmt'  => number_format($diff, 2),
            'service_charges' => $schg,
            'service_charges_fmt' => number_format($schg, 2),
            'net_amount'      => $net,
            'net_amount_fmt'  => number_format($net, 2)
        ];
    }
}

$card_count = count($items);

// Calculations (Pure sales - bank fee = net total, keeping revenue difference separate):
// 1. Total Pure Amount = sum(amount)
// 2. Total Revenue Charge = sum(difference)
// 3. Total Service Charges (Bank Fee) = Total Pure Amount * (charges_percentage / 100)
// 4. Net Settlement Total = Total Pure Amount - Total Service Charges
$service_charges = round($total_amount * ($fee_pct / 100), 2);
$net_total       = round($total_amount - $service_charges, 2);

echo json_encode([
    'status'                 => 'success',
    'matched'                => ($card_count > 0),
    'machine_id'             => $machineId,
    'machine_name'           => $mach['name'],
    'charges_percentage'     => $fee_pct,
    'revenue_charge'         => $rev_pct,
    'batch_no'               => $batchNo,
    'card_count'             => $card_count,
    'detected_date'          => $detected_date,
    'detected_shift_id'      => $detected_shift_id,
    'detected_shift_name'    => $detected_shift_name,
    'total_amount'           => $total_amount,
    'total_amount_fmt'       => number_format($total_amount, 2),
    'total_difference'       => $total_difference,
    'total_difference_fmt'   => number_format($total_difference, 2),
    'service_charges'        => $service_charges,
    'service_charges_fmt'    => number_format($service_charges, 2),
    'after_deduction_charge' => $service_charges,
    'after_deduction_charge_fmt' => number_format($service_charges, 2),
    'total'                  => $net_total,
    'total_fmt'              => number_format($net_total, 2),
    'available_batches'      => $avail_batches,
    'items'                  => $items
]);
