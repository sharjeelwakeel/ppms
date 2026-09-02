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

// Auto-migrate charge_amount column if not yet present
$chk_ca = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE 'charge_amount'");
if ($chk_ca && mysqli_num_rows($chk_ca) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount");
    mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET charge_amount = IF(slip_type = 'Balanced Slip', 0.00, amount)");
}

// Auto-migrate is_returned and returned_at columns if not yet present
$chk_ret = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE 'is_returned'");
if ($chk_ret && mysqli_num_rows($chk_ret) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN is_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER wasoli");
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN returned_at DATETIME DEFAULT NULL AFTER is_returned");
}

// Only Two Filters: Customer and Vehicle No
$customerId = intval($_GET['customer_id'] ?? 0);
$vehicleNum = trim($_GET['vehicle_number'] ?? '');
$isSearched = (isset($_GET['customer_id']) || isset($_GET['vehicle_number'])) && ($customerId > 0 || !empty($vehicleNum));

// Fetch all active customers for filter dropdown
$customers_res = mysqli_query($connection, "SELECT id, name, phone, fuel_rate FROM tbl_customers WHERE deleted_at IS NULL ORDER BY name ASC");
$all_customers = [];
if ($customers_res) {
    while ($crow = mysqli_fetch_assoc($customers_res)) {
        $all_customers[] = $crow;
    }
}

// Group transactions by customer
$customers_ledger = [];

// Grand totals across all customers
$grand_total_fuel       = 0;
$grand_permanent_fuel   = 0;
$grand_balanced_fuel    = 0;
$grand_temporary_fuel   = 0;
$grand_permanent_bal    = 0;
$grand_balanced_drawn   = 0;
$grand_remaining_bal    = 0;
$grand_perm_collect     = 0;
$grand_temp_collect     = 0;
$grand_total_collect    = 0;

