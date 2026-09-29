<?php
require 'include/session.php';
if (!userloggedin()) {
    header('Location:login.php');
    exit;
}
require 'include/config.php';
require 'include/permissions.php';
require 'include/dashboard_helper.php';

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

// 1. Fetch Today's Overall Revenue & Litres from Meter Readings
$today_stats = get_today_meter_revenue($connection);

// 2. Fetch 7-Day Net Sales and Volume Stats for Chart.js Bar Chart
$seven_days = get_seven_days_revenue_stats($connection);

// 3. Fetch ONLY products that need restocking (Current Stock <= Reorder Level or <= 0)
$restock_items = get_restock_needed_products($connection);
$restock_count = count($restock_items);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Executive Dashboard</title>

    <link rel="stylesheet" href="include/css/roboto.css">
    <link rel="stylesheet" href="include/css/bootstrap.min.css">
    <link rel="stylesheet" href="include/css/all.min.css">
    <link rel="stylesheet" href="include/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="include/style.css?v=1.0.1" />
    <style>
        body {
            background: #f4f6fb;
            font-family: 'Roboto', sans-serif;
        }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .dashboard-card {
            border-radius: 12px;
            border: none;
            color: #fff;
            padding: 20px 22px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.08);
            margin-bottom: 22px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .dashboard-card .card-title {
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            opacity: 0.88;
            margin-bottom: 8px;
            font-weight: 500;
        }
        .dashboard-card .card-value {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.2;
        }
        .dashboard-card .card-subtext {
            font-size: 12px;
            opacity: 0.85;
            margin-top: 6px;
        }
        .dashboard-card .card-icon {
            position: absolute;
            right: 18px;
            bottom: 15px;
            font-size: 42px;
            opacity: 0.18;
        }
        .card-bg-primary { background: linear-gradient(135deg, #04204e 0%, #07347a 100%); }
        .card-bg-success { background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%); }
        .card-bg-info    { background: linear-gradient(135deg, #0288d1 0%, #01579b 100%); }
        .card-bg-warning { background: linear-gradient(135deg, #e65100 0%, #f57c00 100%); }
        .card-bg-danger  { background: linear-gradient(135deg, #b71c1c 0%, #d32f2f 100%); }

        .chart-card {
            border-radius: 12px;
            border: none;
            background: #ffffff;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .chart-card-header {
            padding: 16px 22px;
            background: #ffffff;
            border-bottom: 1px solid #edf2f7;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-highlight-item {
            padding: 12px 16px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
            margin-bottom: 12px;
        }

        #restockTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            font-size: 13px;
        }
        #restockTable,
        #restockTable th,
        #restockTable td {
            box-sizing: border-box !important;
        }
        #restockTable {
            width: 100% !important;
            margin: 0 !important;
        }
        #restockTable_wrapper {
            width: 100% !important;
            box-sizing: border-box !important;
            overflow-x: hidden;
        }
        .restock-card-body .table-responsive {
            overflow-x: hidden !important;
        }
        @media (max-width: 767.98px) {
            .restock-card-body .table-responsive {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
        }
    </style>
</head>
<body>
    <?php include('include/navbar.php'); ?>
    
    <main class="main">
        <div class="container-fluid px-lg-5 pt-4 pb-5">
            
            <!-- Page Header -->
            <div class="row mb-4 align-items-center">
                <div class="col-12">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-tachometer-alt mr-2 text-primary"></i>Operations &amp; Revenue Dashboard
                    </h4>
                    <p class="text-muted small mb-0">Live fuel throughput, 7-day net sales, and inventory restock monitor.</p>
                </div>
            </div>            <!-- MAIN SECTION: 7-DAY NET SALES BAR CHART -->
            <div class="row">
                <!-- 7-Day Net Sales Bar Chart -->
                <div class="col-12 mb-4">
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:var(--primary-color);">
                                    <i class="fas fa-chart-bar mr-2 text-primary"></i>7-Day Net Sales
                                </h5>
                                <span class="text-muted small">Daily net fuel sales turnover from closed shifts</span>
                            </div>
                            <span class="badge badge-light border text-navy font-weight-bold px-2 py-1" style="color:var(--primary-color);">
                                <i class="fas fa-calendar-check mr-1 text-primary"></i>Past 7 Days
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 320px; width: 100%;">
                                <canvas id="revenueBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STOCK RESTOCK ALERT & TABLE SECTION (Alert and Restock Table Only) -->
            <?php if ($restock_count > 0): ?>
            <!-- Low Stock / Restock Needed Alert Banner -->
            <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-3 p-3" style="border-radius:12px; background:#fff5f5; border-left: 6px solid #dc3545 !important;">
                <div class="mr-3 text-center" style="width:48px; height:48px; border-radius:50%; background:#ffebee; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="mb-1 text-danger font-weight-bold">
                        <i class="fas fa-bell mr-1"></i> Stock Needs Restocking: <?php echo $restock_count; ?> <?php echo $restock_count == 1 ? 'Product is' : 'Products are'; ?> Below Reorder Level!
                    </h5>
                    <span class="text-muted" style="font-size:14px;">The inventory for the products listed below has dropped to or below their designated threshold. Please restock promptly.</span>
                </div>
            </div>

            <!-- Restock Action Table: Shows ONLY products that need restocking -->
            <div class="card shadow-sm border-0 mb-4" style="border-radius:12px; overflow:hidden;">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 font-weight-bold text-danger">
                        <i class="fas fa-boxes mr-2"></i>Products Requiring Urgent Restock (Stock &le; Reorder Level)
                    </h5>
                    <span class="badge badge-danger px-3 py-2" style="font-size:12px; border-radius:20px;">
                        <?php echo $restock_count; ?> Restock Alert<?php echo $restock_count > 1 ? 's' : ''; ?>
                    </span>
                </div>
                <div class="card-body p-3 restock-card-body">
                    <div class="table-responsive border-0 mb-0">
                        <table id="restockTable" class="table table-striped table-bordered mb-0" style="width:100% !important;">
                            <thead>
                                <tr>
                                    <th style="width: 50px; text-align:center;">#</th>
                                    <th>Product Name</th>
                                    <th style="text-align:center;">Current Stock</th>
                                    <th style="text-align:center;">Reorder Level</th>
                                    <th style="text-align:center;">Deficit to Order</th>
                                    <th style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sr = 1;
                                foreach ($restock_items as $item) {
                                    $cur = $item['current_stock'];
                                    $reorder = $item['reorder_level'];
                                    
                                    if ($cur <= 0) {
                                        $status = '<span class="badge badge-dark py-1 px-2"><i class="fas fa-times-circle mr-1 text-danger"></i> Out of Stock</span>';
                                    } else {
                                        $status = '<span class="badge badge-danger py-1 px-2"><i class="fas fa-exclamation-triangle mr-1"></i> Reorder Required</span>';
                                    }
                                    
                                    echo '
                                    <tr>
                                        <td class="text-center font-weight-bold">' . $sr++ . '</td>
                                        <td class="font-weight-bold" style="color:var(--primary-color);">' . htmlspecialchars($item['name']) . '</td>
                                        <td class="text-center">
                                            <span class="badge badge-danger px-2 py-1 font-weight-bold" style="font-size:13px; background:#ffebee; color:#c62828 !important; border:1px solid #ffcdd2;">
                                                ' . number_format($cur, 0) . '
                                            </span>
                                        </td>
                                        <td class="text-center font-weight-bold">' . number_format($reorder, 0) . '</td>
                                        <td class="text-center text-danger font-weight-bold">' . number_format($item['deficit'], 0) . '</td>
                                        <td class="text-center">' . $status . '</td>
                                    </tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- All Healthy Stock Confirmation Alert -->
            <div class="card shadow-sm border-0 mb-4 p-3 text-center" style="border-radius:12px; background:#f8fff9; border: 1px solid #c8e6c9 !important;">
                <div class="d-flex align-items-center justify-content-center text-success py-1">
                    <i class="fas fa-check-circle fa-2x mr-2"></i>
                    <div>
                        <strong class="h6 mb-0 font-weight-bold">All product inventory levels are healthy.</strong>
                        <span class="text-muted ml-2">No products currently require restocking.</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Dependencies -->
    <script src="include/js/jquery.min.js"></script>
    <script src="include/js/popper.min.js"></script>
    <script src="include/js/bootstrap.min.js"></script>
    <script src="include/js/jquery.dataTables.min.js"></script>
    <!-- Chart.js for 7-Day Net Sales Bar Chart (Locally Hosted for Offline Intranet Reliability) -->
    <script src="include/js/chart.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize Restock Table DataTable if present
        if ($('#restockTable').length) {
            $('#restockTable').DataTable({
                "order": [[ 4, "desc" ]], // Order by Deficit descending
                "pageLength": 10,
                "autoWidth": false,
                "language": {
                    "emptyTable": "No items currently require restocking."
                }
            });
        }

        // Initialize 7-Day Net Sales Bar Chart (Chart.js)
        const ctx = document.getElementById('revenueBarChart');
        if (ctx) {
            const chartLabels  = <?php echo json_encode($seven_days['labels']); ?>;
            const chartRevenue = <?php echo json_encode($seven_days['revenues']); ?>;
            const chartLitres  = <?php echo json_encode($seven_days['litres']); ?>;

            // Deep Navy Primary Color Palette (#04204e -> #07347a)
            const primaryNavy = '#04204e';
            const hoverNavy   = '#07347a';

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Net Revenue (PKR)',
                        data: chartRevenue,
                        backgroundColor: 'rgba(4, 32, 78, 0.85)',
                        borderColor: primaryNavy,
                        borderWidth: 1.5,
                        borderRadius: 6,
                        borderSkipped: false,
                        hoverBackgroundColor: hoverNavy,
                        barPercentage: 0.62,
                        categoryPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#04204e',
                            titleFont: { size: 13, weight: 'bold', family: 'Roboto' },
                            bodyFont: { size: 12.5, family: 'Roboto' },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const val = context.parsed.y || 0;
                                    const index = context.dataIndex;
                                    const ltr = chartLitres[index] || 0;
                                    return [
                                        ' Revenue: PKR ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                                        ' Volume:  ' + ltr.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Ltr'
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                font: { size: 12, weight: 'bold', family: 'Roboto' },
                                color: '#4a5568'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(226, 232, 240, 0.8)',
                                drawBorder: false
                            },
                            ticks: {
                                font: { size: 11, family: 'Roboto' },
                                color: '#718096',
                                callback: function(value) {
                                    if (value >= 1000000) {
                                        return 'Rs. ' + (value / 1000000).toFixed(1) + 'M';
                                    } else if (value >= 1000) {
                                        return 'Rs. ' + (value / 1000).toFixed(0) + 'k';
                                    }
                                    return 'Rs. ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>