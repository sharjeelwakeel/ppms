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
require_once __DIR__ . '/../include/settings_helper.php';

// RBAC Gatekeeper
if (!has_permission('reports', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);

$nozzleId   = intval($_GET['nozzle_id'] ?? 0);
$shiftId    = intval($_GET['shift_id'] ?? 0);
$reportDate = trim($_GET['date'] ?? date('Y-m-d'));

$selected_nozzle = null;
if ($nozzleId > 0) {
    // Fetch Nozzle details
    $noz_q = mysqli_query($connection, "
        SELECT n.id, n.name, n.tank_id, n.item_id, n.start_reading,
               i.name AS item_name, t.tank_name
        FROM tbl_nozzles n
        LEFT JOIN tbl_items i ON n.item_id = i.id
        LEFT JOIN tbl_tanks t ON n.tank_id = t.id
        WHERE n.id = '$nozzleId'
        LIMIT 1
    ");
    $selected_nozzle = ($noz_q) ? mysqli_fetch_assoc($noz_q) : null;
}

$selected_shift_name = 'All Shifts (Combined)';
if ($shiftId > 0) {
    $sh_q = mysqli_query($connection, "SELECT name FROM tbl_shifts WHERE id = '$shiftId' LIMIT 1");
    if ($sh_q && $sh_row = mysqli_fetch_assoc($sh_q)) {
        $selected_shift_name = $sh_row['name'];
    }
}

require_once __DIR__ . '/../include/nozzle_report_helper.php';

// Fetch nozzle report data: single nozzle if chosen, otherwise station-wide summary across all nozzles
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
    <title><?php echo ($selected_nozzle) ? ('Daily Nozzle Report - ' . htmlspecialchars($selected_nozzle['name'])) : 'Station-Wide Daily Nozzle Summary & Audit Report'; ?></title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; padding: 0; line-height: 1.35; }
        .header-table { width: 100%; border-bottom: 2px solid #04204e; margin-bottom: 12px; padding-bottom: 8px; }
        .header-logo { max-height: 55px; max-width: 140px; }
        .station-title { font-size: 16px; font-weight: bold; color: #04204e; text-transform: uppercase; margin: 0; }
        .station-sub { font-size: 10px; color: #555; margin: 2px 0 0 0; }
        .report-badge { background: #04204e; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block; }
        
        .meta-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }
        .meta-box table { width: 100%; font-size: 10.5px; }
        .meta-box td { padding: 2px 4px; }

        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .summary-table th, .summary-table td { border: 1px solid #cbd5e1; padding: 6px 8px; font-size: 10.5px; }
        .summary-table th { background: #04204e; color: #fff; font-weight: bold; text-align: left; }
        
        .section-title { font-size: 11.5px; font-weight: bold; color: #04204e; margin: 10px 0 4px 0; border-bottom: 1.5px solid #04204e; padding-bottom: 2px; }
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
        .data-table th, .data-table td { border: 1px solid #e2e8f0; padding: 4px 6px; }
        .data-table th { background: #f1f5f9; color: #04204e; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-weight-bold { font-weight: bold; }

        .sig-section { margin-top: 30px; width: 100%; display: flex; justify-content: space-between; page-break-inside: avoid; }
        .sig-box { width: 30%; border-top: 1px solid #000; text-align: center; font-size: 10px; padding-top: 4px; font-weight: bold; }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table class="header-table">
        <tr>
            <td style="width: 20%; vertical-align: middle;">
                <?php if ($hasLogo): ?>
                    <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" class="header-logo" alt="Logo">
                <?php else: ?>
                    <div style="font-size: 24px; color: #04204e; font-weight: 900;">PPMS</div>
                <?php endif; ?>
            </td>
            <td style="width: 55%; vertical-align: middle; text-align: center;">
                <h1 class="station-title"><?php echo htmlspecialchars($station_settings['name'] ?? 'Petrol Pump Management System'); ?></h1>
                <p class="station-sub">
                    <?php echo htmlspecialchars($station_settings['address'] ?? ''); ?>
                    <?php if (!empty($station_settings['phone'])): ?> | Ph: <?php echo htmlspecialchars($station_settings['phone']); ?><?php endif; ?>
                    <?php if (!empty($station_settings['ntn_number'])): ?> | NTN: <?php echo htmlspecialchars($station_settings['ntn_number']); ?><?php endif; ?>
                </p>
                <div style="font-size: 13px; font-weight: bold; color: #04204e; margin-top: 4px;">
                    <?php echo ($selected_nozzle) ? 'DAILY NOZZLE PERFORMANCE &amp; SETTLEMENT REPORT' : 'STATION-WIDE DAILY NOZZLE SUMMARY &amp; AUDIT REPORT'; ?>
                </div>
            </td>
            <td style="width: 25%; vertical-align: middle; text-align: right;">
                <div class="report-badge"><?php echo ($selected_nozzle) ? 'NOZZLE AUDIT' : 'STATION AUDIT'; ?></div>
                <div style="font-size: 9px; color: #666; margin-top: 4px;">Generated: <?php echo date('d-m-Y H:i A'); ?></div>
            </td>
        </tr>
    </table>

    <!-- Meta Information Box -->
    <div class="meta-box">
        <table>
            <?php if ($selected_nozzle): ?>
            <tr>
                <td style="width: 15%; font-weight: bold; color: #555;">Nozzle:</td>
                <td style="width: 35%; font-weight: bold; color: #04204e;"><?php echo htmlspecialchars($selected_nozzle['name']); ?></td>
                <td style="width: 15%; font-weight: bold; color: #555;">Report Date:</td>
                <td style="width: 35%; font-weight: bold;"><?php echo date('d-m-Y', strtotime($reportDate)); ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold; color: #555;">Fuel Item:</td>
                <td style="font-weight: bold;"><?php echo htmlspecialchars($selected_nozzle['item_name'] ?? 'Fuel'); ?></td>
                <td style="font-weight: bold; color: #555;">Selected Shift:</td>
                <td style="font-weight: bold;"><?php echo htmlspecialchars($selected_shift_name); ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold; color: #555;">Storage Tank:</td>
                <td style="font-weight: bold;"><?php echo htmlspecialchars($selected_nozzle['tank_name'] ?? 'N/A'); ?></td>
                <td style="font-weight: bold; color: #555;">Running Meter:</td>
                <td style="font-weight: bold; color: #04204e;"><?php echo number_format(floatval($selected_nozzle['start_reading']), 2); ?> Ltr</td>
            </tr>
            <?php else: ?>
            <tr>
                <td style="width: 15%; font-weight: bold; color: #555;">Scope:</td>
                <td style="width: 35%; font-weight: bold; color: #04204e;">All Dispensing Nozzles (Station Summary)</td>
                <td style="width: 15%; font-weight: bold; color: #555;">Report Date:</td>
                <td style="width: 35%; font-weight: bold;"><?php echo date('d-m-Y', strtotime($reportDate)); ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold; color: #555;">Nozzles Active:</td>
                <td style="font-weight: bold;"><?php echo count($nozzle_matrix ?? []); ?> Dispensing Nozzle(s)</td>
                <td style="font-weight: bold; color: #555;">Selected Shift:</td>
                <td style="font-weight: bold;"><?php echo htmlspecialchars($selected_shift_name); ?></td>
            </tr>
            <tr>
                <td style="font-weight: bold; color: #555;">Audit Status:</td>
                <td style="font-weight: bold; color: #04204e;"><?php echo htmlspecialchars($station_status ?? 'Balanced'); ?></td>
                <td style="font-weight: bold; color: #555;">Net Station Yield:</td>
                <td style="font-weight: bold; color: #04204e;">Rs. <?php echo number_format($net_nozzle_yield, 2); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Executive Summary Table -->
    <table class="summary-table">
        <thead>
            <tr>
                <th style="width: 28%;">Metric Description</th>
                <th style="width: 22%; text-align: right;">Dispensed Volume</th>
                <th style="width: 26%; text-align: right;">Monetary Value (Rs.)</th>
                <th style="width: 24%; text-align: center;">Audit Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>1. Physical Meter Sale (Overall)</strong></td>
                <td class="text-right font-weight-bold" style="color: #04204e;"><?php echo number_format($total_meter_litres, 2); ?> Ltr</td>
                <td class="text-right font-weight-bold" style="color: #04204e;">Rs. <?php echo number_format($total_meter_revenue, 2); ?></td>
                <td class="text-center">Totalizer Counter</td>
            </tr>
            <tr>
                <td><strong>2. Cash Sales Settled</strong></td>
                <td class="text-right"><?php echo number_format($total_cash_litres, 2); ?> Ltr</td>
                <td class="text-right">Rs. <?php echo number_format($total_cash_amount, 2); ?></td>
                <td class="text-center"><?php echo count($cash_rows); ?> Transaction(s)</td>
            </tr>
            <tr>
                <td><strong>3. Credit Voucher Sales</strong></td>
                <td class="text-right"><?php echo number_format($total_credit_issued_litres, 2); ?> Ltr</td>
                <td class="text-right">Rs. <?php echo number_format($total_credit_amount, 2); ?></td>
                <td class="text-center"><?php echo count($credit_rows); ?> Slip(s)</td>
            </tr>
            <tr>
                <td><strong>4. Card POS Terminal Sales</strong></td>
                <td class="text-right"><?php echo number_format($total_card_litres, 2); ?> Ltr</td>
                <td class="text-right">Rs. <?php echo number_format($total_card_amount, 2); ?></td>
                <td class="text-center">Net: Rs. <?php echo number_format($total_card_net, 2); ?></td>
            </tr>
            <tr style="background: #f8fafc; font-weight: bold;">
                <td><strong>TOTAL SETTLEMENTS (2 + 3 + 4):</strong></td>
                <td class="text-right"><?php echo number_format($total_settled_litres, 2); ?> Ltr</td>
                <td class="text-right">Rs. <?php echo number_format($total_settled_amount, 2); ?></td>
                <td class="text-center">
                    <?php if (abs($volume_variance) < 0.05): ?>
                        <span style="color: #28a745; font-weight: bold;">Reconciled</span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-weight: bold;">Var: <?php echo number_format($volume_variance, 2); ?> L</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong style="color: #b91c1c;"><?php echo (!empty($is_station_summary)) ? '5. Total Operating Expenses' : '5. Nozzle Maintenance Expenses'; ?></strong></td>
                <td class="text-right text-muted">&mdash;</td>
                <td class="text-right font-weight-bold" style="color: #b91c1c;">Rs. <?php echo number_format($total_nozzle_expenses, 2); ?></td>
                <td class="text-center"><?php echo count($expense_rows); ?> Expense(s)</td>
            </tr>
            <tr style="background: #eef2ff; font-weight: bold; font-size: 11px;">
                <td style="color: #04204e;"><?php echo (!empty($is_station_summary)) ? 'NET STATION OPERATING YIELD:' : 'NET NOZZLE OPERATING YIELD:'; ?></td>
                <td class="text-right text-muted">&mdash;</td>
                <td class="text-right" style="color: #04204e; font-size: 12px;">Rs. <?php echo number_format($net_nozzle_yield, 2); ?></td>
                <td class="text-center" style="color: #04204e;">(Revenue &minus; Expenses)</td>
            </tr>
        </tbody>
    </table>

    <?php if (!empty($is_station_summary) && !empty($nozzle_matrix)): ?>
    <!-- Comparative Nozzle Audit Matrix -->
    <div class="section-title">Dispensing Nozzles Comparative Audit Matrix</div>
    <table class="data-table" style="margin-bottom: 14px;">
        <thead>
            <tr>
                <th>#</th>
                <th>Nozzle</th>
                <th>Product</th>
                <th>Opening</th>
                <th>Closing</th>
                <th>Net Sale (Ltr)</th>
                <th>Revenue (Rs.)</th>
                <th>Cash (Rs.)</th>
                <th>Credit (Rs.)</th>
                <th>Card (Rs.)</th>
                <th>Settled (Rs.)</th>
                <th>Variance (Rs.)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $pdf_nm_i = 1; foreach ($nozzle_matrix as $nm): 
                $op_txt = ($nm['min_opening_meter'] !== null) ? number_format($nm['min_opening_meter'], 2) : number_format($nm['start_reading'], 2);
                $cl_txt = ($nm['max_closing_meter'] !== null) ? number_format($nm['max_closing_meter'], 2) : '-';
            ?>
            <tr>
                <td class="text-center"><?php echo $pdf_nm_i++; ?></td>
                <td class="font-weight-bold"><?php echo htmlspecialchars($nm['nozzle_name']); ?></td>
                <td><?php echo htmlspecialchars($nm['item_name']); ?></td>
                <td class="text-right"><?php echo $op_txt; ?></td>
                <td class="text-right font-weight-bold"><?php echo $cl_txt; ?></td>
                <td class="text-right font-weight-bold" style="color:#04204e;"><?php echo number_format($nm['meter_litres'], 2); ?></td>
                <td class="text-right font-weight-bold">Rs. <?php echo number_format($nm['meter_revenue'], 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($nm['cash_amount'], 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($nm['credit_amount'], 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($nm['card_amount'], 2); ?></td>
                <td class="text-right font-weight-bold">Rs. <?php echo number_format($nm['settled_amount'], 2); ?></td>
                <td class="text-right font-weight-bold" style="color: <?php echo ($nm['financial_variance'] < -0.01) ? '#dc3545' : (($nm['financial_variance'] > 0.01) ? '#b45309' : '#28a745'); ?>;">
                    <?php echo ($nm['financial_variance'] >= 0 ? '+' : '') . number_format($nm['financial_variance'], 2); ?>
                </td>
                <td class="text-center font-weight-bold"><?php echo htmlspecialchars($nm['status']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background: #f1f5f9; font-weight: bold;">
                <td colspan="5" class="text-right">TOTAL STATION:</td>
                <td class="text-right" style="color:#04204e;"><?php echo number_format($total_meter_litres, 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($total_meter_revenue, 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($total_cash_amount, 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($total_credit_amount, 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($total_card_amount, 2); ?></td>
                <td class="text-right">Rs. <?php echo number_format($total_settled_amount, 2); ?></td>
                <td class="text-right" style="color: <?php echo ($financial_variance < -0.01) ? '#dc3545' : (($financial_variance > 0.01) ? '#b45309' : '#28a745'); ?>;">
                    <?php echo ($financial_variance >= 0 ? '+' : '') . number_format($financial_variance, 2); ?>
                </td>
                <td class="text-center"><?php echo htmlspecialchars($station_status ?? 'Balanced'); ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- 1. Meter Reading Table -->
    <div class="section-title">1. Physical Meter Reading Audit</div>
    <table class="data-table">
        <thead>
            <tr>
                <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
                <th>Date</th>
                <th>Shift</th>
                <th>Opening Meter</th>
                <th>Closing Meter</th>
                <th>Sale (Ltr)</th>
                <th>Test (Ltr)</th>
                <th>Net Sale (Ltr)</th>
                <th>Fuel Rate</th>
                <th>Total Value (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($meter_rows)): ?>
                <tr><td colspan="<?php echo (!empty($is_station_summary)) ? '10' : '9'; ?>" class="text-center text-muted py-2">No meter readings recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($meter_rows as $mr): ?>
                <tr>
                    <?php if (!empty($is_station_summary)): ?>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($mr['nozzle_name'] ?? 'N/A'); ?></td>
                    <?php endif; ?>
                    <td class="text-center"><?php echo date('d-m-Y', strtotime($mr['date'])); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($mr['shift_name'] ?? 'General'); ?></td>
                    <td class="text-right"><?php echo number_format(floatval($mr['last_reading']), 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format(floatval($mr['current_reading']), 2); ?></td>
                    <td class="text-right"><?php echo number_format(floatval($mr['sale_reading']), 2); ?></td>
                    <td class="text-right"><?php echo number_format(floatval($mr['test_reading']), 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format(floatval($mr['net_sale']), 2); ?></td>
                    <td class="text-right">Rs. <?php echo number_format(floatval($mr['price']), 2); ?></td>
                    <td class="text-right font-weight-bold">Rs. <?php echo number_format(floatval($mr['amount']), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 2. Cash & Credit & Card Settlements -->
    <table style="width: 100%; border: none; margin-bottom: 8px;">
        <tr>
            <td style="width: 49%; vertical-align: top; padding: 0;">
                <div class="section-title">2. Cash Sales Log</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Litres</th>
                            <th>Rate</th>
                            <th>Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cash_rows)): ?>
                            <tr><td colspan="<?php echo (!empty($is_station_summary)) ? '6' : '5'; ?>" class="text-center text-muted">No cash entries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cash_rows as $cs): ?>
                            <tr>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="font-weight-bold"><?php echo htmlspecialchars($cs['nozzle_name'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td class="text-center"><?php echo date('d-m-Y', strtotime($cs['sale_date'])); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($cs['shift_name'] ?? 'General'); ?></td>
                                <td class="text-right font-weight-bold"><?php echo number_format(floatval($cs['quantity']), 2); ?></td>
                                <td class="text-right"><?php echo number_format(floatval($cs['rate']), 2); ?></td>
                                <td class="text-right font-weight-bold">Rs. <?php echo number_format(floatval($cs['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; vertical-align: top; padding: 0;">
                <div class="section-title">3. Card Terminal Swipes</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Terminal</th>
                            <th>Litres</th>
                            <th>Gross (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($card_rows)): ?>
                            <tr><td colspan="<?php echo (!empty($is_station_summary)) ? '6' : '5'; ?>" class="text-center text-muted">No card swipes.</td></tr>
                        <?php else: ?>
                            <?php foreach ($card_rows as $cd): ?>
                            <tr>
                                <?php if (!empty($is_station_summary)): ?>
                                    <td class="font-weight-bold"><?php echo htmlspecialchars($cd['nozzle_name'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td class="text-center"><?php echo date('d-m-Y', strtotime($cd['sale_date'])); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($cd['shift_name'] ?? 'General'); ?></td>
                                <td><?php echo htmlspecialchars($cd['machine_name'] ?? 'POS'); ?></td>
                                <td class="text-right font-weight-bold"><?php echo number_format(floatval($cd['quantity']), 2); ?></td>
                                <td class="text-right font-weight-bold">Rs. <?php echo number_format(floatval($cd['amount']), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <!-- 4. Credit Sales Slips -->
    <div class="section-title">4. Credit Voucher Slips</div>
    <table class="data-table">
        <thead>
            <tr>
                <?php if (!empty($is_station_summary)): ?><th>Nozzle</th><?php endif; ?>
                <th>Date</th>
                <th>Shift</th>
                <th>Slip No</th>
                <th>Type &amp; Settlement</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Rate</th>
                <th>Issued (Ltr)</th>
                <th>Fuel Value (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($credit_rows)): ?>
                <tr><td colspan="<?php echo (!empty($is_station_summary)) ? '10' : '9'; ?>" class="text-center text-muted">No credit slips recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($credit_rows as $cr): 
                    $s_date = !empty($cr['sale_date']) ? $cr['sale_date'] : $cr['slip_date'];
                    $iqty = floatval($cr['issue_quantity'] > 0 ? $cr['issue_quantity'] : $cr['quantity']);
                    $st = $cr['slip_type'];
                    $wasoli = floatval($cr['wasoli'] ?? 0);
                    $bal1 = floatval($cr['balance_1'] ?? 0);
                    $chgAmt = floatval($cr['charge_amount'] ?? 0);
                ?>
                <tr>
                    <?php if (!empty($is_station_summary)): ?>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($cr['nozzle_name'] ?? 'N/A'); ?></td>
                    <?php endif; ?>
                    <td class="text-center"><?php echo date('d-m-Y', strtotime($s_date)); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($cr['shift_name'] ?? 'General'); ?></td>
                    <td class="text-center font-weight-bold"><?php echo htmlspecialchars($cr['slip_no']); ?></td>
                    <td class="text-center">
                        <?php if ($st === 'Balanced Slip'): ?>
                            <strong>Balanced</strong> (Prepaid)
                            <?php if (!empty($cr['ref_slip_no'])): ?><div style="font-size:8px; color:#555;">From #<?php echo htmlspecialchars($cr['ref_slip_no']); ?></div><?php endif; ?>
                        <?php elseif ($st === 'Temporary Slip'): ?>
                            <span style="color:#b91c1c; font-weight:bold;">Temporary (Loan)</span>
                        <?php else: ?>
                            Permanent
                            <?php if ($wasoli > 0): ?><div style="font-size:8px; color:#b45309; font-weight:bold;">Settled #<?php echo htmlspecialchars($cr['temp_slip_no'] ?: $cr['temp_slip_id']); ?> (<?php echo number_format($wasoli, 2); ?>L)</div><?php endif; ?>
                            <?php if ($bal1 > 0): ?><div style="font-size:8px; color:#6b7280;">Bal: <?php echo number_format($bal1, 2); ?>L</div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($cr['customer_name'] ?? '-'); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($cr['vehicle_number']); ?></td>
                    <td class="text-right"><?php echo number_format(floatval($cr['rate']), 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format($iqty, 2); ?></td>
                    <td class="text-right font-weight-bold">
                        Rs. <?php echo number_format(floatval($cr['amount']), 2); ?>
                        <?php if ($st === 'Balanced Slip'): ?>
                            <div style="font-size:8px; color:#6b7280; font-style:italic;">(Prepaid)</div>
                        <?php elseif ($chgAmt > 0 && abs($chgAmt - floatval($cr['amount'])) > 0.01): ?>
                            <div style="font-size:8px; color:#b91c1c; font-weight:bold;">Billed: Rs. <?php echo number_format($chgAmt, 2); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 5. Nozzle Expenses -->
    <?php if (!empty($expense_rows)): ?>
    <div class="section-title" style="color: #b91c1c; border-color: #b91c1c;">5. Operating Expenses</div>
    <table class="data-table">
        <thead>
            <tr>
                <?php if (!empty($is_station_summary)): ?><th>Nozzle / Scope</th><?php endif; ?>
                <th>Date</th>
                <th>Expense Category</th>
                <th>Payment Mode</th>
                <th>Reference No</th>
                <th>Notes / Details</th>
                <th>Amount (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expense_rows as $exp): ?>
            <tr>
                <?php if (!empty($is_station_summary)): ?>
                    <td class="font-weight-bold"><?php echo htmlspecialchars($exp['nozzle_name'] ?: 'Station General'); ?></td>
                <?php endif; ?>
                <td class="text-center"><?php echo date('d-m-Y', strtotime($exp['expense_date'])); ?></td>
                <td class="font-weight-bold"><?php echo htmlspecialchars($exp['expense_type_name'] ?? 'Expense'); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($exp['payment_method']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($exp['reference_no'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($exp['notes'] ?? ''); ?></td>
                <td class="text-right font-weight-bold" style="color: #b91c1c;">Rs. <?php echo number_format(floatval($exp['amount']), 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Signature Block -->
    <table style="width: 100%; margin-top: 40px; page-break-inside: avoid;">
        <tr>
            <td style="width: 30%; border-top: 1px solid #333; text-align: center; font-size: 10px; font-weight: bold; padding-top: 4px;">
                Prepared By (Shift Cashier)
            </td>
            <td style="width: 40%;"></td>
            <td style="width: 30%; border-top: 1px solid #333; text-align: center; font-size: 10px; font-weight: bold; padding-top: 4px;">
                Audited &amp; Approved (Station Manager)
            </td>
        </tr>
    </table>

    <script>
    window.onload = function() {
        window.print();
    };
    </script>
</body>
</html>
