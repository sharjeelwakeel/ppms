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

if (!has_permission('reports', 'show') && !has_permission('customers', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$customerId = intval($_GET['customer_id'] ?? 0);
$vehicleNum = trim($_GET['vehicle_number'] ?? '');

$where_clauses = ["1=1"];
if ($customerId > 0) {
    $where_clauses[] = "mrcs.account_number = '$customerId'";
}
if (!empty($vehicleNum)) {
    $v_safe = mysqli_real_escape_string($connection, $vehicleNum);
    $where_clauses[] = "mrcs.vehicle_number LIKE '%$v_safe%'";
}
$where_sql = implode(' AND ', $where_clauses);

// Fetch slips
$report_sql = "SELECT mrcs.*,
                      c.id AS cust_id,
                      c.name AS customer_name,
                      c.phone AS customer_phone,
                      c.fuel_rate AS customer_rate_tier,
                      n.name AS nozzle_name,
                      i.name AS item_name
               FROM tbl_meter_reading_credit_sales mrcs
               LEFT JOIN tbl_customers c ON (mrcs.account_number = c.id)
               LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
               LEFT JOIN tbl_items i ON (n.item_id = i.id)
               WHERE $where_sql
               ORDER BY COALESCE(c.name, 'ZZZ') ASC, mrcs.slip_date DESC, mrcs.id DESC";

$report_res = mysqli_query($connection, $report_sql);

$customers_ledger = [];
if ($report_res) {
    while ($row = mysqli_fetch_assoc($report_res)) {
        $accNo = !empty($row['account_number']) ? $row['account_number'] : 'unassigned';
        $custName = !empty($row['customer_name']) ? $row['customer_name'] : 'Account #' . $accNo;
        $custPhone = !empty($row['customer_phone']) ? $row['customer_phone'] : '—';
        $rateTier = !empty($row['customer_rate_tier']) ? $row['customer_rate_tier'] : 'Credit';

        if (!isset($customers_ledger[$accNo])) {
            $customers_ledger[$accNo] = [
                'cust_id'            => $accNo,
                'customer_name'      => $custName,
                'customer_phone'     => $custPhone,
                'rate_tier'          => $rateTier,
                'vehicles'           => [],
                'slips'              => [],
                'total_fuel'         => 0,
                'permanent_fuel'     => 0,
                'balanced_fuel'      => 0,
                'temporary_fuel'     => 0,
                'permanent_balance'  => 0,
                'balanced_drawn'     => 0,
                'remaining_balance'  => 0,
                'overdraw_amount'    => 0,
                'permanent_charge'   => 0,
                'temporary_charge'   => 0,
                'total_to_collect'   => 0
            ];
        }

        $st       = $row['slip_type'] ?: 'Permanent Slip';
        $rate     = floatval($row['rate']);
        $baseQty  = floatval($row['quantity']);
        $issueQty = floatval($row['issue_quantity']);
        $wasoli   = floatval($row['wasoli']);
        $bal      = floatval($row['balance_1']) + floatval($row['balance_2']);
        $nomAmt   = floatval($row['amount']);
        $chgAmt   = floatval($row['charge_amount'] ?? ($st == 'Balanced Slip' ? 0 : $nomAmt));

        $isReturned = intval($row['is_returned'] ?? 0);

        // Always calculation depends on QTY (not on issue qty)
        if ($st === 'Temporary Slip') {
            $tempQty = $wasoli > 0 ? $wasoli : ($baseQty > 0 ? $baseQty : $issueQty);
            $tempCharge = $tempQty * $rate;

            $customers_ledger[$accNo]['temporary_fuel']   += $tempQty;
            $customers_ledger[$accNo]['temporary_charge'] += $tempCharge;
            $customers_ledger[$accNo]['total_fuel']       += $tempQty;

            if ($isReturned === 1) {
                // Returned & collected
                $customers_ledger[$accNo]['temporary_fuel_returned']   = ($customers_ledger[$accNo]['temporary_fuel_returned'] ?? 0) + $tempQty;
                $customers_ledger[$accNo]['temporary_charge_returned'] = ($customers_ledger[$accNo]['temporary_charge_returned'] ?? 0) + $tempCharge;
                $chgAmt = 0.00;
            } else {
                // Pending unpaid loan
                $customers_ledger[$accNo]['temporary_fuel_pending']   = ($customers_ledger[$accNo]['temporary_fuel_pending'] ?? 0) + $tempQty;
                $customers_ledger[$accNo]['temporary_charge_pending'] = ($customers_ledger[$accNo]['temporary_charge_pending'] ?? 0) + $tempCharge;
                $customers_ledger[$accNo]['total_to_collect']         += $tempCharge;
                $chgAmt = $tempCharge;
            }

            $qty = $tempQty;
        } elseif ($st === 'Balanced Slip') {
            $balQty = $baseQty > 0 ? $baseQty : $issueQty;

            $customers_ledger[$accNo]['balanced_fuel']  += $balQty;
            $customers_ledger[$accNo]['balanced_drawn'] += $balQty;
            $customers_ledger[$accNo]['total_fuel']     += $balQty;

            $qty    = $balQty;
            $chgAmt = 0.00;
        } else { // Permanent Slip
            $permQty = $baseQty > 0 ? $baseQty : $issueQty;
            $permCharge = $permQty * $rate;

            $customers_ledger[$accNo]['permanent_fuel']    += $permQty;
            $customers_ledger[$accNo]['permanent_balance'] += $bal;
            $customers_ledger[$accNo]['permanent_charge']  += $permCharge;
            $customers_ledger[$accNo]['total_fuel']        += $permQty;
            $customers_ledger[$accNo]['total_to_collect']  += $permCharge;

            $qty    = $permQty;
            $chgAmt = $permCharge;
        }

        if (!empty($row['vehicle_number']) && !in_array($row['vehicle_number'], $customers_ledger[$accNo]['vehicles'])) {
            $customers_ledger[$accNo]['vehicles'][] = $row['vehicle_number'];
        }

        $row['dispensed_qty']    = $qty;
        $row['slip_balance']     = $bal;
        $row['effective_charge'] = $chgAmt;
        $row['temp_charge']      = ($st === 'Temporary Slip') ? ($qty * $rate) : 0;
        $row['is_returned']      = $isReturned;
        $customers_ledger[$accNo]['slips'][] = $row;
    }

    foreach ($customers_ledger as $cId => &$cItem) {
        $cItem['remaining_balance'] = max(0, $cItem['permanent_balance'] - $cItem['balanced_drawn']);
        $cItem['overdraw_amount']   = max(0, $cItem['balanced_drawn'] - $cItem['permanent_balance']);
    }
    unset($cItem);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Customer Credit & Fuel Ledger - PDF Report</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
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
            text-align: center;
            border-bottom: 2px solid #04204e;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header-box h2 { margin: 0 0 4px; color: #04204e; font-size: 18px; font-weight: 800; text-transform: uppercase; }
        .header-box h4 { margin: 0 0 4px; font-size: 13px; font-weight: 700; color: #333; }
        .header-box p { margin: 0; font-size: 10px; color: #666; }

        .customer-meta-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 16px;
        }
        .table-custom th {
            background: #04204e;
            color: #fff;
            padding: 6px 4px;
            border: 1px solid #04204e;
            text-align: center;
            font-size: 9.5px;
        }
        .table-custom td {
            padding: 5px 4px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }
        .table-custom tr:nth-child(even) { background: #f8fafc; }

        .settlement-card {
            border: 1px solid #04204e;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .settlement-header {
            background: #04204e;
            color: #fff;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .settlement-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .settlement-table th, .settlement-table td {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
        }

        .sig-section {
            display: flex;
            justify-content: space-between;
            margin-top: 35px;
            page-break-inside: avoid;
        }
        .sig-box {
            width: 28%;
            border-top: 1px solid #333;
            text-align: center;
            padding-top: 5px;
            font-size: 10px;
            font-weight: 700;
            color: #444;
        }

        @media print {
            .no-print-bar { display: none !important; }
            body { padding: 0 !important; }
            .customer-card-break { page-break-after: always; }
        }
    </style>
</head>
<body>

    <div class="no-print-bar d-print-none">
        <div>
            <strong>PDF Preview</strong> &mdash; Petrol Pump Management System
        </div>
        <div>
            <button onclick="window.print();" style="background:#04204e; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:700; cursor:pointer;">
                Print / Save as PDF
            </button>
            <button onclick="window.close();" style="background:#6c757d; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:700; cursor:pointer; margin-left:6px;">
                Close
            </button>
        </div>
    </div>

    <?php if (empty($customers_ledger)): ?>
        <div style="text-align:center; padding: 40px;">
            <h3>No Customer Slips Found</h3>
            <p>Please search with valid customer or vehicle parameters.</p>
        </div>
    <?php else: ?>

        <?php foreach ($customers_ledger as $cId => $cdata): ?>
            <div class="customer-card-break">
                <!-- Letterhead -->
                <div class="header-box">
                    <h2>Petrol Pump Management System</h2>
                    <h4>Customer Credit &amp; Fuel Ledger Statement</h4>
                    <p>Generated: <?php echo date('d-m-Y h:i A'); ?> &nbsp;|&nbsp; PPMS Audit Ledger</p>
                </div>

                <!-- Customer Details -->
                <div class="customer-meta-box">
                    <div>
                        <strong style="font-size: 13px; color: #04204e;"><?php echo htmlspecialchars($cdata['customer_name']); ?></strong>
                        <div style="font-size: 10px; color: #555; margin-top: 2px;">
                            Account #: <strong><?php echo htmlspecialchars($cdata['cust_id']); ?></strong> &nbsp;|&nbsp;
                            Contact: <strong><?php echo htmlspecialchars($cdata['customer_phone']); ?></strong> &nbsp;|&nbsp;
                            Rate Tier: <strong><?php echo htmlspecialchars($cdata['rate_tier']); ?></strong>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 10px;">
                        <div>Total Slips: <strong><?php echo count($cdata['slips']); ?></strong></div>
                        <?php if (!empty($cdata['vehicles'])): ?>
                            <div style="margin-top: 2px;">Vehicles: <strong><?php echo implode(', ', array_map('htmlspecialchars', $cdata['vehicles'])); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Slips Table -->
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th style="width: 25px;">#</th>
                            <th style="width: 65px;">Slip Date</th>
                            <th style="width: 50px;">Reading #</th>
                            <th style="width: 65px;">Slip No</th>
                            <th style="width: 85px;">Slip Type</th>
                            <th style="width: 70px;">Vehicle No</th>
                            <th>Nozzle / Fuel</th>
                            <th style="width: 50px; text-align: right;">Rate</th>
                            <th style="width: 60px; text-align: right;">QTY</th>
                            <th style="width: 60px; text-align: right;">Balance</th>
                            <th style="width: 60px; text-align: right;">Tmp. Rec</th>
                            <th style="width: 75px; text-align: right;">Must Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        foreach ($cdata['slips'] as $slip): 
                            $st = $slip['slip_type'];
                        ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $sn++; ?></td>
                            <td style="text-align: center;"><?php echo date('d-m-Y', strtotime($slip['slip_date'])); ?></td>
                            <td style="text-align: center;">#<?php echo $slip['meter_reading_id']; ?></td>
                            <td style="text-align: center; font-weight: bold;"><?php echo htmlspecialchars($slip['slip_no']); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($st); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($slip['vehicle_number'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($slip['nozzle_name'] ?: '—'); ?> (<?php echo htmlspecialchars($slip['item_name'] ?: 'Fuel'); ?>)</td>
                            <td style="text-align: right;"><?php echo number_format($slip['rate'], 2); ?></td>
                            <td style="text-align: right; font-weight: bold; color: #04204e;"><?php echo number_format($slip['dispensed_qty'], 2); ?></td>
                            <td style="text-align: right;">
                                <?php if ($st === 'Permanent Slip' && $slip['slip_balance'] > 0): ?>
                                    +<?php echo number_format($slip['slip_balance'], 2); ?>
                                <?php elseif ($st === 'Balanced Slip'): ?>
                                    -<?php echo number_format($slip['dispensed_qty'], 2); ?>
                                <?php else: ?>
                                    0.00
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if ($st === 'Temporary Slip'): ?>
                                    <?php if (!empty($slip['is_returned'])): ?>
                                        <span style="color: #047857; font-weight: bold; font-size: 9px;">✓ <?php echo number_format($slip['dispensed_qty'], 2); ?> (Received)</span>
                                    <?php else: ?>
                                        <span style="color: #b91c1c; font-weight: bold; font-size: 9px;">⏳ <?php echo number_format($slip['dispensed_qty'], 2); ?> (Giving Loan)</span>
                                    <?php endif; ?>
                                <?php elseif ($slip['wasoli'] > 0): ?>
                                    <strong><?php echo number_format($slip['wasoli'], 2); ?></strong>
                                <?php else: ?>
                                    0.00
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; color: <?php echo ($st === 'Balanced Slip' || !empty($slip['is_returned'])) ? '#047857' : '#b91c1c'; ?>;">
                                <?php if ($st === 'Balanced Slip'): ?>
                                    Rs. 0.00 (Pre-paid)
                                <?php elseif ($st === 'Temporary Slip'): ?>
                                    <?php if (!empty($slip['is_returned'])): ?>
                                        Rs. 0.00
                                        <div style="font-size: 8px; color: #047857;">(Received / Settled)</div>
                                    <?php else: ?>
                                        Rs. <?php echo number_format($slip['temp_charge'], 2); ?>
                                        <div style="font-size: 8px; color: #b91c1c;">(Giving &mdash; To Collect)</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Rs. <?php echo number_format($slip['effective_charge'], 2); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Card 2: Financial Debit / Credit Settlement -->
                <div class="settlement-card">
                    <div class="settlement-header">
                        Financial Debit / Credit Settlement (What We Must Collect &amp; Petrol to Deliver)
                    </div>
                    <table class="settlement-table">
                        <thead>
                            <tr style="background: #f1f5f9; font-weight: bold;">
                                <th style="text-align: left;">Transaction Classification</th>
                                <th style="text-align: right; width: 30%; color: #b91c1c;">Debit (Receivable from Customer)</th>
                                <th style="text-align: right; width: 30%; color: #047857;">Credit (Pre-Paid / Settled)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <strong>Permanent Slips (Billed Fuel)</strong>
                                    <div style="font-size: 9px; color: #666"><?php echo number_format($cdata['permanent_fuel'], 2); ?> Ltr issued across permanent chits</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #b91c1c;">Rs. <?php echo number_format($cdata['permanent_charge'], 2); ?></td>
                                <td style="text-align: right; color: #777;">—</td>
                            </tr>
                            <tr>
                                <td>
                                    <strong>Balanced Slips (Claimed Fuel)</strong>
                                    <div style="font-size: 9px; color: #666"><?php echo number_format($cdata['balanced_fuel'], 2); ?> Ltr claimed against previous balance quota</div>
                                </td>
                                <td style="text-align: right; color: #777;">—</td>
                                <td style="text-align: right; font-weight: bold; color: #047857;">Rs. 0.00 (Settled)</td>
                            </tr>
                            <tr style="background-color: #fffdf5;">
                                <td>
                                    <strong style="color: #b91c1c;">Giving Tmp. Receive (Loan Petrol Given &mdash; Must Collect)</strong>
                                    <div style="font-size: 9px; color: #b91c1c; font-weight: bold;"><?php echo number_format($cdata['temporary_fuel_pending'] ?? 0, 2); ?> Ltr loan petrol given on temporary chit &mdash; We need to collect this money</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #b91c1c;">Rs. <?php echo number_format($cdata['temporary_charge_pending'] ?? 0, 2); ?></td>
                                <td style="text-align: right; color: #777;">—</td>
                            </tr>
                            <?php if (!empty($cdata['temporary_charge_returned'])): ?>
                            <tr style="background-color: #f0fdf4;">
                                <td>
                                    <strong style="color: #047857;">Received Tmp. Receive (Loan Petrol Received / Settled)</strong>
                                    <div style="font-size: 9px; color: #047857;"><?php echo number_format($cdata['temporary_fuel_returned'] ?? 0, 2); ?> Ltr loan petrol has been received &amp; settled</div>
                                </td>
                                <td style="text-align: right; color: #777;">—</td>
                                <td style="text-align: right; font-weight: bold; color: #047857;">Rs. <?php echo number_format($cdata['temporary_charge_returned'], 2); ?> (Received)</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #fff5f5; font-weight: bold;">
                                <td style="color: #b91c1c; font-size: 11px;">👉 TOTAL AMOUNT WE NEED TO GET (MUST COLLECT):</td>
                                <td colspan="2" style="text-align: right; font-size: 13px; color: #b91c1c;">
                                    Rs. <?php echo number_format($cdata['total_to_collect'], 2); ?>
                                </td>
                            </tr>
                            <tr style="background: #f0fdf4; font-weight: bold;">
                                <td style="color: #047857; font-size: 11px;">
                                    ⛽ PETROL VOLUME WE MUST GIVE CUSTOMER:
                                    <div style="font-size: 9px; color: #555; font-weight: normal;">
                                        (Quota Recorded: +<?php echo number_format($cdata['permanent_balance'], 2); ?> Ltr &nbsp;|&nbsp; Claimed: -<?php echo number_format($cdata['balanced_drawn'], 2); ?> Ltr)
                                    </div>
                                </td>
                                <td colspan="2" style="text-align: right; font-size: 13px; color: #047857;">
                                    <?php echo number_format($cdata['remaining_balance'], 2); ?> Ltr
                                    <?php if ($cdata['overdraw_amount'] > 0): ?>
                                        <span style="font-size: 9.5px; color: #d97706; display: block;">(Overdraw: <?php echo number_format($cdata['overdraw_amount'], 2); ?> Ltr)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Signatures -->
                <div class="sig-section">
                    <div class="sig-box">Prepared By (Pump Manager)</div>
                    <div class="sig-box">Verified By (Accounts)</div>
                    <div class="sig-box">Customer Signature</div>
                </div>

            </div>
        <?php endforeach; ?>

    <?php endif; ?>

    <script>
    window.onload = function() {
        // Automatically open print dialog for printing or saving as PDF
        window.print();
    };
    </script>
</body>
</html>
