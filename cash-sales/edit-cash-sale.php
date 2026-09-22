<?php
require_once '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/cash_helper.php';

init_cash_sales_schema($connection);
check_access('cash_sales', 'edit');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: cash-sales-list.php');
    exit;
}

// Fetch existing record
$q_rec = mysqli_query($connection, "SELECT cs.*, n.name AS nozzle_name, i.name AS item_name 
                                    FROM tbl_meter_reading_cash_sales cs
                                    LEFT JOIN tbl_nozzles n ON cs.nozzle_id = n.id
                                    LEFT JOIN tbl_items i ON cs.item_id = i.id
                                    WHERE cs.id = '$id' AND cs.deleted_at IS NULL 
                                    LIMIT 1");
if (!$q_rec || mysqli_num_rows($q_rec) == 0) {
    header('Location: cash-sales-list.php');
    exit;
}
$record = mysqli_fetch_assoc($q_rec);

// Fetch active nozzles with items
$nozzles = get_active_nozzles_with_items($connection);
$shifts = get_active_shifts($connection);

$error_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sale_date = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? $record['sale_date']);
    $shift_id  = intval($_POST['shift_id'] ?? $record['shift_id']);
    $nozzle_id = intval($_POST['nozzle_id'] ?? $record['nozzle_id']);
    $item_id   = intval($_POST['item_id'] ?? $record['item_id']);
    $rate      = floatval($_POST['rate'] ?? 0);
    $amount    = floatval($_POST['amount'] ?? 0);
    $quantity  = floatval($_POST['quantity'] ?? 0);
    $notes     = mysqli_real_escape_string($connection, trim($_POST['notes'] ?? ''));

    if (empty($shift_id)) {
        $error_msg = 'Please select a Shift.';
    } elseif ($nozzle_id <= 0) {
        $error_msg = 'Please select a Nozzle.';
    } else {
        // Resolve item_id from nozzle if missing
        if ($item_id <= 0) {
            foreach ($nozzles as $noz) {
                if ($noz['id'] == $nozzle_id) {
                    $item_id = intval($noz['item_id']);
                    break;
                }
            }
        }

        // Recalculate if needed
        if ($quantity <= 0 && $rate > 0 && $amount > 0) {
            $quantity = round($amount / $rate, 2);
        } elseif ($amount <= 0 && $rate > 0 && $quantity > 0) {
            $amount = round($quantity * $rate, 2);
        }

        $sql_upd = "UPDATE tbl_meter_reading_cash_sales 
                    SET sale_date = '$sale_date',
                        shift_id  = '$shift_id',
                        nozzle_id = '$nozzle_id',
                        item_id   = '$item_id',
                        rate      = '$rate',
                        amount    = '$amount',
                        quantity  = '$quantity',
                        notes     = '$notes',
                        updated_at = NOW()
                    WHERE id = '$id'";

        if (mysqli_query($connection, $sql_upd)) {
            $_SESSION['flash_success'] = 'Cash sale record updated successfully.';
            header('Location: cash-sales-list.php');
            exit;
        } else {
            $error_msg = 'Error updating record: ' . mysqli_error($connection);
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
    <title>PPMS - Edit Cash Sale Reading</title>
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
            overflow:hidden;
        }
        .form-card-header {
            background: var(--primary-gradient);
            color:#fff; padding:12px 20px;
            font-weight:600; font-size:14px;
        }
        .fuel-badge {
            font-size:13px; font-weight:600; padding:7px 12px; border-radius:5px;
            background:#e9f0fb; color:var(--primary-color); border:1px solid #c8dcf7;
            display:inline-block;
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container mt-4 px-3 mb-5" style="max-width: 800px;">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-edit mr-2 text-warning"></i> Edit Cash Sale Reading</h4>
            <small class="text-white-50">Modify nozzle cash sale transaction details</small>
        </div>
        <a href="cash-sales-list.php" class="btn btn-outline-light font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Back to List
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
            <i class="fas fa-receipt mr-2"></i> Transaction Details (#<?php echo $record['id']; ?>)
        </div>
        <form method="POST" class="p-4" id="editCashSaleForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark"><i class="fas fa-calendar-day mr-1 text-primary"></i> Sale Date <span class="text-danger">*</span></label>
                    <input type="date" name="sale_date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($_POST['sale_date'] ?? $record['sale_date']); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark"><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" class="form-control font-weight-bold" required>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo $sh['id']; ?>" <?php echo ((isset($_POST['shift_id']) ? $_POST['shift_id'] : $record['shift_id']) == $sh['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sh['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark"><i class="fas fa-gas-pump mr-1 text-primary"></i> Nozzle <span class="text-danger">*</span></label>
                    <select name="nozzle_id" id="nozzle_select" class="form-control font-weight-bold" onchange="onNozzleChange(this)" required>
                        <option value="">-- Select Nozzle --</option>
                        <?php foreach ($nozzles as $noz): ?>
                            <option value="<?php echo $noz['id']; ?>" 
                                    data-item-id="<?php echo $noz['item_id']; ?>" 
                                    data-item-name="<?php echo htmlspecialchars($noz['item_name']); ?>" 
                                    data-rate="<?php echo $noz['cash_rate']; ?>"
                                    <?php echo ((isset($_POST['nozzle_id']) ? $_POST['nozzle_id'] : $record['nozzle_id']) == $noz['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($noz['name'] . ' (' . $noz['item_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="font-weight-bold text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Fuel Type</label>
                    <div>
                        <span class="fuel-badge" id="fuel_name_badge"><?php echo htmlspecialchars($record['item_name'] ?? 'Fuel'); ?></span>
                        <input type="hidden" name="item_id" id="item_id_input" value="<?php echo htmlspecialchars($_POST['item_id'] ?? $record['item_id']); ?>">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="font-weight-bold text-dark"><i class="fas fa-tag mr-1 text-primary"></i> Rate (Rs./Ltr) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" name="rate" id="rate_input" class="form-control text-right font-weight-bold" value="<?php echo htmlspecialchars($_POST['rate'] ?? $record['rate']); ?>" oninput="onRateInput()" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="font-weight-bold text-primary"><i class="fas fa-money-bill-wave mr-1"></i> Cash Amount (Rs.) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" name="amount" id="amount_input" class="form-control text-right font-weight-bold text-primary" value="<?php echo htmlspecialchars($_POST['amount'] ?? $record['amount']); ?>" oninput="onAmountInput()" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="font-weight-bold text-success"><i class="fas fa-tint mr-1"></i> Litres Dispensed</label>
                    <input type="number" step="0.01" min="0" name="quantity" id="quantity_input" class="form-control text-right font-weight-bold text-success" value="<?php echo htmlspecialchars($_POST['quantity'] ?? $record['quantity']); ?>" oninput="onQuantityInput()">
                </div>
            </div>

            <div class="mb-4">
                <label class="font-weight-bold text-dark"><i class="fas fa-comment mr-1 text-primary"></i> Notes / Remarks</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional notes" value="<?php echo htmlspecialchars($_POST['notes'] ?? $record['notes']); ?>">
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                <a href="cash-sales-list.php" class="btn btn-secondary font-weight-bold px-3">
                    <i class="fas fa-times mr-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success font-weight-bold px-4 py-2 shadow-sm">
                    <i class="fas fa-save mr-1"></i> Update Cash Sale
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
<script>
function onNozzleChange(selectElem) {
    const opt = selectElem.options[selectElem.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('fuel_name_badge').textContent = opt.getAttribute('data-item-name') || 'Fuel';
        document.getElementById('item_id_input').value = opt.getAttribute('data-item-id') || '0';
        document.getElementById('rate_input').value = parseFloat(opt.getAttribute('data-rate') || 0).toFixed(2);
        onRateInput();
    }
}

function onAmountInput() {
    const amt = parseFloat(document.getElementById('amount_input').value) || 0;
    const rate = parseFloat(document.getElementById('rate_input').value) || 0;
    if (rate > 0 && amt > 0) {
        document.getElementById('quantity_input').value = (amt / rate).toFixed(2);
    }
}

function onQuantityInput() {
    const qty = parseFloat(document.getElementById('quantity_input').value) || 0;
    const rate = parseFloat(document.getElementById('rate_input').value) || 0;
    if (rate > 0 && qty > 0) {
        document.getElementById('amount_input').value = (qty * rate).toFixed(2);
    }
}

function onRateInput() {
    const amt = parseFloat(document.getElementById('amount_input').value) || 0;
    const qty = parseFloat(document.getElementById('quantity_input').value) || 0;
    if (amt > 0) {
        onAmountInput();
    } else if (qty > 0) {
        onQuantityInput();
    }
}
</script>
</body>
</html>
