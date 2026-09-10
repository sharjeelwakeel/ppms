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

// Ensure auxiliary columns exist (self-healing migration)
$chk_aux = [
    'charge_amount'      => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount",
    'is_returned'        => "TINYINT(1) NOT NULL DEFAULT 0 AFTER wasoli",
    'returned_at'        => "DATETIME DEFAULT NULL AFTER is_returned",
    'settled_in_slip_id' => "INT(11) DEFAULT NULL AFTER returned_at",
    'temp_slip_id'       => "INT(11) DEFAULT NULL AFTER settled_in_slip_id",
    'temp_slip_no'       => "VARCHAR(64) DEFAULT NULL AFTER temp_slip_id",
    'temp_rate'          => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER temp_slip_no",
    'ref_slip_no'        => "VARCHAR(128) DEFAULT NULL AFTER temp_rate"
];
foreach ($chk_aux as $col => $def) {
    $q = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE '$col'");
    if ($q && mysqli_num_rows($q) == 0) {
        mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN $col $def");
    }
}

// Filters: Customer, Vehicle No, From Date, To Date, Shift
$customerId = intval($_GET['customer_id'] ?? 0);
$vehicleNum = trim($_GET['vehicle_number'] ?? '');
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');
$shiftId    = intval($_GET['shift_id'] ?? 0);

$isSearched = (isset($_GET['customer_id']) || isset($_GET['vehicle_number']) || isset($_GET['from_date']) || isset($_GET['to_date']) || isset($_GET['shift_id'])) && 
              ($customerId > 0 || !empty($vehicleNum) || !empty($fromDate) || !empty($toDate) || $shiftId > 0);

// Fetch all active customers for filter dropdown
$customers_res = mysqli_query($connection, "SELECT id, name, phone, fuel_rate FROM tbl_customers WHERE deleted_at IS NULL ORDER BY name ASC");
$all_customers = [];
if ($customers_res) {
    while ($crow = mysqli_fetch_assoc($customers_res)) {
        $all_customers[] = $crow;
    }
}

