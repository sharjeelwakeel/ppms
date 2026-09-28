<?php
/**
 * PPMS - Staff Salary Report
 * 
 * Comprehensive executive and operational report of attendance-based
 * staff cash salary disbursements, vouchers issued, and payment audit log.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/salary_payment_helper.php';

// RBAC Gate: Accessible to staff or reports viewers
if (!has_permission('reports', 'show') && !has_permission('staff', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$filter_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filter_year  = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$filter_staff = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;

$history_records = get_salary_payment_history($connection, $filter_month, $filter_year, $filter_staff);

// Fetch all staff for filter dropdown
$staff_list = [];
$q_staff = mysqli_query($connection, "SELECT id, first_name, last_name FROM tbl_staff WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY first_name ASC");
if ($q_staff) {
    while ($s = mysqli_fetch_assoc($q_staff)) {
        $staff_list[] = $s;
    }
}

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$total_disbursed_cash = 0;
$total_vouchers = count($history_records);
$total_days_paid = 0;

foreach ($history_records as $hr) {
    $total_disbursed_cash += $hr['paid_amount'];
    $total_days_paid += $hr['days_worked'];
}
$avg_disbursed = $total_vouchers > 0 ? ($total_disbursed_cash / $total_vouchers) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Staff Salary Report</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header) !important;
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(49,27,146,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }
        .list-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden;
            padding:20px;
        }
        .table thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:11px; font-weight:600;
        }
        .table td { vertical-align:middle; font-size:13px; }
        
        .kpi-card {
            border: none;
            border-radius: 10px;
            padding: 16px 20px;
            color: #fff;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .kpi-title { font-size: 11px; text-transform: uppercase; font-weight: 600; opacity: 0.9; }
        .kpi-value { font-size: 20px; font-weight: 800; margin-top: 4px; margin-bottom: 0; }
        
        .btn-voucher {
            background: var(--primary-gradient) !important;
            border: none; color: #fff; font-weight: 600;
            padding: 4px 10px; border-radius: 5px; font-size: 11px;
            transition: all .2s;
        }
        .btn-voucher:hover { opacity: .9; color: #fff; }

        @media print {
            .navbar, .page-header button, .no-print, .card-filter, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info {
                display: none !important;
            }
            body { background: #fff !important; }
            .list-card { box-shadow: none !important; border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body>
    <?php include('../include/navbar.php'); ?>
    <main class="main">
        <div class="container-fluid pt-4 pb-5 px-4">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h4><i class="fas fa-file-invoice-dollar mr-2"></i>Staff Salary Report</h4>
                    <small style="opacity:.85;">Official audit report of attendance-based cash salary disbursements &amp; issued vouchers</small>
                </div>
                <div class="no-print">
                    <button type="button" onclick="window.print()" class="btn btn-outline-light btn-sm font-weight-bold mr-2">
                        <i class="fas fa-print mr-1"></i> Print Report
                    </button>
                    <a href="../staff/salary-calculator.php" class="btn btn-outline-light btn-sm font-weight-bold">
                        <i class="fas fa-calculator mr-1"></i> Salary Calculator
                    </a>
                </div>
            </div>

            <!-- KPI Summary Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #1e7e34 0%, #28a745 100%);">
                        <div class="kpi-title"><i class="fas fa-money-bill-wave mr-1"></i> Total Cash Disbursed</div>
                        <div class="kpi-value">Rs. <?php echo number_format($total_disbursed_cash, 2); ?></div>
                        <small style="opacity:0.85;">100% Cash Counter Disbursals</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #04204e 0%, #07347a 100%);">
                        <div class="kpi-title"><i class="fas fa-receipt mr-1"></i> Vouchers Issued</div>
                        <div class="kpi-value"><?php echo $total_vouchers; ?> Vouchers</div>
                        <small style="opacity:0.85;">Audited &amp; Signed Records</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                        <div class="kpi-title"><i class="fas fa-calendar-check mr-1"></i> Days Compensated</div>
                        <div class="kpi-value"><?php echo number_format($total_days_paid); ?> Days</div>
                        <small style="opacity:0.85;">Present + Late + Off + Paid Leaves</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                        <div class="kpi-title"><i class="fas fa-chart-line mr-1"></i> Average Voucher</div>
                        <div class="kpi-value">Rs. <?php echo number_format($avg_disbursed, 2); ?></div>
                        <small style="opacity:0.85;">Per Monthly Disbursement</small>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-4 card-filter no-print" style="border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
                <div class="card-body">
                    <form method="GET" action="staff-salary-report.php" class="form-inline">
                        <label class="my-1 mr-2 font-weight-bold" for="month"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Month:</label>
                        <select name="month" id="month" class="form-control mr-sm-3">
                            <option value="0">-- All Months --</option>
                            <?php foreach ($months as $m => $name): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($filter_month === $m) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="my-1 mr-2 font-weight-bold" for="year"><i class="fas fa-calendar mr-1 text-primary"></i> Year:</label>
                        <select name="year" id="year" class="form-control mr-sm-3">
                            <option value="0">-- All Years --</option>
                            <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filter_year === $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>

                        <label class="my-1 mr-2 font-weight-bold" for="staff_id"><i class="fas fa-user-tie mr-1 text-primary"></i> Staff Member:</label>
                        <select name="staff_id" id="staff_id" class="form-control mr-sm-3">
                            <option value="0">-- All Staff --</option>
                            <?php foreach ($staff_list as $st): ?>
                                <option value="<?php echo $st['id']; ?>" <?php echo ($filter_staff === (int)$st['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?> (#<?php echo $st['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-primary my-1 mr-2" style="background:var(--primary-gradient); border:none;">
                            <i class="fas fa-filter mr-1"></i> Filter
                        </button>
                        <a href="staff-salary-report.php" class="btn btn-outline-secondary my-1">
                            <i class="fas fa-undo mr-1"></i> Reset
                        </a>
                    </form>
                </div>
            </div>

            <!-- History DataTable Card -->
            <div class="list-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0">
                        <i class="fas fa-list-alt mr-1 text-primary"></i> Disbursed Cash Salary Vouchers Audit Log
                    </h5>
                    <span class="badge badge-success px-3 py-2 font-weight-bold">
                        <i class="fas fa-coins mr-1"></i> Payment Mode: 100% Cash Counter
                    </span>
                </div>
                <div class="table-responsive">
                    <table id="salaryReportTable" class="table table-bordered table-striped" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Voucher #</th>
                                <th>Payment Date</th>
                                <th>Staff Name</th>
                                <th>Role</th>
                                <th style="text-align:center;">Salary Period</th>
                                <th style="text-align:center;">Days Paid</th>
                                <th style="text-align:right;">Daily Rate</th>
                                <th style="text-align:right;">Paid Cash Amount</th>
                                <th style="text-align:center;">Disbursed By</th>
                                <th>Remarks</th>
                                <th style="text-align:center; width:120px;" class="no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($history_records)): ?>
                                <?php foreach ($history_records as $rec): ?>
                                    <tr id="row-payment-<?php echo $rec['id']; ?>">
                                        <td>
                                            <strong style="color:var(--primary-color); font-size:13px;">
                                                <?php echo htmlspecialchars($rec['voucher_no']); ?>
                                            </strong>
                                        </td>
                                        <td><?php echo $rec['payment_date_fmt']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($rec['staff_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($rec['role_name']); ?></td>
                                        <td style="text-align:center;">
                                            <span class="badge badge-light border font-weight-bold text-dark">
                                                <?php echo ($months[$rec['salary_month']] ?? $rec['salary_month']) . ' ' . $rec['salary_year']; ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center; font-weight:700;">
                                            <?php echo $rec['days_worked']; ?> Days
                                        </td>
                                        <td style="text-align:right; font-weight:600;">
                                            <?php echo number_format($rec['daily_rate'], 2); ?>
                                        </td>
                                        <td style="text-align:right; font-weight:800; color:#1e7e34; font-size:14px;">
                                            Rs. <?php echo number_format($rec['paid_amount'], 2); ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <small class="text-muted"><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($rec['paid_by_user']); ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($rec['remarks'] ?: '-'); ?></small>
                                        </td>
                                        <td style="text-align:center;" class="no-print">
                                            <a href="../staff/generate-pdf-salary-receipt.php?payment_id=<?php echo $rec['id']; ?>" 
                                               target="_blank" 
                                               class="btn btn-voucher btn-sm mb-1" 
                                               title="Print Cash Voucher">
                                                <i class="fas fa-print mr-1"></i> Print
                                            </a>
                                            <?php if (has_permission('staff', 'delete') || has_permission('staff', 'edit')): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm revert-btn" 
                                                        style="font-size:10px; padding:2px 6px;"
                                                        data-id="<?php echo $rec['id']; ?>"
                                                        data-voucher="<?php echo htmlspecialchars($rec['voucher_no']); ?>"
                                                        data-staff="<?php echo htmlspecialchars($rec['staff_name']); ?>"
                                                        title="Revert Payment to Unpaid">
                                                    <i class="fas fa-undo mr-1"></i> Revert
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#salaryReportTable').DataTable({
                "order": [[ 1, "desc" ], [ 0, "desc" ]],
                "pageLength": 25
            });

            // Handle Revert Payment
            $('.revert-btn').on('click', function() {
                var paymentId = $(this).data('id');
                var voucherNo = $(this).data('voucher');
                var staffName = $(this).data('staff');

                var confirmMsg = "Are you sure you want to revert salary payment for " + staffName + " (Voucher #" + voucherNo + ")?\n\nThis will reset the month's status back to Unpaid.";
                if (!confirm(confirmMsg)) {
                    return;
                }

                $.ajax({
                    url: '../staff/process-salary-payment.php',
                    type: 'POST',
                    data: {
                        action: 'revert',
                        payment_id: paymentId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            alert(response.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('Server error occurred while reverting payment.');
                    }
                });
            });
        });
    </script>
</body>
</html>
