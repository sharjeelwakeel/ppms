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
    </style>
</head>
<body>
    <?php include('include/navbar.php'); ?>
    
    <main class="main">
        <div class="container-fluid px-lg-5 pt-4 pb-5">
            
            <!-- Page Header -->
            <div class="row mb-4 align-items-center">
                <div class="col-md-7">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-tachometer-alt mr-2 text-primary"></i>Operations &amp; Revenue Dashboard
                    </h4>
                    <p class="text-muted small mb-0">Live fuel throughput, meter reading daily net sales, and inventory restock monitor.</p>
                </div>
                <div class="col-md-5 text-md-right mt-3 mt-md-0">
                    <a href="meter-readings/meter-reading-list.php" class="btn btn-outline-primary btn-sm font-weight-bold mr-2" style="border-radius:6px;">
                        <i class="fas fa-list-alt mr-1"></i> Meter Readings
                    </a>
                    <?php if (has_permission('meter_readings', 'add')): ?>
                    <a href="meter-readings/add-meter-reading.php" class="btn btn-primary btn-sm font-weight-bold" style="border-radius:6px;">
                        <i class="fas fa-plus mr-1"></i> New Reading
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TOP KPI ROW: Revenue Sourced From Dispenser Meter Readings -->
            <div class="row">
                <!-- 1. Overall Today's Revenue -->
                <div class="col-xl-3 col-md-6 col-sm-12">
                    <div class="dashboard-card card-bg-primary">
                        <div class="card-title">Today's Meter Revenue</div>
                        <div class="card-value">Rs. <?php echo number_format($today_stats['revenue'], 2); ?></div>
                        <div class="card-subtext">
                            <i class="fas fa-clock mr-1"></i>
                            <?php if ($today_stats['shifts_count'] > 0): ?>
                                <?php echo $today_stats['shifts_count']; ?> Shift<?php echo $today_stats['shifts_count'] > 1 ? 's' : ''; ?> Closed Today
                            <?php else: ?>
                                Shift Active / Awaiting Close
                            <?php endif; ?>
                        </div>
                        <i class="fas fa-cash-register card-icon"></i>
                    </div>
                </div>

                <!-- 2. Overall Today's Fuel Volume Pumped -->
                <div class="col-xl-3 col-md-6 col-sm-12">
                    <div class="dashboard-card card-bg-success">
                        <div class="card-title">Today's Net Fuel Volume</div>
                        <div class="card-value"><?php echo number_format($today_stats['litres'], 2); ?> <span style="font-size:18px; font-weight:500;">Ltr</span></div>
                        <div class="card-subtext">
                            <i class="fas fa-gas-pump mr-1"></i> Net fuel dispensed across nozzles
                        </div>
                        <i class="fas fa-oil-can card-icon"></i>
                    </div>
                </div>

                <!-- 3. 7-Day Cumulative Revenue -->
                <div class="col-xl-3 col-md-6 col-sm-12">
                    <div class="dashboard-card card-bg-info">
                        <div class="card-title">7-Day Total Revenue</div>
                        <div class="card-value">Rs. <?php echo number_format($seven_days['total_revenue'], 2); ?></div>
                        <div class="card-subtext">
                            <i class="fas fa-calendar-alt mr-1"></i> <?php echo $seven_days['labels'][0]; ?> — <?php echo end($seven_days['labels']); ?>
                        </div>
                        <i class="fas fa-chart-line card-icon"></i>
                    </div>
                </div>

                <!-- 4. 7-Day Daily Average -->
                <div class="col-xl-3 col-md-6 col-sm-12">
                    <div class="dashboard-card card-bg-warning">
                        <div class="card-title">7-Day Daily Average</div>
                        <div class="card-value">Rs. <?php echo number_format($seven_days['avg_daily_revenue'], 2); ?></div>
                        <div class="card-subtext">
                            <i class="fas fa-balance-scale mr-1"></i> Avg net sales per day
                        </div>
                        <i class="fas fa-coins card-icon"></i>
                    </div>
                </div>
            </div>

            <!-- MAIN SECTION: 7-DAY NET SALES BAR CHART & PERFORMANCE HIGHLIGHTS -->
            <div class="row">
                <!-- 7-Day Net Sales Bar Chart -->
                <div class="col-lg-8 mb-4">
                    <div class="chart-card h-100">
                        <div class="chart-card-header">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:var(--primary-color);">
                                    <i class="fas fa-chart-bar mr-2 text-primary"></i>7-Day Net Sales Revenue (Meter Readings)
                                </h5>
                                <span class="text-muted small">Daily net fuel sales turnover from closed shifts</span>
                            </div>
                            <span class="badge badge-light border text-navy font-weight-bold px-2 py-1" style="color:var(--primary-color);">
                                <i class="fas fa-calendar-check mr-1 text-primary"></i>Past 7 Days
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 310px; width: 100%;">
                                <canvas id="revenueBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7-Day Performance Snapshot -->
                <div class="col-lg-4 mb-4">
                    <div class="chart-card h-100">
                        <div class="chart-card-header">
                            <h5 class="mb-0 font-weight-bold" style="color:var(--primary-color);">
                                <i class="fas fa-trophy mr-2 text-warning"></i>7-Day Performance
                            </h5>
                            <span class="badge badge-success text-white font-weight-bold px-2 py-1">Audited</span>
                        </div>
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <!-- Peak Sales Day -->
                                <div class="kpi-highlight-item" style="border-left: 4px solid var(--primary-color);">
                                    <div class="text-muted small text-uppercase font-weight-bold mb-1">Peak Sales Day</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="font-weight-bold" style="color:var(--primary-color); font-size:16px;">
                                            <?php echo htmlspecialchars($seven_days['peak_day']['label']); ?>
                                        </div>
                                        <div class="text-success font-weight-bold" style="font-size:16px;">
                                            Rs. <?php echo number_format($seven_days['peak_day']['revenue'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <?php echo number_format($seven_days['peak_day']['litres'], 2); ?> Litres dispensed
                                    </div>
                                </div>

                                <!-- 7-Day Volume -->
                                <div class="kpi-highlight-item" style="border-left: 4px solid #28a745;">
                                    <div class="text-muted small text-uppercase font-weight-bold mb-1">7-Day Total Fuel Dispensed</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="font-weight-bold text-dark" style="font-size:16px;">
                                            <?php echo number_format($seven_days['total_litres'], 2); ?> Ltr
                                        </div>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Meter Flow</span>
                                    </div>
                                    <div class="small text-muted mt-1">Sum of net sales across all nozzles</div>
                                </div>

                                <!-- Average Daily Revenue -->
                                <div class="kpi-highlight-item" style="border-left: 4px solid #ffc107;">
                                    <div class="text-muted small text-uppercase font-weight-bold mb-1">Expected Daily Yield</div>
                                    <div class="font-weight-bold text-dark" style="font-size:16px;">
                                        Rs. <?php echo number_format($seven_days['avg_daily_revenue'], 2); ?> / day
                                    </div>
                                    <div class="small text-muted mt-1">Rolling average based on last 7 days</div>
                                </div>
                            </div>

                            <div class="mt-3 text-center">
                                <a href="meter-readings/meter-reading-list.php" class="btn btn-outline-primary btn-block font-weight-bold shadow-sm" style="border-radius: 8px;">
                                    <i class="fas fa-search mr-1"></i> View All Shift Meter Logs
                                </a>
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
                <div class="ml-3 d-none d-md-block">
                    <a href="lubricants/stock-report.php" class="btn btn-sm btn-outline-danger font-weight-bold" style="border-radius:6px;">
                        <i class="fas fa-chart-bar mr-1"></i> Full Stock Report
                    </a>
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
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="restockTable" class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 50px; text-align:center;">#</th>
                                    <th>Product Name</th>
                                    <th style="text-align:center;">Current Stock</th>
                                    <th style="text-align:center;">Reorder Level</th>
                                    <th style="text-align:center;">Deficit to Order</th>
                                    <th style="text-align:center;">Status</th>
                                    <th style="text-align:center; width:160px;">Action</th>
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
                                        <td class="text-center">
                                            <a href="lubricants/add-purchase.php?product_id=' . $item['id'] . '" class="btn btn-primary btn-sm px-2 py-1 font-weight-bold" style="border-radius:6px; font-size:12px;">
                                                <i class="fas fa-plus mr-1"></i> Add Purchase
                                            </a>
                                        </td>
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