<?php
/**
 * Customer Overall Monthly Credit Bill / Statement & Registry
 * PPMS (Petrol Pump Management System)
 * 
 * Provides an exact-match statement replicating the official credit bill voucher,
 * filtering strictly for outstanding dues (unpaid & partial vouchers) across fuel
 * and lubricant sales, alongside a comprehensive searchable registry of all issued bills.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/settings_helper.php';
require_once '../include/customer_monthly_bill_helper.php';

// RBAC Authorization Gate
if (!has_permission('reports', 'show') && !has_permission('customers', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

// Handle Soft Delete / Cancellation of Bill from Registry
if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') && isset($_POST['action']) && $_POST['action'] === 'delete_bill') {
    $del_id = intval($_POST['bill_id'] ?? 0);
    if ($del_id > 0) {
        soft_delete_monthly_bill($connection, $del_id);
        header('Location: customer-monthly-bill.php?tab=registry&msg=deleted');
        exit;
    }
}

// Fetch list of customers with dues summary
$all_customers = get_customers_with_dues_summary($connection);

// Active Tab ('generate' or 'registry')
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'registry' ? 'registry' : 'generate';

// Quick Find by Bill Number
$search_bill_no = trim($_GET['search_bill_no'] ?? '');
$search_error   = '';
if (!empty($search_bill_no)) {
    $found_bill = get_monthly_bill_by_number($connection, $search_bill_no);
    if ($found_bill) {
        $_GET['customer_id']    = $found_bill['customer_id'];
        $_GET['from_date']      = $found_bill['from_date'];
        $_GET['to_date']        = $found_bill['to_date'];
        $_GET['vehicle_number'] = $found_bill['vehicle_number'] ?? '';
        $_GET['bill_no']        = $found_bill['bill_no'];
        $active_tab             = 'generate';
    } else {
        $search_error = "No bill found matching Bill # " . htmlspecialchars($search_bill_no);
    }
}

// Inputs and Query Parameters for Statement Generator
$customer_id    = intval($_GET['customer_id'] ?? 0);
$vehicle_number = trim($_GET['vehicle_number'] ?? '');
$from_date      = trim($_GET['from_date'] ?? '');
$to_date        = trim($_GET['to_date'] ?? '');
$bill_no_input  = trim($_GET['bill_no'] ?? '');
$do_generate    = isset($_GET['generate_bill']) || !empty($search_bill_no);

// If dates not set, default to current month
if (empty($from_date) || empty($to_date)) {
    $from_date = date('Y-m-01');
    $to_date   = date('Y-m-t');
}

// Pre-load all registered vehicles grouped by customer for instant client-side dropdown switching
$all_cust_vehicles = [];
$v_res = mysqli_query($connection, "SELECT id, customer_id, vehicle_name, reg_number FROM tbl_customer_vehicles WHERE deleted_at IS NULL ORDER BY reg_number ASC");
if ($v_res) {
    while ($vrow = mysqli_fetch_assoc($v_res)) {
        $all_cust_vehicles[$vrow['customer_id']][] = [
            'id'           => $vrow['id'],
            'vehicle_name' => $vrow['vehicle_name'],
            'reg_number'   => $vrow['reg_number']
        ];
    }
}
$customer_vehicles = $all_cust_vehicles[$customer_id] ?? [];

// Generate bill statement data strictly on-demand when user clicks Generate Bill or Quick Find
$bill_data = null;
$gen_error = '';
if ($do_generate) {
    if ($customer_id > 0 && !empty($from_date) && !empty($to_date)) {
        $bill_data = get_customer_monthly_bill_data($connection, $customer_id, $from_date, $to_date, $vehicle_number, [
            'bill_no' => $bill_no_input
        ]);
    } else {
        $gen_error = "Please select a Customer Account before generating the monthly bill.";
    }
}

// Fetch Registry Bills for Tab 2
$reg_bill_no     = trim($_GET['reg_bill_no'] ?? '');
$reg_customer_id = intval($_GET['reg_customer_id'] ?? 0);
$registry_bills  = get_generated_monthly_bills($connection, [
    'bill_no'     => $reg_bill_no,
    'customer_id' => $reg_customer_id
]);

$reg_total_count   = count($registry_bills);
$reg_total_amount  = array_sum(array_column($registry_bills, 'total_amount'));
$reg_total_coupons = array_sum(array_column($registry_bills, 'total_coupons'));

$page_title = "Customer Overall Monthly Credit Bill";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - PPMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../include/style.css">
    <style>
        .bill-preview-sheet {
            background: #ffffff;
            border: 2px solid #222;
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            padding: 30px;
            max-width: 900px;
            margin: 0 auto;
            color: #111;
            font-family: 'Times New Roman', Times, Georgia, serif;
        }
        .bill-header-title {
            font-size: 24px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #000;
            margin-bottom: 2px;
        }
        .bill-header-sub {
            font-size: 15px;
            font-weight: 600;
            color: #111;
            margin-bottom: 2px;
        }
        .bill-header-dates {
            font-size: 16px;
            font-weight: 700;
            margin-top: 10px;
            margin-bottom: 12px;
            text-decoration: underline;
        }
        .bill-meta-table, .bill-trans-table, .bill-cat-table, .bill-total-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .bill-meta-table td {
            border: 1px solid #111;
            padding: 5px 8px;
            vertical-align: middle;
        }
        .bill-trans-table th, .bill-trans-table td {
            border: 1px solid #111;
            padding: 4px 6px;
            font-size: 13.5px;
        }
        .bill-trans-table th {
            background-color: #f7f7f7;
            font-weight: 700;
            text-align: center;
        }
        .bill-cat-table th, .bill-cat-table td {
            border: 1px solid #111;
            padding: 4px 8px;
            font-size: 13px;
        }
        .bill-total-table td {
            border: 1px solid #111;
            padding: 6px 10px;
            font-size: 14px;
            font-weight: 700;
        }
        .note-text {
            font-size: 11px;
            line-height: 1.35;
            color: #222;
        }
        .filter-card {
            border-radius: 8px;
            border: none;
            box-shadow: 0 4px 14px rgba(4, 32, 78, 0.08);
            background: #ffffff;
        }
        .kpi-card {
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            background: #f8fafc;
            padding: 14px 18px;
            transition: all 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
        }
        .nav-tabs-custom .nav-link {
            font-weight: 600;
            font-size: 14px;
            color: #495057;
            border-radius: 8px 8px 0 0;
            padding: 10px 20px;
        }
        .nav-tabs-custom .nav-link.active {
            background: var(--primary-gradient) !important;
            color: #fff !important;
            border-color: transparent;
        }
    </style>
</head>
<body class="bg-light">
    <?php include_once '../include/navbar.php'; ?>

    <div class="container-fluid px-3 px-md-4 py-4">
        <!-- Page Header -->
        <div class="row align-items-center mb-3">
            <div class="col-md-7">
                <h4 class="mb-1 font-weight-bold text-navy" style="color: var(--primary-color);">
                    <i class="fas fa-file-invoice-dollar mr-2 text-primary"></i>Customer Overall Monthly Credit Bill
                </h4>
                <p class="text-muted small mb-0">
                    Comprehensive credit statement for fuel &amp; products. Filtered strictly for <strong>outstanding dues</strong> (excluding paid slips).
                </p>
            </div>
            <div class="col-md-5 text-md-right mt-2 mt-md-0">
                <?php if ($active_tab === 'generate' && $bill_data && count($bill_data['transactions']) > 0): ?>
                    <a href="generate-pdf-customer-monthly-bill.php?bill_no=<?php echo urlencode($bill_data['bill_no']); ?>" 
                       target="_blank" class="btn btn-success shadow-sm font-weight-bold">
                        <i class="fas fa-print mr-1"></i> Print / Save PDF Bill
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($search_error)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $search_error; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-2"></i> Bill record successfully removed from the registry.
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Quick Find Bill by Number Search Bar -->
        <div class="card mb-3 border-0 shadow-sm" style="border-radius: 8px;">
            <div class="card-body py-2 px-3 bg-white d-flex flex-wrap align-items-center justify-content-between">
                <form method="GET" action="customer-monthly-bill.php" class="form-inline my-1">
                    <input type="hidden" name="tab" value="generate">
                    <label class="font-weight-bold mr-2 text-navy" style="font-size: 13px;">
                        <i class="fas fa-search mr-1 text-primary"></i> Quick Find Bill by Number:
                    </label>
                    <div class="input-group input-group-sm mr-2">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-light font-weight-bold text-dark">Bill #</span>
                        </div>
                        <input type="text" name="search_bill_no" class="form-control font-weight-bold text-primary" 
                               placeholder="e.g. 64343" value="<?php echo htmlspecialchars($search_bill_no); ?>" style="width: 140px;" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm px-3 font-weight-bold" style="background:var(--primary-gradient); border:none;">
                        <i class="fas fa-search mr-1"></i> Find Bill
                    </button>
                </form>

                <div class="my-1 small text-muted">
                    <i class="fas fa-info-circle mr-1 text-info"></i> Enter any bill number to instantly preview and re-print that statement.
                </div>
            </div>
        </div>

        <?php if (!empty($gen_error)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $gen_error; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs nav-tabs-custom mb-4" id="billTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo ($active_tab === 'generate') ? 'active' : ''; ?>" 
                   href="customer-monthly-bill.php?tab=generate<?php echo $customer_id ? '&customer_id='.$customer_id : ''; ?>">
                    <i class="fas fa-file-invoice mr-2"></i>Generate Monthly Bill
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($active_tab === 'registry') ? 'active' : ''; ?>" 
                   href="customer-monthly-bill.php?tab=registry">
                    <i class="fas fa-history mr-2"></i>Generated Bills Registry
                    <span class="badge badge-light border ml-1 font-weight-bold text-dark"><?php echo $reg_total_count; ?></span>
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'generate'): ?>
            <!-- TAB 1: GENERATE MONTHLY BILL -->
            
            <!-- Filter Controls Card -->
            <div class="card filter-card mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold text-navy"><i class="fas fa-filter mr-2 text-primary"></i>Bill Statement Parameters</span>
                    <span class="badge badge-danger text-white font-weight-bold px-2 py-1"><i class="fas fa-hand-holding-usd mr-1"></i>Demand Bill: Unpaid Dues Only</span>
                </div>
                <div class="card-body">
                    <form method="GET" action="customer-monthly-bill.php" id="billFilterForm">
                        <input type="hidden" name="tab" value="generate">
                        <div class="form-row align-items-end">
                            <!-- Customer Dropdown (Name Only) -->
                            <div class="col-lg-3 col-md-6 col-sm-12 mb-3">
                                <label class="font-weight-bold text-secondary small mb-1">
                                    <i class="fas fa-user mr-1 text-primary"></i>Customer Account <span class="text-danger">*</span>
                                </label>
                                <select name="customer_id" id="customer_id" class="form-control select2" required>
                                    <option value="">-- Select Customer --</option>
                                    <?php foreach ($all_customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo ($customer_id === $c['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Date Range From -->
                            <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
                                <label class="font-weight-bold text-secondary small mb-1">
                                    <i class="fas fa-calendar-alt mr-1 text-primary"></i>From Date
                                </label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>" required>
                            </div>

                            <!-- Date Range To -->
                            <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
                                <label class="font-weight-bold text-secondary small mb-1">
                                    <i class="fas fa-calendar-alt mr-1 text-primary"></i>To Date
                                </label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>" required>
                            </div>

                            <!-- Vehicle Filter -->
                            <div class="col-lg-2 col-md-6 col-sm-6 mb-3">
                                <label class="font-weight-bold text-secondary small mb-1">
                                    <i class="fas fa-car mr-1 text-primary"></i>Vehicle (Optional)
                                </label>
                                <select name="vehicle_number" id="vehicle_number" class="form-control">
                                    <option value="">All Vehicles</option>
                                    <?php if (!empty($customer_vehicles)): ?>
                                        <?php foreach ($customer_vehicles as $v): ?>
                                            <option value="<?php echo htmlspecialchars($v['reg_number']); ?>" <?php echo ($vehicle_number === $v['reg_number']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($v['reg_number']); ?> (<?php echo htmlspecialchars($v['vehicle_name']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php elseif (!empty($vehicle_number)): ?>
                                        <option value="<?php echo htmlspecialchars($vehicle_number); ?>" selected><?php echo htmlspecialchars($vehicle_number); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Bill No. (Optional Override) -->
                            <div class="col-lg-3 col-md-6 col-sm-12 mb-3">
                                <label class="font-weight-bold text-secondary small mb-1">
                                    <i class="fas fa-barcode mr-1 text-primary"></i>Bill No. (Optional Override)
                                </label>
                                <input type="text" name="bill_no" class="form-control font-weight-bold text-primary" 
                                       value="<?php echo htmlspecialchars($bill_data['bill_no'] ?? $bill_no_input); ?>" 
                                       placeholder="Auto (e.g. 64343)">
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="form-row">
                            <div class="col-12 text-md-right">
                                <button type="submit" name="generate_bill" value="1" class="btn btn-primary px-4 mr-2" style="background:var(--primary-gradient); border:none;">
                                    <i class="fas fa-calculator mr-1"></i> Generate Bill
                                </button>
                                <a href="customer-monthly-bill.php?tab=generate" class="btn btn-secondary px-3">
                                    <i class="fas fa-undo mr-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($bill_data): ?>
                <!-- KPI Summary Bar -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="kpi-card">
                            <div class="text-muted small text-uppercase font-weight-bold">Total Coupons / Slips</div>
                            <div class="h3 font-weight-bold text-navy mb-0"><?php echo intval($bill_data['total_coupons']); ?></div>
                            <div class="small text-muted">Vouchers in this bill</div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="kpi-card" style="border-left-color: #28a745;">
                            <div class="text-muted small text-uppercase font-weight-bold">Total Bill Amount</div>
                            <div class="h3 font-weight-bold text-success mb-0">Rs. <?php echo number_format($bill_data['total_amount'], 2); ?></div>
                            <div class="small text-muted">Sum of all outstanding slips</div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="kpi-card" style="border-left-color: #17a2b8;">
                            <div class="text-muted small text-uppercase font-weight-bold">Fuel Subtotal</div>
                            <div class="h4 font-weight-bold text-info mb-0">
                                Rs. <?php echo number_format($bill_data['category_summary']['Diesel']['amount'] ?? 0, 2); ?>
                            </div>
                            <div class="small text-muted"><?php echo number_format($bill_data['category_summary']['Diesel']['quantity'] ?? 0, 2); ?> Ltr Fuel</div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="kpi-card" style="border-left-color: #ffc107;">
                            <div class="text-muted small text-uppercase font-weight-bold">Lubricants / Other</div>
                            <div class="h4 font-weight-bold text-warning mb-0">
                                Rs. <?php echo number_format($bill_data['category_summary']['Others']['amount'] ?? 0, 2); ?>
                            </div>
                            <div class="small text-muted">Direct lubricants &amp; products</div>
                        </div>
                    </div>
                </div>

                <!-- Printable Sheet Preview Container -->
                <div class="card border-0 mb-5" style="background: transparent;">
                    <div class="card-body p-0">
                        <div class="bill-preview-sheet">

                            <!-- Letterhead Header -->
                            <div class="text-center">
                                <div class="bill-header-title">
                                    <?php echo htmlspecialchars($bill_data['station']['pump_name'] ?? 'KHURRAM PETROLEUM SERVICE & CNG'); ?>
                                </div>
                                <div class="bill-header-sub">
                                    <?php echo htmlspecialchars($bill_data['station']['tagline'] ?? 'Dealers :- Pakistan State Oil Ltd.'); ?>
                                </div>
                                <div class="bill-header-sub">
                                    <?php echo htmlspecialchars($bill_data['station']['address'] ?? 'Hasilpur Road'); ?>, 
                                    <?php echo htmlspecialchars($bill_data['station']['city'] ?? 'Bahawalpur (City)'); ?>.
                                </div>
                                <div class="bill-header-sub">
                                    <?php echo htmlspecialchars($bill_data['station']['phone'] ?? '062-2283577'); ?>
                                </div>
                                <div class="bill-header-dates">
                                    Credit Bill <?php echo htmlspecialchars($bill_data['date_range_label']); ?>
                                </div>
                            </div>

                            <!-- Customer & Bill Meta Box (2-Column Grid) -->
                            <table class="bill-meta-table" style="margin-bottom: 0;">
                                <tr>
                                    <td style="width: 10%; font-weight: 700;">A/c No</td>
                                    <td style="width: 50%; font-weight: 700;"><?php echo htmlspecialchars($bill_data['customer_id']); ?></td>
                                    <td style="width: 14%; font-weight: 700;">Vehicle</td>
                                    <td style="width: 26%; font-weight: 700;"><?php echo htmlspecialchars($bill_data['vehicle_display']); ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 700;">To</td>
                                    <td style="font-weight: 700;"><?php echo htmlspecialchars($bill_data['attention_to'] ?: 'N/A'); ?></td>
                                    <td style="font-weight: 700;">Bill No.</td>
                                    <td style="font-weight: 700; color: #04204e; font-size: 14.5px;"><?php echo htmlspecialchars($bill_data['bill_no']); ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 700;">Name</td>
                                    <td style="font-weight: 700;"><?php echo htmlspecialchars($bill_data['customer']['name'] ?? 'N/A'); ?></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </table>

                            <!-- Main Itemized Transactions Table -->
                            <table class="bill-trans-table" style="border-top: none; margin-bottom: 0;">
                                <thead>
                                    <tr>
                                        <th style="width: 6%;">S.No.</th>
                                        <th style="width: 13%;">Date</th>
                                        <th style="width: 16%;">Coupon</th>
                                        <th style="width: 27%;">Description</th>
                                        <th style="width: 11%; text-align: right;">Qty</th>
                                        <th style="width: 12%; text-align: right;">Rate</th>
                                        <th style="width: 15%; text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bill_data['transactions'])): ?>
                                        <?php $sno = 1; foreach ($bill_data['transactions'] as $t): ?>
                                            <tr>
                                                <td style="text-align: center;"><?php echo $sno++; ?></td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($t['date_formatted']); ?></td>
                                                <td style="text-align: center; font-weight: 600;"><?php echo htmlspecialchars($t['coupon']); ?></td>
                                                <td><?php echo htmlspecialchars($t['description']); ?></td>
                                                <td style="text-align: right;"><?php echo number_format($t['quantity'], 2); ?></td>
                                                <td style="text-align: right;"><?php echo number_format($t['rate'], 2); ?></td>
                                                <td style="text-align: right; font-weight: 600;"><?php echo number_format($t['billed_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="fas fa-check-circle text-success mr-1"></i> No outstanding credit vouchers found for this period. All slips have been settled.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <!-- Total Row -->
                            <table class="bill-total-table" style="border-top: none; margin-bottom: 8px;">
                                <tr>
                                    <td style="width: 85%; text-align: right; text-transform: uppercase;">Total Billed Amount (PKR) :</td>
                                    <td style="width: 15%; text-align: right; font-size: 15px; color: #04204e;">
                                        <?php echo number_format($bill_data['total_amount'], 2); ?>
                                    </td>
                                </tr>
                            </table>

                            <!-- Amount in Words -->
                            <div class="note-text mb-2 font-weight-bold" style="font-size: 13px; text-transform: uppercase;">
                                Amount in Words: <?php echo htmlspecialchars($bill_data['amount_in_words']); ?>
                            </div>

                            <!-- Category Breakdown Box (Bottom Left) -->
                            <div class="row no-gutters mt-2">
                                <div class="col-6">
                                    <table class="bill-cat-table">
                                        <thead>
                                            <tr style="background: #f7f7f7;">
                                                <th style="width: 45%; text-align: left;">Items Description</th>
                                                <th style="width: 25%; text-align: center;">Quantity</th>
                                                <th style="width: 30%; text-align: right;">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bill_data['category_summary'] as $cat): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($cat['name']); ?></td>
                                                    <td style="text-align: center;">
                                                        <?php if ($cat['quantity'] > 0): ?>
                                                            <?php echo number_format($cat['quantity'], 0); ?> <?php echo htmlspecialchars($cat['unit']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: right;"><?php echo number_format($cat['amount'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr style="font-weight: 700;">
                                                <td>Total</td>
                                                <td></td>
                                                <td style="text-align: right;"><?php echo number_format($bill_data['total_amount'], 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Initial State: Prompt to Generate Bill -->
                <div class="card shadow-sm border-0 text-center py-5 bg-white mb-4" style="border-radius: 8px;">
                    <div class="card-body py-5">
                        <div class="mb-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: rgba(4, 32, 78, 0.08); color: var(--primary-color);">
                                <i class="fas fa-file-invoice fa-2x"></i>
                            </span>
                        </div>
                        <h5 class="font-weight-bold text-navy mb-2" style="color: var(--primary-color);">Ready to Generate Monthly Credit Bill</h5>
                        <p class="text-muted mb-0 mx-auto" style="max-width: 520px;">
                            Select a <strong>Customer Account</strong> and <strong>Date Range</strong> above, then click 
                            <span class="badge badge-primary px-2 py-1 ml-1" style="background:var(--primary-gradient); font-size:12px;"><i class="fas fa-calculator mr-1"></i>Generate Bill</span> 
                            to calculate outstanding dues, view the official statement voucher, and print the PDF.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- TAB 2: GENERATED BILLS REGISTRY -->

            <!-- Registry KPIs -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="kpi-card" style="border-left-color: #04204e;">
                        <div class="text-muted small text-uppercase font-weight-bold">Total Bills Issued</div>
                        <div class="h3 font-weight-bold text-navy mb-0"><?php echo $reg_total_count; ?> Bills</div>
                        <div class="small text-muted">Audited billing statements</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card" style="border-left-color: #28a745;">
                        <div class="text-muted small text-uppercase font-weight-bold">Total Revenue Billed</div>
                        <div class="h3 font-weight-bold text-success mb-0">Rs. <?php echo number_format($reg_total_amount, 2); ?></div>
                        <div class="small text-muted">Demand credit issued</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="kpi-card" style="border-left-color: #17a2b8;">
                        <div class="text-muted small text-uppercase font-weight-bold">Total Coupons Included</div>
                        <div class="h3 font-weight-bold text-info mb-0"><?php echo number_format($reg_total_coupons); ?> Coupons</div>
                        <div class="small text-muted">Fuel &amp; product slips billed</div>
                    </div>
                </div>
            </div>

            <!-- Registry Filter Bar -->
            <div class="card filter-card mb-4">
                <div class="card-body py-3">
                    <form method="GET" action="customer-monthly-bill.php" class="form-inline">
                        <input type="hidden" name="tab" value="registry">
                        
                        <label class="my-1 mr-2 font-weight-bold" for="reg_bill_no">
                            <i class="fas fa-barcode mr-1 text-primary"></i> Bill #:
                        </label>
                        <input type="text" name="reg_bill_no" id="reg_bill_no" class="form-control mr-sm-3" 
                               placeholder="e.g. 64343" value="<?php echo htmlspecialchars($reg_bill_no); ?>">

                        <label class="my-1 mr-2 font-weight-bold" for="reg_customer_id">
                            <i class="fas fa-user mr-1 text-primary"></i> Customer:
                        </label>
                        <select name="reg_customer_id" id="reg_customer_id" class="form-control mr-sm-3">
                            <option value="0">-- All Customers --</option>
                            <?php foreach ($all_customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($reg_customer_id === $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-primary my-1 mr-2 font-weight-bold" style="background:var(--primary-gradient); border:none;">
                            <i class="fas fa-filter mr-1"></i> Filter Bills
                        </button>
                        <a href="customer-monthly-bill.php?tab=registry" class="btn btn-outline-secondary my-1">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </a>
                    </form>
                </div>
            </div>

            <!-- Registry Data Table Card -->
            <div class="card shadow-sm border-0" style="border-radius: 8px;">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="registryTable" class="table table-bordered table-striped table-hover mb-0" style="width: 100%;">
                            <thead class="bg-dark text-white">
                                <tr>
                                    <th style="width: 75px; text-align: center;">Bill #</th>
                                    <th>Issue Date</th>
                                    <th>Customer Name</th>
                                    <th>Attention / Position</th>
                                    <th>Billing Period</th>
                                    <th>Vehicle</th>
                                    <th style="text-align: center;">Coupons</th>
                                    <th style="text-align: right;">Total Amount</th>
                                    <th>Generated By</th>
                                    <th style="text-align: center; width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($registry_bills)): ?>
                                    <?php foreach ($registry_bills as $b): ?>
                                        <tr>
                                            <td style="text-align: center;">
                                                <strong style="color: #04204e; font-size: 14px;">
                                                    #<?php echo htmlspecialchars($b['bill_no']); ?>
                                                </strong>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($b['created_at'])); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($b['customer_name']); ?></strong>
                                                <small class="text-muted d-block">A/c #<?php echo $b['customer_id']; ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($b['attention_to'])): ?>
                                                    <span class="badge badge-light border text-dark font-weight-normal">
                                                        <i class="fas fa-id-badge mr-1 text-info"></i><?php echo htmlspecialchars($b['attention_to']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-info font-weight-normal px-2 py-1">
                                                    <?php echo date('d/m/y', strtotime($b['from_date'])); ?> &ndash; <?php echo date('d/m/y', strtotime($b['to_date'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($b['vehicle_number'] ?: 'All'); ?></td>
                                            <td style="text-align: center; font-weight: 700;"><?php echo intval($b['total_coupons']); ?></td>
                                            <td style="text-align: right; font-weight: 800; color: #1e7e34;">
                                                Rs. <?php echo number_format($b['total_amount'], 2); ?>
                                            </td>
                                            <td>
                                                <small class="text-muted"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($b['generated_by_user'] ?: 'System'); ?></small>
                                            </td>
                                            <td style="text-align: center;">
                                                <a href="generate-pdf-customer-monthly-bill.php?bill_no=<?php echo urlencode($b['bill_no']); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-success btn-sm mb-1 px-2 py-1 font-weight-bold" 
                                                   title="Print / Save PDF">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="customer-monthly-bill.php?tab=generate&search_bill_no=<?php echo urlencode($b['bill_no']); ?>" 
                                                   class="btn btn-primary btn-sm mb-1 px-2 py-1 font-weight-bold" 
                                                   style="background:var(--primary-gradient); border:none;" 
                                                   title="View Statement">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <form method="POST" action="customer-monthly-bill.php?tab=registry" class="d-inline" onsubmit="return confirm('Are you sure you want to remove Bill #<?php echo htmlspecialchars($b['bill_no']); ?> from the registry?');">
                                                    <input type="hidden" name="action" value="delete_bill">
                                                    <input type="hidden" name="bill_id" value="<?php echo $b['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm mb-1 px-2 py-1" title="Cancel / Remove Bill">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($('#registryTable').length) {
                $('#registryTable').DataTable({
                    "order": [[ 0, "desc" ]],
                    "pageLength": 25
                });
            }

            // Pre-loaded customer vehicles mapping for dynamic dropdown switching
            const customerVehiclesMap = <?php echo json_encode($all_cust_vehicles); ?>;
            const currentVehicle = <?php echo json_encode($vehicle_number); ?>;

            $('#customer_id').on('change', function() {
                const cid = $(this).val();
                const vehicles = customerVehiclesMap[cid] || [];
                let opts = '<option value="">All Vehicles</option>';
                vehicles.forEach(function(v) {
                    const selected = (v.reg_number === currentVehicle) ? ' selected' : '';
                    opts += '<option value="' + v.reg_number + '"' + selected + '>' + v.reg_number + ' (' + v.vehicle_name + ')</option>';
                });
                $('#vehicle_number').html(opts);
            });
        });
    </script>
</body>
</html>
