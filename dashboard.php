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

// 3. Fetch 7-Day Fuel Sales Breakdown by Payment Channel (Cash, Credit & Card)
$seven_days_payments = get_seven_days_fuel_payment_breakdown($connection);

// 4. Fetch Multi-Period Analytics Data (Days, Week, Month) for all 4 Dashboard Charts
$multi_charts_data = get_dashboard_multi_period_charts_data($connection);
$init_days_data    = $multi_charts_data['days'];

// 5. Fetch ONLY products that need restocking (Current Stock <= Reorder Level or <= 0)
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
            display: flex;
            flex-direction: column;
            height: calc(100% - 24px);
        }
        .chart-card .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
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
            
            <!-- Page Header with Period Filter (Days, Week, Month) -->
            <div class="row mb-4 align-items-center">
                <div class="col-md-7 col-12 mb-3 mb-md-0">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-tachometer-alt mr-2 text-primary"></i>Operations &amp; Revenue Dashboard
                    </h4>
                    <p class="text-muted small mb-0">Live fuel throughput, multi-period analytics, and inventory restock monitor.</p>
                </div>
                <div class="col-md-5 col-12 text-md-right text-left">
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-outline-primary dropdown-toggle font-weight-bold px-3 py-2 shadow-sm d-inline-flex align-items-center" type="button" id="periodFilterDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="border-color: #04204e; color: #04204e; background: #ffffff; border-radius: 8px;">
                            <i class="fas fa-calendar-alt mr-2" style="color:#04204e;"></i>Period: <span id="currentPeriodLabel" class="ml-1 font-weight-bold">Days</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right shadow border-0" aria-labelledby="periodFilterDropdown" style="border-radius: 8px; min-width: 170px;">
                            <h6 class="dropdown-header text-uppercase font-weight-bold small text-muted">Select Period</h6>
                            <a class="dropdown-item period-select-btn active font-weight-bold py-2" href="javascript:void(0);" data-period="days" data-label="Days">
                                <i class="fas fa-calendar-day mr-2 text-primary"></i>Days
                            </a>
                            <a class="dropdown-item period-select-btn font-weight-bold py-2" href="javascript:void(0);" data-period="weeks" data-label="Week">
                                <i class="fas fa-calendar-week mr-2 text-info"></i>Week
                            </a>
                            <a class="dropdown-item period-select-btn font-weight-bold py-2" href="javascript:void(0);" data-period="months" data-label="Month">
                                <i class="fas fa-calendar mr-2 text-success"></i>Month
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS SECTION: 4 CHARTS IN 2x2 INLINE GRID -->
            <!-- ROW 1: Fuel Net Sales & Sales by Payment Channel -->
            <div class="row">
                <!-- Top-Left: Fuel Net Sales Bar Chart -->
                <div class="col-xl-6 col-lg-6 col-12 mb-4">
                    <div class="chart-card">
                        <div class="chart-card-header flex-wrap">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:var(--primary-color);">
                                    <i class="fas fa-chart-bar mr-2 text-primary"></i>Fuel Net Sales
                                </h5>
                                <span class="text-muted small">Net fuel sales turnover and volume from closed shifts</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mt-2 mt-sm-0">
                                <span class="badge badge-light border text-navy font-weight-bold px-2 py-1 period-badge" style="color:var(--primary-color);">
                                    <i class="fas fa-calendar-check mr-1 text-primary"></i><span class="period-text">Days</span>
                                </span>
                                <span class="badge badge-primary px-2 py-1 text-white ml-2" id="fuelSalesTotalBadge" style="background:#04204e; font-size:11.5px;">
                                    Total: Rs. <?php echo number_format($init_days_data['total_fuel_revenue'], 2); ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 320px; width: 100%;">
                                <canvas id="revenueBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top-Right: Fuel Sales by Payment Channel (Cash, Credit & Card) -->
                <div class="col-xl-6 col-lg-6 col-12 mb-4">
                    <div class="chart-card">
                        <div class="chart-card-header flex-wrap">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:var(--primary-color);">
                                    <i class="fas fa-money-check-alt mr-2 text-primary"></i>Sales by Payment Channel
                                </h5>
                                <span class="text-muted small">Fuel sales split across Cash, Credit &amp; Card</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mt-2 mt-sm-0">
                                <span class="badge badge-light border text-navy font-weight-bold px-2 py-1 period-badge mr-2" style="color:var(--primary-color);">
                                    <i class="fas fa-calendar-check mr-1 text-primary"></i><span class="period-text">Days</span>
                                </span>
                                <span id="cashBadge" class="badge px-2 py-1 mr-1 text-white" style="background:#2e7d32; font-size:11.5px;" title="Cash: Rs. <?php echo number_format($init_days_data['total_cash'], 2); ?>">
                                    <i class="fas fa-money-bill-wave mr-1"></i>Cash: <?php echo $init_days_data['cash_percentage']; ?>%
                                </span>
                                <span id="creditBadge" class="badge px-2 py-1 mr-1 text-white" style="background:#f57c00; font-size:11.5px;" title="Credit: Rs. <?php echo number_format($init_days_data['total_credit'], 2); ?>">
                                    <i class="fas fa-file-invoice mr-1"></i>Credit: <?php echo $init_days_data['credit_percentage']; ?>%
                                </span>
                                <span id="cardBadge" class="badge px-2 py-1 text-white" style="background:#04204e; font-size:11.5px;" title="Card: Rs. <?php echo number_format($init_days_data['total_card'], 2); ?>">
                                    <i class="fas fa-credit-card mr-1"></i>Card: <?php echo $init_days_data['card_percentage']; ?>%
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 320px; width: 100%;">
                                <canvas id="paymentChannelsBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 2: Station Operating Expenses & Product / Lubricant Sales -->
            <div class="row">
                <!-- Bottom-Left: Station Operating Expenses Bar Chart -->
                <div class="col-xl-6 col-lg-6 col-12 mb-4">
                    <div class="chart-card">
                        <div class="chart-card-header flex-wrap">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#c62828;">
                                    <i class="fas fa-receipt mr-2 text-danger"></i>Station Operating Expenses
                                </h5>
                                <span class="text-muted small">Periodic operational expenditures across all categories</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mt-2 mt-sm-0">
                                <span class="badge badge-light border text-danger font-weight-bold px-2 py-1 period-badge" style="color:#c62828;">
                                    <i class="fas fa-calendar-check mr-1 text-danger"></i><span class="period-text">Days</span>
                                </span>
                                <span id="expenseTotalBadge" class="badge badge-danger px-2 py-1 text-white ml-2" style="background:#c62828; font-size:11.5px;">
                                    <i class="fas fa-wallet mr-1"></i>Total: Rs. <?php echo number_format($init_days_data['total_expenses'], 2); ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 320px; width: 100%;">
                                <canvas id="expensesBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom-Right: Product & Lubricant Sales Bar Chart -->
                <div class="col-xl-6 col-lg-6 col-12 mb-4">
                    <div class="chart-card">
                        <div class="chart-card-header flex-wrap">
                            <div>
                                <h5 class="mb-0 font-weight-bold" style="color:#4527a0;">
                                    <i class="fas fa-oil-can mr-2" style="color:#4527a0;"></i>Product &amp; Lubricant Sales
                                </h5>
                                <span class="text-muted small">Periodic packaged lubricants and motor oil turnover</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mt-2 mt-sm-0">
                                <span class="badge badge-light border font-weight-bold px-2 py-1 period-badge" style="color:#4527a0;">
                                    <i class="fas fa-calendar-check mr-1" style="color:#4527a0;"></i><span class="period-text">Days</span>
                                </span>
                                <span id="productTotalBadge" class="badge px-2 py-1 text-white ml-2" style="background:#4527a0; font-size:11.5px;">
                                    <i class="fas fa-shopping-bag mr-1"></i>Total: Rs. <?php echo number_format($init_days_data['total_product_sales'], 2); ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div style="position: relative; height: 320px; width: 100%;">
                                <canvas id="productSalesBarChart"></canvas>
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

        // -------------------------------------------------------------
        // Multi-Period Data Store for All 4 Dashboard Charts
        // Periods: 'days' (Days), 'weeks' (Week), 'months' (Month)
        // -------------------------------------------------------------
        window.dashboardChartsData = <?php echo json_encode($multi_charts_data); ?>;
        let activePeriod = 'days';

        function formatCurrency(val) {
            return (val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Color Tokens
        const primaryNavy  = '#04204e';
        const hoverNavy    = '#07347a';
        const greenCash    = '#2e7d32';
        const orangeCredit = '#f57c00';
        const dangerRed    = '#c62828';
        const hoverRed     = '#b71c1c';
        const purpleProd   = '#4527a0';
        const hoverPurple  = '#311b92';

        // 1. Initialize Chart 1: Fuel Net Sales Bar Chart
        let fuelChart = null;
        const ctxFuel = document.getElementById('revenueBarChart');
        if (ctxFuel && window.dashboardChartsData) {
            fuelChart = new Chart(ctxFuel, {
                type: 'bar',
                data: {
                    labels: window.dashboardChartsData.days.labels,
                    datasets: [{
                        label: 'Net Fuel Revenue (PKR)',
                        data: window.dashboardChartsData.days.fuel_revenue,
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
                        legend: { display: false },
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
                                    const currentData = window.dashboardChartsData[activePeriod];
                                    const ltr = (currentData && currentData.fuel_litres && currentData.fuel_litres[index]) || 0;
                                    return [
                                        ' Revenue: PKR ' + formatCurrency(val),
                                        ' Volume:  ' + formatCurrency(ltr) + ' Ltr'
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { font: { size: 12, weight: 'bold', family: 'Roboto' }, color: '#4a5568' }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(226, 232, 240, 0.8)', drawBorder: false },
                            ticks: {
                                font: { size: 11, family: 'Roboto' },
                                color: '#718096',
                                callback: function(value) {
                                    if (value >= 1000000) return 'Rs. ' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return 'Rs. ' + (value / 1000).toFixed(0) + 'k';
                                    return 'Rs. ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        // 2. Initialize Chart 2: Fuel Sales by Payment Channel Grouped Bar Chart
        let paymentChart = null;
        const ctxPayment = document.getElementById('paymentChannelsBarChart');
        if (ctxPayment && window.dashboardChartsData) {
            paymentChart = new Chart(ctxPayment, {
                type: 'bar',
                data: {
                    labels: window.dashboardChartsData.days.labels,
                    datasets: [
                        {
                            label: 'Cash Sale',
                            data: window.dashboardChartsData.days.cash_series,
                            backgroundColor: 'rgba(46, 125, 50, 0.85)',
                            borderColor: greenCash,
                            borderWidth: 1.5,
                            borderRadius: 5,
                            borderSkipped: false,
                            hoverBackgroundColor: '#1b5e20',
                            barPercentage: 0.78,
                            categoryPercentage: 0.72
                        },
                        {
                            label: 'Credit Sale',
                            data: window.dashboardChartsData.days.credit_series,
                            backgroundColor: 'rgba(245, 124, 0, 0.85)',
                            borderColor: orangeCredit,
                            borderWidth: 1.5,
                            borderRadius: 5,
                            borderSkipped: false,
                            hoverBackgroundColor: '#e65100',
                            barPercentage: 0.78,
                            categoryPercentage: 0.72
                        },
                        {
                            label: 'Card Sale',
                            data: window.dashboardChartsData.days.card_series,
                            backgroundColor: 'rgba(4, 32, 78, 0.85)',
                            borderColor: primaryNavy,
                            borderWidth: 1.5,
                            borderRadius: 5,
                            borderSkipped: false,
                            hoverBackgroundColor: hoverNavy,
                            barPercentage: 0.78,
                            categoryPercentage: 0.72
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                boxWidth: 14,
                                font: { size: 12, weight: 'bold', family: 'Roboto' },
                                color: '#4a5568',
                                padding: 16
                            }
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
                                    const dsLabel = context.dataset.label || '';
                                    return ' ' + dsLabel + ': PKR ' + formatCurrency(val);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { font: { size: 12, weight: 'bold', family: 'Roboto' }, color: '#4a5568' }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(226, 232, 240, 0.8)', drawBorder: false },
                            ticks: {
                                font: { size: 11, family: 'Roboto' },
                                color: '#718096',
                                callback: function(value) {
                                    if (value >= 1000000) return 'Rs. ' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return 'Rs. ' + (value / 1000).toFixed(0) + 'k';
                                    return 'Rs. ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        // 3. Initialize Chart 3: Station Operating Expenses Bar Chart
        let expenseChart = null;
        const ctxExpense = document.getElementById('expensesBarChart');
        if (ctxExpense && window.dashboardChartsData) {
            expenseChart = new Chart(ctxExpense, {
                type: 'bar',
                data: {
                    labels: window.dashboardChartsData.days.labels,
                    datasets: [{
                        label: 'Operating Expenses (PKR)',
                        data: window.dashboardChartsData.days.expense_series,
                        backgroundColor: 'rgba(198, 40, 40, 0.85)',
                        borderColor: dangerRed,
                        borderWidth: 1.5,
                        borderRadius: 6,
                        borderSkipped: false,
                        hoverBackgroundColor: hoverRed,
                        barPercentage: 0.62,
                        categoryPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#04204e',
                            titleFont: { size: 13, weight: 'bold', family: 'Roboto' },
                            bodyFont: { size: 12.5, family: 'Roboto' },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const val = context.parsed.y || 0;
                                    return ' Expense: PKR ' + formatCurrency(val);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { font: { size: 12, weight: 'bold', family: 'Roboto' }, color: '#4a5568' }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(226, 232, 240, 0.8)', drawBorder: false },
                            ticks: {
                                font: { size: 11, family: 'Roboto' },
                                color: '#718096',
                                callback: function(value) {
                                    if (value >= 1000000) return 'Rs. ' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return 'Rs. ' + (value / 1000).toFixed(0) + 'k';
                                    return 'Rs. ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        // 4. Initialize Chart 4: Product & Lubricant Sales Bar Chart
        let productChart = null;
        const ctxProduct = document.getElementById('productSalesBarChart');
        if (ctxProduct && window.dashboardChartsData) {
            productChart = new Chart(ctxProduct, {
                type: 'bar',
                data: {
                    labels: window.dashboardChartsData.days.labels,
                    datasets: [{
                        label: 'Product Sales (PKR)',
                        data: window.dashboardChartsData.days.product_sales_series,
                        backgroundColor: 'rgba(69, 39, 160, 0.85)',
                        borderColor: purpleProd,
                        borderWidth: 1.5,
                        borderRadius: 6,
                        borderSkipped: false,
                        hoverBackgroundColor: hoverPurple,
                        barPercentage: 0.62,
                        categoryPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
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
                                    const currentData = window.dashboardChartsData[activePeriod];
                                    const qty = (currentData && currentData.product_qty_series && currentData.product_qty_series[index]) || 0;
                                    return [
                                        ' Sales: PKR ' + formatCurrency(val),
                                        ' Units Sold: ' + formatCurrency(qty)
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { font: { size: 12, weight: 'bold', family: 'Roboto' }, color: '#4a5568' }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(226, 232, 240, 0.8)', drawBorder: false },
                            ticks: {
                                font: { size: 11, family: 'Roboto' },
                                color: '#718096',
                                callback: function(value) {
                                    if (value >= 1000000) return 'Rs. ' + (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return 'Rs. ' + (value / 1000).toFixed(0) + 'k';
                                    return 'Rs. ' + value;
                                }
                            }
                        }
                    }
                }
            });
        }

        // 5. Dynamic Multi-Period Switcher Handler
        function updateChartsForPeriod(periodKey, periodDisplay) {
            if (!window.dashboardChartsData || !window.dashboardChartsData[periodKey]) return;
            activePeriod = periodKey;
            const pData = window.dashboardChartsData[periodKey];

            // Update header label and card badges (Strictly "Days", "Week", "Month" without numbers)
            $('#currentPeriodLabel').text(periodDisplay);
            $('.period-text').text(periodDisplay);

            // Update Chart 1: Fuel Net Sales
            if (fuelChart) {
                fuelChart.data.labels = pData.labels;
                fuelChart.data.datasets[0].data = pData.fuel_revenue;
                fuelChart.update();
            }
            $('#fuelSalesTotalBadge').text('Total: Rs. ' + formatCurrency(pData.total_fuel_revenue));

            // Update Chart 2: Payment Channels
            if (paymentChart) {
                paymentChart.data.labels = pData.labels;
                paymentChart.data.datasets[0].data = pData.cash_series;
                paymentChart.data.datasets[1].data = pData.credit_series;
                paymentChart.data.datasets[2].data = pData.card_series;
                paymentChart.update();
            }
            $('#cashBadge')
                .html('<i class="fas fa-money-bill-wave mr-1"></i>Cash: ' + pData.cash_percentage + '%')
                .attr('title', 'Cash: Rs. ' + formatCurrency(pData.total_cash));
            $('#creditBadge')
                .html('<i class="fas fa-file-invoice mr-1"></i>Credit: ' + pData.credit_percentage + '%')
                .attr('title', 'Credit: Rs. ' + formatCurrency(pData.total_credit));
            $('#cardBadge')
                .html('<i class="fas fa-credit-card mr-1"></i>Card: ' + pData.card_percentage + '%')
                .attr('title', 'Card: Rs. ' + formatCurrency(pData.total_card));

            // Update Chart 3: Operating Expenses
            if (expenseChart) {
                expenseChart.data.labels = pData.labels;
                expenseChart.data.datasets[0].data = pData.expense_series;
                expenseChart.update();
            }
            $('#expenseTotalBadge').html('<i class="fas fa-wallet mr-1"></i>Total: Rs. ' + formatCurrency(pData.total_expenses));

            // Update Chart 4: Product Sales
            if (productChart) {
                productChart.data.labels = pData.labels;
                productChart.data.datasets[0].data = pData.product_sales_series;
                productChart.update();
            }
            $('#productTotalBadge').html('<i class="fas fa-shopping-bag mr-1"></i>Total: Rs. ' + formatCurrency(pData.total_product_sales));
        }

        // Attach click events to period dropdown options
        $('.period-select-btn').on('click', function(e) {
            e.preventDefault();
            const pKey = $(this).data('period');
            const pLabel = $(this).data('label');
            $('.period-select-btn').removeClass('active');
            $(this).addClass('active');
            updateChartsForPeriod(pKey, pLabel);
        });
    });
    </script>
</body>
</html>