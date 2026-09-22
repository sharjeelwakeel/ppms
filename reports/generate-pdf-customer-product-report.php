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

if (!has_permission('reports', 'show') && !has_permission('customers', 'show') && !has_permission('items', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$customerId = intval($_GET['customer_id'] ?? 0);
$vehicleNum = trim($_GET['vehicle_number'] ?? '');
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');

$where_clauses = [
    "inv.payment_type = 'Credit'",
    "(inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')",
    "(sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')"
];

if ($customerId > 0) {
    $where_clauses[] = "inv.customer_id = '$customerId'";
}
if (!empty($vehicleNum)) {
    $v_safe = mysqli_real_escape_string($connection, $vehicleNum);
    $where_clauses[] = "inv.vehicle_number LIKE '%$v_safe%'";
}
if (!empty($fromDate) && !empty($toDate)) {
    $from_safe = mysqli_real_escape_string($connection, $fromDate);
    $to_safe   = mysqli_real_escape_string($connection, $toDate);
    $where_clauses[] = "inv.slip_date BETWEEN '$from_safe' AND '$to_safe'";
} elseif (!empty($fromDate)) {
    $from_safe = mysqli_real_escape_string($connection, $fromDate);
    $where_clauses[] = "inv.slip_date >= '$from_safe'";
} elseif (!empty($toDate)) {
    $to_safe = mysqli_real_escape_string($connection, $toDate);
    $where_clauses[] = "inv.slip_date <= '$to_safe'";
}

$where_sql = implode(' AND ', $where_clauses);

// Pre-calculate claimed balance quantities by reference slip
$claimed_by_ref = [];
$q_claims = mysqli_query($connection, "
    SELECT binv.ref_slip_no, bsal.product_id, SUM(bsal.quantity) AS total_claimed
    FROM tbl_lubricant_sales bsal
    JOIN tbl_lubricant_sale_invoices binv ON (bsal.invoice_id = binv.id)
    WHERE binv.slip_type = 'Balanced Slip'
      AND (binv.deleted_at IS NULL OR binv.deleted_at = '0000-00-00 00:00:00')
      AND (bsal.deleted_at IS NULL OR bsal.deleted_at = '0000-00-00 00:00:00')
      AND binv.ref_slip_no IS NOT NULL AND binv.ref_slip_no != ''
    GROUP BY binv.ref_slip_no, bsal.product_id
");
if ($q_claims) {
    while ($cl = mysqli_fetch_assoc($q_claims)) {
        $refKey = trim($cl['ref_slip_no']) . '_' . intval($cl['product_id']);
        $claimed_by_ref[$refKey] = intval($cl['total_claimed']);
    }
}

// Fetch invoices and items
$sql = "
    SELECT inv.id AS invoice_id,
           inv.invoice_no,
           inv.slip_no,
           inv.date AS sale_date,
           inv.slip_date,
           inv.customer_id,
           inv.vehicle_number,
           inv.payment_type,
           inv.slip_type,
           inv.details,
           inv.ref_slip_no,
           inv.ref_slip_date,
           inv.temp_slip_id,
           inv.temp_wasoli_amount,
           inv.is_returned,
           inv.settled_in_slip_id,
           inv.total_items,
           inv.total_quantity,
           inv.total_amount,
           inv.charge_amount,
           inv.payment_status,
           inv.paid_amount,
           c.id AS cust_id,
           c.name AS customer_name,
           c.phone AS customer_phone,
           c.other_rate AS customer_rate_policy,
           settling_inv.slip_no AS settling_slip_no,
           sal.id AS sale_id,
           sal.product_id,
           sal.quantity AS line_quantity,
           sal.issue_quantity AS line_issue_quantity,
           sal.balance_quantity AS line_balance_quantity,
           sal.rate AS line_rate,
           sal.amount AS line_amount,
           p.name AS product_name,
           COALESCE(cat.name, '') AS category_name,
           sh.name AS shift_name
    FROM tbl_lubricant_sale_invoices inv
    LEFT JOIN tbl_customers c ON (inv.customer_id = c.id)
    LEFT JOIN tbl_shifts sh ON (inv.shift_id = sh.id)
    LEFT JOIN tbl_lubricant_sale_invoices settling_inv ON (inv.settled_in_slip_id = settling_inv.id)
    JOIN tbl_lubricant_sales sal ON (sal.invoice_id = inv.id)
    JOIN tbl_lubricant_products p ON (sal.product_id = p.id)
    LEFT JOIN tbl_product_categories cat ON (p.category_id = cat.id)
    WHERE $where_sql
    ORDER BY COALESCE(c.name, 'ZZZ') ASC, inv.customer_id ASC, inv.slip_date DESC, inv.id DESC, sal.id ASC
";

$res = mysqli_query($connection, $sql);
$customers_ledger = [];

$grand_total_customers    = 0;
$grand_total_vouchers     = 0;
$grand_physical_delivered = 0;
$grand_uncollected_bal    = 0;
$grand_billed_receivable  = 0;
$grand_collected_paid     = 0;
$grand_outstanding_due    = 0;

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $accId     = intval($row['customer_id'] ?? 0);
        $accKey    = $accId > 0 ? strval($accId) : 'unassigned';
        $custName  = !empty($row['customer_name']) ? $row['customer_name'] : 'Unregistered Customer';
        $custPhone = !empty($row['customer_phone']) ? $row['customer_phone'] : '—';
        $rateTier  = !empty($row['customer_rate_policy']) ? $row['customer_rate_policy'] : 'Credit';

        if (!isset($customers_ledger[$accKey])) {
            $customers_ledger[$accKey] = [
                'cust_id'                   => $accId,
                'customer_name'             => $custName,
                'customer_phone'            => $custPhone,
                'rate_tier'                 => $rateTier,
                'vehicles'                  => [],
                'invoices'                  => [],
                'total_physical_delivered'  => 0,
                'uncollected_balance'       => 0,
                'total_billed_charge'       => 0,
                'total_paid'                => 0,
                'outstanding_due'           => 0
            ];
        }

        if (!empty($row['vehicle_number']) && !in_array($row['vehicle_number'], $customers_ledger[$accKey]['vehicles'])) {
            $customers_ledger[$accKey]['vehicles'][] = $row['vehicle_number'];
        }

        $invId = intval($row['invoice_id']);
        if (!isset($customers_ledger[$accKey]['invoices'][$invId])) {
            $customers_ledger[$accKey]['invoices'][$invId] = [
                'invoice_id'         => $invId,
                'invoice_no'         => $row['invoice_no'],
                'slip_no'            => $row['slip_no'] ?: $row['invoice_no'],
                'sale_date'          => $row['sale_date'],
                'shift_name'         => $row['shift_name'] ?? '',
                'slip_date'          => $row['slip_date'] ?: $row['sale_date'],
                'vehicle_number'     => $row['vehicle_number'] ?: '—',
                'slip_type'          => $row['slip_type'] ?: 'Permanent Slip',
                'ref_slip_no'        => $row['ref_slip_no'],
                'ref_slip_date'      => $row['ref_slip_date'],
                'temp_wasoli_amount' => floatval($row['temp_wasoli_amount'] ?? 0),
                'is_returned'        => intval($row['is_returned'] ?? 0),
                'settling_slip_no'   => $row['settling_slip_no'],
                'charge_amount'      => floatval($row['charge_amount']),
                'paid_amount'        => floatval($row['paid_amount']),
                'payment_status'     => $row['payment_status'] ?: 'Unpaid',
                'items'              => [],
                'inv_physical_qty'   => 0,
                'inv_pending_bal'    => 0
            ];
        }

        $lQty  = intval($row['line_quantity']);
        $lIss  = intval($row['line_issue_quantity']);
        $lBal  = intval($row['line_balance_quantity']);
        $st    = $row['slip_type'] ?: 'Permanent Slip';

        if ($st === 'Balanced Slip' || $st === 'Temporary Slip') {
            $linePhysical = $lQty;
        } else {
            $linePhysical = ($lIss > 0) ? $lIss : ($lQty - $lBal);
            if ($linePhysical < 0) $linePhysical = 0;
        }

        $lineNetBal = 0;
        if ($st === 'Permanent Slip' && $lBal > 0) {
            $slipNum = trim($row['slip_no'] ?: $row['invoice_no']);
            $refKey  = $slipNum . '_' . intval($row['product_id']);
            $claimed = $claimed_by_ref[$refKey] ?? 0;
            $lineNetBal = max(0, $lBal - $claimed);
        }

        $customers_ledger[$accKey]['invoices'][$invId]['items'][] = [
            'product_name'     => $row['product_name'],
            'quantity'         => $lQty,
            'issue_quantity'   => $linePhysical,
            'balance_quantity' => $lBal,
            'net_balance'      => $lineNetBal,
            'rate'             => floatval($row['line_rate']),
            'amount'           => floatval($row['line_amount'])
        ];

        $customers_ledger[$accKey]['invoices'][$invId]['inv_physical_qty'] += $linePhysical;
        $customers_ledger[$accKey]['invoices'][$invId]['inv_pending_bal']  += $lineNetBal;
    }

    foreach ($customers_ledger as $cKey => &$cData) {
        $grand_total_customers++;
        foreach ($cData['invoices'] as $inv) {
            $grand_total_vouchers++;
            $cData['total_physical_delivered'] += $inv['inv_physical_qty'];
            $cData['uncollected_balance']      += $inv['inv_pending_bal'];
            $cData['total_billed_charge']      += $inv['charge_amount'];
            $cData['total_paid']               += $inv['paid_amount'];
        }
        $cData['outstanding_due'] = max(0.00, round($cData['total_billed_charge'] - $cData['total_paid'], 2));

        $grand_physical_delivered += $cData['total_physical_delivered'];
        $grand_uncollected_bal    += $cData['uncollected_balance'];
        $grand_billed_receivable  += $cData['total_billed_charge'];
        $grand_collected_paid     += $cData['total_paid'];
        $grand_outstanding_due    += $cData['outstanding_due'];
    }
    unset($cData);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Customer Product Credit Ledger - Statement</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: 'Roboto', sans-serif; font-size: 11px; color: #111; background: #fff; margin: 0; padding: 15px; }
        
        .no-print-bar {
            background: #f1f5f9;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-box {
            display: flex;
            align-items: center;
            justify-content: <?php echo $hasLogo ? 'space-between' : 'center'; ?>;
            text-align: <?php echo $hasLogo ? 'left' : 'center'; ?>;
            border-bottom: 2px solid #04204e;
            padding-bottom: 12px;
            margin-bottom: 16px;
            gap: 16px;
        }
        .header-box-logo { max-height: 55px; max-width: 140px; object-fit: contain; }
        .header-box-content { flex: 1; }
        .header-box h2 { margin: 0 0 2px; color: #04204e; font-size: 18px; font-weight: 800; text-transform: uppercase; }
        .header-box .station-tagline { font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 2px; }
        .header-box .station-contact { font-size: 9.5px; color: #64748b; margin-bottom: 3px; }
        .header-box h4 { margin: 2px 0; font-size: 12.5px; font-weight: 700; color: #0284c7; }
        .header-box p { margin: 0; font-size: 9.5px; color: #64748b; }

        .customer-card-pdf {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            margin-bottom: 22px;
            page-break-inside: avoid;
            overflow: hidden;
        }

        .customer-pdf-header {
            background: #04204e;
            color: #fff;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .customer-meta-sub {
            background: #f8fafc;
            padding: 6px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10.5px;
            color: #475569;
        }

        table.pdf-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        table.pdf-table th {
            background: #f1f5f9;
            color: #04204e;
            font-weight: 700;
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            text-align: center;
        }

        table.pdf-table td {
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }

        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-left   { text-align: left; }
        .font-weight-bold { font-weight: 700; }

        .pdf-ribbon {
            background: #f8fafc;
            border-top: 1.5px solid #cbd5e1;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
        }

        .signature-box {
            margin-top: 35px;
            display: flex;
            justify-content: space-between;
            page-break-inside: avoid;
        }
        .signature-line {
            width: 28%;
            border-top: 1px solid #475569;
            text-align: center;
            font-size: 10px;
            padding-top: 4px;
            color: #334155;
            font-weight: 600;
        }

        @media print {
            .no-print-bar { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div>
            <strong>Print Preview:</strong> Customer Product Credit &amp; Lubricant Statement
        </div>
        <div>
            <button onclick="window.print()" style="background:#04204e; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:bold; cursor:pointer;">
                Print / Save PDF
            </button>
            <button onclick="window.close()" style="background:#e2e8f0; color:#333; border:none; padding:6px 14px; border-radius:4px; cursor:pointer; margin-left:8px;">
                Close
            </button>
        </div>
    </div>

    <!-- Letterhead -->
    <div class="header-box">
        <?php if ($hasLogo): ?>
            <img src="../<?php echo htmlspecialchars($station_settings['logo_path']); ?>" alt="Logo" class="header-box-logo">
        <?php endif; ?>
        <div class="header-box-content">
            <h2><?php echo htmlspecialchars($station_settings['station_name'] ?? 'Petrol Pump Management System'); ?></h2>
            <?php if (!empty($station_settings['tagline'])): ?>
                <div class="station-tagline"><?php echo htmlspecialchars($station_settings['tagline']); ?></div>
            <?php endif; ?>
            <div class="station-contact">
                <?php echo htmlspecialchars($station_settings['address'] ?? ''); ?>
                <?php if (!empty($station_settings['phone'])): ?> | Phone: <?php echo htmlspecialchars($station_settings['phone']); ?><?php endif; ?>
            </div>
            <h4>CUSTOMER PRODUCT CREDIT &amp; LUBRICANT LEDGER STATEMENT</h4>
            <p>
                Generated: <?php echo date('d-m-Y H:i A'); ?>
                <?php if (!empty($fromDate) && !empty($toDate)): ?>
                    &nbsp;|&nbsp; Period: <?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (empty($customers_ledger)): ?>
        <p class="text-center" style="margin: 40px 0; font-size:12px; color:#64748b;">No product credit vouchers found for the selected criteria.</p>
    <?php else: ?>

        <?php foreach ($customers_ledger as $c): ?>
            <div class="customer-card-pdf">
                <div class="customer-pdf-header">
                    <span><?php echo htmlspecialchars($c['customer_name']); ?></span>
                    <span style="font-size:11px; font-weight:normal;"><?php echo htmlspecialchars($c['rate_tier']); ?> Rate Policy</span>
                </div>
                <div class="customer-meta-sub">
                    Phone: <strong><?php echo htmlspecialchars($c['customer_phone']); ?></strong>
                    <?php if (!empty($c['vehicles'])): ?>
                        &nbsp;|&nbsp; Vehicles: <strong><?php echo htmlspecialchars(implode(', ', $c['vehicles'])); ?></strong>
                    <?php endif; ?>
                    &nbsp;|&nbsp; Total Slips: <strong><?php echo count($c['invoices']); ?></strong>
                </div>

                <table class="pdf-table">
                    <thead>
                        <tr>
                            <th style="width: 25px;">#</th>
                            <th style="width: 70px;">Date</th>
                            <th style="width: 80px;">Slip #</th>
                            <th style="width: 80px;">Type</th>
                            <th style="width: 70px;">Vehicle</th>
                            <th style="text-align: left;">Products &amp; Quantities</th>
                            <th style="width: 60px;">Delivered</th>
                            <th style="width: 70px;">Balance</th>
                            <th style="width: 85px; text-align: right;">Must Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($c['invoices'] as $inv): 
                            $prodsText = [];
                            foreach ($inv['items'] as $it) {
                                $prodsText[] = $it['product_name'] . ' (' . $it['quantity'] . ' @ Rs.' . number_format($it['rate'], 0) . ')';
                            }
                            $prodsSummary = implode('; ', $prodsText);
                        ?>
                        <tr>
                            <td class="text-center"><?php echo $counter++; ?></td>
                            <td class="text-center">
                                <?php echo date('d-m-Y', strtotime($inv['slip_date'])); ?>
                                <?php if (!empty($inv['shift_name'])): ?>
                                    <div style="font-size: 8px; color: #475569;"><?php echo htmlspecialchars($inv['shift_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center font-weight-bold"><?php echo htmlspecialchars($inv['slip_no']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($inv['slip_type']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($inv['vehicle_number']); ?></td>
                            <td class="text-left"><?php echo htmlspecialchars($prodsSummary); ?></td>
                            <td class="text-center font-weight-bold"><?php echo $inv['inv_physical_qty']; ?></td>
                            <td class="text-center">
                                <?php if ($inv['inv_pending_bal'] > 0): ?>
                                    <strong><?php echo $inv['inv_pending_bal']; ?> bal</strong>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold;">
                                <?php if ($inv['slip_type'] === 'Balanced Slip'): ?>
                                    <span style="color: #64748b; font-size: 9.5px;">Rs. 0.00 (Pre-paid)</span>
                                <?php elseif ($inv['slip_type'] === 'Temporary Slip'): ?>
                                    <?php if (!empty($inv['settling_slip_no'])): ?>
                                        <span style="color: #047857;">Rs. 0.00</span>
                                        <div style="font-size: 8px; color: #047857;">(Billed in #<?php echo htmlspecialchars($inv['settling_slip_no']); ?>)</div>
                                    <?php else: ?>
                                        <span style="color: #888;">Rs. 0.00</span>
                                        <div style="font-size: 8px; color: #b07800;">(Loan Chit)</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #b91c1c;">Rs. <?php echo number_format($inv['charge_amount'], 2); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pdf-ribbon">
                    <div>Total Handover: <strong><?php echo $c['total_physical_delivered']; ?> units</strong></div>
                    <div>Active Pending Balance: <strong><?php echo $c['uncollected_balance']; ?> units</strong></div>
                    <div>Total Invoiced Receivable: <strong style="color:#b91c1c;">Rs. <?php echo number_format($c['total_billed_charge'], 2); ?></strong></div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Grand Summary for Multi-Customer View -->
        <?php if ($grand_total_customers > 1): ?>
        <div style="border:1.5px solid #04204e; border-radius:6px; padding:10px 14px; background:#f8fafc; margin-top:15px; page-break-inside:avoid;">
            <div style="font-weight:800; color:#04204e; font-size:12px; margin-bottom:6px;">
                GRAND SUMMARY ACROSS ALL CUSTOMERS (<?php echo $grand_total_customers; ?> Accounts | <?php echo $grand_total_vouchers; ?> Vouchers)
            </div>
            <table style="width:100%; font-size:11px;">
                <tr>
                    <td>Total Delivered: <strong><?php echo number_format($grand_physical_delivered, 0); ?> units</strong></td>
                    <td>Pending Balances: <strong><?php echo number_format($grand_uncollected_bal, 0); ?> units</strong></td>
                    <td colspan="2">Total Invoiced To Collect: <strong style="color:#b91c1c; font-size:12px;">Rs. <?php echo number_format($grand_billed_receivable, 2); ?></strong></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="signature-box">
            <div class="signature-line">Prepared By</div>
            <div class="signature-line">Audited &amp; Checked By</div>
            <div class="signature-line">Authorized Signatory / Customer</div>
        </div>

    <?php endif; ?>

</body>
</html>
