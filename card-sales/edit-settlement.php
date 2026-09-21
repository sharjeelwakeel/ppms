<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('card_sales', 'edit');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: settlement-list.php');
    exit;
}

// Self-healing migration for auxiliary columns
$chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'shift_id'");
if ($chk_col && mysqli_num_rows($chk_col) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN shift_id INT(11) NOT NULL DEFAULT 0 AFTER settlement_date, ADD KEY idx_shift_id (shift_id)");
}
$chk_rev_pct = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'revenue_percentage'");
if ($chk_rev_pct && mysqli_num_rows($chk_rev_pct) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN revenue_percentage DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER service_charges");
}
$chk_rev_amt = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_sale_settlements LIKE 'revenue_amount'");
if ($chk_rev_amt && mysqli_num_rows($chk_rev_amt) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_sale_settlements ADD COLUMN revenue_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER revenue_percentage");
}

// Fetch existing settlement record
$sql = "SELECT * FROM tbl_card_sale_settlements 
        WHERE id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
        LIMIT 1";
$res = mysqli_query($connection, $sql);
$settlement = mysqli_fetch_assoc($res);

if (!$settlement) {
    header('Location: settlement-list.php');
    exit;
}

// Fetch active card machines
$card_machines = [];
$q_cm = mysqli_query($connection, "SELECT id, name, charges_percentage, revenue_charge FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY name ASC");
if ($q_cm) {
    while ($r = mysqli_fetch_assoc($q_cm)) {
        $card_machines[] = $r;
    }
}

