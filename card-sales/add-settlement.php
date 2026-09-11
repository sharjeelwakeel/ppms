<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('card_sales', 'add');

// Fetch active card machines
$card_machines = [];
$q_cm = mysqli_query($connection, "SELECT id, name, charges_percentage FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY name ASC");
if ($q_cm) {
    while ($r = mysqli_fetch_assoc($q_cm)) {
        $card_machines[] = $r;
    }
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_machine_id = intval($_POST['card_machine_id'] ?? 0);
    $settlement_date = mysqli_real_escape_string($connection, trim($_POST['settlement_date'] ?? date('Y-m-d')));
    $batch_no        = mysqli_real_escape_string($connection, trim($_POST['batch_no'] ?? ''));
    $no_of_cards     = intval($_POST['no_of_cards'] ?? 1);
    $amount          = floatval($_POST['amount'] ?? 0);
    $notes           = mysqli_real_escape_string($connection, trim($_POST['notes'] ?? ''));

    if (empty($settlement_date)) {
        $error_msg = 'Please select a valid Settlement Date.';
    } elseif ($card_machine_id <= 0) {
        $error_msg = 'Please select a Card Machine.';
    } elseif (empty($batch_no)) {
        $error_msg = 'Please enter the POS Batch Number.';
    } elseif ($no_of_cards < 1) {
        $error_msg = 'Number of cards must be at least 1.';
    } elseif ($amount <= 0) {
        $error_msg = 'Please enter a valid Settlement Amount greater than 0.';
    } else {
        // Fetch machine fee %
        $charges_pct = 0.0000;
        foreach ($card_machines as $cm) {
            if ($cm['id'] == $card_machine_id) {
                $charges_pct = floatval($cm['charges_percentage'] ?? 0);
                break;
            }
        }

        $service_charges = round($amount * ($charges_pct / 100), 2);
        $net_amount      = round($amount - $service_charges, 2);

        $sql = "INSERT INTO tbl_card_sale_settlements 
                (card_machine_id, settlement_date, batch_no, no_of_cards, amount, charges_percentage, service_charges, net_amount, notes)
                VALUES 
                ('$card_machine_id', '$settlement_date', '$batch_no', '$no_of_cards', '$amount', '$charges_pct', '$service_charges', '$net_amount', '$notes')";

        if (mysqli_query($connection, $sql)) {
            header('Location: settlement-list.php?msg=added');
            exit;
        } else {
            $error_msg = 'Error saving settlement: ' . mysqli_error($connection);
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
    <title>PPMS - Add Card Sale Settlement</title>
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
        .calc-preview-card {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 15px; margin-top: 15px;
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container mt-4 mb-5" style="max-width: 850px;">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-plus mr-2 text-warning"></i> Add Card Sale Settlement</h4>
            <small class="text-white-50">Record bank POS terminal batch settlement and fee reconciliation</small>
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

    <div class="form-card">
        <div class="form-card-header">
            <i class="fas fa-file-invoice-dollar mr-2"></i> Settlement Information
        </div>
        <form method="POST" id="addSettlementForm" class="p-4" onsubmit="return validateSettlementForm()">
            <div class="row">
                <!-- Settlement Date (Default: Today) -->
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-calendar-alt mr-1 text-primary"></i> Settlement Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="settlement_date" id="settlement_date" class="form-control font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                    <small class="text-muted">Defaults to current date</small>
                </div>

                <!-- Card Machine -->
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-credit-card mr-1 text-primary"></i> Card Machine (Bank Terminal) <span class="text-danger">*</span>
                    </label>
                    <select name="card_machine_id" id="card_machine_id" class="form-control font-weight-bold" required onchange="calculateSettlement()">
                        <option value="" data-fee="0">-- Select Card Machine --</option>
                        <?php foreach ($card_machines as $cm): ?>
                            <option value="<?php echo $cm['id']; ?>" data-fee="<?php echo floatval($cm['charges_percentage']); ?>">
                                <?php echo htmlspecialchars($cm['name']); ?> (Fee: <?php echo number_format($cm['charges_percentage'], 4); ?>%)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Batch No -->
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-barcode mr-1 text-primary"></i> Batch No <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="batch_no" id="batch_no" class="form-control font-weight-bold text-monospace" placeholder="e.g. BATCH-00129" required>
                    <small class="text-muted">Enter batch slip reference number</small>
                </div>

                <!-- No of Cards -->
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-layer-group mr-1 text-primary"></i> No. of Cards (Swipes) <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="no_of_cards" id="no_of_cards" class="form-control font-weight-bold text-center" value="1" min="1" required>
                    <small class="text-muted">Total card transactions in this batch</small>
                </div>

                <!-- Settlement Amount -->
                <div class="col-md-12 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-money-bill-wave mr-1 text-primary"></i> Settlement Amount (Rs.) <span class="text-danger">*</span>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control form-control-lg font-weight-bold text-primary" placeholder="0.00" required oninput="calculateSettlement()">
                </div>

                <!-- Notes -->
                <div class="col-md-12 mb-3">
                    <label class="font-weight-bold text-dark">
                        <i class="fas fa-sticky-note mr-1 text-muted"></i> Remarks / Notes (Optional)
                    </label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional settlement notes..."></textarea>
                </div>
            </div>

            <!-- Calculation Preview -->
            <div class="calc-preview-card">
                <div class="row align-items-center text-center">
                    <div class="col-md-4 mb-2 mb-md-0">
                        <span class="text-muted small d-block font-weight-bold">COMMISSION FEE %:</span>
                        <span class="text-dark font-weight-bold" id="lblFeePct">0.0000%</span>
                    </div>
                    <div class="col-md-4 mb-2 mb-md-0">
                        <span class="text-muted small d-block font-weight-bold">ESTIMATED BANK CHARGES:</span>
                        <span class="text-danger font-weight-bold" id="lblCharges">Rs. 0.00</span>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted small d-block font-weight-bold">NET BANK RECEIVABLE:</span>
                        <span class="text-success font-weight-bold" style="font-size: 1.15rem;" id="lblNet">Rs. 0.00</span>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="settlement-list.php" class="btn btn-outline-secondary font-weight-bold">
                    <i class="fas fa-times mr-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary font-weight-bold px-4">
                    <i class="fas fa-save mr-1"></i> Save Settlement
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
function calculateSettlement() {
    var feePct = parseFloat($('#card_machine_id option:selected').attr('data-fee')) || 0;
    var amount = parseFloat($('#amount').val()) || 0;

    var charges = (amount * (feePct / 100));
    var net = amount - charges;

    $('#lblFeePct').text(feePct.toFixed(4) + '%');
    $('#lblCharges').text('-Rs. ' + charges.toFixed(2));
    $('#lblNet').text('Rs. ' + net.toFixed(2));
}

function validateSettlementForm() {
    var date = $('#settlement_date').val();
    var mach = $('#card_machine_id').val();
    var batch = ($('#batch_no').val() || '').trim();
    var cards = parseInt($('#no_of_cards').val()) || 0;
    var amt = parseFloat($('#amount').val()) || 0;

    if (!date) {
        alert('Please select Settlement Date.');
        $('#settlement_date').focus();
        return false;
    }
    if (!mach) {
        alert('Please select a Card Machine.');
        $('#card_machine_id').focus();
        return false;
    }
    if (!batch) {
        alert('Please enter the Batch Number.');
        $('#batch_no').focus();
        return false;
    }
    if (cards < 1) {
        alert('Number of cards must be at least 1.');
        $('#no_of_cards').focus();
        return false;
    }
    if (amt <= 0) {
        alert('Please enter a valid Settlement Amount greater than 0.');
        $('#amount').focus();
        return false;
    }
    return true;
}
</script>
</body>
</html>
