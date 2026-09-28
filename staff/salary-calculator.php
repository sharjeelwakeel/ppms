<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/salary_payment_helper.php';

// Enforce access check for viewing salary calculator
check_access('staff', 'show');

// Default to current month and year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch staff salary and attendance records with payment status
$staff_salary_records = get_staff_monthly_salary_records($connection, $selected_month, $selected_year);

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$total_payroll_calculated = 0;
$total_payroll_paid = 0;
$total_staff_paid_count = 0;
$total_staff_unpaid_count = 0;

foreach ($staff_salary_records as $rec) {
    $total_payroll_calculated += $rec['calculated_salary'];
    if ($rec['is_paid']) {
        $total_payroll_paid += $rec['paid_amount'];
        $total_staff_paid_count++;
    } else {
        $total_staff_unpaid_count++;
    }
}
$total_payroll_pending = max(0, $total_payroll_calculated - $total_payroll_paid);
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
    <title>PPMS - Salary Calculation & Payroll</title>
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
        .btn-slip {
            background: var(--primary-gradient) !important;
            border: none; color: #fff; font-weight: 600;
            padding: 4px 10px; border-radius: 6px; font-size: 11px;
            transition: all .2s;
        }
        .btn-slip:hover { opacity: .9; color: #fff; transform: translateY(-1px); }
        
        .kpi-card {
            border: none;
            border-radius: 10px;
            padding: 16px 20px;
            color: #fff;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .kpi-title { font-size: 12px; text-transform: uppercase; font-weight: 600; opacity: 0.9; }
        .kpi-value { font-size: 20px; font-weight: 800; margin-top: 4px; margin-bottom: 0; }
        
        .modal-header-custom {
            background: var(--primary-gradient);
            color: #fff;
        }
        .cash-badge {
            background: #28a745;
            color: #fff;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }
        
        /* Printable Salary Slip styles */
        #printableSlipArea {
            padding: 30px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .slip-header {
            text-align: center;
            border-bottom: 3px double #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .slip-title {
            font-size: 22px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }
        .slip-section-title {
            background: #f4f6fb;
            font-weight: bold;
            padding: 5px 10px;
            margin-top: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary-color) !important;
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
                    <h4><i class="fas fa-money-check-alt mr-2"></i>Staff Salary Calculation & Payroll</h4>
                    <small style="opacity:.85;">Calculate monthly cash payroll strictly based on physical attendance days (No advance deductions)</small>
                </div>
                <div>
                    <a href="../reports/staff-salary-report.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" class="btn btn-outline-light btn-sm font-weight-bold">
                        <i class="fas fa-file-invoice-dollar mr-1"></i> Staff Salary Report
                    </a>
                </div>
            </div>

            <!-- KPI Summary Cards -->
            <div class="row">
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #04204e 0%, #07347a 100%);">
                        <div class="kpi-title"><i class="fas fa-calculator mr-1"></i> Total Calculated Payroll</div>
                        <div class="kpi-value">Rs. <?php echo number_format($total_payroll_calculated, 2); ?></div>
                        <small style="opacity:0.8;"><?php echo count($staff_salary_records); ?> Active Staff Members</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #1e7e34 0%, #28a745 100%);">
                        <div class="kpi-title"><i class="fas fa-money-bill-wave mr-1"></i> Cash Disbursed</div>
                        <div class="kpi-value">Rs. <?php echo number_format($total_payroll_paid, 2); ?></div>
                        <small style="opacity:0.8;"><?php echo $total_staff_paid_count; ?> Staff Paid (100% Cash)</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #d39e00 0%, #ffc107 100%); color:#333;">
                        <div class="kpi-title"><i class="fas fa-hourglass-half mr-1"></i> Pending Cash Disbursement</div>
                        <div class="kpi-value" style="color:#222;">Rs. <?php echo number_format($total_payroll_pending, 2); ?></div>
                        <small style="opacity:0.9;"><?php echo $total_staff_unpaid_count; ?> Staff Unpaid</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                        <div class="kpi-title"><i class="fas fa-calendar-alt mr-1"></i> Selected Period</div>
                        <div class="kpi-value"><?php echo $months[$selected_month] . ' ' . $selected_year; ?></div>
                        <small style="opacity:0.8;">Working Days Basis</small>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-4" style="border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
                <div class="card-body">
                    <form method="GET" action="salary-calculator.php" class="form-inline">
                        <label class="my-1 mr-2 font-weight-bold" for="month"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Month:</label>
                        <select name="month" id="month" class="form-control mr-sm-3" required>
                            <?php foreach ($months as $m => $name): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($selected_month === $m) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="my-1 mr-2 font-weight-bold" for="year"><i class="fas fa-calendar mr-1 text-primary"></i> Year:</label>
                        <select name="year" id="year" class="form-control mr-sm-3" required>
                            <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year === $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>

                        <button type="submit" class="btn btn-primary my-1" style="background:var(--primary-gradient); border:none;">
                            <i class="fas fa-calculator mr-1"></i> Calculate Salary
                        </button>
                    </form>
                </div>
            </div>

            <!-- List Card -->
            <div class="list-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0">
                        Attendance &amp; Payroll Register for <strong><?php echo $months[$selected_month] . ' ' . $selected_year; ?></strong>
                    </h5>
                    <span class="badge badge-light p-2 border font-weight-bold text-dark">
                        <i class="fas fa-info-circle text-info mr-1"></i> Paid Days = Present + Late + Holiday + Paid Leaves (Global Policy: <?php echo get_global_paid_leaves($connection); ?> Leaves Allowed)
                    </span>
                </div>
                <div class="table-responsive">
                    <table id="salaryCalculationTable" class="table table-bordered table-striped" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:45px;">ID</th>
                                <th>Staff Name</th>
                                <th>Role</th>
                                <th style="text-align:center;">Weekly Off</th>
                                <th style="text-align:right;">Per Day Rate</th>
                                <th style="text-align:center;">Present</th>
                                <th style="text-align:center;">Late</th>
                                <th style="text-align:center; background:#117a8b !important;">Holiday</th>
                                <th style="text-align:center;">Leave</th>
                                <th style="text-align:center; background:#1e7e34 !important;">Paid L.</th>
                                <th style="text-align:center;">Absent</th>
                                <th style="text-align:center; background:#07347a !important;">Paid Days</th>
                                <th style="text-align:right;">Total Salary</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center; width:130px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($staff_salary_records)): ?>
                                <?php foreach ($staff_salary_records as $row): ?>
                                    <tr>
                                        <td><strong>#<?php echo $row['staff_id']; ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['role_name']); ?></td>
                                        <td style="text-align:center;">
                                            <span class="badge badge-light border text-info font-weight-bold" style="font-size:11px;">
                                                <i class="fas fa-umbrella-beach mr-1"></i><?php echo htmlspecialchars($row['weekly_off']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right; font-weight:600;"><?php echo number_format($row['daily_rate'], 2); ?></td>
                                        <td style="text-align:center;" class="text-success font-weight-bold"><?php echo $row['count_present']; ?></td>
                                        <td style="text-align:center;" class="text-warning"><?php echo $row['count_late']; ?></td>
                                        <td style="text-align:center;" class="text-info font-weight-bold"><?php echo $row['count_holiday']; ?></td>
                                        <td style="text-align:center;"><?php echo $row['count_leave']; ?></td>
                                        <td style="text-align:center;" class="text-success font-weight-bold"><?php echo $row['paid_leaves']; ?></td>
                                        <td style="text-align:center;" class="text-danger"><?php echo $row['count_absent']; ?></td>
                                        <td style="text-align:center;" class="bg-light font-weight-bold text-dark"><?php echo $row['days_worked']; ?></td>
                                        <td style="text-align:right; font-weight:800; color:var(--primary-color); font-size:14px;">
                                            Rs. <?php echo number_format($row['calculated_salary'], 2); ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($row['is_paid']): ?>
                                                <span class="badge badge-success px-2 py-1">
                                                    <i class="fas fa-check-circle mr-1"></i> Paid (Cash)
                                                </span>
                                                <div style="font-size:10px; color:#555; margin-top:2px;">
                                                    #<?php echo htmlspecialchars($row['voucher_no']); ?><br>
                                                    <?php echo date('d/m/Y', strtotime($row['payment_date'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge badge-warning px-2 py-1 text-dark font-weight-bold">
                                                    <i class="fas fa-clock mr-1"></i> Unpaid
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if (!$row['is_paid']): ?>
                                                <button type="button" class="btn btn-success btn-sm font-weight-bold mb-1 w-100 open-pay-modal-btn"
                                                        data-staff-id="<?php echo $row['staff_id']; ?>"
                                                        data-staff-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                        data-role="<?php echo htmlspecialchars($row['role_name']); ?>"
                                                        data-phone="<?php echo htmlspecialchars($row['phone']); ?>"
                                                        data-weekly-off="<?php echo htmlspecialchars($row['weekly_off']); ?>"
                                                        data-days-worked="<?php echo $row['days_worked']; ?>"
                                                        data-daily-rate="<?php echo $row['daily_rate']; ?>"
                                                        data-calculated-salary="<?php echo $row['calculated_salary']; ?>"
                                                        data-month="<?php echo $selected_month; ?>"
                                                        data-month-name="<?php echo $months[$selected_month]; ?>"
                                                        data-year="<?php echo $selected_year; ?>">
                                                    <i class="fas fa-money-bill-wave mr-1"></i> Pay Cash
                                                </button>
                                            <?php else: ?>
                                                <a href="generate-pdf-salary-receipt.php?payment_id=<?php echo $row['payment_id']; ?>" 
                                                   target="_blank" 
                                                   class="btn btn-primary btn-sm font-weight-bold mb-1 w-100"
                                                   style="background:var(--primary-gradient); border:none;">
                                                    <i class="fas fa-print mr-1"></i> Voucher
                                                </a>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn btn-outline-secondary btn-sm w-100" 
                                                    style="font-size:10px; padding:2px 5px;"
                                                    onclick="viewSlip({
                                                        id: <?php echo $row['staff_id']; ?>,
                                                        name: '<?php echo htmlspecialchars(addslashes($row['name'])); ?>',
                                                        role: '<?php echo htmlspecialchars(addslashes($row['role_name'])); ?>',
                                                        phone: '<?php echo htmlspecialchars(addslashes($row['phone'])); ?>',
                                                        weeklyOff: '<?php echo htmlspecialchars(addslashes($row['weekly_off'])); ?>',
                                                        dailySalary: <?php echo $row['daily_rate']; ?>,
                                                        present: <?php echo $row['count_present']; ?>,
                                                        late: <?php echo $row['count_late']; ?>,
                                                        holiday: <?php echo $row['count_holiday']; ?>,
                                                        leave: <?php echo $row['count_leave']; ?>,
                                                        paidLeaves: <?php echo $row['paid_leaves']; ?>,
                                                        absent: <?php echo $row['count_absent']; ?>,
                                                        paidDays: <?php echo $row['days_worked']; ?>,
                                                        totalSalary: <?php echo $row['calculated_salary']; ?>,
                                                        month: '<?php echo $months[$selected_month]; ?>',
                                                        year: '<?php echo $selected_year; ?>'
                                                    })">
                                                <i class="fas fa-eye mr-1"></i> Breakdown
                                            </button>
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

    <!-- Cash Salary Payment Modal -->
    <div class="modal fade" id="paySalaryModal" tabindex="-1" role="dialog" aria-labelledby="paySalaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <form id="paySalaryForm">
                    <div class="modal-header modal-header-custom">
                        <h5 class="modal-title font-weight-bold" id="paySalaryModalLabel">
                            <i class="fas fa-money-bill-wave mr-2"></i> Disburse Cash Salary
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="pay_cash">
                        <input type="hidden" name="staff_id" id="modal_staff_id">
                        <input type="hidden" name="month" id="modal_month">
                        <input type="hidden" name="year" id="modal_year">

                        <div class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center justify-content-between" style="font-size:12px;">
                            <div>
                                <i class="fas fa-info-circle mr-1"></i> <strong>Payment Policy:</strong> 100% Cash Counter Disbursal.
                            </div>
                            <span class="cash-badge"><i class="fas fa-coins mr-1"></i> Cash Only</span>
                        </div>

                        <!-- Staff Particulars & Period Card -->
                        <div class="p-3 bg-light rounded mb-3 border">
                            <div class="row mb-2">
                                <div class="col-6">
                                    <small class="text-muted text-uppercase font-weight-bold">Staff Member</small>
                                    <div class="font-weight-bold text-dark" id="modal_staff_name" style="font-size:15px;">-</div>
                                    <small class="text-muted" id="modal_staff_role">-</small>
                                    <div class="mt-1" style="font-size:12px;">
                                        <span class="badge badge-light border text-info"><i class="fas fa-umbrella-beach mr-1"></i>Off: <span id="modal_weekly_off">-</span></span>
                                    </div>
                                </div>
                                <div class="col-6 text-right">
                                    <small class="text-muted text-uppercase font-weight-bold">Salary Period</small>
                                    <div class="font-weight-bold text-primary" id="modal_period_text" style="font-size:15px;">-</div>
                                    <small class="text-muted">No Advance Deductions</small>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="row text-center">
                                <div class="col-4 border-right">
                                    <small class="text-muted">Paid Days</small>
                                    <div class="font-weight-bold text-dark" id="modal_days_worked">0</div>
                                </div>
                                <div class="col-4 border-right">
                                    <small class="text-muted">Daily Rate</small>
                                    <div class="font-weight-bold text-dark" id="modal_daily_rate">Rs. 0.00</div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Payment Mode</small>
                                    <div class="font-weight-bold text-success"><i class="fas fa-money-bill-wave"></i> Cash</div>
                                </div>
                            </div>
                        </div>

                        <!-- Calculated Salary Callout -->
                        <div class="p-3 rounded mb-3 text-center" style="background:#e8f4ec; border:1px solid #c3e6cb;">
                            <small class="text-muted text-uppercase font-weight-bold">Total Cash Amount Payable</small>
                            <div class="font-weight-bold text-success" id="modal_calculated_salary" style="font-size:26px;">
                                Rs. 0.00
                            </div>
                            <small class="text-muted">(Paid Days &times; Daily Wage Rate)</small>
                        </div>

                        <!-- Payment Date -->
                        <div class="form-group mb-3">
                            <label for="payment_date" class="font-weight-bold" style="font-size:13px;">
                                <i class="fas fa-calendar-day mr-1 text-primary"></i> Disbursal / Payment Date:
                            </label>
                            <input type="date" name="payment_date" id="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <!-- Remarks -->
                        <div class="form-group mb-0">
                            <label for="remarks" class="font-weight-bold" style="font-size:13px;">
                                <i class="fas fa-comment-dots mr-1 text-muted"></i> Remarks / Payment Memo (Optional):
                            </label>
                            <input type="text" name="remarks" id="remarks" class="form-control" placeholder="e.g. Paid in full cash at pump counter">
                        </div>

                        <div id="modal_alert" class="mt-3" style="display:none;"></div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" id="btnSubmitPayment" class="btn btn-success font-weight-bold px-4">
                            <i class="fas fa-check-circle mr-1"></i> Confirm &amp; Pay Cash
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Slip Modal -->
    <div class="modal fade" id="editSlipModal" tabindex="-1" role="dialog" aria-labelledby="editSlipModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="editSlipModalLabel"><i class="fas fa-file-invoice mr-2"></i>Staff Attendance &amp; Salary Breakdown</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="printableSlipArea">
                        <div class="slip-header">
                            <h2 class="mb-0 text-uppercase font-weight-bold" style="color:var(--primary-color);">PPMS</h2>
                            <p class="text-muted mb-1">Petrol Pump Management System</p>
                            <div class="slip-title">Salary Calculation Breakdown</div>
                            <span class="badge badge-secondary px-3 py-2 mt-1" id="slip_period_badge" style="font-size:12px;">June 2026</span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-5">
                                <div class="slip-section-title">Employee Details</div>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td style="width:110px;" class="font-weight-bold">Staff ID:</td>
                                        <td id="slip_staff_id">#12</td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Full Name:</td>
                                        <td id="slip_staff_name" class="font-weight-bold text-uppercase">John Doe</td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Designation:</td>
                                        <td id="slip_role">Manager</td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Weekly Off:</td>
                                        <td><span class="badge badge-light border text-info font-weight-bold" id="slip_weekly_off"><i class="fas fa-umbrella-beach mr-1"></i>Friday</span></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Phone:</td>
                                        <td id="slip_phone">03001234567</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div class="col-md-7">
                                <div class="slip-section-title">Attendance Registry</div>
                                <table class="table table-bordered table-sm text-center">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Present</th>
                                            <th>Late</th>
                                            <th>Holiday</th>
                                            <th>Leave</th>
                                            <th>Paid L.</th>
                                            <th>Absent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td id="slip_present" class="text-success font-weight-bold">0</td>
                                            <td id="slip_late" class="text-warning">0</td>
                                            <td id="slip_holiday" class="text-info font-weight-bold">0</td>
                                            <td id="slip_leave">0</td>
                                            <td id="slip_paid_leaves" class="text-success font-weight-bold">0</td>
                                            <td id="slip_absent" class="text-danger">0</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="text-muted" style="font-size: 11px; margin-top: -5px;">
                                    * Policy: Weekly holiday and up to <?php echo get_global_paid_leaves($connection); ?> approved leaves/month are counted as paid days.
                                </div>
                            </div>
                        </div>
                        
                        <div class="slip-section-title mt-4">Salary Summary &amp; Breakdown</div>
                        <table class="table table-bordered">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Description</th>
                                    <th class="text-center" style="width: 150px;">Calculation</th>
                                    <th class="text-right" style="width: 200px;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Daily Base Wage (Per Day Salary)</td>
                                    <td class="text-center">-</td>
                                    <td class="text-right" id="slip_daily_rate">0.00</td>
                                </tr>
                                <tr>
                                    <td>Total Paid Days (Present + Late + Holiday + Paid Leaves)</td>
                                    <td class="text-center font-weight-bold" id="slip_paid_days">0</td>
                                    <td class="text-right">-</td>
                                </tr>
                                <tr class="table-info font-weight-bold">
                                    <td style="font-size:16px;">Gross Payable Salary</td>
                                    <td class="text-center">-</td>
                                    <td class="text-right" style="font-size:18px; color:var(--primary-color);" id="slip_gross_total">0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#salaryCalculationTable').DataTable({
                "order": [[ 0, "desc" ]],
                "pageLength": 25
            });

            // Open Cash Payment Modal
            $('.open-pay-modal-btn').on('click', function() {
                var btn = $(this);
                var staffId = btn.data('staff-id');
                var staffName = btn.data('staff-name');
                var role = btn.data('role');
                var weeklyOff = btn.data('weekly-off') || 'Friday';
                var daysWorked = btn.data('days-worked');
                var dailyRate = parseFloat(btn.data('daily-rate'));
                var calculatedSalary = parseFloat(btn.data('calculated-salary'));
                var month = btn.data('month');
                var monthName = btn.data('month-name');
                var year = btn.data('year');

                $('#modal_staff_id').val(staffId);
                $('#modal_month').val(month);
                $('#modal_year').val(year);
                $('#modal_staff_name').text(staffName + ' (#' + staffId + ')');
                $('#modal_staff_role').text(role);
                $('#modal_weekly_off').text(weeklyOff);
                $('#modal_period_text').text(monthName + ' ' + year);
                $('#modal_days_worked').text(daysWorked + ' Days');
                $('#modal_daily_rate').text('Rs. ' + dailyRate.toFixed(2));
                $('#modal_calculated_salary').text('Rs. ' + calculatedSalary.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('#modal_alert').hide().html('');
                $('#remarks').val('');
                $('#btnSubmitPayment').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Confirm &amp; Pay Cash');

                $('#paySalaryModal').modal('show');
            });

            // Process Cash Payment AJAX
            $('#paySalaryForm').on('submit', function(e) {
                e.preventDefault();
                var btn = $('#btnSubmitPayment');
                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Processing...');

                $.ajax({
                    url: 'process-salary-payment.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            var alertHtml = '<div class="alert alert-success">' +
                                '<i class="fas fa-check-circle mr-1"></i> ' + response.message +
                                '<div class="mt-2">' +
                                    '<a href="generate-pdf-salary-receipt.php?payment_id=' + response.payment_id + '" target="_blank" class="btn btn-sm btn-primary">' +
                                        '<i class="fas fa-print mr-1"></i> Print Cash Voucher' +
                                    '</a>' +
                                '</div>' +
                                '</div>';
                            $('#modal_alert').html(alertHtml).show();
                            setTimeout(function() {
                                location.reload();
                            }, 1800);
                        } else {
                            $('#modal_alert').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> ' + response.message + '</div>').show();
                            btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Confirm &amp; Pay Cash');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#modal_alert').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> Server error processing payment.</div>').show();
                        btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Confirm &amp; Pay Cash');
                    }
                });
            });
        });

        function viewSlip(data) {
            $('#slip_staff_id').text('#' + data.id);
            $('#slip_staff_name').text(data.name);
            $('#slip_role').text(data.role);
            $('#slip_phone').text(data.phone || 'N/A');
            $('#slip_weekly_off').html('<i class="fas fa-umbrella-beach mr-1"></i>' + (data.weeklyOff || 'Friday'));
            
            $('#slip_present').text(data.present);
            $('#slip_late').text(data.late);
            $('#slip_holiday').text(data.holiday || 0);
            $('#slip_leave').text(data.leave);
            $('#slip_paid_leaves').text(data.paidLeaves || 0);
            $('#slip_absent').text(data.absent);
            
            var formattedRate = parseFloat(data.dailySalary).toFixed(2);
            $('#slip_daily_rate').text(formattedRate);
            $('#slip_paid_days').text(data.paidDays);
            
            var formattedGross = parseFloat(data.totalSalary).toFixed(2);
            $('#slip_gross_total').text(formattedGross);
            
            $('#slip_period_badge').text(data.month + ' ' + data.year);
            
            $('#editSlipModal').modal('show');
        }
    </script>
</body>
</html>
