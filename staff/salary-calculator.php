<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';

// Default to current month and year
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Construct year-month string for SQL filtering
$month_str = sprintf('%04d-%02d', $selected_year, $selected_month);

// Fetch all staff members with their role, leave setup, and attendance aggregates
$sql = "SELECT 
            s.id,
            s.first_name,
            s.last_name,
            s.salary as daily_salary,
            s.phone,
            r.name as role_name,
            COALESCE(ls.allowed_leaves, 0) as allowed_leaves,
            SUM(CASE WHEN sa.status = 'Present' THEN 1 ELSE 0 END) as count_present,
            SUM(CASE WHEN sa.status = 'Late' THEN 1 ELSE 0 END) as count_late,
            SUM(CASE WHEN sa.status = 'Leave' THEN 1 ELSE 0 END) as count_leave,
            SUM(CASE WHEN sa.status = 'Absent' THEN 1 ELSE 0 END) as count_absent,
            COUNT(sa.status) as total_attendance_marked
        FROM tbl_staff s
        LEFT JOIN tbl_roles r ON s.role_id = r.id
        LEFT JOIN tbl_leave_setup ls ON s.id = ls.staff_id
        LEFT JOIN tbl_staff_attendance sa ON s.id = sa.staff_id AND sa.date LIKE '$month_str-%'
        GROUP BY s.id
        ORDER BY s.id DESC";
