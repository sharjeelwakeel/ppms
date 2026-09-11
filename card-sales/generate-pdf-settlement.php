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

check_access('card_sales', 'show');

$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$where = "(s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND s.settlement_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND s.settlement_date <= '$to_safe'";
}

$sql = "SELECT s.*, cm.name AS machine_name 
        FROM tbl_card_sale_settlements s
        LEFT JOIN tbl_card_machines cm ON (s.card_machine_id = cm.id)
        WHERE $where
        ORDER BY s.settlement_date ASC, s.id ASC";
$res = mysqli_query($connection, $sql);

$items = [];
$tot_cards = 0;
$tot_gross = 0;
$tot_charges = 0;
$tot_net = 0;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $items[] = $r;
        $tot_cards += intval($r['no_of_cards']);
        $tot_gross += floatval($r['amount']);
        $tot_charges += floatval($r['service_charges']);
        $tot_net += floatval($r['net_amount']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Card Sale Settlements Statement - PPMS</title>
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
            color: #0284c7;
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
                <div class="report-title">Card Sale Settlements Statement</div>
                <div style="font-size: 9.5px; color: #64748b; margin-top: 2px;">Bank POS Batch Terminal Settlement &bull; Deposit Summary</div>
            </td>
            <td style="width: 35%; text-align: right;">
                <div style="font-size: 11px; font-weight: bold;">
                    Period: 
                    <span style="color: #04204e;">
                        <?php 
                        if (!empty($from_date) && !empty($to_date)) {
                            echo date('d-m-Y', strtotime($from_date)) . ' to ' . date('d-m-Y', strtotime($to_date));
                        } elseif (!empty($from_date)) {
                            echo 'From ' . date('d-m-Y', strtotime($from_date));
                        } elseif (!empty($to_date)) {
                            echo 'Up to ' . date('d-m-Y', strtotime($to_date));
                        } else {
                            echo 'All Recorded Settlements';
                        }
                        ?>
                    </span>
                </div>
                <div style="font-size: 9.5px; color: #64748b; margin-top: 2px;">Generated: <?php echo date('d-m-Y H:i A'); ?></div>
                <div style="font-size: 9.5px; color: #64748b;">Total Settlements: <?php echo count($items); ?></div>
            </td>
        </tr>
    </table>

    <!-- Metrics Cards -->
    <div class="metric-cards">
        <div class="metric-cell">
            <div class="lbl">Total Settlements</div>
            <div class="val"><?php echo count($items); ?> Batches</div>
        </div>
        <div class="metric-cell">
            <div class="lbl">Total Swipes / Cards</div>
            <div class="val"><?php echo $tot_cards; ?> Cards</div>
        </div>
        <div class="metric-cell">
            <div class="lbl">Gross Settled Amount</div>
            <div class="val">Rs. <?php echo number_format($tot_gross, 2); ?></div>
        </div>
        <div class="metric-cell" style="background: #f0fdf4; border-color: #86efac;">
            <div class="lbl" style="color: #047857;">Net Bank Receivable</div>
            <div class="val" style="color: #047857;">Rs. <?php echo number_format($tot_net, 2); ?></div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25px;">#</th>
                <th style="width: 75px;">Date</th>
                <th>Card Machine (Bank Terminal)</th>
                <th style="width: 80px;">Batch No</th>
                <th style="width: 60px;">Cards</th>
                <th style="width: 90px;">Gross Amount</th>
                <th style="width: 65px;">Fee %</th>
                <th style="width: 85px;">Service Charges</th>
                <th style="width: 95px;">Net Receivable</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($items)): 
            ?>
            <tr>
                <td colspan="9" style="text-align: center; color: #94a3b8; padding: 15px;">No card sale settlements recorded for this criteria.</td>
            </tr>
            <?php 
            else: 
                $c = 1;
                foreach ($items as $item): 
                    $cards = intval($item['no_of_cards']);
                    $gross = floatval($item['amount']);
                    $fee   = floatval($item['service_charges']);
                    $net   = floatval($item['net_amount']);
                    $feePct = floatval($item['charges_percentage']);
                    $dateDisp = date('d-m-Y', strtotime($item['settlement_date']));
            ?>
            <tr>
                <td style="text-align: center;"><?php echo $c++; ?></td>
                <td style="text-align: center; font-weight: bold;"><?php echo $dateDisp; ?></td>
                <td><strong><?php echo htmlspecialchars($item['machine_name'] ?? 'Machine #' . $item['card_machine_id']); ?></strong></td>
                <td style="text-align: center; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($item['batch_no']); ?></td>
                <td style="text-align: center; font-weight: bold;"><?php echo $cards; ?></td>
                <td style="text-align: right; font-weight: bold;">Rs. <?php echo number_format($gross, 2); ?></td>
                <td style="text-align: center; font-size: 9px; color: #64748b;"><?php echo number_format($feePct, 4); ?>%</td>
                <td style="text-align: right; font-weight: bold; color: #b91c1c;">-Rs. <?php echo number_format($fee, 2); ?></td>
                <td style="text-align: right; font-weight: bold; color: #047857;">Rs. <?php echo number_format($net, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f1f5f9; font-weight: bold; border-top: 2px solid #04204e;">
                <td colspan="4" style="text-align: right; font-size: 10.5px;">TOTALS (<?php echo count($items); ?> Settlements):</td>
                <td style="text-align: center; color: #04204e; font-size: 10.5px;"><?php echo $tot_cards; ?></td>
                <td style="text-align: right; font-size: 10.5px;">Rs. <?php echo number_format($tot_gross, 2); ?></td>
                <td>—</td>
                <td style="text-align: right; color: #b91c1c; font-size: 10px;">-Rs. <?php echo number_format($tot_charges, 2); ?></td>
                <td style="text-align: right; color: #047857; font-size: 11px;">Rs. <?php echo number_format($tot_net, 2); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Signatures Section -->
    <div class="sig-section">
        <div class="sig-box">Prepared By (POS Operator)</div>
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
