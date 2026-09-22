<?php
require_once '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/cash_helper.php';

init_cash_sales_schema($connection);
check_access('cash_sales', 'add');

// Fetch active nozzles with attached fuel items and rates
$nozzles = get_active_nozzles_with_items($connection);

// Fetch active shifts
$shifts = get_active_shifts($connection);

// Fetch fuel items for fallback
$fuel_items = get_fuel_items($connection);

$error_msg = '';
$success_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sale_date = mysqli_real_escape_string($connection, $_POST['sale_date'] ?? date('Y-m-d'));
    $shift_id  = intval($_POST['shift_id'] ?? 0);
    $user_id   = intval($_SESSION['loggedInUser'] ?? 0);

    $nozzle_ids = $_POST['cash_nozzle_id'] ?? [];
    $item_ids   = $_POST['cash_item_id'] ?? [];
    $rates      = $_POST['cash_rate'] ?? [];
    $amounts    = $_POST['cash_amount'] ?? [];
    $quantities = $_POST['cash_quantity'] ?? [];
    $notes_arr  = $_POST['cash_notes'] ?? [];

    if (empty($shift_id)) {
        $error_msg = 'Please select a Shift before saving.';
    } elseif (empty($nozzle_ids)) {
        $error_msg = 'Please add at least one cash sale row before saving.';
    } else {
        mysqli_begin_transaction($connection);
        try {
            $inserted_count = 0;
            for ($i = 0; $i < count($nozzle_ids); $i++) {
                $noz_id = intval($nozzle_ids[$i] ?? 0);
                $itm_id = intval($item_ids[$i] ?? 0);
                $rate   = floatval($rates[$i] ?? 0);
                $amt    = floatval($amounts[$i] ?? 0);
                $qty    = floatval($quantities[$i] ?? 0);
                $notes  = mysqli_real_escape_string($connection, trim($notes_arr[$i] ?? ''));

                if ($noz_id <= 0) {
                    continue;
                }

                // If item_id wasn't submitted directly, resolve from nozzle
                if ($itm_id <= 0) {
                    foreach ($nozzles as $noz) {
                        if ($noz['id'] == $noz_id) {
                            $itm_id = intval($noz['item_id']);
                            if ($rate <= 0) {
                                $rate = floatval($noz['cash_rate']);
                            }
                            break;
                        }
                    }
                }

                // Auto-calculate if either amt or qty is missing
                if ($qty <= 0 && $rate > 0 && $amt > 0) {
                    $qty = round($amt / $rate, 2);
                } elseif ($amt <= 0 && $rate > 0 && $qty > 0) {
                    $amt = round($qty * $rate, 2);
                }

                if ($amt <= 0 && $qty <= 0) {
                    continue; // Skip blank rows
                }

                $sql_ins = "INSERT INTO tbl_meter_reading_cash_sales 
                            (sale_date, shift_id, nozzle_id, item_id, rate, amount, quantity, notes, created_by, created_at)
                            VALUES ('$sale_date', '$shift_id', '$noz_id', '$itm_id', '$rate', '$amt', '$qty', '$notes', '$user_id', NOW())";

                if (!mysqli_query($connection, $sql_ins)) {
                    throw new Exception("Error inserting row " . ($i + 1) . ": " . mysqli_error($connection));
                }
                $inserted_count++;
            }

            if ($inserted_count === 0) {
                throw new Exception("No valid rows with positive cash amount or litres were provided.");
            }

            mysqli_commit($connection);
            $_SESSION['flash_success'] = "Successfully saved $inserted_count cash sale record(s).";
            header("Location: cash-sales-list.php?from_date=$sale_date&to_date=$sale_date&shift_id=$shift_id");
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Add Cash Sale Reading</title>
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
        .table thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600; text-align:center; vertical-align:middle;
        }
        .table tbody td { vertical-align:middle; }
        .fuel-badge {
            font-size:12px; font-weight:600; padding:6px 10px; border-radius:5px;
            background:#e9f0fb; color:var(--primary-color); border:1px solid #c8dcf7;
            display:inline-block; width:100%; text-align:center;
        }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-money-bill-wave mr-2 text-warning"></i> Add Cash Sale Reading</h4>
            <small class="text-white-50">Record shift cash sales per nozzle with automatic fuel type detection and amount-to-litre calculation</small>
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

    <form method="POST" id="cashSaleForm">
        <!-- Date & Shift Selection Card -->
        <div class="form-card mb-4">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-calendar-alt mr-2"></i> Shift &amp; Date Information</span>
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
                                <option value="<?php echo $sh['id']; ?>" <?php echo (isset($_POST['shift_id']) && $_POST['shift_id'] == $sh['id']) ? 'selected' : (count($shifts) === 1 ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($sh['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-sm-12 mt-3 mt-md-0 text-md-right">
                        <span class="badge badge-info px-3 py-2" style="font-size:13px;">
                            <i class="fas fa-info-circle mr-1"></i> Selecting a Nozzle automatically detects Fuel Type &amp; Cash Rate
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Sale Entries Spreadsheet Card -->
        <div class="form-card">
            <div class="form-card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-gas-pump mr-2"></i> Cash Sale Entries (Multi-Row Entry)</span>
                <button type="button" class="btn btn-sm btn-light font-weight-bold text-primary" onclick="addCashRow()">
                    <i class="fas fa-plus mr-1"></i> Add Another Row
                </button>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0" id="cashSalesTable" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th style="width: 22%;">Nozzle <span class="text-danger">*</span></th>
                                <th style="width: 16%;">Fuel Type</th>
                                <th style="width: 14%;">Rate (Rs./Ltr) <span class="text-danger">*</span></th>
                                <th style="width: 16%;">Cash Amount (Rs.) <span class="text-danger">*</span></th>
                                <th style="width: 14%;">Litres Sold</th>
                                <th style="width: 14%;">Notes / Remarks</th>
                                <th style="width: 4%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cashSalesBody">
                            <!-- Injected by JavaScript -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <button type="button" class="btn btn-primary btn-sm font-weight-bold" onclick="addCashRow()">
                        <i class="fas fa-plus mr-1"></i> Add Another Row
                    </button>
                </div>

                <!-- Bottom Summary & Action Bar -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div class="d-flex align-items-center flex-wrap">
                        <div class="mr-4 my-1">
                            <span class="text-muted small d-block font-weight-bold">TOTAL ENTRIES:</span>
                            <span class="text-dark font-weight-bold" id="lblTotalEntries">0 Rows</span>
                        </div>
                        <div class="mr-4 my-1">
                            <span class="text-muted small d-block font-weight-bold">TOTAL LITRES SOLD:</span>
                            <span class="text-success font-weight-bold" style="font-size:16px;" id="lblTotalLitres">0.00 Ltr</span>
                        </div>
                        <div class="mr-4 my-1">
                            <span class="text-muted small d-block font-weight-bold">TOTAL CASH RECEIVED:</span>
                            <span class="text-primary font-weight-bold" style="font-size:18px;" id="lblTotalAmount">Rs. 0.00</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success font-weight-bold px-4 py-2 my-1 shadow-sm">
                        <i class="fas fa-save mr-1"></i> Save Cash Sales
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Nozzles data array passed from PHP
const availableNozzles = <?php echo json_encode($nozzles); ?>;

function addCashRow(defaultNozzleId = '') {
    const tbody = document.getElementById('cashSalesBody');
    const rowIndex = tbody.children.length;

    let nozzleOptions = '<option value="">-- Select Nozzle --</option>';
    availableNozzles.forEach(noz => {
        const isSel = (defaultNozzleId && defaultNozzleId == noz.id) || (availableNozzles.length === 1 && !defaultNozzleId) ? 'selected' : '';
        nozzleOptions += `<option value="${noz.id}" data-item-id="${noz.item_id}" data-item-name="${noz.item_name}" data-rate="${noz.cash_rate}" ${isSel}>${noz.name} (${noz.item_name})</option>`;
    });

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="cash_nozzle_id[]" class="form-control form-control-sm font-weight-bold nozzle-select" onchange="onNozzleChange(this)" required>
                ${nozzleOptions}
            </select>
        </td>
        <td>
            <span class="fuel-badge fuel-name-display text-muted">Select Nozzle</span>
            <input type="hidden" name="cash_item_id[]" class="item-id-input" value="">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="cash_rate[]" class="form-control form-control-sm rate-input text-right font-weight-bold" placeholder="0.00" oninput="onRateInput(this)" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="cash_amount[]" class="form-control form-control-sm amount-input text-right font-weight-bold text-primary" placeholder="0.00" oninput="onAmountInput(this)" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="cash_quantity[]" class="form-control form-control-sm quantity-input text-right font-weight-bold text-success" placeholder="0.00" oninput="onQuantityInput(this)">
        </td>
        <td>
            <input type="text" name="cash_notes[]" class="form-control form-control-sm" placeholder="Optional notes">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" title="Remove Row" onclick="removeCashRow(this)">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);

    // If nozzle selected automatically (e.g. single nozzle), trigger change
    const select = tr.querySelector('.nozzle-select');
    if (select.value) {
        onNozzleChange(select);
    }

    updateSummaryTotals();
}

function onNozzleChange(selectElem) {
    const tr = selectElem.closest('tr');
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const fuelBadge = tr.querySelector('.fuel-name-display');
    const itemIdInput = tr.querySelector('.item-id-input');
    const rateInput = tr.querySelector('.rate-input');

    if (selectedOption && selectedOption.value) {
        const itemName = selectedOption.getAttribute('data-item-name') || 'Fuel';
        const itemId   = selectedOption.getAttribute('data-item-id') || '0';
        const rate     = parseFloat(selectedOption.getAttribute('data-rate') || 0);

        fuelBadge.textContent = itemName;
        fuelBadge.classList.remove('text-muted');
        itemIdInput.value = itemId;
        rateInput.value = rate.toFixed(2);

        // Recalculate based on existing amount or quantity
        const amountInput = tr.querySelector('.amount-input');
        const quantityInput = tr.querySelector('.quantity-input');
        if (parseFloat(amountInput.value) > 0) {
            onAmountInput(amountInput);
        } else if (parseFloat(quantityInput.value) > 0) {
            onQuantityInput(quantityInput);
        }
    } else {
        fuelBadge.textContent = 'Select Nozzle';
        fuelBadge.classList.add('text-muted');
        itemIdInput.value = '';
        rateInput.value = '';
    }
    updateSummaryTotals();
}

function onAmountInput(amountElem) {
    const tr = amountElem.closest('tr');
    const rateInput = tr.querySelector('.rate-input');
    const quantityInput = tr.querySelector('.quantity-input');

    const amount = parseFloat(amountElem.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;

    if (rate > 0 && amount > 0) {
        const litres = amount / rate;
        quantityInput.value = litres.toFixed(2);
    } else if (amount === 0) {
        quantityInput.value = '';
    }
    updateSummaryTotals();
}

function onQuantityInput(quantityElem) {
    const tr = quantityElem.closest('tr');
    const rateInput = tr.querySelector('.rate-input');
    const amountInput = tr.querySelector('.amount-input');

    const quantity = parseFloat(quantityElem.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;

    if (rate > 0 && quantity > 0) {
        const amount = quantity * rate;
        amountInput.value = amount.toFixed(2);
    } else if (quantity === 0) {
        amountInput.value = '';
    }
    updateSummaryTotals();
}

function onRateInput(rateElem) {
    const tr = rateElem.closest('tr');
    const amountInput = tr.querySelector('.amount-input');
    const quantityInput = tr.querySelector('.quantity-input');

    // Whichever has value, re-calculate the other
    if (parseFloat(amountInput.value) > 0) {
        onAmountInput(amountInput);
    } else if (parseFloat(quantityInput.value) > 0) {
        onQuantityInput(quantityInput);
    }
}

function removeCashRow(btnElem) {
    const tbody = document.getElementById('cashSalesBody');
    if (tbody.children.length <= 1) {
        Swal.fire({
            icon: 'info',
            title: 'Minimum Row Required',
            text: 'At least one cash sale row must be present.',
            confirmButtonColor: '#04204e'
        });
        return;
    }
    const tr = btnElem.closest('tr');
    tr.remove();
    updateSummaryTotals();
}

function updateSummaryTotals() {
    let totalEntries = 0;
    let totalLitres = 0.00;
    let totalCash = 0.00;

    const rows = document.querySelectorAll('#cashSalesBody tr');
    rows.forEach(tr => {
        const amtVal = parseFloat(tr.querySelector('.amount-input')?.value || 0);
        const qtyVal = parseFloat(tr.querySelector('.quantity-input')?.value || 0);
        if (amtVal > 0 || qtyVal > 0) {
            totalEntries++;
        }
        totalCash += amtVal;
        totalLitres += qtyVal;
    });

    document.getElementById('lblTotalEntries').textContent = totalEntries + ' ' + (totalEntries === 1 ? 'Row' : 'Rows');
    document.getElementById('lblTotalLitres').textContent = totalLitres.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Ltr';
    document.getElementById('lblTotalAmount').textContent = 'Rs. ' + totalCash.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Initialise with 1 empty row on page load
document.addEventListener('DOMContentLoaded', function() {
    addCashRow();
});
</script>
</body>
</html>
