<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('credit_sales', 'add');

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
                                           c.name AS customer_name
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sale_date = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? date('Y-m-d'));
    $shift_id  = intval($_POST['shift_id'] ?? 0);
    
    $nozzles_arr   = $_POST['credit_nozzle_id'] ?? [];
    $slip_nos      = $_POST['credit_slip_no'] ?? [];
    $slip_types    = $_POST['credit_slip_type'] ?? [];
    $vehicles_arr  = $_POST['credit_vehicle_number'] ?? [];
    $accounts_arr  = $_POST['credit_account_number'] ?? [];
    $qtys_arr      = $_POST['credit_quantity'] ?? [];
    $rates_arr     = $_POST['credit_rate'] ?? [];
    $amounts_arr   = $_POST['credit_amount'] ?? [];
    $charges_arr   = $_POST['credit_charge_amount'] ?? [];
    $cash_rates    = $_POST['credit_cash_rate'] ?? [];
    $issue_qtys    = $_POST['credit_issue_quantity'] ?? [];
    $bal1_arr      = $_POST['credit_balance_1'] ?? [];
    $bal2_arr      = $_POST['credit_balance_2'] ?? [];
    $wasoli_arr    = $_POST['credit_wasoli'] ?? [];
    $returned_arr  = $_POST['credit_is_returned'] ?? [];

    if (empty($shift_id)) {
        $error_msg = 'Please select a Shift before saving.';
    } elseif (empty($nozzles_arr)) {
        $error_msg = 'Please add at least one credit sale row before saving.';
    } else {
        // Validate slip numbers
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
                    $noz_id     = intval($nozzles_arr[$i]);
                    $slip_no    = mysqli_real_escape_string($connection, trim($slip_nos[$i]));
                    $slip_type  = mysqli_real_escape_string($connection, trim($slip_types[$i] ?? 'Permanent Slip'));
                    $veh_num    = mysqli_real_escape_string($connection, trim($vehicles_arr[$i] ?? ''));
                    $acc_num    = mysqli_real_escape_string($connection, trim($accounts_arr[$i] ?? ''));
                    $qty        = floatval($qtys_arr[$i] ?? 0);
                    $rate       = floatval($rates_arr[$i] ?? 0);
                    $amount     = floatval($amounts_arr[$i] ?? 0);
                    $charge_amt = floatval($charges_arr[$i] ?? 0);
                    $cash_rate  = floatval($cash_rates[$i] ?? 0);
                    $issue_qty  = floatval($issue_qtys[$i] ?? 0);
                    $bal1       = floatval($bal1_arr[$i] ?? 0);
                    $bal2       = floatval($bal2_arr[$i] ?? 0);
                    $wasoli     = floatval($wasoli_arr[$i] ?? 0);
                    $is_ret     = intval($returned_arr[$i] ?? 0);
                    $ret_at     = ($is_ret === 1) ? "NOW()" : "NULL";

                    $ins_sql = "INSERT INTO tbl_meter_reading_credit_sales 
                                (meter_reading_id, nozzle_id, slip_date, shift_id, slip_no, slip_type, account_number, vehicle_number,
                                 quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli, is_returned, returned_at)
                                VALUES 
                                (0, '$noz_id', '$sale_date', '$shift_id', '$slip_no', '$slip_type', '$acc_num', '$veh_num',
                                 '$qty', '$rate', '$amount', '$charge_amt', '$cash_rate', '$issue_qty', '$bal1', '$bal2', '$wasoli', '$is_ret', $ret_at)";
                    if (!mysqli_query($connection, $ins_sql)) {
                        throw new Exception("Error saving slip #$slip_no: " . mysqli_error($connection));
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

    <form method="POST" id="creditSaleForm" onsubmit="return validateCreditForm()">
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
                        <span class="badge badge-primary px-2 py-1"><i class="fas fa-info-circle mr-1"></i> Permanent Slip: Billed Qty</span>
                        <span class="badge badge-info px-2 py-1 ml-1"><i class="fas fa-balance-scale mr-1"></i> Balanced Slip: Pre-Paid Rs. 0</span>
                        <span class="badge badge-warning text-dark px-2 py-1 ml-1"><i class="fas fa-hand-holding mr-1"></i> Temporary Slip: Giving vs Received</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Slips Entry Card -->
        <div class="form-card">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list mr-2"></i> Credit Sale Slips</span>
                <button type="button" class="btn btn-sm btn-light font-weight-bold text-primary" onclick="addCreditRow()">
                    <i class="fas fa-plus mr-1"></i> Add Another Row
                </button>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm text-center mb-0" id="creditSalesTable" style="min-width: 1960px;">
                        <thead>
                            <tr style="background: var(--primary-color); color: #fff;">
                                <th style="width: 180px; min-width: 180px;">Nozzle</th>
                                <th style="width: 200px; min-width: 200px;">Slip Type</th>
                                <th style="width: 130px; min-width: 130px;">Slip No *</th>
                                <th style="width: 175px; min-width: 175px;">Vehicle No</th>
                                <th style="width: 125px; min-width: 125px;">Account No</th>
                                <th style="width: 125px; min-width: 125px;">Item</th>
                                <th style="width: 105px; min-width: 105px;">Qty</th>
                                <th style="width: 110px; min-width: 110px;">Sale Rate</th>
                                <th style="width: 125px; min-width: 125px;">Fuel Amt</th>
                                <th style="width: 125px; min-width: 125px;">Charge Amt</th>
                                <th style="width: 110px; min-width: 110px;">Cash Rate</th>
                                <th style="width: 105px; min-width: 105px;">Issue Qty</th>
                                <th style="width: 100px; min-width: 100px;">Bal 1</th>
                                <th style="width: 100px; min-width: 100px;">Bal 2</th>
                                <th style="width: 110px; min-width: 110px;">Wasoli</th>
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
                    <button type="button" class="btn btn-outline-primary btn-sm font-weight-bold" onclick="addCreditRow()">
                        <i class="fas fa-plus mr-1"></i> Add Another Row
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
    addCreditRow(); // Start with 1 row by default
});

