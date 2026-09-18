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
require_once '../include/settings_helper.php';

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);

check_access('card_sales', 'show');

$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';
$shift_id  = intval($_GET['shift_id'] ?? 0);

$where = "(s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND s.settlement_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND s.settlement_date <= '$to_safe'";
}
if ($shift_id > 0) {
    $where .= " AND s.shift_id = '$shift_id'";
}

$sql = "SELECT s.*, 
               cm.name AS machine_name,
               sh.name AS shift_name
        FROM tbl_card_sale_settlements s
        LEFT JOIN tbl_card_machines cm ON (s.card_machine_id = cm.id)
        LEFT JOIN tbl_shifts sh ON (s.shift_id = sh.id)
        WHERE $where
        ORDER BY s.settlement_date ASC, s.id ASC";
$res = mysqli_query($connection, $sql);

$items = [];
$tot_cards = 0;
$tot_gross = 0;
$tot_charges = 0;
$tot_net = 0;
$tot_revenue = 0;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $items[] = $r;
        $tot_cards += intval($r['no_of_cards']);
        $tot_gross += floatval($r['amount']);
        $tot_charges += floatval($r['service_charges']);
        $tot_net += floatval($r['net_amount']);
        $tot_revenue += floatval($r['revenue_amount'] ?? 0);
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
            width: 20%;
            padding: 6px 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
        }
        .metric-cell .lbl {
            font-size: 8.5px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
        }
        .metric-cell .val {
            font-size: 12px;
            font-weight: bold;
            color: #04204e;
            margin-top: 2px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .data-table th {
            background-color: #04204e;
            color: #fff;
            font-size: 9.5px;
            font-weight: 600;
            padding: 6px 4px;
            text-align: center;
            border: 1px solid #04204e;
        }
        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 5px;
            font-size: 9.5px;
        }
        .data-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .sig-section {
            margin-top: 30px;
            display: table;
            width: 100%;
        }
        .sig-box {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding-top: 40px;
            border-top: 1px dashed #94a3b8;
            font-size: 10px;
            font-weight: bold;
            color: #475569;
        }
    </style>
