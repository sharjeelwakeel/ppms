<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';

$tank_id = isset($_GET['tank_id']) ? intval($_GET['tank_id']) : 0;
if ($tank_id <= 0 && isset($_POST['tank_id'])) {
    $tank_id = intval($_POST['tank_id']);
}
if ($tank_id <= 0) {
    header('Location:tanks-list.php');
    exit;
}

// Fetch Tank Info
$stmt_tank = mysqli_prepare($connection, "SELECT t.*, i.name AS item_name, i.unit AS item_unit 
                                          FROM tbl_tanks t 
                                          LEFT JOIN tbl_items i ON t.item_id = i.id 
                                          WHERE t.id = ? LIMIT 1");
$tank = null;
if ($stmt_tank) {
    mysqli_stmt_bind_param($stmt_tank, "i", $tank_id);
    mysqli_stmt_execute($stmt_tank);
    $res_tank = mysqli_stmt_get_result($stmt_tank);
    $tank = mysqli_fetch_assoc($res_tank);
    mysqli_stmt_close($stmt_tank);
}

if (!$tank) {
    header('Location:tanks-list.php');
    exit;
}

// Fetch Attached Active Nozzles with Previous Meter Reading
$attached_nozzles = [];
$stmt_noz = mysqli_prepare($connection, "SELECT id, name, start_reading FROM tbl_nozzles WHERE tank_id = ? AND status = 'Active' ORDER BY id ASC");
if ($stmt_noz) {
    mysqli_stmt_bind_param($stmt_noz, "i", $tank_id);
    mysqli_stmt_execute($stmt_noz);
    $res_noz = mysqli_stmt_get_result($stmt_noz);
    while ($row_n = mysqli_fetch_assoc($res_noz)) {
        $nid = intval($row_n['id']);
        $prev_rdg = floatval($row_n['start_reading']);
        
        $stmt_prev_m = mysqli_prepare($connection, "SELECT ml.reading 
            FROM tbl_tank_dip_meter_logs ml
            INNER JOIN tbl_tank_dip_logs dl ON ml.dip_log_id = dl.id
            WHERE dl.tank_id = ? AND ml.nozzle_id = ? AND dl.deleted_at IS NULL
            ORDER BY dl.date DESC, dl.id DESC LIMIT 1");
        if ($stmt_prev_m) {
            mysqli_stmt_bind_param($stmt_prev_m, "ii", $tank_id, $nid);
            mysqli_stmt_execute($stmt_prev_m);
            $res_prev_m = mysqli_stmt_get_result($stmt_prev_m);
            if ($r_prev_m = mysqli_fetch_assoc($res_prev_m)) {
                $prev_rdg = floatval($r_prev_m['reading']);
            }
            mysqli_stmt_close($stmt_prev_m);
        }

        $row_n['prev_reading'] = $prev_rdg;
        $attached_nozzles[] = $row_n;
    }
    mysqli_stmt_close($stmt_noz);
}

// Fetch Shifts
$shifts = [];
$res_shifts = mysqli_query($connection, "SELECT id, name FROM tbl_shifts WHERE status = 'Active' ORDER BY id ASC");
if ($res_shifts) {
    while ($row_s = mysqli_fetch_assoc($res_shifts)) {
        $shifts[] = $row_s;
    }
}

$error = '';

// Handle Form Submission
if (isset($_POST['submit_dip_log'])) {
    $tank_id = intval($_POST['tank_id']);
    $date = trim($_POST['date']);
    $shift_id = intval($_POST['shift_id']);
    $dip_mm = floatval($_POST['dip_mm']);
    $balance = floatval($_POST['balance']);
    $addition = floatval($_POST['addition']);
    $usage_litre = floatval($_POST['usage_litre']);
    $book_balance = floatval($_POST['book_balance']);
    $per_dip_gain_loss = floatval($_POST['per_dip_gain_loss']);
    $overall_gain_loss = floatval($_POST['overall_gain_loss']);
    $accumulative_pmg = floatval($_POST['accumulative_pmg']);
    $remarks = trim($_POST['remarks']);

    if (empty($date) || $shift_id <= 0 || $dip_mm < 0) {
        $error = "Date, Shift, and Dip (mm) are required fields.";
    } else {
        mysqli_begin_transaction($connection);
        try {
            // 1. Insert Parent Header Record into tbl_tank_dip_logs
            $stmt_ins = mysqli_prepare($connection, "INSERT INTO tbl_tank_dip_logs 
                (tank_id, date, shift_id, dip_mm, balance, addition, usage_litre, book_balance, per_dip_gain_loss, overall_gain_loss, accumulative_pmg, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_ins, "isidddddddds", 
                $tank_id, $date, $shift_id, $dip_mm, $balance, $addition, $usage_litre, $book_balance, $per_dip_gain_loss, $overall_gain_loss, $accumulative_pmg, $remarks);
            mysqli_stmt_execute($stmt_ins);
            $dip_log_id = mysqli_insert_id($connection);
            mysqli_stmt_close($stmt_ins);

            // 2. Insert Per-Nozzle Meter Reading Detail Records into tbl_tank_dip_meter_logs
            if (isset($_POST['nozzle_reading']) && is_array($_POST['nozzle_reading'])) {
                $stmt_m = mysqli_prepare($connection, "INSERT INTO tbl_tank_dip_meter_logs (dip_log_id, nozzle_id, reading) VALUES (?, ?, ?)");
                foreach ($_POST['nozzle_reading'] as $noz_id => $rdg) {
                    $noz_id_int = intval($noz_id);
                    $rdg_val = floatval($rdg);
                    mysqli_stmt_bind_param($stmt_m, "iid", $dip_log_id, $noz_id_int, $rdg_val);
                    mysqli_stmt_execute($stmt_m);
                }
                mysqli_stmt_close($stmt_m);
            }

            mysqli_commit($connection);
            header("Location: dip-chart.php?tank_id=" . $tank_id . "&msg=added");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($connection);
            $error = "Failed to save dip log: " . $e->getMessage();
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
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        .card-header-navy {
            background: var(--gradient-header) !important;
            color: #fff;
        }
        .auto-calc-field {
            background-color: #e6f0fa !important;
            font-weight: bold;
        }
        .modal-header-navy {
            background: var(--gradient-header) !important;
            color: #fff;
        }
        .nozzle-card {
            border-left: 4px solid var(--primary-color);
            background: #f8f9fa;
        }
		</style>
		<title>Add Dip Log - <?php echo htmlspecialchars($tank['tank_name']); ?></title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-5">
				<div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="card shadow-sm border-0">
                            <div class="card-header card-header-navy d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-plus-circle mr-2"></i>Add Tank Dip Log - 
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($tank['tank_name']); ?></span> 
                                    (<?php echo htmlspecialchars($tank['item_name'] ?? 'Product'); ?>)
                                </h5>
                                <a href="dip-chart.php?tank_id=<?php echo $tank_id; ?>" class="btn btn-sm btn-light font-weight-bold text-dark">
                                    <i class="fas fa-times mr-1"></i> Cancel
                                </a>
                            </div>
                            <div class="card-body p-4">
                                <?php if (!empty($error)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <form action="add-dip-log.php?tank_id=<?php echo $tank_id; ?>" method="POST" id="dipLogForm">
                                    <input type="hidden" name="tank_id" id="tank_id" value="<?php echo $tank_id; ?>">
                                    
                                    <div class="row">
                                        <!-- Date Field -->
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="date" id="date" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>

                                        <!-- Shift Field -->
                                        <div class="col-md-6 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                                            <select class="form-control" name="shift_id" id="shift_id" required>
                                                <option value="">-- Select Shift --</option>
                                                <?php foreach ($shifts as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- ATTACHED METERS / NOZZLES READINGS SECTION -->
                                    <?php if (!empty($attached_nozzles)): ?>
                                        <div class="card my-3 shadow-sm border">
                                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0 font-weight-bold text-dark">
                                                    <i class="fas fa-gas-pump mr-2 text-primary"></i>Attached Tank Meters / Nozzles Readings
                                                </h6>
                                                <small class="text-muted font-italic">Usage = Current Reading - Previous Reading</small>
                                            </div>
                                            <div class="card-body p-3">
                                                <div class="row">
                                                    <?php foreach ($attached_nozzles as $noz): ?>
                                                        <div class="col-md-4 mb-3">
                                                            <div class="p-3 border rounded nozzle-card">
                                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                                    <label class="font-weight-bold text-dark mb-0">
                                                                        <i class="fas fa-tachometer-alt mr-1 text-primary"></i><?php echo htmlspecialchars($noz['name']); ?>
                                                                    </label>
                                                                    <span class="badge badge-secondary" id="nozzle_prev_badge_<?php echo $noz['id']; ?>">
                                                                        Prev: <?php echo number_format($noz['prev_reading'], 2); ?>
                                                                    </span>
                                                                </div>
                                                                <label class="small text-muted mb-1">Current Reading</label>
                                                                <input type="number" step="0.01" min="0" 
                                                                       class="form-control nozzle-reading-input font-weight-bold text-primary" 
                                                                       name="nozzle_reading[<?php echo $noz['id']; ?>]" 
                                                                       id="nozzle_reading_<?php echo $noz['id']; ?>" 
                                                                       data-prev-reading="<?php echo $noz['prev_reading']; ?>"
                                                                       value="0.00" placeholder="0.00">
                                                                <div class="d-flex justify-content-between align-items-center mt-2">
                                                                    <small class="text-muted">Net Nozzle Usage:</small>
                                                                    <span class="font-weight-bold text-success small" id="nozzle_usage_text_<?php echo $noz['id']; ?>">0.00 Ltrs</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <hr class="my-3">

                                    <div class="row">
                                        <!-- Dip (mm) Input -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-ruler-vertical mr-1 text-primary"></i> Dip (mm) <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="0" class="form-control" name="dip_mm" id="dip_mm" placeholder="e.g. 450.00" required>
                                            <small class="text-muted">Enter manual tank dip in mm</small>
                                        </div>

                                        <!-- Balance (Ltrs) - Auto Lookup -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-balance-scale mr-1 text-primary"></i> Balance (Ltrs) <small class="text-muted">(Auto Lookup)</small></label>
                                            <input type="number" step="0.01" class="form-control auto-calc-field text-primary" name="balance" id="balance" placeholder="0.00" readonly required>
                                            <small class="text-muted">Fetched from tbl_dip_lookup</small>
                                        </div>

                                        <!-- Addition (Ltrs) -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-truck-loading mr-1 text-primary"></i> Addition (Ltrs)</label>
                                            <input type="number" step="0.01" min="0" class="form-control" name="addition" id="addition" value="0.00">
                                            <small class="text-muted">Fuel delivery / tanker addition</small>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <!-- Usage (Ltrs) - Sum of (Current - Prev) attached meter readings -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-gas-pump mr-1 text-primary"></i> Usage (Ltrs) <small class="text-muted">(Net Meters)</small></label>
                                            <input type="number" step="0.01" class="form-control auto-calc-field" name="usage_litre" id="usage_litre" value="0.00">
                                            <small class="text-muted">Sum of (Current - Prev) meter readings</small>
                                        </div>

                                        <!-- Book Balance - Auto calculated -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-book mr-1 text-primary"></i> Book Balance <small class="text-muted">(Auto Calc)</small></label>
                                            <input type="number" step="0.01" class="form-control auto-calc-field" name="book_balance" id="book_balance" value="0.00" readonly>
                                            <small class="text-muted" id="bookBalanceHelp">Prev Book Bal - Usage + Addition</small>
                                        </div>

                                        <!-- Per Dip Gain / Loss - Auto calculated -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-chart-pie mr-1 text-primary"></i> Per Dip Gain / Loss <small class="text-muted">(Auto Calc)</small></label>
                                            <input type="number" step="0.01" class="form-control auto-calc-field" name="per_dip_gain_loss" id="per_dip_gain_loss" value="0.00" readonly>
                                            <small class="text-muted">Formula: Current Balance - Book Balance</small>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <!-- Overall Gain / Loss - Auto calculated -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-exchange-alt mr-1 text-primary"></i> Overall Gain / Loss <small class="text-muted">(Auto Calc)</small></label>
                                            <input type="number" step="0.01" class="form-control auto-calc-field" name="overall_gain_loss" id="overall_gain_loss" value="0.00" readonly>
                                            <small class="text-muted">Formula: Current Loss - Prev Loss</small>
                                        </div>

                                        <!-- Accumulative PMG / Product -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-layer-group mr-1 text-primary"></i> Accumulative PMG</label>
                                            <input type="number" step="0.01" class="form-control" name="accumulative_pmg" id="accumulative_pmg" value="0.00">
                                            <small class="text-muted">Formula: Prev Accumulative + Usage</small>
                                        </div>

                                        <!-- Remarks Field -->
                                        <div class="col-md-4 form-group">
                                            <label class="font-weight-bold"><i class="fas fa-sticky-note mr-1 text-primary"></i> Remarks</label>
                                            <input type="text" class="form-control" name="remarks" id="remarks" placeholder="Optional notes">
                                        </div>
                                    </div>

                                    <div class="form-group text-right mt-4 mb-0">
                                        <button type="submit" name="submit_dip_log" class="btn btn-primary px-4 py-2 font-weight-bold">
                                            <i class="fas fa-save mr-1"></i> Save Dip Log
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
			</div>
		</main>

        <!-- MODAL 1: Missing Meter Reading Warning Modal -->
        <div class="modal fade" id="missingMeterModal" tabindex="-1" role="dialog" aria-labelledby="missingMeterModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-navy">
                        <h5 class="modal-title" id="missingMeterModalLabel">
                            <i class="fas fa-exclamation-triangle text-warning mr-2"></i>Meter Reading Missing
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-tachometer-alt text-warning mb-3" style="font-size: 48px;"></i>
                        <p class="mb-0 font-weight-bold text-dark" id="missingMeterModalMsg">
                            You don't add meter reading for this date & shift. Please add manually or complete meter reading entry.
                        </p>
                    </div>
                    <div class="modal-footer justify-content-center bg-light">
                        <a href="../meter-readings/add-meter-reading.php" target="_blank" class="btn btn-primary">
                            <i class="fas fa-plus mr-1"></i> Add Meter Reading
                        </a>
                        <button type="button" class="btn btn-secondary" id="btnEnterUsageManually" data-dismiss="modal">
                            <i class="fas fa-edit mr-1"></i> Enter Manually
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL 2: Unmapped Dip MM Modal -->
        <div class="modal fade" id="missingDipModal" tabindex="-1" role="dialog" aria-labelledby="missingDipModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header modal-header-navy">
                        <h5 class="modal-title" id="missingDipModalLabel">
                            <i class="fas fa-exclamation-circle text-warning mr-2"></i>Dip Measurement Not Found
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body text-center p-4">
                        <i class="fas fa-ruler-vertical text-danger mb-3" style="font-size: 48px;"></i>
                        <p class="mb-0 font-weight-bold text-dark" id="missingDipModalMsg">
                            Dip reading value not found in Dip Lookup table. Please check Dip Lookup master.
                        </p>
                    </div>
                    <div class="modal-footer justify-content-center bg-light">
                        <a href="../dip-lookup/dip-lookup-list.php" target="_blank" class="btn btn-primary">
                            <i class="fas fa-ruler-vertical mr-1"></i> Open Dip Lookup Master
                        </a>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script>
    let prevBookBalance = 0.00;
    let prevPerDipGainLoss = 0.00;
    let prevAccumulativePmg = 0.00;
    let storageCapacity = 0.00;
    let hasPrevLog = false;

	$(document).ready(function() {
		// Fetch previous dip log stats on page load
        fetchPrevDipLog();

        // Event listeners
        $('#date, #shift_id').on('change', function() {
            fetchMeterReadings();
        });

        $('#dip_mm').on('change blur keyup', function(e) {
            if (e.type === 'keyup' && e.keyCode !== 13) return;
            lookupDipMM();
        });

        $('.nozzle-reading-input').on('input change', function() {
            calculateTotalNozzleUsage();
        });

        $('#addition, #usage_litre, #balance, #accumulative_pmg').on('input change', function() {
            calculateFormulas();
        });

        // Enter Manually button handler from Missing Meter Reading Modal
        $('#btnEnterUsageManually').on('click', function() {
            $('#missingMeterModal').modal('hide');
            $('.nozzle-reading-input').first().focus().select();
        });
	});

    function fetchPrevDipLog() {
        const tankId = $('#tank_id').val();
        $.ajax({
            url: 'get-prev-dip-log.php',
            type: 'GET',
            data: { tank_id: tankId },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    hasPrevLog = res.has_prev;
                    storageCapacity = parseFloat(res.storage_capacity) || 0.00;
                    if (res.has_prev) {
                        prevBookBalance = parseFloat(res.prev_book_balance) || 0.00;
                        prevPerDipGainLoss = parseFloat(res.prev_per_dip_gain_loss) || 0.00;
                        prevAccumulativePmg = parseFloat(res.prev_accumulative_pmg) || 0.00;
                        $('#bookBalanceHelp').text('Prev Book Bal - Usage + Addition');
                    } else {
                        prevBookBalance = 0.00;
                        prevPerDipGainLoss = 0.00;
                        prevAccumulativePmg = 0.00;
                        $('#bookBalanceHelp').text('First entry: Takes Physical Balance');
                    }
                    calculateFormulas();
                }
            }
        });
    }

    function fetchMeterReadings() {
        const tankId = $('#tank_id').val();
        const dateVal = $('#date').val();
        const shiftId = $('#shift_id').val();

        if (!dateVal || !shiftId || shiftId <= 0) return;

        $.ajax({
            url: 'get-tank-meter-readings.php',
            type: 'GET',
            data: { tank_id: tankId, date: dateVal, shift_id: shiftId },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    if (res.meter_found) {
                        // Auto-fill prev_reading and current_reading for nozzles
                        if (res.nozzles && res.nozzles.length > 0) {
                            res.nozzles.forEach(function(n) {
                                const prevR = parseFloat(n.prev_reading) || 0.00;
                                const currR = parseFloat(n.current_reading) || 0.00;
                                $('#nozzle_reading_' + n.id).attr('data-prev-reading', prevR);
                                $('#nozzle_prev_badge_' + n.id).text('Prev: ' + prevR.toFixed(2));
                                $('#nozzle_reading_' + n.id).val(currR.toFixed(2));
                            });
                        }
                        calculateTotalNozzleUsage();
                    } else {
                        if (res.nozzles && res.nozzles.length > 0) {
                            res.nozzles.forEach(function(n) {
                                const prevR = parseFloat(n.prev_reading) || 0.00;
                                $('#nozzle_reading_' + n.id).attr('data-prev-reading', prevR);
                                $('#nozzle_prev_badge_' + n.id).text('Prev: ' + prevR.toFixed(2));
                            });
                        }
                        // Trigger Warning Modal if meter reading not found
                        $('#missingMeterModalMsg').text(res.message || 'You don\'t add meter reading for this date and shift. Please add manually or complete meter reading.');
                        $('#missingMeterModal').modal('show');
                        calculateTotalNozzleUsage();
                    }
                }
                calculateFormulas();
            }
        });
    }

    function calculateTotalNozzleUsage() {
        let totalUsage = 0.00;
        $('.nozzle-reading-input').each(function() {
            const nozId = $(this).attr('id').replace('nozzle_reading_', '');
            const prevR = parseFloat($(this).attr('data-prev-reading')) || 0.00;
            const currR = parseFloat($(this).val()) || 0.00;
            
            // Formula: Current Reading - Previous Meter Reading
            const netUsage = Math.max(0.00, currR - prevR);
            $('#nozzle_usage_text_' + nozId).text(netUsage.toFixed(2) + ' Ltrs');

            totalUsage += netUsage;
        });
        $('#usage_litre').val(totalUsage.toFixed(2));
        calculateFormulas();
    }

    function lookupDipMM() {
        const dipMM = parseFloat($('#dip_mm').val());
        if (isNaN(dipMM) || dipMM < 0) return;

        $.ajax({
            url: '../dip-lookup/lookup-by-mm.php',
            type: 'GET',
            data: { dip_mm: dipMM },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#balance').val(res.balance.toFixed(2));
                    calculateFormulas();
                } else {
                    $('#balance').val('0.00');
                    $('#missingDipModalMsg').text(res.message || 'Dip reading value not found in Dip Lookup table. Please check Dip Lookup master.');
                    $('#missingDipModal').modal('show');
                    calculateFormulas();
                }
            }
        });
    }

    function calculateFormulas() {
        const dipBalance = parseFloat($('#balance').val()) || 0.00;
        const addition = parseFloat($('#addition').val()) || 0.00;
        const usage = parseFloat($('#usage_litre').val()) || 0.00;

        let bookBalance = 0.00;
        if (hasPrevLog) {
            bookBalance = prevBookBalance - usage + addition;
        } else {
            bookBalance = dipBalance;
        }
        $('#book_balance').val(bookBalance.toFixed(2));

        const perDipGainLoss = dipBalance - bookBalance;
        $('#per_dip_gain_loss').val(perDipGainLoss.toFixed(2));

        const overallGainLoss = perDipGainLoss - prevPerDipGainLoss;
        $('#overall_gain_loss').val(overallGainLoss.toFixed(2));

        const accumulativePmg = prevAccumulativePmg + usage;
        if ($('#accumulative_pmg').data('manual') !== true) {
            $('#accumulative_pmg').val(accumulativePmg.toFixed(2));
        }
    }

    $('#accumulative_pmg').on('input', function() {
        $(this).data('manual', true);
    });
	</script>
</html>
