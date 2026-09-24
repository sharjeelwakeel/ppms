<?php
require_once '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once '../include/config.php';
require_once '../include/cash_automation_helper.php';

header('Content-Type: application/json');

$date      = trim($_GET['sale_date'] ?? '');
$shift_id  = intval($_GET['shift_id'] ?? 0);
$nozzle_id = intval($_GET['nozzle_id'] ?? 0);

if (empty($date) || $shift_id <= 0 || $nozzle_id <= 0) {
    echo json_encode(['status' => 'success', 'exists' => false]);
    exit;
}

$record = check_existing_cash_sale_for_shift($connection, $date, $shift_id, $nozzle_id);

if ($record) {
    echo json_encode([
        'status' => 'success',
        'exists' => true,
        'data'   => [
            'id'                 => intval($record['id']),
            'sale_date'          => $record['sale_date'],
            'shift_id'           => intval($record['shift_id']),
            'shift_name'         => $record['shift_name'] ?? 'Shift #' . $record['shift_id'],
            'nozzle_id'          => intval($record['nozzle_id']),
            'nozzle_name'        => $record['nozzle_name'] ?? 'Nozzle #' . $record['nozzle_id'],
            'item_name'          => $record['item_name'] ?? 'Fuel',
            'quantity'           => floatval($record['quantity']),
            'amount'             => floatval($record['amount']),
            'rate'               => floatval($record['rate']),
            'is_manual_override' => intval($record['is_manual_override'] ?? 0),
            'meter_reading_id'   => intval($record['meter_reading_id'] ?? 0),
            'notes'              => $record['notes'] ?? ''
        ]
    ]);
} else {
    echo json_encode(['status' => 'success', 'exists' => false]);
}