// Fetch all active shifts for filter dropdown
$shifts_res = mysqli_query($connection, "SELECT id, name FROM tbl_shifts WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
$all_shifts = [];
$selected_shift_name = '';
if ($shifts_res) {
    while ($srow = mysqli_fetch_assoc($shifts_res)) {
        $all_shifts[] = $srow;
        if ($shiftId == $srow['id']) {
            $selected_shift_name = $srow['name'];
        }
    }
}

// Group transactions by customer
$customers_ledger = [];

// Grand totals across all customers
$grand_total_fuel     = 0;
$grand_permanent_fuel = 0;
$grand_balanced_fuel  = 0;
$grand_temporary_fuel = 0;
$grand_permanent_bal  = 0;
$grand_balanced_drawn = 0;
$grand_remaining_bal  = 0;
$grand_perm_collect   = 0;
$grand_temp_collect   = 0;
$grand_total_collect  = 0;

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
    if (!empty($fromDate) && !empty($toDate)) {
        $from_safe = mysqli_real_escape_string($connection, $fromDate);
        $to_safe   = mysqli_real_escape_string($connection, $toDate);
        $where_clauses[] = "mrcs.slip_date BETWEEN '$from_safe' AND '$to_safe'";
    } elseif (!empty($fromDate)) {
        $from_safe = mysqli_real_escape_string($connection, $fromDate);
        $where_clauses[] = "mrcs.slip_date >= '$from_safe'";
    } elseif (!empty($toDate)) {
        $to_safe = mysqli_real_escape_string($connection, $toDate);
        $where_clauses[] = "mrcs.slip_date <= '$to_safe'";
    }
    if ($shiftId > 0) {
        $where_clauses[] = "mrcs.shift_id = '$shiftId'";
    }

    $where_sql = !empty($where_clauses) ? implode(' AND ', $where_clauses) : '1=1';

    // Fetch credit sales records with joined settling slip information
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
                    'total_fuel'                => 0, // All physical litres pumped into vehicles
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
    }

    // Calculate remaining quota balance per customer and grand totals
    foreach ($customers_ledger as $cId => &$cItem) {
        $cItem['remaining_balance'] = max(0, round($cItem['permanent_balance'] - $cItem['balanced_quota_settled'], 2));
        $cItem['overdraw_amount']   = max(0, round($cItem['balanced_quota_settled'] - $cItem['permanent_balance'], 2));

        $grand_total_fuel     += $cItem['total_fuel'];
        $grand_permanent_fuel += $cItem['permanent_fuel'];
        $grand_balanced_fuel  += $cItem['balanced_fuel'];
        $grand_temporary_fuel += $cItem['temporary_fuel'];
        $grand_permanent_bal  += $cItem['permanent_balance'];
        $grand_balanced_drawn += $cItem['balanced_drawn'];
        $grand_remaining_bal  += $cItem['remaining_balance'];
        $grand_perm_collect   += $cItem['permanent_charge'];
        $grand_temp_collect   += $cItem['temporary_charge_pending'];
        $grand_total_collect  += $cItem['total_to_collect'];
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
                <h5 class="font-weight-bold mb-1">Customer Credit &amp; Fuel Ledger Report</h5>
                <p class="text-muted small mb-2">
                    Generated On: <strong><?php echo date('d-m-Y H:i A'); ?></strong>
                    <?php if (!empty($fromDate) && !empty($toDate)): ?>
                        &nbsp;|&nbsp; Filter Period: <strong><?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?></strong>
                    <?php elseif (!empty($fromDate)): ?>
                        &nbsp;|&nbsp; From Date: <strong><?php echo date('d-m-Y', strtotime($fromDate)); ?></strong>
                    <?php elseif (!empty($toDate)): ?>
                        &nbsp;|&nbsp; Till Date: <strong><?php echo date('d-m-Y', strtotime($toDate)); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($selected_shift_name)): ?>
                        &nbsp;|&nbsp; Shift: <strong><?php echo htmlspecialchars($selected_shift_name); ?></strong>
                    <?php endif; ?>
                </p>
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
                    <a href="generate-pdf-customer-report.php?customer_id=<?php echo urlencode($customerId); ?>&vehicle_number=<?php echo urlencode($vehicleNum); ?>&from_date=<?php echo urlencode($fromDate); ?>&to_date=<?php echo urlencode($toDate); ?>&shift_id=<?php echo urlencode($shiftId); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
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

            <!-- Filter Card: Date Range, Shift, Customer & Vehicle -->
            <div class="card p-3 mb-4 shadow-sm border-0 d-print-none" style="border-radius:10px; background:#fff;">
                <form action="customer-report.php" method="GET" class="form-row align-items-end">
                    <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-alt mr-1 text-primary"></i> From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($fromDate); ?>">
                    </div>
                    <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-check mr-1 text-primary"></i> To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold" value="<?php echo htmlspecialchars($toDate); ?>">
                    </div>
                    <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 mb-2 mb-lg-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-clock mr-1 text-primary"></i> Shift</label>
                        <select name="shift_id" class="form-control form-control-sm font-weight-bold">
                            <option value="">-- All Shifts --</option>
                            <?php foreach ($all_shifts as $sh): ?>
                                <option value="<?php echo $sh['id']; ?>" <?php echo ($shiftId == $sh['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sh['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-2 mb-lg-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-user mr-1 text-primary"></i> Customer</label>
                        <select name="customer_id" class="form-control form-control-sm font-weight-bold">
                            <option value="">-- All Customers --</option>
                            <?php foreach ($all_customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>" <?php echo ($customerId == $cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-2 col-md-6 col-sm-6 mb-2 mb-lg-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-truck mr-1 text-primary"></i> Vehicle No</label>
                        <input type="text" name="vehicle_number" class="form-control form-control-sm font-weight-bold text-monospace" placeholder="e.g. LE-1234" value="<?php echo htmlspecialchars($vehicleNum); ?>">
                    </div>
                    <div class="col-xl-2 col-lg-2 col-md-12 col-sm-12">
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
                    <h5 class="font-weight-bold" style="color:#04204e;">Search by Customer, Vehicle, or Date Range to View Report</h5>
                    <p class="text-muted mb-0">Select a customer, vehicle number, or date range above, then click <strong>Search</strong> to load the ledger.</p>
                </div>
            <?php elseif (empty($customers_ledger)): ?>
                <div class="card p-5 text-center shadow-sm border-0 d-print-none" style="border-radius:12px; background:#fff;">
                    <i class="fas fa-receipt text-muted mb-3" style="font-size: 48px; opacity:0.4;"></i>
                    <h5 class="font-weight-bold text-muted">No Credit Sales Slips Found</h5>
                    <p class="text-muted mb-0">No credit sales records match the selected customer, vehicle, or date parameters.</p>
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
                                        <th style="width: 95px;">Slip Date</th>
                                        <th style="width: 100px;">Slip No</th>
                                        <th style="width: 110px;">Slip Type</th>
                                        <th style="width: 100px;">Vehicle No</th>
                                        <th>Nozzle / Fuel</th>
                                        <th style="width: 75px;" class="text-right">Rate</th>
                                        <th style="width: 85px;" class="text-right">Issued (Ltr)</th>
                                        <th style="width: 85px;" class="text-right text-primary">Pumped (Ltr)</th>
                                        <th style="width: 95px;" class="text-right">Balance Quota</th>
                                        <th style="width: 155px;" class="text-left text-warning">Temp. Receive</th>
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
                                        $issVal = floatval($slip['issue_quantity']);
                                        $dispVal = floatval($slip['dispensed_qty']);
                                        $displayIssue = ($issVal > 0) ? $issVal : $dispVal;
                                    ?>
                                    <tr>
                                        <td class="text-center font-weight-bold text-muted"><?php echo $sn++; ?></td>
                                        <td class="text-center"><?php echo date('d-m-Y', strtotime($slip['slip_date'])); ?></td>
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
                                        <td class="text-right font-weight-bold text-muted">
                                            <?php echo number_format($displayIssue, 2); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-primary">
                                            <?php echo number_format($dispVal, 2); ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($st === 'Permanent Slip' && $slip['slip_balance'] > 0): ?>
                                                 <span class="badge badge-info px-2 py-0.5 font-weight-bold" title="Uncollected balance credited to customer">+<?php echo number_format($slip['slip_balance'], 2); ?> Ltr</span>
                                            <?php elseif ($st === 'Balanced Slip'): ?>
                                                <?php 
                                                $quotaSettled = !empty($slip['orig_quota_settled']) ? floatval($slip['orig_quota_settled']) : $dispVal;
                                                $pFluct = isset($slip['price_fluctuation_ltr']) ? floatval($slip['price_fluctuation_ltr']) : 0;
                                                ?>
                                                <span class="badge badge-secondary px-2 py-0.5 text-white font-weight-bold" title="Voucher Quota Settled">-<?php echo number_format($quotaSettled, 2); ?> Ltr</span>
                                                <?php if (!empty($slip['ref_slip_no'])): ?>
                                                    <small class="text-muted d-block text-monospace" style="font-size:9.5px;">(from #<?php echo htmlspecialchars($slip['ref_slip_no']); ?>)</small>
                                                <?php endif; ?>
                                                <?php if ($pFluct > 0.001): ?>
                                                    <span class="badge badge-warning text-dark font-weight-bold mt-1 d-inline-block" style="font-size:9px;" title="Rate increased: customer received <?php echo number_format($dispVal, 2); ?>L for <?php echo number_format($quotaSettled, 2); ?>L prepaid quota">
                                                        <i class="fas fa-chart-line mr-1"></i>Price Absorption: -<?php echo number_format($pFluct, 2); ?> Ltr
                                                    </span>
                                                <?php elseif ($pFluct < -0.001): ?>
                                                    <span class="badge badge-info text-white font-weight-bold mt-1 d-inline-block" style="font-size:9px;" title="Rate decreased: customer received <?php echo number_format($dispVal, 2); ?>L for <?php echo number_format($quotaSettled, 2); ?>L prepaid quota">
                                                        <i class="fas fa-chart-line mr-1"></i>Price Drop Gain: +<?php echo number_format(abs($pFluct), 2); ?> Ltr
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-left">
                                            <?php if ($st === 'Permanent Slip'): ?>
                                                <?php if (!empty($slip['wasoli']) && floatval($slip['wasoli']) > 0): ?>
                                                    <span class="badge badge-success px-2 py-0.5 text-white font-weight-bold" style="font-size:10.5px;" title="Settled Loan Chit">
                                                        <i class="fas fa-check-circle mr-1"></i>Settling #<?php echo htmlspecialchars($slip['temp_slip_no'] ?: 'Temp'); ?> (<?php echo number_format($slip['wasoli'], 0); ?>L @ Rs. <?php echo number_format($slip['temp_rate'], 0); ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            <?php elseif ($st === 'Temporary Slip'): ?>
                                                <?php if (!empty($slip['is_returned'])): ?>
                                                    <span class="badge badge-success px-2 py-0.5 text-white font-weight-bold" style="font-size:10.5px;">
                                                        <i class="fas fa-check-circle mr-1"></i>Settled<?php echo !empty($slip['settling_slip_no']) ? ' (in #' . htmlspecialchars($slip['settling_slip_no']) . ')' : ''; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning px-2 py-0.5 text-dark font-weight-bold" style="font-size:10.5px;">
                                                        <i class="fas fa-clock mr-1"></i>Loan Chit (Open)
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo ($st === 'Balanced Slip') ? 'text-muted' : ''; ?>" style="font-size:13px; background-color:#fffdfd;">
                                            <?php if ($st === 'Balanced Slip'): ?>
                                                <span class="badge badge-light border text-muted">Rs. 0.00 (Pre-paid)</span>
                                            <?php elseif ($st === 'Temporary Slip'): ?>
                                                <?php if (!empty($slip['is_returned'])): ?>
                                                    <span class="text-success font-weight-bold">Rs. 0.00</span>
                                                    <small class="text-muted d-block font-weight-bold" style="font-size: 10px;">
                                                        <i class="fas fa-check-circle mr-1"></i>(Billed in #<?php echo htmlspecialchars($slip['settling_slip_no'] ?: 'Perm'); ?>)
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted font-weight-bold">Rs. 0.00</span>
                                                    <small class="text-warning d-block font-weight-bold" style="font-size: 10px; color:#b07800 !important;">
                                                        (Loan Chit &mdash; Pending Voucher)
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-danger font-weight-bold">Rs. <?php echo number_format($slip['effective_charge'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Card 2: Financial Debit / Credit Settlement & Petrol Quota Reconciliation -->
                        <div class="p-3 bg-light border-top">
                            <div class="row">
                                <!-- Panel A: Financial Statement (Money Receivable) -->
                                <div class="col-lg-6 mb-3 mb-lg-0">
                                    <div class="card border-0 shadow-sm h-100" style="border-radius:10px; overflow:hidden; border:1px solid #cbd5e1 !important;">
                                        <div class="card-header bg-dark text-white py-2 px-3 d-flex justify-content-between align-items-center">
                                            <strong style="font-size: 13px;"><i class="fas fa-file-invoice-dollar mr-2 text-warning"></i>1. Financial Statement (Money Receivable)</strong>
                                            <span class="badge badge-warning text-dark font-weight-bold">Account #<?php echo htmlspecialchars($cdata['cust_id']); ?></span>
                                        </div>
                                        <div class="card-body p-0">
                                            <table class="table table-bordered table-sm mb-0" style="font-size: 12.5px;">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-left pl-3" style="width:60%;">Transaction Classification</th>
                                                        <th class="text-right pr-3" style="width:40%; color:#b91c1c;">Invoiced Receivable</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Permanent Slips (Billed Fuel &amp; Settled Loans)</strong>
                                                            <br><small class="text-muted"><?php echo number_format($cdata['permanent_fuel'], 2); ?> Ltr pumped across permanent vouchers</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-danger">Rs. <?php echo number_format($cdata['permanent_charge'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Balanced Slips (Claimed Fuel Quota)</strong>
                                                            <br><small class="text-muted"><?php echo number_format($cdata['balanced_fuel'], 2); ?> Ltr drawn against prepaid quota</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-success">Rs. 0.00 <span class="badge badge-light border text-muted">Pre-paid</span></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Settled Temporary Slips</strong>
                                                            <br><small class="text-muted"><?php echo number_format($cdata['temporary_fuel_returned'], 2); ?> Ltr loan petrol billed on permanent vouchers</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-success">Rs. 0.00 <span class="badge badge-success">Billed in Permanent</span></td>
                                                    </tr>
                                                </tbody>
                                                <tfoot>
                                                    <tr style="background-color: #fff5f5;">
                                                        <th class="text-left pl-3 text-danger font-weight-bold" style="font-size: 13px;">👉 TOTAL INVOICED RECEIVABLE (MUST COLLECT):</th>
                                                        <th class="text-right pr-3 text-danger font-weight-bold" style="font-size: 16px;">Rs. <?php echo number_format($cdata['total_to_collect'], 2); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                            <?php if ($cdata['temporary_charge_pending'] > 0): ?>
                                            <div class="p-2 bg-warning text-dark font-weight-bold small border-top" style="font-size:11px;">
                                                <i class="fas fa-exclamation-circle mr-1 text-danger"></i> <strong>Open Loan Alert:</strong> Customer holds <?php echo number_format($cdata['temporary_fuel_pending'], 2); ?> Ltr on open loan chits (Est. Rs. <?php echo number_format($cdata['temporary_charge_pending'], 2); ?>) awaiting permanent voucher submission.
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Panel B: Petrol Quota Reconciliation (Physical Litres) -->
                                <div class="col-lg-6">
                                    <div class="card border-0 shadow-sm h-100" style="border-radius:10px; overflow:hidden; border:1px solid #cbd5e1 !important;">
                                        <div class="card-header bg-dark text-white py-2 px-3 d-flex justify-content-between align-items-center">
                                            <strong style="font-size: 13px;"><i class="fas fa-gas-pump mr-2 text-info"></i>2. Fuel Quota Statement (Physical Petrol Owed)</strong>
                                            <span class="badge badge-info font-weight-bold">Volume Ledger</span>
                                        </div>
                                        <div class="card-body p-0">
                                            <table class="table table-bordered table-sm mb-0" style="font-size: 12.5px;">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-left pl-3" style="width:60%;">Quota Movement Description</th>
                                                        <th class="text-right pr-3" style="width:40%; color:#047857;">Fuel Volume</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Total Quota Created (Permanent Slips)</strong>
                                                            <br><small class="text-muted">Uncollected voucher litres (Issue Qty &gt; Pumped Qty)</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-primary">+<?php echo number_format($cdata['permanent_balance'], 2); ?> Ltr</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Total Quota Settled (Balanced Slips)</strong>
                                                            <br><small class="text-muted">Prepaid voucher quota redeemed and closed</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-secondary">-<?php echo number_format($cdata['balanced_quota_settled'], 2); ?> Ltr</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Price Fluctuation Impact (Separate Item)</strong>
                                                            <br><small class="text-muted">
                                                                <?php if ($cdata['price_fluctuation_litres'] > 0.001): ?>
                                                                    Litres absorbed due to fuel price increase
                                                                <?php elseif ($cdata['price_fluctuation_litres'] < -0.001): ?>
                                                                    Extra litres gained due to fuel price decrease
                                                                <?php else: ?>
                                                                    Zero price fluctuation impact (prices unchanged)
                                                                <?php endif; ?>
                                                            </small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold <?php echo ($cdata['price_fluctuation_litres'] > 0.001) ? 'text-warning' : (($cdata['price_fluctuation_litres'] < -0.001) ? 'text-info' : 'text-muted'); ?>">
                                                            <?php if ($cdata['price_fluctuation_litres'] > 0.001): ?>
                                                                -<?php echo number_format($cdata['price_fluctuation_litres'], 2); ?> Ltr <small class="text-muted">(Price Escalation)</small>
                                                            <?php elseif ($cdata['price_fluctuation_litres'] < -0.001): ?>
                                                                +<?php echo number_format(abs($cdata['price_fluctuation_litres']), 2); ?> Ltr <small class="text-muted">(Price Drop Gain)</small>
                                                            <?php else: ?>
                                                                0.00 Ltr
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-left pl-3">
                                                            <strong>Total Physical Petrol Pumped</strong>
                                                            <br><small class="text-muted">Direct permanent + balanced delivered + temporary loan</small>
                                                        </td>
                                                        <td class="text-right pr-3 font-weight-bold text-dark"><?php echo number_format($cdata['total_fuel'], 2); ?> Ltr</td>
                                                    </tr>
                                                </tbody>
                                                <tfoot>
                                                    <tr style="background-color: #f0fdf4;">
                                                        <th class="text-left pl-3 text-success font-weight-bold" style="font-size: 13px;">⛽ PETROL VOLUME WE MUST GIVE CUSTOMER:</th>
                                                        <th class="text-right pr-3 text-success font-weight-bold" style="font-size: 16px;">
                                                            <?php if ($cdata['remaining_balance'] > 0): ?>
                                                                <span class="badge badge-success px-3 py-1" style="font-size: 13.5px;">
                                                                    <i class="fas fa-gas-pump mr-1"></i> <?php echo number_format($cdata['remaining_balance'], 2); ?> Ltr
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge badge-secondary px-3 py-1" style="font-size: 12px;">
                                                                    <i class="fas fa-check-circle mr-1"></i> 0.00 Ltr (All Quota Delivered)
                                                                </span>
                                                            <?php endif; ?>
                                                        </th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                            <?php if ($cdata['overdraw_amount'] > 0): ?>
                                            <div class="p-2 bg-warning text-dark font-weight-bold small border-top" style="font-size:11px;">
                                                <i class="fas fa-exclamation-triangle mr-1 text-danger"></i> <strong>Quota Overdraw:</strong> Customer has drawn <?php echo number_format($cdata['overdraw_amount'], 2); ?> Ltr more than recorded balance quota.
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
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
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-muted font-weight-bold text-uppercase">Total Balance Left</div>
                                    <div class="h4 font-weight-bold text-info mb-0"><?php echo number_format($grand_remaining_bal, 2); ?> <small>Ltr</small></div>
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-muted font-weight-bold text-uppercase">Open Loan Fuel</div>
                                    <div class="h4 font-weight-bold text-warning mb-0" style="color:#b07800 !important;">Rs. <?php echo number_format($grand_temp_collect, 2); ?></div>
                                </div>
                                <div class="col-md-3 col-6 mb-2 mb-md-0">
                                    <div class="small text-danger font-weight-bold text-uppercase">Total Invoiced To Collect</div>
                                    <div class="h3 font-weight-bold text-danger mb-0">Rs. <?php echo number_format($grand_total_collect, 2); ?></div>
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
</body>
</html>
