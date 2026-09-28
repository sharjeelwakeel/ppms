<?php
/**
 * AJAX Endpoint: Process Cash Salary Payment & Reversions
 * PPMS (Petrol Pump Management System)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Please log in to perform this action.']);
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/salary_payment_helper.php';

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

if ($action === 'pay_cash') {
    if (!has_permission('staff', 'add') && !has_permission('staff', 'edit')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: You do not have access to disburse salary.']);
        exit;
    }

    $staff_id     = isset($_POST['staff_id']) ? intval($_POST['staff_id']) : 0;
    $month        = isset($_POST['month']) ? intval($_POST['month']) : 0;
    $year         = isset($_POST['year']) ? intval($_POST['year']) : 0;
    $payment_date = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
    $remarks      = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $user_id      = isset($_SESSION['loggedInUser']) ? intval($_SESSION['loggedInUser']) : 0;

    if ($staff_id <= 0 || $month < 1 || $month > 12 || $year < 2020) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid staff or salary period specified.']);
        exit;
    }

    $result = process_cash_salary_payment($connection, $staff_id, $month, $year, $payment_date, $remarks, $user_id);
    echo json_encode($result);
    exit;
}

if ($action === 'revert') {
    if (!has_permission('staff', 'delete') && !has_permission('staff', 'edit')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: You do not have access to revert salary payments.']);
        exit;
    }

    $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
    if ($payment_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID specified.']);
        exit;
    }

    $result = revert_salary_payment($connection, $payment_id);
    echo json_encode($result);
    exit;
}

if ($action === 'get_staff_preview') {
    if (!has_permission('staff', 'show')) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied.']);
        exit;
    }

    $staff_id = isset($_POST['staff_id']) ? intval($_POST['staff_id']) : 0;
    $month    = isset($_POST['month']) ? intval($_POST['month']) : intval(date('m'));
    $year     = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));

    if ($staff_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid staff ID.']);
        exit;
    }

    $records = get_staff_monthly_salary_records($connection, $month, $year, $staff_id);
    if (empty($records)) {
        echo json_encode(['status' => 'error', 'message' => 'Staff record not found.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'data' => $records[0]]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request action.']);
exit;
