<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing stock report
check_access('items', 'show');

// Date filters
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate   = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$pur_date_cond = "";
$sal_date_cond = "";
$cum_pur_cond  = "";
$cum_sal_cond  = "";

if (!empty($fromDate) && !empty($toDate)) {
    $escFrom = mysqli_real_escape_string($connection, $fromDate);
    $escTo   = mysqli_real_escape_string($connection, $toDate);
    $pur_date_cond = " AND date BETWEEN '$escFrom' AND '$escTo' ";
    $sal_date_cond = " AND date BETWEEN '$escFrom' AND '$escTo' ";
    $cum_pur_cond  = " AND date <= '$escTo' ";
    $cum_sal_cond  = " AND date <= '$escTo' ";
}

// Calculate overall summary metrics in date range
$total_purchases_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $pur_date_cond);
$total_purchases = floatval(mysqli_fetch_row($total_purchases_res)[0] ?? 0);

$total_cash_sales_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE payment_type='Cash' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond);
$total_cash_sales = floatval(mysqli_fetch_row($total_cash_sales_res)[0] ?? 0);

$total_credit_sales_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE payment_type='Credit' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond);
$total_credit_sales = floatval(mysqli_fetch_row($total_credit_sales_res)[0] ?? 0);
$total_sold_units = $total_cash_sales + $total_credit_sales;