function addCreditRow() {
    var rowId = creditRowIdx++;
    var nozzleOptionsHtml = '';
    for (var i = 0; i < nozzlesData.length; i++) {
        var nz = nozzlesData[i];
        nozzleOptionsHtml += '<option value="' + nz.id + '" data-item="' + (nz.item_name || '') + '" data-credit-rate="' + (nz.credit_rate || 0) + '" data-cash-rate="' + (nz.cash_rate || 0) + '">' +
                             nz.name + ' (' + (nz.item_name || 'Fuel') + ')' +
                             '</option>';
    }

    var rowHtml = '<tr id="credit_row_' + rowId + '">' +
        '<td>' +
            '<select name="credit_nozzle_id[]" class="form-control form-control-sm credit-nozzle-select" onchange="updateCreditItem(this)" required>' +
                nozzleOptionsHtml +
            '</select>' +
        '</td>' +
        '<td>' +
            '<div class="d-flex flex-column align-items-start">' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_perm_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Permanent Slip" checked onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-primary" for="st_perm_' + rowId + '" style="font-size:11px; cursor:pointer;">Permanent</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_bal_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Balanced Slip" onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-info" for="st_bal_' + rowId + '" style="font-size:11px; cursor:pointer;">Balanced</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_temp_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Temporary Slip" onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-warning" for="st_temp_' + rowId + '" style="font-size:11px; cursor:pointer; color:#b07800 !important;">Temporary</label>' +
                '</div>' +
                '<div class="mt-1 return-slip-box return-slip-box_' + rowId + '" style="display:none;">' +
                    '<div class="custom-control custom-checkbox">' +
                        '<input type="hidden" name="credit_is_returned[]" class="credit-is-returned-val" value="0">' +
                        '<input type="checkbox" class="custom-control-input credit-is-returned" id="chk_ret_' + rowId + '" onchange="onReturnCheckboxChange(this)">' +
                        '<label class="custom-control-label font-weight-bold text-success" for="chk_ret_' + rowId + '" style="font-size:11px; cursor:pointer;">Received</label>' +
                    '</div>' +
                    '<span class="badge badge-warning text-dark font-weight-bold p-1 mt-1 slip-return-status-text" style="font-size:9.5px; display:block; text-align:left;">' +
                        '<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)' +
                    '</span>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="credit_slip_type[]" class="credit-slip-type-val" value="Permanent Slip">' +
        '</td>' +
        '<td>' +
            '<input type="text" name="credit_slip_no[]" class="form-control form-control-sm credit-slip-no font-weight-bold text-monospace" placeholder="Slip #" required>' +
        '</td>' +
        '<td>' +
            '<input type="text" name="credit_vehicle_number[]" list="registeredVehiclesList" class="form-control form-control-sm credit-vehicle-number font-weight-bold text-monospace" placeholder="Pick Vehicle" oninput="onCreditVehicleInput(this)" onchange="onCreditVehicleInput(this)" required>' +
            '<div class="vehicle-match-info small text-left mt-1" style="display:none; font-size:10.5px; line-height:1.2;"></div>' +
        '</td>' +
        '<td><input type="text" name="credit_account_number[]" class="form-control form-control-sm credit-account-number font-weight-bold" placeholder="Cust ID" readonly style="background-color:#e9ecef; cursor:not-allowed;" required></td>' +
        '<td><input type="text" class="form-control form-control-sm credit-item-name" disabled></td>' +
        '<td><input type="number" step="0.01" name="credit_quantity[]" class="form-control form-control-sm credit-qty font-weight-bold text-primary" value="0" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_rate[]" class="form-control form-control-sm credit-rate font-weight-bold" value="0" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_amount[]" class="form-control form-control-sm credit-amount-field" value="0" readonly style="background-color:#f8f9fa;"></td>' +
        '<td><input type="number" step="0.01" name="credit_charge_amount[]" class="form-control form-control-sm credit-charge-amount-field font-weight-bold text-primary" value="0" readonly style="background-color:#eef2ff;" required></td>' +
        '<td><input type="number" step="0.01" name="credit_cash_rate[]" class="form-control form-control-sm credit-cash-rate" value="0"></td>' +
        '<td><input type="number" step="0.01" name="credit_issue_quantity[]" class="form-control form-control-sm credit-issue-qty" value="0" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_balance_1[]" class="form-control form-control-sm" value="0"></td>' +
        '<td><input type="number" step="0.01" name="credit_balance_2[]" class="form-control form-control-sm" value="0"></td>' +
        '<td><input type="number" step="0.01" name="credit_wasoli[]" class="form-control form-control-sm credit-wasoli font-weight-bold text-warning" value="0" oninput="calculateCreditRow(this)"></td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeCreditRow(this)"><i class="fas fa-trash-alt"></i></button></td>' +
        '</tr>';

    $('#creditSalesBody').append(rowHtml);

    var $newRow = $('#credit_row_' + rowId);
    updateCreditItem($newRow.find('.credit-nozzle-select')[0]);
    onSlipTypeChange($newRow.find('input[name="slip_type_radio_' + rowId + '"]:checked')[0], rowId);
}

