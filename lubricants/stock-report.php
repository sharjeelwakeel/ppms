<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

// Date filters
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate   = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$pur_date_cond = "";
$sal_date_cond = "";

if (!empty($fromDate) && !empty($toDate)) {
    $escFrom = mysqli_real_escape_string($connection, $fromDate);
    $escTo   = mysqli_real_escape_string($connection, $toDate);
    $pur_date_cond = " AND date BETWEEN '$escFrom' AND '$escTo' ";
    $sal_date_cond = " AND date BETWEEN '$escFrom' AND '$escTo' ";
}

// Calculate overall summary metrics in date range
$total_purchases_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE 1=1" . $pur_date_cond);
$total_purchases = floatval(mysqli_fetch_row($total_purchases_res)[0]);

$total_cash_sales_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE payment_type='Cash'" . $sal_date_cond);
$total_cash_sales = floatval(mysqli_fetch_row($total_cash_sales_res)[0]);

$total_credit_sales_res = mysqli_query($connection, "SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE payment_type='Credit'" . $sal_date_cond);
$total_credit_sales = floatval(mysqli_fetch_row($total_credit_sales_res)[0]);

$total_products_res = mysqli_query($connection, "SELECT COUNT(*) FROM tbl_lubricant_products");
$total_products = intval(mysqli_fetch_row($total_products_res)[0]);

// Fetch products with lifetime and period metrics
$sql = "
    SELECT p.id, p.name, p.price,
           -- Lifetime stock calculations
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id), 0) AS lifetime_purchased,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id), 0) AS lifetime_sold,
           
           -- Period calculations based on date range
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id" . $pur_date_cond . "), 0) AS period_purchased,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND payment_type='Cash'" . $sal_date_cond . "), 0) AS period_cash_sold,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND payment_type='Credit'" . $sal_date_cond . "), 0) AS period_credit_sold
    FROM tbl_lubricant_products p
    ORDER BY p.name ASC
