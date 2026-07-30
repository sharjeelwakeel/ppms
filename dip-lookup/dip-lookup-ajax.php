<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require '../include/config.php';

header('Content-Type: application/json');

// Read DataTables parameters
$draw = isset($_POST['draw']) ? intval($_POST['draw']) : (isset($_GET['draw']) ? intval($_GET['draw']) : 1);
$start = isset($_POST['start']) ? intval($_POST['start']) : (isset($_GET['start']) ? intval($_GET['start']) : 0);
$length = isset($_POST['length']) ? intval($_POST['length']) : (isset($_GET['length']) ? intval($_GET['length']) : 25);
$searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : (isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '');

// Order parameters
$columns = ['id', 'dip_mm', 'dip_litre', 'created_at', 'updated_at'];
$columnIndex = 1; // Default dip_mm
$columnName = 'dip_mm';
$columnSortOrder = 'asc';

if (isset($_POST['order'][0]['column'])) {
    $columnIndex = intval($_POST['order'][0]['column']);
    if (isset($columns[$columnIndex])) {
        $columnName = $columns[$columnIndex];
    }
} elseif (isset($_GET['order'][0]['column'])) {
    $columnIndex = intval($_GET['order'][0]['column']);
    if (isset($columns[$columnIndex])) {
        $columnName = $columns[$columnIndex];
    }
}

if (isset($_POST['order'][0]['dir'])) {
    $columnSortOrder = strtolower($_POST['order'][0]['dir']) === 'desc' ? 'desc' : 'asc';
} elseif (isset($_GET['order'][0]['dir'])) {
    $columnSortOrder = strtolower($_GET['order'][0]['dir']) === 'desc' ? 'desc' : 'asc';
}

// 1. Total records count
$totalQuery = "SELECT COUNT(*) AS total FROM tbl_dip_lookup WHERE deleted_at IS NULL";
$totalRes = mysqli_query($connection, $totalQuery);
$totalRow = mysqli_fetch_assoc($totalRes);
$totalRecords = intval($totalRow['total']);

// 2. Filtered records count & search condition
$searchQuery = "";
if ($searchValue !== '') {
    $escapedSearch = mysqli_real_escape_string($connection, $searchValue);
    $searchQuery = " AND (dip_mm LIKE '%$escapedSearch%' OR dip_litre LIKE '%$escapedSearch%')";
}

$filteredQuery = "SELECT COUNT(*) AS total FROM tbl_dip_lookup WHERE deleted_at IS NULL" . $searchQuery;
$filteredRes = mysqli_query($connection, $filteredQuery);
$filteredRow = mysqli_fetch_assoc($filteredRes);
$filteredRecords = intval($filteredRow['total']);

// 3. Fetch data rows
if ($length < 0) {
    $length = 25; // fallback default
}

$dataQuery = "SELECT * FROM tbl_dip_lookup WHERE deleted_at IS NULL" . $searchQuery . " ORDER BY $columnName $columnSortOrder LIMIT $length OFFSET $start";
$dataRes = mysqli_query($connection, $dataQuery);

$data = [];
if ($dataRes && mysqli_num_rows($dataRes) > 0) {
    while ($row = mysqli_fetch_assoc($dataRes)) {
        $id = $row['id'];
        $dip_mm = htmlspecialchars(number_format($row['dip_mm'], 2));
        $dip_litre = htmlspecialchars(number_format($row['dip_litre'], 2));
        $created_at = date("d-m-Y h:i A", strtotime($row['created_at']));
        $updated_at = date("d-m-Y h:i A", strtotime($row['updated_at']));

        $dip_mm_link = '<a href="edit-dip-lookup.php?id=' . $id . '" class="font-weight-bold" style="color: var(--primary-color);">' . $dip_mm . ' mm</a>';
        
        $actions = '<a class="btn btn-large btn-link p-0 text-danger" onclick="deleteDipLookup(' . $id . ', \'' . $dip_mm . '\')" title="Delete"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a>';

        $data[] = [
            $id,
            $dip_mm_link,
            $dip_litre . ' L',
            $created_at,
            $updated_at,
            $actions
        ];
    }
}

// Return JSON response for DataTables
echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $filteredRecords,
    "data" => $data
]);
?>
