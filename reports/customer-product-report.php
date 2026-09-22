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

if (!has_permission('reports', 'show') && !has_permission('customers', 'show') && !has_permission('items', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

// Filter inputs
$customerId = intval($_GET['customer_id'] ?? 0);
$vehicleNum = trim($_GET['vehicle_number'] ?? '');
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');

$isSearched = isset($_GET['filter']) || ($customerId > 0 || !empty($vehicleNum) || !empty($fromDate) || !empty($toDate));

// Fetch all active customers for filter dropdown
$customers_res = mysqli_query($connection, "SELECT id, name, phone, other_rate FROM tbl_customers WHERE deleted_at IS NULL ORDER BY name ASC");
$all_customers = [];
if ($customers_res) {
    while ($crow = mysqli_fetch_assoc($customers_res)) {
        $all_customers[] = $crow;
    }
}

// Group transactions by customer
$customers_ledger = [];

// Grand totals across all customers
$grand_total_customers    = 0;
$grand_total_vouchers     = 0;
$grand_physical_delivered = 0;
$grand_permanent_units    = 0;
$grand_balanced_units     = 0;
$grand_temporary_units    = 0;
$grand_uncollected_bal    = 0;
$grand_billed_receivable  = 0;
$grand_collected_paid     = 0;
$grand_outstanding_due    = 0;

if ($isSearched) {
    // 1. Build where conditions for invoices
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

    // 2. Pre-calculate claimed balance quantities by reference slip
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

    // 3. Main report query: join invoices, customer, product line items, and settling slips
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
                    'permanent_delivered'       => 0,
                    'balanced_delivered'        => 0,
                    'temporary_delivered'       => 0,
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
                    'temp_slip_id'       => intval($row['temp_slip_id'] ?? 0),
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

            // Determine line physical handover
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

            // Remaining net uncollected balance for this line
            $lineNetBal = 0;
            if ($st === 'Permanent Slip' && $lBal > 0) {
                $slipNum = trim($row['slip_no'] ?: $row['invoice_no']);
                $refKey  = $slipNum . '_' . intval($row['product_id']);
                $claimed = $claimed_by_ref[$refKey] ?? 0;
                $lineNetBal = max(0, $lBal - $claimed);
            }

            $customers_ledger[$accKey]['invoices'][$invId]['items'][] = [
                'product_id'       => intval($row['product_id']),
                'product_name'     => $row['product_name'],
                'category_name'    => $row['category_name'],
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
    }

    // 4. Compute customer-level aggregates and grand totals
    foreach ($customers_ledger as $cKey => &$cData) {
        $grand_total_customers++;

        foreach ($cData['invoices'] as $inv) {
            $grand_total_vouchers++;

            $cData['total_physical_delivered'] += $inv['inv_physical_qty'];
            $cData['uncollected_balance']      += $inv['inv_pending_bal'];
            $cData['total_billed_charge']      += $inv['charge_amount'];
            $cData['total_paid']               += $inv['paid_amount'];

            if ($inv['slip_type'] === 'Balanced Slip') {
                $cData['balanced_delivered'] += $inv['inv_physical_qty'];
                $grand_balanced_units        += $inv['inv_physical_qty'];
            } elseif ($inv['slip_type'] === 'Temporary Slip') {
                $cData['temporary_delivered'] += $inv['inv_physical_qty'];
                $grand_temporary_units        += $inv['inv_physical_qty'];
            } else {
                $cData['permanent_delivered'] += $inv['inv_physical_qty'];
                $grand_permanent_units        += $inv['inv_physical_qty'];
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.2">
    <title>PPMS - Customer Product Credit &amp; Lubricant Ledger Report</title>
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
            opacity: 0.92;
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

        .item-breakdown-box {
            background: #f8fafc;
            border-radius: 6px;
            padding: 4px 8px;
            margin-bottom: 4px;
            border: 1px solid #e2e8f0;
            font-size: 11.5px;
        }
        .item-breakdown-box:last-child { margin-bottom: 0; }

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
                <h5 class="font-weight-bold mb-1">Customer Product Credit &amp; Lubricant Ledger Report</h5>
                <p class="text-muted small mb-2">
                    Generated On: <strong><?php echo date('d-m-Y H:i A'); ?></strong>
                    <?php if (!empty($fromDate) && !empty($toDate)): ?>
                        &nbsp;|&nbsp; Period: <strong><?php echo date('d-m-Y', strtotime($fromDate)); ?> to <?php echo date('d-m-Y', strtotime($toDate)); ?></strong>
                    <?php elseif (!empty($fromDate)): ?>
                        &nbsp;|&nbsp; From: <strong><?php echo date('d-m-Y', strtotime($fromDate)); ?></strong>
                    <?php elseif (!empty($toDate)): ?>
                        &nbsp;|&nbsp; Till: <strong><?php echo date('d-m-Y', strtotime($toDate)); ?></strong>
                    <?php endif; ?>
                </p>
                <hr style="border-top:2px solid #04204e;">
            </div>

            <!-- Page Title & Actions -->
            <div class="row mb-3 align-items-center d-print-none">
                <div class="col-md-7">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-oil-can mr-2 text-warning"></i>Customer Product Credit &amp; Ledger Report
                    </h4>
                    <p class="text-muted small mb-0">Comprehensive product credit ledger per customer: physical deliveries, uncollected balance tracking, zero-double-count billing, and debt receivables.</p>
                </div>
                <div class="col-md-5 text-right">
                    <?php if ($isSearched && !empty($customers_ledger)): ?>
                    <a href="generate-pdf-customer-product-report.php?customer_id=<?php echo urlencode($customerId); ?>&vehicle_number=<?php echo urlencode($vehicleNum); ?>&from_date=<?php echo urlencode($fromDate); ?>&to_date=<?php echo urlencode($toDate); ?>" target="_blank" class="btn btn-danger font-weight-bold mr-2">
                        <i class="fas fa-file-pdf mr-1"></i> Export PDF
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-secondary font-weight-bold mr-2">
                        <i class="fas fa-print mr-1"></i> Print
                    </button>
                    <?php endif; ?>
                    <a href="../lubricants/sales-list.php" class="btn btn-outline-primary font-weight-bold">
                        <i class="fas fa-arrow-left mr-1"></i> Sales List
                    </a>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-4 border-0 shadow-sm d-print-none" style="border-radius:10px;">
                <div class="card-body p-3">
                    <form method="GET" action="customer-product-report.php">
                        <input type="hidden" name="filter" value="1">
                        <div class="form-row align-items-end">
                            <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                                <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-user mr-1 text-primary"></i>Customer Account</label>
                                <select name="customer_id" class="form-control form-control-sm select2">
                                    <option value="">— All Customers —</option>
                                    <?php foreach ($all_customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo ($customerId == $c['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']) . (!empty($c['phone']) ? ' (' . $c['phone'] . ')' : ''); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
                                <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-truck mr-1 text-primary"></i>Vehicle #</label>
                                <input type="text" name="vehicle_number" class="form-control form-control-sm text-uppercase" placeholder="e.g. LEA-9821" value="<?php echo htmlspecialchars($vehicleNum); ?>">
                            </div>

                            <div class="col-lg-2 col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-calendar mr-1 text-primary"></i>From Date</label>
                                <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                            </div>

                            <div class="col-lg-2 col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold text-muted mb-1"><i class="fas fa-calendar mr-1 text-primary"></i>To Date</label>
                                <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                            </div>

                            <div class="col-lg-3 col-md-12 col-sm-12 mb-2 text-right">
                                <button type="submit" class="btn btn-primary btn-sm px-3 font-weight-bold mr-1">
                                    <i class="fas fa-search mr-1"></i> Filter Report
                                </button>
                                <a href="customer-product-report.php" class="btn btn-outline-secondary btn-sm px-3">
                                    <i class="fas fa-sync-alt mr-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Body -->
            <?php if (!$isSearched): ?>
                <div class="text-center py-5 bg-white rounded shadow-sm border">
                    <i class="fas fa-oil-can fa-3x text-muted mb-3" style="opacity:0.4;"></i>
                    <h5 class="text-dark font-weight-bold">Select Search Criteria to View Customer Product Ledger</h5>
                    <p class="text-muted small mb-0">Use the filter panel above to analyze credit product deliveries, pending balances, and receivables.</p>
                </div>
            <?php elseif (empty($customers_ledger)): ?>
                <div class="text-center py-5 bg-white rounded shadow-sm border">
                    <i class="fas fa-folder-open fa-3x text-muted mb-3" style="opacity:0.4;"></i>
                    <h5 class="text-dark font-weight-bold">No Product Credit Records Found</h5>
                    <p class="text-muted small mb-0">No credit invoices or product sales matched your selected filter criteria.</p>
                </div>
            <?php else: ?>

                <!-- Iterate Customer Ledger Cards -->
                <?php foreach ($customers_ledger as $cKey => $c): ?>
                    <div class="customer-card">
                        
                        <!-- Customer Header -->
                        <div class="customer-header">
                            <div>
                                <h5 class="customer-title mb-1">
                                    <i class="fas fa-user-circle mr-2"></i><?php echo htmlspecialchars($c['customer_name']); ?>
                                    <span class="badge badge-light ml-2 text-dark font-weight-bold" style="font-size:11.5px;">
                                        <i class="fas fa-tag mr-1 text-primary"></i><?php echo htmlspecialchars($c['rate_tier']); ?> Rate Policy
                                    </span>
                                </h5>
                                <div class="customer-meta">
                                    <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($c['customer_phone']); ?>
                                    <?php if (!empty($c['vehicles'])): ?>
                                        &nbsp;|&nbsp; <i class="fas fa-truck mr-1"></i>Fleets: <strong><?php echo htmlspecialchars(implode(', ', array_slice($c['vehicles'], 0, 5))) . (count($c['vehicles']) > 5 ? ' (+' . (count($c['vehicles']) - 5) . ' more)' : ''); ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="text-right d-print-none mt-2 mt-sm-0">
                                <?php if ($c['cust_id'] > 0): ?>
                                <a href="generate-pdf-customer-product-report.php?customer_id=<?php echo urlencode($c['cust_id']); ?>&from_date=<?php echo urlencode($fromDate); ?>&to_date=<?php echo urlencode($toDate); ?>" target="_blank" class="btn btn-sm btn-light font-weight-bold text-danger mr-1" title="Export this customer statement">
                                    <i class="fas fa-file-pdf mr-1"></i> Customer Statement
                                </a>
                                <?php endif; ?>
                                <span class="badge badge-warning text-dark font-weight-bold px-2 py-1" style="font-size:12px;">
                                    <?php echo count($c['invoices']); ?> <?php echo (count($c['invoices']) === 1) ? 'Voucher' : 'Vouchers'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Itemized Slips Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 ledger-table">
                                <thead>
                                    <tr>
                                        <th style="width: 35px;">#</th>
                                        <th style="width: 90px;">Slip Date</th>
                                        <th style="width: 140px;">Voucher / Slip #</th>
                                        <th style="width: 130px;">Classification</th>
                                        <th style="width: 100px;">Vehicle #</th>
                                        <th style="min-width: 260px; text-align: left;">Itemized Product Dispensed</th>
                                        <th style="width: 95px;">Physical Outflow</th>
                                        <th style="width: 110px;">Balance Status</th>
                                        <th style="width: 150px; text-align: left;">Linked Reference / Settle</th>
                                        <th style="width: 130px;" class="text-right bg-light text-danger">Must Pay (Rs.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rowCounter = 1;
                                    foreach ($c['invoices'] as $inv): 
                                        $st = $inv['slip_type'];
                                        if ($st === 'Balanced Slip') {
                                            $badgeClass = 'slip-badge-bal';
                                            $stIcon = 'fa-history';
                                            $stLabel = 'Balanced Claim';
                                        } elseif ($st === 'Temporary Slip') {
                                            $badgeClass = 'slip-badge-temp';
                                            $stIcon = 'fa-hand-holding';
                                            $stLabel = 'Temp. Loan Chit';
                                        } else {
                                            $badgeClass = 'slip-badge-perm';
                                            $stIcon = 'fa-file-invoice';
                                            $stLabel = 'Permanent Slip';
                                        }

                                        // Payment status styling
                                        $paySt = $inv['payment_status'];
                                        if ($paySt === 'Paid') {
                                            $payBadge = '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i>Paid</span>';
                                        } elseif ($paySt === 'Partial') {
                                            $payBadge = '<span class="badge badge-info px-2 py-1"><i class="fas fa-adjust mr-1"></i>Partial</span>';
                                        } else {
                                            $payBadge = '<span class="badge badge-danger px-2 py-1"><i class="fas fa-times-circle mr-1"></i>Unpaid</span>';
                                        }
                                        $invDue = max(0.00, round($inv['charge_amount'] - $inv['paid_amount'], 2));
                                    ?>
                                    <tr>
                                        <td class="text-center font-weight-bold text-muted"><?php echo $rowCounter++; ?></td>
                                        <td class="text-center font-weight-bold text-dark">
                                            <?php echo date('d-M-Y', strtotime($inv['slip_date'])); ?>
                                            <?php if (!empty($inv['shift_name'])): ?>
                                                <div class="small text-muted font-weight-normal" style="font-size:11px;"><i class="fas fa-clock mr-1 text-primary"></i><?php echo htmlspecialchars($inv['shift_name']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($inv['slip_date'] !== $inv['sale_date']): ?>
                                                <div class="text-muted small" style="font-size:10px;">Entry: <?php echo date('d-M-y', strtotime($inv['sale_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="../lubricants/edit-sale.php?invoice_id=<?php echo $inv['invoice_id']; ?>" class="font-weight-bold text-primary" title="View / Edit Invoice">
                                                <?php echo htmlspecialchars($inv['slip_no']); ?>
                                            </a>
                                            <div class="text-muted small" style="font-size:10.5px;"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $badgeClass; ?> px-2 py-1" style="font-size:11px;">
                                                <i class="fas <?php echo $stIcon; ?> mr-1"></i><?php echo $stLabel; ?>
                                            </span>
                                        </td>
                                        <td class="text-center font-weight-bold text-dark">
                                            <i class="fas fa-truck mr-1 text-muted"></i><?php echo htmlspecialchars($inv['vehicle_number']); ?>
                                        </td>
                                        
                                        <!-- Itemized Product Lines -->
                                        <td class="text-left">
                                            <?php foreach ($inv['items'] as $it): ?>
                                                <div class="item-breakdown-box">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>
                                                            <strong><?php echo htmlspecialchars($it['product_name']); ?></strong>
                                                            <?php if (!empty($it['category_name'])): ?>
                                                                <span class="text-muted small">[<?php echo htmlspecialchars($it['category_name']); ?>]</span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="font-weight-bold text-primary">
                                                            Rs. <?php echo number_format($it['amount'], 2); ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-muted small mt-1 d-flex justify-content-between">
                                                        <span>
                                                            Quota: <strong><?php echo $it['quantity']; ?></strong> &nbsp;|&nbsp; 
                                                            Issue: <strong class="text-success"><?php echo $it['issue_quantity']; ?></strong> &nbsp;|&nbsp; 
                                                            Rate: Rs. <?php echo number_format($it['rate'], 2); ?>
                                                        </span>
                                                        <?php if ($it['balance_quantity'] > 0): ?>
                                                            <span class="<?php echo ($it['net_balance'] > 0) ? 'text-warning font-weight-bold' : 'text-success'; ?>">
                                                                <?php if ($it['net_balance'] > 0): ?>
                                                                    <i class="fas fa-clock mr-1"></i><?php echo $it['net_balance']; ?> pending
                                                                <?php else: ?>
                                                                    <i class="fas fa-check-circle mr-1"></i>Balance claimed
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>

                                        <!-- Physical Stock Outflow -->
                                        <td class="text-center font-weight-bold text-success" style="font-size:13px;">
                                            <?php echo $inv['inv_physical_qty']; ?> units
                                        </td>

                                        <!-- Balance Status -->
                                        <td class="text-center">
                                            <?php if ($inv['slip_type'] === 'Permanent Slip'): ?>
                                                <?php if ($inv['inv_pending_bal'] > 0): ?>
                                                    <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold">
                                                        <i class="fas fa-clock mr-1"></i><?php echo $inv['inv_pending_bal']; ?> pending
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-success small font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Fulfilled</span>
                                                <?php endif; ?>
                                            <?php elseif ($inv['slip_type'] === 'Balanced Slip'): ?>
                                                <span class="text-info small font-weight-bold"><i class="fas fa-undo mr-1"></i>Returned</span>
                                            <?php else: ?>
                                                <?php if ($inv['is_returned']): ?>
                                                    <span class="text-success small font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Settled</span>
                                                <?php else: ?>
                                                    <span class="text-warning small font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i>Open Chit</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Linked Reference Info -->
                                        <td class="text-left small">
                                            <?php if ($inv['slip_type'] === 'Balanced Slip'): ?>
                                                <div class="text-dark">
                                                    <strong>Claimed Ref:</strong> #<?php echo htmlspecialchars($inv['ref_slip_no'] ?: '—'); ?>
                                                </div>
                                                <?php if (!empty($inv['ref_slip_date'])): ?>
                                                    <div class="text-muted" style="font-size:10.5px;">Orig Date: <?php echo date('d-M-Y', strtotime($inv['ref_slip_date'])); ?></div>
                                                <?php endif; ?>
                                            <?php elseif ($inv['slip_type'] === 'Temporary Slip'): ?>
                                                <?php if (!empty($inv['settling_slip_no'])): ?>
                                                    <div class="text-success font-weight-bold">
                                                        <i class="fas fa-link mr-1"></i>Settled in Slip #<?php echo htmlspecialchars($inv['settling_slip_no']); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-danger font-weight-bold"><i class="fas fa-clock mr-1"></i>Awaiting Permanent Slip</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($inv['temp_wasoli_amount'] > 0): ?>
                                                    <div class="text-info font-weight-bold">
                                                        <i class="fas fa-file-import mr-1"></i>Temp Receive: Rs. <?php echo number_format($inv['temp_wasoli_amount'], 2); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Must Pay (Rs.) - Styled identically to Customer Report Fuel -->
                                        <td class="text-right font-weight-bold <?php echo ($st === 'Balanced Slip') ? 'text-muted' : ''; ?>" style="font-size:13px; background-color:#fffdfd;">
                                            <?php if ($st === 'Balanced Slip'): ?>
                                                <span class="badge badge-light border text-muted">Rs. 0.00 (Pre-paid)</span>
                                            <?php elseif ($st === 'Temporary Slip'): ?>
                                                <?php if (!empty($inv['settling_slip_no'])): ?>
                                                    <span class="text-success font-weight-bold">Rs. 0.00</span>
                                                    <small class="text-muted d-block font-weight-bold" style="font-size: 10px;">
                                                        <i class="fas fa-check-circle mr-1"></i>(Billed in #<?php echo htmlspecialchars($inv['settling_slip_no']); ?>)
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted font-weight-bold">Rs. 0.00</span>
                                                    <small class="text-warning d-block font-weight-bold" style="font-size: 10px; color:#b07800 !important;">
                                                        (Loan Chit &mdash; Pending Voucher)
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-danger font-weight-bold">Rs. <?php echo number_format($inv['charge_amount'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Customer Summary Ribbon -->
                        <div class="summary-ribbon">
                            <div class="row">
                                <div class="col-lg-3 col-md-6 col-sm-6 mb-2 mb-lg-0">
                                    <div class="summary-stat-box">
                                        <div class="summary-stat-label"><i class="fas fa-truck-loading mr-1 text-primary"></i>Total Handover</div>
                                        <div class="summary-stat-value text-primary"><?php echo $c['total_physical_delivered']; ?> <span style="font-size:12px; font-weight:600;">units</span></div>
                                        <div class="text-muted small mt-1" style="font-size:10px;">Physical Outflow</div>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-md-6 col-sm-6 mb-2 mb-lg-0">
                                    <div class="summary-stat-box">
                                        <div class="summary-stat-label"><i class="fas fa-sitemap mr-1 text-info"></i>Delivery Types</div>
                                        <div class="summary-stat-value text-dark" style="font-size:13px; text-align:left;">
                                            <div><strong class="text-primary"><?php echo $c['permanent_delivered']; ?></strong> <span class="text-muted">Perm</span></div>
                                            <div><strong class="text-info"><?php echo $c['balanced_delivered']; ?></strong> <span class="text-muted">Bal Claim</span></div>
                                            <div><strong class="text-warning"><?php echo $c['temporary_delivered']; ?></strong> <span class="text-muted">Temp Loan</span></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-md-6 col-sm-6 mb-2 mb-lg-0">
                                    <div class="summary-stat-box">
                                        <div class="summary-stat-label"><i class="fas fa-clock mr-1 text-warning"></i>Remaining Bal</div>
                                        <div class="summary-stat-value <?php echo ($c['uncollected_balance'] > 0) ? 'text-warning' : 'text-success'; ?>">
                                            <?php echo $c['uncollected_balance']; ?> <span style="font-size:12px; font-weight:600;">units</span>
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size:10px;">Active Balance Owed</div>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-md-6 col-sm-6">
                                    <div class="summary-stat-box" style="background:#fff8f8; border-color:#fed7d7;">
                                        <div class="summary-stat-label text-danger font-weight-bold"><i class="fas fa-file-invoice-dollar mr-1"></i>Total Invoiced Receivable</div>
                                        <div class="summary-stat-value text-danger" style="font-size: 18px;">Rs. <?php echo number_format($c['total_billed_charge'], 2); ?></div>
                                        <div class="text-muted small mt-1" style="font-size:10px;">Must Collect (Customer Debt)</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>

                <!-- Grand Summary Card -->
                <div class="grand-summary-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="font-weight-bold mb-0" style="color:#04204e;">
                            <i class="fas fa-calculator mr-2 text-primary"></i>All Customers Combined Product Ledger Summary
                        </h5>
                        <span class="badge badge-primary px-3 py-2 font-weight-bold" style="font-size:13px;">
                            <?php echo $grand_total_customers; ?> <?php echo ($grand_total_customers === 1) ? 'Customer' : 'Customers'; ?> &nbsp;|&nbsp; <?php echo $grand_total_vouchers; ?> Vouchers
                        </span>
                    </div>

                    <div class="row">
                        <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                            <div class="p-3 bg-light rounded border text-center">
                                <div class="text-muted small text-uppercase font-weight-bold">Total Physical Delivered</div>
                                <div class="h4 font-weight-bold text-primary mb-1 mt-2"><?php echo number_format($grand_physical_delivered, 0); ?> units</div>
                                <div class="small text-muted">
                                    <strong class="text-primary"><?php echo $grand_permanent_units; ?></strong> Perm &nbsp;|&nbsp; 
                                    <strong class="text-info"><?php echo $grand_balanced_units; ?></strong> Bal &nbsp;|&nbsp; 
                                    <strong class="text-warning"><?php echo $grand_temporary_units; ?></strong> Temp
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                            <div class="p-3 bg-light rounded border text-center">
                                <div class="text-muted small text-uppercase font-weight-bold">Uncollected Balances</div>
                                <div class="h4 font-weight-bold <?php echo ($grand_uncollected_bal > 0) ? 'text-warning' : 'text-success'; ?> mb-1 mt-2">
                                    <?php echo number_format($grand_uncollected_bal, 0); ?> units
                                </div>
                                <div class="small text-muted">Pending Stock Owed to Clients</div>
                            </div>
                        </div>

                        <div class="col-lg-4 col-md-12">
                            <div class="p-3 rounded border text-center" style="background:#fff5f5; border-color:#feb2b2;">
                                <div class="text-danger small text-uppercase font-weight-bold">Total Invoiced To Collect</div>
                                <div class="h4 font-weight-bold text-danger mb-1 mt-2">Rs. <?php echo number_format($grand_billed_receivable, 2); ?></div>
                                <div class="small text-danger">Total Customer Product Receivable</div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
</body>
</html>