</head>
<body>
    <!-- Station Header -->
    <table class="header-table">
        <tr>
            <td style="width: 65%; vertical-align: middle;">
                <div style="display: flex; align-items: center;">
                    <?php if ($hasLogo): ?>
                        <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" alt="Station Logo" style="max-height: 48px; max-width: 120px; margin-right: 12px;">
                    <?php endif; ?>
                    <div>
                        <div class="station-title"><?php echo htmlspecialchars($station_settings['pump_name']); ?></div>
                        <?php if (!empty($station_settings['tagline'])): ?>
                            <div style="font-size: 10.5px; font-weight: 600; color: #475569; margin-top: 1px;"><?php echo htmlspecialchars($station_settings['tagline']); ?></div>
                        <?php endif; ?>
                        <div style="font-size: 9px; color: #64748b; margin-top: 1px;">
                            <?php if (!empty($station_settings['address'])): ?>
                                <span><?php echo htmlspecialchars($station_settings['address']); ?><?php echo !empty($station_settings['city']) ? ', ' . htmlspecialchars($station_settings['city']) : ''; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($station_settings['phone'])): ?>
                                <span> &bull; Tel: <?php echo htmlspecialchars($station_settings['phone']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="report-title">Card Sale Settlements Statement</div>
                    </div>
                </div>
            </td>
            <td style="width: 35%; text-align: right; vertical-align: top;">
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
            <div class="lbl">Total Pure Sales</div>
            <div class="val text-primary" style="color: #04204e;">Rs. <?php echo number_format($tot_gross, 2); ?></div>
        </div>
        <div class="metric-cell">
            <div class="lbl">Bank Service Fees</div>
            <div class="val" style="color: #dc2626;">-Rs. <?php echo number_format($tot_charges, 2); ?></div>
        </div>
        <div class="metric-cell" style="background: #f0fdf4; border-color: #86efac;">
            <div class="lbl" style="color: #047857;">Net Settled Deposit</div>
            <div class="val" style="color: #047857;">Rs. <?php echo number_format($tot_net, 2); ?></div>
        </div>
        <div class="metric-cell" style="background: #f0f9ff; border-color: #7dd3fc;">
            <div class="lbl" style="color: #0284c7;">Revenue Surcharge</div>
            <div class="val" style="color: #0284c7;">+Rs. <?php echo number_format($tot_revenue, 2); ?></div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 20px;">#</th>
                <th style="width: 60px;">Date</th>
                <th style="width: 50px;">Shift</th>
                <th>Card Machine (Bank Terminal)</th>
                <th style="width: 60px;">Batch No</th>
                <th style="width: 35px;">Cards</th>
                <th style="width: 70px;">Total Amount</th>
                <th style="width: 45px;">Fee %</th>
                <th style="width: 65px;">Service Fee</th>
                <th style="width: 70px;">Net Amount</th>
                <th style="width: 45px;">Rev. %</th>
                <th style="width: 65px;">Revenue (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($items)): 
            ?>
            <tr>
                <td colspan="12" style="text-align: center; color: #94a3b8; padding: 15px;">No card sale settlements recorded for this criteria.</td>
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
                    $revAmt = floatval($item['revenue_amount'] ?? 0);
                    $revPct = floatval($item['revenue_percentage'] ?? 0);
                    $dateDisp = date('d-m-Y', strtotime($item['settlement_date']));
                    $shiftDisp = !empty($item['shift_name']) ? $item['shift_name'] : '-';
            ?>
            <tr>
                <td style="text-align: center;"><?php echo $c++; ?></td>
                <td style="text-align: center; font-weight: bold;"><?php echo $dateDisp; ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($shiftDisp); ?></td>
                <td><strong><?php echo htmlspecialchars($item['machine_name'] ?? 'Machine #' . $item['card_machine_id']); ?></strong></td>
                <td style="text-align: center; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($item['batch_no']); ?></td>
                <td style="text-align: center; font-weight: bold;"><?php echo $cards; ?></td>
                <td style="text-align: right; font-weight: bold; color: #04204e;">Rs. <?php echo number_format($gross, 2); ?></td>
                <td style="text-align: center; font-size: 8.5px; color: #64748b;"><?php echo number_format($feePct, 4); ?>%</td>
                <td style="text-align: right; font-weight: bold; color: #dc2626;">-Rs. <?php echo number_format($fee, 2); ?></td>
                <td style="text-align: right; font-weight: bold; color: #16a34a;">Rs. <?php echo number_format($net, 2); ?></td>
                <td style="text-align: center; font-size: 8.5px; color: #0284c7;"><?php echo number_format($revPct, 4); ?>%</td>
                <td style="text-align: right; font-weight: bold; color: #0284c7;">+Rs. <?php echo number_format($revAmt, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f1f5f9; font-weight: bold; border-top: 2px solid #04204e;">
                <td colspan="5" style="text-align: right; font-size: 10px;">TOTALS (<?php echo count($items); ?> Settlements):</td>
                <td style="text-align: center; color: #04204e; font-size: 10px;"><?php echo $tot_cards; ?></td>
                <td style="text-align: right; font-size: 10px; color: #04204e;">Rs. <?php echo number_format($tot_gross, 2); ?></td>
                <td>—</td>
                <td style="text-align: right; color: #dc2626; font-size: 9.5px;">-Rs. <?php echo number_format($tot_charges, 2); ?></td>
                <td style="text-align: right; color: #16a34a; font-size: 10.5px;">Rs. <?php echo number_format($tot_net, 2); ?></td>
                <td>—</td>
                <td style="text-align: right; color: #0284c7; font-size: 10.5px;">+Rs. <?php echo number_format($tot_revenue, 2); ?></td>
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
