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

$station_settings = get_station_settings($connection);
$hasLogo = !empty($station_settings['logo_path']) && file_exists(__DIR__ . '/../' . $station_settings['logo_path']);

if (!has_permission('accounts', 'show') && !has_permission('card_sales', 'show') && !has_permission('reports', 'show')) {
    echo 'Unauthorized access.';
    exit;
}

$paymentId = intval($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    echo 'Error: Valid revenue payment ID is required.';
    exit;
}

// Fetch revenue payment details
$sql = "SELECT p.*, 
               cm.name AS machine_name,
               cm.revenue_charge AS default_machine_revenue_charge,
               b.name AS bank_name,
               b.account_number AS bank_account_no,
               acc.username AS created_by_name
        FROM tbl_card_revenue_payments p
        LEFT JOIN tbl_card_machines cm ON (p.card_machine_id = cm.id)
        LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
        LEFT JOIN tbl_accounts acc ON (p.created_by = acc.id)
        WHERE p.id = '$paymentId' AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        LIMIT 1";

$res = mysqli_query($connection, $sql);
if (!$res || mysqli_num_rows($res) == 0) {
    echo 'Card revenue voucher not found or deleted.';
    exit;
}
$payment = mysqli_fetch_assoc($res);

// Fetch allocated settlements
$sql_alloc = "SELECT a.*, 
                     s.batch_no, 
                     s.settlement_date, 
                     s.amount AS gross_amount, 
                     s.revenue_percentage, 
                     s.revenue_amount, 
                     s.revenue_paid_amount AS current_total_rev_paid,
                     s.revenue_payment_status
              FROM tbl_card_revenue_payment_allocations a
              LEFT JOIN tbl_card_sale_settlements s ON (a.settlement_id = s.id)
              WHERE a.payment_id = '$paymentId' AND (a.deleted_at IS NULL OR a.deleted_at = '0000-00-00 00:00:00')
              ORDER BY a.id ASC";

