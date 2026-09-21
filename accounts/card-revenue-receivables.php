<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// AJAX endpoint: Fetch underlying swipes for a settlement batch
if (isset($_GET['action']) && $_GET['action'] === 'get_swipes') {
    header('Content-Type: application/json');
    $machineId = intval($_GET['machine_id'] ?? 0);
    $batchNo   = trim($_GET['batch_no'] ?? '');

    if ($machineId <= 0 || empty($batchNo)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid machine or batch number.']);
        exit;
    }

    $b_safe = mysqli_real_escape_string($connection, $batchNo);
    $sql_swipes = "SELECT cs.*, 
                          n.name AS nozzle_name, 
                          i.name AS item_name
                   FROM tbl_meter_reading_card_sales cs
                   LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
                   LEFT JOIN tbl_items i ON (n.item_id = i.id)
                   WHERE cs.card_machine_id = '$machineId' 
                     AND cs.batch_no = '$b_safe'
                     AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
                   ORDER BY cs.sale_date ASC, cs.id ASC";

    $res_swipes = mysqli_query($connection, $sql_swipes);
    $swipes = [];
    $total_swipe_amt = 0.00;
    $total_swipe_diff = 0.00;

    if ($res_swipes) {
        while ($row = mysqli_fetch_assoc($res_swipes)) {
            $amt  = floatval($row['amount']);
            $diff = floatval($row['difference']);
            $total_swipe_amt  += $amt;
            $total_swipe_diff += $diff;

            $swipes[] = [
                'id'          => $row['id'],
                'sale_date'   => $row['sale_date'],
                'trace_no'    => $row['trace_no'] ?: '—',
                'nozzle_name' => $row['nozzle_name'] ?: '—',
                'item_name'   => $row['item_name'] ?: '—',
                'quantity'    => floatval($row['quantity']),
                'rate'        => floatval($row['rate']),
                'amount'      => $amt,
                'difference'  => $diff
            ];
        }
    }

    echo json_encode([
        'status'          => 'success',
        'batch_no'        => $batchNo,
        'count'           => count($swipes),
        'total_amount'    => $total_swipe_amt,
        'total_diff'      => $total_swipe_diff,
        'swipes'          => $swipes
    ]);
    exit;
}

