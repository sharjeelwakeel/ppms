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

if (!has_permission('accounts', 'show') && !has_permission('credit_sales', 'show') && !has_permission('reports', 'show')) {
    echo 'Unauthorized access.';
    exit;
}

$paymentId = intval($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    echo 'Error: Valid payment ID is required.';
    exit;
}

// Fetch payment details
$sql = "SELECT p.*, 
               c.name AS customer_name,
               c.phone AS customer_phone,
               c.fuel_rate AS customer_tier,
               b.name AS bank_name,
               b.account_number AS bank_account_no,
               sh.name AS shift_name
        FROM tbl_customer_payments p
        LEFT JOIN tbl_customers c ON (p.customer_id = c.id)
        LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
        LEFT JOIN tbl_shifts sh ON (p.filter_shift_id = sh.id)
        WHERE p.id = '$paymentId' AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        LIMIT 1";

$res = mysqli_query($connection, $sql);
if (!$res || mysqli_num_rows($res) == 0) {
    echo 'Payment receipt not found or deleted.';
    exit;
}
$payment = mysqli_fetch_assoc($res);

// Fetch allocated slips
$sql_alloc = "SELECT a.*, 
                     s.slip_no, 
                     s.slip_date, 
                     s.vehicle_number, 
                     s.quantity,
                     s.rate,
                     s.charge_amount, 
                     s.paid_amount,
                     i.name AS item_name,
                     n.name AS nozzle_name
              FROM tbl_customer_payment_allocations a
              LEFT JOIN tbl_meter_reading_credit_sales s ON (a.credit_sale_id = s.id)
              LEFT JOIN tbl_nozzles n ON (s.nozzle_id = n.id)
              LEFT JOIN tbl_items i ON (n.item_id = i.id)
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
    <title>Payment Receipt - <?php echo htmlspecialchars($payment['receipt_no']); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700,900&display=swap">
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
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #04204e;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #04204e;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .header h2 {
            margin: 0 0 4px 0;
            color: #04204e;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 0.5px;
        }
        .header h4 {
            margin: 0 0 6px 0;
            color: #444;
            font-size: 14px;
            font-weight: 700;
        }
        .meta-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 18px;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .meta-col { width: 48%; line-height: 1.6; }
        .meta-col strong { color: #04204e; }
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
            font-size: 11px;
        }
        .table-custom th {
            background: #04204e;
            color: #fff;
            padding: 8px;
            text-align: center;
            font-weight: 700;
            border: 1px solid #04204e;
        }
        .table-custom td {
            padding: 7px 8px;
            border: 1px solid #cbd5e1;
            text-align: center;
        }
        .table-custom tr:nth-child(even) { background: #f8fafc; }
        .total-box {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        .total-card {
            width: 280px;
            background: #e8f0fe;
            border: 2px solid #04204e;
            border-radius: 6px;
            padding: 10px 16px;
            text-align: right;
        }
        .sig-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            padding-top: 20px;
        }
        .sig-box {
            width: 38%;
            border-top: 1px solid #444;
            text-align: center;
            padding-top: 6px;
            font-weight: 700;
            color: #444;
            font-size: 11px;
        }
        .no-print-bar {
            background: #04204e;
            color: #fff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .btn-print {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 6px 16px;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
        }
        @media print {
            .no-print-bar { display: none !important; }
            body { padding: 0 !important; }
            .receipt-card { border: none !important; box-shadow: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div><strong>PPMS</strong> &mdash; Payment Voucher Preview</div>
        <div>
            <button class="btn-print" onclick="window.print();">Print / Save as PDF</button>
            <button onclick="window.close();" style="background:#6c757d; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:700; cursor:pointer; margin-left:6px;">Close</button>
        </div>
    </div>

    <div class="receipt-card">
        <!-- Letterhead -->
        <div class="header">
            <h2>PETROL PUMP MANAGEMENT SYSTEM</h2>
            <h4>CUSTOMER PAYMENT ACKNOWLEDGMENT RECEIPT</h4>
            <div style="font-size: 11px; color:#666;">Official Acknowledgment of Credit Sale Settlement</div>
        </div>

        <!-- Meta Details -->
        <div class="meta-grid">
            <div class="meta-col">
                <div><strong>Receipt No:</strong> <span style="font-family:monospace; font-size:12.5px; font-weight:bold;"><?php echo htmlspecialchars($payment['receipt_no']); ?></span></div>
                <div><strong>Payment Date:</strong> <?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></div>
                <div><strong>Customer Name:</strong> <?php echo htmlspecialchars($payment['customer_name'] ?: 'Account #' . $payment['customer_id']); ?></div>
                <div><strong>Phone:</strong> <?php echo htmlspecialchars($payment['customer_phone'] ?: '—'); ?></div>
            </div>
            <div class="meta-col text-right">
                <div><strong>Payment Mode:</strong> <?php echo htmlspecialchars($payment['payment_mode']); ?></div>
                <?php if ($payment['payment_mode'] === 'Online Payment'): ?>
                    <div><strong>Bank:</strong> <?php echo htmlspecialchars($payment['bank_name']); ?></div>
                    <div><strong>Account #:</strong> <?php echo htmlspecialchars($payment['bank_account_no']); ?></div>
                    <?php if (!empty($payment['transaction_ref'])): ?>
                        <div><strong>Ref / UTR:</strong> <?php echo htmlspecialchars($payment['transaction_ref']); ?></div>
                    <?php endif; ?>
                <?php elseif ($payment['payment_mode'] === 'Cheque'): ?>
                    <div><strong>Cheque No:</strong> <?php echo htmlspecialchars($payment['cheque_no']); ?></div>
                    <?php if (!empty($payment['cheque_date'])): ?>
                        <div><strong>Cheque Date:</strong> <?php echo date('d-m-Y', strtotime($payment['cheque_date'])); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($payment['remarks'])): ?>
                    <div><strong>Remarks:</strong> <?php echo htmlspecialchars($payment['remarks']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settled Slips Table -->
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>Slip No</th>
                    <th>Slip Date</th>
                    <th>Vehicle No</th>
                    <th>Item / Fuel</th>
                    <th>Qty (Ltr)</th>
                    <th>Total Billed (Rs.)</th>
                    <th>Amount Settled (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $count = 1;
                foreach ($allocations as $al): 
                    $cut = floatval($al['allocated_amount']);
                    $chg = floatval($al['charge_amount']);
                ?>
                <tr>
                    <td><?php echo $count++; ?></td>
                    <td style="font-family:monospace; font-weight:bold;"><?php echo htmlspecialchars($al['slip_no'] ?: '—'); ?></td>
                    <td><?php echo !empty($al['slip_date']) ? date('d-m-Y', strtotime($al['slip_date'])) : '—'; ?></td>
                    <td style="font-family:monospace;"><?php echo htmlspecialchars($al['vehicle_number'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($al['item_name'] ?: 'Fuel'); ?></td>
                    <td><?php echo number_format($al['quantity'], 2); ?></td>
                    <td>Rs. <?php echo number_format($chg, 2); ?></td>
                    <td style="font-weight:bold; color:#04204e;">Rs. <?php echo number_format($cut, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Total Box -->
        <div class="total-box">
            <div class="total-card">
                <div style="font-size:11px; text-transform:uppercase; color:#555;">Total Amount Received:</div>
                <div style="font-size:18px; font-weight:900; color:#04204e;">
                    Rs. <?php echo number_format($payment['total_amount'], 2); ?>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="sig-section">
            <div class="sig-box">Customer Signature / Acknowledgment</div>
            <div class="sig-box">Authorized Station Cashier / Manager</div>
        </div>
    </div>

</body>
</html>