// ONLY query when user has searched
if ($isSearched) {
    $where_clauses = [];

    if ($customerId > 0) {
        $where_clauses[] = "mrcs.account_number = '$customerId'";
    }
    if (!empty($vehicleNum)) {
        $v_safe = mysqli_real_escape_string($connection, $vehicleNum);
        $where_clauses[] = "mrcs.vehicle_number LIKE '%$v_safe%'";
    }

    $where_sql = implode(' AND ', $where_clauses);

    // Fetch credit sales records
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

    if ($report_res) {
        while ($row = mysqli_fetch_assoc($report_res)) {
            $accNo = !empty($row['account_number']) ? $row['account_number'] : 'unassigned';
            $custName = !empty($row['customer_name']) ? $row['customer_name'] : 'Account #' . $accNo;
            $custPhone = !empty($row['customer_phone']) ? $row['customer_phone'] : '—';
            $rateTier = !empty($row['customer_rate_tier']) ? $row['customer_rate_tier'] : 'Credit';

            if (!isset($customers_ledger[$accNo])) {
                $customers_ledger[$accNo] = [
                    'cust_id'                   => $accNo,
                    'customer_name'             => $custName,
                    'customer_phone'            => $custPhone,
                    'rate_tier'                 => $rateTier,
                    'vehicles'                  => [],
                    'slips'                     => [],
                    'total_fuel'                => 0,
                    'permanent_fuel'            => 0,
                    'balanced_fuel'             => 0,
                    'temporary_fuel'            => 0,
                    'temporary_fuel_pending'    => 0,
                    'temporary_fuel_returned'   => 0,
                    'permanent_balance'         => 0, // Sum of balance_1 + balance_2 from Permanent slips
                    'balanced_drawn'            => 0, // Sum of fuel drawn from Balanced slips (subtracts from balance)
                    'remaining_balance'         => 0, // permanent_balance - balanced_drawn
                    'overdraw_amount'           => 0,
                    'permanent_charge'          => 0, // Amount to collect on Permanent slips
                    'temporary_charge'          => 0, // Total value of all Temporary slips
                    'temporary_charge_pending'  => 0, // Unpaid loan fuel -> MUST COLLECT
                    'temporary_charge_returned' => 0, // Paid / returned loan fuel -> ALREADY RECEIVED
                    'total_to_collect'          => 0  // permanent_charge + temporary_charge_pending
                ];
            }

            $st      = $row['slip_type'] ?: 'Permanent Slip';
            $rate    = floatval($row['rate']);
            $baseQty = floatval($row['quantity']);
            $issueQty= floatval($row['issue_quantity']);
            $wasoli  = floatval($row['wasoli']);
            $bal     = floatval($row['balance_1']) + floatval($row['balance_2']);
            $nomAmt  = floatval($row['amount']);
            $isReturned = intval($row['is_returned'] ?? 0);

            if ($st === 'Temporary Slip') {
                // Temporary slip represents loan fuel under Tmp. Receive
                $tempQty = $wasoli > 0 ? $wasoli : ($baseQty > 0 ? $baseQty : $issueQty);
                $tempCharge = $tempQty * $rate;

                $customers_ledger[$accNo]['temporary_fuel']   += $tempQty;
                $customers_ledger[$accNo]['temporary_charge'] += $tempCharge;
                $customers_ledger[$accNo]['total_fuel']       += $tempQty;

                if ($isReturned === 1) {
                    // Customer returned / paid for this temporary slip -> ALREADY RECEIVED
                    $customers_ledger[$accNo]['temporary_fuel_returned']   += $tempQty;
                    $customers_ledger[$accNo]['temporary_charge_returned'] += $tempCharge;
                    $chgAmt = 0.00; // Paid, does not owe anymore
                } else {
                    // Pending loan petrol -> Customer owes money, we must collect!
                    $customers_ledger[$accNo]['temporary_fuel_pending']   += $tempQty;
                    $customers_ledger[$accNo]['temporary_charge_pending'] += $tempCharge;
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
    }

    // Calculate remaining balance per customer and grand totals
    foreach ($customers_ledger as $cId => &$cItem) {
        $cItem['remaining_balance'] = max(0, $cItem['permanent_balance'] - $cItem['balanced_drawn']);
        $cItem['overdraw_amount']   = max(0, $cItem['balanced_drawn'] - $cItem['permanent_balance']);
        
        $grand_total_fuel      += $cItem['total_fuel'];
        $grand_permanent_fuel  += $cItem['permanent_fuel'];
        $grand_balanced_fuel   += $cItem['balanced_fuel'];
        $grand_temporary_fuel  += $cItem['temporary_fuel'];
        $grand_permanent_bal   += $cItem['permanent_balance'];
        $grand_balanced_drawn  += $cItem['balanced_drawn'];
        $grand_remaining_bal   += $cItem['remaining_balance'];
        $grand_perm_collect    += $cItem['permanent_charge'];
        $grand_temp_collect    += $cItem['temporary_charge_pending'];
        $grand_total_collect   += $cItem['total_to_collect'];
    }
    unset($cItem);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Customer Credit & Fuel Ledger Report</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }

        .customer-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            margin-bottom: 26px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .customer-header {
            background: linear-gradient(135deg, #04204e 0%, #07347a 100%);
            color: #fff;
            padding: 14px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .customer-title {
            font-size: 16px;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .customer-meta {
            font-size: 12.5px;
            opacity: 0.9;
        }

        .ledger-table thead th {
            background: #f1f5f9;
            color: #04204e;
            font-size: 11.5px;
            font-weight: 800;
            text-align: center;
            vertical-align: middle;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }

        .ledger-table td {
            font-size: 12px;
            vertical-align: middle;
        }

        .summary-ribbon {
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
            padding: 14px 22px;
        }

        .summary-stat-box {
            background: #fff;
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            text-align: center;
            height: 100%;
        }

        .summary-stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 3px;
        }

        .summary-stat-value {
            font-size: 16px;
            font-weight: 900;
            line-height: 1.2;
        }

        .grand-summary-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(4,32,78,0.12);
            border: 2px solid #04204e;
            padding: 20px 24px;
            margin-top: 20px;
            margin-bottom: 40px;
        }

        .slip-badge-perm { background-color: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; font-weight: 700; }
        .slip-badge-bal  { background-color: #e0f7fa; color: #006064; border: 1px solid #b2ebf2; font-weight: 700; }
        .slip-badge-temp { background-color: #fff8e1; color: #b07800; border: 1px solid #ffe082; font-weight: 700; }

        @media print {
            .d-print-none, .main-navbar { display: none !important; }
            body { background: #fff !important; color: #000 !important; }
            .container-fluid { padding: 0 !important; }
            .customer-card { box-shadow: none !important; border: 1px solid #ccc !important; page-break-inside: avoid; }
            .customer-header { background: #eee !important; color: #000 !important; }
            .print-header { display: block !important; margin-bottom: 15px; }
            .grand-summary-card { box-shadow: none !important; border: 1px solid #000 !important; }
        }
        .print-header { display: none; }
    </style>
</head>
<body>

    <?php require_once '../include/navbar.php'; ?>

    <main class="main">
        <div class="container-fluid pt-4 pb-4 px-lg-5">

            <!-- Print Header -->
            <div class="print-header text-center">
                <h3 class="font-weight-bold mb-1" style="color:#04204e;">PETROL PUMP MANAGEMENT SYSTEM</h3>
                <h5 class="font-weight-bold mb-1">All Customers - Credit &amp; Fuel Ledger Report</h5>
                <p class="text-muted small mb-2">Generated On: <strong><?php echo date('d-m-Y H:i A'); ?></strong></p>
                <hr style="border-top:2px solid #04204e;">
            </div>

            <!-- Page Title & Actions -->
            <div class="row mb-3 align-items-center d-print-none">
                <div class="col-md-7">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-file-invoice-dollar mr-2 text-primary"></i>Customer Credit &amp; Fuel Ledger Report
                    </h4>
                    <p class="text-muted small mb-0">Complete itemized slip ledger: tracks total fuel taken, remaining balances after balanced slips, and amounts to collect.</p>
                </div>
                <div class="col-md-5 text-right">
                    <?php if ($isSearched && !empty($customers_ledger)): ?>
                    <a href="generate-pdf-customer-report.php?customer_id=<?php echo urlencode($customerId); ?>&vehicle_number=<?php echo urlencode($vehicleNum); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
                        <i class="fas fa-file-pdf mr-1"></i> Export PDF
                    </a>
                    <?php endif; ?>
                    <button class="btn btn-outline-secondary font-weight-bold mr-2" onclick="window.print();">
                        <i class="fas fa-print mr-1"></i> Print Report
                    </button>
                    <a href="customer-report.php" class="btn btn-outline-primary font-weight-bold">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- Two Filters Only: Customer & Vehicle -->
            <div class="card p-3 mb-4 shadow-sm border-0 d-print-none" style="border-radius:10px; background:#fff;">
                <form action="customer-report.php" method="GET" class="form-row align-items-end">
                    <div class="col-md-5 col-sm-6 mb-2 mb-md-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-user mr-1 text-primary"></i> Customer</label>
                        <select name="customer_id" class="form-control form-control-sm font-weight-bold">
                            <option value="">-- All Customers --</option>
                            <?php foreach ($all_customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>" <?php echo ($customerId == $cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['name']) . ' (' . htmlspecialchars($cust['fuel_rate'] ?: 'Credit') . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-truck mr-1 text-primary"></i> Vehicle No</label>
                        <input type="text" name="vehicle_number" class="form-control form-control-sm font-weight-bold" placeholder="e.g. LE-1234" value="<?php echo htmlspecialchars($vehicleNum); ?>">
                    </div>
                    <div class="col-md-3 col-sm-12">
                        <div class="btn-group btn-block">
                            <button type="submit" class="btn btn-primary btn-sm font-weight-bold shadow-sm">
                                <i class="fas fa-search mr-1"></i> Search
                            </button>
                            <a href="customer-report.php" class="btn btn-outline-danger btn-sm font-weight-bold">
                                <i class="fas fa-sync-alt mr-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!$isSearched): ?>
                <div class="card p-5 text-center shadow-sm border-0 d-print-none" style="border-radius:12px; background:#fff;">
                    <i class="fas fa-search text-muted mb-3" style="font-size: 48px; opacity:0.4;"></i>
                    <h5 class="font-weight-bold" style="color:#04204e;">Search by Customer or Vehicle to View Report</h5>
                    <p class="text-muted mb-0">Please select a customer from the dropdown or enter a vehicle number above, then click <strong>Search</strong> to load the ledger.</p>
                </div>
            <?php elseif (empty($customers_ledger)): ?>
                <div class="card p-5 text-center shadow-sm border-0 d-print-none" style="border-radius:12px; background:#fff;">
                    <i class="fas fa-receipt text-muted mb-3" style="font-size: 48px; opacity:0.4;"></i>
                    <h5 class="font-weight-bold text-muted">No Credit Sales Slips Found</h5>
                    <p class="text-muted mb-0">No credit sales records match the selected customer or vehicle number.</p>
                </div>
            <?php else: ?>

                <!-- Loop through each customer -->
                <?php foreach ($customers_ledger as $cId => $cdata): ?>
                    <div class="customer-card">
                        <!-- Customer Header Banner -->
                        <div class="customer-header">
                            <div>
                                <h5 class="customer-title">
                                    <i class="fas fa-user-circle mr-2"></i><?php echo htmlspecialchars($cdata['customer_name']); ?>
                                </h5>
                                <div class="customer-meta mt-1">
                                    <span class="mr-3"><i class="fas fa-id-badge mr-1"></i>Account #: <strong><?php echo htmlspecialchars($cdata['cust_id']); ?></strong></span>
                                    <span class="mr-3"><i class="fas fa-phone mr-1"></i>Contact: <strong><?php echo htmlspecialchars($cdata['customer_phone']); ?></strong></span>
                                    <span><i class="fas fa-tags mr-1"></i>Rate Tier: <strong><?php echo htmlspecialchars($cdata['rate_tier']); ?></strong></span>
                                </div>
                            </div>
                            <div class="text-right mt-2 mt-md-0">
                                <span class="badge badge-light px-3 py-1 font-weight-bold" style="font-size:12px; color:#04204e;">
                                    <?php echo count($cdata['slips']); ?> Slip(s) Recorded
                                </span>
                                <?php if (!empty($cdata['vehicles'])): ?>
                                    <div class="mt-1">
                                        <?php foreach ($cdata['vehicles'] as $v): ?>
                                            <span class="badge badge-dark px-2 py-0.5 font-weight-bold text-monospace" style="font-size:10.5px;"><?php echo htmlspecialchars($v); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Direct Itemized Slips Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover table-sm mb-0 ledger-table">
                                <thead>
                                    <tr>
                                        <th style="width: 35px;">#</th>
                                        <th style="width: 90px;">Slip Date</th>
                                        <th style="width: 80px;">Reading #</th>
                                        <th style="width: 100px;">Slip No</th>
                                        <th style="width: 130px;">Slip Type</th>
                                        <th style="width: 110px;">Vehicle No</th>
                                        <th>Nozzle / Fuel</th>
                                        <th style="width: 80px;" class="text-right">Rate</th>
                                        <th style="width: 100px;" class="text-right text-primary">QTY (Ltr)</th>
                                        <th style="width: 90px;" class="text-right">Balance (Ltr)</th>
                                        <th style="width: 100px;" class="text-right text-warning">Tmp. Receive</th>
                                        <th style="width: 120px;" class="text-right bg-light text-danger">Must Pay (Rs.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sn = 1;
                                    foreach ($cdata['slips'] as $slip): 
                                        $st = $slip['slip_type'];
                                        $badgeClass = 'slip-badge-perm';
                                        if ($st === 'Balanced Slip') {
                                            $badgeClass = 'slip-badge-bal';
                                        } elseif ($st === 'Temporary Slip') {
                                            $badgeClass = 'slip-badge-temp';
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-center font-weight-bold text-muted"><?php echo $sn++; ?></td>
                                        <td class="text-center"><?php echo date('d-m-Y', strtotime($slip['slip_date'])); ?></td>
                                        <td class="text-center">
                                            <a href="../meter-readings/view-meter-reading.php?id=<?php echo $slip['meter_reading_id']; ?>" target="_blank" class="font-weight-bold text-primary">
                                                #<?php echo $slip['meter_reading_id']; ?>
                                            </a>
                                        </td>
                                        <td class="text-center font-weight-bold"><?php echo htmlspecialchars($slip['slip_no']); ?></td>
                                        <td class="text-center">
                                            <span class="badge px-2 py-1 <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($st); ?>
                                            </span>
                                        </td>
                                        <td class="text-center font-weight-bold text-monospace"><?php echo htmlspecialchars($slip['vehicle_number'] ?: '—'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($slip['nozzle_name'] ?: '—'); ?></strong>
                                            <small class="text-muted">(<?php echo htmlspecialchars($slip['item_name'] ?: 'Fuel'); ?>)</small>
                                        </td>
                                        <td class="text-right"><?php echo number_format($slip['rate'], 2); ?></td>
                                        <td class="text-right font-weight-bold text-primary">
                                            <?php echo number_format($slip['dispensed_qty'], 2); ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($st === 'Permanent Slip' && $slip['slip_balance'] > 0): ?>
                                                 <span class="badge badge-info px-2 py-0.5">+<?php echo number_format($slip['slip_balance'], 2); ?></span>
                                            <?php elseif ($st === 'Balanced Slip'): ?>
                                                <span class="badge badge-secondary px-2 py-0.5 text-muted">-<?php echo number_format($slip['dispensed_qty'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($st === 'Temporary Slip'): ?>
                                                <?php if (!empty($slip['is_returned'])): ?>
                                                    <span class="badge badge-success px-2 py-0.5 text-white font-weight-bold" style="font-size:11px;">
                                                        <i class="fas fa-check-circle mr-1"></i><?php echo number_format($slip['dispensed_qty'], 2); ?> (Received)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning px-2 py-0.5 text-dark font-weight-bold" style="font-size:11px;">
                                                        <i class="fas fa-hand-holding mr-1"></i><?php echo number_format($slip['dispensed_qty'], 2); ?> (Giving Loan)
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($slip['wasoli'] > 0): ?>
                                                <span class="badge badge-warning px-2 py-0.5 text-dark font-weight-bold"><?php echo number_format($slip['wasoli'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo ($st === 'Balanced Slip') ? 'text-muted' : ''; ?>" style="font-size:13px; background-color:#fffdfd;">
                                            <?php if ($st === 'Balanced Slip'): ?>
                                                <span class="badge badge-light border text-muted">Rs. 0.00 (Pre-paid)</span>
                                            <?php elseif ($st === 'Temporary Slip'): ?>
                                                <?php if (!empty($slip['is_returned'])): ?>
                                                    <span class="text-success font-weight-bold">Rs. 0.00</span>
                                                    <small class="text-success d-block font-weight-bold" style="font-size: 10px;">
                                                        <i class="fas fa-check-circle mr-1"></i>(Received / Settled)
                                                    </small>
                                                    <button type="button" class="btn btn-outline-secondary py-0 px-1 mt-1 d-print-none" style="font-size:10px;" onclick="toggleSlipReturn(<?php echo $slip['id']; ?>, 0)" title="Revert to Giving">
                                                        <i class="fas fa-undo mr-1"></i> Undo
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-danger font-weight-bold">Rs. <?php echo number_format($slip['temp_charge'], 2); ?></span>
                                                    <small class="text-danger d-block font-weight-bold" style="font-size: 10px;">(Giving &mdash; To Collect)</small>
                                                    <button type="button" class="btn btn-success py-0 px-2 mt-1 font-weight-bold d-print-none" style="font-size:11px;" onclick="toggleSlipReturn(<?php echo $slip['id']; ?>, 1)">
                                                        <i class="fas fa-check mr-1"></i> Mark Received
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-danger">Rs. <?php echo number_format($slip['effective_charge'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Card 2: Financial Debit / Credit Settlement -->
                        <div class="p-3 bg-light border-top">
                            <div class="card border-0 shadow-sm" style="border-radius:10px; overflow:hidden; border:1px solid #cbd5e1 !important;">
                                <div class="card-header bg-dark text-white py-2 px-3 d-flex justify-content-between align-items-center">
                                    <strong style="font-size: 13px;"><i class="fas fa-balance-scale mr-2 text-warning"></i>Financial Debit / Credit Settlement (What We Must Collect &amp; Petrol to Deliver)</strong>
                                    <span class="badge badge-warning text-dark font-weight-bold">Account #<?php echo htmlspecialchars($cdata['cust_id']); ?></span>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-bordered table-sm mb-0 text-center" style="font-size: 12.5px;">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="text-left pl-3" style="width: 50%;">Transaction Classification</th>
                                                <th class="text-right pr-3" style="width: 25%; color: #b91c1c;">Debit (Receivable from Customer)</th>
                                                <th class="text-right pr-3" style="width: 25%; color: #047857;">Credit (Pre-Paid / Settled)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="text-left pl-3">
                                                    <strong>Permanent Slips (Billed Fuel)</strong>
                                                    <br><small class="text-muted"><?php echo number_format($cdata['permanent_fuel'], 2); ?> Ltr issued across permanent chits</small>
                                                </td>
                                                <td class="text-right pr-3 font-weight-bold text-danger">Rs. <?php echo number_format($cdata['permanent_charge'], 2); ?></td>
                                                <td class="text-right pr-3 text-muted">—</td>
                                            </tr>
                                            <tr>
                                                <td class="text-left pl-3">
                                                    <strong>Balanced Slips (Claimed Fuel)</strong>
                                                    <br><small class="text-muted"><?php echo number_format($cdata['balanced_fuel'], 2); ?> Ltr claimed against previous balance quota</small>
                                                </td>
                                                <td class="text-right pr-3 text-muted">—</td>
                                                <td class="text-right pr-3 font-weight-bold text-success">Rs. 0.00 <span class="badge badge-light border text-muted">Settled</span></td>
                                            </tr>
                                            <tr style="background-color: #fffdf5;">
                                                <td class="text-left pl-3">
                                                    <strong class="text-danger"><i class="fas fa-hand-holding mr-1 text-warning"></i> Giving Tmp. Receive (Loan Petrol Given &mdash; Must Collect)</strong>
                                                    <br><small class="text-danger font-weight-bold"><?php echo number_format($cdata['temporary_fuel_pending'], 2); ?> Ltr loan petrol given &mdash; We need to collect this money</small>
                                                </td>
                                                <td class="text-right pr-3 font-weight-bold text-danger" style="font-size:13.5px;">Rs. <?php echo number_format($cdata['temporary_charge_pending'], 2); ?></td>
                                                <td class="text-right pr-3 text-muted">—</td>
                                            </tr>
                                            <?php if ($cdata['temporary_charge_returned'] > 0): ?>
                                            <tr style="background-color: #f0fdf4;">
                                                <td class="text-left pl-3">
                                                    <strong class="text-success"><i class="fas fa-check-circle mr-1 text-success"></i> Received Tmp. Receive (Loan Petrol Received / Settled)</strong>
                                                    <br><small class="text-muted"><?php echo number_format($cdata['temporary_fuel_returned'], 2); ?> Ltr loan petrol has been received &amp; settled</small>
                                                </td>
                                                <td class="text-right pr-3 text-muted">—</td>
                                                <td class="text-right pr-3 font-weight-bold text-success" style="font-size:13.5px;">
                                                    Rs. <?php echo number_format($cdata['temporary_charge_returned'], 2); ?> <span class="badge badge-success">Received</span>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background-color: #fff5f5;">
                                                <th class="text-left pl-3 text-danger font-weight-bold" style="font-size: 13px;">👉 TOTAL AMOUNT WE NEED TO GET (MUST COLLECT):</th>
                                                <th colspan="2" class="text-right pr-3 text-danger font-weight-bold" style="font-size: 16px;">Rs. <?php echo number_format($cdata['total_to_collect'], 2); ?></th>
                                            </tr>
                                            <tr style="background-color: #f0fdf4;">
                                                <th class="text-left pl-3 text-success font-weight-bold" style="font-size: 13px;">
                                                    ⛽ PETROL VOLUME WE MUST GIVE CUSTOMER:
                                                    <small class="text-muted font-weight-normal d-block">
                                                        (Total Quota Recorded: +<?php echo number_format($cdata['permanent_balance'], 2); ?> Ltr &nbsp;|&nbsp; Claimed on Balanced Slips: -<?php echo number_format($cdata['balanced_drawn'], 2); ?> Ltr)
                                                    </small>
                                                </th>
                                                <th colspan="2" class="text-right pr-3 text-success font-weight-bold" style="font-size: 16px;">
                                                    <?php if ($cdata['remaining_balance'] > 0): ?>
                                                        <span class="badge badge-success px-3 py-1" style="font-size: 13.5px;">
                                                            <i class="fas fa-gas-pump mr-1"></i> <?php echo number_format($cdata['remaining_balance'], 2); ?> Ltr (Pending Return)
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary px-3 py-1" style="font-size: 12px;">
                                                            <i class="fas fa-check-circle mr-1"></i> 0.00 Ltr (All Quota Delivered)
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($cdata['overdraw_amount'] > 0): ?>
                                                        <div class="mt-1">
                                                            <span class="badge badge-warning text-dark px-2 py-1" style="font-size: 11px;">
                                                                <i class="fas fa-exclamation-triangle mr-1"></i> Quota Over-drawn by <?php echo number_format($cdata['overdraw_amount'], 2); ?> Ltr
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>

                <!-- Grand Summary Across All Customers -->
                <div class="grand-summary-card">
                    <div class="row align-items-center">
                        <div class="col-lg-3 col-md-12 mb-3 mb-lg-0">
                            <h5 class="font-weight-bold mb-1" style="color:#04204e;">
                                <i class="fas fa-layer-group mr-2"></i>Grand System Totals
                            </h5>
                            <p class="text-muted small mb-0">Aggregated totals across all <?php echo count($customers_ledger); ?> customer credit accounts.</p>
                        </div>
                        <div class="col-lg-9 col-md-12">
                            <div class="row text-center">
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-muted font-weight-bold text-uppercase">Total Fuel Dispensed</div>
                                    <div class="h4 font-weight-bold text-primary mb-0"><?php echo number_format($grand_total_fuel, 2); ?> <small>Ltr</small></div>
                                    <small class="text-muted">Perm: <?php echo number_format($grand_permanent_fuel, 1); ?> | Bal: <?php echo number_format($grand_balanced_fuel, 1); ?></small>
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-muted font-weight-bold text-uppercase">Total Balance Left</div>
                                    <div class="h4 font-weight-bold text-info mb-0"><?php echo number_format($grand_remaining_bal, 2); ?> <small>Ltr</small></div>
                                    <small class="text-muted">(<?php echo number_format($grand_permanent_bal, 1); ?> - <?php echo number_format($grand_balanced_drawn, 1); ?> Ltr)</small>
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-muted font-weight-bold text-uppercase">Under Tmp. Receive</div>
                                    <div class="h4 font-weight-bold text-warning mb-0" style="color:#b07800 !important;">Rs. <?php echo number_format($grand_temp_collect, 2); ?></div>
                                    <small class="text-muted"><?php echo number_format($grand_temporary_fuel, 1); ?> Ltr pending</small>
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-danger font-weight-bold text-uppercase">Total Amount To Collect</div>
                                    <div class="h3 font-weight-bold text-danger mb-0">Rs. <?php echo number_format($grand_total_collect, 2); ?></div>
                                    <small class="text-muted">Perm: Rs. <?php echo number_format($grand_perm_collect, 0); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
    function toggleSlipReturn(slipId, status) {
        var actionText = status === 1 
            ? 'mark this Temporary Slip as RETURNED / RECEIVED (fuel/money collected)?' 
            : 'revert this slip back to PENDING (unpaid loan)?';
        if (confirm('Are you sure you want to ' + actionText)) {
            $.ajax({
                type: "POST",
                url: "../include/returntemporaryslip.php",
                data: { slip_id: slipId, status: status },
                dataType: "json",
                success: function(resp) {
                    if (resp.status === 'success') {
                        window.location.reload();
                    } else {
                        alert(resp.message || 'Error occurred while updating slip.');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Server error: ' + error);
                }
            });
        }
    }
    </script>
</body>
</html>
