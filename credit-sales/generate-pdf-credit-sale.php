<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../include/config.php';
require_once '../include/permissions.php';

check_access('credit_sales', 'show');

$target_date  = $_GET['date'] ?? '';
$target_shift = intval($_GET['shift_id'] ?? 0);
if (empty($target_date)) {
    echo 'Error: Date parameter is required.';
    exit;
}

$date_safe = mysqli_real_escape_string($connection, $target_date);
$display_date = date('d-m-Y', strtotime($target_date));

$shift_clause = ($target_shift > 0) ? " AND mrcs.shift_id = '$target_shift'" : "";

// Fetch slips for this date and shift
$sql = "SELECT mrcs.*,
               sh.name AS shift_name,
               c.name AS customer_name,
               c.phone AS customer_phone,
               n.name AS nozzle_name,
               i.name AS item_name
        FROM tbl_meter_reading_credit_sales mrcs
        LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
        LEFT JOIN tbl_customers c ON (mrcs.account_number = c.id)
        LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
        LEFT JOIN tbl_items i ON (n.item_id = i.id)
        WHERE mrcs.slip_date = '$date_safe' $shift_clause AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')
        ORDER BY mrcs.id ASC";
$res = mysqli_query($connection, $sql);

$slips = [];
$shift_name = '';
$tot_qty = 0;
$tot_amt = 0;
$tot_charge = 0;
$tot_giving_qty = 0;
$tot_giving_charge = 0;
$tot_received_qty = 0;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        if (empty($shift_name) && !empty($r['shift_name'])) {
            $shift_name = $r['shift_name'];
        }
        $slips[] = $r;
        $q = floatval($r['quantity']);
        $a = floatval($r['amount']);
        $c = floatval($r['charge_amount']);
        $tot_qty += $q;
        $tot_amt += $a;
        $tot_charge += $c;

        if ($r['slip_type'] === 'Temporary Slip') {
            if (intval($r['is_returned']) === 1) {
                $tot_received_qty += $q;
            } else {
                $tot_giving_qty += $q;
                $tot_giving_charge += $c;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Daily Credit Sales Statement - <?php echo $display_date; ?><?php echo !empty($shift_name) ? ' (' . htmlspecialchars($shift_name) . ')' : ''; ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 10mm 12mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 10px;
            font-size: 11px;
            background: #fff;
        }
        .header-table {
            width: 100%;
            border-bottom: 2.5px solid #04204e;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .station-title {
            font-size: 18px;
            font-weight: bold;
            color: #04204e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #b07800;
            margin-top: 3px;
        }
        .metric-cards {
            display: table;
            width: 100%;
            margin-bottom: 14px;
        }
        .metric-cell {
            display: table-cell;
            width: 25%;
            padding: 6px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .metric-cell .lbl {
            font-size: 9px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }
        .metric-cell .val {
            font-size: 13px;
            font-weight: bold;
            color: #04204e;
            margin-top: 2px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 15px;
        }
        .data-table th {
            background-color: #04204e;
            color: #ffffff;
            font-weight: bold;
            text-align: center;
            padding: 6px 4px;
            border: 1px solid #04204e;
            font-size: 9.5px;
        }
        .data-table td {
            padding: 5px 4px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .status-giving {
            color: #b45309;
            font-weight: bold;
            background: #fef3c7;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 8.5px;
            display: inline-block;
        }
        .status-received {
            color: #047857;
            font-weight: bold;
            background: #d1fae5;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 8.5px;
            display: inline-block;
        }
        .status-billed {
            color: #1e40af;
            font-weight: bold;
            background: #dbeafe;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 8.5px;
            display: inline-block;
        }
        .sig-section {
            margin-top: 25px;
            display: table;
            width: 100%;
        }
        .sig-box {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            border-top: 1px solid #475569;
            padding-top: 5px;
            font-size: 10px;
            font-weight: bold;
            color: #334155;
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 12px; text-align: right;">
        <button onclick="window.print()" style="padding: 6px 14px; background: #04204e; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
            🖨️ Print / Save PDF
        </button>
    </div>

    <!-- Station Header -->
    <table class="header-table">
        <tr>
            <td style="width: 65%;">
                <div class="station-title">Petrol Pump Management System</div>
                <div class="report-title">Daily Credit Sales Statement</div>
                <div style="font-size: 9.5px; color: #64748b; margin-top: 2px;">Station Operations &bull; Client Credit Slips Manifest</div>
            </td>
            <td style="width: 35%; text-align: right;">
                <div style="font-size: 11px; font-weight: bold;">Date: <span style="color: #04204e; font-size: 13px;"><?php echo $display_date; ?></span></div>
                <?php if (!empty($shift_name)): ?>
                <div style="font-size: 10.5px; font-weight: bold; color: #04204e; margin-top: 1px;">Shift: <span><?php echo htmlspecialchars($shift_name); ?></span></div>
                <?php endif; ?>
                <div style="font-size: 9.5px; color: #64748b; margin-top: 2px;">Generated: <?php echo date('d-m-Y H:i A'); ?></div>
                <div style="font-size: 9.5px; color: #64748b;">Total Slips: <?php echo count($slips); ?></div>
            </td>
        </tr>
    </table>

    <!-- Metrics Cards -->
    <div class="metric-cards">
        <div class="metric-cell">
            <div class="lbl">Total Fuel Volume</div>
            <div class="val"><?php echo number_format($tot_qty, 2); ?> Ltr</div>
        </div>
        <div class="metric-cell">
            <div class="lbl">Gross Fuel Amount</div>
            <div class="val">Rs. <?php echo number_format($tot_amt, 2); ?></div>
        </div>
        <div class="metric-cell" style="background: #fff5f5; border-color: #fca5a5;">
            <div class="lbl" style="color: #b91c1c;">Billable Charge (To Collect)</div>
            <div class="val" style="color: #b91c1c;">Rs. <?php echo number_format($tot_charge, 2); ?></div>
        </div>
        <div class="metric-cell" style="background: #fffdf5; border-color: #fde68a;">
            <div class="lbl" style="color: #b45309;">Giving Loan Fuel</div>
            <div class="val" style="color: #b45309;"><?php echo number_format($tot_giving_qty, 2); ?> Ltr</div>
        </div>
    </div>

    <!-- Slips Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25px;">#</th>
                <th style="width: 55px;">Slip #</th>
                <th style="width: 70px;">Slip Type</th>
                <th>Customer Account &amp; Name</th>
                <th style="width: 80px;">Vehicle No</th>
                <th style="width: 85px;">Nozzle / Item</th>
                <th style="width: 50px;">Qty</th>
                <th style="width: 50px;">Rate</th>
                <th style="width: 65px;">Fuel Rs.</th>
                <th style="width: 70px;">Charge Rs.</th>
                <th style="width: 85px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($slips)): 
            ?>
            <tr>
                <td colspan="11" style="text-align: center; color: #94a3b8; padding: 15px;">No credit sales recorded for this date.</td>
            </tr>
            <?php 
            else: 
                $c = 1;
                foreach ($slips as $s): 
                    $qty = floatval($s['quantity']);
                    $rate = floatval($s['rate']);
                    $amt = floatval($s['amount']);
                    $charge = floatval($s['charge_amount']);

                    $statusHtml = '';
                    if ($s['slip_type'] === 'Temporary Slip') {
                        if (intval($s['is_returned']) === 1) {
                            $statusHtml = '<span class="status-received">✓ Received (Settled)</span>';
                        } else {
                            $statusHtml = '<span class="status-giving">⏳ Giving (Loan)</span>';
                        }
                    } elseif ($s['slip_type'] === 'Balanced Slip') {
                        $statusHtml = '<span style="color:#0284c7; font-weight:bold; font-size:8.5px;">Pre-Paid (0.00)</span>';
                    } else {
                        $statusHtml = '<span class="status-billed">Billed</span>';
                    }
            ?>
            <tr>
                <td style="text-align: center;"><?php echo $c++; ?></td>
                <td style="text-align: center; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($s['slip_no'] ?? ''); ?></td>
                <td style="text-align: center; font-size: 9px; font-weight: bold;"><?php echo htmlspecialchars($s['slip_type'] ?? ''); ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($s['customer_name'] ?? 'Account #' . $s['account_number']); ?></strong>
                    <span style="font-size: 8.5px; color: #64748b;">(#<?php echo htmlspecialchars($s['account_number']); ?>)</span>
                </td>
                <td style="text-align: center; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($s['vehicle_number'] ?? ''); ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($s['nozzle_name'] ?? ''); ?> <span style="color: #64748b; font-size: 8.5px;">(<?php echo htmlspecialchars($s['item_name'] ?? ''); ?>)</span></td>
                <td style="text-align: right; font-weight: bold;"><?php echo number_format($qty, 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($rate, 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($amt, 2); ?></td>
                <td style="text-align: right; font-weight: bold; color: #b91c1c;"><?php echo number_format($charge, 2); ?></td>
                <td style="text-align: center;"><?php echo $statusHtml; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f1f5f9; font-weight: bold; border-top: 2px solid #04204e;">
                <td colspan="6" style="text-align: right; font-size: 10.5px;">TOTALS:</td>
                <td style="text-align: right; color: #04204e; font-size: 10.5px;"><?php echo number_format($tot_qty, 2); ?></td>
                <td style="text-align: right;">—</td>
                <td style="text-align: right; font-size: 10.5px;">Rs. <?php echo number_format($tot_amt, 2); ?></td>
                <td style="text-align: right; font-size: 11px; color: #b91c1c;">Rs. <?php echo number_format($tot_charge, 2); ?></td>
                <td></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Signatures Section -->
    <div class="sig-section">
        <div class="sig-box">Prepared By (Shift Operator)</div>
        <div class="sig-box">Verified By (Accounts Officer)</div>
        <div class="sig-box">Authorized Station Manager</div>
    </div>

    <script>
    window.onload = function() {
        window.print();
    };
    </script>
</body>
</html>