$result = mysqli_query($connection, $sql);

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
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
    <title>PPMS - Salary Calculation</title>
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
            padding: 5px 12px; border-radius: 6px; font-size: 12px;
            transition: all .2s;
        }
        .btn-slip:hover { opacity: .9; color: #fff; transform: translateY(-1px); }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
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
        
        @media print {
            body * { display: none !important; }
            #editSlipModal, #editSlipModal * { display: block !important; }
            .modal-dialog { max-width: 100% !important; margin: 0 !important; }
            .modal-content { border: none !important; box-shadow: none !important; }
            .modal-header, .modal-footer { display: none !important; }
            #printableSlipArea { display: block !important; border: none !important; padding: 0 !important; }
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
                    <h4><i class="fas fa-money-check-alt mr-2"></i>Salary Calculation & Payroll</h4>
                    <small style="opacity:.8;">Calculate monthly payroll based on daily attendance registers and leave configuration</small>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-4" style="border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
                <div class="card-body">
                    <form method="GET" action="salary-calculator.php" class="form-inline">
                        <label class="my-1 mr-2 font-weight-bold" for="month">Month:</label>
                        <select name="month" id="month" class="form-control mr-sm-3" required>
                            <?php foreach ($months as $m => $name): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($selected_month === $m) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="my-1 mr-2 font-weight-bold" for="year">Year:</label>
                        <select name="year" id="year" class="form-control mr-sm-3" required>
                            <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($selected_year === $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>

                        <button type="submit" class="btn btn-primary my-1"><i class="fas fa-calculator mr-1"></i> Calculate Salary</button>
                    </form>
                </div>
            </div>

            <!-- List Card -->
            <div class="list-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0">Salary Summary for <strong><?php echo $months[$selected_month] . ' ' . $selected_year; ?></strong></h5>
                </div>
                <div class="table-responsive">
                    <table id="salaryCalculationTable" class="table table-bordered table-striped" style="width:100%;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Staff Name</th>
                                <th>Role</th>
                                <th style="text-align:right;">Per Day Salary</th>
                                <th style="text-align:center;">Present</th>
                                <th style="text-align:center;">Late</th>
                                <th style="text-align:center;">Leave</th>
                                <th style="text-align:center;">Allowed Leaves</th>
                                <th style="text-align:center;">Paid Leaves</th>
                                <th style="text-align:center;">Absent</th>
                                <th style="text-align:center;">Paid Days</th>
                                <th style="text-align:right;">Total Salary</th>
                                <th style="text-align:center;">Salary Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($result)): 
                                    $fullName = $row['first_name'] . ' ' . $row['last_name'];
                                    $daily_salary = (float)$row['daily_salary'];
                                    $allowed_leaves = (int)$row['allowed_leaves'];
                                    
                                    $count_present = (int)$row['count_present'];
                                    $count_late = (int)$row['count_late'];
                                    $count_leave = (int)$row['count_leave'];
                                    $count_absent = (int)$row['count_absent'];
                                    
                                    // Paid leaves is capped by allowed leaves limit
                                    $paid_leaves = min($count_leave, $allowed_leaves);
                                    $unpaid_leaves = $count_leave - $paid_leaves;
                                    
                                    // Total paid days = Present + Late + Paid Leaves
                                    $paid_days = $count_present + $count_late + $paid_leaves;
                                    $unpaid_days = $count_absent + $unpaid_leaves;
                                    
                                    $total_salary = $paid_days * $daily_salary;
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['role_name'] ?? 'N/A'); ?></td>
                                        <td style="text-align:right; font-weight:600;"><?php echo number_format($daily_salary, 2); ?></td>
                                        <td style="text-align:center;" class="text-success font-weight-bold"><?php echo $count_present; ?></td>
                                        <td style="text-align:center;" class="text-warning"><?php echo $count_late; ?></td>
                                        <td style="text-align:center;" class="text-info"><?php echo $count_leave; ?></td>
                                        <td style="text-align:center;"><?php echo $allowed_leaves; ?></td>
                                        <td style="text-align:center;" class="text-success"><?php echo $paid_leaves; ?></td>
                                        <td style="text-align:center;" class="text-danger"><?php echo $count_absent; ?></td>
                                        <td style="text-align:center;" class="bg-light font-weight-bold text-dark"><?php echo $paid_days; ?></td>
                                        <td style="text-align:right; font-weight:800; color:var(--primary-color);"><?php echo number_format($total_salary, 2); ?></td>
                                        <td style="text-align:center;">
                                            <button class="btn btn-slip btn-sm" 
                                                    onclick="viewSlip({
                                                        id: <?php echo $row['id']; ?>,
                                                        name: '<?php echo htmlspecialchars(addslashes($fullName)); ?>',
                                                        role: '<?php echo htmlspecialchars(addslashes($row['role_name'] ?? 'N/A')); ?>',
                                                        phone: '<?php echo htmlspecialchars(addslashes($row['phone'])); ?>',
                                                        dailySalary: <?php echo $daily_salary; ?>,
                                                        present: <?php echo $count_present; ?>,
                                                        late: <?php echo $count_late; ?>,
                                                        leave: <?php echo $count_leave; ?>,
                                                        allowed: <?php echo $allowed_leaves; ?>,
                                                        paidLeaves: <?php echo $paid_leaves; ?>,
                                                        absent: <?php echo $count_absent; ?>,
                                                        paidDays: <?php echo $paid_days; ?>,
                                                        totalSalary: <?php echo $total_salary; ?>,
                                                        month: '<?php echo $months[$selected_month]; ?>',
                                                        year: '<?php echo $selected_year; ?>'
                                                    })">
                                                <i class="fas fa-file-invoice mr-1"></i> Slip
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- View Slip Modal -->
    <div class="modal fade" id="editSlipModal" tabindex="-1" role="dialog" aria-labelledby="editSlipModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="editSlipModalLabel"><i class="fas fa-file-invoice mr-2"></i>Staff Salary Slip</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="printableSlipArea">
                        <div class="slip-header">
                            <h2 class="mb-0 text-uppercase font-weight-bold" style="color:var(--primary-color);">PPMS</h2>
                            <p class="text-muted mb-1">Petrol Pump Management System</p>
                            <div class="slip-title">Salary Slip</div>
                            <span class="badge badge-secondary px-3 py-2 mt-1" id="slip_period_badge" style="font-size:12px;">June 2026</span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="slip-section-title">Employee Details</div>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td style="width:120px;" class="font-weight-bold">Staff ID:</td>
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
                                        <td class="font-weight-bold">Phone Number:</td>
                                        <td id="slip_phone">03001234567</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="slip-section-title">Attendance Registry</div>
                                <table class="table table-bordered table-sm text-center">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Present</th>
                                            <th>Late</th>
                                            <th>Leave</th>
                                            <th>Absent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td id="slip_present" class="text-success font-weight-bold">0</td>
                                            <td id="slip_late" class="text-warning">0</td>
                                            <td id="slip_leave" class="text-info">0</td>
                                            <td id="slip_absent" class="text-danger">0</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="text-muted" style="font-size: 11px; margin-top: -5px;">
                                    * Allowed Leaves Setup: <strong id="slip_allowed_leaves">0</strong> day(s) / month.<br>
                                    * Paid Leaves Granted: <strong id="slip_paid_leaves">0</strong> day(s).
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
                                    <td>Total Paid Days (Present + Late + Paid Leaves)</td>
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
                        
                        <div class="row" style="margin-top: 60px;">
                            <div class="col-md-6 text-center">
                                <div style="border-top: 1px solid #999; width: 200px; margin: 0 auto; padding-top: 5px;">
                                    <strong>Employee Signature</strong>
                                </div>
                            </div>
                            <div class="col-md-6 text-center">
                                <div style="border-top: 1px solid #999; width: 200px; margin: 0 auto; padding-top: 5px;">
                                    <strong>Manager / Admin</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print mr-2"></i>Print Slip</button>
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
                "order": [[ 0, "desc" ]]
            });
        });

        function viewSlip(data) {
            $('#slip_staff_id').text('#' + data.id);
            $('#slip_staff_name').text(data.name);
            $('#slip_role').text(data.role);
            $('#slip_phone').text(data.phone || 'N/A');
            
            $('#slip_present').text(data.present);
            $('#slip_late').text(data.late);
            $('#slip_leave').text(data.leave);
            $('#slip_absent').text(data.absent);
            
            $('#slip_allowed_leaves').text(data.allowed);
            $('#slip_paid_leaves').text(data.paidLeaves);
            
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
