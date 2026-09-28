<?php
/**
 * PPMS - Card Sales Recovery & Outstanding Balance Report
 *
 * Dedicated executive financial audit reconciling POS card settlements:
 * - Gross sales swiped on POS machines
 * - Net receivable expected from bank (after MDR fee)
 * - Amount received / deposited into station bank account ("How much get")
 * - Outstanding balance pending from bank ("How much balance")
 * - Filtered by Date Range, Card Machine, Shift, and Status.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/card_balance_report_helper.php';

// RBAC Gate
if (!has_permission('reports', 'show') && !has_permission('card_sales', 'show') && !has_permission('accounts', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

// Fetch active filter options
$active_machines = get_active_card_machines($connection);
$active_shifts   = get_active_shifts_list($connection);

// Date defaults: Current Month-to-Date
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$fromDate       = trim($_GET['from_date'] ?? $default_from);
$toDate         = trim($_GET['to_date'] ?? $default_to);
$cardMachineId  = intval($_GET['card_machine_id'] ?? 0);
$shiftId        = intval($_GET['shift_id'] ?? 0);
$statusFilter   = trim($_GET['status'] ?? 'all');

// Validate date formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = $default_from;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = $default_to;
}

// Fetch report data
$report_data = get_card_balance_report_data($connection, $fromDate, $toDate, $cardMachineId, $shiftId, $statusFilter);
$overall         = $report_data['overall'];
$machine_matrix  = $report_data['machine_matrix'];
$settlement_rows = $report_data['settlement_rows'];

// Resolve labels for header display
$selected_machine_name = 'All Card Machines';
if ($cardMachineId > 0) {
    foreach ($active_machines as $m) {
        if ($m['id'] == $cardMachineId) {
            $selected_machine_name = $m['name'];
            break;
        }
    }
}

$selected_shift_name = 'Both Morning & Evening (All Shifts)';
if ($shiftId > 0) {
    foreach ($active_shifts as $s) {
        if ($s['id'] == $shiftId) {
            $selected_shift_name = $s['name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Card Sales Recovery & Balance Report</title>
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
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.09);
        }
        .kpi-card.kpi-success { border-top-color: #28a745; }
        .kpi-card.kpi-primary { border-top-color: #007bff; }
        .kpi-card.kpi-warning { border-top-color: #ffc107; }
        .kpi-card.kpi-info    { border-top-color: #17a2b8; }
        .kpi-card.kpi-danger  { border-top-color: #dc3545; }
        .kpi-card.kpi-dark    { border-top-color: #04204e; }

        .kpi-title { font-size: 11px; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.5px; }
        .kpi-val   { font-size: 21px; font-weight: 700; color: #1a202c; margin: 6px 0 2px; }
        .kpi-sub   { font-size: 11.5px; color: #8898aa; }

        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            margin-bottom: 24px; overflow: hidden;
        }
        .section-header {
            padding: 12px 18px; border-bottom: 1px solid #edf2f7;
            display: flex; align-items: center; justify-content: space-between;
            background: #fafbfe;
        }
        .section-title { font-size: 13.5px; font-weight: 700; color: #04204e; margin: 0; text-transform: uppercase; letter-spacing: 0.4px; }

        .table-custom thead th {
            background-color: #04204e !important;
            color: #ffffff !important;
            font-size: 11.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: none;
            padding: 10px 12px;
            vertical-align: middle;
        }
        .table-custom tbody td {
            font-size: 12.5px;
            vertical-align: middle;
            padding: 9px 12px;
            border-top: 1px solid #edf2f7;
        }
        .table-custom tfoot th {
            background-color: #e9ecef !important;
            font-size: 12.5px;
            font-weight: 700;
            color: #04204e;
            padding: 10px 12px;
            border-top: 2px solid #04204e;
            vertical-align: middle;
        }
        .preset-badge {
            cursor: pointer;
            font-size: 11px;
            padding: 5px 10px;
            border-radius: 4px;
            margin-right: 5px;
            transition: all 0.2s;
        }
    </style>
</head>
<body>

<?php include '../include/navbar.php'; ?>

<div class="container-fluid px-4 py-3">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-hand-holding-usd mr-2"></i> Card Sales Recovery & Outstanding Balance Report</h4>
            <div style="font-size: 12px; opacity: 0.88;" class="mt-1">
                <span><i class="fas fa-calendar-alt mr-1"></i> Period: <strong><?php echo date('d M Y', strtotime($fromDate)); ?></strong> to <strong><?php echo date('d M Y', strtotime($toDate)); ?></strong></span>
                <span class="mx-2">|</span>
                <span><i class="fas fa-cash-register mr-1"></i> Machine: <strong><?php echo htmlspecialchars($selected_machine_name); ?></strong></span>
                <span class="mx-2">|</span>
                <span><i class="fas fa-layer-group mr-1"></i> Shift: <strong><?php echo htmlspecialchars($selected_shift_name); ?></strong></span>
                <span class="mx-2">|</span>
                <span><i class="fas fa-info-circle mr-1"></i> Status: <strong><?php echo strtoupper($statusFilter); ?></strong></span>
            </div>
        </div>
        <div>
            <a href="generate-pdf-card-balance-report.php?from_date=<?php echo urlencode($fromDate); ?>&to_date=<?php echo urlencode($toDate); ?>&card_machine_id=<?php echo urlencode($cardMachineId); ?>&shift_id=<?php echo urlencode($shiftId); ?>&status=<?php echo urlencode($statusFilter); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
                <i class="fas fa-file-pdf mr-1"></i> Print / PDF Export
            </a>
            <a href="card-balance-report.php" class="btn btn-outline-light font-weight-bold">
                <i class="fas fa-undo mr-1"></i> Reset
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <!-- Quick Presets -->
        <div class="d-flex align-items-center mb-3 pb-2 border-bottom">
            <span class="text-muted font-weight-bold mr-2" style="font-size: 12px;"><i class="fas fa-bolt text-warning mr-1"></i> Quick Presets:</span>
            <a href="card-balance-report.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>&card_machine_id=<?php echo $cardMachineId; ?>&shift_id=<?php echo $shiftId; ?>&status=<?php echo urlencode($statusFilter); ?>" class="badge <?php echo ($fromDate === date('Y-m-d') && $toDate === date('Y-m-d')) ? 'badge-primary' : 'badge-light border'; ?> preset-badge">
                <i class="fas fa-calendar-day mr-1"></i> Today
            </a>
            <a href="card-balance-report.php?from_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>&to_date=<?php echo date('Y-m-d', strtotime('-1 day')); ?>&card_machine_id=<?php echo $cardMachineId; ?>&shift_id=<?php echo $shiftId; ?>&status=<?php echo urlencode($statusFilter); ?>" class="badge <?php echo ($fromDate === date('Y-m-d', strtotime('-1 day')) && $toDate === date('Y-m-d', strtotime('-1 day'))) ? 'badge-primary' : 'badge-light border'; ?> preset-badge">
                <i class="fas fa-history mr-1"></i> Yesterday
            </a>
            <a href="card-balance-report.php?from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-d'); ?>&card_machine_id=<?php echo $cardMachineId; ?>&shift_id=<?php echo $shiftId; ?>&status=<?php echo urlencode($statusFilter); ?>" class="badge <?php echo ($fromDate === date('Y-m-01') && $toDate === date('Y-m-d')) ? 'badge-primary' : 'badge-light border'; ?> preset-badge">
                <i class="fas fa-calendar mr-1"></i> Current Month
            </a>
            <a href="card-balance-report.php?from_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&to_date=<?php echo date('Y-m-d'); ?>&card_machine_id=<?php echo $cardMachineId; ?>&shift_id=<?php echo $shiftId; ?>&status=<?php echo urlencode($statusFilter); ?>" class="badge <?php echo ($fromDate === date('Y-m-d', strtotime('-30 days')) && $toDate === date('Y-m-d')) ? 'badge-primary' : 'badge-light border'; ?> preset-badge">
                <i class="fas fa-chart-line mr-1"></i> Last 30 Days
            </a>
        </div>

        <form action="card-balance-report.php" method="GET" class="form-row align-items-end" id="balanceReportFilterForm">
            <!-- From Date -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="font-weight-bold text-secondary small mb-1"><i class="fas fa-calendar-alt mr-1"></i> From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>" required>
            </div>

            <!-- To Date -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="font-weight-bold text-secondary small mb-1"><i class="fas fa-calendar-alt mr-1"></i> To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>" required>
            </div>

            <!-- Card Machine Filter -->
            <div class="col-md-3 col-sm-6 mb-2">
                <label class="font-weight-bold text-secondary small mb-1"><i class="fas fa-cash-register mr-1"></i> Card Machine / POS Terminal</label>
                <select name="card_machine_id" class="form-control form-control-sm">
                    <option value="0" <?php echo ($cardMachineId === 0) ? 'selected' : ''; ?>>-- All Card Machines --</option>
                    <?php foreach ($active_machines as $m): ?>
                        <option value="<?php echo $m['id']; ?>" <?php echo ($cardMachineId === intval($m['id'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Shift Filter -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="font-weight-bold text-secondary small mb-1"><i class="fas fa-layer-group mr-1"></i> Shift</label>
                <select name="shift_id" class="form-control form-control-sm">
                    <option value="0" <?php echo ($shiftId === 0) ? 'selected' : ''; ?>>-- Both Shifts --</option>
                    <?php foreach ($active_shifts as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo ($shiftId === intval($s['id'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Filter -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="font-weight-bold text-secondary small mb-1"><i class="fas fa-filter mr-1"></i> Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>-- All Batches --</option>
                    <option value="outstanding" <?php echo ($statusFilter === 'outstanding') ? 'selected' : ''; ?>>Outstanding (Unpaid & Partial)</option>
                    <option value="paid" <?php echo ($statusFilter === 'paid') ? 'selected' : ''; ?>>Fully Paid</option>
                    <option value="unpaid" <?php echo ($statusFilter === 'unpaid') ? 'selected' : ''; ?>>Unpaid Only</option>
                    <option value="partial" <?php echo ($statusFilter === 'partial') ? 'selected' : ''; ?>>Partial Only</option>
                </select>
            </div>

            <!-- Submit Button -->
            <div class="col-md-1 col-sm-6 mb-2">
                <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Executive KPI Summary Cards -->
    <div class="row">
        <!-- 1. Total Gross Sales -->
        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
            <div class="kpi-card kpi-dark">
                <div>
                    <div class="kpi-title"><i class="fas fa-credit-card mr-1 text-dark"></i> Total Card Sales</div>
                    <div class="kpi-val">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></div>
                </div>
                <div class="kpi-sub"><?php echo number_format($overall['total_batches']); ?> Batches / <?php echo number_format($overall['total_swipes']); ?> Swipes</div>
            </div>
        </div>

        <!-- 2. Net Expected from Bank -->
        <div class="col-xl-2 col-md-6 col-sm-6 mb-3">
            <div class="kpi-card kpi-info">
                <div>
                    <div class="kpi-title"><i class="fas fa-file-invoice-dollar mr-1 text-info"></i> Net Expected</div>
                    <div class="kpi-val text-primary">Rs. <?php echo number_format($overall['total_net_expected'], 2); ?></div>
                </div>
                <div class="kpi-sub">Fee: Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></div>
            </div>
        </div>

        <!-- 3. Amount Received ("How Much Get") -->
        <div class="col-xl-3 col-md-6 col-sm-6 mb-3">
            <div class="kpi-card kpi-success">
                <div>
                    <div class="kpi-title"><i class="fas fa-hand-holding-usd mr-1 text-success"></i> Amount Received (Get)</div>
                    <div class="kpi-val text-success">Rs. <?php echo number_format($overall['total_paid_amount'], 2); ?></div>
                </div>
                <div class="kpi-sub">Deposited into Bank Accounts</div>
            </div>
        </div>

        <!-- 4. Outstanding Balance ("How Much Balance") -->
        <div class="col-xl-2 col-md-6 col-sm-6 mb-3">
            <div class="kpi-card kpi-danger">
                <div>
                    <div class="kpi-title"><i class="fas fa-hourglass-half mr-1 text-danger"></i> Balance Due</div>
                    <div class="kpi-val text-danger">Rs. <?php echo number_format($overall['total_balance_due'], 2); ?></div>
                </div>
                <div class="kpi-sub">Pending Bank Deposit</div>
            </div>
        </div>

        <!-- 5. Recovery Rate % -->
        <div class="col-xl-2 col-md-6 col-sm-12 mb-3">
            <div class="kpi-card <?php echo ($overall['recovery_rate_pct'] >= 100) ? 'kpi-success' : 'kpi-warning'; ?>">
                <div>
                    <div class="kpi-title"><i class="fas fa-chart-pie mr-1 text-warning"></i> Recovery Rate</div>
                    <div class="kpi-val <?php echo ($overall['recovery_rate_pct'] >= 100) ? 'text-success' : 'text-warning'; ?>"><?php echo $overall['recovery_rate_pct']; ?>%</div>
                </div>
                <div class="kpi-sub">Progress of Collection</div>
            </div>
        </div>
    </div>

    <!-- Section 1: POS Card Machine Recovery Summary -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-cash-register mr-2 text-primary"></i> POS Card Machine Recovery & Outstanding Summary
            </div>
            <span class="badge badge-light border text-muted font-weight-bold">
                <?php echo count($machine_matrix); ?> Machine(s) Recorded
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-custom table-hover table-bordered mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;" class="text-center">#</th>
                        <th>Card Machine / POS Terminal</th>
                        <th class="text-center" style="width: 90px;">Batches</th>
                        <th class="text-center" style="width: 90px;">Swipes</th>
                        <th class="text-right" style="width: 160px;">Gross Sales (Rs.)</th>
                        <th class="text-right text-danger" style="width: 140px;">Bank Fee (Rs.)</th>
                        <th class="text-right" style="width: 160px;">Net Expected (Rs.)</th>
                        <th class="text-right text-success" style="width: 170px;">Amount Received (Rs.)</th>
                        <th class="text-right text-danger" style="width: 170px;">Outstanding Balance (Rs.)</th>
                        <th class="text-center" style="width: 110px;">Recovery %</th>
                        <th class="text-center" style="width: 100px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($machine_matrix)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fa-2x mb-2 d-block text-secondary"></i>
                                No settlement batches found matching the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $sr = 1;
                        foreach ($machine_matrix as $m): 
                        ?>
                            <tr>
                                <td class="text-center font-weight-bold text-muted"><?php echo $sr++; ?></td>
                                <td>
                                    <strong><i class="fas fa-hdd mr-1 text-primary"></i> <?php echo htmlspecialchars($m['machine_name']); ?></strong>
                                </td>
                                <td class="text-center font-weight-bold"><?php echo number_format($m['total_batches']); ?></td>
                                <td class="text-center font-weight-bold"><?php echo number_format($m['total_swipes']); ?></td>
                                <td class="text-right font-weight-bold text-dark"><?php echo number_format($m['total_gross_amount'], 2); ?></td>
                                <td class="text-right text-danger font-weight-bold">- <?php echo number_format($m['total_service_charges'], 2); ?></td>
                                <td class="text-right font-weight-bold text-primary"><?php echo number_format($m['total_net_expected'], 2); ?></td>
                                <td class="text-right text-success font-weight-bold" style="font-size: 13px;">
                                    <?php echo number_format($m['total_paid_amount'], 2); ?>
                                </td>
                                <td class="text-right text-danger font-weight-bold" style="font-size: 13px;">
                                    <?php echo number_format($m['total_balance_due'], 2); ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo ($m['recovery_rate_pct'] >= 100) ? 'badge-success' : (($m['recovery_rate_pct'] > 0) ? 'badge-warning' : 'badge-danger'); ?> px-2">
                                        <?php echo $m['recovery_rate_pct']; ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['status'] === 'Paid'): ?>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Paid</span>
                                    <?php elseif ($m['status'] === 'Partial'): ?>
                                        <span class="badge badge-warning px-2 py-1 text-dark"><i class="fas fa-adjust mr-1"></i> Partial</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-2 py-1"><i class="fas fa-clock mr-1"></i> Unpaid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($machine_matrix)): ?>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-right">Grand Total:</th>
                        <th class="text-center"><?php echo number_format($overall['total_batches']); ?></th>
                        <th class="text-center"><?php echo number_format($overall['total_swipes']); ?></th>
                        <th class="text-right">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></th>
                        <th class="text-right text-danger">- Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></th>
                        <th class="text-right text-primary">Rs. <?php echo number_format($overall['total_net_expected'], 2); ?></th>
                        <th class="text-right text-success">Rs. <?php echo number_format($overall['total_paid_amount'], 2); ?></th>
                        <th class="text-right text-danger">Rs. <?php echo number_format($overall['total_balance_due'], 2); ?></th>
                        <th class="text-center"><?php echo $overall['recovery_rate_pct']; ?>%</th>
                        <th class="text-center">
                            <?php if ($overall['total_balance_due'] <= 0 && $overall['total_net_expected'] > 0): ?>
                                <span class="badge badge-success px-2">Fully Paid</span>
                            <?php else: ?>
                                <span class="badge badge-warning px-2 text-dark">Outstanding</span>
                            <?php endif; ?>
                        </th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Section 2: Daily Shift Settlement Breakdown -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-layer-group mr-2 text-info"></i> Daily Shift Settlement & Balance Rollup
            </div>
            <span class="badge badge-light border text-muted font-weight-bold">
                <?php echo count($settlement_rows); ?> Settlement Batch(es)
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-custom table-hover table-bordered mb-0">
                <thead>
                    <tr>
                        <th style="width: 110px;">Date</th>
                        <th style="width: 120px;">Shift</th>
                        <th>Card Machine</th>
                        <th class="text-center" style="width: 100px;">Batch #</th>
                        <th class="text-center" style="width: 80px;">Cards</th>
                        <th class="text-right" style="width: 150px;">Gross Amount (Rs.)</th>
                        <th class="text-right text-danger" style="width: 130px;">Bank Fee (Rs.)</th>
                        <th class="text-right" style="width: 150px;">Net Expected (Rs.)</th>
                        <th class="text-right text-success" style="width: 160px;">Amount Received (Rs.)</th>
                        <th class="text-right text-danger" style="width: 160px;">Balance Due (Rs.)</th>
                        <th class="text-center" style="width: 100px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($settlement_rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fa-2x mb-2 d-block text-secondary"></i>
                                No settlements found matching the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($settlement_rows as $row): ?>
                            <tr>
                                <td class="font-weight-bold text-dark">
                                    <?php echo date('d M Y', strtotime($row['settlement_date'])); ?>
                                </td>
                                <td>
                                    <span class="badge badge-info px-2 py-1">
                                        <i class="fas fa-clock mr-1"></i> <?php echo htmlspecialchars($row['shift_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><i class="fas fa-hdd mr-1 text-secondary"></i> <?php echo htmlspecialchars($row['machine_name']); ?></strong>
                                </td>
                                <td class="text-center font-weight-bold text-primary">
                                    #<?php echo htmlspecialchars($row['batch_no']); ?>
                                </td>
                                <td class="text-center font-weight-bold">
                                    <?php echo number_format($row['no_of_cards']); ?>
                                </td>
                                <td class="text-right font-weight-bold text-dark">
                                    <?php echo number_format($row['gross_amount'], 2); ?>
                                </td>
                                <td class="text-right text-danger font-weight-bold">
                                    - <?php echo number_format($row['service_charges'], 2); ?>
                                </td>
                                <td class="text-right font-weight-bold text-primary">
                                    <?php echo number_format($row['net_expected'], 2); ?>
                                </td>
                                <td class="text-right text-success font-weight-bold">
                                    <?php echo number_format($row['paid_amount'], 2); ?>
                                </td>
                                <td class="text-right text-danger font-weight-bold">
                                    <?php echo number_format($row['balance_due'], 2); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['payment_status'] === 'Paid' || $row['balance_due'] <= 0): ?>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Paid</span>
                                    <?php elseif ($row['payment_status'] === 'Partial' || ($row['paid_amount'] > 0 && $row['balance_due'] > 0)): ?>
                                        <span class="badge badge-warning px-2 py-1 text-dark"><i class="fas fa-adjust mr-1"></i> Partial</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-2 py-1"><i class="fas fa-clock mr-1"></i> Unpaid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($settlement_rows)): ?>
                <tfoot>
                    <tr>
                        <th colspan="4" class="text-right">Grand Total:</th>
                        <th class="text-center"><?php echo number_format($overall['total_swipes']); ?></th>
                        <th class="text-right">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></th>
                        <th class="text-right text-danger">- Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></th>
                        <th class="text-right text-primary">Rs. <?php echo number_format($overall['total_net_expected'], 2); ?></th>
                        <th class="text-right text-success">Rs. <?php echo number_format($overall['total_paid_amount'], 2); ?></th>
                        <th class="text-right text-danger">Rs. <?php echo number_format($overall['total_balance_due'], 2); ?></th>
                        <th class="text-center">
                            <?php if ($overall['total_balance_due'] <= 0 && $overall['total_net_expected'] > 0): ?>
                                <span class="badge badge-success px-2">Fully Paid</span>
                            <?php else: ?>
                                <span class="badge badge-warning px-2 text-dark">Outstanding</span>
                            <?php endif; ?>
                        </th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>