// Calculate overall revenue generated from product sales
$total_revenue_res = mysqli_query($connection, "SELECT SUM(amount) FROM tbl_lubricant_sales WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond);
$total_revenue = floatval(mysqli_fetch_row($total_revenue_res)[0] ?? 0);

$total_cash_rev_res = mysqli_query($connection, "SELECT SUM(amount) FROM tbl_lubricant_sales WHERE payment_type='Cash' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond);
$total_cash_revenue = floatval(mysqli_fetch_row($total_cash_rev_res)[0] ?? 0);

$total_credit_rev_res = mysqli_query($connection, "SELECT SUM(amount) FROM tbl_lubricant_sales WHERE payment_type='Credit' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond);
$total_credit_revenue = floatval(mysqli_fetch_row($total_credit_rev_res)[0] ?? 0);

// Auto-migrate tbl_lubricant_products: ensure reorder_level column exists and deleted_at exists
$chk_ro = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'reorder_level'");
if ($chk_ro && mysqli_num_rows($chk_ro) == 0) {
    $chk_sq = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'shelf_quantity'");
    if ($chk_sq && mysqli_num_rows($chk_sq) > 0) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products CHANGE COLUMN shelf_quantity reorder_level INT(11) NOT NULL DEFAULT 0");
    } else {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products ADD COLUMN reorder_level INT(11) NOT NULL DEFAULT 0 AFTER category");
    }
} else {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_products MODIFY COLUMN reorder_level INT(11) NOT NULL DEFAULT 0");
}

// Fetch products with cumulative and period metrics
$sql = "
    SELECT p.id, p.name, p.price, 
           COALESCE((SELECT reorder_level FROM tbl_lubricant_products WHERE id = p.id), 0) AS reorder_level,
           -- Cumulative stock calculations up to To Date
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $cum_pur_cond . "), 0) AS cumulative_purchased,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $cum_sal_cond . "), 0) AS cumulative_sold,
           
           -- Period calculations based on date range
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $pur_date_cond . "), 0) AS period_purchased,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND payment_type='Cash' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond . "), 0) AS period_cash_sold,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND payment_type='Credit' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond . "), 0) AS period_credit_sold,
           COALESCE((SELECT SUM(amount) FROM tbl_lubricant_sales WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')" . $sal_date_cond . "), 0) AS period_revenue
    FROM tbl_lubricant_products p
    WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
    ORDER BY p.name ASC
";
$result = mysqli_query($connection, $sql);
$products_data = [];
$total_valuation = 0;
$total_low_stock = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['current_stock'] = intval($row['cumulative_purchased']) - intval($row['cumulative_sold']);
        $row['reorder_level'] = intval($row['reorder_level'] ?? 0);
        $row['period_revenue'] = floatval($row['period_revenue'] ?? 0);
        $row['stock_value']   = $row['current_stock'] * floatval($row['price']);
        $total_valuation      += $row['stock_value'];
        $row['is_low_stock']  = ($row['reorder_level'] > 0 && $row['current_stock'] <= $row['reorder_level']) || ($row['current_stock'] <= 0);
        if ($row['is_low_stock']) {
            $total_low_stock++;
        }
        $products_data[]      = $row;
    }
}
$total_products = count($products_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Stock & Revenue Report</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
    		<link rel="stylesheet" href="../include/style.css?v=1.0.2" />
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
            font-weight: 500;
        }
        .btn-primary:hover { opacity: 0.9; }
        
        .btn-outline-primary {
            color: #04204e !important;
            border-color: #04204e !important;
            background-color: transparent !important;
            font-weight: 500;
        }
        .btn-outline-primary:hover,
        .btn-outline-primary.active {
            background: var(--primary-gradient) !important;
            background-color: #04204e !important;
            color: #ffffff !important;
            border-color: #04204e !important;
        }
        
        /* Premium Card styling */
        .metric-card {
            border-radius: 12px;
            color: #fff;
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
            margin-bottom: 22px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 22px rgba(0,0,0,0.12);
        }
        .card-bg-1 { background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%); }
        .card-bg-2 { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .card-bg-3 { background: linear-gradient(135deg, #f857a6 0%, #ff5858 100%); }
        .card-bg-4 { background: linear-gradient(135deg, #1d976c 0%, #2ecc71 100%); }
        .card-bg-5 { background: linear-gradient(135deg, #00c6ff 0%, #0072ff 100%); }
        
        .metric-card .card-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; font-weight: 700; margin-bottom: 4px; }
        .metric-card .card-value { font-size: 24px; font-weight: 900; }
        .metric-card .card-subtext { font-size: 11px; opacity: 0.9; margin-top: 4px; font-weight: 500; }
        .metric-card .card-icon { position: absolute; right: 20px; bottom: 20px; font-size: 38px; opacity: 0.2; }

        .filter-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            padding: 18px 22px;
            margin-bottom: 22px;
        }

        #reportTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            font-size: 12px;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        #reportTable tfoot th {
            background-color: #f1f4f9 !important;
            font-size: 12.5px;
            vertical-align: middle;
            text-align: center;
        }
        #reportTable td {
            vertical-align: middle;
            text-align: center;
        }
        
        /* Stock gauges */
        .stock-badge-high {
            background-color: #e8f5e9;
            color: #2e7d32;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
        }
        .stock-badge-mid {
            background-color: #fff3e0;
            color: #e65100;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
        }
        .stock-badge-low {
            background-color: #ffebee;
            color: #c62828;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }
        
        @media print {
            nav, .navbar, .filter-card, .btn, button, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info, th:last-child, td:last-child {
                display: none !important;
            }
            main, .main {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
            body {
                background: #fff !important;
                color: #000 !important;
                font-family: Arial, sans-serif;
            }
            .container-fluid {
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .card {
                box-shadow: none !important;
                border: none !important;
            }
            .table-responsive {
                overflow: visible !important;
            }
            #reportTable {
                width: 100% !important;
                border: 1px solid #000 !important;
                border-collapse: collapse !important;
            }
            #reportTable th, #reportTable td {
                border: 1px solid #000 !important;
                font-size: 10px !important;
                padding: 4px !important;
            }
        }
    </style>
</head>
<body>
    
    <?php include('../include/navbar.php'); ?>

    <main class="main">
        <div class="container-fluid pt-4 pb-4 px-lg-5">
            
            <!-- Page Header -->
            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-chart-line mr-2 text-primary"></i>Lubricant Stock & Revenue Report
                    </h4>
                    <p class="text-muted small mb-0">Track real-time inventory balances, stock movements, and sales revenue generated across date ranges.</p>
                </div>
                <div class="col-md-6 text-right d-print-none">
                    <button class="btn btn-outline-secondary mr-2 font-weight-bold" onclick="window.print();">
                        <i class="fas fa-print mr-1"></i> Print Report
                    </button>
                    <a href="stock-report.php" class="btn btn-outline-danger font-weight-bold">
                        <i class="fas fa-sync-alt mr-1"></i> Reset Filters
                    </a>
                </div>
            </div>

            <!-- Date Filter Form -->
            <div class="filter-card d-print-none">
                <form action="stock-report.php" method="GET" class="form-row align-items-end">
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-alt mr-1 text-primary"></i> From Date</label>
                        <input type="date" name="from_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($fromDate); ?>">
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <label class="font-weight-bold small text-muted mb-1"><i class="fas fa-calendar-alt mr-1 text-primary"></i> To Date</label>
                        <input type="date" name="to_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($toDate); ?>">
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <label class="d-none d-md-block small text-muted mb-1">&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm" style="height: 38px;">
                            <i class="fas fa-filter mr-1"></i> Filter Range
                        </button>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                        <label class="d-none d-md-block small text-muted mb-1">&nbsp;</label>
                        <div class="btn-group btn-block shadow-sm" style="height: 38px;">
                            <a href="stock-report.php?from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-t'); ?>" class="btn btn-outline-primary font-weight-bold <?php echo ($fromDate == date('Y-m-01') && $toDate == date('Y-m-t')) ? 'active' : ''; ?>">This Month</a>
                            <a href="stock-report.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-primary font-weight-bold <?php echo ($fromDate == date('Y-m-d') && $toDate == date('Y-m-d')) ? 'active' : ''; ?>">Today</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary metrics -->
            <div class="row">
                <div class="col-xl-2 col-md-4 col-sm-6 position-relative">
                    <div class="metric-card card-bg-1">
                        <div class="card-title">Total Products</div>
                        <div class="card-value"><?php echo $total_products; ?></div>
                        <div class="card-subtext"><?php echo $total_low_stock; ?> need reorder</div>
                        <i class="fas fa-boxes card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 position-relative">
                    <div class="metric-card card-bg-2">
                        <div class="card-title">Purchases (Period)</div>
                        <div class="card-value"><?php echo number_format($total_purchases, 0); ?></div>
                        <div class="card-subtext">Total inflow units</div>
                        <i class="fas fa-download card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 position-relative">
                    <div class="metric-card card-bg-3">
                        <div class="card-title">Total Sold (Period)</div>
                        <div class="card-value"><?php echo number_format($total_sold_units, 0); ?></div>
                        <div class="card-subtext">Cash: <?php echo number_format($total_cash_sales, 0); ?> | Credit: <?php echo number_format($total_credit_sales, 0); ?></div>
                        <i class="fas fa-shopping-bag card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-sm-6 position-relative">
                    <div class="metric-card card-bg-4">
                        <div class="card-title">Sales Revenue (Period)</div>
                        <div class="card-value">Rs. <?php echo number_format($total_revenue, 2); ?></div>
                        <div class="card-subtext">Cash: Rs. <?php echo number_format($total_cash_revenue, 2); ?> | Credit: Rs. <?php echo number_format($total_credit_revenue, 2); ?></div>
                        <i class="fas fa-money-bill-wave card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-sm-12 position-relative">
                    <div class="metric-card card-bg-5">
                        <div class="card-title">In-Stock Valuation</div>
                        <div class="card-value">Rs. <?php echo number_format($total_valuation, 2); ?></div>
                        <div class="card-subtext">Available inventory value</div>
                        <i class="fas fa-chart-line card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Report Table -->
            <div class="card shadow-sm border-0" style="border-radius:10px; overflow:hidden;">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="reportTable" class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Purchased (Inflow)</th>
                                    <th>Cash Sales</th>
                                    <th>Credit Sales</th>
                                    <th style="background:#28a745 !important; color:#fff !important;">Total Sold</th>
                                    <th>Selling Price</th>
                                    <th style="background:#198754 !important; color:#fff !important;">Sales Revenue (Rs.)</th>
                                    <th style="background:#17a2b8 !important; color:#fff !important;">Reorder Level</th>
                                    <th style="background:#0072ff !important; color:#fff !important;">Current Stock</th>
                                    <th style="text-align:center;">Status</th>
                                    <th>Stock Value (Rs.)</th>
                                    <th class="d-print-none" style="min-width:140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($products_data as $row) {
                                    $current_stock = $row['current_stock'];
                                    $reorder_level = $row['reorder_level'];
                                    $period_total_sales = $row['period_cash_sold'] + $row['period_credit_sold'];
                                    $period_revenue = $row['period_revenue'];
                                    
                                    // Stock Level & Status highlight
                                    if ($current_stock <= 0) {
                                        $stock_badge = '<span class="font-weight-bold text-danger px-2 py-1" style="background:#ffebee; border-radius:5px; border:1px solid #ffcdd2;">' . number_format($current_stock, 0) . '</span>';
                                        $status_badge = '<span class="badge badge-dark py-1 px-2"><i class="fas fa-times-circle mr-1 text-danger"></i> Out of Stock</span>';
                                    } elseif ($row['is_low_stock']) {
                                        $stock_badge = '<span class="font-weight-bold text-danger px-2 py-1" style="background:#ffebee; border-radius:5px; border:1px solid #ffcdd2;">' . number_format($current_stock, 0) . '</span>';
                                        $status_badge = '<span class="badge badge-danger py-1 px-2"><i class="fas fa-exclamation-triangle mr-1"></i> Reorder Required</span>';
                                    } elseif ($current_stock >= 50) {
                                        $stock_badge = '<span class="stock-badge-high font-weight-bold px-2 py-1" style="background:#e8f5e9; color:#2e7d32; border-radius:5px;">' . number_format($current_stock, 0) . '</span>';
                                        $status_badge = '<span class="badge badge-success py-1 px-2"><i class="fas fa-check-circle mr-1"></i> In Stock</span>';
                                    } else {
                                        $stock_badge = '<span class="stock-badge-mid font-weight-bold px-2 py-1" style="background:#fff8e1; color:#f57f17; border-radius:5px;">' . number_format($current_stock, 0) . '</span>';
                                        $status_badge = '<span class="badge badge-info py-1 px-2"><i class="fas fa-check mr-1"></i> Adequate</span>';
                                    }
                                    
                                    $row_bg = $row['is_low_stock'] ? 'style="background-color: #fff9f9;"' : '';
                                    
                                    echo '
                                        <tr ' . $row_bg . '>
                                            <td class="text-left font-weight-bold" style="color:var(--primary-color);">' . htmlspecialchars($row['name']) . '</td>
                                            <td>' . number_format($row['period_purchased'], 0) . '</td>
                                            <td class="text-success">' . number_format($row['period_cash_sold'], 0) . '</td>
                                            <td class="text-warning font-weight-bold">' . number_format($row['period_credit_sold'], 0) . '</td>
                                            <td class="font-weight-bold" style="background:#f4fbf4; color:#28a745;">' . number_format($period_total_sales, 0) . '</td>
                                            <td>' . number_format($row['price'], 2) . '</td>
                                            <td class="font-weight-bold text-success" style="background:#f0fff4;">Rs. ' . number_format($period_revenue, 2) . '</td>
                                            <td class="font-weight-bold">' . number_format($reorder_level, 0) . '</td>
                                            <td>' . $stock_badge . '</td>
                                            <td class="text-center">' . $status_badge . '</td>
                                            <td class="font-weight-bold" style="background:#f0f5ff; color:#0052cc;">Rs. ' . number_format($row['stock_value'], 2) . '</td>
                                            <td class="d-print-none">
                                                <button class="btn btn-outline-info btn-xs py-1 px-2 font-weight-bold" style="font-size:11px; border-radius:5px;" onclick="viewLedger(' . $row['id'] . ', \'' . addslashes(htmlspecialchars($row['name'])) . '\')">
                                                    <i class="fas fa-receipt mr-1"></i> Ledger
                                                </button>
                                                <a href="add-purchase.php?product_id=' . $row['id'] . '" class="btn btn-outline-success btn-xs py-1 px-2 font-weight-bold ml-1" style="font-size:11px; border-radius:5px;">
                                                    <i class="fas fa-plus mr-1"></i> Stock
                                                </a>
                                            </td>
                                        </tr>
                                    ';
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th class="text-left font-weight-bold">Total / Summary</th>
                                    <th><?php echo number_format($total_purchases, 0); ?></th>
                                    <th class="text-success"><?php echo number_format($total_cash_sales, 0); ?></th>
                                    <th class="text-warning"><?php echo number_format($total_credit_sales, 0); ?></th>
                                    <th class="text-success font-weight-bold"><?php echo number_format($total_sold_units, 0); ?></th>
                                    <th>—</th>
                                    <th class="text-success font-weight-bold" style="background:#e8f5e9 !important;">Rs. <?php echo number_format($total_revenue, 2); ?></th>
                                    <th>—</th>
                                    <th>—</th>
                                    <th>—</th>
                                    <th class="font-weight-bold text-primary" style="background:#e8eaf6 !important;">Rs. <?php echo number_format($total_valuation, 2); ?></th>
                                    <th class="d-print-none">—</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </main>

    <!-- Product Audit Ledger Modal -->
    <div class="modal fade" id="ledgerModal" tabindex="-1" role="dialog" aria-labelledby="ledgerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <div class="modal-header bg-dark text-white py-3">
                    <h5 class="modal-title font-weight-bold" id="ledgerModalLabel"><i class="fas fa-file-invoice mr-2 text-info"></i>Audit Ledger - <span id="ledgerProductName"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                        <table class="table table-bordered table-striped mb-0" id="ledgerTable" style="font-size:13px;">
                            <thead class="bg-light text-secondary">
                                <tr>
                                    <th style="width: 50px;" class="text-center">#</th>
                                    <th>Date</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-right">Qty In (+)</th>
                                    <th class="text-right">Qty Out (-)</th>
                                    <th class="text-right">Rate (Rs.)</th>
                                    <th class="text-right">Total (Rs.)</th>
                                    <th>Transaction Details</th>
                                </tr>
                            </thead>
                            <tbody id="ledgerBody">
                                <!-- Loaded dynamically via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary btn-sm px-4 font-weight-bold" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</body>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#reportTable').DataTable({
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "order": [[ 0, "asc" ]]
        });
    });

    function viewLedger(productId, productName) {
        $('#ledgerProductName').text(productName);
        $('#ledgerBody').html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary mr-2"></i>Loading ledger entries...</td></tr>');
        $('#ledgerModal').modal('show');
        
        $.ajax({
            type: "GET",
            url: "get-product-ledger.php",
            data: { id: productId },
            success: function(response) {
                $('#ledgerBody').html(response);
            },
            error: function(xhr, status, error) {
                $('#ledgerBody').html('<tr><td colspan="8" class="text-center text-danger"><i class="fas fa-exclamation-circle mr-2"></i>Failed to fetch ledger details. Please try again.</td></tr>');
            }
        });
    }
</script>
</html>
