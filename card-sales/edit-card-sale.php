<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('card_sales', 'edit');

$target_date  = $_GET['date'] ?? '';
$target_shift = intval($_GET['shift_id'] ?? 0);
if (empty($target_date)) {
    header('Location: card-sales-list.php');
    exit;
}

$date_safe = mysqli_real_escape_string($connection, $target_date);
$shift_filter = ($target_shift > 0) ? " AND shift_id = '$target_shift'" : "";

// Fetch existing card transactions for this date and shift
$sql_existing = "SELECT * FROM tbl_meter_reading_card_sales 
                 WHERE sale_date = '$date_safe' $shift_filter AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                 ORDER BY id ASC";
$res_existing = mysqli_query($connection, $sql_existing);
$existing_rows = [];
if ($res_existing) {
    while ($r = mysqli_fetch_assoc($res_existing)) {
        $existing_rows[] = $r;
    }
}

if (empty($existing_rows)) {
    header('Location: card-sales-list.php');
    exit;
}

$current_shift_id = ($target_shift > 0) ? $target_shift : intval($existing_rows[0]['shift_id'] ?? 0);

// Ensure trace_no and difference columns exist (self-healing migration)
$chk_col = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_card_sales LIKE 'trace_no'");
if ($chk_col && mysqli_num_rows($chk_col) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_card_sales ADD COLUMN trace_no VARCHAR(64) DEFAULT NULL AFTER batch_no");
}
$chk_diff = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_card_sales LIKE 'difference'");
if ($chk_diff && mysqli_num_rows($chk_diff) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_card_sales ADD COLUMN difference DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount");
}

