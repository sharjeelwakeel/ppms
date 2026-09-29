<?php
/**
 * Printable Cash Salary Disbursement Voucher / Receipt
 * PPMS (Petrol Pump Management System)
 * 
 * Standard printable A4 receipt for staff cash salary payments with station letterhead,
 * attendance register breakdown, daily rate, total cash disbursed, and dual signatures.
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
require_once __DIR__ . '/../include/settings_helper.php';
require_once __DIR__ . '/../include/salary_payment_helper.php';

// RBAC Gate
if (!has_permission('staff', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
$staff_id   = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
$month      = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year       = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

$payment_record = null;

if ($payment_id > 0) {
    $q = mysqli_query($connection, "SELECT 
                pay.*,
                s.first_name,
                s.last_name,
                s.phone,
                r.name AS role_name,
                u.username AS paid_by_user
            FROM tbl_staff_salary_payments pay
            JOIN tbl_staff s ON pay.staff_id = s.id
            LEFT JOIN tbl_staff_roles r ON s.role_id = r.id
            LEFT JOIN tbl_accounts u ON pay.paid_by = u.id
            WHERE pay.id = '$payment_id' AND (pay.deleted_at IS NULL OR pay.deleted_at = '0000-00-00 00:00:00')
            LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $payment_record = $r;
        $staff_id = intval($r['staff_id']);
        $month = intval($r['salary_month']);
        $year = intval($r['salary_year']);
    }
}

if (!$payment_record && $staff_id > 0) {
    $q = mysqli_query($connection, "SELECT 
                pay.*,
                s.first_name,
                s.last_name,
                s.phone,
                r.name AS role_name,
                u.username AS paid_by_user
            FROM tbl_staff_salary_payments pay
            JOIN tbl_staff s ON pay.staff_id = s.id
            LEFT JOIN tbl_staff_roles r ON s.role_id = r.id
            LEFT JOIN tbl_accounts u ON pay.paid_by = u.id
            WHERE pay.staff_id = '$staff_id' 
              AND pay.salary_month = '$month' 
              AND pay.salary_year = '$year'
              AND (pay.deleted_at IS NULL OR pay.deleted_at = '0000-00-00 00:00:00')
            LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $payment_record = $r;
    }
}

// Fetch staff & attendance calculation details
$all_records = get_staff_monthly_salary_records($connection, $month, $year, $staff_id);
if (empty($all_records)) {
    die("Error: Staff record not found or invalid parameters.");
}
$staff_detail = $all_records[0];

$station = get_station_settings($connection);
$station_name = !empty($station['name']) ? $station['name'] : 'PETROL PUMP MANAGEMENT SYSTEM';
$station_address = !empty($station['address']) ? $station['address'] : '';
$station_phone = !empty($station['phone']) ? $station['phone'] : '';

$months_list = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$period_text = ($months_list[$month] ?? 'Month ' . $month) . ' ' . $year;

$voucher_no = $payment_record['voucher_no'] ?? ('PROV-' . date('Ym') . '-' . $staff_detail['staff_id']);
$payment_date = !empty($payment_record['payment_date']) ? date('d/m/Y', strtotime($payment_record['payment_date'])) : date('d/m/Y');
$disbursed_amount = $payment_record ? floatval($payment_record['paid_amount']) : floatval($staff_detail['calculated_salary']);
$amount_words = convert_salary_amount_to_words($disbursed_amount);
$is_confirmed = !empty($payment_record);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary_Voucher_<?php echo htmlspecialchars($voucher_no); ?>_<?php echo htmlspecialchars($staff_detail['name']); ?></title>
    <link rel="stylesheet" href="../include/css/roboto.css">
    <link rel="stylesheet" href="../include/css/bootstrap.min.css">
    <link rel="stylesheet" href="../include/css/all.min.css">
    <style>
        body {
            background-color: #525659;
            margin: 0;
            padding: 20px 0;
            font-family: 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #111;
        }

        .no-print-bar {
            background: #ffffff;
            border-bottom: 1px solid #ddd;
            padding: 12px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .receipt-paper {
            background: #ffffff;
            width: 210mm;
            min-height: 270mm;
            margin: 0 auto;
            padding: 15mm 18mm 18mm 18mm;
            box-shadow: 0 4px 15px rgba(0,0,0,0.25);
            box-sizing: border-box;
            position: relative;
        }

        .receipt-header-title {
            font-size: 21pt;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #04204e;
            margin-bottom: 2px;
            text-align: center;
        }

        .receipt-header-sub {
            font-size: 11pt;
            font-weight: 500;
            color: #555;
            margin-bottom: 3px;
            text-align: center;
        }

        .receipt-badge-title {
            display: inline-block;
            background: #04204e;
            color: #fff;
            font-weight: 700;
            font-size: 12pt;
            padding: 4px 20px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 10px;
            margin-bottom: 12px;
        }

        .info-box {
            border: 1px solid #c2c9d6;
            border-radius: 6px;
            padding: 12px 16px;
            background: #fbfcfe;
            margin-bottom: 18px;
        }

        .info-table td {
            padding: 3px 6px;
            font-size: 10.5pt;
            vertical-align: middle;
        }

        .info-label {
            font-weight: 700;
            color: #04204e;
            width: 140px;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .table-custom th, .table-custom td {
            border: 1px solid #333;
            padding: 8px 10px;
            font-size: 10pt;
        }

        .table-custom thead th {
            background-color: #04204e;
            color: #fff;
            font-weight: 700;
            text-align: center;
        }

        .salary-summary-table td {
            font-size: 11pt;
            padding: 8px 12px;
            border: 1px solid #bbb;
        }

        .grand-total-row {
            background-color: #e8f0fe !important;
            font-size: 13pt !important;
            font-weight: 900 !important;
            color: #04204e !important;
        }

        .amount-words-box {
            border: 1px dashed #04204e;
            background: #f4f7fb;
            padding: 10px 14px;
            font-size: 10.5pt;
            font-weight: 700;
            color: #04204e;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .sign-area {
            margin-top: 60px;
        }

        .sign-line {
            border-top: 1.5px solid #222;
            width: 220px;
            margin: 0 auto;
            padding-top: 6px;
            text-align: center;
            font-size: 10.5pt;
            font-weight: 700;
        }

        .watermark-paid {
            position: absolute;
            top: 40%;
            left: 30%;
            transform: rotate(-30deg);
            font-size: 75pt;
            font-weight: 900;
            color: rgba(40, 167, 69, 0.12);
            border: 8px solid rgba(40, 167, 69, 0.15);
            padding: 10px 40px;
            border-radius: 12px;
            pointer-events: none;
            letter-spacing: 10px;
        }

        @media print {
            body {
                background: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .receipt-paper {
                box-shadow: none !important;
                margin: 0 !important;
                width: 100% !important;
                padding: 10mm 15mm !important;
            }
        }
    </style>
</head>
<body>

    <!-- Action Bar (No-Print) -->
    <div class="no-print-bar d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0 font-weight-bold" style="color: #04204e;">
                <i class="fas fa-file-invoice-dollar mr-2 text-success"></i> Cash Salary Disbursement Voucher
            </h5>
            <small class="text-muted">Staff: <?php echo htmlspecialchars($staff_detail['name']); ?> | Period: <?php echo $period_text; ?></small>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary px-4 py-2 font-weight-bold" style="background: #04204e; border-color: #04204e;">
                <i class="fas fa-print mr-2"></i> Print Voucher
            </button>
            <button onclick="window.close()" class="btn btn-secondary px-3 py-2 ml-2">
                <i class="fas fa-times mr-1"></i> Close
            </button>
        </div>
    </div>

    <!-- Printable Receipt Paper -->
    <div class="receipt-paper">
        <?php if ($is_confirmed): ?>
            <div class="watermark-paid">PAID</div>
        <?php endif; ?>

        <!-- Letterhead Header -->
        <div class="text-center mb-2">
            <div class="receipt-header-title"><?php echo htmlspecialchars($station_name); ?></div>
            <?php if (!empty($station_address)): ?>
                <div class="receipt-header-sub"><i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($station_address); ?></div>
            <?php endif; ?>
            <?php if (!empty($station_phone)): ?>
                <div class="receipt-header-sub"><i class="fas fa-phone mr-1"></i> Phone: <?php echo htmlspecialchars($station_phone); ?></div>
            <?php endif; ?>
            <div>
                <span class="receipt-badge-title">STAFF CASH SALARY VOUCHER</span>
            </div>
        </div>

        <!-- Voucher Details & Staff Particulars -->
        <div class="info-box">
            <div class="row">
                <div class="col-6">
                    <table class="info-table w-100">
                        <tr>
                            <td class="info-label">Voucher No:</td>
                            <td><strong style="color: #04204e; font-size:11.5pt;"><?php echo htmlspecialchars($voucher_no); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Payment Date:</td>
                            <td><strong><?php echo htmlspecialchars($payment_date); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Salary Month:</td>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($period_text); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Payment Mode:</td>
                            <td><span class="badge badge-success px-2 py-1 font-weight-bold"><i class="fas fa-money-bill-wave mr-1"></i> CASH</span></td>
                        </tr>
                    </table>
                </div>
                <div class="col-6">
                    <table class="info-table w-100">
                        <tr>
                            <td class="info-label">Staff ID:</td>
                            <td><strong>#<?php echo intval($staff_detail['staff_id']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Staff Name:</td>
                            <td><strong class="text-uppercase" style="font-size:11pt;"><?php echo htmlspecialchars($staff_detail['name']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Designation:</td>
                            <td><?php echo htmlspecialchars($staff_detail['role_name']); ?></td>
                        </tr>
                        <tr>
                            <td class="info-label">Weekly Off:</td>
                            <td><strong style="color: #04204e;"><i class="fas fa-umbrella-beach mr-1 text-info"></i><?php echo htmlspecialchars($staff_detail['weekly_off'] ?? 'Friday'); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="info-label">Phone:</td>
                            <td><?php echo htmlspecialchars($staff_detail['phone'] ?: 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Attendance Registry Breakdown -->
        <div class="font-weight-bold mb-1" style="font-size: 11pt; color: #04204e;">
            <i class="fas fa-calendar-check mr-1"></i> Attendance Registry Breakdown (<?php echo $period_text; ?>)
        </div>
        <table class="table-custom text-center">
            <thead>
                <tr>
                    <th>Present</th>
                    <th>Late</th>
                    <th>Weekly Holiday</th>
                    <th>Leave Taken</th>
                    <th>Paid Leaves</th>
                    <th>Absent (Unpaid)</th>
                    <th style="background:#07347a;">Total Paid Days</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="font-weight-bold text-success"><?php echo $staff_detail['count_present']; ?></td>
                    <td class="font-weight-bold text-warning"><?php echo $staff_detail['count_late']; ?></td>
                    <td class="text-info font-weight-bold"><?php echo $staff_detail['count_holiday']; ?></td>
                    <td><?php echo $staff_detail['count_leave']; ?></td>
                    <td class="text-success font-weight-bold"><?php echo $staff_detail['paid_leaves']; ?></td>
                    <td class="text-danger font-weight-bold"><?php echo $staff_detail['count_absent']; ?></td>
                    <td class="font-weight-bold" style="background:#f0f4ff; font-size:11pt; color:#04204e;">
                        <?php echo $staff_detail['days_worked']; ?> Days
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="text-muted mb-3" style="font-size: 8.5pt;">
            * Policy: Weekly holiday (<?php echo htmlspecialchars($staff_detail['weekly_off'] ?? 'Friday'); ?>) and up to <?php echo get_global_paid_leaves($connection); ?> approved leaves per month are counted as paid days.
        </div>

        <!-- Salary Calculation Breakdown -->
        <div class="font-weight-bold mb-1" style="font-size: 11pt; color: #04204e;">
            <i class="fas fa-calculator mr-1"></i> Cash Disbursement Statement
        </div>
        <table class="table-custom salary-summary-table mb-3">
            <thead>
                <tr>
                    <th style="text-align:left;">Description</th>
                    <th style="width: 25%; text-align:center;">Factor / Days</th>
                    <th style="width: 30%; text-align:right;">Amount (PKR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Daily Base Wage Rate</td>
                    <td class="text-center">Per Working Day</td>
                    <td class="text-right font-weight-bold">Rs. <?php echo number_format($staff_detail['daily_rate'], 2); ?></td>
                </tr>
                <tr>
                    <td>Paid Working Days (Present + Late + Holiday + Paid Leaves)</td>
                    <td class="text-center font-weight-bold"><?php echo $staff_detail['days_worked']; ?> Days</td>
                    <td class="text-right">-</td>
                </tr>
                <tr>
                    <td>Advance Salary Deductions</td>
                    <td class="text-center text-muted">No Advance Policy</td>
                    <td class="text-right">Rs. 0.00</td>
                </tr>
                <tr class="grand-total-row">
                    <td><strong>NET CASH SALARY PAID</strong></td>
                    <td class="text-center font-weight-bold"><?php echo $staff_detail['days_worked']; ?> &times; Rs. <?php echo number_format($staff_detail['daily_rate'], 2); ?></td>
                    <td class="text-right font-weight-bold" style="font-size:14pt;">Rs. <?php echo number_format($disbursed_amount, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Amount In Words -->
        <div class="amount-words-box">
            <div><small class="text-muted text-uppercase font-weight-bold">Amount in Words:</small></div>
            <div style="font-size: 11pt; margin-top: 3px;"><?php echo htmlspecialchars($amount_words); ?></div>
        </div>

        <!-- Remarks & Audit info if present -->
        <?php if (!empty($payment_record['remarks'])): ?>
            <div style="font-size:10pt; margin-bottom: 20px;">
                <strong>Remarks / Notes:</strong> <?php echo htmlspecialchars($payment_record['remarks']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($payment_record['paid_by_user'])): ?>
            <div class="text-muted" style="font-size: 9pt; margin-bottom: 20px;">
                Disbursed by System User: <strong><?php echo htmlspecialchars($payment_record['paid_by_user']); ?></strong> on <?php echo date('d/m/Y h:i A', strtotime($payment_record['created_at'])); ?>
            </div>
        <?php endif; ?>

        <!-- Dual Signature Area -->
        <div class="row sign-area">
            <div class="col-6 text-center">
                <div class="sign-line">
                    Employee / Receiver Signature<br>
                    <small class="text-muted">(<?php echo htmlspecialchars($staff_detail['name']); ?>)</small>
                </div>
            </div>
            <div class="col-6 text-center">
                <div class="sign-line">
                    Manager / Authorized Cashier<br>
                    <small class="text-muted">(PPMS Cash Counter)</small>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
