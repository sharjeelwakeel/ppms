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

$nozzleId = intval($_GET['nozzle_id'] ?? 0);
$shiftId  = intval($_GET['shift_id'] ?? 0);
$fromDate = trim($_GET['from_date'] ?? date('Y-m-d'));
$toDate   = trim($_GET['to_date'] ?? date('Y-m-d'));

if ($nozzleId <= 0) {
    die("Error: Dispensing nozzle is required to generate this report.");
}

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
if (!$selected_nozzle) {
    die("Error: Selected nozzle not found.");
}

$selected_shift_name = 'All Shifts (Combined)';
if ($shiftId > 0) {
    $sh_q = mysqli_query($connection, "SELECT name FROM tbl_shifts WHERE id = '$shiftId' LIMIT 1");
    if ($sh_q && $sh_row = mysqli_fetch_assoc($sh_q)) {
        $selected_shift_name = $sh_row['name'];
    }
}

$from_safe = mysqli_real_escape_string($connection, $fromDate);
$to_safe   = mysqli_real_escape_string($connection, $toDate);
$shift_filter_mr = ($shiftId > 0) ? " AND mr.shift_id = '$shiftId'" : "";

// 1. Fetch Meter Readings
$sql_mr = "
    SELECT mrd.*, mr.date, mr.shift_id, sh.name AS shift_name, st.name AS staff_name
    FROM tbl_meter_reading_details mrd
    JOIN tbl_meter_readings mr ON mrd.meter_reading_id = mr.id
    LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
    LEFT JOIN tbl_staff st ON mrd.staff_id = st.id
    WHERE mrd.nozzle_id = '$nozzleId'
      AND mr.date BETWEEN '$from_safe' AND '$to_safe'
      $shift_filter_mr
      AND (mr.deleted_at IS NULL OR mr.deleted_at = '0000-00-00 00:00:00')
    ORDER BY mr.date ASC, mr.shift_id ASC, mr.id ASC
";
$res_mr = mysqli_query($connection, $sql_mr);
$meter_rows = [];
$total_meter_litres  = 0.00;
$total_meter_revenue = 0.00;
$total_test_litres   = 0.00;
if ($res_mr) {
    while ($row = mysqli_fetch_assoc($res_mr)) {
        $meter_rows[] = $row;
        $total_meter_litres  += floatval($row['net_sale']);
        $total_meter_revenue += floatval($row['amount']);
        $total_test_litres   += floatval($row['test_reading']);
    }
}

// 2. Fetch Cash Sales
$shift_filter_cs = ($shiftId > 0) ? " AND cs.shift_id = '$shiftId'" : "";
$sql_cs = "
    SELECT cs.*, sh.name AS shift_name
    FROM tbl_meter_reading_cash_sales cs
    LEFT JOIN tbl_shifts sh ON cs.shift_id = sh.id
    WHERE cs.nozzle_id = '$nozzleId'
      AND cs.sale_date BETWEEN '$from_safe' AND '$to_safe'
      $shift_filter_cs
      AND (cs.deleted_at IS NULL OR cs.deleted_at = '0000-00-00 00:00:00')
    ORDER BY cs.sale_date ASC, cs.shift_id ASC, cs.id ASC
";
$res_cs = mysqli_query($connection, $sql_cs);
$cash_rows = [];
$total_cash_litres = 0.00;
$total_cash_amount = 0.00;
if ($res_cs) {
    while ($row = mysqli_fetch_assoc($res_cs)) {
        $cash_rows[] = $row;
        $total_cash_litres += floatval($row['quantity']);
        $total_cash_amount += floatval($row['amount']);
    }
}

// 3. Fetch Credit Sales
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
$credit_rows = [];
$total_credit_issued_litres = 0.00;
$total_credit_amount        = 0.00;
if ($res_cr) {
    while ($row = mysqli_fetch_assoc($res_cr)) {
        $credit_rows[] = $row;
        $iqty = floatval($row['issue_quantity'] > 0 ? $row['issue_quantity'] : $row['quantity']);
        $total_credit_issued_litres += $iqty;
        $total_credit_amount        += floatval($row['amount']);
    }
}

// 4. Fetch Card Sales
$shift_filter_cd = ($shiftId > 0) ? " AND cd.shift_id = '$shiftId'" : "";
$sql_cd = "
    SELECT cd.*, sh.name AS shift_name, cm.name AS machine_name
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
$card_rows = [];
$total_card_litres = 0.00;
$total_card_amount = 0.00;
$total_card_charges = 0.00;
$total_card_net     = 0.00;
if ($res_cd) {
    while ($row = mysqli_fetch_assoc($res_cd)) {
        $card_rows[] = $row;
        $total_card_litres  += floatval($row['quantity']);
        $total_card_amount  += floatval($row['amount']);
        $total_card_charges += floatval($row['service_charges']);
        $total_card_net     += floatval($row['net_amount'] > 0 ? $row['net_amount'] : $row['amount']);
    }
}

// 5. Fetch Nozzle Expenses
$sql_exp = "
    SELECT e.*, et.name AS expense_type_name
    FROM tbl_expenses e
    LEFT JOIN tbl_expense_types et ON e.expense_type_id = et.id
    WHERE e.nozzle_id = '$nozzleId'
      AND e.expense_date BETWEEN '$from_safe' AND '$to_safe'
      AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')
    ORDER BY e.expense_date ASC, e.id ASC
