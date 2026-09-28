<?php
/**
 * PPMS - Card Machine Settlement Report PDF Companion
 *
 * Print-ready A4 executive summary report for POS Card Machine settlements.
 * Displays high-level settlement totals and matrices without raw transaction details.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/settings_helper.php';
require_once __DIR__ . '/../include/card_report_helper.php';

// RBAC Gatekeeper
if (!has_permission('reports', 'show') && !has_permission('card_sales', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);

// Filter inputs
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$fromDate       = trim($_GET['from_date'] ?? $default_from);
$toDate         = trim($_GET['to_date'] ?? $default_to);
$cardMachineId  = intval($_GET['card_machine_id'] ?? 0);
$shiftId        = intval($_GET['shift_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = $default_from;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = $default_to;
}

// Fetch report data
$report_data = get_card_report_data($connection, $fromDate, $toDate, $cardMachineId, $shiftId);
$overall        = $report_data['overall'];
$machine_matrix = $report_data['machine_matrix'];
$shift_summary  = $report_data['shift_summary'];

// Resolve labels
$selected_machine_name = 'All Card Machines';
if ($cardMachineId > 0) {
    $m_q = mysqli_query($connection, "SELECT name FROM tbl_card_machines WHERE id = '$cardMachineId' LIMIT 1");
    if ($m_q && $m_row = mysqli_fetch_assoc($m_q)) {
        $selected_machine_name = $m_row['name'];
    }
}

$selected_shift_name = 'Both Morning & Evening (All Shifts)';
if ($shiftId > 0) {
    $s_q = mysqli_query($connection, "SELECT name FROM tbl_shifts WHERE id = '$shiftId' LIMIT 1");
    if ($s_q && $s_row = mysqli_fetch_assoc($s_q)) {
        $selected_shift_name = $s_row['name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PPMS - Card Settlement Statement (<?php echo htmlspecialchars($fromDate); ?> to <?php echo htmlspecialchars($toDate); ?>)</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        body {
            font-family: 'Roboto', sans-serif;
            background: #fff;
            color: #333;
            font-size: 11px;
            line-height: 1.35;
        }
        .header-table {
            width: 100%;
            margin-bottom: 12px;
            border-bottom: 2px solid #04204e;
            padding-bottom: 8px;
        }
        .station-title {
            font-size: 18px;
            font-weight: 900;
            color: #04204e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .station-sub {
            font-size: 11px;
            color: #555;
            margin-top: 2px;
        }
        .report-badge {
            background: #04204e;
            color: #fff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .meta-box {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 11px;
        }
        .kpi-row {
            display: flex;
            margin-left: -5px;
            margin-right: -5px;
            margin-bottom: 14px;
        }
        .kpi-col {
            flex: 1;
            padding: 0 5px;
        }
        .kpi-box {
            border: 1px solid #cbd5e1;
            border-top: 3px solid #04204e;
            border-radius: 6px;
            padding: 8px 10px;
            background: #ffffff;
            text-align: center;
        }
        .kpi-box.green { border-top-color: #28a745; }
        .kpi-box.red   { border-top-color: #dc3545; }
        .kpi-box.blue  { border-top-color: #007bff; }
        .kpi-box.cyan  { border-top-color: #17a2b8; }

        .kpi-box-title {
            font-size: 9.5px;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.3px;
        }
        .kpi-box-val {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            margin: 4px 0 2px;
        }
        .kpi-box-sub {
            font-size: 9px;
            color: #94a3b8;
        }
        .section-header-pdf {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #04204e;
            border-bottom: 1.5px solid #04204e;
            padding-bottom: 3px;
            margin: 12px 0 6px;
        }
        table.pdf-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 12px;
        }
        table.pdf-table th {
            background-color: #04204e !important;
            color: #ffffff !important;
            padding: 6px 8px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
            border: 1px solid #04204e;
            vertical-align: middle;
        }
        table.pdf-table td {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }
        table.pdf-table tfoot th {
            background-color: #f1f5f9 !important;
            color: #04204e !important;
            font-weight: 800;
            border: 1px solid #cbd5e1;
            border-top: 1.5px solid #04204e;
            padding: 6px 8px;
            font-size: 10px;
        }
        .signatures-area {
            margin-top: 26px;
            display: flex;
            justify-content: space-between;
        }
        .sig-box {
            width: 28%;
            text-align: center;
            border-top: 1px dashed #64748b;
            padding-top: 5px;
            font-size: 10px;
            font-weight: 600;
            color: #475569;
        }
        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>

<div class="no-print text-center my-3">
    <button onclick="window.print()" class="btn btn-primary font-weight-bold mr-2"><i class="fas fa-print mr-1"></i> Print Statement</button>
    <button onclick="window.close()" class="btn btn-secondary font-weight-bold"><i class="fas fa-times mr-1"></i> Close</button>
</div>

<!-- Header Table -->
<table class="header-table">
    <tr>
        <td style="width: 65%; vertical-align: middle;">
            <div class="station-title"><?php echo htmlspecialchars($station_settings['station_name'] ?? 'PETROL PUMP MANAGEMENT SYSTEM'); ?></div>
            <div class="station-sub">
                <?php if (!empty($station_settings['station_address'])): ?>
                    <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($station_settings['station_address']); ?> &bull; 
                <?php endif; ?>
                <?php if (!empty($station_settings['station_phone'])): ?>
                    <i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($station_settings['station_phone']); ?>
                <?php endif; ?>
            </div>
        </td>
        <td style="width: 35%; text-align: right; vertical-align: middle;">
            <div class="report-badge"><i class="fas fa-credit-card mr-1"></i> CARD SETTLEMENT REPORT</div>
            <div style="font-size: 9.5px; color: #64748b; margin-top: 4px;">
                Generated: <?php echo date('d-M-Y H:i A'); ?>
            </div>
        </td>
    </tr>
</table>

<!-- Filter Meta Box -->
<div class="meta-box">
    <table style="width: 100%;">
        <tr>
            <td style="width: 35%;"><strong>Period:</strong> <?php echo date('d M Y', strtotime($fromDate)); ?> to <?php echo date('d M Y', strtotime($toDate)); ?></td>
            <td style="width: 35%;"><strong>Terminal:</strong> <?php echo htmlspecialchars($selected_machine_name); ?></td>
            <td style="width: 30%; text-align: right;"><strong>Shift:</strong> <?php echo htmlspecialchars($selected_shift_name); ?></td>
        </tr>
    </table>
</div>

<!-- Executive KPI Summary Cards -->
<div class="kpi-row">
    <div class="kpi-col">
        <div class="kpi-box blue">
            <div class="kpi-box-title">Total Fuel Sales</div>
            <div class="kpi-box-val"><?php echo number_format($overall['total_volume'], 2); ?> <small style="font-size:9px;">Ltr</small></div>
            <div class="kpi-box-sub">Dispensed Volume</div>
        </div>
    </div>
    <div class="kpi-col">
        <div class="kpi-box">
            <div class="kpi-box-title">Total Swipes</div>
            <div class="kpi-box-val"><?php echo number_format($overall['total_swipes']); ?></div>
            <div class="kpi-box-sub">Card Slips</div>
        </div>
    </div>
    <div class="kpi-col">
        <div class="kpi-box cyan">
            <div class="kpi-box-title">Gross Card Sales</div>
            <div class="kpi-box-val" style="color: #0369a1;">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></div>
            <div class="kpi-box-sub">Swiped Amount</div>
        </div>
    </div>
    <div class="kpi-col">
        <div class="kpi-box red">
            <div class="kpi-box-title">Bank Service Fee</div>
            <div class="kpi-box-val text-danger">Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></div>
            <div class="kpi-box-sub">Avg Fee: <?php echo $overall['effective_fee_percentage']; ?>%</div>
        </div>
    </div>
    <div class="kpi-col">
        <div class="kpi-box green">
            <div class="kpi-box-title">Net Card Revenue</div>
            <div class="kpi-box-val text-success">Rs. <?php echo number_format($overall['total_net_revenue'], 2); ?></div>
            <div class="kpi-box-sub">Payout (<?php echo $overall['effective_payout_percentage']; ?>%)</div>
        </div>
    </div>
</div>

<!-- Section A: Card Machine Summary Matrix -->
<div class="section-header-pdf"><i class="fas fa-cash-register mr-1"></i> POS Card Machine Performance Matrix</div>
<table class="pdf-table">
    <thead>
        <tr>
            <th style="width: 30px;" class="text-center">#</th>
            <th>Card Machine / POS Terminal</th>
            <th class="text-center" style="width: 70px;">Bank Fee %</th>
            <th class="text-center" style="width: 65px;">Swipes</th>
            <th class="text-right" style="width: 100px;">Volume (Ltr)</th>
            <th class="text-right" style="width: 120px;">Gross Sales (Rs.)</th>
            <th class="text-right" style="width: 110px;">Service Fee (Rs.)</th>
            <th class="text-right" style="width: 120px;">Net Revenue (Rs.)</th>
            <th class="text-center" style="width: 65px;">Payout %</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($machine_matrix)): ?>
            <tr>
                <td colspan="9" class="text-center py-2 text-muted">No card machine records found for the selected period.</td>
            </tr>
        <?php else: ?>
            <?php 
            $i = 1;
            foreach ($machine_matrix as $m): 
            ?>
                <tr>
                    <td class="text-center"><?php echo $i++; ?></td>
                    <td><strong><?php echo htmlspecialchars($m['machine_name']); ?></strong></td>
                    <td class="text-center"><?php echo number_format($m['charges_percentage'], 2); ?>%</td>
                    <td class="text-center font-weight-bold"><?php echo number_format($m['total_swipes']); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format($m['total_volume'], 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format($m['total_gross_amount'], 2); ?></td>
                    <td class="text-right text-danger">- <?php echo number_format($m['total_service_charges'], 2); ?></td>
                    <td class="text-right text-success font-weight-bold"><?php echo number_format($m['total_net_revenue'], 2); ?></td>
                    <td class="text-center"><?php echo $m['effective_payout_pct']; ?>%</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    <?php if (!empty($machine_matrix)): ?>
    <tfoot>
        <tr>
            <th colspan="3" class="text-right">Grand Total:</th>
            <th class="text-center"><?php echo number_format($overall['total_swipes']); ?></th>
            <th class="text-right"><?php echo number_format($overall['total_volume'], 2); ?></th>
            <th class="text-right">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></th>
            <th class="text-right text-danger">- Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></th>
            <th class="text-right text-success">Rs. <?php echo number_format($overall['total_net_revenue'], 2); ?></th>
            <th class="text-center"><?php echo $overall['effective_payout_percentage']; ?>%</th>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<!-- Section B: Daily Shift Settlement Rollup -->
<div class="section-header-pdf"><i class="fas fa-layer-group mr-1"></i> Daily Shift Settlement Rollup</div>
<table class="pdf-table">
    <thead>
        <tr>
            <th style="width: 80px;">Date</th>
            <th style="width: 85px;">Shift</th>
            <th>Card Machine</th>
            <th class="text-center" style="width: 60px;">Swipes</th>
            <th class="text-right" style="width: 90px;">Volume (Ltr)</th>
            <th class="text-right" style="width: 120px;">Gross Amount (Rs.)</th>
            <th class="text-right" style="width: 110px;">Service Fee (Rs.)</th>
            <th class="text-right" style="width: 120px;">Net Revenue (Rs.)</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($shift_summary)): ?>
            <tr>
                <td colspan="8" class="text-center py-2 text-muted">No shift settlements recorded.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($shift_summary as $s): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($s['sale_date'])); ?></td>
                    <td><strong><?php echo htmlspecialchars($s['shift_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($s['machine_name']); ?></td>
                    <td class="text-center"><?php echo number_format($s['total_swipes']); ?></td>
                    <td class="text-right"><?php echo number_format($s['total_volume'], 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format($s['total_gross_amount'], 2); ?></td>
                    <td class="text-right text-danger">- <?php echo number_format($s['total_service_charges'], 2); ?></td>
                    <td class="text-right text-success font-weight-bold"><?php echo number_format($s['total_net_revenue'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    <?php if (!empty($shift_summary)): ?>
    <tfoot>
        <tr>
            <th colspan="3" class="text-right">Grand Total:</th>
            <th class="text-center"><?php echo number_format($overall['total_swipes']); ?></th>
            <th class="text-right"><?php echo number_format($overall['total_volume'], 2); ?></th>
            <th class="text-right">Rs. <?php echo number_format($overall['total_gross_amount'], 2); ?></th>
            <th class="text-right text-danger">- Rs. <?php echo number_format($overall['total_service_charges'], 2); ?></th>
            <th class="text-right text-success">Rs. <?php echo number_format($overall['total_net_revenue'], 2); ?></th>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<!-- Signatures Area -->
<div class="signatures-area">
    <div class="sig-box">Prepared By (Cashier / Operator)</div>
    <div class="sig-box">Verified By (Shift Supervisor)</div>
    <div class="sig-box">Approved By (Station Auditor / Owner)</div>
</div>

<script>
window.onload = function() {
    window.print();
};
</script>
</body>
</html>
