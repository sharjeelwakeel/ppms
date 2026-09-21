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
    echo 'Error: Valid deposit payment ID is required.';
    exit;
}

// Fetch deposit details
$sql = "SELECT p.*, 
               cm.name AS machine_name,
               cm.charges_percentage AS default_machine_fee,
               b.name AS bank_name,
               b.account_number AS bank_account_no,
               acc.username AS created_by_name
        FROM tbl_card_settlement_payments p
        LEFT JOIN tbl_card_machines cm ON (p.card_machine_id = cm.id)
        LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
        LEFT JOIN tbl_accounts acc ON (p.created_by = acc.id)
        WHERE p.id = '$paymentId' AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        LIMIT 1";

$res = mysqli_query($connection, $sql);
if (!$res || mysqli_num_rows($res) == 0) {
    echo 'Bank deposit voucher not found or deleted.';
    exit;
}
$payment = mysqli_fetch_assoc($res);

// Fetch allocated settlements
$sql_alloc = "SELECT a.*, 
                     s.batch_no, 
                     s.settlement_date, 
                     s.amount AS gross_amount, 
                     s.service_charges, 
                     s.net_amount, 
                     s.paid_amount AS current_total_paid,
                     s.payment_status
              FROM tbl_card_settlement_payment_allocations a
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
    <title>Bank Deposit Voucher - #<?php echo str_pad($payment['id'], 5, '0', STR_PAD_LEFT); ?></title>
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
        .station-title {
            font-size: 20px;
            font-weight: 900;
            color: #04204e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .station-sub {
            font-size: 11px;
            color: #555;
            margin-top: 2px;
        }
        .voucher-badge {
            background: #04204e;
            color: #fff;
            padding: 6px 14px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 4px;
            display: inline-block;
            text-align: right;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 18px;
            border-collapse: collapse;
        }
        .info-grid td {
            padding: 5px 8px;
            vertical-align: top;
        }
        .info-label {
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
        }
        .info-value {
            font-size: 13px;
            font-weight: 700;
            color: #111;
        }
        .amount-box {
            background: #eef3fc;
            border: 1px dashed #04204e;
            border-radius: 6px;
            padding: 10px 16px;
            text-align: center;
            margin-bottom: 18px;
        }
        .amount-box .amount-label {
            font-size: 11px;
            color: #04204e;
            font-weight: 700;
            text-transform: uppercase;
        }
        .amount-box .amount-num {
            font-size: 24px;
            font-weight: 900;
            color: #04204e;
        }
        table.alloc-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        table.alloc-table th {
            background: #04204e;
            color: #fff;
            padding: 7px 10px;
            font-size: 11px;
            text-transform: uppercase;
            border: 1px solid #04204e;
        }
        table.alloc-table td {
            padding: 7px 10px;
            font-size: 11px;
            border: 1px solid #ddd;
        }
        table.alloc-table tfoot td {
            font-weight: 700;
            background: #f8f9fa;
            border-top: 2px solid #04204e;
        }
        .signatures {
            margin-top: 40px;
            width: 100%;
        }
        .signatures td {
            text-align: center;
            width: 33.33%;
            padding-top: 35px;
            border-top: 1px dotted #888;
            font-size: 11px;
            font-weight: 700;
            color: #333;
        }
        .print-actions {
            max-width: 820px;
            margin: 0 auto 16px auto;
            text-align: right;
        }
        .btn-print {
            background: #04204e;
            color: #fff;
            border: none;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-print:hover {
            background: #07347a;
            color: #fff;
        }
        @media print {
            .print-actions { display: none; }
            body { padding: 0; }
            .receipt-card { border: none; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="print-actions">
    <a href="javascript:window.print()" class="btn-print"><i class="fas fa-print"></i> Print Voucher</a>
    <a href="card-settlement-history.php" class="btn-print" style="background:#555; margin-left: 6px;"><i class="fas fa-arrow-left"></i> Back to History</a>
</div>

<div class="receipt-card">
    <table class="header-table">
        <tr>
            <td style="width: 70%;">
                <?php if ($hasLogo): ?>
                    <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" alt="Logo" style="max-height: 48px; margin-bottom: 6px;"><br>
                <?php endif; ?>
                <div class="station-title"><?php echo htmlspecialchars($station_settings['name'] ?? 'Petrol Pump Management System'); ?></div>
                <div class="station-sub"><?php echo htmlspecialchars($station_settings['address'] ?? 'Fuel Station Station Address'); ?></div>
                <?php if (!empty($station_settings['phone'])): ?>
                <div class="station-sub">Phone: <?php echo htmlspecialchars($station_settings['phone']); ?></div>
                <?php endif; ?>
            </td>
            <td style="width: 30%; text-align: right; vertical-align: top;">
                <div class="voucher-badge">CARD SETTLEMENT VOUCHER</div>
                <div style="font-size: 12px; margin-top: 6px; font-weight: bold; color: #555;">
                    Voucher #: <strong>DEP-<?php echo str_pad($payment['id'], 5, '0', STR_PAD_LEFT); ?></strong>
                </div>
                <div style="font-size: 11px; color: #777;">
                    Payment Date: <strong><?php echo date('d-M-Y', strtotime($payment['payment_date'])); ?></strong>
                </div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td style="width: 50%;">
                <div class="info-label">Card Machine / POS Terminal:</div>
                <div class="info-value"><i class="fas fa-credit-card text-primary mr-1"></i> <?php echo htmlspecialchars($payment['machine_name']); ?></div>
            </td>
            <td style="width: 50%;">
                <div class="info-label">Destination Bank Account (Master):</div>
                <div class="info-value"><i class="fas fa-university text-success mr-1"></i> <?php echo htmlspecialchars($payment['bank_name']); ?> (A/C: <?php echo htmlspecialchars($payment['bank_account_no']); ?>)</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="info-label">Settlement Batch Date Range:</div>
                <div class="info-value">
                    <?php 
                    if (!empty($payment['filter_from_date']) && !empty($payment['filter_to_date'])) {
                        echo date('d-M-Y', strtotime($payment['filter_from_date'])) . ' to ' . date('d-M-Y', strtotime($payment['filter_to_date']));
                    } elseif (!empty($payment['filter_from_date'])) {
                        echo 'From ' . date('d-M-Y', strtotime($payment['filter_from_date']));
                    } else {
                        echo 'All Open Batches';
                    }
                    ?>
                </div>
            </td>
            <td>
                <div class="info-label">Transaction / Bank Ref:</div>
                <div class="info-value text-monospace"><?php echo !empty($payment['transaction_ref']) ? htmlspecialchars($payment['transaction_ref']) : '—'; ?></div>
            </td>
        </tr>
        <?php if (!empty($payment['remarks'])): ?>
        <tr>
            <td colspan="2">
                <div class="info-label">Remarks / Description:</div>
                <div class="info-value" style="font-weight: normal; font-style: italic;"><?php echo nl2br(htmlspecialchars($payment['remarks'])); ?></div>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <div class="amount-box">
        <div class="amount-label">Total Net Amount Deposited / Received</div>
        <div class="amount-num">Rs. <?php echo number_format(floatval($payment['total_amount']), 2); ?></div>
    </div>

    <div style="font-weight: 700; font-size: 12px; margin-bottom: 6px; text-transform: uppercase; color: #04204e;">
        <i class="fas fa-list-ol mr-1"></i> Itemized Settlement Batch Allocations
    </div>
    <table class="alloc-table">
        <thead>
            <tr>
                <th style="width: 5%; text-align: center;">#</th>
                <th style="width: 15%; text-align: center;">Batch Date</th>
                <th style="width: 15%; text-align: center;">Batch #</th>
                <th style="width: 16%; text-align: right;">Pure Sales (Rs.)</th>
                <th style="width: 16%; text-align: right;">Bank Fee (Rs.)</th>
                <th style="width: 16%; text-align: right;">Net Expected (Rs.)</th>
                <th style="width: 17%; text-align: right;">Allocated / Settled (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($allocations)): ?>
                <?php $i = 1; foreach ($allocations as $al): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $i++; ?></td>
                    <td style="text-align: center;"><?php echo date('d-M-Y', strtotime($al['settlement_date'])); ?></td>
                    <td style="text-align: center; font-weight: bold;" class="text-monospace"><?php echo htmlspecialchars($al['batch_no']); ?></td>
                    <td style="text-align: right;"><?php echo number_format(floatval($al['gross_amount']), 2); ?></td>
                    <td style="text-align: right; color: #c00;">-<?php echo number_format(floatval($al['service_charges']), 2); ?></td>
                    <td style="text-align: right; font-weight: bold;"><?php echo number_format(floatval($al['net_amount']), 2); ?></td>
                    <td style="text-align: right; font-weight: bold; color: #04204e;">Rs. <?php echo number_format(floatval($al['allocated_amount']), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #888;">No itemized batch allocations found for this deposit.</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" style="text-align: right; font-weight: 900; text-transform: uppercase;">Total Settlement Allocated:</td>
                <td style="text-align: right; font-weight: 900; color: #04204e;">Rs. <?php echo number_format($total_allocated, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <table class="signatures">
        <tr>
            <td>
                Prepared By: <strong><?php echo htmlspecialchars($payment['created_by_name'] ?? 'System'); ?></strong><br>
                <small style="color: #888;"><?php echo date('d-M-Y h:i A', strtotime($payment['created_at'])); ?></small>
            </td>
            <td>
                Bank Teller / Depositor Signature
            </td>
            <td>
                Station Manager / Accountant
            </td>
        </tr>
    </table>
</div>

</body>
</html>