";
$result = mysqli_query($connection, $sql);
$products_data = [];
$total_valuation = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['current_stock'] = $row['lifetime_purchased'] - $row['lifetime_sold'];
        $row['stock_value'] = $row['current_stock'] * $row['price'];
        $total_valuation += $row['stock_value'];
        $products_data[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Stock Report</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
    <link rel="stylesheet" href="../include/style.css?v=1.0.1" />
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        
        /* Premium Card styling */
        .metric-card {
            border-radius: 12px;
            border: none;
            color: #fff;
            padding: 20px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
            margin-bottom: 22px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 22px rgba(0,0,0,0.12);
        }
        .card-bg-1 { background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%); }
        .card-bg-2 { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .card-bg-3 { background: linear-gradient(135deg, #f857a6 0%, #ff5858 100%); }
        .card-bg-4 { background: linear-gradient(135deg, #e65100 0%, #f57c00 100%); }
        .card-bg-5 { background: linear-gradient(135deg, #00c6ff 0%, #0072ff 100%); }
        
        .metric-card .card-title { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; font-weight: 700; margin-bottom: 5px; }
        .metric-card .card-value { font-size: 26px; font-weight: 900; }
        .metric-card .card-icon { position: absolute; right: 25px; bottom: 25px; font-size: 40px; opacity: 0.2; }

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
                padding: 4px 6px !important;
                font-size: 10px !important;
                color: #000 !important;
                background: transparent !important;
                text-align: center !important;
            }
            #reportTable td.text-left {
                text-align: left !important;
            }
            .stock-badge-high, .stock-badge-mid, .stock-badge-low {
                background: none !important;
                color: #000 !important;
                padding: 0 !important;
                font-weight: bold !important;
            }
            .metric-card {
                border: 1px solid #ccc !important;
                background: #fafafa !important;
                color: #000 !important;
                box-shadow: none !important;
                margin-bottom: 10px !important;
                padding: 10px !important;
            }
            .metric-card .card-title { color: #666 !important; font-weight: bold !important; }
            .metric-card .card-value { color: #000 !important; font-size: 18px !important; }
            .metric-card .card-icon { display: none !important; }
        }
    </style>
</head>
<body>
    <?php include('../include/navbar.php'); ?>
    <main class="main">
        <div class="container-fluid pt-4 pb-5 px-lg-4">
            
            <!-- Print Header -->
            <div class="d-none d-print-block text-center mb-4">
                <h3 class="font-weight-bold">Petrol Pump Management System (PPMS)</h3>
                <h4 class="text-secondary">Stock & Lubricants Inventory Report</h4>
                <p class="small text-muted">
                    <?php if (!empty($fromDate) && !empty($toDate)): ?>
                        Period: <strong><?php echo date("d-m-Y", strtotime($fromDate)); ?></strong> to <strong><?php echo date("d-m-Y", strtotime($toDate)); ?></strong>
                    <?php else: ?>
                        All-Time Inventory Summary
                    <?php endif; ?>
                    &nbsp;|&nbsp; Generated on: <?php echo date("d-m-Y h:i A"); ?>
                </p>
                <hr style="border-top: 2px solid #333; margin-top: 10px;">
            </div>

            <!-- Page Header -->
            <div class="row align-items-center mb-4 d-print-none">
                <div class="col-md-6 col-sm-12">
                    <h4 class="font-weight-bold"><i class="fas fa-cubes mr-2 text-primary"></i>Stock & Inventory Report</h4>
                    <small class="text-muted">Manage and audit non-fuel stock sales, inflow, outflow, and current availability</small>
                </div>
                <div class="col-md-6 col-sm-12 text-md-right mt-2 mt-md-0">
                    <button onclick="window.print()" class="btn btn-dark btn-sm px-4 font-weight-bold shadow-sm" style="background:#2c3e50; border:none; border-radius:6px;">
                        <i class="fas fa-print mr-1"></i> Print / Save PDF
                    </button>
                </div>
            </div>

            <!-- Date Filters -->
            <div class="filter-card d-print-none">
                <form method="GET" action="stock-report.php">
                    <div class="row align-items-end">
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> From Date</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo $fromDate; ?>">
                        </div>
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> To Date</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo $toDate; ?>">
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <button type="submit" class="btn btn-primary btn-sm px-4"><i class="fas fa-filter mr-1"></i> Apply Filter</button>
                            <a href="stock-report.php" class="btn btn-secondary btn-sm px-3 ml-2"><i class="fas fa-redo mr-1"></i> Reset</a>
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
                        <i class="fas fa-tags card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 position-relative">
                    <div class="metric-card card-bg-2">
                        <div class="card-title">Purchases (Period)</div>
                        <div class="card-value"><?php echo number_format($total_purchases, 1); ?></div>
                        <i class="fas fa-download card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 position-relative">
                    <div class="metric-card card-bg-3">
                        <div class="card-title">Cash Sales (Period)</div>
                        <div class="card-value"><?php echo number_format($total_cash_sales, 1); ?></div>
                        <i class="fas fa-money-bill-wave card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-sm-6 position-relative">
                    <div class="metric-card card-bg-4">
                        <div class="card-title">Credit Sales (Period)</div>
                        <div class="card-value"><?php echo number_format($total_credit_sales, 1); ?></div>
                        <i class="fas fa-file-invoice card-icon"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-sm-12 position-relative">
                    <div class="metric-card card-bg-5">
                        <div class="card-title">Current Valuation (Rs.)</div>
                        <div class="card-value">Rs. <?php echo number_format($total_valuation, 2); ?></div>
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
                                    <th rowspan="2" style="border-bottom:none;">Product Name</th>
                                    <th colspan="3" class="bg-dark text-white text-center py-2" style="font-size:11px; letter-spacing:0.5px;">LIFETIME QUANTITY</th>
                                    <th colspan="4" class="bg-secondary text-white text-center py-2" style="font-size:11px; letter-spacing:0.5px;">
                                        <?php if (!empty($fromDate) && !empty($toDate)): ?>
                                            PERIOD QUANTITY (FROM: <?php echo date("d-m-Y", strtotime($fromDate)); ?> TO: <?php echo date("d-m-Y", strtotime($toDate)); ?>)
                                        <?php else: ?>
                                            PERIOD QUANTITY (ALL-TIME SUMMARY)
                                        <?php endif; ?>
                                    </th>
                                    <th rowspan="2" style="border-bottom:none;">Selling Price</th>
                                    <th rowspan="2" style="border-bottom:none;">Stock Value</th>
                                    <th rowspan="2" style="border-bottom:none; min-width:140px;">Actions</th>
                                </tr>
                                <tr>
                                    <th>Total Purchases</th>
                                    <th>Total Sales</th>
                                    <th style="background:#0072ff !important;">Current Stock</th>
                                    <th>Purchases</th>
                                    <th>Cash Sales</th>
                                    <th>Credit Sales</th>
                                    <th style="background:#28a745 !important;">Total Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($products_data as $row) {
                                    $current_stock = $row['current_stock'];
                                    
                                    // Stock Level highlight
                                    if ($current_stock >= 50) {
                                        $stock_badge = '<span class="stock-badge-high">' . number_format($current_stock, 1) . '</span>';
                                    } elseif ($current_stock >= 10) {
                                        $stock_badge = '<span class="stock-badge-mid">' . number_format($current_stock, 1) . '</span>';
                                    } else {
                                        $stock_badge = '<span class="stock-badge-low">' . number_format($current_stock, 1) . '</span>';
                                    }
                                    
                                    $period_total_sales = $row['period_cash_sold'] + $row['period_credit_sold'];
                                    
                                    echo '
                                        <tr>
                                            <td class="text-left font-weight-bold" style="color:var(--primary-color);">' . htmlspecialchars($row['name']) . '</td>
                                            <td>' . number_format($row['lifetime_purchased'], 1) . '</td>
                                            <td>' . number_format($row['lifetime_sold'], 1) . '</td>
                                            <td>' . $stock_badge . '</td>
                                            <td>' . number_format($row['period_purchased'], 1) . '</td>
                                            <td class="text-success">' . number_format($row['period_cash_sold'], 1) . '</td>
                                            <td class="text-warning font-weight-bold">' . number_format($row['period_credit_sold'], 1) . '</td>
                                            <td class="font-weight-bold" style="background:#f4fbf4; color:#28a745;">' . number_format($period_total_sales, 1) . '</td>
                                            <td>' . number_format($row['price'], 2) . '</td>
                                            <td class="font-weight-bold" style="background:#f0f5ff; color:#0052cc;">Rs. ' . number_format($row['stock_value'], 2) . '</td>
                                            <td>
                                                <button class="btn btn-outline-info btn-xs py-1 px-2 font-weight-bold" style="font-size:11px; border-radius:5px;" onclick="viewLedger(' . $row['id'] . ', \'' . addslashes(htmlspecialchars($row['name'])) . '\')">
                                                    <i class="fas fa-receipt mr-1"></i> Ledger
                                                </button>
                                                <a href="add-purchase.php" class="btn btn-outline-success btn-xs py-1 px-2 font-weight-bold ml-1" style="font-size:11px; border-radius:5px;">
                                                    <i class="fas fa-plus mr-1"></i> Stock
                                                </a>
                                            </td>
                                        </tr>
                                    ';
                                }
                                ?>
                            </tbody>
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
