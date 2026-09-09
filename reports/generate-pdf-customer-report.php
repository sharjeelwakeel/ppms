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
// Fetch slips with settling slip join
$report_sql = "SELECT mrcs.*,
                      c.id AS cust_id,
                      c.name AS customer_name,
                      c.phone AS customer_phone,
                      c.fuel_rate AS customer_rate_tier,
                      n.name AS nozzle_name,
                      i.name AS item_name,
                      settled_by.slip_no AS settling_slip_no
               FROM tbl_meter_reading_credit_sales mrcs
               LEFT JOIN tbl_customers c ON (mrcs.account_number = c.id)
               LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
               LEFT JOIN tbl_items i ON (n.item_id = i.id)
               LEFT JOIN tbl_meter_reading_credit_sales settled_by ON (mrcs.settled_in_slip_id = settled_by.id)
               WHERE $where_sql AND (mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')
               ORDER BY COALESCE(c.name, 'ZZZ') ASC, mrcs.slip_date DESC, mrcs.id DESC";

$report_res = mysqli_query($connection, $report_sql);

$customers_ledger = [];
if ($report_res) {
    while ($row = mysqli_fetch_assoc($report_res)) {
        $accNo     = !empty($row['account_number']) ? $row['account_number'] : 'unassigned';
        $custName  = !empty($row['customer_name']) ? $row['customer_name'] : 'Account #' . $accNo;
        $custPhone = !empty($row['customer_phone']) ? $row['customer_phone'] : '—';
        $rateTier  = !empty($row['customer_rate_tier']) ? $row['customer_rate_tier'] : 'Credit';

        if (!isset($customers_ledger[$accNo])) {
            $customers_ledger[$accNo] = [
                'cust_id'                   => $accNo,
                'customer_name'             => $custName,
                'customer_phone'            => $custPhone,
                'rate_tier'                 => $rateTier,
                'vehicles'                  => [],
                'slips'                     => [],
                'total_fuel'                => 0, // All physical litres pumped
                'permanent_fuel'            => 0,
                'balanced_fuel'             => 0,
                'temporary_fuel'            => 0,
                'temporary_fuel_pending'    => 0, // Open loan fuel litres
                'temporary_fuel_returned'   => 0, // Settled loan fuel litres
                'permanent_balance'         => 0, // Sum of balance_1 + balance_2 quota generated
                'balanced_drawn'            => 0, // Sum of fuel drawn on Balanced slips
                'balanced_quota_settled'    => 0, // Sum of original voucher quota cleared by Balanced slips
                'price_fluctuation_litres'  => 0, // Quota settled minus physical pumped
                'remaining_balance'         => 0, // permanent_balance - balanced_quota_settled
                'overdraw_amount'           => 0,
                'permanent_charge'          => 0, // Total money billed on Permanent slips
                'temporary_charge_pending'  => 0, // Est. value of open loan chits
                'temporary_charge_returned' => 0, // Value of settled loan fuel
                'total_to_collect'          => 0  // Net billed receivable (= permanent_charge)
            ];
        }

        $st             = $row['slip_type'] ?: 'Permanent Slip';
        $rate           = floatval($row['rate']);
        $baseQty        = floatval($row['quantity']);
        $issueQty       = floatval($row['issue_quantity']);
        $wasoli         = floatval($row['wasoli']);
        $tempRate       = floatval($row['temp_rate']) > 0 ? floatval($row['temp_rate']) : $rate;
        $tempSlipNo     = trim($row['temp_slip_no'] ?? '');
        $refSlipNo      = trim($row['ref_slip_no'] ?? '');
        $bal            = floatval($row['balance_1']) + floatval($row['balance_2']);
        $isReturned     = intval($row['is_returned'] ?? 0);
        $settlingSlipNo = trim($row['settling_slip_no'] ?? '');

        if ($st === 'Temporary Slip') {
            // Physical fuel dispensed as open or settled loan chit
            $loanQty = ($baseQty > 0) ? $baseQty : (($issueQty > 0) ? $issueQty : $wasoli);
            $loanVal = round($loanQty * $rate, 2);

            $customers_ledger[$accNo]['temporary_fuel'] += $loanQty;
            $customers_ledger[$accNo]['total_fuel']     += $loanQty;

            if ($isReturned === 1) {
                // Settled on a Permanent Slip (Scenario 2) -> already billed on that permanent voucher!
                $customers_ledger[$accNo]['temporary_fuel_returned']   += $loanQty;
                $customers_ledger[$accNo]['temporary_charge_returned'] += $loanVal;
                $chgAmt = 0.00; // Zero additional charge to prevent double-billing
            } else {
                // Open loan chit awaiting permanent voucher
                $customers_ledger[$accNo]['temporary_fuel_pending']   += $loanQty;
                $customers_ledger[$accNo]['temporary_charge_pending'] += $loanVal;
                $chgAmt = 0.00; // Customer charge is deferred until permanent voucher settles it
            }

            $dispensedQty = $loanQty;
            $row['effective_charge'] = $chgAmt;
            $row['loan_value'] = $loanVal;
        } elseif ($st === 'Balanced Slip') {
            // Fuel drawn against pre-paid balance quota
            $balQty = ($baseQty > 0) ? $baseQty : $issueQty;

            // What was the original voucher quota that this balanced slip claimed and closed?
            $origQuota = floatval($row['balance_1']) + floatval($row['balance_2']);
            if ($origQuota <= 0 && !empty($refSlipNo)) {
                // Self-heal: look up referenced permanent slip's balance
                $rs_no_safe = mysqli_real_escape_string($connection, $refSlipNo);
                $rs_acc_safe = mysqli_real_escape_string($connection, $accNo);
                $q_ref = mysqli_query($connection, "SELECT (balance_1 + balance_2) AS ref_bal, issue_quantity, quantity FROM tbl_meter_reading_credit_sales WHERE slip_no = '$rs_no_safe' AND slip_type = 'Permanent Slip' AND account_number = '$rs_acc_safe' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
                if ($q_ref && $r_ref = mysqli_fetch_assoc($q_ref)) {
                    $origQuota = floatval($r_ref['ref_bal']);
                    if ($origQuota <= 0) {
                        $origQuota = max(0.00, floatval($r_ref['issue_quantity']) - floatval($r_ref['quantity']));
                    }
                }
            }
            if ($origQuota <= 0) {
                $origQuota = $balQty;
            }

            // Price Fluctuation Litres: positive = price increased (absorbed); negative = price decreased (gain)
            $priceFluctLtr = round($origQuota - $balQty, 2);

            $customers_ledger[$accNo]['balanced_fuel']             += $balQty;
            $customers_ledger[$accNo]['balanced_drawn']            += $balQty;
            $customers_ledger[$accNo]['balanced_quota_settled']    += $origQuota;
            $customers_ledger[$accNo]['price_fluctuation_litres']  += $priceFluctLtr;
            $customers_ledger[$accNo]['total_fuel']                += $balQty;

            $dispensedQty = $balQty;
            $chgAmt = 0.00; // Pre-paid on original voucher
            $row['effective_charge']      = 0.00;
            $row['orig_quota_settled']    = $origQuota;
            $row['price_fluctuation_ltr'] = $priceFluctLtr;
        } else { // Permanent Slip
            $dispensedQty = $baseQty;
            $effIssue     = ($issueQty > 0) ? $issueQty : $baseQty;

            // Direct charge amount from db (or fallback)
            $chgAmt = floatval($row['charge_amount']);
            if ($chgAmt <= 0) {
                $chgAmt = round(($effIssue * $rate) + ($wasoli * $tempRate), 2);
            }
            // Auto-compute balance if balance fields were 0 but effIssue > baseQty
            if ($bal <= 0 && $effIssue > $baseQty) {
                $bal = max(0.00, round($effIssue - $baseQty, 2));
            }

            $customers_ledger[$accNo]['permanent_fuel']    += $dispensedQty;
            $customers_ledger[$accNo]['permanent_balance'] += $bal;
            $customers_ledger[$accNo]['permanent_charge']  += $chgAmt;
            $customers_ledger[$accNo]['total_fuel']        += $dispensedQty;
            $customers_ledger[$accNo]['total_to_collect']  += $chgAmt;

            $row['effective_charge'] = $chgAmt;
        }

        if (!empty($row['vehicle_number']) && !in_array($row['vehicle_number'], $customers_ledger[$accNo]['vehicles'])) {
            $customers_ledger[$accNo]['vehicles'][] = $row['vehicle_number'];
        }

        $row['dispensed_qty']    = $dispensedQty;
        $row['slip_balance']     = $bal;
        $row['temp_slip_no']     = $tempSlipNo;
        $row['temp_rate']        = $tempRate;
        $row['ref_slip_no']      = $refSlipNo;
        $row['settling_slip_no'] = $settlingSlipNo;
        $customers_ledger[$accNo]['slips'][] = $row;
    }

    // Calculate remaining quota balance per customer
    foreach ($customers_ledger as $cId => &$cItem) {
        $cItem['remaining_balance'] = max(0, round($cItem['permanent_balance'] - $cItem['balanced_quota_settled'], 2));
        $cItem['overdraw_amount']   = max(0, round($cItem['balanced_quota_settled'] - $cItem['permanent_balance'], 2));
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
                            <th style="width: 70px;">Slip Date</th>
                            <th style="width: 65px;">Slip No</th>
                            <th style="width: 80px;">Slip Type</th>
                            <th style="width: 70px;">Vehicle No</th>
                            <th>Nozzle / Fuel</th>
                            <th style="width: 45px; text-align: right;">Rate</th>
                            <th style="width: 50px; text-align: right;">Issued</th>
                            <th style="width: 50px; text-align: right;">Pumped</th>
                            <th style="width: 60px; text-align: right;">Balance</th>
                            <th style="width: 95px; text-align: left;">Temp. Receive</th>
                            <th style="width: 75px; text-align: right;">Must Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        foreach ($cdata['slips'] as $slip): 
                            $st = $slip['slip_type'];
                            $issVal = floatval($slip['issue_quantity']);
                            $dispVal = floatval($slip['dispensed_qty']);
                            $displayIssue = ($issVal > 0) ? $issVal : $dispVal;
                        ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $sn++; ?></td>
                            <td style="text-align: center;"><?php echo date('d-m-Y', strtotime($slip['slip_date'])); ?></td>
                            <td style="text-align: center; font-weight: bold;"><?php echo htmlspecialchars($slip['slip_no']); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($st); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($slip['vehicle_number'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($slip['nozzle_name'] ?: '—'); ?> (<?php echo htmlspecialchars($slip['item_name'] ?: 'Fuel'); ?>)</td>
                            <td style="text-align: right;"><?php echo number_format($slip['rate'], 2); ?></td>
                            <td style="text-align: right; color: #555;"><?php echo number_format($displayIssue, 2); ?></td>
                            <td style="text-align: right; font-weight: bold; color: #04204e;"><?php echo number_format($dispVal, 2); ?></td>
                            <td style="text-align: right;">
                                <?php if ($st === 'Permanent Slip' && $slip['slip_balance'] > 0): ?>
                                    <strong style="color: #0d47a1;">+<?php echo number_format($slip['slip_balance'], 2); ?></strong>
                                <?php elseif ($st === 'Balanced Slip'): ?>
                                    <?php 
                                    $quotaSettled = !empty($slip['orig_quota_settled']) ? floatval($slip['orig_quota_settled']) : $dispVal;
                                    $pFluct = isset($slip['price_fluctuation_ltr']) ? floatval($slip['price_fluctuation_ltr']) : 0;
                                    ?>
                                    <strong style="color: #64748b;">-<?php echo number_format($quotaSettled, 2); ?></strong>
                                    <?php if (!empty($slip['ref_slip_no'])): ?>
                                        <div style="font-size: 8px; color: #777;">(from #<?php echo htmlspecialchars($slip['ref_slip_no']); ?>)</div>
                                    <?php endif; ?>
                                    <?php if ($pFluct > 0.001): ?>
                                        <div style="font-size: 7.5px; color: #b45309; font-weight: bold;">(Price Escalation: -<?php echo number_format($pFluct, 2); ?>L)</div>
                                    <?php elseif ($pFluct < -0.001): ?>
                                        <div style="font-size: 7.5px; color: #0284c7; font-weight: bold;">(Price Drop Gain: +<?php echo number_format(abs($pFluct), 2); ?>L)</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    0.00
                                <?php endif; ?>
                            </td>
                            <td style="text-align: left;">
                                <?php if ($st === 'Permanent Slip'): ?>
                                    <?php if (!empty($slip['wasoli']) && floatval($slip['wasoli']) > 0): ?>
                                        <span style="color: #047857; font-weight: bold; font-size: 8.5px;">✓ Settling #<?php echo htmlspecialchars($slip['temp_slip_no'] ?: 'Temp'); ?> (<?php echo number_format($slip['wasoli'], 0); ?>L @ Rs. <?php echo number_format($slip['temp_rate'], 0); ?>)</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                <?php elseif ($st === 'Temporary Slip'): ?>
                                    <?php if (!empty($slip['is_returned'])): ?>
                                        <span style="color: #047857; font-weight: bold; font-size: 8.5px;">✓ Settled<?php echo !empty($slip['settling_slip_no']) ? ' (in #' . htmlspecialchars($slip['settling_slip_no']) . ')' : ''; ?></span>
                                    <?php else: ?>
                                        <span style="color: #b07800; font-weight: bold; font-size: 8.5px;">⏳ Loan Chit (Open)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: bold;">
                                <?php if ($st === 'Balanced Slip'): ?>
                                    <span style="color: #64748b; font-size: 9.5px;">Rs. 0.00 (Pre-paid)</span>
                                <?php elseif ($st === 'Temporary Slip'): ?>
                                    <?php if (!empty($slip['is_returned'])): ?>
                                        <span style="color: #047857;">Rs. 0.00</span>
                                        <div style="font-size: 8px; color: #047857;">(Billed in #<?php echo htmlspecialchars($slip['settling_slip_no'] ?: 'Perm'); ?>)</div>
                                    <?php else: ?>
                                        <span style="color: #888;">Rs. 0.00</span>
                                        <div style="font-size: 8px; color: #b07800;">(Loan Chit)</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #b91c1c;">Rs. <?php echo number_format($slip['effective_charge'], 2); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Card 2: Financial Debit / Credit Settlement & Petrol Quota Reconciliation -->
                <div class="settlement-card">
                    <div class="settlement-header">
                        1. Financial Statement (Money Receivable) &amp; 2. Fuel Quota Reconciliation
                    </div>
                    <table class="settlement-table">
                        <thead>
                            <tr style="background: #f1f5f9; font-weight: bold;">
                                <th style="text-align: left; width: 45%;">Transaction Classification</th>
                                <th style="text-align: right; width: 25%; color: #b91c1c;">Invoiced Receivable</th>
                                <th style="text-align: right; width: 30%; color: #047857;">Fuel Quota Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <strong>Permanent Slips (Billed Fuel &amp; Settled Loans)</strong>
                                    <div style="font-size: 9px; color: #666"><?php echo number_format($cdata['permanent_fuel'], 2); ?> Ltr pumped across permanent vouchers</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #b91c1c;">Rs. <?php echo number_format($cdata['permanent_charge'], 2); ?></td>
                                <td style="text-align: right; font-weight: bold; color: #0d47a1;">Quota Created: +<?php echo number_format($cdata['permanent_balance'], 2); ?> Ltr</td>
                            </tr>
                            <tr>
                                <td>
                                    <strong>Balanced Slips (Claimed Fuel Quota)</strong>
                                    <div style="font-size: 9px; color: #666"><?php echo number_format($cdata['balanced_fuel'], 2); ?> Ltr drawn against prepaid quota</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #047857;">Rs. 0.00 (Pre-paid)</td>
                                <td style="text-align: right; font-weight: bold; color: #64748b;">Quota Settled: -<?php echo number_format($cdata['balanced_quota_settled'], 2); ?> Ltr</td>
                            </tr>
                            <tr>
                                <td>
                                    <strong>Price Fluctuation Impact (Separate Item)</strong>
                                    <div style="font-size: 8.5px; color: #666;">
                                        <?php if ($cdata['price_fluctuation_litres'] > 0.001): ?>
                                            Litres absorbed due to fuel price increase
                                        <?php elseif ($cdata['price_fluctuation_litres'] < -0.001): ?>
                                            Extra litres gained due to fuel price decrease
                                        <?php else: ?>
                                            Zero price fluctuation impact (prices unchanged)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right; color: #666;">—</td>
                                <td style="text-align: right; font-weight: bold; <?php echo ($cdata['price_fluctuation_litres'] > 0.001) ? 'color: #b45309;' : (($cdata['price_fluctuation_litres'] < -0.001) ? 'color: #0284c7;' : 'color: #666;'); ?>">
                                    <?php if ($cdata['price_fluctuation_litres'] > 0.001): ?>
                                        -<?php echo number_format($cdata['price_fluctuation_litres'], 2); ?> Ltr (Escalation)
                                    <?php elseif ($cdata['price_fluctuation_litres'] < -0.001): ?>
                                        +<?php echo number_format(abs($cdata['price_fluctuation_litres']), 2); ?> Ltr (Price Drop Gain)
                                    <?php else: ?>
                                        0.00 Ltr
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <strong>Settled Temporary Slips</strong>
                                    <div style="font-size: 9px; color: #666"><?php echo number_format($cdata['temporary_fuel_returned'], 2); ?> Ltr loan petrol billed on permanent vouchers</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #047857;">Rs. 0.00 (Billed in Perm)</td>
                                <td style="text-align: right; color: #777;">—</td>
                            </tr>
                            <?php if ($cdata['temporary_charge_pending'] > 0): ?>
                            <tr style="background-color: #fffdf5;">
                                <td>
                                    <strong style="color: #b07800;">Open Loan Fuel (Awaiting Permanent Voucher)</strong>
                                    <div style="font-size: 9px; color: #b07800;"><?php echo number_format($cdata['temporary_fuel_pending'], 2); ?> Ltr loan petrol taken on temporary chits</div>
                                </td>
                                <td style="text-align: right; font-weight: bold; color: #b07800;">Est. Rs. <?php echo number_format($cdata['temporary_charge_pending'], 2); ?></td>
                                <td style="text-align: right; color: #b07800;">Open Loan: <?php echo number_format($cdata['temporary_fuel_pending'], 2); ?> Ltr</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #fff5f5; font-weight: bold;">
                                <td style="color: #b91c1c; font-size: 11px;">👉 TOTAL INVOICED RECEIVABLE (MUST COLLECT):</td>
                                <td colspan="2" style="text-align: right; font-size: 13px; color: #b91c1c;">
                                    Rs. <?php echo number_format($cdata['total_to_collect'], 2); ?>
                                </td>
                            </tr>
                            <tr style="background: #f0fdf4; font-weight: bold;">
                                <td style="color: #047857; font-size: 11px;">
                                    ⛽ NET PETROL VOLUME PUMP MUST DELIVER:
                                    <div style="font-size: 8.5px; color: #555; font-weight: normal;">
                                        (Total Quota Recorded: +<?php echo number_format($cdata['permanent_balance'], 2); ?> Ltr &nbsp;|&nbsp; Quota Settled on Balanced Slips: -<?php echo number_format($cdata['balanced_quota_settled'], 2); ?> Ltr)
                                    </div>
                                </td>
                                <td colspan="2" style="text-align: right; font-size: 13px; color: #047857;">
                                    <?php if ($cdata['remaining_balance'] > 0): ?>
                                        <strong><?php echo number_format($cdata['remaining_balance'], 2); ?> Ltr (Pending Delivery)</strong>
                                    <?php else: ?>
                                        <strong>0.00 Ltr (All Quota Delivered)</strong>
                                    <?php endif; ?>
                                    <?php if ($cdata['overdraw_amount'] > 0): ?>
                                        <div style="font-size: 9px; color: #d97706;">(Overdraw: <?php echo number_format($cdata['overdraw_amount'], 2); ?> Ltr)</div>
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