// Fetch Nozzles with attached items
$nozzles = [];
$q_noz = mysqli_query($connection, "SELECT n.id, n.name, n.item_id, i.name AS item_name, i.cash_rate, i.credit_rate
                                    FROM tbl_nozzles n
                                    LEFT JOIN tbl_items i ON n.item_id = i.id
                                    WHERE n.deleted_at IS NULL AND n.status = 'Active'
                                    ORDER BY n.name ASC");
if ($q_noz) {
    while ($r = mysqli_fetch_assoc($q_noz)) {
        $nozzles[] = $r;
    }
}

// Fetch Card Machines with fee percentage and revenue charge
$card_machines = [];
$q_cm = mysqli_query($connection, "SELECT id, name, charges_percentage, revenue_charge 
                                   FROM tbl_card_machines 
                                   WHERE deleted_at IS NULL 
                                   ORDER BY name ASC");
if ($q_cm) {
    while ($r = mysqli_fetch_assoc($q_cm)) {
        $card_machines[] = $r;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_date     = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? $target_date);
    $new_shift_id = intval($_POST['shift_id'] ?? $current_shift_id);

    $nozzle_ids   = $_POST['card_nozzle_id'] ?? [];
    $machine_ids  = $_POST['card_machine_id'] ?? [];
    $batch_nos    = $_POST['card_batch_no'] ?? [];
    $trace_nos    = $_POST['card_trace_no'] ?? [];
    $amounts      = $_POST['card_amount'] ?? [];
    $differences  = $_POST['card_difference'] ?? [];

    if (empty($new_shift_id)) {
        $error_msg = 'Please select a Shift before updating.';
    } elseif (empty($machine_ids)) {
        $error_msg = 'Please keep at least one card machine transaction row.';
    } else {
        mysqli_begin_transaction($connection);
        try {
            $del_shift_clause = ($current_shift_id > 0) ? " AND shift_id = '$current_shift_id'" : "";

            // Soft-delete previous rows for this date and shift
            $del_sql = "UPDATE tbl_meter_reading_card_sales SET deleted_at = NOW() 
                        WHERE sale_date = '$date_safe' $del_shift_clause AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            if (!mysqli_query($connection, $del_sql)) {
                throw new Exception("Error updating existing rows: " . mysqli_error($connection));
            }

            for ($i = 0; $i < count($machine_ids); $i++) {
                $m_id      = intval($machine_ids[$i]);
                $noz_id    = intval($nozzle_ids[$i] ?? 0);
                $batch_no  = mysqli_real_escape_string($connection, trim($batch_nos[$i] ?? ''));
                $trace_no  = mysqli_real_escape_string($connection, trim($trace_nos[$i] ?? ''));
                $amt       = floatval($amounts[$i] ?? 0);

                // Calculate fee and net amount automatically from card machine settings
                $fee_pct = 0.00;
                $rev_pct = 0.00;
                foreach ($card_machines as $cm_item) {
                    if ($cm_item['id'] == $m_id) {
                        $fee_pct = floatval($cm_item['charges_percentage'] ?? 0);
                        $rev_pct = floatval($cm_item['revenue_charge'] ?? 0);
                        break;
                    }
                }
                $schg = round($amt * ($fee_pct / 100), 2);
                $net  = round($amt - $schg, 2);

                // User entered difference or auto-calculated from machine revenue charge %
                if (isset($differences[$i]) && trim($differences[$i]) !== '') {
                    $diff = floatval($differences[$i]);
                } else {
                    $diff = round($amt * ($rev_pct / 100), 2);
                }

                // Look up attached item_id and cash rate from nozzle
                $item_id   = 0;
                $cash_rate = 0.00;
                foreach ($nozzles as $nz) {
                    if ($nz['id'] == $noz_id) {
                        $item_id   = intval($nz['item_id']);
                        $cash_rate = floatval($nz['cash_rate'] ?? 0);
                        break;
                    }
                }
                $qty = ($cash_rate > 0) ? round($amt / $cash_rate, 2) : 0.00;

                $ins_sql = "INSERT INTO tbl_meter_reading_card_sales 
                            (meter_reading_id, sale_date, shift_id, staff_id, card_machine_id, item_id, rate_type, 
                             quantity, rate, amount, difference, batch_no, trace_no, service_charges, net_amount, nozzle_id, no_of_cards)
                            VALUES 
                            (0, '$new_date', '$new_shift_id', 0, '$m_id', '$item_id', 'Cash', 
                             '$qty', '$cash_rate', '$amt', '$diff', '$batch_no', '$trace_no', '$schg', '$net', '$noz_id', 1)";
                if (!mysqli_query($connection, $ins_sql)) {
                    throw new Exception("Error saving card transaction: " . mysqli_error($connection));
                }
            }
            mysqli_commit($connection);
            header('Location: card-sales-list.php?msg=updated');
            exit;
        } catch (Exception $e) {
            mysqli_rollback($connection);
            $error_msg = $e->getMessage();
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
    <title>PPMS - Edit Card Sale Reading</title>
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
        #cardSalesTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:11.5px; font-weight:600; text-align:center; vertical-align:middle;
        }
        #cardSalesTable td { vertical-align:middle; padding:6px; font-size:12.5px; }
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
            <h4><i class="fas fa-edit mr-2 text-warning"></i> Edit Card Sale Reading</h4>
            <small class="text-white-50">Editing card transactions for <?php echo date('d-m-Y', strtotime($target_date)); ?></small>
        </div>
        <a href="card-sales-list.php" class="btn btn-outline-light btn-sm font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Back to List
        </a>
    </div>

    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="cardSaleForm" onsubmit="return validateAndCleanCardForm()">
        <!-- Date Card -->
        <div class="form-card mb-3">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-calendar-alt mr-2"></i> Transaction Date</span>
            </div>
            <div class="p-3">
                <div class="row align-items-center">
                    <div class="col-md-3 col-sm-6">
                        <label class="font-weight-bold text-dark mb-1"><i class="fas fa-calendar-day mr-1 text-primary"></i> Sale Date <span class="text-danger">*</span></label>
                        <input type="date" name="sale_date" id="sale_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($target_date); ?>" required>
                    </div>
                    <div class="col-md-3 col-sm-6 mt-3 mt-sm-0">
                        <label class="font-weight-bold text-dark mb-1"><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                        <select name="shift_id" id="shift_id" class="form-control font-weight-bold" required>
                            <option value="">-- Select Shift --</option>
                            <?php foreach ($shifts as $sh): ?>
                                <option value="<?php echo $sh['id']; ?>" <?php echo ($current_shift_id == $sh['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sh['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-sm-12 mt-3 mt-md-0 text-md-right text-muted small">
                        <span class="badge badge-primary px-2 py-1"><i class="fas fa-credit-card mr-1"></i> Bank POS Card Sales Entry</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Terminal Entries Card -->
        <div class="form-card">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-credit-card mr-2"></i> Card Sale Details (Multiple Entries)</span>
                <button type="button" class="btn btn-sm btn-light font-weight-bold text-primary" onclick="addCardRow()">
                    <i class="fas fa-plus mr-1"></i> Add New Row
                </button>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm text-center mb-0" id="cardSalesTable" style="font-size: 13px;">
                        <thead>
                            <tr style="background: var(--primary-color); color: #fff;">
                                <th style="width: 20%;">Nozzle <span class="text-danger">*</span></th>
                                <th style="width: 20%;">Machine Type <span class="text-danger">*</span></th>
                                <th style="width: 15%;">Batch No</th>
                                <th style="width: 15%;">Trace No</th>
                                <th style="width: 15%;">Amount (Rs.) <span class="text-danger">*</span></th>
                                <th style="width: 15%;">Difference</th>
                                <th style="width: 50px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cardSalesBody">
                            <!-- Injected via JS -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <button type="button" class="btn btn-primary btn-sm font-weight-bold" onclick="addCardRow()">
                        <i class="fas fa-plus mr-1"></i> Add New Row
                    </button>
                </div>

                <!-- Bottom Summary & Action -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div class="d-flex align-items-center">
                        <div class="mr-4">
                            <span class="text-muted small d-block font-weight-bold">TOTAL ENTRIES:</span>
                            <span class="text-dark font-weight-bold" id="lblTotalEntries">0 Entries</span>
                        </div>
                        <div class="mr-4">
                            <span class="text-muted small d-block font-weight-bold">TOTAL AMOUNT:</span>
                            <span class="text-primary font-weight-bold" style="font-size:16px;" id="lblTotalGross">Rs. 0.00</span>
                        </div>
                        <div class="mr-4">
                            <span class="text-muted small d-block font-weight-bold">TOTAL DIFFERENCE:</span>
                            <span class="text-danger font-weight-bold" style="font-size:16px;" id="lblTotalDiff">Rs. 0.00</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4">
                        <i class="fas fa-save mr-1"></i> Update Card Sales
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
var nozzlesData       = <?php echo json_encode($nozzles); ?>;
var cardMachinesData  = <?php echo json_encode($card_machines); ?>;
var existingCardsData = <?php echo json_encode($existing_rows); ?>;
var cardRowIdx = 0;

$(document).ready(function() {
    if (existingCardsData && existingCardsData.length > 0) {
        for (var i = 0; i < existingCardsData.length; i++) {
            addCardRowWithData(existingCardsData[i]);
        }
    } else {
        addCardRow();
    }
    calculateCardTotal();

    // Auto-append next row when typing or selecting in the current last row
    $('#cardSalesBody').on('input change', 'tr:last-child input, tr:last-child select', function() {
        var $tr = $(this).closest('tr');
        if ($tr.is(':last-child') && isCardRowActive($tr)) {
            addCardRow();
        }
    });
});

function isCardRowActive($tr) {
    if (!$tr || $tr.length === 0) return false;
    var amt = parseFloat($tr.find('.card-amount-field').val()) || 0;
    var noz = $tr.find('select[name="card_nozzle_id[]"]').val() || '';
    var machineId = $tr.find('.card-machine-select').val() || '';
    var batch = ($tr.find('input[name="card_batch_no[]"]').val() || '').trim();
    var trace = ($tr.find('input[name="card_trace_no[]"]').val() || '').trim();
    return (amt > 0 || noz !== '' || machineId !== '' || batch !== '' || trace !== '');
}

function onRowMachineOrAmountChange(el) {
    var $tr = $(el).closest('tr');
    var $machSelect = $tr.find('.card-machine-select');
    var revCharge = parseFloat($machSelect.find('option:selected').attr('data-revenue-charge')) || 0;
    var amt = parseFloat($tr.find('.card-amount-field').val()) || 0;

    // Auto-calculate Difference = Amount * (Revenue Charge % / 100)
    var diff = (amt * (revCharge / 100)).toFixed(2);
    $tr.find('.card-diff-field').val(diff);

    calculateCardTotal();
}

function addCardRow() {
    addCardRowWithData(null);
}

function addCardRowWithData(data) {
    var rowId = cardRowIdx++;
    var selectedMachineId = data ? data.card_machine_id : '';
    var selectedNozzleId  = data ? data.nozzle_id : '';
    var batchNoVal        = data ? (data.batch_no || '') : '';
    var traceNoVal        = data ? (data.trace_no || '') : '';
    var amountVal         = data ? parseFloat(data.amount) : 0;
    var diffVal           = data ? (data.difference !== undefined && data.difference !== null ? parseFloat(data.difference) : 0.00) : 0.00;

    var nozzleOptions = '<option value="">-- Select Nozzle --</option>';
    for (var j = 0; j < nozzlesData.length; j++) {
        var nz = nozzlesData[j];
        var isNzSel = (nz.id == selectedNozzleId) ? 'selected' : '';
        nozzleOptions += '<option value="' + nz.id + '" ' + isNzSel + '>' + nz.name + '</option>';
    }

    var machineOptions = '<option value="" data-revenue-charge="0">-- Select Machine --</option>';
    for (var i = 0; i < cardMachinesData.length; i++) {
        var cm = cardMachinesData[i];
        var rev = parseFloat(cm.revenue_charge) || 0;
        var isSel = (cm.id == selectedMachineId) ? 'selected' : '';
        machineOptions += '<option value="' + cm.id + '" data-revenue-charge="' + rev + '" ' + isSel + '>' + cm.name + '</option>';
    }

    // If diffVal is 0 and amountVal > 0, calculate from machine revenue charge %
    if (data && (!diffVal || diffVal === 0) && amountVal > 0 && selectedMachineId) {
        for (var k = 0; k < cardMachinesData.length; k++) {
            if (cardMachinesData[k].id == selectedMachineId) {
                var rCharge = parseFloat(cardMachinesData[k].revenue_charge) || 0;
                diffVal = parseFloat((amountVal * (rCharge / 100)).toFixed(2));
                break;
            }
        }
    }

    var rowHtml = '<tr id="card_row_' + rowId + '">' +
        '<td>' +
            '<select name="card_nozzle_id[]" class="form-control form-control-sm">' +
                nozzleOptions +
            '</select>' +
        '</td>' +
        '<td>' +
            '<select name="card_machine_id[]" class="form-control form-control-sm card-machine-select" onchange="onRowMachineOrAmountChange(this)">' +
                machineOptions +
            '</select>' +
        '</td>' +
        '<td>' +
            '<input type="text" name="card_batch_no[]" class="form-control form-control-sm" placeholder="Batch No" value="' + batchNoVal + '">' +
        '</td>' +
        '<td>' +
            '<input type="text" name="card_trace_no[]" class="form-control form-control-sm" placeholder="Trace No" value="' + traceNoVal + '">' +
        '</td>' +
        '<td>' +
            '<input type="number" step="0.01" min="0" name="card_amount[]" class="form-control form-control-sm card-amount-field font-weight-bold text-primary" value="' + amountVal + '" placeholder="0.00" oninput="onRowMachineOrAmountChange(this)">' +
        '</td>' +
        '<td>' +
            '<input type="number" step="0.01" name="card_difference[]" class="form-control form-control-sm card-diff-field font-weight-bold text-danger" value="' + (diffVal || 0).toFixed(2) + '" placeholder="0.00" oninput="calculateCardTotal()">' +
        '</td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeCardRow(this)"><i class="fas fa-trash-alt"></i></button></td>' +
        '</tr>';

    $('#cardSalesBody').append(rowHtml);
    calculateCardTotal();
}

function removeCardRow(btn) {
    if ($('#cardSalesBody tr').length <= 1) {
        alert('At least one row is required.');
        return;
    }
    $(btn).closest('tr').remove();
    calculateCardTotal();
}

function calculateCardTotal() {
    var totEntries = 0, totAmount = 0, totDiff = 0;
    $('#cardSalesBody tr').each(function() {
        var amt = parseFloat($(this).find('.card-amount-field').val()) || 0;
        var diff = parseFloat($(this).find('.card-diff-field').val()) || 0;
        var noz = $(this).find('select[name="card_nozzle_id[]"]').val() || '';
        var mach = $(this).find('.card-machine-select').val() || '';
        if (amt > 0 || noz !== '' || mach !== '') {
            totEntries++;
        }
        totAmount += amt;
        totDiff += diff;
    });

    $('#lblTotalEntries').text(totEntries + ' Entries');
    $('#lblTotalGross').text('Rs. ' + totAmount.toFixed(2));
    $('#lblTotalDiff').text('Rs. ' + totDiff.toFixed(2));
}

function validateAndCleanCardForm() {
    // 0. Validate Sale Date
    var saleDate = $('#sale_date').val();
    if (!saleDate || saleDate.trim() === '') {
        alert('Please select Sale Date before saving.');
        $('#sale_date').focus().addClass('is-invalid');
        return false;
    }
    $('#sale_date').removeClass('is-invalid');

    // 1. Automatically prune trailing blank/untouched rows
    while ($('#cardSalesBody tr').length > 1) {
        var $lastRow = $('#cardSalesBody tr:last-child');
        if (!isCardRowActive($lastRow)) {
            $lastRow.remove();
        } else {
            break;
        }
    }
    calculateCardTotal();

    // 2. Validate that at least one active row exists
    var $rows = $('#cardSalesBody tr');
    if ($rows.length === 0 || !isCardRowActive($rows.first())) {
        alert('Please enter at least one card sale transaction before saving.');
        return false;
    }

    // 3. Enforce mandatory inputs on all retained rows
    var isValid = true;
    $rows.each(function(idx) {
        var rowNum = idx + 1;
        var noz = $(this).find('select[name="card_nozzle_id[]"]').val();
        var mach = $(this).find('.card-machine-select').val();
        var amt = parseFloat($(this).find('.card-amount-field').val()) || 0;

        if (!noz) {
            alert('Please select a Nozzle on row #' + rowNum);
            $(this).find('select[name="card_nozzle_id[]"]').focus();
            isValid = false;
            return false;
        }
        if (!mach) {
            alert('Please select a Machine Type on row #' + rowNum);
            $(this).find('.card-machine-select').focus();
            isValid = false;
            return false;
        }
        if (amt <= 0) {
            alert('Please enter a valid Card Amount greater than 0 on row #' + rowNum);
            $(this).find('.card-amount-field').focus();
            isValid = false;
            return false;
        }
    });

    return isValid;
}
</script>
</body>
</html>
