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
require_once '../include/cash_helper.php';

init_cash_sales_schema($connection);
check_access('cash_sales', 'show');

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);

$from_date     = $_GET['from_date'] ?? date('Y-m-01');
$to_date       = $_GET['to_date'] ?? date('Y-m-d');
$filter_shift  = intval($_GET['shift_id'] ?? 0);
$filter_nozzle = intval($_GET['nozzle_id'] ?? 0);

$where = "cs.deleted_at IS NULL";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND cs.sale_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND cs.sale_date <= '$to_safe'";
}
if ($filter_shift > 0) {
    $where .= " AND cs.shift_id = '$filter_shift'";
}
if ($filter_nozzle > 0) {
    $where .= " AND cs.nozzle_id = '$filter_nozzle'";
}

$sql = "SELECT cs.*,
               sh.name AS shift_name,
               n.name AS nozzle_name,
               i.name AS item_name
        FROM tbl_meter_reading_cash_sales cs
        LEFT JOIN tbl_shifts sh ON (cs.shift_id = sh.id)
        LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
        LEFT JOIN tbl_items i ON (cs.item_id = i.id)
        WHERE $where
        ORDER BY cs.sale_date ASC, cs.id ASC";

$res = mysqli_query($connection, $sql);
$rows = [];
$tot_cash = 0.00;
$tot_litres = 0.00;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
        $tot_cash += floatval($r['amount']);
        $tot_litres += floatval($r['quantity']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Cash Sale Reading Statement - <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?></title>
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
            color: #475569;
            margin-top: 3px;
        }
        .meta-info {
            font-size: 10px;
            color: #64748b;
            line-height: 1.4;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .data-table th {
            background-color: #04204e;
            color: #ffffff;
            font-weight: 600;
            padding: 6px 8px;
            font-size: 10px;
            border: 1px solid #04204e;
            text-align: center;
        }
        .data-table td {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            font-size: 10.5px;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .total-row td {
            font-weight: bold;
            background-color: #f1f5f9;
            border-top: 2px solid #04204e;
            border-bottom: 2px solid #04204e;
        }
        .sign-table {
            width: 100%;
            margin-top: 40px;
            border: none;
        }
        .sign-table td {
            border: none;
            text-align: center;
            font-size: 10px;
            color: #475569;
            padding-top: 30px;
        }
        .sign-line {
            border-top: 1px solid #94a3b8;
            width: 75%;
            margin: 0 auto 5px auto;
        }
        .no-print {
            margin-bottom: 15px;
            text-align: right;
        }
        .btn-print {
            background-color: #04204e;
            color: #fff;
            padding: 7px 18px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            font-weight: bold;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print();">Print Statement</button>
</div>

<table class="header-table">
    <tr>
        <?php if ($hasLogo): ?>
        <td style="width: 70px; vertical-align: middle;">
            <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" style="max-height: 55px; max-width: 65px;" alt="Logo">
        </td>
        <?php endif; ?>
        <td style="vertical-align: middle;">
            <div class="station-title"><?php echo htmlspecialchars($station_settings['station_name'] ?? 'PETROL PUMP MANAGEMENT SYSTEM'); ?></div>
            <div class="report-title">CASH SALE READING STATEMENT</div>
            <div class="meta-info">
                <?php if (!empty($station_settings['address'])): ?>
                    <?php echo htmlspecialchars($station_settings['address']); ?> | 
                <?php endif; ?>
                <?php if (!empty($station_settings['phone'])): ?>
                    Phone: <?php echo htmlspecialchars($station_settings['phone']); ?> | 
                <?php endif; ?>
                Period: <?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?>
            </div>
        </td>
        <td style="text-align: right; vertical-align: middle;" class="meta-info">
            Printed: <?php echo date('d-m-Y h:i A'); ?><br>
            Records: <?php echo count($rows); ?>
        </td>
    </tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th style="width: 12%;">Sale Date</th>
            <th style="width: 12%;">Shift</th>
            <th style="width: 16%;">Nozzle</th>
            <th style="width: 14%;">Fuel Type</th>
            <th style="width: 12%;">Rate (Rs.)</th>
            <th style="width: 14%;">Litres Sold</th>
            <th style="width: 16%;">Amount (Rs.)</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="8" style="text-align:center; padding:20px; color:#64748b;">No cash sale records found for this period.</td>
        </tr>
        <?php else: ?>
            <?php 
            $i = 1;
            foreach ($rows as $r): 
            ?>
            <tr>
                <td style="text-align: center;"><?php echo $i++; ?></td>
                <td style="text-align: center;"><?php echo date('d-m-Y', strtotime($r['sale_date'])); ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($r['shift_name'] ?? 'General'); ?></td>
                <td style="font-weight: 600;"><?php echo htmlspecialchars($r['nozzle_name'] ?? 'N/A'); ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($r['item_name'] ?? 'Fuel'); ?></td>
                <td style="text-align: right;"><?php echo number_format(floatval($r['rate']), 2); ?></td>
                <td style="text-align: right; font-weight: 600;"><?php echo number_format(floatval($r['quantity']), 2); ?> Ltr</td>
                <td style="text-align: right; font-weight: 600; color: #04204e;">Rs. <?php echo number_format(floatval($r['amount']), 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="6" style="text-align: right; text-transform: uppercase;">Total:</td>
                <td style="text-align: right;"><?php echo number_format($tot_litres, 2); ?> Ltr</td>
                <td style="text-align: right; color: #04204e;">Rs. <?php echo number_format($tot_cash, 2); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<table class="sign-table">
    <tr>
        <td style="width: 33%;">
            <div class="sign-line"></div>
            Prepared By (Cashier)
        </td>
        <td style="width: 33%;">
            <div class="sign-line"></div>
            Shift Supervisor
        </td>
        <td style="width: 33%;">
            <div class="sign-line"></div>
            Station Manager
        </td>
    </tr>
</table>

</body>
</html>