";
$res_exp = mysqli_query($connection, $sql_exp);
$expense_rows = [];
$total_nozzle_expenses = 0.00;
if ($res_exp) {
    while ($row = mysqli_fetch_assoc($res_exp)) {
        $expense_rows[] = $row;
        $total_nozzle_expenses += floatval($row['amount']);
    }
}

$total_settled_litres = $total_cash_litres + $total_credit_issued_litres + $total_card_litres;
$total_settled_amount = $total_cash_amount + $total_credit_amount + $total_card_amount;
$volume_variance      = round($total_settled_litres - $total_meter_litres, 2);
$financial_variance   = round($total_settled_amount - $total_meter_revenue, 2);
$net_nozzle_yield     = $total_meter_revenue - $total_nozzle_expenses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily Nozzle Report - <?php echo htmlspecialchars($selected_nozzle['name']); ?></title>
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
                <div style="font-size: 13px; font-weight: bold; color: #04204e; margin-top: 4px;">DAILY NOZZLE PERFORMANCE &amp; SETTLEMENT REPORT</div>
            </td>
            <td style="width: 25%; vertical-align: middle; text-align: right;">
                <div class="report-badge">NOZZLE AUDIT</div>
                <div style="font-size: 9px; color: #666; margin-top: 4px;">Generated: <?php echo date('d-m-Y H:i A'); ?></div>
            </td>
        </tr>
    </table>

    <!-- Meta Information Box -->
    <div class="meta-box">
        <table>
            <tr>
                <td style="width: 15%; font-weight: bold; color: #555;">Nozzle:</td>
                <td style="width: 35%; font-weight: bold; color: #04204e;"><?php echo htmlspecialchars($selected_nozzle['name']); ?></td>
                <td style="width: 15%; font-weight: bold; color: #555;">Reporting Period:</td>
                <td style="width: 35%; font-weight: bold;"><?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?></td>
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
                <td><strong style="color: #b91c1c;">5. Nozzle Maintenance Expenses</strong></td>
                <td class="text-right text-muted">&mdash;</td>
                <td class="text-right font-weight-bold" style="color: #b91c1c;">Rs. <?php echo number_format($total_nozzle_expenses, 2); ?></td>
                <td class="text-center"><?php echo count($expense_rows); ?> Expense(s)</td>
            </tr>
            <tr style="background: #eef2ff; font-weight: bold; font-size: 11px;">
                <td style="color: #04204e;">NET NOZZLE OPERATING YIELD:</td>
                <td class="text-right text-muted">&mdash;</td>
                <td class="text-right" style="color: #04204e; font-size: 12px;">Rs. <?php echo number_format($net_nozzle_yield, 2); ?></td>
                <td class="text-center" style="color: #04204e;">(Revenue &minus; Expenses)</td>
            </tr>
        </tbody>
    </table>

    <!-- 1. Meter Reading Table -->
    <div class="section-title">1. Physical Meter Reading Audit</div>
    <table class="data-table">
        <thead>
            <tr>
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
                <tr><td colspan="9" class="text-center text-muted py-2">No meter readings recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($meter_rows as $mr): ?>
                <tr>
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
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Litres</th>
                            <th>Rate</th>
                            <th>Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cash_rows)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No cash entries.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cash_rows as $cs): ?>
                            <tr>
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
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Terminal</th>
                            <th>Litres</th>
                            <th>Gross (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($card_rows)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No card swipes.</td></tr>
                        <?php else: ?>
                            <?php foreach ($card_rows as $cd): ?>
                            <tr>
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
                <th>Date</th>
                <th>Shift</th>
                <th>Slip No</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Rate</th>
                <th>Issued (Ltr)</th>
                <th>Amount (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($credit_rows)): ?>
                <tr><td colspan="8" class="text-center text-muted">No credit slips recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($credit_rows as $cr): 
                    $s_date = !empty($cr['sale_date']) ? $cr['sale_date'] : $cr['slip_date'];
                    $iqty = floatval($cr['issue_quantity'] > 0 ? $cr['issue_quantity'] : $cr['quantity']);
                ?>
                <tr>
                    <td class="text-center"><?php echo date('d-m-Y', strtotime($s_date)); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($cr['shift_name'] ?? 'General'); ?></td>
                    <td class="text-center font-weight-bold"><?php echo htmlspecialchars($cr['slip_no']); ?></td>
                    <td><?php echo htmlspecialchars($cr['customer_name'] ?? '-'); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($cr['vehicle_number']); ?></td>
                    <td class="text-right"><?php echo number_format(floatval($cr['rate']), 2); ?></td>
                    <td class="text-right font-weight-bold"><?php echo number_format($iqty, 2); ?></td>
                    <td class="text-right font-weight-bold">Rs. <?php echo number_format(floatval($cr['amount']), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 5. Nozzle Expenses -->
    <?php if (!empty($expense_rows)): ?>
    <div class="section-title" style="color: #b91c1c; border-color: #b91c1c;">5. Itemized Nozzle Maintenance Expenses</div>
    <table class="data-table">
        <thead>
            <tr>
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
                <td class="text-center"><?php echo date('d-m-Y', strtotime($exp['expense_date'])); ?></td>
                <td class="font-weight-bold"><?php echo htmlspecialchars($exp['expense_type_name'] ?? 'Nozzle Expense'); ?></td>
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