function removeCreditRow(btn) {
    if ($('#creditSalesBody tr').length <= 1) {
        alert('At least one row is required.');
        return;
    }
    $(btn).closest('tr').remove();
    updateAllTotals();
}

function onReturnCheckboxChange(checkboxElement) {
    var $box = $(checkboxElement).closest('.return-slip-box');
    var isChecked = $(checkboxElement).is(':checked');
    $box.find('.credit-is-returned-val').val(isChecked ? 1 : 0);
    var $statusLabel = $box.find('.slip-return-status-text');
    if (isChecked) {
        $statusLabel.removeClass('badge-warning text-dark').addClass('badge-success text-white')
                    .html('<i class="fas fa-check-circle mr-1"></i> Received (Settled)');
    } else {
        $statusLabel.removeClass('badge-success text-white').addClass('badge-warning text-dark')
                    .html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
    }
    calculateCreditRow(checkboxElement);
}

function onSlipTypeChange(radioElement, rowId) {
    if (!radioElement) return;
    var val = $(radioElement).val();
    var $row = $(radioElement).closest('tr');
    $row.find('.credit-slip-type-val').val(val);

    var $issueQty  = $row.find('.credit-issue-qty');
    var $wasoli    = $row.find('.credit-wasoli');
    var $chargeAmt = $row.find('.credit-charge-amount-field');
    var $retBox    = $row.find('.return-slip-box');

    if (val === 'Balanced Slip') {
        $issueQty.prop('disabled', false).css('background-color', '');
        $wasoli.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $retBox.hide();
        $retBox.find('input[type="checkbox"]').prop('checked', false);
        $retBox.find('.credit-is-returned-val').val(0);
        $chargeAmt.val('0.00');
    } else if (val === 'Temporary Slip') {
        $issueQty.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $wasoli.prop('disabled', false).css('background-color', '');
        $retBox.show();
        var isChecked = $retBox.find('input[type="checkbox"]').is(':checked');
        var $statusLabel = $retBox.find('.slip-return-status-text');
        if (isChecked) {
            $statusLabel.removeClass('badge-warning text-dark').addClass('badge-success text-white').html('<i class="fas fa-check-circle mr-1"></i> Received (Settled)');
        } else {
            $statusLabel.removeClass('badge-success text-white').addClass('badge-warning text-dark').html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
        }
    } else { // Permanent Slip
        $issueQty.prop('disabled', false).css('background-color', '');
        $wasoli.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $retBox.hide();
        $retBox.find('input[type="checkbox"]').prop('checked', false);
        $retBox.find('.credit-is-returned-val').val(0);
    }

    calculateCreditRow($row.find('.credit-qty')[0]);
}