$res_alloc = mysqli_query($connection, $sql_alloc);
$allocations = [];
$total_allocated = 0.00;
if ($res_alloc) {
    while ($al = mysqli_fetch_assoc($res_alloc)) {
        $allocations[] = $al;
        $total_allocated += floatval($al['allocated_amount']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Card Revenue Voucher - #<?php echo str_pad($payment['id'], 5, '0', STR_PAD_LEFT); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: #fff;
            color: #111;
            margin: 0;
            padding: 24px;
            font-size: 12px;
        }
        .receipt-card {
            max-width: 820px;
            margin: 0 auto;
            border: 2px solid #04204e;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #04204e;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .station-name {
            font-size: 20px;
            font-weight: 900;
            color: #04204e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .station-sub {
            font-size: 11px;
            color: #555;
            margin-top: 3px;
        }
        .voucher-title-box {
            text-align: right;
        }
        .voucher-title {
            font-size: 16px;
            font-weight: 800;
            color: #04204e;
            text-transform: uppercase;
            margin: 0;
        }
        .voucher-no {
            font-size: 14px;
            font-weight: 700;
            color: #28a745;
            font-family: monospace;
            margin-top: 4px;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 18px;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 6px 8px;
            vertical-align: top;
            font-size: 11.5px;
        }
        .meta-label {
            font-weight: 700;
            color: #04204e;
            width: 18%;
        }
        .meta-val {
            color: #222;
            width: 32%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .items-table th {
            background-color: #04204e;
            color: #fff;
            padding: 7px 8px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #04204e;
        }
        .items-table td {
            padding: 6px 8px;
            border: 1px solid #dee2e6;
            font-size: 11.5px;
        }
        .items-table tr:nth-child(even) td {
            background-color: #fcfcfd;
        }
        .total-box {
            float: right;
            width: 280px;
            margin-bottom: 24px;
        }
        .total-table {
            width: 100%;
            border-collapse: collapse;
        }
        .total-table td {
            padding: 5px 8px;
            font-size: 12px;
        }
        .total-table .grand-total {
            background-color: #e8f5e9;
            font-weight: 900;
            font-size: 14px;
            color: #2e7d32;
            border-top: 2px solid #28a745;
        }
        .signatures {
            clear: both;
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 10px;
        }
        .sig-block {
            text-align: center;
            width: 28%;
            border-top: 1px dashed #777;
            padding-top: 6px;
            font-size: 11px;
            color: #444;
            font-weight: 500;
        }
        .print-btn-bar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: flex-end;
        }
        .btn-print {
            background: #04204e;
            color: #fff;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            border: none;
        }
        .btn-print:hover {
            background: #07347a;
            color: #fff;
        }
        @media print {
            body { padding: 0; }
            .print-btn-bar { display: none !important; }
            .receipt-card { border: none; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="print-btn-bar">
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print mr-1"></i> Print Voucher
    </button>
</div>

<div class="receipt-card">

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td style="width: 60%; vertical-align: middle;">
                <?php if ($hasLogo): ?>
                    <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" alt="Station Logo" style="max-height: 48px; margin-bottom: 6px;"><br>
                <?php endif; ?>
                <h1 class="station-name"><?php echo htmlspecialchars($station_settings['station_name'] ?? 'Petrol Pump Management System'); ?></h1>
                <div class="station-sub">
                    <?php if (!empty($station_settings['address'])): ?>
                        <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($station_settings['address']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($station_settings['phone'])): ?>
                        <i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($station_settings['phone']); ?>
                    <?php endif; ?>
                </div>
            </td>
            <td class="voucher-title-box" style="width: 40%; vertical-align: middle;">
                <h2 class="voucher-title">Card Revenue Voucher</h2>
                <div class="voucher-no">#REV-<?php echo str_pad($payment['id'], 5, '0', STR_PAD_LEFT); ?></div>
                <div style="font-size: 11px; color: #666; margin-top: 4px;">
                    Date: <strong><?php echo date('d-M-Y', strtotime($payment['payment_date'])); ?></strong>
                </div>
            </td>
        </tr>
    </table>

    <!-- Metadata -->
    <table class="meta-table">
        <tr>
            <td class="meta-label">POS Terminal:</td>
            <td class="meta-val"><strong><?php echo htmlspecialchars($payment['machine_name']); ?></strong></td>
            <td class="meta-label">Destination Mode:</td>
            <td class="meta-val">
                <?php if ($payment['payment_mode'] === 'Bank'): ?>
                    <strong>Bank Account:</strong> <?php echo htmlspecialchars($payment['bank_name']); ?> (A/C: <?php echo htmlspecialchars($payment['bank_account_no']); ?>)
                <?php else: ?>
                    <strong>Cash in Hand</strong> (Station Safe / Register)
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="meta-label">Settlement Period:</td>
            <td class="meta-val">
                <?php 
                if (!empty($payment['filter_from_date']) && !empty($payment['filter_to_date'])) {
                    echo date('d-M-Y', strtotime($payment['filter_from_date'])) . ' to ' . date('d-M-Y', strtotime($payment['filter_to_date']));
                } elseif (!empty($payment['filter_from_date'])) {
                    echo 'From ' . date('d-M-Y', strtotime($payment['filter_from_date']));
                } else {
                    echo 'All Filtered Batches';
                }
                ?>
            </td>
            <td class="meta-label">Transaction Ref:</td>
            <td class="meta-val">
                <?php echo !empty($payment['transaction_ref']) ? htmlspecialchars($payment['transaction_ref']) : '—'; ?>
            </td>
        </tr>
        <tr>
            <td class="meta-label">Collected By:</td>
            <td class="meta-val"><?php echo htmlspecialchars($payment['created_by_name'] ?: 'System'); ?></td>
            <td class="meta-label">Remarks:</td>
            <td class="meta-val"><?php echo !empty($payment['remarks']) ? htmlspecialchars($payment['remarks']) : '—'; ?></td>
        </tr>
    </table>

    <!-- Allocated Settlement Batches Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px; text-align: center;">#</th>
                <th>Batch #</th>
                <th>Settlement Date</th>
                <th style="text-align: right;">Pure Fuel Sales</th>
                <th style="text-align: center;">Rev Rate %</th>
                <th style="text-align: right;">Total Surcharge</th>
                <th style="text-align: right;">Allocated Surcharge</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sr = 1;
            foreach ($allocations as $al): 
            ?>
            <tr>
                <td style="text-align: center; color:#777;"><?php echo $sr++; ?></td>
                <td style="font-family: monospace; font-weight: 700;"><?php echo htmlspecialchars($al['batch_no'] ?: '—'); ?></td>
                <td><?php echo date('d-M-Y', strtotime($al['settlement_date'])); ?></td>
                <td style="text-align: right;">Rs. <?php echo number_format(floatval($al['gross_amount']), 2); ?></td>
                <td style="text-align: center; color:#666; font-family: monospace;"><?php echo number_format(floatval($al['revenue_percentage']), 4); ?>%</td>
                <td style="text-align: right; color:#17a2b8; font-weight: 700;">+Rs. <?php echo number_format(floatval($al['revenue_amount']), 2); ?></td>
                <td style="text-align: right; font-weight: 700; color:#28a745;">Rs. <?php echo number_format(floatval($al['allocated_amount']), 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="total-box">
        <table class="total-table">
            <tr>
                <td>Total Batches Cleared:</td>
                <td style="text-align: right; font-weight: 700;"><?php echo count($allocations); ?></td>
            </tr>
            <tr class="grand-total">
                <td>Total Surcharge Collected:</td>
                <td style="text-align: right;">Rs. <?php echo number_format(floatval($payment['total_amount']), 2); ?></td>
            </tr>
        </table>
    </div>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-block">
            Collected By<br>
            <strong><?php echo htmlspecialchars($payment['created_by_name'] ?: 'Cashier'); ?></strong>
        </div>
        <div class="sig-block">
            Verified / Accountant
        </div>
        <div class="sig-block">
            Station Manager / Owner
        </div>
    </div>

</div>

</body>
</html>
