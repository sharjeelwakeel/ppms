<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/nozzle_daily_sync.php';

check_access('card_sales', 'add');

// Fetch Nozzles with attached items
$nozzles = [];
$q_noz = mysqli_query($connection, "SELECT n.id, n.name, n.item_id, i.name AS item_name, i.cash_rate
                                    FROM tbl_nozzles n
                                    LEFT JOIN tbl_items i ON n.item_id = i.id
                                    WHERE n.deleted_at IS NULL AND n.status = 'Active'
                                    ORDER BY n.name ASC");
if ($q_noz) {
    while ($r = mysqli_fetch_assoc($q_noz)) {
        $nozzles[] = $r;
    }
}

// Fetch Card Machines with fee percentage
$card_machines = [];
$q_cm = mysqli_query($connection, "SELECT id, name, charges_percentage 
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
    $sale_date = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? date('Y-m-d'));
    $shift_id  = intval($_POST['shift_id'] ?? 0);

    $nozzle_ids    = $_POST['card_nozzle_id'] ?? [];
    $machine_ids   = $_POST['card_machine_id'] ?? [];
    $batch_nos     = $_POST['card_batch_no'] ?? [];
    $card_counts   = $_POST['card_no_of_cards'] ?? [];
    $amounts       = $_POST['card_amount'] ?? [];

    if (empty($shift_id)) {
        $error_msg = 'Please select a Shift before saving.';
    } elseif (empty($machine_ids)) {
        $error_msg = 'Please add at least one card machine transaction row before saving.';
    } else {
        mysqli_begin_transaction($connection);
        try {
            for ($i = 0; $i < count($machine_ids); $i++) {
                $m_id     = intval($machine_ids[$i]);
                $noz_id   = intval($nozzle_ids[$i] ?? 0);
                $batch_no = mysqli_real_escape_string($connection, trim($batch_nos[$i] ?? ''));
                $cards    = intval($card_counts[$i] ?? 1);
                $amt      = floatval($amounts[$i] ?? 0);

                // Calculate fee and net amount automatically from card machine settings
                $fee_pct = 0.00;
                foreach ($card_machines as $cm_item) {
                    if ($cm_item['id'] == $m_id) {
                        $fee_pct = floatval($cm_item['charges_percentage'] ?? 0);
                        break;
                    }
                }
                $schg = round($amt * ($fee_pct / 100), 2);
                $net  = round($amt - $schg, 2);

                // Look up attached item_id and cash_rate from nozzle
                $item_id   = 0;
                $fuel_rate = 0.00;
                foreach ($nozzles as $nz) {
                    if ($nz['id'] == $noz_id) {
                        $item_id   = intval($nz['item_id']);
                        $fuel_rate = floatval($nz['cash_rate'] ?? 0);
                        break;
                    }
                }

                // Calculate dispensed petrol volume (Litres)
                $qty = ($fuel_rate > 0) ? round($amt / $fuel_rate, 2) : 0.00;

                $ins_sql = "INSERT INTO tbl_meter_reading_card_sales 
                            (meter_reading_id, sale_date, shift_id, staff_id, card_machine_id, item_id, 
                             quantity, rate, amount, batch_no, service_charges, net_amount, nozzle_id, no_of_cards)
                            VALUES 
                            (0, '$sale_date', '$shift_id', 0, '$m_id', '$item_id', 
                             '$qty', '$fuel_rate', '$amt', '$batch_no', '$schg', '$net', '$noz_id', '$cards')";
                if (!mysqli_query($connection, $ins_sql)) {
                    throw new Exception("Error saving card transaction: " . mysqli_error($connection));
                }

                // Add dispensed petrol quantity to the nozzle's running meter reading in tbl_nozzles
                if ($noz_id > 0 && $qty > 0) {
                    $upd_noz = "UPDATE tbl_nozzles 
                                SET start_reading = start_reading + $qty 
                                WHERE id = '$noz_id'";
                    if (!mysqli_query($connection, $upd_noz)) {
                        throw new Exception("Error updating nozzle meter reading: " . mysqli_error($connection));
                    }

                    // Synchronize daily nozzle snapshot
                    sync_nozzle_daily_card_sale_delta($connection, $sale_date, $shift_id, $noz_id, $qty);
                }
            }
            mysqli_commit($connection);
            header('Location: card-sales-list.php?msg=added');
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
    <title>PPMS - Add Card Sale Reading</title>
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
            <h4><i class="fas fa-plus-circle mr-2 text-warning"></i> Add Card Sale Reading</h4>
            <small class="text-white-50">Record bank POS card terminal sales, fee deductions, and net bank deposits</small>
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

    <form method="POST" id="cardSaleForm">
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
                    <i class="fas fa-plus mr-1"></i> Add Card Sale Row
                </button>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm text-center mb-0" id="cardSalesTable" style="font-size: 13px;">
                        <thead>
                            <tr style="background: var(--primary-color); color: #fff;">
                                <th style="width: 25%;">Nozzle <span class="text-danger">*</span></th>
                                <th style="width: 25%;">Card Machine <span class="text-danger">*</span></th>
                                <th style="width: 20%;">Batch No</th>
                                <th style="width: 12%;">No of Cards</th>
                                <th style="width: 18%;">Amount (Rs.) <span class="text-danger">*</span></th>
                                <th style="width: 50px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cardSalesBody">
                            <!-- Rows injected via JS -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <button type="button" class="btn btn-info btn-sm font-weight-bold" onclick="addCardRow()">
                        <i class="fas fa-plus mr-1"></i> Add Card Sale Row
                    </button>
                </div>

                <!-- Bottom Summary & Action -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div class="d-flex align-items-center">
                        <div class="mr-4">
                            <span class="text-muted small d-block font-weight-bold">TOTAL CARDS:</span>
                            <span class="text-dark font-weight-bold" id="lblTotalCards">0 Cards</span>
                        </div>
                        <div class="mr-4">
                            <span class="text-muted small d-block font-weight-bold">TOTAL AMOUNT:</span>
                            <span class="text-primary font-weight-bold" style="font-size:16px;" id="lblTotalGross">Rs. 0.00</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success font-weight-bold px-4">
                        <i class="fas fa-save mr-1"></i> Save Card Sales
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
var nozzlesData      = <?php echo json_encode($nozzles); ?>;
var cardMachinesData = <?php echo json_encode($card_machines); ?>;
var cardRowIdx = 0;

$(document).ready(function() {
    addCardRow(); // Start with 1 row by default
});

function addCardRow() {
    var rowId = cardRowIdx++;

    var nozzleOptions = '<option value="">-- Select Nozzle --</option>';
    for (var j = 0; j < nozzlesData.length; j++) {
        var nz = nozzlesData[j];
        nozzleOptions += '<option value="' + nz.id + '">' + nz.name + '</option>';
    }

    var machineOptions = '<option value="">-- Select Machine --</option>';
    for (var i = 0; i < cardMachinesData.length; i++) {
        var cm = cardMachinesData[i];
        machineOptions += '<option value="' + cm.id + '">' + cm.name + '</option>';
    }

    var rowHtml = '<tr id="card_row_' + rowId + '">' +
        '<td>' +
            '<select name="card_nozzle_id[]" class="form-control form-control-sm" required>' +
                nozzleOptions +
            '</select>' +
        '</td>' +
        '<td>' +
            '<select name="card_machine_id[]" class="form-control form-control-sm" required>' +
                machineOptions +
            '</select>' +
        '</td>' +
        '<td>' +
            '<input type="text" name="card_batch_no[]" class="form-control form-control-sm" placeholder="Batch No">' +
        '</td>' +
        '<td>' +
            '<input type="number" min="1" name="card_no_of_cards[]" class="form-control form-control-sm text-center" value="1" oninput="calculateCardTotal()">' +
        '</td>' +
        '<td>' +
            '<input type="number" step="0.01" min="0" name="card_amount[]" class="form-control form-control-sm card-amount-field font-weight-bold text-primary" value="0" oninput="calculateCardTotal()" required>' +
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
    var totCards = 0, totAmount = 0;
    $('#cardSalesBody tr').each(function() {
        totCards  += parseInt($(this).find('input[name="card_no_of_cards[]"]').val()) || 0;
        totAmount += parseFloat($(this).find('.card-amount-field').val()) || 0;
    });

    $('#lblTotalCards').text(totCards + ' Cards');
    $('#lblTotalGross').text('Rs. ' + totAmount.toFixed(2));
}
</script>
</body>
</html>
