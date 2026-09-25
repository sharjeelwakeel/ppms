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
$nozzleId   = intval($_GET['nozzle_id'] ?? 0);
$shiftId    = intval($_GET['shift_id'] ?? 0);
$reportDate = trim($_GET['date'] ?? date('Y-m-d'));

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

$isSearched = true;

require_once __DIR__ . '/../include/nozzle_report_helper.php';

// Fetch report data: if nozzle selected, fetch single nozzle audit; otherwise fetch station-wide summary across all nozzles
if ($nozzleId > 0 && $selected_nozzle) {
    $report_data = get_daily_nozzle_report_data($connection, $nozzleId, $reportDate, $shiftId);
} else {
    $report_data = get_daily_station_report_data($connection, $reportDate, $shiftId);
}
extract($report_data);
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
        <h5 class="font-weight-bold mb-1">
            <?php echo ($selected_nozzle) ? 'Daily Nozzle Performance &amp; Settlement Report' : 'Station-Wide Daily Nozzle Summary &amp; Reconciliation Report'; ?>
        </h5>
        <?php if ($selected_nozzle): ?>
        <p class="mb-1 font-weight-bold">
            Nozzle: <?php echo htmlspecialchars($selected_nozzle['name']); ?> 
            (<?php echo htmlspecialchars($selected_nozzle['item_name'] ?? 'Fuel'); ?> - Tank: <?php echo htmlspecialchars($selected_nozzle['tank_name'] ?? 'N/A'); ?>)
            &nbsp;|&nbsp; Shift: <?php echo htmlspecialchars($selected_shift_name); ?>
            &nbsp;|&nbsp; Date: <?php echo date('d-m-Y', strtotime($reportDate)); ?>
        </p>
        <?php else: ?>
        <p class="mb-1 font-weight-bold">
            Scope: All Dispensing Nozzles (Station Summary)
            &nbsp;|&nbsp; Shift: <?php echo htmlspecialchars($selected_shift_name); ?>
            &nbsp;|&nbsp; Date: <?php echo date('d-m-Y', strtotime($reportDate)); ?>
        </p>
        <?php endif; ?>
        <hr style="border-top:2px solid #04204e;">
    </div>

    <!-- Page Header & Action Bar -->
    <div class="page-header d-print-none">
        <div>
            <h4>
                <i class="fas fa-gas-pump mr-2 text-warning"></i> 
                <?php echo ($selected_nozzle) ? 'Daily Nozzle Report' : 'Daily Nozzle Report (All Nozzles)'; ?>
            </h4>
            <div class="mt-1">
                <?php if ($selected_nozzle): ?>
                    <span class="meta-pill"><i class="fas fa-tachometer-alt mr-1 text-warning"></i> Nozzle: <?php echo htmlspecialchars($selected_nozzle['name']); ?></span>
                    <span class="meta-pill"><i class="fas fa-oil-can mr-1 text-info"></i> Fuel: <?php echo htmlspecialchars($selected_nozzle['item_name'] ?? 'Fuel'); ?></span>
                    <span class="meta-pill"><i class="fas fa-database mr-1 text-success"></i> Tank: <?php echo htmlspecialchars($selected_nozzle['tank_name'] ?? 'N/A'); ?></span>
                    <span class="meta-pill"><i class="fas fa-clock mr-1 text-light"></i> Shift: <?php echo htmlspecialchars($selected_shift_name); ?></span>
                    <span class="meta-pill"><i class="fas fa-calendar-day mr-1 text-warning"></i> Date: <?php echo date('d-m-Y', strtotime($reportDate)); ?></span>
                <?php else: ?>
                    <span class="meta-pill"><i class="fas fa-layer-group mr-1 text-warning"></i> Scope: All Dispensing Nozzles</span>
                    <span class="meta-pill"><i class="fas fa-clock mr-1 text-light"></i> Shift: <?php echo htmlspecialchars($selected_shift_name); ?></span>
                    <span class="meta-pill"><i class="fas fa-calendar-day mr-1 text-warning"></i> Date: <?php echo date('d-m-Y', strtotime($reportDate)); ?></span>
                    <span class="meta-pill"><i class="fas fa-chart-pie mr-1 text-success"></i> Station Summary Mode</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="generate-pdf-nozzle-report.php?nozzle_id=<?php echo urlencode($nozzleId); ?>&shift_id=<?php echo urlencode($shiftId); ?>&date=<?php echo urlencode($reportDate); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
                <i class="fas fa-file-pdf mr-1"></i> Export PDF
            </a>
            <button class="btn btn-outline-light font-weight-bold mr-2" onclick="window.print();">
                <i class="fas fa-print mr-1"></i> Print
            </button>
            <a href="nozzle-report.php" class="btn btn-outline-light font-weight-bold">
                <i class="fas fa-sync-alt mr-1"></i> Reset
            </a>
        </div>
    </div>

    <!-- Filter Card: Nozzle (Optional), Shift (Optional), Single Date (Required) -->
    <div class="filter-card d-print-none">
        <form action="nozzle-report.php" method="GET" class="form-row align-items-end" id="filterForm">
            <div class="col-xl-5 col-lg-5 col-md-6 col-sm-12 mb-2 mb-lg-0">
                <label class="font-weight-bold small text-dark mb-1">
                    <i class="fas fa-gas-pump mr-1 text-primary"></i> Dispensing Nozzle <span class="text-muted">(Optional)</span>
                </label>
                <select name="nozzle_id" id="nozzle_id" class="form-control form-control-sm font-weight-bold">
                    <option value="0" <?php echo ($nozzleId <= 0) ? 'selected' : ''; ?>>-- All Dispensing Nozzles (Station Summary) --</option>
                    <?php foreach ($all_nozzles as $n): ?>
                        <option value="<?php echo $n['id']; ?>" <?php echo ($nozzleId == $n['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($n['name']); ?> (<?php echo htmlspecialchars($n['item_name'] ?? 'Fuel'); ?> - Tank: <?php echo htmlspecialchars($n['tank_name'] ?? 'N/A'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-2 mb-lg-0">
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
                    <i class="fas fa-calendar-day mr-1 text-primary"></i> Report Date <span class="text-danger">*</span>
                </label>
                <input type="date" name="date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($reportDate); ?>" required>
            </div>
            <div class="col-xl-2 col-lg-2 col-md-12 col-sm-12">
                <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold shadow-sm">
                    <i class="fas fa-search mr-1"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <?php if (empty($all_nozzles)): ?>
        <!-- No Active Nozzles Warning -->
        <div class="card p-5 text-center shadow-sm border-0 d-print-none" style="border-radius:12px; background:#fff;">
            <div class="mb-3">
                <span class="rounded-circle p-3 d-inline-block" style="background:#eef2ff;">
                    <i class="fas fa-gas-pump text-primary" style="font-size: 42px;"></i>
                </span>
            </div>
            <h5 class="font-weight-bold" style="color:#04204e;">No Dispensing Nozzles Configured</h5>
            <p class="text-muted mx-auto" style="max-width: 550px;">
                No active dispensing nozzles were found in the database. Please add nozzles in Master Data to generate reports.
            </p>
        </div>
    <?php else: ?>

        <!-- 6 KPI Metric Cards Grid -->
        <div class="row">
            <!-- 1. Total Net Sale (Meter) -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-dark">
                    <div class="kpi-title"><i class="fas fa-tachometer-alt mr-1"></i> <?php echo (!empty($is_station_summary)) ? 'Station Net Sale' : 'Net Sale (Meter)'; ?></div>
                    <div class="kpi-value text-dark"><?php echo number_format($total_meter_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub text-primary font-weight-bold">Rs. <?php echo number_format($total_meter_revenue, 2); ?></div>
                    <div class="text-muted small mt-1">Gross Dispensed: <?php echo number_format($total_meter_litres + $total_test_litres, 2); ?> Ltr</div>
                </div>
            </div>

            <!-- 2. Cash Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-primary">
                    <div class="kpi-title"><i class="fas fa-money-bill-wave mr-1 text-primary"></i> <?php echo (!empty($is_station_summary)) ? 'Station Cash' : 'Cash Sales'; ?></div>
                    <div class="kpi-value text-primary"><?php echo number_format($total_cash_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_cash_amount, 2); ?></div>
                    <div class="text-muted small mt-1"><?php echo count($cash_rows); ?> Cash Transaction(s)</div>
                </div>
            </div>

            <!-- 3. Credit Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-info">
                    <div class="kpi-title"><i class="fas fa-file-invoice mr-1 text-info"></i> <?php echo (!empty($is_station_summary)) ? 'Station Credit' : 'Credit Sales'; ?></div>
                    <div class="kpi-value text-info"><?php echo number_format($total_credit_issued_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_credit_amount, 2); ?></div>
                    <div class="text-muted small mt-1"><?php echo count($credit_rows); ?> Slip(s) Recorded</div>
                </div>
            </div>

            <!-- 4. Card Sale -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-warning">
                    <div class="kpi-title"><i class="fas fa-credit-card mr-1 text-warning"></i> <?php echo (!empty($is_station_summary)) ? 'Station Card' : 'Card Sales'; ?></div>
                    <div class="kpi-value text-warning"><?php echo number_format($total_card_litres, 2); ?> <small style="font-size:13px;">Ltr</small></div>
                    <div class="kpi-sub font-weight-bold">Rs. <?php echo number_format($total_card_amount, 2); ?></div>
                    <div class="text-muted small mt-1">Net: Rs. <?php echo number_format($total_card_net, 2); ?></div>
                </div>
            </div>

            <!-- 5. Expenses -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-danger">
                    <div class="kpi-title"><i class="fas fa-tools mr-1 text-danger"></i> <?php echo (!empty($is_station_summary)) ? 'Station Expenses' : 'Nozzle Expenses'; ?></div>
                    <div class="kpi-value text-danger">Rs. <?php echo number_format($total_nozzle_expenses, 2); ?></div>
                    <div class="kpi-sub text-muted font-weight-bold">
                        <?php if (!empty($is_station_summary)): ?>
                            Nozzle: Rs. <?php echo number_format($station_nozzle_expenses, 2); ?>
                        <?php else: ?>
                            <?php echo count($expense_rows); ?> Expense Item(s)
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small mt-1">
                        <?php echo (!empty($is_station_summary)) ? ('General: Rs. ' . number_format($station_general_expenses, 2)) : 'Maintenance &amp; Repairs'; ?>
                    </div>
                </div>
            </div>

            <!-- 6. Net Yield -->
            <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6">
                <div class="kpi-card kpi-success">
                    <div class="kpi-title"><i class="fas fa-chart-line mr-1 text-success"></i> <?php echo (!empty($is_station_summary)) ? 'Net Station Yield' : 'Net Nozzle Yield'; ?></div>
                    <div class="kpi-value text-success">Rs. <?php echo number_format($net_nozzle_yield, 2); ?></div>
                    <div class="kpi-sub text-muted font-weight-bold">Revenue &minus; Expenses</div>
                    <div class="text-muted small mt-1">
                        <?php echo (!empty($is_station_summary)) ? 'Station Operating Yield' : 'Net Contribution'; ?>
                    </div>
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
                    <small class="text-muted">
                        <?php echo (!empty($is_station_summary)) 
                            ? 'Comparing physical fuel recorded across all nozzle meter counters vs. total recorded payment settlements (Cash + Credit + Card)' 
                            : 'Comparing physical fuel recorded on meter counters vs. total recorded payment settlements (Cash + Credit + Card)'; ?>
                    </small>
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

        <?php if (!empty($is_station_summary) && !empty($product_summaries)): ?>
        <!-- Product-Wise Rollup Summary -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #04204e 0%, #07347a 100%);">
                <span><i class="fas fa-oil-can mr-2 text-warning"></i> Fuel Product Rollup Summary</span>
                <span class="badge badge-light text-primary font-weight-bold"><?php echo count($product_summaries); ?> Fuel Type(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fuel Product</th>
                            <th>Active Nozzles</th>
                            <th>Total Net Dispensed (Ltr)</th>
                            <th>Gross Fuel Revenue (Rs.)</th>
                            <th>Share of Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $prod_i = 1;
                        foreach ($product_summaries as $ps): 
                            $pct = ($total_meter_revenue > 0) ? ($ps['revenue'] / $total_meter_revenue * 100) : 0;
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $prod_i++; ?></td>
                            <td class="font-weight-bold text-dark"><i class="fas fa-gas-pump mr-2 text-primary"></i><?php echo htmlspecialchars($ps['fuel_name']); ?></td>
                            <td class="text-center"><span class="badge badge-secondary px-2 py-1"><?php echo intval($ps['nozzle_count']); ?> Nozzle(s)</span></td>
                            <td class="text-right font-weight-bold text-success"><?php echo number_format($ps['meter_litres'], 2); ?> Ltr</td>
                            <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format($ps['revenue'], 2); ?></td>
                            <td class="text-center">
                                <span class="badge badge-light border font-weight-bold"><?php echo number_format($pct, 1); ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($is_station_summary) && !empty($nozzle_matrix)): ?>
        <!-- All Dispensing Nozzles Performance & Reconciliation Matrix -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #04204e 0%, #07347a 100%);">
                <span><i class="fas fa-table mr-2 text-warning"></i> All Dispensing Nozzles Performance &amp; Reconciliation Matrix</span>
                <span class="badge badge-light text-primary font-weight-bold"><?php echo count($nozzle_matrix); ?> Nozzle(s) Configured</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nozzle Name</th>
                            <th>Product &amp; Tank</th>
                            <th>Opening Meter</th>
                            <th>Closing Meter</th>
                            <th>Net Sale (Ltr)</th>
                            <th>Gross Revenue (Rs.)</th>
                            <th>Cash (Rs.)</th>
                            <th>Credit (Rs.)</th>
                            <th>Card (Rs.)</th>
                            <th>Total Settled (Rs.)</th>
                            <th>Variance (Rs.)</th>
                            <th>Status</th>
                            <th class="d-print-none">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $nm_i = 1;
                        foreach ($nozzle_matrix as $nm): 
                            $op_txt = ($nm['min_opening_meter'] !== null) ? number_format($nm['min_opening_meter'], 2) : number_format($nm['start_reading'], 2);
                            $cl_txt = ($nm['max_closing_meter'] !== null) ? number_format($nm['max_closing_meter'], 2) : '-';
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $nm_i++; ?></td>
                            <td class="font-weight-bold text-dark">
                                <a href="nozzle-report.php?nozzle_id=<?php echo $nm['nozzle_id']; ?>&shift_id=<?php echo urlencode($shiftId); ?>&date=<?php echo urlencode($reportDate); ?>" class="text-primary font-weight-bold" title="Click to view detailed report for this nozzle">
                                    <i class="fas fa-gas-pump mr-1 text-warning"></i><?php echo htmlspecialchars($nm['nozzle_name']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($nm['item_name']); ?></span>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($nm['tank_name']); ?></small>
                            </td>
                            <td class="text-right"><?php echo $op_txt; ?></td>
                            <td class="text-right font-weight-bold text-dark"><?php echo $cl_txt; ?></td>
                            <td class="text-right font-weight-bold text-success"><?php echo number_format($nm['meter_litres'], 2); ?> Ltr</td>
                            <td class="text-right font-weight-bold text-primary">Rs. <?php echo number_format($nm['meter_revenue'], 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($nm['cash_amount'], 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($nm['credit_amount'], 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($nm['card_amount'], 2); ?></td>
                            <td class="text-right font-weight-bold text-dark">Rs. <?php echo number_format($nm['settled_amount'], 2); ?></td>
                            <td class="text-right font-weight-bold <?php echo ($nm['financial_variance'] < -0.01) ? 'text-danger' : (($nm['financial_variance'] > 0.01) ? 'text-warning' : 'text-success'); ?>">
                                <?php echo ($nm['financial_variance'] >= 0 ? '+' : '') . number_format($nm['financial_variance'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php if ($nm['status'] === 'Balanced'): ?>
                                    <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i>Balanced</span>
                                <?php elseif ($nm['status'] === 'Shortage'): ?>
                                    <span class="badge badge-danger px-2 py-1"><i class="fas fa-arrow-down mr-1"></i>Shortage</span>
                                <?php else: ?>
                                    <span class="badge badge-warning px-2 py-1 text-dark"><i class="fas fa-arrow-up mr-1"></i>Surplus</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center d-print-none">
                                <a href="nozzle-report.php?nozzle_id=<?php echo $nm['nozzle_id']; ?>&shift_id=<?php echo urlencode($shiftId); ?>&date=<?php echo urlencode($reportDate); ?>" class="btn btn-sm btn-outline-primary py-0 px-2 font-weight-bold" title="View Single Nozzle Audit">
                                    <i class="fas fa-eye mr-1"></i> Audit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-light font-weight-bold" style="font-size:13px;">
                            <td colspan="5" class="text-right">TOTAL STATION:</td>
                            <td class="text-right text-success"><?php echo number_format($total_meter_litres, 2); ?> Ltr</td>
                            <td class="text-right text-primary">Rs. <?php echo number_format($total_meter_revenue, 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($total_cash_amount, 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($total_credit_amount, 2); ?></td>
                            <td class="text-right">Rs. <?php echo number_format($total_card_amount, 2); ?></td>
                            <td class="text-right text-dark">Rs. <?php echo number_format($total_settled_amount, 2); ?></td>
                            <td class="text-right <?php echo ($financial_variance < -0.01) ? 'text-danger' : (($financial_variance > 0.01) ? 'text-warning' : 'text-success'); ?>">
                                <?php echo ($financial_variance >= 0 ? '+' : '') . number_format($financial_variance, 2); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-dark"><?php echo htmlspecialchars($station_status ?? 'Balanced'); ?></span>
                            </td>
                            <td class="d-print-none"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

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
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '12' : '11'; ?>" class="text-center py-3 text-muted">No meter readings recorded for the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $m_i = 1; foreach ($meter_rows as $mr): ?>
                            <tr>
                                <td class="text-center"><?php echo $m_i++; ?></td>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="text-center font-weight-bold">
                                        <span class="badge badge-dark"><?php echo htmlspecialchars($mr['nozzle_name'] ?? 'N/A'); ?></span>
                                    </td>
                                <?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '8' : '7'; ?>" class="text-right">TOTAL:</td>
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
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '9' : '8'; ?>" class="text-center py-3 text-muted">No cash sale transactions found for the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $c_i = 1; foreach ($cash_rows as $cs): ?>
                            <tr>
                                <td class="text-center"><?php echo $c_i++; ?></td>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="text-center font-weight-bold">
                                        <span class="badge badge-dark"><?php echo htmlspecialchars($cs['nozzle_name'] ?? 'N/A'); ?></span>
                                    </td>
                                <?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '5' : '4'; ?>" class="text-right">TOTAL CASH:</td>
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
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
                            <th>Slip Date</th>
                            <th>Shift</th>
                            <th>Slip No</th>
                            <th>Slip Type &amp; Settlement</th>
                            <th>Customer Account</th>
                            <th>Vehicle No</th>
                            <th>Rate</th>
                            <th>Fuel Issued (Ltr)</th>
                            <th>Billed Quota (Ltr)</th>
                            <th>Fuel Value (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($credit_rows)): ?>
                            <tr>
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '12' : '11'; ?>" class="text-center py-3 text-muted">No credit sales slips recorded for the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $cr_i = 1; foreach ($credit_rows as $cr): 
                                $s_date = !empty($cr['sale_date']) ? $cr['sale_date'] : $cr['slip_date'];
                                $iqty = floatval($cr['issue_quantity'] > 0 ? $cr['issue_quantity'] : $cr['quantity']);
                                $st = $cr['slip_type'];
                                $wasoli = floatval($cr['wasoli'] ?? 0);
                                $bal1 = floatval($cr['balance_1'] ?? 0);
                                $chgAmt = floatval($cr['charge_amount'] ?? 0);
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $cr_i++; ?></td>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="text-center font-weight-bold">
                                        <span class="badge badge-dark"><?php echo htmlspecialchars($cr['nozzle_name'] ?? 'N/A'); ?></span>
                                    </td>
                                <?php endif; ?>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($s_date)); ?></td>
                                <td class="text-center"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($cr['shift_name'] ?? 'General'); ?></span></td>
                                <td class="font-weight-bold text-center"><?php echo htmlspecialchars($cr['slip_no']); ?></td>
                                <td class="text-center">
                                    <?php if ($st === 'Balanced Slip'): ?>
                                        <span class="badge badge-info px-2 py-1"><i class="fas fa-balance-scale mr-1"></i>Balanced Slip</span>
                                        <?php if (!empty($cr['ref_slip_no'])): ?>
                                            <div class="small text-muted font-weight-bold mt-1">From #<?php echo htmlspecialchars($cr['ref_slip_no']); ?></div>
                                        <?php endif; ?>
                                        <span class="badge badge-light border text-muted mt-1" title="Fuel prepaid on original voucher">Prepaid (Rs. 0 Charge)</span>
                                    <?php elseif ($st === 'Temporary Slip'): ?>
                                        <span class="badge badge-danger px-2 py-1"><i class="fas fa-hand-holding mr-1"></i>Temporary Slip</span>
                                        <span class="badge badge-warning text-dark mt-1">Loan Fuel</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary px-2 py-1"><i class="fas fa-file-invoice mr-1"></i>Permanent Slip</span>
                                        <?php if ($wasoli > 0): ?>
                                            <div class="mt-1">
                                                <span class="badge badge-warning text-dark" title="Settled past loan chit"><i class="fas fa-link mr-1"></i>Settled Loan #<?php echo htmlspecialchars($cr['temp_slip_no'] ?: $cr['temp_slip_id']); ?> (<?php echo number_format($wasoli, 2); ?>L)</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($bal1 > 0): ?>
                                            <div class="mt-1">
                                                <span class="badge badge-secondary" title="Uncollected voucher balance"><i class="fas fa-hourglass-half mr-1"></i>Remaining: <?php echo number_format($bal1, 2); ?>L</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($cr['customer_name'] ?? ('Account #' . $cr['account_number'])); ?></td>
                                <td class="font-weight-bold text-center"><?php echo htmlspecialchars($cr['vehicle_number']); ?></td>
                                <td class="text-right">Rs. <?php echo number_format(floatval($cr['rate']), 2); ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo number_format($iqty, 2); ?> Ltr</td>
                                <td class="text-right text-muted"><?php echo number_format(floatval($cr['quantity']), 2); ?> Ltr</td>
                                <td class="text-right font-weight-bold text-primary">
                                    Rs. <?php echo number_format(floatval($cr['amount']), 2); ?>
                                    <?php if ($st === 'Balanced Slip'): ?>
                                        <div class="small text-muted font-italic">(Prepaid)</div>
                                    <?php elseif ($chgAmt > 0 && abs($chgAmt - floatval($cr['amount'])) > 0.01): ?>
                                        <div class="small text-danger font-weight-bold" title="Total billed including settled loan">Billed: Rs. <?php echo number_format($chgAmt, 2); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '9' : '8'; ?>" class="text-right">TOTAL CREDIT:</td>
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
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '12' : '11'; ?>" class="text-center py-3 text-muted">No card terminal transactions recorded for the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $cd_i = 1; foreach ($card_rows as $cd): ?>
                            <tr>
                                <td class="text-center"><?php echo $cd_i++; ?></td>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="text-center font-weight-bold">
                                        <span class="badge badge-dark"><?php echo htmlspecialchars($cd['nozzle_name'] ?? 'N/A'); ?></span>
                                    </td>
                                <?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '8' : '7'; ?>" class="text-right">TOTAL CARD:</td>
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

        <!-- 5. Expenses Table -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius:10px; overflow:hidden;">
            <div class="section-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);">
                <span><i class="fas fa-tools mr-2"></i> <?php echo (!empty($is_station_summary)) ? 'Equipment Maintenance &amp; Station Expenses' : 'Equipment Maintenance &amp; Nozzle Expenses'; ?></span>
                <span class="badge badge-light text-danger font-weight-bold"><?php echo count($expense_rows); ?> Item(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-custom mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle / Scope</th><?php endif; ?>
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
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '8' : '7'; ?>" class="text-center py-3 text-muted">No expenses recorded for the selected period.</td>
                            </tr>
                        <?php else: ?>
                            <?php $e_i = 1; foreach ($expense_rows as $exp): ?>
                            <tr>
                                <td class="text-center"><?php echo $e_i++; ?></td>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="text-center">
                                        <span class="badge <?php echo (!empty($exp['nozzle_name'])) ? 'badge-primary' : 'badge-secondary'; ?>">
                                            <?php echo htmlspecialchars($exp['nozzle_name'] ?: 'Station General'); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td class="text-center font-weight-bold"><?php echo date('d-m-Y', strtotime($exp['expense_date'])); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($exp['expense_type_name'] ?? 'Expense'); ?></td>
                                <td class="text-center"><span class="badge badge-secondary"><?php echo htmlspecialchars($exp['payment_method']); ?></span></td>
                                <td class="text-center"><?php echo htmlspecialchars($exp['reference_no'] ?? '-'); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($exp['notes'] ?? ''); ?></td>
                                <td class="text-right font-weight-bold text-danger">Rs. <?php echo number_format(floatval($exp['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="<?php echo (!empty($is_station_summary)) ? '7' : '6'; ?>" class="text-right text-danger">TOTAL EXPENSES:</td>
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
