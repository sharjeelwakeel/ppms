<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('credit_sales', 'add');

// Ensure auxiliary columns exist (self-healing migration)
$aux_cols = [
    'temp_slip_id'       => "INT(11) DEFAULT NULL AFTER wasoli",
    'temp_slip_no'       => "VARCHAR(64) DEFAULT NULL AFTER temp_slip_id",
    'temp_slip_date'     => "DATE DEFAULT NULL AFTER temp_slip_no",
    'sale_date'          => "DATE NULL AFTER nozzle_id",
    'temp_rate'          => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER temp_slip_date",
    'ref_slip_no'        => "VARCHAR(128) DEFAULT NULL AFTER temp_rate",
    'ref_slip_date'      => "DATE DEFAULT NULL AFTER ref_slip_no",
    'settled_in_slip_id' => "INT(11) DEFAULT NULL AFTER ref_slip_date"
];
foreach ($aux_cols as $ac => $def) {
    $chk = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE '$ac'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN $ac $def");
    }
}

// Fetch Nozzles with attached items and rates
$nozzles = [];
$q_noz = mysqli_query($connection, "SELECT n.id, n.name, n.tank_id, n.item_id, n.start_reading,
                                           i.name AS item_name, i.cash_rate, i.credit_rate
                                    FROM tbl_nozzles n
                                    LEFT JOIN tbl_items i ON n.item_id = i.id
                                    WHERE n.deleted_at IS NULL AND n.status = 'Active'
                                    ORDER BY n.name ASC");
if ($q_noz) {
    while ($r = mysqli_fetch_assoc($q_noz)) {
        $nozzles[] = $r;
    }
}

// Fetch Vehicles for autocomplete & customer resolution
$vehicles = [];
$q_veh = mysqli_query($connection, "SELECT v.id, v.customer_id, v.vehicle_name, v.reg_number, v.numeric_number, v.fuel_limit,
                                           c.name AS customer_name, c.fuel_rate
                                    FROM tbl_customer_vehicles v
                                    LEFT JOIN tbl_customers c ON v.customer_id = c.id
                                    WHERE v.deleted_at IS NULL AND v.status = 'Active'
                                    ORDER BY v.reg_number ASC");
if ($q_veh) {
    while ($r = mysqli_fetch_assoc($q_veh)) {
        $vehicles[] = $r;
    }
}

// Fetch active shifts
$shifts = [];
$q_shift = mysqli_query($connection, "SELECT id, name FROM tbl_shifts WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
if ($q_shift) {
    while ($r = mysqli_fetch_assoc($q_shift)) {
        $shifts[] = $r;
    }
}

$error_msg = '';
$success_msg = '';
$posted_rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sale_date = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? date('Y-m-d'));
    $shift_id  = intval($_POST['shift_id'] ?? 0);
    
    $nozzles_arr      = $_POST['credit_nozzle_id'] ?? [];
    $slip_dates_arr   = $_POST['credit_slip_date'] ?? [];
    $slip_nos         = $_POST['credit_slip_no'] ?? [];
    $slip_types       = $_POST['credit_slip_type'] ?? [];
    $vehicles_arr     = $_POST['credit_vehicle_number'] ?? [];
    $accounts_arr     = $_POST['credit_account_number'] ?? [];
    $qtys_arr         = $_POST['credit_quantity'] ?? [];
    $rates_arr        = $_POST['credit_rate'] ?? [];
    $amounts_arr      = $_POST['credit_amount'] ?? [];
    $charges_arr      = $_POST['credit_charge_amount'] ?? [];
    $cash_rates       = $_POST['credit_cash_rate'] ?? [];
    $issue_qtys       = $_POST['credit_issue_quantity'] ?? [];
    $bal1_arr         = $_POST['credit_balance_1'] ?? [];
    $bal2_arr         = $_POST['credit_balance_2'] ?? [];
    $wasoli_arr       = $_POST['credit_wasoli'] ?? [];
    $temp_slip_ids    = $_POST['credit_temp_slip_id'] ?? [];
    $temp_slip_nos    = $_POST['credit_temp_slip_no'] ?? [];
    $temp_slip_dates  = $_POST['credit_temp_slip_date'] ?? [];
    $temp_rates       = $_POST['credit_temp_rate'] ?? [];
    $ref_slip_nos     = $_POST['credit_ref_slip_no'] ?? [];
    $ref_slip_dates   = $_POST['credit_ref_slip_date'] ?? [];

    // Capture submitted rows to prevent data loss on validation failure
    if (!empty($nozzles_arr)) {
        for ($i = 0; $i < count($nozzles_arr); $i++) {
            $posted_rows[] = [
                'nozzle_id'        => $nozzles_arr[$i] ?? '',
                'slip_type'        => $slip_types[$i] ?? 'Permanent Slip',
                'slip_date'        => $slip_dates_arr[$i] ?? date('Y-m-d'),
                'slip_no'          => $slip_nos[$i] ?? '',
                'vehicle_number'   => $vehicles_arr[$i] ?? '',
                'account_number'   => $accounts_arr[$i] ?? '',
                'quantity'         => $qtys_arr[$i] ?? '0',
                'rate'             => $rates_arr[$i] ?? '0',
                'amount'           => $amounts_arr[$i] ?? '0',
                'charge_amount'    => $charges_arr[$i] ?? '0',
                'cash_rate'        => $cash_rates[$i] ?? '0',
                'issue_quantity'   => $issue_qtys[$i] ?? '0',
                'balance_1'        => $bal1_arr[$i] ?? '0',
                'balance_2'        => $bal2_arr[$i] ?? '0',
                'wasoli'           => $wasoli_arr[$i] ?? '0',
                'temp_slip_id'     => $temp_slip_ids[$i] ?? '',
                'temp_slip_no'     => $temp_slip_nos[$i] ?? '',
                'temp_slip_date'   => $temp_slip_dates[$i] ?? '',
                'temp_rate'        => $temp_rates[$i] ?? '0',
                'ref_slip_no'      => $ref_slip_nos[$i] ?? '',
                'ref_slip_date'    => $ref_slip_dates[$i] ?? '',
            ];
        }
    }

    if (empty($shift_id)) {
        $error_msg = 'Please select a Shift before saving.';
    } elseif (empty($nozzles_arr)) {
        $error_msg = 'Please add at least one credit sale row before saving.';
    } else {
        // Validate slip numbers and slip dates
        $valid = true;
        for ($i = 0; $i < count($nozzles_arr); $i++) {
            if (empty(trim($slip_nos[$i] ?? ''))) {
                $error_msg = 'Slip No is mandatory on row #' . ($i + 1) . '.';
                $valid = false;
                break;
            }
        }

        if ($valid) {
            mysqli_begin_transaction($connection);
            try {
                for ($i = 0; $i < count($nozzles_arr); $i++) {
                    $noz_id        = intval($nozzles_arr[$i]);
                    $row_slip_date = !empty(trim($slip_dates_arr[$i] ?? '')) ? mysqli_real_escape_string($connection, trim($slip_dates_arr[$i])) : $sale_date;
                    $slip_no       = mysqli_real_escape_string($connection, trim($slip_nos[$i]));
                    $slip_type     = mysqli_real_escape_string($connection, trim($slip_types[$i] ?? 'Permanent Slip'));
                    $veh_num       = mysqli_real_escape_string($connection, trim($vehicles_arr[$i] ?? ''));
                    $acc_num       = mysqli_real_escape_string($connection, trim($accounts_arr[$i] ?? ''));
                    $qty           = floatval($qtys_arr[$i] ?? 0);
                    $rate          = floatval($rates_arr[$i] ?? 0);
                    $cash_rate     = floatval($cash_rates[$i] ?? 0);
                    $issue_qty     = floatval($issue_qtys[$i] ?? 0);
                    $wasoli        = floatval($wasoli_arr[$i] ?? 0);
                    $temp_id       = intval($temp_slip_ids[$i] ?? 0);
                    $temp_no       = mysqli_real_escape_string($connection, trim($temp_slip_nos[$i] ?? ''));
                    $temp_date_str = trim($temp_slip_dates[$i] ?? '');
                    $temp_date_sql = !empty($temp_date_str) ? "'" . mysqli_real_escape_string($connection, $temp_date_str) . "'" : "NULL";
                    $temp_rate     = floatval($temp_rates[$i] ?? 0);
                    $ref_no        = mysqli_real_escape_string($connection, trim($ref_slip_nos[$i] ?? ''));
                    $ref_date_str  = trim($ref_slip_dates[$i] ?? '');
                    $ref_date_sql  = !empty($ref_date_str) ? "'" . mysqli_real_escape_string($connection, $ref_date_str) . "'" : "NULL";

                    $amount        = round($qty * $rate, 2);
                    $charge_amt    = 0.00;
                    $bal1          = 0.00;
                    $bal2          = 0.00;
                    $is_ret        = 0;
                    $ret_at        = "NULL";

                    if ($slip_type === 'Permanent Slip') {
                        // Safety fallback: if linked temporary slip exists but wasoli was 0, retrieve from temp slip
                        if ((!empty($temp_id) || !empty($temp_no)) && $wasoli <= 0) {
                            $ts_chk = null;
                            if ($temp_id > 0) {
                                $ts_chk = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE id = '$temp_id'");
                            }
                            if ((!$ts_chk || mysqli_num_rows($ts_chk) == 0) && !empty($temp_no)) {
                                $ts_no_safe = mysqli_real_escape_string($connection, $temp_no);
                                $ts_acc_safe = mysqli_real_escape_string($connection, $acc_num);
                                $ts_chk = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE slip_no = '$ts_no_safe' AND slip_type = 'Temporary Slip' AND account_number = '$ts_acc_safe' ORDER BY id DESC LIMIT 1");
                            }
                            if ($ts_chk && $ts_row = mysqli_fetch_assoc($ts_chk)) {
                                $wasoli = floatval($ts_row['quantity']);
                                if ($temp_rate <= 0) {
                                    $temp_rate = floatval($ts_row['rate']);
                                }
                            }
                        }

                        $eff_issue = ($issue_qty > 0) ? $issue_qty : $qty;
                        $bal1      = max(0.00, round($eff_issue - $qty, 2));
                        $t_rate    = ($temp_rate > 0) ? $temp_rate : $rate;
                        $charge_amt = round(($eff_issue * $rate) + ($wasoli * $t_rate), 2);
                    } elseif ($slip_type === 'Balanced Slip') {
                        $charge_amt = 0.00;
                        $bal1       = floatval($bal1_arr[$i] ?? 0);
                        $bal2       = floatval($bal2_arr[$i] ?? 0);
                    } elseif ($slip_type === 'Temporary Slip') {
                        $charge_amt = 0.00;
                        $issue_qty  = $qty;
                        $wasoli     = 0.00;
                        $is_ret     = 0;
                    }

                    $ins_sql = "INSERT INTO tbl_meter_reading_credit_sales 
                                (nozzle_id, sale_date, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
                                 quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli,
                                 temp_slip_id, temp_slip_no, temp_slip_date, temp_rate, ref_slip_no, ref_slip_date, is_returned, returned_at)
                                VALUES 
                                ('$noz_id', '$sale_date', '$row_slip_date', '$shift_id', '$slip_no', '$slip_type', '$acc_num', '$veh_num',
                                 '$qty', '$rate', '$amount', '$charge_amt', '$cash_rate', '$issue_qty', '$bal1', '$bal2', '$wasoli',
                                 " . ($temp_id > 0 ? "'$temp_id'" : "NULL") . ", '$temp_no', $temp_date_sql, '$temp_rate', '$ref_no', $ref_date_sql, '$is_ret', $ret_at)";
                    if (!mysqli_query($connection, $ins_sql)) {
                        throw new Exception("Error saving slip #$slip_no: " . mysqli_error($connection));
                    }
                    $new_slip_id = mysqli_insert_id($connection);

                    // If a temporary slip was settled via Temp. Receive on this permanent slip, mark it settled
                    if ($slip_type === 'Permanent Slip' && (!empty($temp_id) || !empty($temp_no))) {
                        $target_temp_id = $temp_id;
                        // If temp_id points to a deleted row, find the active one by slip_no and customer
                        $chk_act = mysqli_query($connection, "SELECT id FROM tbl_meter_reading_credit_sales WHERE id = '$temp_id' AND deleted_at IS NULL");
                        if (!$chk_act || mysqli_num_rows($chk_act) == 0) {
                            $ts_no_safe = mysqli_real_escape_string($connection, $temp_no);
                            $ts_acc_safe = mysqli_real_escape_string($connection, $acc_num);
                            $chk_by_no = mysqli_query($connection, "SELECT id FROM tbl_meter_reading_credit_sales WHERE slip_no = '$ts_no_safe' AND slip_type = 'Temporary Slip' AND account_number = '$ts_acc_safe' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                            if ($chk_by_no && $t_act = mysqli_fetch_assoc($chk_by_no)) {
                                $target_temp_id = intval($t_act['id']);
                                mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales SET temp_slip_id = '$target_temp_id' WHERE id = '$new_slip_id'");
                            }
                        }
                        if ($target_temp_id > 0) {
                            mysqli_query($connection, "UPDATE tbl_meter_reading_credit_sales 
                                                       SET is_returned = 1, returned_at = NOW(), settled_in_slip_id = '$new_slip_id' 
                                                       WHERE id = '$target_temp_id'");
                        }
                    }
                }
                mysqli_commit($connection);
                header('Location: credit-sales-list.php?msg=added');
                exit;
            } catch (Exception $e) {
                mysqli_rollback($connection);
                $error_msg = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Add Credit Sale Reading</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header);
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }
        .form-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden; margin-bottom:25px;
        }
        .form-card-header {
            background: var(--primary-gradient);
            color:#fff; padding:12px 20px;
            font-weight:600; font-size:14px;
        }
        #creditSalesTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:11.5px; font-weight:600; text-align:center; vertical-align:middle;
        }
        #creditSalesTable td { vertical-align:middle; padding:5px; font-size:12px; }
        .table-responsive { 
            max-height: 580px; 
            overflow-x: auto !important; 
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e2e8f0; 
            border-radius: 6px;
            padding-bottom: 6px;
        }
        .table-responsive::-webkit-scrollbar {
            height: 10px;
            width: 8px;
        }
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .table-responsive::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 4px;
        }
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }
        .summary-badge-box {
            background:#eef2ff; border-radius:8px; border:1px solid #c7d2fe; padding:12px 18px;
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-plus-circle mr-2 text-warning"></i> Add Credit Sale Reading</h4>
            <small class="text-white-50">Record permanent, balanced, and temporary loan slips by date</small>
        </div>
        <a href="credit-sales-list.php" class="btn btn-outline-light btn-sm font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Back to List
        </a>
    </div>

    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="creditSaleForm" onsubmit="return validateCreditForm()" novalidate>
        <!-- Date Selection Card -->
        <div class="form-card mb-3">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-calendar-alt mr-2"></i> Transaction Details</span>
            </div>
            <div class="p-3">
                <div class="row align-items-center">
                    <div class="col-md-3 col-sm-6">
                        <label class="font-weight-bold text-dark mb-1"><i class="fas fa-calendar-day mr-1 text-primary"></i> Sale Date <span class="text-danger">*</span></label>
                        <input type="date" name="sale_date" id="sale_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-3 col-sm-6 mt-3 mt-sm-0">
                        <label class="font-weight-bold text-dark mb-1"><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                        <select name="shift_id" id="shift_id" class="form-control font-weight-bold" required>
                            <option value="">-- Select Shift --</option>
                            <?php foreach ($shifts as $sh): ?>
                                <option value="<?php echo $sh['id']; ?>" <?php echo (isset($_POST['shift_id']) && $_POST['shift_id'] == $sh['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sh['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-sm-12 mt-3 mt-md-0 text-md-right text-muted small">
                        <span class="badge badge-primary px-2 py-1"><i class="fas fa-file-invoice mr-1"></i> Permanent: Billed Issue Qty</span>
                        <span class="badge badge-info px-2 py-1 ml-1"><i class="fas fa-balance-scale mr-1"></i> Balanced: Price-Adjusted Rs. 0</span>
                        <span class="badge badge-warning text-dark px-2 py-1 ml-1"><i class="fas fa-hand-holding mr-1"></i> Temporary: Loan Fuel (Pending)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Slips Entry Card -->
        <div class="form-card">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list mr-2"></i> Credit Sale Slips</span>
                <button type="button" class="btn btn-sm btn-light font-weight-bold text-primary" onclick="addCreditRow()">
                    <i class="fas fa-plus mr-1"></i> Add New Row
                </button>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm text-center mb-0" id="creditSalesTable" style="min-width: 2150px;">
                        <thead>
                            <tr style="background: var(--primary-color); color: #fff;">
                                <th style="width: 180px; min-width: 180px;">Nozzle</th>
                                <th style="width: 210px; min-width: 210px;">Slip Type</th>
                                <th style="width: 140px; min-width: 140px;">Slip Date <span class="text-danger">*</span></th>
                                <th style="width: 130px; min-width: 130px;">Slip No *</th>
                                <th style="width: 175px; min-width: 175px;">Vehicle No</th>
                                <th style="width: 125px; min-width: 125px;">Account No</th>
                                <th style="width: 125px; min-width: 125px;">Item</th>
                                <th style="width: 105px; min-width: 105px;">Qty (Ltr)</th>
                                <th style="width: 110px; min-width: 110px;">Sale Rate</th>
                                <th style="width: 125px; min-width: 125px;">Fuel Amt</th>
                                <th style="width: 125px; min-width: 125px;">Charge Amt</th>
                                <th style="width: 110px; min-width: 110px;">Cash Rate</th>
                                <th style="width: 105px; min-width: 105px;">Issue Qty</th>
                                <th style="width: 100px; min-width: 100px;">Bal 1</th>
                                <th style="width: 100px; min-width: 100px;">Bal 2</th>
                                <th style="width: 150px; min-width: 150px;">Temp. Receive</th>
                                <th style="width: 60px; min-width: 60px;"><i class="fas fa-trash-alt"></i></th>
                            </tr>
                        </thead>
                        <tbody id="creditSalesBody">
                            <!-- Rows injected via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Bottom Summary & Action -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-3 border-top">
                    <button type="button" class="btn btn-primary btn-sm font-weight-bold" onclick="addCreditRow()">
                        <i class="fas fa-plus mr-1"></i> Add New Row
                    </button>

                    <div class="d-flex flex-wrap align-items-center gap-3 mt-2 mt-md-0 summary-badge-box">
                        <div class="mr-3">
                            <span class="text-muted small d-block font-weight-bold">TOTAL FUEL:</span>
                            <span class="text-dark font-weight-bold" id="lblTotalQty">0.00 Ltr</span>
                        </div>
                        <div class="mr-3">
                            <span class="text-muted small d-block font-weight-bold">GROSS FUEL AMOUNT:</span>
                            <span class="text-dark font-weight-bold" id="lblTotalAmount">Rs. 0.00</span>
                        </div>
                        <div class="mr-3">
                            <span class="text-danger small d-block font-weight-bold">TOTAL BILLABLE CHARGE:</span>
                            <span class="text-danger font-weight-bold" style="font-size:15px;" id="lblTotalCharge">Rs. 0.00</span>
                        </div>
                        <button type="submit" class="btn btn-success font-weight-bold px-4 ml-md-3">
                            <i class="fas fa-save mr-1"></i> Save Credit Sales
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Datalist for registered vehicles -->
<datalist id="registeredVehiclesList">
    <?php foreach ($vehicles as $v): ?>
        <option value="<?php echo htmlspecialchars($v['reg_number']); ?>" 
                data-custid="<?php echo $v['customer_id']; ?>" 
                data-custname="<?php echo htmlspecialchars($v['customer_name']); ?>"
                data-limit="<?php echo $v['fuel_limit']; ?>">
            <?php echo htmlspecialchars($v['customer_name'] . ' (' . $v['reg_number'] . ')'); ?>
        </option>
    <?php endforeach; ?>
</datalist>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
var nozzlesData  = <?php echo json_encode($nozzles); ?>;
var vehiclesData = <?php echo json_encode($vehicles); ?>;
var creditRowIdx = 0;

$(document).ready(function() {
    var postedCreditData = <?php echo json_encode($posted_rows); ?>;
    if (postedCreditData && postedCreditData.length > 0) {
        for (var i = 0; i < postedCreditData.length; i++) {
            addCreditRowWithData(postedCreditData[i]);
        }
        updateAllTotals();
    } else {
        addCreditRow(); // Start with 1 row by default
    }

    // Auto-append next row when typing or selecting in the current last row
    $('#creditSalesBody').on('input change', 'tr:last-child input, tr:last-child select', function() {
        var $tr = $(this).closest('tr');
        if ($tr.is(':last-child') && isCreditRowActive($tr)) {
            addCreditRow();
        }
    });

});

function isCreditRowActive($tr) {
    if (!$tr || $tr.length === 0) return false;
    var slipNo = ($tr.find('.credit-slip-no').val() || '').trim();
    var veh = ($tr.find('.credit-vehicle-number').val() || '').trim();
    var qty = parseFloat($tr.find('.credit-qty').val()) || 0;
    var wasoli = parseFloat($tr.find('.credit-wasoli').val()) || 0;
    return (slipNo !== '' || veh !== '' || qty > 0 || wasoli > 0);
}

function addCreditRow() {
    addCreditRowWithData(null);
}

function addCreditRowWithData(data) {
    var rowId = creditRowIdx++;
    var selectedNozzleId = data ? data.nozzle_id : '';
    var slipDateVal      = (data && data.slip_date) ? data.slip_date : '';
    var slipNoVal        = data ? data.slip_no : '';
    var slipTypeVal      = data ? data.slip_type : 'Permanent Slip';
    var vehicleVal       = data ? data.vehicle_number : '';
    var accountVal       = data ? data.account_number : '';
    var qtyVal           = data ? parseFloat(data.quantity) : 0;
    var rateVal          = data ? parseFloat(data.rate) : 0;
    var amountVal        = data ? parseFloat(data.amount) : 0;
    var chargeVal        = data ? parseFloat(data.charge_amount) : 0;
    var cashRateVal      = data ? parseFloat(data.cash_rate) : 0;
    var issueVal         = data ? parseFloat(data.issue_quantity) : 0;
    var bal1Val          = data ? parseFloat(data.balance_1) : 0;
    var bal2Val          = data ? parseFloat(data.balance_2) : 0;
    var wasoliVal        = data ? parseFloat(data.wasoli) : 0;
    var tempIdVal        = (data && data.temp_slip_id) ? data.temp_slip_id : '';
    var tempNoVal        = (data && data.temp_slip_no) ? data.temp_slip_no : '';
    var tempDateVal      = (data && data.temp_slip_date) ? data.temp_slip_date : '';
    var tempRateVal      = (data && data.temp_rate) ? parseFloat(data.temp_rate) : 0;
    var refNoVal         = (data && data.ref_slip_no) ? data.ref_slip_no : '';
    var refDateVal       = (data && data.ref_slip_date) ? data.ref_slip_date : '';

    var nozzleOptionsHtml = '';
    for (var i = 0; i < nozzlesData.length; i++) {
        var nz = nozzlesData[i];
        var isSel = (nz.id == selectedNozzleId) ? 'selected' : '';
        nozzleOptionsHtml += '<option value="' + nz.id + '" data-item="' + (nz.item_name || '') + '" data-credit-rate="' + (nz.credit_rate || 0) + '" data-cash-rate="' + (nz.cash_rate || 0) + '" ' + isSel + '>' +
                             nz.name + ' (' + (nz.item_name || 'Fuel') + ')' +
                             '</option>';
    }

    var rowHtml = '<tr id="credit_row_' + rowId + '">' +
        '<td>' +
            '<select name="credit_nozzle_id[]" class="form-control form-control-sm credit-nozzle-select" onchange="updateCreditItem(this)">' +
                nozzleOptionsHtml +
            '</select>' +
        '</td>' +
        '<td>' +
            '<div class="d-flex flex-column align-items-start">' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_perm_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Permanent Slip" ' + (slipTypeVal === 'Permanent Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-primary" for="st_perm_' + rowId + '" style="font-size:11px; cursor:pointer;">Permanent</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_bal_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Balanced Slip" ' + (slipTypeVal === 'Balanced Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-info" for="st_bal_' + rowId + '" style="font-size:11px; cursor:pointer;">Balanced</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_temp_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Temporary Slip" ' + (slipTypeVal === 'Temporary Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-warning" for="st_temp_' + rowId + '" style="font-size:11px; cursor:pointer; color:#b07800 !important;">Temporary</label>' +
                '</div>' +
                '<button type="button" class="btn btn-xs btn-outline-info font-weight-bold px-1 py-0 mt-1 btn-claim-balance" style="' + (slipTypeVal === 'Balanced Slip' ? '' : 'display:none;') + ' font-size:10px;" onclick="openBalanceSlipModal(' + rowId + ')"><i class="fas fa-balance-scale mr-1"></i> Claim Bal</button>' +
                '<div class="balance-slip-info mt-1 text-left" style="' + (refNoVal ? '' : 'display:none;') + ' font-size:9.5px; line-height:1.2;">' +
                    (refNoVal ? '<span class="badge badge-info text-white font-weight-bold"><i class="fas fa-balance-scale mr-1"></i> From #' + refNoVal + '</span>' : '') +
                '</div>' +
                '<div class="temp-loan-badge mt-1 text-left" style="' + (slipTypeVal === 'Temporary Slip' ? '' : 'display:none;') + '">' +
                    '<span class="badge badge-warning text-dark font-weight-bold p-1" style="font-size:9.5px;"><i class="fas fa-hand-holding mr-1"></i> Loan (Pending)</span>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="credit_slip_type[]" class="credit-slip-type-val" value="' + slipTypeVal + '">' +
        '</td>' +
        '<td>' +
            '<input type="date" name="credit_slip_date[]" class="form-control form-control-sm credit-slip-date font-weight-bold" value="' + slipDateVal + '" onchange="onSlipDateChange(this)">' +
        '</td>' +
        '<td>' +
            '<input type="text" name="credit_slip_no[]" class="form-control form-control-sm credit-slip-no font-weight-bold text-monospace" placeholder="Slip #" value="' + slipNoVal + '">' +
        '</td>' +
        '<td>' +
            '<input type="text" name="credit_vehicle_number[]" list="registeredVehiclesList" class="form-control form-control-sm credit-vehicle-number font-weight-bold text-monospace" placeholder="Pick Vehicle" value="' + vehicleVal + '" oninput="onCreditVehicleInput(this)" onchange="onCreditVehicleInput(this)">' +
            '<div class="vehicle-match-info small text-left mt-1" style="display:none; font-size:10.5px; line-height:1.2;"></div>' +
        '</td>' +
        '<td><input type="text" name="credit_account_number[]" class="form-control form-control-sm credit-account-number font-weight-bold" placeholder="Cust ID" value="' + accountVal + '" readonly style="background-color:#e9ecef; cursor:not-allowed;"></td>' +
        '<td><input type="text" class="form-control form-control-sm credit-item-name" disabled></td>' +
        '<td><input type="number" step="0.01" name="credit_quantity[]" class="form-control form-control-sm credit-qty font-weight-bold text-primary" value="' + qtyVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_rate[]" class="form-control form-control-sm credit-rate font-weight-bold" value="' + rateVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_amount[]" class="form-control form-control-sm credit-amount-field" value="' + amountVal + '" readonly style="background-color:#f8f9fa;"></td>' +
        '<td><input type="number" step="0.01" name="credit_charge_amount[]" class="form-control form-control-sm credit-charge-amount-field font-weight-bold text-primary" value="' + chargeVal + '" readonly style="background-color:#eef2ff;"></td>' +
        '<td><input type="number" step="0.01" name="credit_cash_rate[]" class="form-control form-control-sm credit-cash-rate" value="' + cashRateVal + '"></td>' +
        '<td><input type="number" step="0.01" name="credit_issue_quantity[]" class="form-control form-control-sm credit-issue-qty" value="' + issueVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_balance_1[]" class="form-control form-control-sm credit-bal1" value="' + bal1Val + '" readonly style="background-color:#f8f9fa;"></td>' +
        '<td><input type="number" step="0.01" name="credit_balance_2[]" class="form-control form-control-sm credit-bal2" value="' + bal2Val + '" readonly style="background-color:#f8f9fa;"></td>' +
        '<td>' +
            '<div class="input-group input-group-sm">' +
                '<input type="number" step="0.01" name="credit_wasoli[]" class="form-control form-control-sm credit-wasoli font-weight-bold text-warning" value="' + wasoliVal + '" oninput="calculateCreditRow(this)">' +
                '<div class="input-group-append">' +
                    '<button type="button" class="btn btn-outline-primary btn-sm btn-link-temp px-2" title="Find & Settle Temporary Slip" onclick="openTempReceiveModal(' + rowId + ')"><i class="fas fa-link"></i></button>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="credit_temp_slip_id[]" class="credit-temp-slip-id" value="' + tempIdVal + '">' +
            '<input type="hidden" name="credit_temp_slip_no[]" class="credit-temp-slip-no" value="' + tempNoVal + '">' +
            '<input type="hidden" name="credit_temp_slip_date[]" class="credit-temp-slip-date" value="' + tempDateVal + '">' +
            '<input type="hidden" name="credit_temp_rate[]" class="credit-temp-rate" value="' + tempRateVal + '">' +
            '<input type="hidden" name="credit_ref_slip_no[]" class="credit-ref-slip-no" value="' + refNoVal + '">' +
            '<input type="hidden" name="credit_ref_slip_date[]" class="credit-ref-slip-date" value="' + refDateVal + '">' +
            '<div class="temp-linked-badge mt-1 text-left" style="' + (tempNoVal ? '' : 'display:none;') + ' font-size:9px; line-height:1.2;">' +
                (tempNoVal ? '<span class="badge badge-success text-white font-weight-bold"><i class="fas fa-check-circle mr-1"></i> Settling #' + tempNoVal + (wasoliVal > 0 ? ' (' + wasoliVal.toFixed(0) + 'L)' : '') + '</span>' : '') +
            '</div>' +
        '</td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeCreditRow(this)"><i class="fas fa-trash-alt"></i></button></td>' +
        '</tr>';

    $('#creditSalesBody').append(rowHtml);

    var $newRow = $('#credit_row_' + rowId);
    if (data) {
        if (data.vehicle_number) {
            onCreditVehicleInput($newRow.find('.credit-vehicle-number')[0], true);
        }
        onSlipTypeChange($newRow.find('input[name="slip_type_radio_' + rowId + '"]:checked')[0], rowId);
        $newRow.find('.credit-qty').val(parseFloat(data.quantity || 0).toFixed(2));
        $newRow.find('.credit-rate').val(parseFloat(data.rate || 0).toFixed(2));
        $newRow.find('.credit-cash-rate').val(parseFloat(data.cash_rate || 0).toFixed(2));
        $newRow.find('.credit-issue-qty').val(parseFloat(data.issue_quantity || 0).toFixed(2));
        $newRow.find('.credit-bal1').val(parseFloat(data.balance_1 || 0).toFixed(2));
        $newRow.find('.credit-bal2').val(parseFloat(data.balance_2 || 0).toFixed(2));
        $newRow.find('.credit-wasoli').val(parseFloat(data.wasoli || 0).toFixed(2));
        calculateCreditRow($newRow.find('.credit-qty')[0]);
    } else {
        updateCreditItem($newRow.find('.credit-nozzle-select')[0]);
        onSlipTypeChange($newRow.find('input[name="slip_type_radio_' + rowId + '"]:checked')[0], rowId);
    }
}

function removeCreditRow(btn) {
    if ($('#creditSalesBody tr').length <= 1) {
        alert('At least one row is required.');
        return;
    }
    $(btn).closest('tr').remove();
    updateAllTotals();
}

function onSlipTypeChange(radioElement, rowId) {
    if (!radioElement) return;
    var val = $(radioElement).val();
    var $row = $(radioElement).closest('tr');
    $row.find('.credit-slip-type-val').val(val);

    var $qty       = $row.find('.credit-qty');
    var $issueQty  = $row.find('.credit-issue-qty');
    var $wasoli    = $row.find('.credit-wasoli');
    var $linkBtn   = $row.find('.btn-link-temp');
    var $claimBtn  = $row.find('.btn-claim-balance');
    var $balInfo   = $row.find('.balance-slip-info');
    var $tempBadge = $row.find('.temp-linked-badge');
    var $loanBadge = $row.find('.temp-loan-badge');

    if (val === 'Balanced Slip') {
        $claimBtn.show();
        $balInfo.show();
        $loanBadge.hide();
        $wasoli.prop('readonly', true).css({'background-color': '#e9ecef', 'cursor': 'not-allowed'}).val(0);
        $linkBtn.prop('disabled', true);
        $tempBadge.hide().html('');
        $row.find('.credit-temp-slip-id').val('');
        $row.find('.credit-temp-slip-no').val('');
        $row.find('.credit-temp-slip-date').val('');
        $row.find('.credit-temp-rate').val(0);
        $issueQty.prop('readonly', false).css({'background-color': '', 'cursor': ''});
        openBalanceSlipModal(rowId);
    } else if (val === 'Temporary Slip') {
        $claimBtn.hide();
        $balInfo.hide().html('');
        $loanBadge.show();
        $wasoli.prop('readonly', true).css({'background-color': '#e9ecef', 'cursor': 'not-allowed'}).val(0);
        $linkBtn.prop('disabled', true);
        $tempBadge.hide().html('');
        $row.find('.credit-temp-slip-id').val('');
        $row.find('.credit-temp-slip-no').val('');
        $row.find('.credit-temp-slip-date').val('');
        $row.find('.credit-temp-rate').val(0);
        $row.find('.credit-ref-slip-no').val('');
        $row.find('.credit-ref-slip-date').val('');
        $issueQty.prop('readonly', true).css({'background-color': '#e9ecef', 'cursor': 'not-allowed'}).val($qty.val());
    } else { // Permanent Slip
        $claimBtn.hide();
        $balInfo.hide().html('');
        $loanBadge.hide();
        $issueQty.prop('readonly', false).css({'background-color': '', 'cursor': ''});
        $wasoli.prop('readonly', false).css({'background-color': '', 'cursor': ''});
        $linkBtn.prop('disabled', false);
        $row.find('.credit-ref-slip-no').val('');
        $row.find('.credit-ref-slip-date').val('');
    }

    calculateCreditRow($row.find('.credit-qty')[0]);
}

function resolveCreditRowRate($row, callback) {
    var slipType = $row.find('.credit-slip-type-val').val();
    // Balanced Slip rate is determined by the claimed balance voucher, do not overwrite
    if (slipType === 'Balanced Slip' && $row.find('.credit-ref-slip-no').val()) {
        calculateCreditRow($row.find('.credit-qty')[0]);
        if (callback) callback();
        return;
    }

    var slipDate = $row.find('.credit-slip-date').val() || '';
    var nzId = $row.find('.credit-nozzle-select').val();
    var nz = nozzlesData.find(function(n) { return n.id == nzId; });
    var policy = $row.data('customer-fuel-rate') || 'Credit';

    if (!nz || !nz.item_id || !slipDate) {
        if (!slipDate) {
            $row.find('.credit-rate').val('').attr('placeholder', 'Pick Date');
            $row.find('.credit-cash-rate').val('').attr('placeholder', 'Pick Date');
        }
        calculateCreditRow($row.find('.credit-qty')[0]);
        if (callback) callback();
        return;
    }

    $.getJSON('ajax-credit-slip-lookup.php', {
        action: 'get_price_for_date',
        item_id: nz.item_id,
        slip_date: slipDate,
        policy: policy
    }, function(res) {
        if (res && res.status === 'success') {
            $row.find('.credit-rate').val(parseFloat(res.applicable_rate).toFixed(2));
            $row.find('.credit-cash-rate').val(parseFloat(res.cash_rate).toFixed(2));
        } else {
            var cr = parseFloat(nz.credit_rate) || 0;
            var ca = parseFloat(nz.cash_rate) || 0;
            var fallback = (policy === 'Cash') ? ca : (cr > 0 ? cr : ca);
            $row.find('.credit-rate').val(fallback.toFixed(2));
            $row.find('.credit-cash-rate').val(ca.toFixed(2));
        }
        calculateCreditRow($row.find('.credit-qty')[0]);
        if (callback) callback();
    }).fail(function() {
        calculateCreditRow($row.find('.credit-qty')[0]);
        if (callback) callback();
    });
}

function updateCreditItem(selectElement) {
    var nzId = $(selectElement).val();
    var $row = $(selectElement).closest('tr');
    var nz = nozzlesData.find(function(n) { return n.id == nzId; });
    if (nz) {
        $row.find('.credit-item-name').val(nz.item_name || '');
    }
    resolveCreditRowRate($row);
}

function onSlipDateChange(inputElement) {
    var $row = $(inputElement).closest('tr');
    resolveCreditRowRate($row);
}

function onCreditVehicleInput(inputElement, skipPriceFetch) {
    var val = $(inputElement).val().trim().toUpperCase();
    var $row = $(inputElement).closest('tr');
    var $accountField = $row.find('.credit-account-number');
    var $infoDiv = $row.find('.vehicle-match-info');

    if (!val) {
        $accountField.val('');
        $row.removeData('customer-fuel-rate');
        $infoDiv.hide().html('');
        if (!skipPriceFetch) {
            resolveCreditRowRate($row);
        }
        return;
    }

    var matched = vehiclesData.find(function(v) {
        return (v.reg_number && v.reg_number.toUpperCase() === val) ||
               (v.numeric_number && v.numeric_number.toUpperCase() === val);
    });

    if (matched) {
        $accountField.val(matched.customer_id);
        var fuelRatePolicy = matched.fuel_rate || 'Credit';
        $row.data('customer-fuel-rate', fuelRatePolicy);

        var rateBadgeClass = (fuelRatePolicy === 'Cash') ? 'badge-success' : 'badge-primary';
        var rateBadgeText  = (fuelRatePolicy === 'Cash') ? 'Cash Rate' : 'Credit Rate';
        var rateBadgeIcon  = (fuelRatePolicy === 'Cash') ? 'fa-money-bill-wave' : 'fa-credit-card';

        $infoDiv.show().html(
            '<div class="d-flex flex-wrap align-items-center mt-1">' +
                '<span class="text-success font-weight-bold mr-1"><i class="fas fa-check-circle"></i> ' + matched.customer_name + '</span> ' +
                '<span class="badge badge-info mr-1" title="Fuel Limit">' + parseFloat(matched.fuel_limit).toFixed(0) + ' Ltr</span>' +
                '<span class="badge ' + rateBadgeClass + '" title="Customer Tariff Policy"><i class="fas ' + rateBadgeIcon + ' mr-1"></i>' + rateBadgeText + '</span>' +
            '</div>'
        );

        // Fetch price strictly based on this row's slip date and matched customer tariff policy
        if (!skipPriceFetch) {
            resolveCreditRowRate($row);
        }
    } else {
        $accountField.val('');
        $row.removeData('customer-fuel-rate');
        $infoDiv.show().html('<span class="text-warning"><i class="fas fa-exclamation-circle"></i> Unregistered</span>');
        if (!skipPriceFetch) {
            resolveCreditRowRate($row);
        }
    }
}

function calculateCreditRow(element) {
    var $row = $(element).closest('tr');
    var qty = parseFloat($row.find('.credit-qty').val()) || 0;
    var rate = parseFloat($row.find('.credit-rate').val()) || 0;
    var issueQty = parseFloat($row.find('.credit-issue-qty').val()) || 0;
    var slipType = $row.find('.credit-slip-type-val').val();
    var wasoli = parseFloat($row.find('.credit-wasoli').val()) || 0;
    var tempRate = parseFloat($row.find('.credit-temp-rate').val()) || rate;

    // Gross fuel value of physical petrol dispensed right now
    var fuelAmount = qty * rate;
    $row.find('.credit-amount-field').val(fuelAmount.toFixed(2));

    var chargeAmount = 0;
    if (slipType === 'Permanent Slip') {
        var effectiveIssue = (issueQty > 0) ? issueQty : qty;
        var newFuelCharge = effectiveIssue * rate;
        var tempFuelCharge = (wasoli > 0) ? (wasoli * tempRate) : 0;
        chargeAmount = newFuelCharge + tempFuelCharge;

        // Auto-calculate remaining balance
        var remainingBal = Math.max(0, effectiveIssue - qty);
        $row.find('.credit-bal1').val(remainingBal.toFixed(2));
    } else if (slipType === 'Balanced Slip') {
        chargeAmount = 0.00;
    } else if (slipType === 'Temporary Slip') {
        chargeAmount = 0.00;
        $row.find('.credit-issue-qty').val(qty.toFixed(2));
        $row.find('.credit-bal1').val('0.00');
        $row.find('.credit-bal2').val('0.00');
    }

    $row.find('.credit-charge-amount-field').val(chargeAmount.toFixed(2));
    updateAllTotals();
}

function updateAllTotals() {
    var totQty = 0, totAmt = 0, totCharge = 0;
    $('#creditSalesBody tr').each(function() {
        totQty    += parseFloat($(this).find('.credit-qty').val()) || 0;
        totAmt    += parseFloat($(this).find('.credit-amount-field').val()) || 0;
        totCharge += parseFloat($(this).find('.credit-charge-amount-field').val()) || 0;
    });
    $('#lblTotalQty').text(totQty.toFixed(2) + ' Ltr');
    $('#lblTotalAmount').text('Rs. ' + totAmt.toFixed(2));
    $('#lblTotalCharge').text('Rs. ' + totCharge.toFixed(2));
}

// ----------------------------------------------------
// MODAL WORKFLOW: TEMP. RECEIVE (PERMANENT SLIP)
// ----------------------------------------------------
var activeModalRowId = null;

function openTempReceiveModal(rowId) {
    activeModalRowId = rowId;
    var $row = $('#credit_row_' + rowId);
    var custId = $row.find('.credit-account-number').val() || 0;

    $('#txtSearchTempSlip').val('');
    $('#txtSearchTempDate').val('');
    $('#tempFoundBox').hide();
    $('#btnAttachTempSlip').prop('disabled', true);
    $('#tempModalCustNotice').text(custId ? 'Filtered for Account #' + custId : 'All Customer Accounts');

    loadUnsettledTempSlips(custId);
    $('#tempReceiveModal').modal('show');
}

function loadUnsettledTempSlips(custId) {
    $('#tempSlipsListTable tbody').html('<tr><td colspan="6" class="text-center py-2 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading open temporary loan slips...</td></tr>');
    $.getJSON('ajax-credit-slip-lookup.php', {
        action: 'list_unsettled_temp_slips',
        customer_id: custId
    }, function(res) {
        if (res && res.status === 'success' && res.slips && res.slips.length > 0) {
            var rows = '';
            for (var i = 0; i < res.slips.length; i++) {
                var s = res.slips[i];
                var sData = JSON.stringify(s).replace(/"/g, '&quot;');
                rows += '<tr>' +
                    '<td><strong class="text-primary">' + s.slip_no + '</strong></td>' +
                    '<td>' + s.slip_date + '</td>' +
                    '<td>' + (s.vehicle_number || '—') + '</td>' +
                    '<td><span class="badge badge-info">' + parseFloat(s.quantity).toFixed(2) + ' Ltr</span></td>' +
                    '<td>Rs. ' + parseFloat(s.rate).toFixed(2) + '</td>' +
                    '<td><button type="button" class="btn btn-xs btn-success font-weight-bold" onclick="selectTempSlip(' + sData + ')"><i class="fas fa-check mr-1"></i>Select</button></td>' +
                '</tr>';
            }
            $('#tempSlipsListTable tbody').html(rows);
        } else {
            $('#tempSlipsListTable tbody').html('<tr><td colspan="6" class="text-center text-muted py-2">No unsettled temporary slips found.</td></tr>');
        }
    });
}

function searchTempSlipManual() {
    var slipNo = $('#txtSearchTempSlip').val().trim();
    var slipDate = $('#txtSearchTempDate').val().trim();
    if (!slipNo) {
        alert('Please enter a Temporary Slip No to search.');
        return;
    }
    $.getJSON('ajax-credit-slip-lookup.php', {
        action: 'find_temp_slip',
        slip_no: slipNo,
        slip_date: slipDate
    }, function(res) {
        if (res && res.status === 'success' && res.found) {
            selectTempSlip(res);
        } else {
            alert(res.message || 'No unsettled temporary slip found.');
            $('#tempFoundBox').hide();
            $('#btnAttachTempSlip').prop('disabled', true);
        }
    });
}

var currentSelectedTempSlip = null;
function selectTempSlip(slipObj) {
    currentSelectedTempSlip = slipObj;
    $('#lblFoundTempSlipNo').text(slipObj.slip_no);
    $('#lblFoundTempDate').text(slipObj.slip_date);
    $('#lblFoundTempQty').text(parseFloat(slipObj.quantity).toFixed(2) + ' Ltr');
    $('#lblFoundTempRate').text('Rs. ' + parseFloat(slipObj.rate).toFixed(2) + ' / Ltr');
    var val = parseFloat(slipObj.value || (slipObj.quantity * slipObj.rate)) || 0;
    $('#lblFoundTempValue').text('Rs. ' + val.toFixed(2));
    $('#tempFoundBox').show();
    $('#btnAttachTempSlip').prop('disabled', false);
}

function attachTempSlipToRow() {
    if (!currentSelectedTempSlip || activeModalRowId === null) return;
    var $row = $('#credit_row_' + activeModalRowId);
    var qty = parseFloat(currentSelectedTempSlip.quantity) || 0;
    var rate = parseFloat(currentSelectedTempSlip.rate) || 0;

    $row.find('.credit-wasoli').val(qty.toFixed(2));
    $row.find('.credit-temp-slip-id').val(currentSelectedTempSlip.temp_slip_id || currentSelectedTempSlip.id);
    $row.find('.credit-temp-slip-no').val(currentSelectedTempSlip.slip_no);
    $row.find('.credit-temp-slip-date').val(currentSelectedTempSlip.slip_date);
    $row.find('.credit-temp-rate').val(rate.toFixed(2));

    $row.find('.temp-linked-badge').show().html(
        '<span class="badge badge-success text-white font-weight-bold" title="Settling Loan Slip ' + currentSelectedTempSlip.slip_date + '">' +
            '<i class="fas fa-check-circle mr-1"></i> Settling #' + currentSelectedTempSlip.slip_no + ' (' + qty.toFixed(0) + 'L @ Rs. ' + rate.toFixed(0) + ')' +
        '</span>'
    );

    calculateCreditRow($row.find('.credit-qty')[0]);
    $('#tempReceiveModal').modal('hide');
}

// ----------------------------------------------------
// ----------------------------------------------------
// MODAL WORKFLOW: BALANCED SLIP (CLAIM MERGED BALANCES)
// ----------------------------------------------------
function openBalanceSlipModal(rowId) {
    activeModalRowId = rowId;
    var $row = $('#credit_row_' + rowId);
    var curRate = parseFloat($row.find('.credit-rate').val()) || 0;
    var custPolicy = $row.data('customer-fuel-rate');

    $('#txtSearchBalSlip').val('');
    $('#txtSearchBalDate').val('');
    $('#balFoundBox').hide();
    $('#btnApplyBalanceSlip').prop('disabled', true);
    var rateNotice = 'Current Rate: Rs. ' + curRate.toFixed(2) + ' / Ltr';
    if (custPolicy) {
        rateNotice += ' (' + custPolicy + ' Policy)';
    }
    $('#lblCurRateNotice').text(rateNotice);
    $('#balanceSlipModal').modal('show');
}

function searchBalanceSlipManual() {
    var slipNo = $('#txtSearchBalSlip').val().trim();
    var origSlipDate = $('#txtSearchBalDate').val().trim();
    var $row = $('#credit_row_' + activeModalRowId);
    var balanceSlipDate = $row.find('.credit-slip-date').val() || $('#sale_date').val() || '<?php echo date('Y-m-d'); ?>';
    var curRate = parseFloat($row.find('.credit-rate').val()) || 0;
    var custId = $row.find('.credit-account-number').val() || 0;

    if (!slipNo) {
        alert('Please enter a Permanent Slip No to search for remaining balance.');
        return;
    }

    $.getJSON('ajax-credit-slip-lookup.php', {
        action: 'find_balance_slip',
        slip_no: slipNo,
        orig_slip_date: origSlipDate,
        balance_slip_date: balanceSlipDate,
        current_rate: curRate,
        customer_id: custId
    }, function(res) {
        if (res && res.status === 'success' && res.found) {
            selectBalSlip(res);
        } else {
            alert(res.message || 'No Permanent Slip found with remaining balance.');
            $('#balFoundBox').hide();
            $('#btnApplyBalanceSlip').prop('disabled', true);
        }
    });
}

var currentSelectedBalSlip = null;
function selectBalSlip(res) {
    currentSelectedBalSlip = res;
    $('#lblFoundBalSlipNo').text(res.slip_no);
    $('#lblFoundBalDate').text(res.slip_date);
    $('#lblFoundBal1').text(parseFloat(res.balance_1).toFixed(2) + ' Ltr');
    $('#lblFoundBal2').text(parseFloat(res.balance_2).toFixed(2) + ' Ltr');
    $('#lblFoundMergedBal').text(parseFloat(res.total_balance).toFixed(2) + ' Ltr');
    $('#lblFoundOrigRate').text('Rs. ' + parseFloat(res.original_rate).toFixed(2));
    $('#lblFoundPrepaidVal').text('Rs. ' + parseFloat(res.prepaid_money).toFixed(2));
    $('#lblFoundCurPrice').text('Rs. ' + parseFloat(res.current_rate).toFixed(2) + (res.balance_claim_date ? ' (' + res.balance_claim_date + ')' : ''));
    $('#lblFoundAdjLitres').text(parseFloat(res.adjusted_litres).toFixed(2) + ' Litres');

    var diffLtr = parseFloat(res.volume_diff) || 0;
    var priceDiff = parseFloat(res.price_diff) || 0;
    var alertHtml = '';

    if (res.fluctuation_type === 'increase' || priceDiff > 0.001) {
        alertHtml = '<div class="alert alert-warning py-2 px-3 small mb-0 w-100 font-weight-bold" style="border-left: 4px solid #f59e0b;">' +
            '<i class="fas fa-exclamation-triangle mr-1 text-warning"></i> ' +
            'Price Increased (+Rs. ' + Math.abs(priceDiff).toFixed(2) + '/Ltr): ' +
            'Petrol quantity deducted by <span class="text-danger">-' + Math.abs(diffLtr).toFixed(2) + ' Ltr</span>. ' +
            'Customer receives <span class="text-primary">' + parseFloat(res.adjusted_litres).toFixed(2) + ' Ltr</span>.' +
            '</div>';
    } else if (res.fluctuation_type === 'decrease' || priceDiff < -0.001) {
        alertHtml = '<div class="alert alert-success py-2 px-3 small mb-0 w-100 font-weight-bold" style="border-left: 4px solid #10b981;">' +
            '<i class="fas fa-arrow-down mr-1 text-success"></i> ' +
            'Price Decreased (-Rs. ' + Math.abs(priceDiff).toFixed(2) + '/Ltr): ' +
            'Customer receives <span class="text-success">+' + Math.abs(diffLtr).toFixed(2) + ' Ltr bonus fuel</span> (<span class="text-success">' + parseFloat(res.adjusted_litres).toFixed(2) + ' Ltr</span>).' +
            '</div>';
    } else {
        alertHtml = '<div class="alert alert-info py-2 px-3 small mb-0 w-100 font-weight-bold" style="border-left: 4px solid #3b82f6;">' +
            '<i class="fas fa-equals mr-1 text-info"></i> ' +
            'Price Unchanged: Customer receives exact remaining balance of ' + parseFloat(res.total_balance).toFixed(2) + ' Ltr.' +
            '</div>';
    }
    $('#lblFoundFluctuationBox').html(alertHtml).show();

    $('#balFoundBox').show();
    $('#btnApplyBalanceSlip').prop('disabled', false);
}

function applyBalanceSlipToRow() {
    if (!currentSelectedBalSlip || activeModalRowId === null) return;
    var $row = $('#credit_row_' + activeModalRowId);
    var adjLitres = parseFloat(currentSelectedBalSlip.adjusted_litres) || 0;

    // Set reference slip info first
    $row.find('.credit-ref-slip-no').val(currentSelectedBalSlip.slip_no);
    $row.find('.credit-ref-slip-date').val(currentSelectedBalSlip.slip_date);

    // Auto-populate vehicle & account if available from the original slip (skipPriceFetch to prevent async overwrite)
    if (currentSelectedBalSlip.vehicle_number && !$row.find('.credit-vehicle-number').val().trim()) {
        $row.find('.credit-vehicle-number').val(currentSelectedBalSlip.vehicle_number);
        onCreditVehicleInput($row.find('.credit-vehicle-number')[0], true);
    } else if (currentSelectedBalSlip.account_number && !$row.find('.credit-account-number').val()) {
        $row.find('.credit-account-number').val(currentSelectedBalSlip.account_number);
    }

    if (currentSelectedBalSlip.customer_fuel_rate) {
        $row.data('customer-fuel-rate', currentSelectedBalSlip.customer_fuel_rate);
    }

    // Set applicable rate resolved by backend (matching customer rate policy on claim date)
    var applicableRate = parseFloat(currentSelectedBalSlip.current_rate) || parseFloat($row.find('.credit-rate').val()) || 0;
    if (applicableRate > 0) {
        $row.find('.credit-rate').val(applicableRate.toFixed(2));
    }

    $row.find('.credit-qty').val(adjLitres.toFixed(2));
    $row.find('.credit-issue-qty').val(adjLitres.toFixed(2));
    $row.find('.credit-bal1').val(parseFloat(currentSelectedBalSlip.balance_1).toFixed(2));
    $row.find('.credit-bal2').val(parseFloat(currentSelectedBalSlip.balance_2).toFixed(2));

    var badgeText = 'From #' + currentSelectedBalSlip.slip_no + ' (' + parseFloat(currentSelectedBalSlip.total_balance).toFixed(0) + 'L @ Rs. ' + parseFloat(currentSelectedBalSlip.original_rate).toFixed(0) + ' &rarr; ' + adjLitres.toFixed(2) + 'L @ Rs. ' + applicableRate.toFixed(0) + ')';
    $row.find('.balance-slip-info').show().html(
        '<span class="badge badge-info text-white font-weight-bold" title="Prepaid Value Rs. ' + parseFloat(currentSelectedBalSlip.prepaid_money).toFixed(2) + '">' +
            '<i class="fas fa-balance-scale mr-1"></i> ' + badgeText +
        '</span>'
    );

    calculateCreditRow($row.find('.credit-qty')[0]);
    $('#balanceSlipModal').modal('hide');
}

function validateCreditForm() {
    // 0a. Validate Sale Date
    var saleDate = $('#sale_date').val();
    if (!saleDate || saleDate.trim() === '') {
        alert('Please select Sale Date before saving.');
        $('#sale_date').focus().addClass('is-invalid');
        return false;
    }
    $('#sale_date').removeClass('is-invalid');

    // 0b. Validate Shift selection
    var shiftId = $('#shift_id').val();
    if (!shiftId || shiftId === '0' || shiftId.trim() === '') {
        alert('Please select an active Shift from the dropdown before saving.');
        $('#shift_id').focus().addClass('is-invalid');
        return false;
    }
    $('#shift_id').removeClass('is-invalid');

    // 1. Automatically prune trailing blank/untouched rows
    while ($('#creditSalesBody tr').length > 1) {
        var $lastRow = $('#creditSalesBody tr:last-child');
        if (!isCreditRowActive($lastRow)) {
            $lastRow.remove();
        } else {
            break;
        }
    }
    updateAllTotals();

    // 2. Ensure at least one active row exists
    var $rows = $('#creditSalesBody tr');
    if ($rows.length === 0 || !isCreditRowActive($rows.first())) {
        alert('Please enter at least one credit sale slip before saving.');
        return false;
    }

    // 3. Validate mandatory fields on all retained active rows
    var valid = true;
    $rows.each(function(idx) {
        var rowNum = idx + 1;
        var slipDate = $(this).find('.credit-slip-date').val().trim();
        var slipNo = $(this).find('.credit-slip-no').val().trim();
        var vehicle = $(this).find('.credit-vehicle-number').val().trim();
        var account = $(this).find('.credit-account-number').val().trim();
        var qty = parseFloat($(this).find('.credit-qty').val()) || 0;
        var wasoli = parseFloat($(this).find('.credit-wasoli').val()) || 0;
        var slipType = $(this).find('.credit-slip-type-val').val();

        if (!slipDate) {
            alert('Please select Slip Date on row #' + rowNum);
            $(this).find('.credit-slip-date').focus();
            valid = false;
            return false;
        }
        if (!slipNo) {
            alert('Please enter Slip No on row #' + rowNum);
            $(this).find('.credit-slip-no').focus();
            valid = false;
            return false;
        }
        if (!vehicle) {
            alert('Please enter Vehicle No on row #' + rowNum);
            $(this).find('.credit-vehicle-number').focus();
            valid = false;
            return false;
        }
        if (!account) {
            alert('Please select a registered vehicle or valid account on row #' + rowNum);
            $(this).find('.credit-vehicle-number').focus();
            valid = false;
            return false;
        }
        if (qty <= 0 && wasoli <= 0) {
            alert('Please enter a valid Quantity greater than 0 on row #' + rowNum);
            $(this).find('.credit-qty').focus();
            valid = false;
            return false;
        }
    });
    return valid;
}
</script>

<!-- MODAL 1: Temp. Receive Modal -->
<div class="modal fade" id="tempReceiveModal" tabindex="-1" role="dialog" aria-labelledby="tempReceiveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold" id="tempReceiveModalLabel" style="font-size: 15px;">
                    <i class="fas fa-link mr-2"></i> Link & Settle Temporary Slip (Temp. Receive)
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-info-circle mr-1"></i> Select an open temporary loan chit to bill onto this permanent slip.
                    <span class="d-block font-weight-bold mt-1 text-primary" id="tempModalCustNotice"></span>
                </div>

                <div class="form-row mb-3">
                    <div class="col-6">
                        <label class="font-weight-bold small mb-1">Temporary Slip No</label>
                        <input type="text" class="form-control form-control-sm font-weight-bold" id="txtSearchTempSlip" placeholder="e.g. TMP-101">
                    </div>
                    <div class="col-4">
                        <label class="font-weight-bold small mb-1">Slip Date</label>
                        <input type="date" class="form-control form-control-sm" id="txtSearchTempDate">
                    </div>
                    <div class="col-2 d-flex align-items-end">
                        <button type="button" class="btn btn-primary btn-sm btn-block" onclick="searchTempSlipManual()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>

                <label class="font-weight-bold small text-muted text-uppercase mb-1">Recent Open Temporary Slips</label>
                <div class="table-responsive border rounded mb-3" style="max-height: 160px;">
                    <table class="table table-sm table-hover text-center mb-0" id="tempSlipsListTable" style="font-size: 12px;">
                        <thead class="bg-light">
                            <tr>
                                <th>Slip #</th>
                                <th>Date</th>
                                <th>Vehicle</th>
                                <th>Litres</th>
                                <th>Rate</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Loaded via AJAX -->
                        </tbody>
                    </table>
                </div>

                <!-- Preview of selected temporary slip -->
                <div class="card bg-light border p-2" id="tempFoundBox" style="display:none;">
                    <h6 class="font-weight-bold text-success mb-2" style="font-size:13px;">
                        <i class="fas fa-check-circle mr-1"></i> Temporary Slip Selected
                    </h6>
                    <div class="row small">
                        <div class="col-6">
                            <span class="text-muted d-block">Slip Number:</span>
                            <strong id="lblFoundTempSlipNo" class="text-dark"></strong>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block">Loan Date:</span>
                            <strong id="lblFoundTempDate" class="text-dark"></strong>
                        </div>
                        <div class="col-6 mt-2">
                            <span class="text-muted d-block">Quantity:</span>
                            <strong id="lblFoundTempQty" class="text-primary font-weight-bold"></strong>
                        </div>
                        <div class="col-6 mt-2">
                            <span class="text-muted d-block">Historical Rate:</span>
                            <strong id="lblFoundTempRate" class="text-dark"></strong>
                        </div>
                        <div class="col-12 mt-2 pt-2 border-top">
                            <span class="text-muted d-block">Amount to Add to Permanent Slip:</span>
                            <strong id="lblFoundTempValue" class="text-success font-weight-bold" style="font-size:14px;"></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-2 bg-light">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm font-weight-bold px-3" id="btnAttachTempSlip" onclick="attachTempSlipToRow()" disabled>
                    <i class="fas fa-check mr-1"></i> Attach to Permanent Slip
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: Claim Balance Slip Modal -->
<div class="modal fade" id="balanceSlipModal" tabindex="-1" role="dialog" aria-labelledby="balanceSlipModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold" id="balanceSlipModalLabel" style="font-size: 15px;">
                    <i class="fas fa-balance-scale mr-2"></i> Claim Previous Balance Slip
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="fas fa-info-circle mr-1"></i> Look up a prior Permanent Slip. Balances (Bal 1 & Bal 2) are merged and converted to current litres according to the active fuel market price.
                    <span class="d-block font-weight-bold mt-1 text-primary" id="lblCurRateNotice"></span>
                </div>

                <div class="form-row mb-3">
                    <div class="col-6">
                        <label class="font-weight-bold small mb-1">Previous Slip No <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm font-weight-bold" id="txtSearchBalSlip" placeholder="e.g. SL-101">
                    </div>
                    <div class="col-4">
                        <label class="font-weight-bold small mb-1">Slip Date</label>
                        <input type="date" class="form-control form-control-sm" id="txtSearchBalDate">
                    </div>
                    <div class="col-2 d-flex align-items-end">
                        <button type="button" class="btn btn-primary btn-sm btn-block" onclick="searchBalanceSlipManual()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>

                <!-- Preview of balance calculation -->
                <div class="card bg-light border p-3" id="balFoundBox" style="display:none;">
                    <h6 class="font-weight-bold text-primary mb-2" style="font-size:13px;">
                        <i class="fas fa-calculator mr-1"></i> Balance Calculation & Adjustment
                    </h6>
                    <div class="row small">
                        <div class="col-6">
                            <span class="text-muted d-block">Original Permanent Slip:</span>
                            <strong id="lblFoundBalSlipNo" class="text-dark"></strong>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block">Original Slip Date:</span>
                            <strong id="lblFoundBalDate" class="text-dark"></strong>
                        </div>
                        <div class="col-4 mt-2">
                            <span class="text-muted d-block">Balance 1:</span>
                            <strong id="lblFoundBal1" class="text-dark"></strong>
                        </div>
                        <div class="col-4 mt-2">
                            <span class="text-muted d-block">Balance 2:</span>
                            <strong id="lblFoundBal2" class="text-dark"></strong>
                        </div>
                        <div class="col-4 mt-2">
                            <span class="text-muted d-block">Total Balance Quota:</span>
                            <strong id="lblFoundMergedBal" class="text-primary font-weight-bold"></strong>
                        </div>
                        <div class="col-6 mt-2 pt-2 border-top">
                            <span class="text-muted d-block">Original Slip Rate:</span>
                            <strong id="lblFoundOrigRate" class="text-dark"></strong>
                        </div>
                        <div class="col-6 mt-2 pt-2 border-top">
                            <span class="text-muted d-block">Prepaid Monetary Value:</span>
                            <strong id="lblFoundPrepaidVal" class="text-success font-weight-bold"></strong>
                        </div>
                        <div class="col-6 mt-2">
                            <span class="text-muted d-block">Current Rate on Claim Date:</span>
                            <strong id="lblFoundCurPrice" class="text-danger font-weight-bold"></strong>
                        </div>
                        <div class="col-6 mt-2">
                            <span class="text-muted d-block">Adjusted Petrol Quantity:</span>
                            <strong id="lblFoundAdjLitres" class="text-primary font-weight-bold" style="font-size:16px;"></strong>
                        </div>
                        <div class="col-12 mt-2" id="lblFoundFluctuationBox">
                            <!-- Injected dynamic price fluctuation alert -->
                        </div>
                        <div class="col-12 mt-2 pt-2 border-top">
                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i> Customer Charge: Rs. 0.00 (Pre-paid on Original Slip)</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-2 bg-light">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info btn-sm font-weight-bold px-3" id="btnApplyBalanceSlip" onclick="applyBalanceSlipToRow()" disabled>
                    <i class="fas fa-check mr-1"></i> Apply Balance Litres
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>
