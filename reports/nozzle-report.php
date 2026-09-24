<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

// Check RBAC permission for reports or meter readings
if (!has_permission('reports', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

// Fetch all active nozzles for filter dropdown
$nozzles_q = mysqli_query($connection, "
    SELECT n.id, n.name, n.tank_id, n.item_id, n.start_reading,
           i.name AS item_name, t.tank_name
    FROM tbl_nozzles n
    LEFT JOIN tbl_items i ON n.item_id = i.id
    LEFT JOIN tbl_tanks t ON n.tank_id = t.id
    WHERE (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
      AND n.status = 'Active'
    ORDER BY n.name ASC
");
$all_nozzles = [];
while ($nrow = mysqli_fetch_assoc($nozzles_q)) {
    $all_nozzles[] = $nrow;
}

// Fetch all active shifts for filter dropdown
$shifts_q = mysqli_query($connection, "
    SELECT id, name FROM tbl_shifts 
    WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
    ORDER BY id ASC
");
$all_shifts = [];
while ($srow = mysqli_fetch_assoc($shifts_q)) {
    $all_shifts[] = $srow;
}

// Filter inputs
$nozzleId = intval($_GET['nozzle_id'] ?? 0);
$shiftId  = intval($_GET['shift_id'] ?? 0);
$fromDate = trim($_GET['from_date'] ?? date('Y-m-d'));
$toDate   = trim($_GET['to_date'] ?? date('Y-m-d'));

$selected_nozzle = null;
if ($nozzleId > 0) {
    foreach ($all_nozzles as $n) {
        if ($n['id'] == $nozzleId) {
            $selected_nozzle = $n;
            break;
        }
    }
}

$selected_shift_name = 'All Shifts (Combined)';
if ($shiftId > 0) {
    foreach ($all_shifts as $s) {
        if ($s['id'] == $shiftId) {
            $selected_shift_name = $s['name'];
            break;
        }
    }
}

$isSearched = ($nozzleId > 0);

// Initialize report metric accumulators
$meter_rows = [];
$total_meter_litres  = 0.00;
$total_meter_revenue = 0.00;
$total_test_litres   = 0.00;
$min_opening_meter   = null;
$max_closing_meter   = null;

$cash_rows = [];
$total_cash_litres = 0.00;
$total_cash_amount = 0.00;

$credit_rows = [];
$total_credit_issued_litres = 0.00;
$total_credit_quota_litres  = 0.00;
$total_credit_amount        = 0.00;

$card_rows = [];
$total_card_litres   = 0.00;
$total_card_amount   = 0.00;
$total_card_charges  = 0.00;
$total_card_net      = 0.00;

$expense_rows = [];
$total_nozzle_expenses = 0.00;

if ($isSearched && $selected_nozzle) {
    $from_safe = mysqli_real_escape_string($connection, $fromDate);
    $to_safe   = mysqli_real_escape_string($connection, $toDate);
    $shift_filter = ($shiftId > 0) ? " AND mr.shift_id = '$shiftId'" : "";

    // 1. Fetch Meter Readings for this nozzle
    $sql_mr = "
        SELECT mrd.*, mr.date, mr.shift_id, sh.name AS shift_name,
               st.name AS staff_name
        FROM tbl_meter_reading_details mrd
        JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
        LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
        LEFT JOIN tbl_staff st ON mrd.staff_id = st.id
        WHERE mrd.nozzle_id = '$nozzleId'
          AND mr.date BETWEEN '$from_safe' AND '$to_safe'
          $shift_filter
          AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
        ORDER BY mr.date ASC, mr.shift_id ASC, mr.id ASC
    ";
    $res_mr = mysqli_query($connection, $sql_mr);
    if ($res_mr) {
        while ($row = mysqli_fetch_assoc($res_mr)) {
            $meter_rows[] = $row;
            $net_qty = floatval($row['net_sale']);
            $amt     = floatval($row['amount']);
            $tst     = floatval($row['test_reading']);
            $last_m  = floatval($row['last_reading']);
            $curr_m  = floatval($row['current_reading']);

            $total_meter_litres  += $net_qty;
            $total_meter_revenue += $amt;
            $total_test_litres   += $tst;

            if ($min_opening_meter === null || $last_m < $min_opening_meter) {
                $min_opening_meter = $last_m;
            }
            if ($max_closing_meter === null || $curr_m > $max_closing_meter) {
                $max_closing_meter = $curr_m;
            }
        }
    }

    // 2. Fetch Cash Sales for this nozzle
    $shift_filter_cs = ($shiftId > 0) ? " AND cs.shift_id = '$shiftId'" : "";
    $sql_cs = "
        SELECT cs.*, sh.name AS shift_name, st.name AS staff_name
        FROM tbl_meter_reading_cash_sales cs
        LEFT JOIN tbl_shifts sh ON cs.shift_id = sh.id
        LEFT JOIN tbl_staff st ON cs.staff_id = st.id
        WHERE cs.nozzle_id = '$nozzleId'
          AND cs.sale_date BETWEEN '$from_safe' AND '$to_safe'
          $shift_filter_cs
          AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
        ORDER BY cs.sale_date ASC, cs.shift_id ASC, cs.id ASC
    ";
    $res_cs = mysqli_query($connection, $sql_cs);
    if ($res_cs) {
        while ($row = mysqli_fetch_assoc($res_cs)) {
            $cash_rows[] = $row;
            $total_cash_litres += floatval($row['quantity']);
            $total_cash_amount += floatval($row['amount']);
        }
    }

    // 3. Fetch Credit Sales for this nozzle
    $shift_filter_cr = ($shiftId > 0) ? " AND cr.shift_id = '$shiftId'" : "";
    $sql_cr = "
        SELECT cr.*, sh.name AS shift_name, c.name AS customer_name
        FROM tbl_meter_reading_credit_sales cr
        LEFT JOIN tbl_shifts sh ON cr.shift_id = sh.id
        LEFT JOIN tbl_customers c ON cr.account_number = c.id
        WHERE cr.nozzle_id = '$nozzleId'
          AND (cr.sale_date BETWEEN '$from_safe' AND '$to_safe' 
               OR (cr.sale_date IS NULL AND cr.slip_date BETWEEN '$from_safe' AND '$to_safe'))
          $shift_filter_cr
          AND (cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')
        ORDER BY COALESCE(cr.sale_date, cr.slip_date) ASC, cr.shift_id ASC, cr.id ASC
    ";
    $res_cr = mysqli_query($connection, $sql_cr);
    if ($res_cr) {
        while ($row = mysqli_fetch_assoc($res_cr)) {
            $credit_rows[] = $row;
            $iqty = floatval($row['issue_quantity'] > 0 ? $row['issue_quantity'] : $row['quantity']);
            $bqty = floatval($row['quantity']);
            $camt = floatval($row['amount']);

            $total_credit_issued_litres += $iqty;
            $total_credit_quota_litres  += $bqty;
            $total_credit_amount        += $camt;
        }
    }

    // 4. Fetch Card Sales for this nozzle
    $shift_filter_cd = ($shiftId > 0) ? " AND cd.shift_id = '$shiftId'" : "";
    $sql_cd = "
        SELECT cd.*, sh.name AS shift_name, cm.name AS machine_name, cm.terminal_id
        FROM tbl_meter_reading_card_sales cd
        LEFT JOIN tbl_shifts sh ON cd.shift_id = sh.id
        LEFT JOIN tbl_card_machines cm ON cd.card_machine_id = cm.id
        WHERE cd.nozzle_id = '$nozzleId'
          AND cd.sale_date BETWEEN '$from_safe' AND '$to_safe'
          $shift_filter_cd
          AND (cd.deleted_at IS NULL OR cd.deleted_at = '0000-00-00 00:00:00')
        ORDER BY cd.sale_date ASC, cd.shift_id ASC, cd.id ASC
    ";
    $res_cd = mysqli_query($connection, $sql_cd);
    if ($res_cd) {
        while ($row = mysqli_fetch_assoc($res_cd)) {
            $card_rows[] = $row;
            $total_card_litres  += floatval($row['quantity']);
            $total_card_amount  += floatval($row['amount']);
            $total_card_charges += floatval($row['service_charges']);
            $total_card_net     += floatval($row['net_amount'] > 0 ? $row['net_amount'] : $row['amount']);
        }
    }

    // 5. Fetch Expenses recorded on behalf of this nozzle
    $sql_exp = "
        SELECT e.*, et.name AS expense_type_name, a.name AS creator_name
        FROM tbl_expenses e
        LEFT JOIN tbl_expense_types et ON e.expense_type_id = et.id
        LEFT JOIN tbl_accounts a ON e.created_by = a.id
        WHERE e.nozzle_id = '$nozzleId'
          AND e.expense_date BETWEEN '$from_safe' AND '$to_safe'
          AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')
        ORDER BY e.expense_date ASC, e.id ASC
    ";
    $res_exp = mysqli_query($connection, $sql_exp);
    if ($res_exp) {
        while ($row = mysqli_fetch_assoc($res_exp)) {
            $expense_rows[] = $row;
            $total_nozzle_expenses += floatval($row['amount']);
        }
    }
}

// Financial and volumetric reconciliations
$total_settled_litres = $total_cash_litres + $total_credit_issued_litres + $total_card_litres;
$total_settled_amount = $total_cash_amount + $total_credit_amount + $total_card_amount;

$volume_variance    = round($total_settled_litres - $total_meter_litres, 2);
$financial_variance = round($total_settled_amount - $total_meter_revenue, 2);

// Net Nozzle Operating Yield = Meter Gross Revenue - Nozzle Expenses
$net_nozzle_yield = $total_meter_revenue - $total_nozzle_expenses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Daily Nozzle Report</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">

    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: var(--gradient-header);
            color: #fff; padding: 18px 24px; border-radius: 10px;
            margin-bottom: 22px; display: flex; align-items: center;
            justify-content: space-between; box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.25rem; }
        .filter-card {
            background: #fff; border-radius: 10px; padding: 16px 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 22px;
        }
        .kpi-card {
            background: #fff; border-radius: 10px; padding: 16px 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px;
            border-top: 3.5px solid var(--primary-color); height: calc(100% - 20px);
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .kpi-card.kpi-success { border-top-color: #28a745; }
        .kpi-card.kpi-primary { border-top-color: #007bff; }
        .kpi-card.kpi-warning { border-top-color: #ffc107; }
        .kpi-card.kpi-info    { border-top-color: #17a2b8; }
        .kpi-card.kpi-danger  { border-top-color: #dc3545; }
        .kpi-card.kpi-dark    { border-top-color: #04204e; }

        .kpi-title { font-size: 11px; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.5px; }
        .kpi-value { font-size: 1.45rem; font-weight: 700; color: #04204e; margin-top: 4px; }
        .kpi-sub   { font-size: 12px; color: #6c757d; margin-top: 2px; }

        .audit-box {
            border-radius: 10px; padding: 14px 20px; margin-bottom: 22px;
            border: 1px solid #d1d5db; background: #fff;
        }
        .section-header {
            background: var(--primary-gradient); color: #fff;
            padding: 10px 18px; border-radius: 8px 8px 0 0;
            font-size: 14px; font-weight: 600;
        }
        .table-custom thead th {
            background: var(--primary-color) !important; color: #fff;
            font-size: 12px; font-weight: 600; vertical-align: middle; text-align: center;
        }
        .table-custom td { vertical-align: middle; font-size: 12.5px; }
        .meta-pill {
            background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3);
            border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 500;
            display: inline-flex; align-items: center; color: #fff; margin-right: 6px;
        }
        @media print {
            .d-print-none, .main-navbar { display: none !important; }
            body { background: #fff !important; color: #000 !important; }
            .container-fluid { padding: 0 !important; }
            .kpi-card { box-shadow: none !important; border: 1px solid #999 !important; }
            .print-header { display: block !important; margin-bottom: 20px; }
        }
        .print-header { display: none; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5">
    
    <!-- Print Header -->
    <div class="print-header text-center">
        <h3 class="font-weight-bold mb-1" style="color:#04204e;">PETROL PUMP MANAGEMENT SYSTEM</h3>
        <h5 class="font-weight-bold mb-1">Daily Nozzle Performance &amp; Settlement Report</h5>
        <?php if ($selected_nozzle): ?>
        <p class="mb-1 font-weight-bold">
            Nozzle: <?php echo htmlspecialchars($selected_nozzle['name']); ?> 
            (<?php echo htmlspecialchars($selected_nozzle['item_name'] ?? 'Fuel'); ?> - Tank: <?php echo htmlspecialchars($selected_nozzle['tank_name'] ?? 'N/A'); ?>)
            &nbsp;|&nbsp; Shift: <?php echo htmlspecialchars($selected_shift_name); ?>
            &nbsp;|&nbsp; Period: <?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?>
        </p>
        <?php endif; ?>
        <hr style="border-top:2px solid #04204e;">
    </div>

    <!-- Page Header & Action Bar -->
    <div class="page-header d-print-none">
        <div>
            <h4><i class="fas fa-gas-pump mr-2 text-warning"></i> Daily Nozzle Report</h4>
            <div class="mt-1">
                <?php if ($selected_nozzle): ?>
                    <span class="meta-pill"><i class="fas fa-tachometer-alt mr-1 text-warning"></i> Nozzle: <?php echo htmlspecialchars($selected_nozzle['name']); ?></span>
                    <span class="meta-pill"><i class="fas fa-oil-can mr-1 text-info"></i> Fuel: <?php echo htmlspecialchars($selected_nozzle['item_name'] ?? 'Fuel'); ?></span>
                    <span class="meta-pill"><i class="fas fa-database mr-1 text-success"></i> Tank: <?php echo htmlspecialchars($selected_nozzle['tank_name'] ?? 'N/A'); ?></span>
                    <span class="meta-pill"><i class="fas fa-clock mr-1 text-light"></i> Shift: <?php echo htmlspecialchars($selected_shift_name); ?></span>
                <?php else: ?>
                    <small class="text-white-50">Comprehensive single-nozzle performance: Net meter sales, settlement reconciliation (Cash, Credit, Card), and equipment expenses</small>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?php if ($isSearched && $selected_nozzle): ?>
            <a href="generate-pdf-nozzle-report.php?nozzle_id=<?php echo urlencode($nozzleId); ?>&shift_id=<?php echo urlencode($shiftId); ?>&from_date=<?php echo urlencode($fromDate); ?>&to_date=<?php echo urlencode($toDate); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
                <i class="fas fa-file-pdf mr-1"></i> Export PDF
            </a>
            <?php endif; ?>
            <button class="btn btn-outline-light font-weight-bold mr-2" onclick="window.print();">
                <i class="fas fa-print mr-1"></i> Print
            </button>
            <a href="nozzle-report.php" class="btn btn-outline-light font-weight-bold">
                <i class="fas fa-sync-alt mr-1"></i> Reset
            </a>
        </div>
    </div>

    <!-- Filter Card: Nozzle (Required), Shift (Optional), From/To Dates -->
    <div class="filter-card d-print-none">
        <form action="nozzle-report.php" method="GET" class="form-row align-items-end" id="filterForm">
            <div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 mb-2 mb-lg-0">
                <label class="font-weight-bold small text-dark mb-1">
                    <i class="fas fa-gas-pump mr-1 text-primary"></i> Dispensing Nozzle <span class="text-danger">* (Required)</span>
                </label>
                <select name="nozzle_id" id="nozzle_id" class="form-control form-control-sm font-weight-bold" required>
                    <option value="" disabled <?php echo ($nozzleId <= 0) ? 'selected' : ''; ?>>-- Select Dispensing Nozzle (Required) --</option>
                    <?php foreach ($all_nozzles as $n): ?>
                        <option value="<?php echo $n['id']; ?>" <?php echo ($nozzleId == $n['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($n['name']); ?> (<?php echo htmlspecialchars($n['item_name'] ?? 'Fuel'); ?> - Tank: <?php echo htmlspecialchars($n['tank_name'] ?? 'N/A'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-2 mb-lg-0">
                <label class="font-weight-bold small text-dark mb-1">
                    <i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-muted">(Optional)</span>
                </label>
                <select name="shift_id" id="shift_id" class="form-control form-control-sm font-weight-bold">
                    <option value="0" <?php echo ($shiftId == 0) ? 'selected' : ''; ?>>-- All Shifts (Combined) --</option>
                    <?php foreach ($all_shifts as $sh): ?>
                        <option value="<?php echo $sh['id']; ?>" <?php echo ($shiftId == $sh['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sh['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-2 mb-lg-0">
                <label class="font-weight-bold small text-dark mb-1">
                    <i class="fas fa-calendar-alt mr-1 text-primary"></i> From Date
                </label>
                <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($fromDate); ?>">
            </div>
            <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-2 mb-lg-0">
                <label class="font-weight-bold small text-dark mb-1">
                    <i class="fas fa-calendar-check mr-1 text-primary"></i> To Date
                </label>
                <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($toDate); ?>">
            </div>
            <div class="col-xl-2 col-lg-2 col-md-12 col-sm-12">
                <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold shadow-sm">
                    <i class="fas fa-search mr-1"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <?php if (!$isSearched || !$selected_nozzle): ?>
        <!-- Mandatory Nozzle Selection Guidance -->
        <div class="card p-5 text-center shadow-sm border-0 d-print-none" style="border-radius:12px; background:#fff;">
            <div class="mb-3">
                <span class="rounded-circle p-3 d-inline-block" style="background:#eef2ff;">
                    <i class="fas fa-gas-pump text-primary" style="font-size: 42px;"></i>
                </span>
            </div>
            <h5 class="font-weight-bold" style="color:#04204e;">Please Select a Dispensing Nozzle to View Report</h5>
            <p class="text-muted mx-auto" style="max-width: 550px;">
                The Daily Nozzle Report provides an itemized audit of fuel volume dispensed, payments settled (Cash, Credit, Card), and equipment-specific expenses. Select a nozzle from the dropdown above and click <strong>Generate Report</strong>.
            </p>
        </div>
    <?php else: ?>

        <!-- 6 KPI Metric Cards Grid -->
        <div class="row">
            <!-- 1. Total Net Sale (Meter) -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-dark">
                    <div class="kpi-title"><i class="fas fa-tachometer-alt mr-1"></i> Net Sale (Meter)</div>
                    <div class="kpi-value text-dark"><?php echo number_format($total_meter_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub text-primary font-weight-bold">Rs. <?php echo number_format($total_meter_revenue, 2); ?></div>
                    <div class="text-muted small mt-1">Gross Dispensed: <?php echo number_format($total_meter_litres + $total_test_litres, 2); ?> Ltr</div>
                </div>
            </div>

            <!-- 2. Cash Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-primary">
                    <div class="kpi-title"><i class="fas fa-money-bill-wave mr-1 text-primary"></i> Cash Sales</div>
                    <div class="kpi-value text-primary"><?php echo number_format($total_cash_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_cash_amount, 2); ?></div>
                    <div class="text-muted small mt-1"><?php echo count($cash_rows); ?> Cash Transaction(s)</div>
                </div>
            </div>

            <!-- 3. Credit Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-info">
                    <div class="kpi-title"><i class="fas fa-file-invoice mr-1 text-info"></i> Credit Sales</div>
                    <div class="kpi-value text-info"><?php echo number_format($total_credit_issued_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_credit_amount, 2); ?></div>
                    <div class="text-muted small mt-1"><?php echo count($credit_rows); ?> Slip(s) Recorded</div>
                </div>
            </div>

            <!-- 4. Card Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-warning">
                    <div class="kpi-title"><i class="fas fa-credit-card mr-1 text-warning"></i> Card Sales</div>
                    <div class="kpi-value text-warning"><?php echo number_format($total_card_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_card_amount, 2); ?></div>
                    <div class="text-muted small mt-1">Net: Rs. <?php echo number_format($total_card_net, 2); ?></div>
                </div>
            </div>

            <!-- 5. Nozzle Expenses -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-danger">
                    <div class="kpi-title"><i class="fas fa-tools mr-1 text-danger"></i> Nozzle Expenses</div>
                    <div class="kpi-value text-danger">Rs. <?php echo number_format($total_nozzle_expenses, 2); ?></div>
                    <div class="kpi-sub text-muted font-weight-bold"><?php echo count($expense_rows); ?> Expense Item(s)</div>
                    <div class="text-muted small mt-1">Maintenance &amp; Repairs</div>
                </div>
            </div>

            <!-- 6. Net Nozzle Operating Yield -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-success">
                    <div class="kpi-title"><i class="fas fa-chart-line mr-1 text-success"></i> Net Nozzle Yield</div>
                    <div class="kpi-value text-success">Rs. <?php echo number_format($net_nozzle_yield, 2); ?></div>
                    <div class="kpi-sub text-muted font-weight-bold">Revenue &minus; Expenses</div>
                    <div class="text-muted small mt-1">Net Contribution</div>
                </div>
            </div>
        </div>

        <!-- Volumetric & Financial Reconciliation Audit Box -->
        <div class="audit-box shadow-sm mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h6 class="font-weight-bold mb-1" style="color:#04204e;">
                        <i class="fas fa-balance-scale mr-2 text-primary"></i> Fuel Volume &amp; Settlement Reconciliation
                    </h6>
                    <small class="text-muted">Comparing physical fuel recorded on meter counters vs. total recorded payment settlements (Cash + Credit + Card)</small>
                </div>
                <div>
                    <?php if (abs($volume_variance) < 0.05 && abs($financial_variance) < 1.00): ?>
                        <span class="badge badge-success px-3 py-2" style="font-size:13px;">
                            <i class="fas fa-check-circle mr-1"></i> Fully Reconciled (100% Balanced)
                        </span>
                    <?php else: ?>
                        <span class="badge badge-warning px-3 py-2 text-dark font-weight-bold" style="font-size:13px;">
                            <i class="fas fa-exclamation-triangle mr-1"></i> Variance: 
                            <?php echo ($volume_variance >= 0 ? '+' : '') . number_format($volume_variance, 2); ?> Ltr / 
                            Rs. <?php echo ($financial_variance >= 0 ? '+' : '') . number_format($financial_variance, 2); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mt-3 text-center" style="font-size:13px;">
                <div class="col-md-3 col-6 border-right">
                    <span class="text-muted small d-block font-weight-bold">PHYSICAL METER VOLUME:</span>
                    <span class="font-weight-bold text-dark" style="font-size:15px;"><?php echo number_format($total_meter_litres, 2); ?> Ltr</span>
                </div>
                <div class="col-md-3 col-6 border-right">
                    <span class="text-muted small d-block font-weight-bold">TOTAL SETTLED VOLUME:</span>
                    <span class="font-weight-bold text-primary" style="font-size:15px;"><?php echo number_format($total_settled_litres, 2); ?> Ltr</span>
                    <div class="text-muted small">(Cash: <?php echo number_format($total_cash_litres, 2); ?> | Credit: <?php echo number_format($total_credit_issued_litres, 2); ?> | Card: <?php echo number_format($total_card_litres, 2); ?>)</div>
                </div>
                <div class="col-md-3 col-6 border-right">
                    <span class="text-muted small d-block font-weight-bold">GROSS METER REVENUE:</span>
                    <span class="font-weight-bold text-dark" style="font-size:15px;">Rs. <?php echo number_format($total_meter_revenue, 2); ?></span>
                </div>
                <div class="col-md-3 col-6">
                    <span class="text-muted small d-block font-weight-bold">TOTAL MONETARY SETTLEMENT:</span>
                    <span class="font-weight-bold text-success" style="font-size:15px;">Rs. <?php echo number_format($total_settled_amount, 2); ?></span>
                    <div class="text-muted small">(Cash + Credit + Card Receipts)</div>
                </div>
            </div>
        </div>

        <!-- 1. Meter Readings Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-tachometer-alt mr-2"></i> Physical Shift Meter Readings (Overall Sale)</span>
                <span class="badge badge-light text-primary font-weight-bold"><?php echo count($meter_rows); ?> Reading(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Staff / Operator</th>
                            <th>Last Reading</th>
                            <th>Current Reading</th>
                            <th>Sale Reading</th>
                            <th>Test (Ltr)</th>
                            <th>Net Sale (Ltr)</th>
                            <th>Fuel Rate</th>
                            <th>Total Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($meter_rows)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-3 text-muted">No meter readings recorded for this nozzle in the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $m_i = 1; foreach ($meter_rows as $mr): ?>
                            <tr>
                                <td class="text-center"><?php echo $m_i++; ?></td>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($mr['date'])); ?></td>
                                <td class="text-center"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($mr['shift_name'] ?? 'General'); ?></span></td>
                                <td><?php echo htmlspecialchars($mr['staff_name'] ?? 'Unassigned'); ?></td>
                                <td class="text-right"><?php echo number_format(floatval($mr['last_reading']), 2); ?></td>
                                <td class="text-right font-weight-bold text-dark"><?php echo number_format(floatval($mr['current_reading']), 2); ?></td>
                                <td class="text-right"><?php echo number_format(floatval($mr['sale_reading']), 2); ?></td>
                                <td class="text-right text-muted"><?php echo number_format(floatval($mr['test_reading']), 2); ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo number_format(floatval($mr['net_sale']), 2); ?> Ltr</td>
                                <td class="text-right">Rs. <?php echo number_format(floatval($mr['price']), 2); ?></td>
                                <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format(floatval($mr['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="7" class="text-right">TOTAL:</td>
                                <td class="text-right text-muted"><?php echo number_format($total_test_litres, 2); ?> Ltr</td>
                                <td class="text-right text-success"><?php echo number_format($total_meter_litres, 2); ?> Ltr</td>
                                <td></td>
                                <td class="text-right text-primary">Rs. <?php echo number_format($total_meter_revenue, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Cash Sales Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);">
                <span><i class="fas fa-money-bill-wave mr-2"></i> Cash Sales Breakdown</span>
                <span class="badge badge-light text-primary font-weight-bold"><?php echo count($cash_rows); ?> Entry(ies)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Rate (Rs.)</th>
                            <th>Litres Sold</th>
                            <th>Cash Amount (Rs.)</th>
                            <th>Source / Type</th>
                            <th>Notes / Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cash_rows)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-3 text-muted">No cash sale transactions found for this nozzle in the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $c_i = 1; foreach ($cash_rows as $cs): ?>
                            <tr>
                                <td class="text-center"><?php echo $c_i++; ?></td>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($cs['sale_date'])); ?></td>
                                <td class="text-center"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($cs['shift_name'] ?? 'General'); ?></span></td>
                                <td class="text-right">Rs. <?php echo number_format(floatval($cs['rate']), 2); ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo number_format(floatval($cs['quantity']), 2); ?> Ltr</td>
                                <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format(floatval($cs['amount']), 2); ?></td>
                                <td class="text-center">
                                    <?php if (intval($cs['is_manual_override'] ?? 0) === 1): ?>
                                        <span class="badge badge-warning text-dark"><i class="fas fa-user-edit mr-1"></i>Manual</span>
                                    <?php elseif (intval($cs['meter_reading_id'] ?? 0) > 0): ?>
                                        <span class="badge badge-info"><i class="fas fa-robot mr-1"></i>Auto (Meter #<?php echo $cs['meter_reading_id']; ?>)</span>
                                    <?php else: ?>
                                        <span class="badge badge-light text-muted"><i class="fas fa-pencil-alt mr-1"></i>Direct</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars($cs['notes'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="4" class="text-right">TOTAL CASH:</td>
                                <td class="text-right text-success"><?php echo number_format($total_cash_litres, 2); ?> Ltr</td>
                                <td class="text-right text-primary">Rs. <?php echo number_format($total_cash_amount, 2); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. Credit Sales Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0dcaf0 0%, #0aa2c0 100%);">
                <span><i class="fas fa-file-invoice mr-2"></i> Credit Sales Slips</span>
                <span class="badge badge-light text-dark font-weight-bold"><?php echo count($credit_rows); ?> Slip(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Slip Date</th>
                            <th>Shift</th>
                            <th>Slip No</th>
                            <th>Slip Type</th>
                            <th>Customer Account</th>
                            <th>Vehicle No</th>
                            <th>Rate</th>
                            <th>Fuel Issued (Ltr)</th>
                            <th>Billed Quota (Ltr)</th>
                            <th>Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($credit_rows)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-3 text-muted">No credit sales slips recorded for this nozzle in the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $cr_i = 1; foreach ($credit_rows as $cr): 
                                $s_date = !empty($cr['sale_date']) ? $cr['sale_date'] : $cr['slip_date'];
                                $iqty = floatval($cr['issue_quantity'] > 0 ? $cr['issue_quantity'] : $cr['quantity']);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $cr_i++; ?></td>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($s_date)); ?></td>
                                <td class="text-center"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($cr['shift_name'] ?? 'General'); ?></span></td>
                                <td class="font-weight-bold text-center"><?php echo htmlspecialchars($cr['slip_no']); ?></td>
                                <td class="text-center"><span class="badge badge-secondary"><?php echo htmlspecialchars($cr['slip_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($cr['customer_name'] ?? ('Account #' . $cr['account_number'])); ?></td>
                                <td class="font-weight-bold text-center"><?php echo htmlspecialchars($cr['vehicle_number']); ?></td>
                                <td class="text-right">Rs. <?php echo number_format(floatval($cr['rate']), 2); ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo number_format($iqty, 2); ?> Ltr</td>
                                <td class="text-right text-muted"><?php echo number_format(floatval($cr['quantity']), 2); ?> Ltr</td>
                                <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format(floatval($cr['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="8" class="text-right">TOTAL CREDIT:</td>
                                <td class="text-right text-success"><?php echo number_format($total_credit_issued_litres, 2); ?> Ltr</td>
                                <td class="text-right text-muted"><?php echo number_format($total_credit_quota_litres, 2); ?> Ltr</td>
                                <td class="text-right text-primary">Rs. <?php echo number_format($total_credit_amount, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. Card Sales Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #fd7e14 0%, #d66408 100%);">
                <span><i class="fas fa-credit-card mr-2"></i> Card Machine / POS Transactions</span>
                <span class="badge badge-light text-dark font-weight-bold"><?php echo count($card_rows); ?> Swipe(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>POS Terminal / Machine</th>
                            <th>Batch No</th>
                            <th>Trace No</th>
                            <th>Rate</th>
                            <th>Litres</th>
                            <th>Gross Amount (Rs.)</th>
                            <th>Service Charges</th>
                            <th>Net Deposit (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($card_rows)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-3 text-muted">No card terminal transactions recorded for this nozzle in the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $cd_i = 1; foreach ($card_rows as $cd): ?>
                            <tr>
                                <td class="text-center"><?php echo $cd_i++; ?></td>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($cd['sale_date'])); ?></td>
                                <td class="text-center"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($cd['shift_name'] ?? 'General'); ?></span></td>
                                <td><?php echo htmlspecialchars($cd['machine_name'] ?? 'POS Machine'); ?></td>
                                <td class="text-center font-weight-bold"><?php echo htmlspecialchars($cd['batch_no'] ?? '-'); ?></td>
                                <td class="text-center text-muted"><?php echo htmlspecialchars($cd['trace_no'] ?? '-'); ?></td>
                                <td class="text-right">Rs. <?php echo number_format(floatval($cd['rate']), 2); ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo number_format(floatval($cd['quantity']), 2); ?> Ltr</td>
                                <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format(floatval($cd['amount']), 2); ?></td>
                                <td class="text-right text-danger">Rs. <?php echo number_format(floatval($cd['service_charges']), 2); ?></td>
                                <td class="text-right font-weight-bold text-dark">Rs. <?php echo number_format(floatval($cd['net_amount'] > 0 ? $cd['net_amount'] : $cd['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="7" class="text-right">TOTAL CARD:</td>
                                <td class="text-right text-success"><?php echo number_format($total_card_litres, 2); ?> Ltr</td>
                                <td class="text-right text-primary">Rs. <?php echo number_format($total_card_amount, 2); ?></td>
                                <td class="text-right text-danger">Rs. <?php echo number_format($total_card_charges, 2); ?></td>
                                <td class="text-right text-dark">Rs. <?php echo number_format($total_card_net, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Nozzle Expenses Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);">
                <span><i class="fas fa-tools mr-2"></i> Equipment Maintenance &amp; Nozzle Expenses</span>
                <span class="badge badge-light text-danger font-weight-bold"><?php echo count($expense_rows); ?> Item(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Expense Date</th>
                            <th>Expense Category</th>
                            <th>Payment Method</th>
                            <th>Bill / Voucher Ref</th>
                            <th>Remarks / Notes</th>
                            <th>Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expense_rows)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-3 text-muted">No equipment expenses recorded for this nozzle in the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $e_i = 1; foreach ($expense_rows as $exp): ?>
                            <tr>
                                <td class="text-center"><?php echo $e_i++; ?></td>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($exp['expense_date'])); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($exp['expense_type_name'] ?? 'Nozzle Expense'); ?></td>
                                <td class="text-center"><span class="badge badge-secondary"><?php echo htmlspecialchars($exp['payment_method']); ?></span></td>
                                <td class="text-center"><?php echo htmlspecialchars($exp['reference_no'] ?? '-'); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($exp['notes'] ?? ''); ?></td>
                                <td class="text-right font-weight-bold text-danger">Rs. <?php echo number_format(floatval($exp['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="6" class="text-right text-danger">TOTAL NOZZLE EXPENSES:</td>
                                <td class="text-right text-danger">Rs. <?php echo number_format($total_nozzle_expenses, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
</body>
</html>