// Fetch active shifts
$shifts = [];
$q_sh = mysqli_query($connection, "SELECT id, name FROM tbl_shifts WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
if ($q_sh) {
    while ($r = mysqli_fetch_assoc($q_sh)) {
        $shifts[] = $r;
    }
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_machine_id = intval($_POST['card_machine_id'] ?? 0);
    $settlement_date = mysqli_real_escape_string($connection, trim($_POST['settlement_date'] ?? ''));
    $shift_id        = intval($_POST['shift_id'] ?? 0);
    $batch_no        = mysqli_real_escape_string($connection, trim($_POST['batch_no'] ?? ''));
    $no_of_cards     = intval($_POST['no_of_cards'] ?? 1);

    $total_amount    = floatval($_POST['total_amount'] ?? 0);
    $net_amount      = floatval($_POST['net_amount'] ?? ($_POST['amount'] ?? 0));
    $charges_pct     = floatval($_POST['charges_percentage'] ?? 0);
    $service_charges = floatval($_POST['service_charges'] ?? 0);
    $revenue_pct     = floatval($_POST['revenue_percentage'] ?? 0);
    $revenue_amount  = floatval($_POST['revenue_amount'] ?? 0);
    $notes           = mysqli_real_escape_string($connection, trim($_POST['notes'] ?? ''));

    // Fetch machine rate defaults if not submitted
    if ($charges_pct <= 0 || $revenue_pct <= 0) {
        foreach ($card_machines as $cm) {
            if ($cm['id'] == $card_machine_id) {
                if ($charges_pct <= 0) $charges_pct = floatval($cm['charges_percentage'] ?? 0);
                if ($revenue_pct <= 0) $revenue_pct = floatval($cm['revenue_charge'] ?? 0);
                break;
            }
        }
    }

    // Mathematical reconciliation:
    if ($total_amount > 0 && $net_amount <= 0) {
        $service_charges = round($total_amount * ($charges_pct / 100), 2);
        $net_amount      = round($total_amount - $service_charges, 2);
    } elseif ($net_amount > 0 && $total_amount <= 0) {
        if ($charges_pct > 0 && $charges_pct < 100) {
            $total_amount    = round($net_amount / (1 - ($charges_pct / 100)), 2);
            $service_charges = round($total_amount - $net_amount, 2);
        } else {
            $total_amount    = $net_amount;
            $service_charges = 0.00;
        }
    } elseif ($total_amount > 0 && $net_amount > 0) {
        if ($service_charges <= 0) {
            $service_charges = round($total_amount * ($charges_pct / 100), 2);
        }
    }

    if ($revenue_amount <= 0 && $total_amount > 0 && $revenue_pct > 0) {
        $revenue_amount = round($total_amount * ($revenue_pct / 100), 2);
    }

    if (empty($settlement_date)) {
        $error_msg = 'Please select a valid Settlement Date.';
    } elseif ($shift_id <= 0) {
        $error_msg = 'Please select a Shift.';
    } elseif ($card_machine_id <= 0) {
        $error_msg = 'Please select a Card Machine.';
    } elseif (empty($batch_no)) {
        $error_msg = 'Please enter the POS Batch Number.';
    } elseif ($no_of_cards < 1) {
        $error_msg = 'Number of cards must be at least 1.';
    } elseif ($total_amount <= 0 && $net_amount <= 0) {
        $error_msg = 'Please enter a valid Settlement Amount greater than 0.';
    } else {
        $upd_sql = "UPDATE tbl_card_sale_settlements SET 
                    card_machine_id = '$card_machine_id',
                    settlement_date = '$settlement_date',
                    shift_id = '$shift_id',
                    batch_no = '$batch_no',
                    no_of_cards = '$no_of_cards',
                    amount = '$total_amount',
                    charges_percentage = '$charges_pct',
                    service_charges = '$service_charges',
                    revenue_percentage = '$revenue_pct',
                    revenue_amount = '$revenue_amount',
                    net_amount = '$net_amount',
                    notes = '$notes'
                    WHERE id = '$id'";

        if (mysqli_query($connection, $upd_sql)) {
            header('Location: settlement-list.php?msg=updated');
            exit;
        } else {
            $error_msg = 'Error updating settlement: ' . mysqli_error($connection);
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
    <title>PPMS - Edit Card Sale Settlement</title>
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
        .calc-step-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            height: 100%;
        }
        .calc-step-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .calc-step-val {
            font-size: 1.15rem;
            font-weight: 800;
        }
        .breakdown-table thead th {
            background: var(--primary-color) !important;
            color: #fff;
            font-size: 11.5px;
            text-align: center;
            vertical-align: middle;
        }
        .breakdown-table td {
            font-size: 12px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5" style="max-width: 960px;">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-edit mr-2 text-warning"></i> Edit Card Sale Settlement</h4>
            <small class="text-white-50">Modify bank POS terminal batch settlement for Batch #<?php echo htmlspecialchars($settlement['batch_no']); ?></small>
        </div>
        <a href="settlement-list.php" class="btn btn-outline-light btn-sm font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Back to Settlements
        </a>
    </div>

    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="editSettlementForm" onsubmit="return validateSettlementForm(event)">
        <!-- Details Card -->
        <div class="form-card mb-4">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-info-circle mr-2"></i> Settlement & Batch Identification</span>
                <span class="badge badge-light text-primary font-weight-bold" id="badgeMatchStatus">Loading calculations...</span>
            </div>
            <div class="p-4">
                <div class="row">
                    <!-- Settlement Date -->
                    <div class="col-md-6 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-primary"></i> Settlement Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="settlement_date" id="settlement_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($settlement['settlement_date']); ?>" required onchange="onDateChange()">
                    </div>

                    <!-- Shift -->
                    <div class="col-md-6 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span>
                        </label>
                        <select name="shift_id" id="shift_id" class="form-control font-weight-bold" required onchange="onShiftChange()">
                            <option value="">-- Select Shift --</option>
                            <?php foreach ($shifts as $sh): ?>
                                <option value="<?php echo $sh['id']; ?>" <?php echo (intval($settlement['shift_id'] ?? 0) == $sh['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sh['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Card Machine -->
                    <div class="col-md-6 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-credit-card mr-1 text-primary"></i> Card Machine <span class="text-danger">*</span>
                        </label>
                        <select name="card_machine_id" id="card_machine_id" class="form-control font-weight-bold" required onchange="onMachineChange()">
                            <option value="" data-fee="0">-- Select Machine --</option>
                            <?php foreach ($card_machines as $cm): ?>
                                <option value="<?php echo $cm['id']; ?>" data-fee="<?php echo floatval($cm['charges_percentage']); ?>" <?php echo ($cm['id'] == $settlement['card_machine_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cm['name']); ?> (Fee: <?php echo number_format($cm['charges_percentage'], 4); ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Batch No (Manual Input with Datalist Suggestions) -->
                    <div class="col-md-6 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-barcode mr-1 text-primary"></i> Batch No <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="batch_no" id="batch_no" list="batch_no_list" class="form-control font-weight-bold" placeholder="Enter Batch No from POS Slip..." value="<?php echo htmlspecialchars($settlement['batch_no']); ?>" required oninput="onBatchInputChange()" onchange="onBatchInputChange()" autocomplete="off">
                        <datalist id="batch_no_list"></datalist>
                        <small class="text-muted">Type POS batch number or select from recorded batches</small>
                    </div>
                </div>

                <!-- Popup Trigger Bar -->
                <div class="p-3 bg-light border rounded d-flex flex-wrap justify-content-between align-items-center mt-2">
                    <div>
                        <span class="font-weight-bold text-dark d-block">
                            <i class="fas fa-calculator text-primary mr-1"></i> Batch System Readings & Calculation Breakdown:
                        </span>
                        <small class="text-muted" id="lblPopupSummaryDesc">View calculation breakdown and attached card swipe records in popup.</small>
                    </div>
                    <button type="button" class="btn btn-info font-weight-bold px-3 my-1" id="btnOpenCalcModal" onclick="openCalculationsModal()">
                        <i class="fas fa-window-restore mr-1"></i> View Calculations & Swipes Popup (<span id="btnModalSummaryCount">0 Swipes</span>)
                    </button>
                </div>
            </div>
        </div>

        <!-- Operator Settlement Slip Entry Card -->
        <div class="form-card mb-4">
            <div class="form-card-header">
                <i class="fas fa-receipt mr-2"></i> Enter Physical Slip Settlement Figures
            </div>
            <div class="p-4">
                <div class="row">
                    <!-- No of Cards -->
                    <div class="col-md-4 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-layer-group mr-1 text-primary"></i> No. of Cards (Swipes) <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="no_of_cards" id="no_of_cards" class="form-control font-weight-bold text-center" value="<?php echo intval($settlement['no_of_cards']); ?>" min="1" required>
                        <small class="text-muted">Total card transactions on slip</small>
                    </div>

                    <!-- Total Pure Amount (Gross Sales) -->
                    <div class="col-md-4 col-sm-6 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-money-bill mr-1 text-primary"></i> Total Amount (Pure Sales) <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.01" min="0.01" name="total_amount" id="total_amount" class="form-control font-weight-bold text-primary" value="<?php echo floatval($settlement['amount']); ?>" required oninput="onPureAmountInputChange()">
                        <small class="text-muted">Total card sales before fee deduction</small>
                    </div>

                    <!-- Settlement Amount (Net after Service Charges) -->
                    <div class="col-md-4 col-sm-12 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="font-weight-bold text-dark mb-0">
                                <i class="fas fa-money-bill-wave mr-1 text-success"></i> Net Settlement (Rs.) <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-outline-success btn-sm py-0 font-weight-bold" id="btnCopyCalcTotal" onclick="copyCalculatedTotal()" style="display:none;">
                                <i class="fas fa-copy mr-1"></i> Use Calc (<span id="lblCopyTotalVal">Rs. 0.00</span>)
                            </button>
                        </div>
                        <input type="number" step="0.01" min="0.01" name="net_amount" id="amount" class="form-control font-weight-bold text-success" value="<?php echo floatval($settlement['net_amount'] > 0 ? $settlement['net_amount'] : $settlement['amount']); ?>" required>
                        <small class="text-muted">Settled deposit amount from POS terminal slip</small>
                    </div>

                    <!-- Hidden Auxiliary Calculations -->
                    <input type="hidden" name="charges_percentage" id="charges_percentage" value="<?php echo floatval($settlement['charges_percentage'] ?? 0); ?>">
                    <input type="hidden" name="service_charges" id="service_charges" value="<?php echo floatval($settlement['service_charges'] ?? 0); ?>">
                    <input type="hidden" name="revenue_percentage" id="revenue_percentage" value="<?php echo floatval($settlement['revenue_percentage'] ?? 0); ?>">
                    <input type="hidden" name="revenue_amount" id="revenue_amount" value="<?php echo floatval($settlement['revenue_amount'] ?? 0); ?>">

                    <!-- Notes -->
                    <div class="col-md-12 mb-3">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-sticky-note mr-1 text-muted"></i> Remarks / Notes (Optional)
                        </label>
                        <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional notes regarding this terminal batch settlement..."><?php echo htmlspecialchars($settlement['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <a href="settlement-list.php" class="btn btn-outline-secondary font-weight-bold">
                        <i class="fas fa-times mr-1"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4">
                        <i class="fas fa-save mr-1"></i> Update Settlement
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Calculations & Itemized Swipes Modal Popup -->
<div class="modal fade" id="batchCalculationsModal" tabindex="-1" role="dialog" aria-labelledby="batchCalculationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold" id="batchCalculationsModalLabel">
                    <i class="fas fa-calculator text-warning mr-2"></i> System Readings Breakdown for Batch #<span id="modalBatchNoTitle">-</span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- 4 Step Calculation Breakdown Cards (Exact user requested labels) -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white font-weight-bold text-dark py-2">
                        <i class="fas fa-calculator text-primary mr-1"></i> Calculation Breakdown
                    </div>
                    <div class="card-body p-3">
                        <div class="row">
                            <!-- 1. TOTAL AMOUNT (PURE SALES) -->
                            <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                                <div class="calc-step-box border-primary">
                                    <div class="calc-step-label text-primary"><i class="fas fa-money-bill mr-1"></i> TOTAL AMOUNT</div>
                                    <div class="calc-step-val text-primary" id="lblStepTotalAmount">Rs. 0.00</div>
                                    <small class="text-muted d-block mt-1">Pure Sales (without revenue & fee)</small>
                                </div>
                            </div>

                            <!-- 2. REVENUE CHARGES (SEPARATE) -->
                            <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                                <div class="calc-step-box" style="border-color:#0284c7;">
                                    <div class="calc-step-label" style="color:#0284c7;"><i class="fas fa-chart-line mr-1"></i> REVENUE CHARGES</div>
                                    <div class="calc-step-val" style="color:#0284c7;" id="lblStepRevenue">Rs. 0.00</div>
                                    <small class="text-muted d-block mt-1"><span id="lblRevenueRateText">Current Rate: 0.00%</span></small>
                                </div>
                            </div>

                            <!-- 3. SERVICE CHARGES (BANK FEE) -->
                            <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                                <div class="calc-step-box border-danger">
                                    <div class="calc-step-label text-danger"><i class="fas fa-percent mr-1"></i> SERVICE CHARGES</div>
                                    <div class="calc-step-val text-danger" id="lblStepDeduction">-Rs. 0.00</div>
                                    <small class="text-muted d-block mt-1"><span id="lblDeductionRateText">Bank Fee: 0.00%</span></small>
                                </div>
                            </div>

                            <!-- 4. NET TOTAL (- SERVICE CHARGES) -->
                            <div class="col-md-3 col-sm-6">
                                <div class="calc-step-box border-success" style="background:#f0fdf4;">
                                    <div class="calc-step-label text-success"><i class="fas fa-check-circle mr-1"></i> NET TOTAL (- FEE)</div>
                                    <div class="calc-step-val text-success" style="font-size:1.35rem;" id="lblStepFinalTotal">Rs. 0.00</div>
                                    <small class="text-muted d-block mt-1">Pure Sales - Fee (Revenue Excluded)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Itemized Table of Swipes for this Batch -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="font-weight-bold text-dark"><i class="fas fa-list-ol text-primary mr-1"></i> Itemized Card Sale Swipes</span>
                        <span class="badge badge-primary" id="badgeItemCount">0 Records</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm text-center mb-0 breakdown-table">
                                <thead>
                                    <tr>
                                        <th style="width: 45px;">#</th>
                                        <th>Date & Shift</th>
                                        <th>Nozzle / Item</th>
                                        <th>Trace No</th>
                                        <th>Swipe Pure Sale (Rs.)</th>
                                        <th>Revenue Diff (+)</th>
                                    </tr>
                                </thead>
                                <tbody id="batchItemsBody">
                                    <tr>
                                        <td colspan="6" class="text-muted text-center py-3">
                                            <i class="fas fa-spinner fa-spin mr-1"></i> Loading batch details...
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot id="batchItemsFoot" style="display:none;" class="font-weight-bold bg-light">
                                    <tr>
                                        <td colspan="4" class="text-right text-uppercase">Total Pure Swipes:</td>
                                        <td class="text-primary font-weight-bold" id="footTotGross">Rs. 0.00</td>
                                        <td class="text-info font-weight-bold" id="footTotDiff">+Rs. 0.00</td>
                                    </tr>
                                    <tr class="bg-white text-muted small">
                                        <td colspan="6" class="text-center py-2 font-weight-normal">
                                            <i class="fas fa-info-circle text-primary mr-1"></i> Bank service charges are deducted on the batch total amount (e.g. on Total Rs. 400.00), not on individual swipe transactions.
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-secondary font-weight-bold" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Close
                </button>
                <button type="button" class="btn btn-success font-weight-bold px-4" onclick="useCalculatedTotalFromModal()">
                    <i class="fas fa-check-circle mr-1"></i> Apply Total (<span id="lblModalApplyTotalVal">Rs. 0.00</span>) to Settlement Amount
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
var currentCalculatedTotal = 0;
var currentBatchData = null;
var allowSubmissionOverride = false;
var batchInputTimeout = null;

$(document).ready(function() {
    var machId   = $('#card_machine_id').val();
    var shiftId  = $('#shift_id').val();
    var saleDate = $('#settlement_date').val();
    var batchNo  = ($('#batch_no').val() || '').trim();

    if (machId) {
        $.ajax({
            url: 'ajax-get-batch-summary.php',
            type: 'GET',
            data: {
                card_machine_id: machId,
                batch_no: '',
                sale_date: saleDate,
                shift_id: shiftId
            },
            dataType: 'json',
            success: function(res) {
                var $dl = $('#batch_no_list').empty();
                if (res && res.available_batches && res.available_batches.length > 0) {
                    for (var i = 0; i < res.available_batches.length; i++) {
                        var b = res.available_batches[i];
                        $dl.append('<option value="' + b.batch_no + '">Batch #' + b.batch_no + ' (' + b.row_count + ' swipes, Rs. ' + b.total_amount_fmt + ')</option>');
                    }
                }
            }
        });
    }

    if (batchNo) {
        fetchBatchDetails(batchNo);
    }
});

function onDateChange() {
    if ($('#card_machine_id').val()) {
        onMachineChange();
    }
}

function onShiftChange() {
    // 1. Unselect Bank / Card Machine
    $('#card_machine_id').val('');

    // 2. Clear Batch No input & datalist
    $('#batch_no').val('');
    $('#batch_no_list').empty();

    // 3. Reset Calculations & Modal
    resetCalculations();
}

function onMachineChange() {
    var machId   = $('#card_machine_id').val();
    var shiftId  = $('#shift_id').val();
    var saleDate = $('#settlement_date').val();

    // 1. Clear Batch No input & datalist
    $('#batch_no').val('');
    $('#batch_no_list').empty();
    resetCalculations();

    if (!machId) {
        return;
    }

    // 2. Fetch available batches for datalist suggestions
    $.ajax({
        url: 'ajax-get-batch-summary.php',
        type: 'GET',
        data: {
            card_machine_id: machId,
            batch_no: '',
            sale_date: saleDate,
            shift_id: shiftId
        },
        dataType: 'json',
        success: function(res) {
            var $dl = $('#batch_no_list').empty();
            if (res && res.available_batches && res.available_batches.length > 0) {
                for (var i = 0; i < res.available_batches.length; i++) {
                    var b = res.available_batches[i];
                    $dl.append('<option value="' + b.batch_no + '">Batch #' + b.batch_no + ' (' + b.row_count + ' swipes, Rs. ' + b.total_amount_fmt + ')</option>');
                }
            }
        }
    });
}

function onBatchInputChange() {
    clearTimeout(batchInputTimeout);
    batchInputTimeout = setTimeout(function() {
        var val = ($('#batch_no').val() || '').trim();
        if (!val) {
            resetCalculations();
            return;
        }
        fetchBatchDetails(val);
    }, 250);
}

function fetchBatchDetails(batchNo) {
    var machId   = $('#card_machine_id').val();
    var shiftId  = $('#shift_id').val() || 0;
    var saleDate = $('#settlement_date').val() || '';

    if (!machId || !batchNo) {
        resetCalculations();
        return;
    }

    $('#badgeMatchStatus').removeClass('badge-success badge-warning badge-danger').addClass('badge-info').text('Fetching Summary...');

    $.ajax({
        url: 'ajax-get-batch-summary.php',
        type: 'GET',
        data: {
            card_machine_id: machId,
            batch_no: batchNo,
            sale_date: saleDate,
            shift_id: shiftId
        },
        dataType: 'json',
        success: function(res) {
            if (!res || res.status !== 'success') {
                return;
            }

            currentBatchData = res;
            $('#modalBatchNoTitle').text(batchNo);

            if (res.matched && res.items && res.items.length > 0) {
                $('#badgeMatchStatus').removeClass('badge-light badge-info badge-danger badge-warning').addClass('badge-success').text('Matched (' + res.card_count + ' Swipes)');
                $('#btnModalSummaryCount').text(res.card_count + ' Swipes');
                $('#badgeItemCount').text(res.card_count + ' Records');
                $('#lblPopupSummaryDesc').html('<strong class="text-success">' + res.card_count + ' swipes found</strong> for Batch #' + batchNo + '. Pure Sales: <strong>Rs. ' + res.total_amount_fmt + '</strong> | Revenue: <strong>+Rs. ' + res.total_difference_fmt + '</strong> | Bank Fee: <strong>-Rs. ' + res.service_charges_fmt + '</strong> | Net Settlement: <strong class="text-success">Rs. ' + res.total_fmt + '</strong>');

                // 1. Total Pure Sales Amount (without revenue & without fee)
                $('#lblStepTotalAmount').text('Rs. ' + res.total_amount_fmt);

                // 2. Revenue Charges (Separate) with current machine rate
                $('#lblStepRevenue').text('Rs. ' + res.total_difference_fmt);
                $('#lblRevenueRateText').text('Current Rate: ' + parseFloat(res.revenue_charge || 0).toFixed(2) + '%');

                // 3. Service Charges (Bank Fee)
                $('#lblStepDeduction').text('-Rs. ' + res.service_charges_fmt);
                $('#lblDeductionRateText').text('Bank Fee: ' + parseFloat(res.charges_percentage).toFixed(2) + '%');

                // 4. Net Settlement Total (- Service Charges)
                $('#lblStepFinalTotal').text('Rs. ' + res.total_fmt);

                // Auto-fill No of Cards if current value is default 1
                if (parseInt($('#no_of_cards').val()) <= 1) {
                    $('#no_of_cards').val(res.card_count);
                }

                if (!parseFloat($('#total_amount').val())) {
                    $('#total_amount').val(parseFloat(res.total_amount).toFixed(2));
                }
                $('#charges_percentage').val(parseFloat(res.charges_percentage).toFixed(4));
                $('#service_charges').val(parseFloat(res.service_charges).toFixed(2));
                $('#revenue_percentage').val(parseFloat(res.revenue_charge || 0).toFixed(4));
                $('#revenue_amount').val(parseFloat(res.total_difference || 0).toFixed(2));

                // Show fast-copy button and update modal apply label
                currentCalculatedTotal = res.total;
                $('#lblCopyTotalVal').text('Rs. ' + res.total_fmt);
                $('#lblModalApplyTotalVal').text('Rs. ' + res.total_fmt);
                $('#btnCopyCalcTotal').show();

                // Render Itemized Swipes Table inside modal
                var rowsHtml = '';
                for (var j = 0; j < res.items.length; j++) {
                    var itm = res.items[j];
                    rowsHtml += '<tr>' +
                        '<td>' + (j + 1) + '</td>' +
                        '<td>' + itm.sale_date + ' <small class="text-muted">(' + itm.shift_name + ')</small></td>' +
                        '<td>' + itm.nozzle_name + '</td>' +
                        '<td><span class="text-monospace font-weight-bold">' + (itm.trace_no || '-') + '</span></td>' +
                        '<td class="font-weight-bold text-primary">Rs. ' + itm.amount_fmt + '</td>' +
                        '<td class="font-weight-bold text-info">+Rs. ' + itm.difference_fmt + '</td>' +
                        '</tr>';
                }
                $('#batchItemsBody').html(rowsHtml);
                $('#batchItemsFoot').show();
                $('#footTotGross').text('Rs. ' + res.total_amount_fmt);
                $('#footTotDiff').text('+Rs. ' + res.total_difference_fmt);

            } else {
                $('#badgeMatchStatus').removeClass('badge-info badge-light badge-success').addClass('badge-warning').text('No Swipes Logged for Batch');
                $('#lblPopupSummaryDesc').text('No recorded card swipes found in system for Batch #' + batchNo + '.');
                resetCalculations();
            }
        }
    });
}

function onPureAmountInputChange() {
    var gross = parseFloat($('#total_amount').val()) || 0;
    var feePct = parseFloat($('#charges_percentage').val()) || 0;
    var revPct = parseFloat($('#revenue_percentage').val()) || 0;

    if (feePct <= 0) {
        var opt = $('#card_machine_id option:selected');
        feePct = parseFloat(opt.data('fee')) || 0;
        $('#charges_percentage').val(feePct.toFixed(4));
    }

    if (gross > 0) {
        var feeAmt = Math.round(gross * (feePct / 100) * 100) / 100;
        var netAmt = Math.round((gross - feeAmt) * 100) / 100;
        var revAmt = Math.round(gross * (revPct / 100) * 100) / 100;

        $('#service_charges').val(feeAmt.toFixed(2));
        $('#revenue_amount').val(revAmt.toFixed(2));
        currentCalculatedTotal = netAmt;
        $('#lblCopyTotalVal').text('Rs. ' + netAmt.toFixed(2));
        $('#btnCopyCalcTotal').show();
    }
}

function resetCalculations() {
    currentBatchData = null;
    currentCalculatedTotal = 0;
    $('#lblStepTotalAmount').text('Rs. 0.00');
    $('#lblStepRevenue').text('Rs. 0.00');
    $('#lblRevenueRateText').text('Current Rate: 0.00%');
    $('#lblStepDeduction').text('-Rs. 0.00');
    $('#lblDeductionRateText').text('Bank Fee: 0.00%');
    $('#lblStepFinalTotal').text('Rs. 0.00');
    $('#lblModalApplyTotalVal').text('Rs. 0.00');
    $('#btnModalSummaryCount').text('0 Swipes');
    $('#badgeItemCount').text('0 Records');
    $('#btnCopyCalcTotal').hide();
    $('#total_amount').val('');
    $('#charges_percentage').val('0.0000');
    $('#service_charges').val('0.00');
    $('#revenue_percentage').val('0.0000');
    $('#revenue_amount').val('0.00');
    $('#batchItemsBody').html('<tr><td colspan="8" class="text-muted text-center py-3"><i class="fas fa-info-circle mr-1"></i> No recorded card sales found for this batch. You can still manually enter the settlement amount.</td></tr>');
    $('#batchItemsFoot').hide();
}

function openCalculationsModal() {
    var machId = $('#card_machine_id').val();
    var batchNo = ($('#batch_no').val() || '').trim();

    if (!machId) {
        Swal.fire({ icon: 'warning', title: 'Machine Required', text: 'Please select a Card Machine first.' });
        $('#card_machine_id').focus();
        return;
    }
    if (!batchNo) {
        Swal.fire({ icon: 'warning', title: 'Batch Required', text: 'Please enter a Batch Number first.' });
        $('#batch_no').focus();
        return;
    }

    $('#batchCalculationsModal').modal('show');
}

function useCalculatedTotalFromModal() {
    copyCalculatedTotal();
    $('#batchCalculationsModal').modal('hide');
}

function copyCalculatedTotal() {
    if (currentCalculatedTotal > 0) {
        $('#amount').val(currentCalculatedTotal.toFixed(2));
    }
}

function validateSettlementForm(e) {
    if (allowSubmissionOverride) {
        return true;
    }

    var date  = $('#settlement_date').val();
    var shift = $('#shift_id').val();
    var mach  = $('#card_machine_id').val();
    var batch = ($('#batch_no').val() || '').trim();
    var cards = parseInt($('#no_of_cards').val()) || 0;
    var amt   = parseFloat($('#amount').val()) || 0;

    if (!date) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Missing Date', text: 'Please select Settlement Date.' });
        $('#settlement_date').focus();
        return false;
    }
    if (!shift || shift === '0' || shift === '') {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Missing Shift', text: 'Please select a Shift.' });
        $('#shift_id').focus();
        return false;
    }
    if (!mach) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Missing Machine', text: 'Please select a Card Machine.' });
        $('#card_machine_id').focus();
        return false;
    }
    if (!batch) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Missing Batch No', text: 'Please enter a Batch Number.' });
        $('#batch_no').focus();
        return false;
    }
    if (cards < 1) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Invalid Cards Count', text: 'Number of cards (swipes) must be at least 1.' });
        $('#no_of_cards').focus();
        return false;
    }
    if (amt <= 0) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Invalid Amount', text: 'Please enter a valid Settlement Amount greater than 0.' });
        $('#amount').focus();
        return false;
    }

    // Ensure batch summary is loaded and up-to-date for current machine & batch
    if (!currentBatchData || currentBatchData.batch_no !== batch || currentBatchData.machine_id != mach) {
        $.ajax({
            url: 'ajax-get-batch-summary.php',
            type: 'GET',
            async: false,
            data: {
                card_machine_id: mach,
                batch_no: batch,
                sale_date: date,
                shift_id: shift
            },
            dataType: 'json',
            success: function(res) {
                if (res && res.status === 'success') {
                    currentBatchData = res;
                }
            }
        });
    }

    // Calculation Check: If batch has recorded system readings
    if (currentBatchData && currentBatchData.matched) {
        var expectedTotal = parseFloat(currentBatchData.total) || 0;
        var enteredAmount = amt;
        var diff = enteredAmount - expectedTotal;

        if (Math.abs(diff) > 0.01) {
            if (e && typeof e.preventDefault === 'function') e.preventDefault();
            var diffSign = diff > 0 ? '+Rs. ' : '-Rs. ';
            var diffFormatted = diffSign + Math.abs(diff).toFixed(2);
            var colorClass = diff > 0 ? 'text-primary' : 'text-danger';

            var htmlContent = `
                <div class="text-left" style="font-size: 13.5px; line-height: 1.6;">
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="fas fa-exclamation-triangle mr-1"></i> <strong>Notice:</strong> The entered settlement amount does not match the calculated net batch total.
                    </div>
                    <table class="table table-sm table-bordered mb-2" style="font-size: 13px;">
                        <tbody>
                            <tr>
                                <td><strong>Pure Card Sales Amount:</strong></td>
                                <td class="text-right text-primary font-weight-bold">Rs. ${currentBatchData.total_amount_fmt}</td>
                            </tr>
                            <tr>
                                <td><strong>Revenue Charge (Separate):</strong></td>
                                <td class="text-right text-info font-weight-bold">+Rs. ${currentBatchData.total_difference_fmt} <small class="text-muted">(${parseFloat(currentBatchData.revenue_charge || 0).toFixed(2)}%)</small></td>
                            </tr>
                            <tr>
                                <td><strong>Bank Service Charges (${parseFloat(currentBatchData.charges_percentage).toFixed(2)}%):</strong></td>
                                <td class="text-right text-danger font-weight-bold">-Rs. ${currentBatchData.service_charges_fmt}</td>
                            </tr>
                            <tr class="table-light">
                                <td><strong>Expected Net Settlement (Sales - Fee):</strong></td>
                                <td class="text-right text-success font-weight-bold" style="font-size: 15px;">Rs. ${currentBatchData.total_fmt}</td>
                            </tr>
                            <tr class="table-active">
                                <td><strong>You Entered:</strong></td>
                                <td class="text-right font-weight-bold" style="font-size: 15px;">Rs. ${enteredAmount.toFixed(2)}</td>
                            </tr>
                            <tr class="${diff > 0 ? 'table-info' : 'table-danger'}">
                                <td><strong>Discrepancy (Difference):</strong></td>
                                <td class="text-right font-weight-bold ${colorClass}" style="font-size: 15px;">${diffFormatted}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mb-0 text-muted small">
                        Please review your physical POS terminal batch report. If this difference is expected, click <strong>Yes, Proceed & Save</strong> to continue.
                    </p>
                </div>
            `;

            Swal.fire({
                title: 'Settlement Amount Mismatch!',
                html: htmlContent,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#04204e',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check-circle mr-1"></i> Yes, Proceed & Save',
                cancelButtonText: '<i class="fas fa-edit mr-1"></i> Review & Fix',
                focusCancel: true,
                width: '560px'
            }).then((result) => {
                if (result.isConfirmed) {
                    allowSubmissionOverride = true;
                    document.getElementById('editSettlementForm').submit();
                } else {
                    $('#amount').focus();
                }
            });

            return false;
        }
    } else if (!currentBatchData || !currentBatchData.matched) {
        // No system readings logged for this batch
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        Swal.fire({
            title: 'No Recorded Sales for Batch #' + batch,
            text: 'There are no card swipe readings logged in the system for this machine and batch. Do you still want to proceed and save this manual settlement?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#04204e',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-check-circle mr-1"></i> Yes, Proceed & Save',
            cancelButtonText: '<i class="fas fa-times mr-1"></i> Cancel',
            width: '500px'
        }).then((result) => {
            if (result.isConfirmed) {
                allowSubmissionOverride = true;
                document.getElementById('editSettlementForm').submit();
            }
        });
        return false;
    }

    // All validations pass cleanly and amount matches expected total -> allow form submission!
    return true;
}
</script>
</body>
</html>