function updateCreditItem(selectElement) {
    var nzId = $(selectElement).val();
    var $row = $(selectElement).closest('tr');
    var nz = nozzlesData.find(function(n) { return n.id == nzId; });
    if (nz) {
        $row.find('.credit-item-name').val(nz.item_name || '');
        var cr = parseFloat(nz.credit_rate) || 0;
        var ca = parseFloat(nz.cash_rate) || 0;
        $row.find('.credit-rate').val(cr > 0 ? cr : ca);
        $row.find('.credit-cash-rate').val(ca);
    }
    calculateCreditRow($row.find('.credit-qty')[0]);
}

function onCreditVehicleInput(inputElement) {
    var val = $(inputElement).val().trim().toUpperCase();
    var $row = $(inputElement).closest('tr');
    var $accountField = $row.find('.credit-account-number');
    var $infoDiv = $row.find('.vehicle-match-info');

    if (!val) {
        $accountField.val('');
        $infoDiv.hide().html('');
        return;
    }

    var matched = vehiclesData.find(function(v) {
        return (v.reg_number && v.reg_number.toUpperCase() === val) ||
               (v.numeric_number && v.numeric_number.toUpperCase() === val);
    });

    if (matched) {
        $accountField.val(matched.customer_id);
        $infoDiv.show().html('<span class="text-success font-weight-bold"><i class="fas fa-check-circle"></i> ' + matched.customer_name + '</span> <span class="badge badge-info">' + parseFloat(matched.fuel_limit).toFixed(0) + ' Ltr</span>');
    } else {
        $accountField.val('');
        $infoDiv.show().html('<span class="text-warning"><i class="fas fa-exclamation-circle"></i> Unregistered</span>');
    }
}

function calculateCreditRow(element) {
    var $row = $(element).closest('tr');
    var qty = parseFloat($row.find('.credit-qty').val()) || 0;
    var rate = parseFloat($row.find('.credit-rate').val()) || 0;
    var slipType = $row.find('.credit-slip-type-val').val();
    var isReturned = $row.find('.credit-is-returned').is(':checked');

    var grossAmount = qty * rate;
    $row.find('.credit-amount-field').val(grossAmount.toFixed(2));

    var chargeAmount = 0;
    if (slipType === 'Permanent Slip') {
        chargeAmount = grossAmount;
    } else if (slipType === 'Balanced Slip') {
        chargeAmount = 0;
    } else if (slipType === 'Temporary Slip') {
        if (isReturned) {
            chargeAmount = 0;
        } else {
            var wasoli = parseFloat($row.find('.credit-wasoli').val()) || 0;
            var effQty = wasoli > 0 ? wasoli : qty;
            chargeAmount = effQty * rate;
        }
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

function validateCreditForm() {
    var valid = true;
    $('#creditSalesBody tr').each(function(idx) {
        var slipNo = $(this).find('.credit-slip-no').val().trim();
        if (!slipNo) {
            alert('Please enter Slip No on row #' + (idx + 1));
            $(this).find('.credit-slip-no').focus();
            valid = false;
            return false;
        }
    });
    return valid;
}
</script>
</body>
</html>
