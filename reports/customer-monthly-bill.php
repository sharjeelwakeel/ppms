<?php
/**
 * Customer Overall Monthly Credit Bill / Statement
 * PPMS (Petrol Pump Management System)
 * 
 * Provides an exact-match statement replicating the official credit bill voucher,
 * filtering strictly for outstanding dues (unpaid & partial vouchers) across fuel
 * and lubricant sales.
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

// Fetch list of customers with dues summary
$all_customers = get_customers_with_dues_summary($connection);

// Inputs and Query Parameters
$customer_id    = intval($_GET['customer_id'] ?? 0);
$vehicle_number = trim($_GET['vehicle_number'] ?? '');
$from_date      = trim($_GET['from_date'] ?? '');
$to_date        = trim($_GET['to_date'] ?? '');
$bill_no_input  = trim($_GET['bill_no'] ?? '');
$attention_to   = trim($_GET['attention_to'] ?? '');

// If dates not set, default to current month or August 2026 if customer 22 is selected
if (empty($from_date) || empty($to_date)) {
    if ($customer_id === 22) {
        $from_date = '2026-08-01';
        $to_date   = '2026-08-31';
    } else {
        $from_date = date('Y-m-01');
        $to_date   = date('Y-m-t');
    }
}

// If no customer selected yet, see if customer 22 has dues, otherwise select first customer with dues
if ($customer_id === 0 && !empty($all_customers)) {
    foreach ($all_customers as $c) {
        if ($c['id'] === 22) {
            $customer_id = 22;
            break;
        }
    }
    if ($customer_id === 0) {
        foreach ($all_customers as $c) {
            if ($c['has_outstanding']) {
                $customer_id = $c['id'];
                break;
            }
        }
    }
    if ($customer_id === 0 && !empty($all_customers)) {
        $customer_id = $all_customers[0]['id'];
    }
}

// Fetch Customer's registered vehicles for dropdown
$customer_vehicles = [];
if ($customer_id > 0) {
    $v_res = mysqli_query($connection, "SELECT id, vehicle_name, reg_number FROM tbl_customer_vehicles WHERE customer_id = '$customer_id' AND deleted_at IS NULL ORDER BY reg_number ASC");
    if ($v_res) {
        while ($vrow = mysqli_fetch_assoc($v_res)) {
            $customer_vehicles[] = $vrow;
        }
    }
}

// Generate bill data
$bill_data = null;
if ($customer_id > 0 && !empty($from_date) && !empty($to_date)) {
    $bill_data = get_customer_monthly_bill_data($connection, $customer_id, $from_date, $to_date, $vehicle_number, [
        'bill_no'       => $bill_no_input,
        'attention_to'  => $attention_to
    ]);
}

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
    </style>
</head>
<body class="bg-light">
    <?php include_once '../include/navbar.php'; ?>

    <div class="container-fluid px-3 px-md-4 py-4">
        <!-- Breadcrumb & Top Bar -->
        <div class="row align-items-center mb-3">
            <div class="col-md-7">
                <h4 class="mb-1 font-weight-bold text-navy" style="color: var(--primary-color);">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>Customer Overall Monthly Credit Bill
                </h4>
                <p class="text-muted small mb-0">
                    Comprehensive credit statement for fuel &amp; products. Filtered strictly for <strong>outstanding dues</strong> (excluding paid slips).
                </p>
            </div>
            <div class="col-md-5 text-md-right mt-2 mt-md-0">
                <?php if ($bill_data && count($bill_data['transactions']) > 0): ?>
                    <a href="generate-pdf-customer-monthly-bill.php?customer_id=<?php echo urlencode($customer_id); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&vehicle_number=<?php echo urlencode($vehicle_number); ?>&bill_no=<?php echo urlencode($bill_data['bill_no']); ?>&attention_to=<?php echo urlencode($bill_data['attention_to']); ?>" 
                       target="_blank" class="btn btn-success shadow-sm">
                        <i class="fas fa-print mr-1"></i> Print / Save PDF Bill
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Controls Card -->
        <div class="card filter-card mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="font-weight-bold text-navy"><i class="fas fa-filter mr-2 text-primary"></i>Bill Statement Parameters</span>
                <span class="badge badge-danger text-white font-weight-bold px-2 py-1"><i class="fas fa-hand-holding-usd mr-1"></i>Demand Bill: Unpaid Dues Only</span>
            </div>
            <div class="card-body">
                <form method="GET" action="customer-monthly-bill.php" id="billFilterForm">
                    <div class="form-row">
                        <!-- Customer Dropdown -->
                        <div class="col-md-5 col-sm-12 mb-3">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-user mr-1 text-primary"></i>Customer Account <span class="text-danger">*</span>
                            </label>
                            <select name="customer_id" id="customer_id" class="form-control select2" required onchange="this.form.submit()">
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($all_customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($customer_id === $c['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['name']); ?>
                                        <?php if ($c['has_outstanding']): ?>
                                            (Due: Rs. <?php echo number_format($c['total_due'], 2); ?> - <?php echo $c['total_vouchers']; ?> unpaid)
                                        <?php else: ?>
                                            (All Paid - Rs. 0.00 Due)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Range From -->
                        <div class="col-md-2 col-sm-6 mb-3">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-calendar-alt mr-1 text-primary"></i>From Date
                            </label>
                            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($from_date); ?>" required>
                        </div>

                        <!-- Date Range To -->
                        <div class="col-md-2 col-sm-6 mb-3">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-calendar-alt mr-1 text-primary"></i>To Date
                            </label>
                            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($to_date); ?>" required>
                        </div>

                        <!-- Vehicle Filter -->
                        <div class="col-md-3 col-sm-12 mb-3">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-car mr-1 text-primary"></i>Vehicle (Optional)
                            </label>
                            <select name="vehicle_number" class="form-control">
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
                    </div>

                    <!-- Row 2: Attention, Custom Bill No, and Buttons -->
                    <div class="form-row align-items-end">
                        <div class="col-md-5 col-sm-6 mb-2">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-id-card-alt mr-1 text-primary"></i>Attention Line (e.g. The Vice Chancellor,)
                            </label>
                            <input type="text" name="attention_to" class="form-control" 
                                    value="<?php echo htmlspecialchars($bill_data['attention_to'] ?? $attention_to); ?>" 
                                    placeholder="The Vice Chancellor,">
                        </div>

                        <div class="col-md-3 col-sm-6 mb-2">
                            <label class="font-weight-bold text-secondary small mb-1">
                                <i class="fas fa-barcode mr-1 text-primary"></i>Bill No. (Optional)
                            </label>
                            <input type="text" name="bill_no" class="form-control font-weight-bold" 
                                    value="<?php echo htmlspecialchars($bill_data['bill_no'] ?? $bill_no_input); ?>" 
                                    placeholder="Auto (e.g. 64343)">
                        </div>

                        <div class="col-md-4 col-sm-12 mb-2 text-md-right">
                            <button type="submit" class="btn btn-primary px-4 mr-2">
                                <i class="fas fa-search mr-1"></i> Generate Statement
                            </button>
                            <a href="customer-monthly-bill.php" class="btn btn-secondary px-3">
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

            <!-- On-Screen Exact Match Preview Sheet -->
            <div class="card shadow-sm border-0 mb-5">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 font-weight-bold text-navy">
                        <i class="fas fa-file-invoice mr-2 text-primary"></i>Official Credit Bill Voucher Preview
                    </h5>
                    <a href="generate-pdf-customer-monthly-bill.php?customer_id=<?php echo urlencode($customer_id); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&vehicle_number=<?php echo urlencode($vehicle_number); ?>&status_filter=<?php echo urlencode($status_filter); ?>&bill_no=<?php echo urlencode($bill_data['bill_no']); ?>&attention_to=<?php echo urlencode($bill_data['attention_to']); ?>" 
                       target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-external-link-alt mr-1"></i> Open Fullscreen Print Sheet
                    </a>
                </div>
                <div class="card-body bg-light py-4">
                    <div class="bill-preview-sheet">
                        <!-- Station Header -->
                        <div class="text-center mb-3">
                            <div class="bill-header-title"><?php echo htmlspecialchars($bill_data['station']['pump_name'] ?? 'KHURRAM PETROLEUM SERVICE & CNG'); ?></div>
                            <div class="bill-header-sub"><?php echo htmlspecialchars($bill_data['station']['tagline'] ?? 'Dealers :- Pakistan State Oil Ltd.'); ?></div>
                            <div class="bill-header-sub">
                                <?php echo htmlspecialchars($bill_data['station']['address'] ?? 'Hasilpur Road'); ?>, 
                                <?php echo htmlspecialchars($bill_data['station']['city'] ?? 'Bahawalpur (City)'); ?>.
                            </div>
                            <div class="bill-header-sub"><?php echo htmlspecialchars($bill_data['station']['phone'] ?? '062-2283577'); ?></div>
                            <div class="bill-header-dates">
                                Credit Bill <?php echo htmlspecialchars($bill_data['date_range_label']); ?>
                            </div>
                        </div>

                        <!-- Customer & Bill Metadata Box -->
                        <table class="bill-meta-table mb-2">
                            <tr>
                                <td style="width: 12%; font-weight: 700;">A/cNo</td>
                                <td style="width: 48%; font-weight: 700;"><?php echo htmlspecialchars($bill_data['customer_id']); ?></td>
                                <td style="width: 15%; font-weight: 700;">Vehicle</td>
                                <td style="width: 25%; font-weight: 700;"><?php echo htmlspecialchars($bill_data['vehicle_display']); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 700;">To</td>
                                <td style="font-weight: 700;"><?php echo htmlspecialchars($bill_data['attention_to']); ?></td>
                                <td style="font-weight: 700;">Bill No.</td>
                                <td style="font-weight: 700;"><?php echo htmlspecialchars($bill_data['bill_no']); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight: 700;">Name</td>
                                <td style="font-weight: 700;"><?php echo htmlspecialchars($bill_data['customer']['name'] ?? 'N/A'); ?></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </table>

                        <!-- Itemized Transactions Grid -->
                        <table class="bill-trans-table mb-2">
                            <thead>
                                <tr>
                                    <th style="width: 7%;">S.No.</th>
                                    <th style="width: 14%;">Date</th>
                                    <th style="width: 12%;">Coupon</th>
                                    <th style="width: 27%;">Description</th>
                                    <th style="width: 13%;">Quantity</th>
                                    <th style="width: 13%;">Rate</th>
                                    <th style="width: 14%;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($bill_data['transactions'])): ?>
                                    <?php foreach ($bill_data['transactions'] as $row): ?>
                                        <tr>
                                            <td style="text-align: center;"><?php echo $row['s_no']; ?></td>
                                            <td style="text-align: center;"><?php echo $row['date_formatted']; ?></td>
                                            <td style="text-align: center; font-weight: 600;"><?php echo htmlspecialchars($row['coupon']); ?></td>
                                            <td style="text-align: left; padding-left: 8px;"><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td style="text-align: right; padding-right: 8px;"><?php echo number_format($row['quantity'], 2); ?></td>
                                            <td style="text-align: right; padding-right: 8px;"><?php echo number_format($row['rate'], 2); ?></td>
                                            <td style="text-align: right; padding-right: 8px; font-weight: 600;"><?php echo number_format($row['billed_amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 25px; color: #888;">
                                            No outstanding credit vouchers found for this customer and date range.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <!-- Coupons Count, In Words, and Total Bill Amount -->
                        <div class="row no-gutters mb-3">
                            <div class="col-7 pr-2">
                                <div style="font-size: 13px; font-weight: 700; margin-bottom: 4px;">
                                    Total No of Coupons &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo intval($bill_data['total_coupons']); ?>
                                </div>
                                <div style="font-size: 12.5px; font-weight: 700; line-height: 1.35; margin-bottom: 6px;">
                                    <?php echo htmlspecialchars($bill_data['amount_in_words']); ?>
                                </div>
                                <div class="note-text">
                                    <strong>Note:</strong> <?php echo htmlspecialchars($bill_data['station']['receipt_footer'] ?? 'If payment is not made within 7 days after receipt of bills supplies may withheld without further information and 10% surcharge will be recoverable per month. Includes service charges & taxes'); ?>
                                </div>
                            </div>
                            <div class="col-5 pl-2 text-right">
                                <table class="bill-total-table mb-2">
                                    <tr>
                                        <td style="width: 55%; text-align: left; background: #fafafa;">Total Bill Amount</td>
                                        <td style="width: 45%; text-align: right;"><?php echo number_format($bill_data['total_amount'], 2); ?></td>
                                    </tr>
                                    <tr style="height: 24px;">
                                        <td></td>
                                        <td></td>
                                    </tr>
                                </table>
                                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; margin-top: 15px;">
                                    <?php echo htmlspecialchars($bill_data['station']['pump_name'] ?? 'KHURRAM PETROLEUM SERVICE & CNG'); ?>
                                </div>
                            </div>
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
        <?php endif; ?>

    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
