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

    if ($res_swipes) {
        while ($row = mysqli_fetch_assoc($res_swipes)) {
            $amt = floatval($row['amount']);
            $total_swipe_amt += $amt;

            $swipes[] = [
                'id'          => $row['id'],
                'sale_date'   => $row['sale_date'],
                'trace_no'    => $row['trace_no'] ?: '—',
                'nozzle_name' => $row['nozzle_name'] ?: '—',
                'item_name'   => $row['item_name'] ?: '—',
                'quantity'    => floatval($row['quantity']),
                'rate'        => floatval($row['rate']),
                'amount'      => $amt,
                'difference'  => floatval($row['difference'])
            ];
        }
    }

    echo json_encode([
        'status'          => 'success',
        'batch_no'        => $batchNo,
        'count'           => count($swipes),
        'total_amount'    => $total_swipe_amt,
        'swipes'          => $swipes
    ]);
    exit;
}

// Self-healing schema checks
$chk_p = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_settlement_payments'");
if ($chk_p && mysqli_num_rows($chk_p) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_settlement_payments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_date` DATE NOT NULL,
      `card_machine_id` INT(11) NOT NULL,
      `bank_id` INT(11) NOT NULL,
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

$chk_a = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_settlement_payment_allocations'");
if ($chk_a && mysqli_num_rows($chk_a) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_settlement_payment_allocations` (
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

$chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'paid_amount'");
if ($chk_col && mysqli_num_rows($chk_col) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER net_amount");
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN payment_status ENUM('Unpaid', 'Partial', 'Paid') NOT NULL DEFAULT 'Unpaid' AFTER paid_amount, ADD INDEX idx_payment_status (payment_status)");
}

// Fetch active POS card machines
$mach_res = mysqli_query($connection, "SELECT id, name, charges_percentage FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY name ASC");
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
$total_charges = 0.00;
$total_net = 0.00;
$total_paid = 0.00;
$total_due = 0.00;
$unpaid_count = 0;
$partial_count = 0;
$paid_count = 0;

$machine_lifetime_due = 0.00;
$selected_machine_name = '';

// Calculate Lifetime Outstanding Due for selected machine (or station-wide if none selected)
if ($machineId > 0) {
    foreach ($all_machines as $am) {
        if ($am['id'] == $machineId) {
            $selected_machine_name = $am['name'];
            break;
        }
    }
    $q_life = mysqli_query($connection, "SELECT SUM(net_amount - paid_amount) AS lifetime_due 
                                          FROM tbl_card_sale_settlements 
                                          WHERE card_machine_id = '$machineId' 
                                            AND payment_status != 'Paid'
                                            AND (net_amount - paid_amount) > 0.00
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_life && ($r_life = mysqli_fetch_assoc($q_life))) {
        $machine_lifetime_due = floatval($r_life['lifetime_due'] ?? 0.00);
    }
} else {
    $q_life_all = mysqli_query($connection, "SELECT SUM(net_amount - paid_amount) AS lifetime_due 
                                              FROM tbl_card_sale_settlements 
                                              WHERE payment_status != 'Paid'
                                                AND (net_amount - paid_amount) > 0.00
                                                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_life_all && ($r_life_all = mysqli_fetch_assoc($q_life_all))) {
        $machine_lifetime_due = floatval($r_life_all['lifetime_due'] ?? 0.00);
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
        $where[] = "s.payment_status = 'Unpaid'";
    } elseif ($statusFilter === 'partial') {
        $where[] = "s.payment_status = 'Partial'";
    } elseif ($statusFilter === 'paid') {
        $where[] = "s.payment_status = 'Paid'";
    } elseif ($statusFilter === 'outstanding') {
        $where[] = "s.payment_status IN ('Unpaid', 'Partial')";
    } // 'all' requires no payment_status filter

    $where_sql = implode(' AND ', $where);

    $sql_list = "SELECT s.*, 
                        cm.name AS machine_name, 
                        cm.charges_percentage AS default_machine_fee
                 FROM tbl_card_sale_settlements s
                 LEFT JOIN tbl_card_machines cm ON (s.card_machine_id = cm.id)
                 WHERE $where_sql
                 ORDER BY s.settlement_date DESC, s.id DESC";

    $res_list = mysqli_query($connection, $sql_list);
    if ($res_list) {
        while ($row = mysqli_fetch_assoc($res_list)) {
            $g_amt = floatval($row['amount']);
            $fee   = floatval($row['service_charges']);
            $net   = floatval($row['net_amount']);
            $paid  = floatval($row['paid_amount']);
            $due   = max(0.00, round($net - $paid, 2));

            $row['calculated_due'] = $due;

            $total_gross   += $g_amt;
            $total_charges += $fee;
            $total_net     += $net;
            $total_paid    += $paid;
            $total_due     += $due;

            if ($row['payment_status'] === 'Paid') {
                $paid_count++;
            } elseif ($row['payment_status'] === 'Partial') {
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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.7">
    <title>PPMS - Accounts Receivable (Card Sale Reconciliation)</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 20px 28px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.25rem; }
        .data-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            overflow: hidden;
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
            <h4><i class="fas fa-credit-card mr-2"></i> Accounts Receivable (Card Sale Settlements)</h4>
            <small class="text-white-50">POS Terminal Batch Reconciliation &amp; Direct Bank Master Deposits</small>
        </div>
        <div>
            <a href="card-settlement-history.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                <i class="fas fa-history mr-1 text-primary"></i> Deposit History
            </a>
            <a href="../card-sales/settlement-list.php" class="btn btn-outline-light btn-sm font-weight-bold ml-2">
                <i class="fas fa-list-alt mr-1"></i> Settlements List
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
                                <?php echo htmlspecialchars($m['name']); ?> (Fee: <?php echo number_format(floatval($m['charges_percentage']), 2); ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">Payment Status</label>
                    <select name="status" class="form-control form-control-sm">
                        <option value="outstanding" <?php echo ($statusFilter === 'outstanding') ? 'selected' : ''; ?>>Outstanding (Unpaid + Partial)</option>
                        <option value="unpaid" <?php echo ($statusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid Only</option>
                        <option value="partial" <?php echo ($statusFilter === 'partial') ? 'selected' : ''; ?>>Partial Only</option>
                        <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Paid Only</option>
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Settlements (History)</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-12 col-sm-12 mb-2 d-flex">
                    <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold mr-2">
                        <i class="fas fa-search mr-1"></i> Search
                    </button>
                    <a href="card-sale-receivables.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
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
            <i class="fas fa-credit-card fa-3x text-muted mb-3" style="opacity: 0.4;"></i>
            <h5 class="font-weight-bold text-dark mb-1">Select Filters to Load Settlement Batches</h5>
            <p class="text-muted small mb-3">Choose a Date Range and POS Card Machine above, then click <strong>Search</strong> to review receivables and record bank settlement deposits.</p>
            <div class="d-inline-flex align-items-center bg-light border rounded px-3 py-2">
                <span class="text-muted small mr-2">Station-Wide Lifetime Card Receivables Due:</span>
                <strong class="text-danger font-weight-bold" style="font-size:1.05rem;">Rs. <?php echo number_format($machine_lifetime_due, 2); ?></strong>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Summary Ribbon: Total Received & Lifetime Balance -->
    <div class="row mb-4">
        <div class="col-md-6 col-sm-12 mb-3">
            <div class="metric-card border-success">
                <div class="metric-label text-success"><i class="fas fa-check-circle mr-1"></i> Total Received</div>
                <div class="metric-value text-success">Rs. <?php echo number_format($total_paid, 2); ?></div>
                <div class="metric-sub"><?php echo $paid_count; ?> fully settled</div>
            </div>
        </div>
        <div class="col-md-6 col-sm-12 mb-3">
            <div class="metric-card border-info">
                <div class="metric-label text-info"><i class="fas fa-university mr-1"></i> Lifetime Balance</div>
                <div class="metric-value text-info">Rs. <?php echo number_format($machine_lifetime_due, 2); ?></div>
                <div class="metric-sub">
                    <?php echo !empty($selected_machine_name) ? htmlspecialchars($selected_machine_name) : 'All Card Machines'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Settlement Batches Ledger Card -->
    <div class="data-card">
        <div class="data-card-header">
            <div>
                <h6 class="mb-0 font-weight-bold text-dark d-inline-block">
                    <i class="fas fa-layer-group mr-2 text-primary"></i> Settlement Batches
                    <span class="badge badge-secondary ml-2"><?php echo count($settlements); ?> Batches</span>
                </h6>
            </div>
            <div>
                <?php if ($canReceive): ?>
                    <?php if ($total_due > 0.00): ?>
                    <button type="button" class="btn btn-success btn-sm font-weight-bold shadow-sm" onclick="openReceiveModal()">
                        <i class="fas fa-hand-holding-usd mr-1"></i> Receive Bank Deposit (Rs. <?php echo number_format($total_due, 2); ?>)
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm font-weight-bold" disabled="disabled" title="All filtered settlement batches are fully paid">
                        <i class="fas fa-check mr-1"></i> All Filtered Batches Paid
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="data-card-body p-0">
            <div class="table-responsive p-3">
                <table id="settlementsTable" class="table table-hover table-striped table-bordered mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:40px;">#</th>
                            <th class="text-center">Settlement Date</th>
                            <th>POS Card Machine</th>
                            <th class="text-center">Batch #</th>
                            <th class="text-center">Cards</th>
                            <th class="text-right">Pure Sales (Rs.)</th>
                            <th class="text-center">Fee %</th>
                            <th class="text-right">Bank Fee (Rs.)</th>
                            <th class="text-right">Net Expected (Rs.)</th>
                            <th class="text-right">Paid / Recv (Rs.)</th>
                            <th class="text-right">Balance Due (Rs.)</th>
                            <th class="text-center">Status</th>
                            <th class="text-center" style="width:80px;">Swipes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($settlements as $s): ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td class="text-center font-weight-bold">
                                <?php echo date('d-M-Y', strtotime($s['settlement_date'])); ?>
                            </td>
                            <td class="font-weight-bold">
                                <i class="fas fa-credit-card text-info mr-1"></i>
                                <?php echo htmlspecialchars($s['machine_name']); ?>
                            </td>
                            <td class="text-center font-weight-bold text-monospace">
                                <?php echo htmlspecialchars($s['batch_no']); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-light border"><?php echo intval($s['no_of_cards']); ?></span>
                            </td>
                            <td class="text-right font-weight-bold">
                                <?php echo number_format(floatval($s['amount']), 2); ?>
                            </td>
                            <td class="text-center small text-muted">
                                <?php echo number_format(floatval($s['charges_percentage']), 2); ?>%
                            </td>
                            <td class="text-right text-danger">
                                -<?php echo number_format(floatval($s['service_charges']), 2); ?>
                            </td>
                            <td class="text-right font-weight-bold">
                                <?php echo number_format(floatval($s['net_amount']), 2); ?>
                            </td>
                            <td class="text-right font-weight-bold text-success">
                                <?php echo number_format(floatval($s['paid_amount']), 2); ?>
                            </td>
                            <td class="text-right font-weight-bold <?php echo ($s['calculated_due'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                <?php echo number_format($s['calculated_due'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php if ($s['payment_status'] === 'Paid'): ?>
                                    <span class="badge-status-paid"><i class="fas fa-check-circle mr-1"></i> Paid</span>
                                <?php elseif ($s['payment_status'] === 'Partial'): ?>
                                    <span class="badge-status-partial"><i class="fas fa-adjust mr-1"></i> Partial</span>
                                <?php else: ?>
                                    <span class="badge-status-unpaid"><i class="fas fa-times-circle mr-1"></i> Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-primary btn-sm px-2 py-0" 
                                        onclick="viewBatchSwipes(<?php echo $s['card_machine_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['batch_no'])); ?>', '<?php echo date('d-M-Y', strtotime($s['settlement_date'])); ?>')" 
                                        title="View Underlying Swipes">
                                    <i class="fas fa-list-ol mr-1"></i> Swipes
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="5" class="text-right text-uppercase">Totals:</td>
                            <td class="text-right text-primary">Rs. <?php echo number_format($total_gross, 2); ?></td>
                            <td></td>
                            <td class="text-right text-danger">-Rs. <?php echo number_format($total_charges, 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($total_net, 2); ?></td>
                            <td class="text-right text-success">Rs. <?php echo number_format($total_paid, 2); ?></td>
                            <td class="text-right text-danger">Rs. <?php echo number_format($total_due, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal: Receive Bank Deposit (Master Receipt Voucher) -->
<div class="modal fade" id="receiveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-university mr-2"></i> Receive Bank Settlement Deposit
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="receiveDepositForm">
                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle mr-1"></i> This records money credited by the bank for terminal batches into your station's Bank Master account.
                    </div>

                    <!-- Pre-filled Settlement Filter Range Context -->
                    <input type="hidden" name="filter_from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <input type="hidden" name="filter_to_date" value="<?php echo htmlspecialchars($toDate); ?>">

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-muted small mb-1">POS Card Machine <span class="text-danger">*</span></label>
                        <?php if ($machineId > 0): ?>
                            <input type="text" class="form-control form-control-sm font-weight-bold bg-light" value="<?php echo htmlspecialchars($selected_machine_name); ?>" readonly>
                            <input type="hidden" name="card_machine_id" id="deposit_card_machine_id" value="<?php echo $machineId; ?>">
                        <?php else: ?>
                            <select name="card_machine_id" id="deposit_card_machine_id" class="form-control form-control-sm font-weight-bold" required>
                                <option value="">Select Card Machine...</option>
                                <?php foreach ($all_machines as $m): ?>
                                    <option value="<?php echo $m['id']; ?>">
                                        <?php echo htmlspecialchars($m['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-row mb-3">
                        <div class="col-md-6">
                            <label class="font-weight-bold text-muted small mb-1">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" id="deposit_payment_date" class="form-control form-control-sm font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="text-muted">Date credited in bank</small>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold text-muted small mb-1">Settlement Date Range</label>
                            <input type="text" class="form-control form-control-sm bg-light" value="<?php 
                                if (!empty($fromDate) && !empty($toDate)) {
                                    echo date('d-M-y', strtotime($fromDate)) . ' to ' . date('d-M-y', strtotime($toDate));
                                } elseif (!empty($fromDate)) {
                                    echo 'From ' . date('d-M-y', strtotime($fromDate));
                                } else {
                                    echo 'All Open Batches';
                                }
                            ?>" readonly>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-muted small mb-1">Destination Bank Account (Master) <span class="text-danger">*</span></label>
                        <select name="bank_id" id="deposit_bank_id" class="form-control form-control-sm font-weight-bold" required>
                            <option value="">Select Destination Bank Account...</option>
                            <?php foreach ($all_banks as $b): ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['name'] . ' — A/C: ' . $b['account_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-muted small mb-1 d-flex justify-content-between">
                            <span>Amount Received (Rs.) <span class="text-danger">*</span></span>
                            <span class="text-danger font-weight-bold">Max Due: Rs. <span id="maxDueLabel"><?php echo number_format($total_due, 2); ?></span></span>
                        </label>
                        <input type="number" step="0.01" min="0.01" max="<?php echo $total_due > 0 ? $total_due : '99999999'; ?>" name="amount" id="deposit_amount" class="form-control font-weight-bold text-primary" style="font-size:1.15rem;" value="<?php echo ($total_due > 0) ? number_format($total_due, 2, '.', '') : ''; ?>" required>
                        <small class="text-muted">Pre-filled with balance due. You can enter a partial amount.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-muted small mb-1">Transaction Ref / Advice / Cheque #</label>
                        <input type="text" name="transaction_ref" class="form-control form-control-sm" placeholder="e.g. CR-ALF-89201 or Online Ref">
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-muted small mb-1">Remarks</label>
                        <textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitDeposit" class="btn btn-primary btn-sm font-weight-bold px-3">
                        <i class="fas fa-check-circle mr-1"></i> Save &amp; Record Deposit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: View Underlying Swipes for Batch -->
<div class="modal fade" id="swipesModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-list-ol mr-2"></i> Individual Card Swipes for Batch #<span id="swipeModalBatchNo">—</span>
                    <small class="text-white-50 ml-2">(<span id="swipeModalDate">—</span>)</small>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead class="thead-dark">
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th class="text-center">Sale Date</th>
                                <th class="text-center">Trace #</th>
                                <th>Nozzle</th>
                                <th>Item / Fuel</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Rate</th>
                                <th class="text-right">Swipe Pure Sale (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody id="swipesModalBody">
                            <!-- Populated via AJAX -->
                        </tbody>
                        <tfoot class="font-weight-bold bg-light">
                            <tr>
                                <td colspan="7" class="text-right text-uppercase">Total Pure Swipes:</td>
                                <td class="text-right text-primary font-weight-bold" id="swipeTotalPure">Rs. 0.00</td>
                            </tr>
                            <tr class="bg-white text-muted small">
                                <td colspan="8" class="text-center py-2 font-weight-normal">
                                    <i class="fas fa-info-circle text-primary mr-1"></i> Bank service charges are deducted on the batch settlement total (e.g. on Total Rs. 400.00), not on individual swipe transactions.
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Close
                </button>
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
var totalDue = <?php echo floatval($total_due); ?>;

$(document).ready(function() {
    <?php if ($isSearched): ?>
    $('#settlementsTable').DataTable({
        "order": [[1, "desc"]],
        "pageLength": 25,
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search settlement batches..."
        }
    });
    <?php endif; ?>

    // Handle Receive Deposit Form Submission
    $('#receiveDepositForm').on('submit', function(e) {
        e.preventDefault();

        var enteredAmt = parseFloat($('#deposit_amount').val()) || 0;
        var machId     = parseInt($('#deposit_card_machine_id').val()) || 0;
        var bankId     = parseInt($('#deposit_bank_id').val()) || 0;

        if (machId <= 0) {
            Swal.fire('Error', 'Please select a valid POS Card Machine.', 'error');
            return;
        }
        if (bankId <= 0) {
            Swal.fire('Error', 'Please select a destination bank account from Bank Master.', 'error');
            return;
        }
        if (enteredAmt <= 0) {
            Swal.fire('Error', 'Deposit amount must be greater than zero.', 'error');
            return;
        }
        if (totalDue > 0 && enteredAmt > totalDue) {
            Swal.fire({
                icon: 'warning',
                title: 'Overpayment Blocked',
                text: 'Deposit amount (Rs. ' + enteredAmt.toLocaleString('en-US', {minimumFractionDigits: 2}) + ') cannot exceed the outstanding balance of Rs. ' + totalDue.toLocaleString('en-US', {minimumFractionDigits: 2}) + '.'
            });
            return;
        }

        $('#btnSubmitDeposit').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Recording...');

        $.ajax({
            url: 'process-card-settlement.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                $('#btnSubmitDeposit').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Save & Record Deposit');
                if (resp.status === 'success') {
                    $('#receiveModal').modal('hide');
                    Swal.fire({
                        title: 'Deposit Recorded!',
                        html: resp.message,
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonColor: '#04204e',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '<i class="fas fa-print mr-1"></i> Print Voucher',
                        cancelButtonText: 'Continue'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.open('generate-pdf-card-receipt.php?payment_id=' + resp.payment_id, '_blank');
                        }
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', resp.message || 'Failed to record deposit.', 'error');
                }
            },
            error: function(xhr, status, error) {
                $('#btnSubmitDeposit').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Save & Record Deposit');
                Swal.fire('Error', 'Server communication error: ' + error, 'error');
            }
        });
    });
});

function openReceiveModal() {
    $('#receiveModal').modal('show');
}

function viewBatchSwipes(machineId, batchNo, dateStr) {
    $('#swipeModalBatchNo').text(batchNo);
    $('#swipeModalDate').text(dateStr);
    var $tbody = $('#swipesModalBody').html('<tr><td colspan="10" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i> Loading swipes...</td></tr>');
    $('#swipeTotalPure').text('Rs. 0.00');
    $('#swipeTotalFee').text('-Rs. 0.00');
    $('#swipeTotalNet').text('Rs. 0.00');
    $('#swipesModal').modal('show');

    $.ajax({
        url: 'card-sale-receivables.php',
        type: 'GET',
        data: {
            action: 'get_swipes',
            machine_id: machineId,
            batch_no: batchNo
        },
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'success') {
                $tbody.empty();
                if (resp.swipes.length === 0) {
                    $tbody.html('<tr><td colspan="10" class="text-center py-4 text-muted">No individual swipe records linked to this batch number.</td></tr>');
                    return;
                }

                $.each(resp.swipes, function(idx, s) {
                    var tr = '<tr>' +
                        '<td class="text-center">' + (idx + 1) + '</td>' +
                        '<td class="text-center">' + (s.sale_date || '—') + '</td>' +
                        '<td class="text-center font-weight-bold text-monospace">' + (s.trace_no || '—') + '</td>' +
                        '<td>' + (s.nozzle_name || '—') + '</td>' +
                        '<td>' + (s.item_name || '—') + '</td>' +
                        '<td class="text-right">' + s.quantity.toFixed(2) + '</td>' +
                        '<td class="text-right">' + s.rate.toFixed(2) + '</td>' +
                        '<td class="text-right font-weight-bold text-primary">Rs. ' + s.amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                    '</tr>';
                    $tbody.append(tr);
                });

                $('#swipeTotalPure').text('Rs. ' + resp.total_amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            } else {
                $tbody.html('<tr><td colspan="8" class="text-center text-danger py-4">' + (resp.message || 'Error loading swipes.') + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            $tbody.html('<tr><td colspan="10" class="text-center text-danger py-4">Failed to load swipes: ' + error + '</td></tr>');
        }
    });
}
</script>

</body>
</html>
