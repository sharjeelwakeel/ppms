<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

header('Content-Type: application/json');

$canEdit   = has_permission('expenses', 'edit');
$canDelete = has_permission('expenses', 'delete');

// Read DataTables parameters
$draw    = isset($_POST['draw']) ? intval($_POST['draw']) : (isset($_GET['draw']) ? intval($_GET['draw']) : 1);
$start   = isset($_POST['start']) ? intval($_POST['start']) : (isset($_GET['start']) ? intval($_GET['start']) : 0);
$length  = isset($_POST['length']) ? intval($_POST['length']) : (isset($_GET['length']) ? intval($_GET['length']) : 25);
$searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : (isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '');

// Additional filters
$from_date = isset($_REQUEST['from_date']) ? trim($_REQUEST['from_date']) : '';
$to_date   = isset($_REQUEST['to_date']) ? trim($_REQUEST['to_date']) : '';
$type_id   = isset($_REQUEST['type_id']) ? trim($_REQUEST['type_id']) : '';

// Columns mapping for ordering
$columns = ['e.id', 'e.expense_date', 't.name', 'e.amount', 'e.payment_method', 'e.reference_no', 'e.notes', 'e.created_at'];
$columnName = 'e.expense_date';
$columnSortOrder = 'desc';

if (isset($_POST['order'][0]['column'])) {
    $cIdx = intval($_POST['order'][0]['column']);
    if (isset($columns[$cIdx])) {
        $columnName = $columns[$cIdx];
    }
}
if (isset($_POST['order'][0]['dir'])) {
    $columnSortOrder = strtolower($_POST['order'][0]['dir']) === 'asc' ? 'asc' : 'desc';
}

$where = " WHERE e.deleted_at IS NULL ";

if (!empty($from_date)) {
    $escapedFrom = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND e.expense_date >= '$escapedFrom' ";
}
if (!empty($to_date)) {
    $escapedTo = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND e.expense_date <= '$escapedTo' ";
}
if (!empty($type_id)) {
    $escapedType = mysqli_real_escape_string($connection, $type_id);
    $where .= " AND e.expense_type_id = '$escapedType' ";
}

if ($searchValue !== '') {
    $escapedSearch = mysqli_real_escape_string($connection, $searchValue);
    $where .= " AND (t.name LIKE '%$escapedSearch%' OR e.amount LIKE '%$escapedSearch%' OR e.reference_no LIKE '%$escapedSearch%' OR e.notes LIKE '%$escapedSearch%' OR e.payment_method LIKE '%$escapedSearch%') ";
}

// Total records count
$totalRes = mysqli_query($connection, "SELECT COUNT(*) AS total FROM tbl_expenses WHERE deleted_at IS NULL");
$totalRow = mysqli_fetch_assoc($totalRes);
$totalRecords = intval($totalRow['total']);

// Filtered records count
$filteredQuery = "SELECT COUNT(*) AS total FROM tbl_expenses e LEFT JOIN tbl_expense_types t ON e.expense_type_id = t.id " . $where;
$filteredRes = mysqli_query($connection, $filteredQuery);
$filteredRow = mysqli_fetch_assoc($filteredRes);
$filteredRecords = intval($filteredRow['total']);

// Summary amounts for current filter
$sumQuery = "SELECT 
    SUM(e.amount) as total_sum, 
    SUM(CASE WHEN e.payment_method = 'Cash' THEN e.amount ELSE 0 END) as cash_sum,
    SUM(CASE WHEN e.payment_method != 'Cash' THEN e.amount ELSE 0 END) as bank_sum
    FROM tbl_expenses e LEFT JOIN tbl_expense_types t ON e.expense_type_id = t.id " . $where;
$sumRes = mysqli_query($connection, $sumQuery);
$sumRow = mysqli_fetch_assoc($sumRes);

$totalSum = floatval($sumRow['total_sum'] ?? 0);
$cashSum  = floatval($sumRow['cash_sum'] ?? 0);
$bankSum  = floatval($sumRow['bank_sum'] ?? 0);

// Fetch data rows
if ($length < 0) {
    $length = 25;
}

$dataQuery = "SELECT e.*, t.name as category_name, b.name as bank_name 
              FROM tbl_expenses e 
              LEFT JOIN tbl_expense_types t ON e.expense_type_id = t.id 
              LEFT JOIN tbl_banks b ON e.bank_id = b.id 
              $where 
              ORDER BY $columnName $columnSortOrder 
              LIMIT $length OFFSET $start";

$dataRes = mysqli_query($connection, $dataQuery);

$data = [];
if ($dataRes && mysqli_num_rows($dataRes) > 0) {
    $sn = $start + 1;
    while ($row = mysqli_fetch_assoc($dataRes)) {
        $id          = $row['id'];
        $exp_date    = date("d-m-Y", strtotime($row['expense_date']));
        $category    = htmlspecialchars($row['category_name'] ?? 'Uncategorized');
        $amount      = '<strong>Rs. ' . number_format($row['amount'], 2) . '</strong>';
        $pay_method  = htmlspecialchars($row['payment_method']);
        if ($row['payment_method'] !== 'Cash' && !empty($row['bank_name'])) {
            $pay_method .= ' <small class="text-muted">(' . htmlspecialchars($row['bank_name']) . ')</small>';
        }
        $ref_no      = !empty($row['reference_no']) ? '<code>' . htmlspecialchars($row['reference_no']) . '</code>' : '-';
        $notes       = htmlspecialchars($row['notes'] ?? '-');

        $actions = '';
        if ($canEdit) {
            $actions .= '<a href="edit-expense.php?id=' . $id . '" class="btn btn-sm btn-link text-primary p-0 mr-2" title="Edit"><i class="fas fa-edit" style="font-size: 16px;"></i></a>';
        }
        if ($canDelete) {
            $actions .= '<a class="btn btn-sm btn-link text-danger p-0" onclick="deleteExpense(' . $id . ')" title="Delete"><i class="fas fa-trash-alt" style="font-size: 16px;"></i></a>';
        }
        if (empty($actions)) {
            $actions = '<span class="text-muted small">No Access</span>';
        }

        $data[] = [
            $sn++,
            $exp_date,
            '<span class="badge badge-info">' . $category . '</span>',
            $amount,
            $pay_method,
            $ref_no,
            $notes,
            $actions
        ];
    }
}

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $totalRecords,
    "recordsFiltered" => $filteredRecords,
    "data"            => $data,
    "totalSum"        => number_format($totalSum, 2),
    "cashSum"         => number_format($cashSum, 2),
    "bankSum"         => number_format($bankSum, 2)
]);
?>
