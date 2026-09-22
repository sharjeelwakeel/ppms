<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// Self-healing schema checks
$c1 = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_product_payments'");
if (!$c1 || mysqli_num_rows($c1) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_product_payments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `receipt_no` VARCHAR(64) NOT NULL,
      `receipt_date` DATE DEFAULT NULL,
      `customer_id` INT(11) NOT NULL,
      `payment_date` DATE NOT NULL,
      `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `payment_mode` ENUM('Cash', 'Online Payment', 'Cheque') NOT NULL DEFAULT 'Cash',
      `bank_id` INT(11) DEFAULT NULL,
      `transaction_ref` VARCHAR(128) DEFAULT NULL,
      `cheque_no` VARCHAR(64) DEFAULT NULL,
      `cheque_date` DATE DEFAULT NULL,
      `filter_from_date` DATE DEFAULT NULL,
      `filter_to_date` DATE DEFAULT NULL,
      `filter_vehicle_number` VARCHAR(64) DEFAULT NULL,
      `remarks` TEXT DEFAULT NULL,
      `created_by` INT(11) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_cust` (`customer_id`),
      KEY `idx_pay_date` (`payment_date`),
      KEY `idx_bank` (`bank_id`),
      KEY `idx_del` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

$c2 = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_product_payment_allocations'");
if (!$c2 || mysqli_num_rows($c2) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_product_payment_allocations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_id` INT(11) NOT NULL,
      `invoice_id` INT(11) NOT NULL,
      `allocated_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_pmt` (`payment_id`),
      KEY `idx_inv` (`invoice_id`),
      KEY `idx_del` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// Fetch active customers for filter dropdown
$cust_res = mysqli_query($connection, "SELECT id, name, phone, other_rate FROM tbl_customers WHERE deleted_at IS NULL ORDER BY name ASC");
$all_customers = [];
if ($cust_res) {
    while ($c = mysqli_fetch_assoc($cust_res)) {
        $all_customers[] = $c;
    }
}

// Fetch active banks for payment modal
$banks_res = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC");
$all_banks = [];
if ($banks_res) {
    while ($b = mysqli_fetch_assoc($banks_res)) {
        $all_banks[] = $b;
    }
}

// Extract filter parameters
$fromDate      = trim($_GET['from_date'] ?? '');
$toDate        = trim($_GET['to_date'] ?? '');
$customerId    = intval($_GET['customer_id'] ?? 0);
$vehicleNum    = trim($_GET['vehicle_number'] ?? '');
$statusFilter  = trim($_GET['status'] ?? 'outstanding'); // 'outstanding', 'unpaid', 'partial', 'paid', 'all'

$isSearched = (
    !empty($fromDate) ||
    !empty($toDate) ||
    $customerId > 0 ||
    !empty($vehicleNum) ||
    (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'outstanding') ||
    isset($_GET['search'])
);

$slips = [];
$total_billed   = 0.00;
$total_paid     = 0.00;
$total_due      = 0.00;
$unpaid_count   = 0;
$partial_count  = 0;
$paid_count     = 0;
$customer_lifetime_due = 0.00;
$selected_customer_name = '';

// Calculate Lifetime Outstanding Balance if customer selected
if ($customerId > 0) {
    foreach ($all_customers as $ac) {
        if ($ac['id'] == $customerId) {
            $selected_customer_name = $ac['name'];
            break;
        }
    }
    $q_life = mysqli_query($connection, "SELECT SUM(charge_amount - paid_amount) AS lifetime_due 
                                          FROM tbl_lubricant_sale_invoices 
                                          WHERE customer_id = '$customerId' 
                                            AND payment_type = 'Credit'
                                            AND slip_type = 'Permanent Slip'
                                            AND charge_amount > 0
                                            AND payment_status != 'Paid'
                                            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    if ($q_life && ($r_life = mysqli_fetch_assoc($q_life))) {
        $customer_lifetime_due = floatval($r_life['lifetime_due'] ?? 0.00);
    }
}

if ($isSearched) {
    $where_clauses = [
        "(inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')",
        "inv.payment_type = 'Credit'",
        "inv.slip_type = 'Permanent Slip'",
        "inv.charge_amount > 0"
    ];

    if (!empty($fromDate) && !empty($toDate)) {
        $f_safe = mysqli_real_escape_string($connection, $fromDate);
        $t_safe = mysqli_real_escape_string($connection, $toDate);
        $where_clauses[] = "inv.slip_date BETWEEN '$f_safe' AND '$t_safe'";
    } elseif (!empty($fromDate)) {
        $f_safe = mysqli_real_escape_string($connection, $fromDate);
        $where_clauses[] = "inv.slip_date >= '$f_safe'";
    } elseif (!empty($toDate)) {
        $t_safe = mysqli_real_escape_string($connection, $toDate);
        $where_clauses[] = "inv.slip_date <= '$t_safe'";
    }

    if ($customerId > 0) {
        $where_clauses[] = "inv.customer_id = '$customerId'";
    }

    if (!empty($vehicleNum)) {
        $v_safe = mysqli_real_escape_string($connection, $vehicleNum);
        $where_clauses[] = "inv.vehicle_number LIKE '%$v_safe%'";
    }

    if ($statusFilter === 'outstanding') {
        $where_clauses[] = "(inv.payment_status != 'Paid' AND (inv.charge_amount - inv.paid_amount) > 0.00)";
    } elseif ($statusFilter === 'unpaid') {
        $where_clauses[] = "inv.payment_status = 'Unpaid' AND inv.paid_amount = 0.00";
    } elseif ($statusFilter === 'partial') {
        $where_clauses[] = "inv.payment_status = 'Partial' AND inv.paid_amount > 0.00 AND (inv.charge_amount - inv.paid_amount) > 0.00";
    } elseif ($statusFilter === 'paid') {
        $where_clauses[] = "inv.payment_status = 'Paid'";
    }

    $where_sql = implode(' AND ', $where_clauses);

    $query_slips = "SELECT inv.*,
                           c.name AS customer_name,
                           c.phone AS customer_phone,
                           c.other_rate AS customer_tariff,
                           (SELECT GROUP_CONCAT(CONCAT(p.name, ' (', s.quantity, ' units)') SEPARATOR ', ')
                            FROM tbl_lubricant_sales s
                            JOIN tbl_lubricant_products p ON s.product_id = p.id
                            WHERE s.invoice_id = inv.id AND (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
                           ) AS products_summary
                    FROM tbl_lubricant_sale_invoices inv
                    LEFT JOIN tbl_customers c ON (inv.customer_id = c.id)
                    WHERE $where_sql
                    ORDER BY inv.slip_date ASC, inv.id ASC";

    $res_slips = mysqli_query($connection, $query_slips);

    if ($res_slips) {
        while ($row = mysqli_fetch_assoc($res_slips)) {
            $chg  = floatval($row['charge_amount']);
            $paid = floatval($row['paid_amount']);
            $due  = max(0.00, round($chg - $paid, 2));

            $row['calc_due'] = $due;

            $total_billed += $chg;
            $total_paid   += $paid;
            $total_due    += $due;

            if ($due <= 0.00 || $row['payment_status'] === 'Paid') {
                $paid_count++;
            } elseif ($paid > 0.00) {
                $partial_count++;
            } else {
                $unpaid_count++;
            }

            $slips[] = $row;
        }
    }
}
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.6">
    <title>PPMS - Product Credit Sales Receivables</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: var(--primary-gradient);
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
        
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-color);
            height: 100%;
            transition: transform 0.15s ease-in-out;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card.stat-billed { border-left-color: #04204e; }
        .stat-card.stat-paid   { border-left-color: #28a745; }
        .stat-card.stat-due    { border-left-color: #dc3545; }
        .stat-card.stat-life   { border-left-color: #fd7e14; }
        .stat-label { font-size: 11.5px; text-transform: uppercase; font-weight: 700; color: #6c757d; margin-bottom: 4px; letter-spacing: 0.5px; }
        .stat-val   { font-size: 20px; font-weight: 900; line-height: 1.2; }

        .filter-card {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 22px;
        }

        .data-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .data-card-header {
            background: var(--primary-gradient);
            color: #fff;
            padding: 12px 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table thead th {
            background: #f1f5f9;
            color: #04204e;
            font-size: 11.5px;
            font-weight: 800;
            text-align: center;
            vertical-align: middle;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        .table tbody td {
            font-size: 12px;
            vertical-align: middle;
        }

        .status-badge {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-block;
        }
        .status-unpaid  { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .status-partial { background: #fff8e1; color: #b78103; border: 1px solid #ffe082; }
        .status-paid    { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        .mode-selector label {
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            text-align: center;
            width: 100%;
            transition: all 0.2s ease;
        }
        .mode-selector input[type="radio"]:checked + label {
            border-color: #04204e;
            background: #04204e;
            color: #fff;
            box-shadow: 0 3px 10px rgba(4,32,78,0.2);
        }
        .mode-selector input[type="radio"] { display: none; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../include/navbar.php'; ?>

    <div class="container-fluid px-lg-5 pt-4 pb-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-oil-can mr-2 text-warning"></i> Product Credit Sales Receivables</h4>
                <small style="opacity:0.85;">Manage customer outstanding product invoices, track debt, and record multi-mode payments with auto-allocation.</small>
            </div>
            <div>
                <a href="product-payment-history.php" class="btn btn-outline-light btn-sm font-weight-bold mr-2">
                    <i class="fas fa-history mr-1"></i> Payment Receipts &amp; History
                </a>
                <?php if ($customerId > 0 && $total_due > 0): ?>
                <button type="button" class="btn btn-warning btn-sm font-weight-bold shadow-sm" onclick="openPaymentModal(<?php echo $customerId; ?>, '<?php echo addslashes($selected_customer_name); ?>', <?php echo $total_due; ?>, <?php echo $customer_lifetime_due; ?>)">
                    <i class="fas fa-cash-register mr-1"></i> Receive Payment (Rs. <?php echo number_format($total_due, 2); ?>)
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-card">
            <form action="product-receivables.php" method="GET" class="form-row align-items-end">
                <input type="hidden" name="search" value="1">
                <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                    <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-alt mr-1 text-primary"></i> From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                    <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-check mr-1 text-primary"></i> To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 mb-2 mb-lg-0">
                    <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-user-tag mr-1 text-primary"></i> Customer Account</label>
                    <select name="customer_id" class="form-control form-control-sm font-weight-bold">
                        <option value="">-- All Customers --</option>
                        <?php foreach ($all_customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>" <?php echo ($customerId == $cust['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['name'] . ' (' . ($cust['other_rate'] ?? 'Credit') . ' Rate)'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                    <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-truck mr-1 text-secondary"></i> Vehicle #</label>
                    <input type="text" name="vehicle_number" class="form-control form-control-sm font-weight-bold text-uppercase" placeholder="e.g. LEA-1234" value="<?php echo htmlspecialchars($vehicleNum); ?>">
                </div>
                <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                    <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-filter mr-1 text-primary"></i> Payment Status</label>
                    <select name="status" class="form-control form-control-sm font-weight-bold">
                        <option value="outstanding" <?php echo ($statusFilter === 'outstanding') ? 'selected' : ''; ?>>Outstanding (Unpaid &amp; Partial)</option>
                        <option value="unpaid" <?php echo ($statusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid Only</option>
                        <option value="partial" <?php echo ($statusFilter === 'partial') ? 'selected' : ''; ?>>Partial Only</option>
                        <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Paid Only</option>
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Slips</option>
                    </select>
                </div>
                <div class="col-xl-1 col-lg-1 col-md-4 col-sm-12">
                    <div class="btn-group w-100">
                        <button type="submit" class="btn btn-primary btn-sm font-weight-bold shadow-sm" title="Search">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="product-receivables.php" class="btn btn-secondary btn-sm" title="Reset Filters">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($isSearched): ?>
        <!-- KPI Summary Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                <div class="stat-card stat-billed">
                    <div class="stat-label"><i class="fas fa-file-invoice mr-1 text-primary"></i> Filter Billed Total</div>
                    <div class="stat-val text-dark">Rs. <?php echo number_format($total_billed, 2); ?></div>
                    <small class="text-muted"><?php echo count($slips); ?> invoice slip(s) in filter</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                <div class="stat-card stat-paid">
                    <div class="stat-label"><i class="fas fa-check-circle mr-1 text-success"></i> Filter Paid Amount</div>
                    <div class="stat-val text-success">Rs. <?php echo number_format($total_paid, 2); ?></div>
                    <small class="text-muted"><?php echo $paid_count; ?> paid, <?php echo $partial_count; ?> partial</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                <div class="stat-card stat-due">
                    <div class="stat-label"><i class="fas fa-exclamation-circle mr-1 text-danger"></i> Current Filter Balance Due</div>
                    <div class="stat-val text-danger">Rs. <?php echo number_format($total_due, 2); ?></div>
                    <small class="text-muted"><?php echo ($unpaid_count + $partial_count); ?> open slip(s) remaining</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                <div class="stat-card stat-life">
                    <div class="stat-label"><i class="fas fa-chart-line mr-1 text-warning"></i> Customer Lifetime Product Due</div>
                    <div class="stat-val text-warning">
                        <?php if ($customerId > 0): ?>
                            Rs. <?php echo number_format($customer_lifetime_due, 2); ?>
                        <?php else: ?>
                            <span style="font-size:15px; color:#999;">Select Customer</span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">
                        <?php echo ($customerId > 0) ? htmlspecialchars($selected_customer_name) : 'All open slips across all time'; ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Invoices Data Table -->
        <div class="data-card">
            <div class="data-card-header">
                <div>
                    <i class="fas fa-list-alt mr-2 text-warning"></i> Filtered Product Credit Invoices
                    <span class="badge badge-light text-primary ml-2 font-weight-bold" style="font-size:12px;"><?php echo count($slips); ?> Slips</span>
                </div>
                <div>
                    <?php if ($customerId > 0 && $total_due > 0): ?>
                    <button type="button" class="btn btn-warning btn-xs font-weight-bold py-1 px-3 shadow-sm" onclick="openPaymentModal(<?php echo $customerId; ?>, '<?php echo addslashes($selected_customer_name); ?>', <?php echo $total_due; ?>, <?php echo $customer_lifetime_due; ?>)">
                        <i class="fas fa-cash-register mr-1"></i> Pay Filtered Due (Rs. <?php echo number_format($total_due, 2); ?>)
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped text-center mb-0" id="receivablesTable">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th>Slip #</th>
                                <th>Slip Date</th>
                                <th>Customer Account</th>
                                <th>Vehicle #</th>
                                <th style="text-align:left; min-width:200px;">Products Summary</th>
                                <th>Items</th>
                                <th>Quantity</th>
                                <th>Invoice Amount</th>
                                <th>Customer Charge</th>
                                <th>Paid Amount</th>
                                <th>Balance Due</th>
                                <th>Status</th>
                                <th style="width:70px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($slips)): 
                                $idx = 0;
                                foreach ($slips as $s): 
                                    $idx++;
                                    $sChg  = floatval($s['charge_amount']);
                                    $sPaid = floatval($s['paid_amount']);
                                    $sDue  = floatval($s['calc_due']);
                                    $cId   = intval($s['customer_id']);
                                    $cName = $s['customer_name'] ?? '—';
                            ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?php echo $idx; ?></td>
                                <td class="font-weight-bold text-primary">
                                    <?php echo htmlspecialchars($s['slip_no'] ?: $s['invoice_no']); ?>
                                </td>
                                <td><?php echo date('d-m-Y', strtotime($s['slip_date'] ?: $s['date'])); ?></td>
                                <td class="font-weight-bold text-dark">
                                    <?php echo htmlspecialchars($cName); ?>
                                </td>
                                <td class="font-weight-bold text-secondary text-uppercase">
                                    <?php echo htmlspecialchars($s['vehicle_number'] ?: '—'); ?>
                                </td>
                                <td class="text-left small">
                                    <?php echo htmlspecialchars($s['products_summary'] ?: 'Standard Products'); ?>
                                </td>
                                <td><span class="badge badge-light border"><?php echo intval($s['total_items']); ?></span></td>
                                <td class="font-weight-bold"><?php echo intval($s['total_quantity']); ?></td>
                                <td class="font-weight-bold text-secondary">
                                    Rs. <?php echo number_format(floatval($s['total_amount']), 2); ?>
                                </td>
                                <td class="font-weight-bold text-dark">
                                    Rs. <?php echo number_format($sChg, 2); ?>
                                </td>
                                <td class="font-weight-bold text-success">
                                    Rs. <?php echo number_format($sPaid, 2); ?>
                                </td>
                                <td class="font-weight-bold text-danger" style="font-size: 13.5px;">
                                    Rs. <?php echo number_format($sDue, 2); ?>
                                </td>
                                <td>
                                    <?php if ($sDue <= 0.00 || $s['payment_status'] === 'Paid'): ?>
                                        <span class="status-badge status-paid">
                                            <i class="fas fa-check-circle mr-1"></i> Paid
                                        </span>
                                    <?php elseif ($sPaid > 0.00): ?>
                                        <span class="status-badge status-partial" title="Paid: Rs. <?php echo number_format($sPaid, 2); ?>">
                                            <i class="fas fa-adjust mr-1"></i> Partial
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-unpaid">
                                            <i class="fas fa-times-circle mr-1"></i> Unpaid
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sDue > 0.00): ?>
                                        <button type="button" class="btn btn-xs btn-primary py-1 px-2 font-weight-bold shadow-sm" onclick="openPaymentModal(<?php echo $cId; ?>, '<?php echo addslashes($cName); ?>', <?php echo $sDue; ?>, <?php echo $customer_lifetime_due ?: $sDue; ?>)">
                                            <i class="fas fa-hand-holding-usd mr-1"></i> Pay
                                        </button>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-check"></i> Settled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            endif;
                            ?>
                        </tbody>
                        <?php if (!empty($slips)): ?>
                        <tfoot class="bg-light font-weight-bold" style="font-size:13px;">
                            <tr>
                                <td colspan="9" class="text-right">TOTALS:</td>
                                <td class="text-dark">Rs. <?php echo number_format($total_billed, 2); ?></td>
                                <td class="text-success">Rs. <?php echo number_format($total_paid, 2); ?></td>
                                <td class="text-danger" style="font-size: 14px;">Rs. <?php echo number_format($total_due, 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                <h5 class="text-dark font-weight-bold">Select Filter Criteria Above</h5>
                <p class="text-muted small mb-3">Please select a Customer Account or Date Range above and click <strong>Filter</strong> to load open credit invoices and outstanding balances.</p>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Payment Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius:12px; overflow:hidden;">
                <div class="modal-header text-white" style="background: var(--primary-gradient);">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-cash-register mr-2 text-warning"></i> Receive Product Payment
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="paymentForm">
                    <input type="hidden" name="customer_id" id="modalCustomerId">
                    <input type="hidden" name="filter_from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
                    <input type="hidden" name="filter_to_date" value="<?php echo htmlspecialchars($toDate); ?>">
                    <input type="hidden" name="filter_vehicle_number" value="<?php echo htmlspecialchars($vehicleNum); ?>">

                    <div class="modal-body p-4">
                        <!-- Customer Balance Banner -->
                        <div class="p-3 mb-4 rounded d-flex justify-content-between align-items-center" style="background:#e8f0fe; border-left: 5px solid #04204e;">
                            <div>
                                <span class="text-muted small text-uppercase font-weight-bold">Customer Account:</span>
                                <h5 class="font-weight-bold mb-0" id="modalCustomerName" style="color:#04204e;">—</h5>
                            </div>
                            <div class="text-right">
                                <span class="text-muted small text-uppercase font-weight-bold">Outstanding Balance Due:</span>
                                <h4 class="font-weight-bold text-danger mb-0" id="modalDueText">Rs. 0.00</h4>
                            </div>
                        </div>

                        <!-- Payment Date & Amount -->
                        <div class="form-row">
                            <div class="col-md-6 mb-3">
                                <label class="font-weight-bold small text-muted"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Payment Date <span class="text-danger">*</span></label>
                                <input type="date" name="payment_date" id="modalPaymentDate" class="form-control font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="font-weight-bold small text-muted"><i class="fas fa-money-bill-wave mr-1 text-success"></i> Amount Received (Rs.) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="modalAmount" class="form-control font-weight-bold text-danger" style="font-size:18px;" placeholder="0.00" required>
                                <small class="text-muted" id="modalMaxHelp">Maximum allowed: Rs. 0.00</small>
                            </div>
                        </div>

                        <!-- Receipt Date & Receipt No -->
                        <div class="form-row">
                            <div class="col-md-6 mb-3">
                                <label class="font-weight-bold small text-muted"><i class="fas fa-calendar-check mr-1 text-primary"></i> Receipt Date <span class="text-danger">*</span></label>
                                <input type="date" name="receipt_date" id="modalReceiptDate" class="form-control font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="font-weight-bold small text-muted"><i class="fas fa-receipt mr-1 text-primary"></i> Receipt No</label>
                                <input type="text" name="receipt_no" id="modalReceiptNo" class="form-control font-weight-bold text-monospace" placeholder="e.g. PRCP-0001 (Leave blank for auto)">
                                <small class="text-muted">Optional custom/book receipt reference</small>
                            </div>
                        </div>

                        <!-- Payment Mode Selection -->
                        <div class="mb-3">
                            <label class="font-weight-bold small text-muted mb-2"><i class="fas fa-credit-card mr-1 text-primary"></i> Select Payment Mode <span class="text-danger">*</span></label>
                            <div class="row mode-selector">
                                <div class="col-4">
                                    <input type="radio" name="payment_mode" id="modeCash" value="Cash" checked onchange="toggleModeFields()">
                                    <label for="modeCash"><i class="fas fa-money-bill-alt mr-1"></i> Cash</label>
                                </div>
                                <div class="col-4">
                                    <input type="radio" name="payment_mode" id="modeOnline" value="Online Payment" onchange="toggleModeFields()">
                                    <label for="modeOnline"><i class="fas fa-university mr-1"></i> Online / Bank</label>
                                </div>
                                <div class="col-4">
                                    <input type="radio" name="payment_mode" id="modeCheque" value="Cheque" onchange="toggleModeFields()">
                                    <label for="modeCheque"><i class="fas fa-money-check mr-1"></i> Cheque</label>
                                </div>
                            </div>
                        </div>

                        <!-- Online Bank Fields -->
                        <div id="onlineFields" style="display:none;" class="p-3 mb-3 bg-light rounded border">
                            <div class="form-row">
                                <div class="col-md-6 mb-2">
                                    <label class="font-weight-bold small text-muted"><i class="fas fa-university mr-1"></i> Received in Bank Account <span class="text-danger">*</span></label>
                                    <select name="bank_id" id="modalBankId" class="form-control form-control-sm font-weight-bold">
                                        <option value="">-- Select Bank --</option>
                                        <?php foreach ($all_banks as $bk): ?>
                                            <option value="<?php echo $bk['id']; ?>">
                                                <?php echo htmlspecialchars($bk['name']); ?> (<?php echo htmlspecialchars($bk['account_number']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="font-weight-bold small text-muted"><i class="fas fa-receipt mr-1"></i> Transaction Reference / UTR</label>
                                    <input type="text" name="transaction_ref" class="form-control form-control-sm" placeholder="e.g. TXN-12345678">
                                </div>
                            </div>
                        </div>

                        <!-- Cheque Fields -->
                        <div id="chequeFields" style="display:none;" class="p-3 mb-3 bg-light rounded border">
                            <div class="form-row">
                                <div class="col-md-6 mb-2">
                                    <label class="font-weight-bold small text-muted"><i class="fas fa-money-check mr-1"></i> Cheque Number <span class="text-danger">*</span></label>
                                    <input type="text" name="cheque_no" id="modalChequeNo" class="form-control form-control-sm" placeholder="e.g. CHQ-998877">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="font-weight-bold small text-muted"><i class="fas fa-calendar-day mr-1"></i> Cheque Date</label>
                                    <input type="date" name="cheque_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="mb-2">
                            <label class="font-weight-bold small text-muted"><i class="fas fa-comment-alt mr-1"></i> Remarks / Notes</label>
                            <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional payment note or receipt reference">
                        </div>
                    </div>

                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" id="submitPaymentBtn" class="btn btn-sm btn-primary font-weight-bold shadow-sm px-4">
                            <i class="fas fa-save mr-1"></i> Record Payment &amp; Settle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    var currentDue = 0.00;

    $(document).ready(function() {
        if ($('#receivablesTable').length) {
            $('#receivablesTable').DataTable({
                "order": [[ 2, "asc" ]],
                "pageLength": 25,
                "autoWidth": false,
                "language": {
                    "emptyTable": "No outstanding product credit invoices found matching criteria."
                }
            });
        }
    });

    function toggleModeFields() {
        var mode = $('input[name="payment_mode"]:checked').val();
        if (mode === 'Online Payment') {
            $('#onlineFields').slideDown(200);
            $('#chequeFields').slideUp(200);
            $('#modalBankId').prop('required', true);
            $('#modalChequeNo').prop('required', false);
        } else if (mode === 'Cheque') {
            $('#chequeFields').slideDown(200);
            $('#onlineFields').slideUp(200);
            $('#modalChequeNo').prop('required', true);
            $('#modalBankId').prop('required', false);
        } else {
            $('#onlineFields').slideUp(200);
            $('#chequeFields').slideUp(200);
            $('#modalBankId').prop('required', false);
            $('#modalChequeNo').prop('required', false);
        }
    }

    function openPaymentModal(customerId, customerName, dueAmount, lifetimeDue) {
        currentDue = parseFloat(dueAmount) || 0.00;
        if (currentDue <= 0.00) {
            Swal.fire('No Due Balance', 'This customer has no outstanding product balance to pay.', 'info');
            return;
        }

        $('#modalCustomerId').val(customerId);
        $('#modalCustomerName').text(customerName);
        $('#modalDueText').text('Rs. ' + currentDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        $('#modalAmount').val(currentDue.toFixed(2)).attr('max', currentDue.toFixed(2));
        $('#modalMaxHelp').text('Maximum allowed: Rs. ' + currentDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        var today = new Date().toISOString().split('T')[0];
        $('#modalPaymentDate').val(today);
        $('#modalReceiptDate').val(today);
        $('#modalReceiptNo').val('');

        $('#modeCash').prop('checked', true);
        toggleModeFields();

        $('#paymentModal').modal('show');
    }

    $('#paymentForm').on('submit', function(e) {
        e.preventDefault();

        var amt = parseFloat($('#modalAmount').val()) || 0;
        if (amt <= 0) {
            Swal.fire('Invalid Amount', 'Please enter a payment amount greater than zero.', 'warning');
            return;
        }
        if (amt > currentDue) {
            Swal.fire('Overpayment Not Allowed', 'Payment amount cannot exceed the outstanding balance of Rs. ' + currentDue.toFixed(2), 'warning');
            return;
        }

        var mode = $('input[name="payment_mode"]:checked').val();
        if (mode === 'Online Payment' && !$('#modalBankId').val()) {
            Swal.fire('Bank Required', 'Please select the bank account where payment was received.', 'warning');
            return;
        }
        if (mode === 'Cheque' && !$('#modalChequeNo').val().trim()) {
            Swal.fire('Cheque Number Required', 'Please enter the cheque number.', 'warning');
            return;
        }

        $('#submitPaymentBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Processing...');

        $.ajax({
            url: 'process-product-payment.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                $('#submitPaymentBtn').prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Record Payment & Settle');
                if (resp.status === 'success') {
                    $('#paymentModal').modal('hide');
                    Swal.fire({
                        title: 'Payment Recorded!',
                        html: resp.message + '<br><small class="text-muted">Receipt No: <strong>' + resp.receipt_no + '</strong></small>',
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: '<i class="fas fa-file-pdf mr-1"></i> Print Receipt',
                        cancelButtonText: '<i class="fas fa-check mr-1"></i> Done'
                    }).then(function(result) {
                        if (result.isConfirmed) {
                            window.open('generate-pdf-product-receipt.php?payment_id=' + resp.payment_id, '_blank');
                        }
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', resp.message || 'Unable to process payment.', 'error');
                }
            },
            error: function() {
                $('#submitPaymentBtn').prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Record Payment & Settle');
                Swal.fire('Server Error', 'Failed to communicate with the server. Please try again.', 'error');
            }
        });
    });
    </script>
</body>
</html>