// Self-healing schema checks
$chk_p = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_revenue_payments'");
if ($chk_p && mysqli_num_rows($chk_p) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_revenue_payments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_date` DATE NOT NULL,
      `card_machine_id` INT(11) NOT NULL,
      `payment_mode` ENUM('Cash', 'Bank') NOT NULL DEFAULT 'Cash',
      `bank_id` INT(11) DEFAULT NULL,
      `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `filter_from_date` DATE DEFAULT NULL,
      `filter_to_date` DATE DEFAULT NULL,
      `transaction_ref` VARCHAR(128) DEFAULT NULL,
      `remarks` TEXT DEFAULT NULL,
      `created_by` INT(11) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_machine` (`card_machine_id`),
      KEY `idx_payment_date` (`payment_date`),
      KEY `idx_bank` (`bank_id`),
      KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

$chk_a = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_revenue_payment_allocations'");
if ($chk_a && mysqli_num_rows($chk_a) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_revenue_payment_allocations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_id` INT(11) NOT NULL,
      `settlement_id` INT(11) NOT NULL,
      `allocated_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_payment` (`payment_id`),
      KEY `idx_settlement` (`settlement_id`),
      KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

$chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'revenue_paid_amount'");
if ($chk_col && mysqli_num_rows($chk_col) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN revenue_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER revenue_amount");
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN revenue_payment_status ENUM('Unpaid', 'Partial', 'Paid') NOT NULL DEFAULT 'Unpaid' AFTER revenue_paid_amount, ADD INDEX idx_rev_status (revenue_payment_status)");
}

// Fetch active POS card machines
$mach_res = mysqli_query($connection, "SELECT id, name, revenue_charge FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY name ASC");
$all_machines = [];
if ($mach_res) {
    while ($m = mysqli_fetch_assoc($mach_res)) {
        $all_machines[] = $m;
    }
}

// Fetch active banks for deposit modal
$bank_res = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC");
$all_banks = [];
if ($bank_res) {
    while ($b = mysqli_fetch_assoc($bank_res)) {
        $all_banks[] = $b;
    }
}

// Extract filter parameters
$fromDate     = trim($_GET['from_date'] ?? '');
$toDate       = trim($_GET['to_date'] ?? '');
$machineId    = intval($_GET['card_machine_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? 'outstanding'); // outstanding, unpaid, partial, paid, all

$isSearched = (
    !empty($fromDate) ||
    !empty($toDate) ||
    $machineId > 0 ||
    (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'outstanding') ||
    isset($_GET['search'])
);

$settlements = [];
$total_gross = 0.00;
$total_revenue_expected = 0.00;
$total_revenue_paid = 0.00;
$total_revenue_due = 0.00;
$paid_count = 0;
$partial_count = 0;
$unpaid_count = 0;

// Calculate Lifetime Machine Outstanding Revenue Due (all unsettled batches across all time)
$machine_lifetime_revenue_due = 0.00;
$selected_machine_name = '';

if ($machineId > 0) {
    foreach ($all_machines as $m) {
        if ($m['id'] == $machineId) {
            $selected_machine_name = $m['name'];
            break;
        }
    }
    $q_life = mysqli_query($connection, "SELECT SUM(revenue_amount - revenue_paid_amount) AS lifetime_due 
                                          FROM tbl_card_sale_settlements 
                                          WHERE card_machine_id = '$machineId' 
                                            AND revenue_payment_status != 'Paid'
                                            AND (revenue_amount - revenue_paid_amount) > 0.00
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_life && ($r_life = mysqli_fetch_assoc($q_life))) {
        $machine_lifetime_revenue_due = floatval($r_life['lifetime_due'] ?? 0.00);
    }
} else {
    $q_life_all = mysqli_query($connection, "SELECT SUM(revenue_amount - revenue_paid_amount) AS lifetime_due 
                                              FROM tbl_card_sale_settlements 
                                              WHERE revenue_payment_status != 'Paid'
                                                AND (revenue_amount - revenue_paid_amount) > 0.00
                                                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_life_all && ($r_life_all = mysqli_fetch_assoc($q_life_all))) {
        $machine_lifetime_revenue_due = floatval($r_life_all['lifetime_due'] ?? 0.00);
    }
}

// If searched, execute the main query
if ($isSearched) {
    $where = ["(s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')"];

    if (!empty($fromDate)) {
        $f_safe = mysqli_real_escape_string($connection, $fromDate);
        $where[] = "s.settlement_date >= '$f_safe'";
    }
    if (!empty($toDate)) {
        $t_safe = mysqli_real_escape_string($connection, $toDate);
        $where[] = "s.settlement_date <= '$t_safe'";
    }
    if ($machineId > 0) {
        $where[] = "s.card_machine_id = '$machineId'";
    }

    if ($statusFilter === 'unpaid') {
        $where[] = "s.revenue_payment_status = 'Unpaid'";
    } elseif ($statusFilter === 'partial') {
        $where[] = "s.revenue_payment_status = 'Partial'";
    } elseif ($statusFilter === 'paid') {
        $where[] = "s.revenue_payment_status = 'Paid'";
    } elseif ($statusFilter === 'outstanding') {
        $where[] = "s.revenue_payment_status IN ('Unpaid', 'Partial')";
    } // 'all' requires no revenue_payment_status filter

    $where_sql = implode(' AND ', $where);

    $sql_list = "SELECT s.*, 
                        cm.name AS machine_name, 
                        cm.revenue_charge AS default_machine_revenue_charge,
                        sh.name AS shift_name
                 FROM tbl_card_sale_settlements s
                 LEFT JOIN tbl_card_machines cm ON (s.card_machine_id = cm.id)
                 LEFT JOIN tbl_shifts sh ON (s.shift_id = sh.id)
                 WHERE $where_sql
                 ORDER BY s.settlement_date DESC, s.id DESC";

    $res_list = mysqli_query($connection, $sql_list);
    if ($res_list) {
        while ($row = mysqli_fetch_assoc($res_list)) {
            $g_amt    = floatval($row['amount']);
            $rev_amt  = floatval($row['revenue_amount']);
            $rev_paid = floatval($row['revenue_paid_amount']);
            $due      = max(0.00, round($rev_amt - $rev_paid, 2));

            $row['calculated_due'] = $due;

            $total_gross            += $g_amt;
            $total_revenue_expected += $rev_amt;
            $total_revenue_paid     += $rev_paid;
            $total_revenue_due      += $due;

            if ($row['revenue_payment_status'] === 'Paid') {
                $paid_count++;
            } elseif ($row['revenue_payment_status'] === 'Partial') {
                $partial_count++;
            } else {
                $unpaid_count++;
            }

            $settlements[] = $row;
        }
    }
}

$canReceive = has_permission('accounts', 'add') || has_permission('accounts', 'edit') || has_permission('card_sales', 'edit');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Accounts Receivable (Card Revenue Charges)</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.5">
    <style>
        body { background-color: #f4f6fa; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: linear-gradient(135deg, #04204e 0%, #07347a 100%);
            color: #fff;
            padding: 20px 24px;
            border-radius: 8px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(4, 32, 78, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.35rem; }
        .data-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            margin-bottom: 24px;
        }
        .data-card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .data-card-body { padding: 22px; }
        .metric-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 4px solid #04204e;
            height: 100%;
        }
        .metric-card.border-danger { border-left-color: #dc3545 !important; }
        .metric-card.border-success { border-left-color: #28a745 !important; }
        .metric-card.border-warning { border-left-color: #ffc107 !important; }
        .metric-card.border-info { border-left-color: #17a2b8 !important; }
        .metric-card.border-primary { border-left-color: #04204e !important; }

        .metric-card .metric-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .metric-card .metric-value {
            font-size: 1.45rem;
            font-weight: 900;
            color: #1a1a2e;
            line-height: 1.2;
        }
        .metric-card .metric-sub {
            font-size: 0.75rem;
            color: #888;
            margin-top: 4px;
        }
        table.dataTable thead th {
            background: #04204e !important;
            color: #fff !important;
            border: none !important;
            font-size: 0.80rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        table.dataTable tbody td {
            font-size: 0.85rem;
            vertical-align: middle;
        }
        .badge-status-unpaid {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .badge-status-partial {
            background-color: #fff3e0;
            color: #ef6c00;
            border: 1px solid #ffcc80;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .badge-status-paid {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<?php include '../include/navbar.php'; ?>

<div class="container-fluid px-4 py-3">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-chart-line mr-2"></i> Accounts Receivable (Card Revenue Charges)</h4>
            <small class="text-white-50">Station Card Surcharge Markup Tracking &amp; Collection (Cash or Bank)</small>
        </div>
        <div>
            <a href="card-revenue-history.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                <i class="fas fa-history mr-1 text-primary"></i> Revenue History
            </a>
            <a href="card-sale-receivables.php" class="btn btn-outline-light btn-sm font-weight-bold ml-2">
                <i class="fas fa-credit-card mr-1"></i> Bank Settlements
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="data-card mb-4">
        <div class="data-card-body py-3">
            <form method="GET" action="" id="filterForm" class="form-row align-items-end">
                <input type="hidden" name="search" value="1">
                <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">From Batch Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">To Batch Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-lg-3 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">Card Machine (POS Terminal)</label>
                    <select name="card_machine_id" id="filter_card_machine_id" class="form-control form-control-sm">
                        <option value="">All Card Machines</option>
                        <?php foreach ($all_machines as $m): ?>
                            <option value="<?php echo $m['id']; ?>" <?php echo ($machineId == $m['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m['name']); ?> (Rev Rate: <?php echo number_format(floatval($m['revenue_charge']), 2); ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">Revenue Payment Status</label>
                    <select name="status" class="form-control form-control-sm">
                        <option value="outstanding" <?php echo ($statusFilter === 'outstanding') ? 'selected' : ''; ?>>Outstanding (Unpaid + Partial)</option>
                        <option value="unpaid" <?php echo ($statusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid Only</option>
                        <option value="partial" <?php echo ($statusFilter === 'partial') ? 'selected' : ''; ?>>Partial Only</option>
                        <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Paid Only</option>
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Revenue Batches</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-12 col-sm-12 mb-2 d-flex">
                    <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold mr-2">
                        <i class="fas fa-search mr-1"></i> Search
                    </button>
                    <a href="card-revenue-receivables.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$isSearched): ?>
    <!-- Search Prompt Card (Search-First Workflow) -->
    <div class="data-card text-center py-5">
        <div class="py-4">
            <i class="fas fa-chart-line fa-3x text-muted mb-3" style="opacity: 0.4;"></i>
            <h5 class="font-weight-bold text-dark mb-1">Select Filters to Load Card Revenue Batches</h5>
            <p class="text-muted small mb-3">Choose a Date Range and POS Card Machine above, then click <strong>Search</strong> to review and collect station card surcharge revenue.</p>
            <div class="d-inline-flex align-items-center bg-light border rounded px-3 py-2">
                <span class="text-muted small mr-2">Station-Wide Lifetime Card Revenue Due:</span>
                <strong class="text-info font-weight-bold" style="font-size:1.05rem;">Rs. <?php echo number_format($machine_lifetime_revenue_due, 2); ?></strong>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Summary Ribbon: Total Received & Lifetime Balance -->
    <div class="row mb-4">
        <div class="col-md-6 col-sm-12 mb-3">
            <div class="metric-card border-success">
                <div class="metric-label text-success"><i class="fas fa-check-circle mr-1"></i> Total Received</div>
                <div class="metric-value text-success">Rs. <?php echo number_format($total_revenue_paid, 2); ?></div>
                <div class="metric-sub"><?php echo $paid_count; ?> fully cleared batches</div>
            </div>
        </div>
        <div class="col-md-6 col-sm-12 mb-3">
            <div class="metric-card border-info">
                <div class="metric-label text-info"><i class="fas fa-university mr-1"></i> Lifetime Balance</div>
                <div class="metric-value text-info">Rs. <?php echo number_format($machine_lifetime_revenue_due, 2); ?></div>
                <div class="metric-sub">
                    <?php echo !empty($selected_machine_name) ? htmlspecialchars($selected_machine_name) : 'All Card Machines'; ?> (Uncollected Revenue)
                </div>
            </div>
        </div>
    </div>

    <!-- Settlement Batches Ledger Card -->
    <div class="data-card">
        <div class="data-card-header">
            <div>
                <h6 class="mb-0 font-weight-bold text-dark d-inline-block">
                    <i class="fas fa-layer-group mr-2 text-primary"></i> Settlement Batches (Revenue Markup)
                    <span class="badge badge-secondary ml-2"><?php echo count($settlements); ?> Batches</span>
                </h6>
            </div>
            <div>
                <?php if ($canReceive): ?>
                    <?php if ($total_revenue_due > 0.00): ?>
                    <button type="button" class="btn btn-success btn-sm font-weight-bold shadow-sm" onclick="openReceiveModal()">
                        <i class="fas fa-hand-holding-usd mr-1"></i> Collect Revenue Charges (Rs. <?php echo number_format($total_revenue_due, 2); ?>)
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm font-weight-bold" disabled title="All filtered batches have zero revenue due.">
                        <i class="fas fa-check-circle mr-1"></i> All Filtered Revenue Collected
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="data-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0" id="settlementsTable" style="width:100%;">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:40px;">#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>POS Terminal</th>
                            <th class="text-center">Batch #</th>
                            <th class="text-center">Cards</th>
                            <th class="text-right">Pure Sales (Rs.)</th>
                            <th class="text-center">Rev Rate %</th>
                            <th class="text-right">Revenue Expected</th>
                            <th class="text-right">Received (Rs.)</th>
                            <th class="text-right">Balance Due</th>
                            <th class="text-center">Status</th>
                            <th class="text-center" style="width:90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sr = 1;
                        foreach ($settlements as $row): 
                            $statusClass = 'badge-status-unpaid';
                            if ($row['revenue_payment_status'] === 'Paid') $statusClass = 'badge-status-paid';
                            elseif ($row['revenue_payment_status'] === 'Partial') $statusClass = 'badge-status-partial';
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold text-muted"><?php echo $sr++; ?></td>
                            <td class="font-weight-bold">
                                <?php echo date('d-m-Y', strtotime($row['settlement_date'])); ?>
                            </td>
                            <td>
                                <span class="badge badge-light border px-2 py-1"><?php echo htmlspecialchars($row['shift_name'] ?: 'N/A'); ?></span>
                            </td>
                            <td>
                                <i class="fas fa-credit-card mr-1 text-primary"></i>
                                <strong><?php echo htmlspecialchars($row['machine_name']); ?></strong>
                            </td>
                            <td class="text-center font-weight-bold" style="font-family: monospace;">
                                <?php echo htmlspecialchars($row['batch_no']); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-secondary"><?php echo intval($row['no_of_cards']); ?></span>
                            </td>
                            <td class="text-right font-weight-bold">
                                Rs. <?php echo number_format(floatval($row['amount']), 2); ?>
                            </td>
                            <td class="text-center" style="font-family: monospace; font-size: 0.8rem; color:#6c757d;">
                                <?php echo number_format(floatval($row['revenue_percentage']), 4); ?>%
                            </td>
                            <td class="text-right font-weight-bold text-info">
                                +Rs. <?php echo number_format(floatval($row['revenue_amount']), 2); ?>
                            </td>
                            <td class="text-right text-success font-weight-bold">
                                Rs. <?php echo number_format(floatval($row['revenue_paid_amount']), 2); ?>
                            </td>
                            <td class="text-right font-weight-bold <?php echo ($row['calculated_due'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                Rs. <?php echo number_format($row['calculated_due'], 2); ?>
                            </td>
                            <td class="text-center">
                                <span class="<?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars($row['revenue_payment_status']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" 
                                        onclick="viewSwipes(<?php echo $row['card_machine_id']; ?>, '<?php echo htmlspecialchars($row['batch_no']); ?>')"
                                        title="View Swipes in Batch">
                                    <i class="fas fa-list-ul mr-1"></i> Swipes
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light font-weight-bold">
                            <td colspan="6" class="text-right">Total:</td>
                            <td class="text-right">Rs. <?php echo number_format($total_gross, 2); ?></td>
                            <td></td>
                            <td class="text-right text-info">+Rs. <?php echo number_format($total_revenue_expected, 2); ?></td>
                            <td class="text-right text-success">Rs. <?php echo number_format($total_revenue_paid, 2); ?></td>
                            <td class="text-right text-danger">Rs. <?php echo number_format($total_revenue_due, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal: Collect Revenue Charges -->
<div class="modal fade" id="receiveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form id="receiveForm" method="POST" action="process-card-revenue.php">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-hand-holding-usd mr-2"></i> Collect Card Revenue Surcharge
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="card_machine_id" value="<?php echo $machineId; ?>">
                    <input type="hidden" name="filter_from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <input type="hidden" name="filter_to_date" value="<?php echo htmlspecialchars($toDate); ?>">

                    <div class="alert alert-info py-2 px-3 mb-3 small">
                        <i class="fas fa-info-circle mr-1"></i>
                        Collecting card surcharge revenue for <strong><?php echo !empty($selected_machine_name) ? htmlspecialchars($selected_machine_name) : 'Selected Batches'; ?></strong>
                        <?php if (!empty($fromDate) && !empty($toDate)): ?>
                            (<?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?>)
                        <?php endif; ?>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Collection Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Destination / Payment Mode <span class="text-danger">*</span></label>
                        <select name="payment_mode" id="payment_mode_select" class="form-control" onchange="togglePaymentDestination()" required>
                            <option value="Cash">Cash in Hand (Station Safe / Register)</option>
                            <option value="Bank">Deposit into Bank Account (Bank Master)</option>
                        </select>
                    </div>

                    <div class="form-group mb-3" id="bank_account_wrapper" style="display:none;">
                        <label class="font-weight-bold text-dark small mb-1">Select Bank Account <span class="text-danger">*</span></label>
                        <select name="bank_id" id="bank_id_select" class="form-control">
                            <option value="">-- Choose Bank Account --</option>
                            <?php foreach ($all_banks as $b): ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['name']); ?> (A/C: <?php echo htmlspecialchars($b['account_number']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1 d-flex justify-content-between">
                            <span>Amount to Collect (Rs.) <span class="text-danger">*</span></span>
                            <span class="text-muted small">Outstanding Due: <strong class="text-danger">Rs. <?php echo number_format($total_revenue_due, 2); ?></strong></span>
                        </label>
                        <input type="number" step="0.01" min="0.01" max="<?php echo $total_revenue_due; ?>" 
                               name="amount" id="receive_amount" class="form-control font-weight-bold text-primary" 
                               style="font-size:1.15rem;" value="<?php echo number_format($total_revenue_due, 2, '.', ''); ?>" required>
                        <small class="form-text text-muted">Amount cannot exceed total outstanding revenue balance.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Receipt Ref / Voucher Note</label>
                        <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. REV-CR-001 or Slip Note">
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small mb-1">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes on this revenue collection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-3">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitReceive" class="btn btn-success btn-sm font-weight-bold px-3">
                        <i class="fas fa-save mr-1"></i> Confirm &amp; Collect Revenue
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: View Swipes Drill-Down -->
<div class="modal fade" id="swipesModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-list-ul mr-2 text-warning"></i> Underlying Card Swipes &bull; Batch #<span id="modalBatchNo"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small">Total Swipes:</span> <strong id="modalSwipeCount">0</strong>
                    </div>
                    <div>
                        <span class="text-muted small">Total Pure Sales:</span> <strong class="text-primary" id="modalSwipeAmount">Rs. 0.00</strong>
                    </div>
                    <div>
                        <span class="text-muted small">Total Revenue Surcharge:</span> <strong class="text-info" id="modalSwipeDiff">+Rs. 0.00</strong>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0" id="swipesTable">
                        <thead class="bg-secondary text-white">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Trace #</th>
                                <th>Nozzle</th>
                                <th>Item</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Rate</th>
                                <th class="text-right">Pure Fuel (Rs.)</th>
                                <th class="text-right">Revenue (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody id="swipesTableBody">
                            <!-- Populated via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    <?php if ($isSearched && count($settlements) > 0): ?>
    $('#settlementsTable').DataTable({
        pageLength: 25,
        ordering: false,
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6"f>>rt<"row mt-2"<"col-md-6"i><"col-md-6"p>>',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search batch records..."
        }
    });
    <?php endif; ?>

    // Receive Form Submission via AJAX
    $('#receiveForm').on('submit', function(e) {
        e.preventDefault();
        var maxDue = parseFloat($('#receive_amount').attr('max')) || 0;
        var entered = parseFloat($('#receive_amount').val()) || 0;

        if (entered <= 0) {
            Swal.fire('Error', 'Please enter a valid amount greater than zero.', 'error');
            return;
        }

        if (entered > maxDue) {
            Swal.fire('Overpayment Blocked', 'Amount (Rs. ' + entered.toFixed(2) + ') cannot exceed outstanding revenue balance (Rs. ' + maxDue.toFixed(2) + ').', 'warning');
            return;
        }

        var btn = $('#btnSubmitReceive');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Processing...');

        $.ajax({
            url: 'process-card-revenue.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Confirm & Collect Revenue');
                if (res.status === 'success') {
                    $('#receiveModal').modal('hide');
                    Swal.fire({
                        title: 'Revenue Collected!',
                        html: res.message,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Failed to process collection.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Confirm & Collect Revenue');
                Swal.fire('Network Error', 'An error occurred while communicating with server.', 'error');
            }
        });
    });
});

function togglePaymentDestination() {
    var mode = $('#payment_mode_select').val();
    if (mode === 'Bank') {
        $('#bank_account_wrapper').slideDown(200);
        $('#bank_id_select').prop('required', true);
    } else {
        $('#bank_account_wrapper').slideUp(200);
        $('#bank_id_select').prop('required', false);
    }
}

function openReceiveModal() {
    <?php if ($machineId <= 0): ?>
    Swal.fire('Machine Required', 'Please filter by a specific Card Machine before collecting revenue charges.', 'info');
    return;
    <?php endif; ?>
    $('#receiveModal').modal('show');
}

function viewSwipes(machineId, batchNo) {
    $('#modalBatchNo').text(batchNo);
    $('#swipesTableBody').html('<tr><td colspan="9" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></td></tr>');
    $('#swipesModal').modal('show');

    $.ajax({
        url: 'card-revenue-receivables.php',
        type: 'GET',
        data: { action: 'get_swipes', machine_id: machineId, batch_no: batchNo },
        dataType: 'json',
        success: function(res) {
            if (res.status === 'success') {
                $('#modalSwipeCount').text(res.count);
                $('#modalSwipeAmount').text('Rs. ' + parseFloat(res.total_amount).toFixed(2));
                $('#modalSwipeDiff').text('+Rs. ' + parseFloat(res.total_diff).toFixed(2));

                if (res.swipes.length === 0) {
                    $('#swipesTableBody').html('<tr><td colspan="9" class="text-center text-muted py-3">No underlying card swipes logged for this batch.</td></tr>');
                    return;
                }

                var html = '';
                $.each(res.swipes, function(idx, s) {
                    html += '<tr>' +
                        '<td>' + (idx + 1) + '</td>' +
                        '<td>' + s.sale_date + '</td>' +
                        '<td style="font-family:monospace;">' + s.trace_no + '</td>' +
                        '<td>' + s.nozzle_name + '</td>' +
                        '<td>' + s.item_name + '</td>' +
                        '<td class="text-right">' + s.quantity.toFixed(2) + '</td>' +
                        '<td class="text-right">' + s.rate.toFixed(2) + '</td>' +
                        '<td class="text-right font-weight-bold text-primary">Rs. ' + s.amount.toFixed(2) + '</td>' +
                        '<td class="text-right font-weight-bold text-info">+Rs. ' + s.difference.toFixed(2) + '</td>' +
                    '</tr>';
                });
                $('#swipesTableBody').html(html);
            } else {
                $('#swipesTableBody').html('<tr><td colspan="9" class="text-center text-danger py-3">' + res.message + '</td></tr>');
            }
        },
        error: function() {
            $('#swipesTableBody').html('<tr><td colspan="9" class="text-center text-danger py-3">Failed to load swipes breakdown.</td></tr>');
        }
    });
}
</script>

</body>
</html>
