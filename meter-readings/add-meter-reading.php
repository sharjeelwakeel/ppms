<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding meter readings
check_access('meter_readings', 'add');

$message = '';

if (isset($_POST['submit'])) {
    $date         = mysqli_real_escape_string($connection, $_POST['date']);
    $shift_id     = mysqli_real_escape_string($connection, $_POST['shift_id']);
    $payment_type = 'Cash';
    $remarks      = mysqli_real_escape_string($connection, $_POST['remarks'] ?? '');
    $grand_total  = 0;

    // Validate that current_reading >= last_reading for all nozzle rows
    $validation_failed = false;
    if (isset($_POST['nozzle_id']) && is_array($_POST['nozzle_id'])) {
        foreach ($_POST['nozzle_id'] as $idx => $n_id) {
            $last_rdg = floatval($_POST['last_reading'][$idx] ?? 0);
            $curr_rdg = floatval($_POST['current_reading'][$idx] ?? 0);
            if ($curr_rdg < $last_rdg) {
                $validation_failed = true;
                break;
            }
        }
    }

    if ($validation_failed) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Validation Error: Current Reading must be greater than or equal to Previous Reading.</div>';
    } else {
        // Insert header record
        $header_sql = "INSERT INTO tbl_meter_readings (date, shift_id, payment_type, grand_total, remarks)
                       VALUES ('$date','$shift_id', '$payment_type', 0, '$remarks')";
        if (mysqli_query($connection, $header_sql)) {
            $reading_id = mysqli_insert_id($connection);

            // Loop through nozzle rows
            if (isset($_POST['nozzle_id']) && is_array($_POST['nozzle_id'])) {
                foreach ($_POST['nozzle_id'] as $idx => $nozzle_id) {
                    $nozzle_id       = mysqli_real_escape_string($connection, $nozzle_id);
                    $row_staff_id    = mysqli_real_escape_string($connection, $_POST['row_staff_id'][$idx] ?? 0);
                    $item_type       = mysqli_real_escape_string($connection, $_POST['item_type'][$idx] ?? '');
                    $price           = floatval($_POST['price'][$idx] ?? 0);
                    $last_reading    = floatval($_POST['last_reading'][$idx] ?? 0);
                    $current_reading = floatval($_POST['current_reading'][$idx] ?? 0);
                    $test_reading    = floatval($_POST['test_reading'][$idx] ?? 0);
                    $row_payment     = 'Cash';

                    $sale_reading = $current_reading - $last_reading;
                    if ($sale_reading < 0) $sale_reading = 0;

                    $net_sale = $sale_reading - $test_reading;
                    if ($net_sale < 0) $net_sale = 0;

                    $amount = $net_sale * $price;
                    $grand_total += $amount;

                    $detail_sql = "INSERT INTO tbl_meter_reading_details
                        (meter_reading_id, nozzle_id, staff_id, item_type, price,
                         last_reading, current_reading, sale_reading, test_reading, net_sale, amount, payment_type)
                        VALUES ('$reading_id','$nozzle_id','$row_staff_id','$item_type','$price',
                                '$last_reading','$current_reading','$sale_reading','$test_reading','$net_sale','$amount','$row_payment')";
                    mysqli_query($connection, $detail_sql);

                    // Update start_reading in tbl_nozzles with the new current_reading (stores current meter running position)
                    $update_nozzle_sql = "UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id'";
                    mysqli_query($connection, $update_nozzle_sql);
                }
            }

            // Update grand total in header

            // Update grand total in header
            mysqli_query($connection, "UPDATE tbl_meter_readings SET grand_total='$grand_total' WHERE id='$reading_id'");
            header('Location: view-meter-reading.php?id=' . $reading_id);
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch all active nozzles with item info, price, credit rate, and last reading (from tbl_nozzles.start_reading)
$nozzles_sql = "SELECT n.id, n.name AS nozzle_name, n.start_reading, i.name AS item_name, i.cash_rate AS price, i.credit_rate, t.tank_name
                FROM tbl_nozzles n
                LEFT JOIN tbl_items i ON n.item_id = i.id
                LEFT JOIN tbl_tanks t ON n.tank_id = t.id
                WHERE n.status = 'Active' AND (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
                ORDER BY n.id ASC";
$nozzles_result = mysqli_query($connection, $nozzles_sql);
$nozzles = [];
while ($row = mysqli_fetch_assoc($nozzles_result)) { $nozzles[] = $row; }

// Fetch active shifts
$shifts_sql    = "SELECT id, name FROM tbl_shifts WHERE status='Active' ORDER BY name ASC";
$shifts_result = mysqli_query($connection, $shifts_sql);

// Fetch staff for per-row selection
$staff_sql    = "SELECT id, CONCAT(first_name,' ',last_name) AS full_name FROM tbl_staff ORDER BY first_name ASC";
$staff_result = mysqli_query($connection, $staff_sql);
$staff_list   = [];
while ($s = mysqli_fetch_assoc($staff_result)) { $staff_list[] = $s; }

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
    <title>PPMS - Add Meter Reading</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }

        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 18px 28px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.3rem; letter-spacing: .5px; }

        .header-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 22px 24px 10px;
            margin-bottom: 22px;
        }
        .header-card label { font-weight: 600; font-size: 13px; color: #444; }
        .header-card .form-control { border-radius: 7px; font-size: 13px; }

        .table-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 20px 24px;
            margin-bottom: 22px;
        }
        .table-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #04204e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
        }

        .reading-table thead th {
            background: #04204e;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: none;
            vertical-align: middle;
            text-align: center;
        }
        .reading-table tbody td {
            vertical-align: middle;
            font-size: 13px;
            padding: 8px 10px;
        }
        .reading-table .form-control {
            border-radius: 6px;
            font-size: 13px;
            padding: 4px 8px;
            height: auto;
        }

        .nozzle-badge {
            background: #eef2fa;
            color: #04204e;
            font-weight: 700;
            font-size: 13px;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
        }
        .badge-petrol {
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 5px;
        }
        .badge-diesel {
            background: #fef3c7;
            color: #b45309;
            font-weight: 600;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 5px;
        }
        .badge-item {
            background: #f1f5f9;
            color: #475569;
            font-weight: 600;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 5px;
        }

        .rate-field  { text-align: right; font-weight: 600; color: #222; }
        .calc-field  { text-align: right; font-weight: 600; color: #04204e; background: #f8fafc; }
        .amount-field{ text-align: right; font-weight: 700; color: #0b8043; background: #f0fdf4; }

        .grand-total-row td {
            background: #04204e !important;
            color: #fff !important;
            font-weight: 700;
            font-size: 14px;
        }
        .grand-total-label { text-align: right; letter-spacing: 0.5px; }
        .grand-total-value { text-align: right; font-size: 16px; color: #4ade80 !important; }

        .btn-save {
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            padding: 10px 28px;
            border-radius: 8px;
            border: none;
            box-shadow: 0 4px 14px rgba(4,32,78,0.25);
            transition: all 0.2s;
        }
        .btn-save:hover { opacity: 0.95; color: #fff; transform: translateY(-1px); }

        .reading-invalid {
            border-color: #dc3545 !important;
            background-color: #fff5f5 !important;
        }
        .reading-error-text {
            color: #dc3545;
            font-size: 11px;
            font-weight: bold;
            margin-top: 2px;
            display: block;
        }
    </style>
</head>
<body>
<?php include('../include/navbar.php');?>
<main class="main">
    <div class="container-fluid pt-4 pb-5 px-4">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-plus-circle mr-2"></i>Add Meter Reading</h4>
                <small class="text-white-50">Record daily shift closing meter readings and calculate sales</small>
            </div>
            <a href="meter-reading-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to List
            </a>
        </div>

        <?php echo $message; ?>

        <form action="add-meter-reading.php" method="POST" id="meterForm">

            <!-- Header Card (Date & Shift) -->
            <div class="header-card">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                            <select name="shift_id" class="form-control" required>
                                <option value="">-- Select Shift --</option>
                                <?php
                                if ($shifts_result) {
                                    mysqli_data_seek($shifts_result, 0);
                                    while ($s = mysqli_fetch_assoc($shifts_result)) {
                                        echo '<option value="' . $s['id'] . '">' . htmlspecialchars($s['name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                        <div class="form-group w-100">
                            <label>&nbsp;</label>
                            <div class="alert alert-info mb-0 py-2 px-3" style="font-size:12px; border-radius:8px;">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Sale Reading</strong> = Current − Last (Current must be ≥ Last)<br>
                                <strong>Net Sale</strong> = Sale − Test<br>
                                <strong>Amount</strong> = Net Sale × Rate
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nozzle Readings Table -->
            <div class="table-card">
                <div class="table-card-title">
                    <i class="fas fa-gas-pump mr-2"></i>Nozzle Readings
                    <span class="badge badge-light ml-2" style="color: var(--primary-color);"><?php echo count($nozzles); ?> Nozzles</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered reading-table mb-0" id="readingTable">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Nozzle</th>
                                <th>Item (Type)</th>
                                <th>Sales Executive</th>
                                <th style="text-align:right;">Rate</th>
                                <th>Last Reading</th>
                                <th>Current Reading</th>
                                <th>
                                    Sale Reading
                                    <br><small style="color:#ffc107;font-weight:400;">(Auto)</small>
                                </th>
                                <th>
                                    Test Reading
                                    <br><small style="color:#ffc107;font-weight:400;">(Deduct)</small>
                                </th>
                                <th>
                                    Net Sale
                                    <br><small style="color:#ffc107;font-weight:400;">(Auto)</small>
                                </th>
                                <th>
                                    Amount
                                    <br><small style="color:#ffc107;font-weight:400;">(Auto)</small>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($nozzles as $i => $nozzle):
                            $itemName = $nozzle['item_name'] ?? 'N/A';
                            $itemLower = strtolower($itemName);
                            if (strpos($itemLower,'petrol') !== false) {
                                $itemClass = 'badge-petrol';
                            } elseif (strpos($itemLower,'diesel') !== false) {
                                $itemClass = 'badge-diesel';
                            } else {
                                $itemClass = 'badge-item';
                            }
                        ?>
                            <tr id="row_<?php echo $i; ?>">
                                <!-- # -->
                                <td style="text-align:center;font-weight:600;color:#666;"><?php echo $i+1; ?></td>

                                <!-- Nozzle -->
                                <td>
                                    <span class="nozzle-badge"><?php echo htmlspecialchars($nozzle['nozzle_name']); ?></span>
                                    <input type="hidden" name="nozzle_id[]"  value="<?php echo $nozzle['id']; ?>">
                                    <input type="hidden" name="item_type[]"  value="<?php echo htmlspecialchars($itemName); ?>">
                                    <input type="hidden" name="price[]"      id="price_<?php echo $i; ?>"
                                           value="<?php echo $nozzle['price']; ?>">
                                </td>

                                <!-- Item -->
                                <td>
                                    <span class="<?php echo $itemClass; ?>"><?php echo htmlspecialchars($itemName); ?></span>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($nozzle['tank_name'] ?? ''); ?></small>
                                </td>

                                <!-- Per-row Sales Executive -->
                                <td>
                                    <select name="row_staff_id[]" class="form-control" style="width:140px;font-size:12px;">
                                        <option value="0">-- Staff --</option>
                                        <?php foreach ($staff_list as $st): ?>
                                        <option value="<?php echo $st['id']; ?>">
                                            <?php echo htmlspecialchars($st['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <!-- Rate -->
                                <td class="rate-field">
                                    <?php echo number_format($nozzle['price'], 2); ?>
                                </td>

                                <!-- Last Reading (Read-only baseline) -->
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="last_reading[]"
                                           id="last_<?php echo $i; ?>"
                                           class="form-control last_reading"
                                           value="<?php echo htmlspecialchars($nozzle['start_reading']); ?>"
                                           readonly style="background-color: #e9ecef; cursor: not-allowed;"
                                           oninput="calculate(<?php echo $i; ?>)">
                                </td>

                                <!-- Current Reading -->
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="current_reading[]"
                                           id="curr_<?php echo $i; ?>"
                                           class="form-control current_reading"
                                           value="<?php echo htmlspecialchars($nozzle['start_reading']); ?>"
                                           oninput="calculate(<?php echo $i; ?>)">
                                    <span class="reading-error-text" id="err_curr_<?php echo $i; ?>" style="display:none;">Must be ≥ Last Reading</span>
                                </td>

                                <!-- Sale Reading (auto) -->
                                <td class="calc-field" id="sale_<?php echo $i; ?>">0.00</td>

                                <!-- Test Reading -->
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="test_reading[]"
                                           id="test_<?php echo $i; ?>"
                                           class="form-control test_reading"
                                           value="0"
                                           oninput="calculate(<?php echo $i; ?>)">
                                </td>

                                <!-- Net Sale (auto) -->
                                <td class="calc-field" id="netsale_<?php echo $i; ?>">0.00</td>

                                <!-- Amount (auto) -->
                                <td class="amount-field" id="amount_<?php echo $i; ?>">0.00</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="grand-total-row">
                                <td colspan="10" class="grand-total-label">
                                    <i class="fas fa-sigma mr-2"></i>GRAND TOTAL
                                </td>
                                <td class="grand-total-value" id="grand_total_display">0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Bottom Details (Remarks & Save) -->
            <div class="row align-items-center mb-4 mt-3">
                <div class="col-md-6">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold" style="font-size:13px; color:#444;"><i class="fas fa-comment-dots mr-1 text-primary"></i> Remarks</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Enter any remarks or notes here..." style="border-radius:7px; font-size:13px;">
                    </div>
                </div>
                <div class="col-md-6 text-right">
                    <a href="meter-reading-list.php" class="btn btn-secondary mr-2">
                        <i class="fas fa-times mr-1"></i> Cancel
                    </a>
                    <button type="submit" name="submit" id="btnSaveMeterReading" class="btn-save btn">
                        <i class="fas fa-save mr-1"></i> Save Reading
                    </button>
                </div>
            </div>

        </form>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
var totalRows = <?php echo count($nozzles); ?>;

$(document).ready(function() {
    for (var i = 0; i < totalRows; i++) {
        calculate(i);
    }
});

/**
 * Recalculate a single nozzle row & validate current_reading >= last_reading:
 *   Sale Reading = Current - Last  (Validation: Current >= Last)
 *   Net Sale     = Sale - Test      (min 0)
 *   Amount       = Net Sale × Price
 */
function calculate(idx) {
    var price       = parseFloat($('#price_' + idx).val()) || 0;
    var last        = parseFloat($('#last_'  + idx).val()) || 0;
    var curr        = parseFloat($('#curr_'  + idx).val()) || 0;
    var test        = parseFloat($('#test_'  + idx).val()) || 0;

    if (curr < last) {
        $('#curr_' + idx).addClass('reading-invalid');
        $('#err_curr_' + idx).show();
    } else {
        $('#curr_' + idx).removeClass('reading-invalid');
        $('#err_curr_' + idx).hide();
    }

    var saleReading = Math.max(curr - last, 0);
    var netSale     = Math.max(saleReading - test, 0);
    var amount      = netSale * price;

    $('#sale_'    + idx).text(saleReading.toFixed(2));
    $('#netsale_' + idx).text(netSale.toFixed(2));
    $('#amount_'  + idx).text(amount.toFixed(2));

    updateGrandTotal();
}

/** Sum all row amounts into the grand total footer cell */
function updateGrandTotal() {
    var grand = 0;
    for (var i = 0; i < totalRows; i++) {
        grand += parseFloat($('#amount_' + i).text()) || 0;
    }
    $('#grand_total_display').text(grand.toFixed(2));
}

// Form submit validation check
$('#meterForm').on('submit', function(e) {
    var invalid = false;
    var errorMsg = '';

    for (var i = 0; i < totalRows; i++) {
        var last = parseFloat($('#last_' + i).val()) || 0;
        var curr = parseFloat($('#curr_' + i).val()) || 0;
        if (curr < last) {
            invalid = true;
            $('#curr_' + i).addClass('reading-invalid');
            $('#err_curr_' + i).show();
            errorMsg = 'Validation Error: Current Reading must be greater than or equal to Previous Reading for all nozzles.';
        }
    }

    if (invalid) {
        e.preventDefault();
        alert(errorMsg);
        return false;
    }
});
</script>
</body>
</html>
