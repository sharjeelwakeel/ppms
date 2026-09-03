<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/nozzle_daily_sync.php';

// Enforce edit access check
check_access('meter_readings', 'edit');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: meter-reading-list.php');
    exit;
}
$id = intval($_GET['id']);


// Fetch header
$header_result = mysqli_query($connection, "SELECT * FROM tbl_meter_readings WHERE id = '$id' LIMIT 1");
if (!$header_result || !($header = mysqli_fetch_assoc($header_result))) {
    header('Location: meter-reading-list.php');
    exit;
}

// Fetch existing details mapped by nozzle_id
$details_query = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_details WHERE meter_reading_id = '$id'");
$existing_details = [];
if ($details_query) {
    while ($d = mysqli_fetch_assoc($details_query)) {
        $existing_details[$d['nozzle_id']] = $d;
    }
}

$message = '';

if (isset($_POST['submit'])) {
    $date     = mysqli_real_escape_string($connection, $_POST['date']);
    $shift_id = intval($_POST['shift_id']);
    $remarks  = mysqli_real_escape_string($connection, $_POST['remarks'] ?? ($_POST['notes'] ?? ''));

    // Validate mandatory fields
    if (empty($date) || empty($shift_id)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Please fill in Date and Shift.</div>';
    } else {
        // Update Header
        $update_header = "UPDATE tbl_meter_readings SET date='$date', shift_id='$shift_id', remarks='$remarks' WHERE id='$id'";
            if (mysqli_query($connection, $update_header)) {
                $grand_total = 0;

                // Update Nozzle Details
                if (isset($_POST['current_reading']) && is_array($_POST['current_reading'])) {
                    foreach ($_POST['current_reading'] as $nozzle_id => $current_reading) {
                        $nozzle_id       = intval($nozzle_id);
                        $current_reading = floatval($current_reading);
                        $last_reading    = floatval($_POST['last_reading'][$nozzle_id] ?? 0);
                        $test_reading    = floatval($_POST['test_reading'][$nozzle_id] ?? 0);
                        $sale_reading    = $current_reading - $last_reading;
                        $net_sale        = $sale_reading - $test_reading;
                        $price           = floatval($_POST['price'][$nozzle_id] ?? 0);
                        $amount          = $net_sale * $price;
                        $row_staff_id    = intval($_POST['row_staff_id'][$nozzle_id] ?? 0);
                        $row_payment     = mysqli_real_escape_string($connection, $_POST['payment_type'][$nozzle_id] ?? 'Cash');
                        $item_type       = mysqli_real_escape_string($connection, $_POST['item_type'][$nozzle_id] ?? '');

                        $grand_total += $amount;

                        if (isset($existing_details[$nozzle_id])) {
                            $det_id = $existing_details[$nozzle_id]['id'];
                            $update_detail = "UPDATE tbl_meter_reading_details SET
                                staff_id='$row_staff_id', item_type='$item_type', price='$price',
                                last_reading='$last_reading', current_reading='$current_reading',
                                sale_reading='$sale_reading', test_reading='$test_reading',
                                net_sale='$net_sale', amount='$amount', payment_type='$row_payment'
                                WHERE id='$det_id'";
                            mysqli_query($connection, $update_detail);
                        } else {
                            $insert_detail = "INSERT INTO tbl_meter_reading_details
                                (meter_reading_id, nozzle_id, staff_id, item_type, price,
                                 last_reading, current_reading, sale_reading, test_reading, net_sale, amount, payment_type)
                                VALUES ('$id','$nozzle_id','$row_staff_id','$item_type','$price',
                                        '$last_reading','$current_reading','$sale_reading','$test_reading','$net_sale','$amount','$row_payment')";
                            mysqli_query($connection, $insert_detail);
                        }

                        // Update running start_reading in tbl_nozzles
                        mysqli_query($connection, "UPDATE tbl_nozzles SET start_reading = '$current_reading' WHERE id = '$nozzle_id'");

                        // Synchronize daily snapshot in tbl_daily_nozzle_readings
                        sync_nozzle_daily_meter_reading($connection, $date, $shift_id, $nozzle_id, $last_reading, $current_reading, $net_sale);
                    }
                }

                // Update grand total in header
                mysqli_query($connection, "UPDATE tbl_meter_readings SET grand_total='$grand_total' WHERE id='$id'");
                header('Location: view-meter-reading.php?id=' . $id . '&msg=updated');
                exit;
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . mysqli_error($connection) . '</div>';
            }
        }
    }

// Fetch active nozzles
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

// Fetch staff
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
    <title>PPMS - Edit Meter Reading #<?php echo $id; ?></title>
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
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .reading-table th {
            background: var(--primary-color) !important;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            padding: 10px 8px;
        }
        .reading-table td {
            vertical-align: middle;
            padding: 7px 6px;
            font-size: 13px;
        }
        .reading-table .form-control-sm {
            border-radius: 5px;
            font-size: 12.5px;
            padding: 4px 7px;
            height: auto;
        }

        .grand-total-row {
            background: var(--primary-light);
            font-weight: 700;
            font-size: 14px;
            color: var(--primary-color);
        }

        .btn-submit {
            background: var(--primary-gradient);
            color: #fff;
            border: none;
            padding: 11px 36px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            box-shadow: 0 4px 14px rgba(4,32,78,0.3);
            transition: all .2s;
        }
        .btn-submit:hover { background: var(--primary-hover); color: #fff; transform: translateY(-1px); }

        .btn-cancel {
            border-radius: 8px;
            padding: 11px 26px;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>
<?php include('../include/navbar.php'); ?>
<main class="main">
<div class="container-fluid pt-4 pb-5 px-4">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-edit mr-2"></i>Edit Meter Reading #<?php echo $id; ?></h4>
            <small style="opacity:.8;">Modify shift meter readings and nozzle sales</small>
        </div>
        <div>
            <a href="view-meter-reading.php?id=<?php echo $id; ?>" class="btn btn-outline-light btn-sm mr-2 font-weight-bold">
                <i class="fas fa-eye mr-1"></i> View Details
            </a>
            <a href="meter-reading-list.php" class="btn btn-outline-light btn-sm font-weight-bold">
                <i class="fas fa-arrow-left mr-1"></i> Back to List
            </a>
        </div>
    </div>

    <?php if (!empty($message)) echo $message; ?>

    <form method="POST" action="edit-meter-reading.php?id=<?php echo $id; ?>" id="editMeterReadingForm">

        <!-- Header Card: Date, Shift, Notes -->
        <div class="header-card">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date <span class="text-danger">*</span></label>
                    <input type="date" name="date" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($header['date']); ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" class="form-control font-weight-bold" required>
                        <option value="">-- Select Shift --</option>
                        <?php
                        if ($shifts_result) {
                            mysqli_data_seek($shifts_result, 0);
                            while ($s = mysqli_fetch_assoc($shifts_result)) {
                                $sel = ($s['id'] == $header['shift_id']) ? 'selected' : '';
                                echo '<option value="'.$s['id'].'" '.$sel.'>'.htmlspecialchars($s['name']).'</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label><i class="fas fa-sticky-note mr-1 text-primary"></i> Shift Remarks</label>
                    <input type="text" name="remarks" class="form-control" placeholder="Optional remarks for this reading" value="<?php echo htmlspecialchars($header['remarks'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Nozzle Readings Table Card -->
        <div class="table-card">
            <div class="table-card-title">
                <span><i class="fas fa-gas-pump mr-2"></i>Nozzle Readings</span>
                <span class="badge badge-primary px-3 py-1 font-weight-bold" style="font-size:12px;"><?php echo count($nozzles); ?> Active Nozzles</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover reading-table mb-0" id="readingTable">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Nozzle</th>
                            <th>Item</th>
                            <th style="width:140px;">Sales Staff</th>
                            <th style="width:100px;">Price (Rs.)</th>
                            <th style="width:115px;">Start Reading</th>
                            <th style="width:120px;">Current Reading</th>
                            <th style="width:100px;">Testing (Ltr)</th>
                            <th style="width:105px;">Sale Reading</th>
                            <th style="width:105px;">Net Sale (Ltr)</th>
                            <th style="width:130px;">Amount (Rs.)</th>
                            <th style="width:110px;">Payment Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sn = 1;
                        $calc_grand = 0;
                        foreach ($nozzles as $nz):
                            $nz_id = $nz['id'];
                            $det = $existing_details[$nz_id] ?? null;

                            $price          = $det ? floatval($det['price']) : floatval($nz['price']);
                            $start_reading  = $det ? floatval($det['last_reading']) : floatval($nz['start_reading']);
                            $current_reading= $det ? floatval($det['current_reading']) : floatval($nz['start_reading']);
                            $test_reading   = $det ? floatval($det['test_reading']) : 0.00;
                            $sale_reading   = $det ? floatval($det['sale_reading']) : 0.00;
                            $net_sale       = $det ? floatval($det['net_sale']) : 0.00;
                            $amount         = $det ? floatval($det['amount']) : 0.00;
                            $staff_id       = $det ? intval($det['staff_id']) : 0;
                            $payment_type   = $det ? $det['payment_type'] : 'Cash';
                            $item_name      = $nz['item_name'] ?? 'Fuel';

                            $calc_grand += $amount;
                        ?>
                        <tr id="row_<?php echo $nz_id; ?>">
                            <td class="text-center font-weight-bold text-muted"><?php echo $sn++; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($nz['nozzle_name']); ?></strong>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($nz['tank_name'] ?? ''); ?></small>
                                <input type="hidden" name="item_type[<?php echo $nz_id; ?>]" value="<?php echo htmlspecialchars($item_name); ?>">
                            </td>
                            <td><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($item_name); ?></span></td>
                            <td>
                                <select name="row_staff_id[<?php echo $nz_id; ?>]" class="form-control form-control-sm">
                                    <option value="0">-- Unassigned --</option>
                                    <?php foreach ($staff_list as $stf): ?>
                                        <option value="<?php echo $stf['id']; ?>" <?php echo ($staff_id == $stf['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($stf['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.01" name="price[<?php echo $nz_id; ?>]" id="price_<?php echo $nz_id; ?>"
                                       class="form-control form-control-sm text-right price-input font-weight-bold"
                                       value="<?php echo number_format($price, 2, '.', ''); ?>"
                                       oninput="calculateRow(<?php echo $nz_id; ?>)">
                            </td>
                            <td>
                                <input type="number" step="0.01" name="last_reading[<?php echo $nz_id; ?>]" id="start_<?php echo $nz_id; ?>"
                                       class="form-control form-control-sm text-right start-reading font-weight-bold"
                                       value="<?php echo number_format($start_reading, 2, '.', ''); ?>"
                                       oninput="calculateRow(<?php echo $nz_id; ?>)">
                            </td>
                            <td>
                                <input type="number" step="0.01" name="current_reading[<?php echo $nz_id; ?>]" id="curr_<?php echo $nz_id; ?>"
                                       class="form-control form-control-sm text-right current-reading font-weight-bold text-primary"
                                       value="<?php echo number_format($current_reading, 2, '.', ''); ?>"
                                       oninput="calculateRow(<?php echo $nz_id; ?>)">
                            </td>
                            <td>
                                <input type="number" step="0.01" name="test_reading[<?php echo $nz_id; ?>]" id="test_<?php echo $nz_id; ?>"
                                       class="form-control form-control-sm text-right test-reading"
                                       value="<?php echo number_format($test_reading, 2, '.', ''); ?>"
                                       oninput="calculateRow(<?php echo $nz_id; ?>)">
                            </td>
                            <td class="text-right font-weight-bold text-muted" id="sale_display_<?php echo $nz_id; ?>">
                                <?php echo number_format($sale_reading, 2); ?>
                            </td>
                            <td class="text-right font-weight-bold text-success" id="net_display_<?php echo $nz_id; ?>">
                                <?php echo number_format($net_sale, 2); ?>
                            </td>
                            <td class="text-right font-weight-bold text-danger row-amount" id="amount_display_<?php echo $nz_id; ?>">
                                <?php echo number_format($amount, 2); ?>
                            </td>
                            <td>
                                <select name="payment_type[<?php echo $nz_id; ?>]" class="form-control form-control-sm">
                                    <option value="Cash" <?php echo ($payment_type === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                    <option value="Credit" <?php echo ($payment_type === 'Credit') ? 'selected' : ''; ?>>Credit</option>
                                    <option value="Online" <?php echo ($payment_type === 'Online') ? 'selected' : ''; ?>>Online</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total-row">
                            <td colspan="10" class="text-right font-weight-bold">Grand Total (Rs.):</td>
                            <td class="text-right font-weight-bold text-danger" id="grand_total_display" style="font-size:16px;">
                                <?php echo number_format($calc_grand, 2); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Submit & Actions Footer -->
        <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
            <a href="view-meter-reading.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-cancel">
                <i class="fas fa-times mr-1"></i> Cancel
            </a>
            <button type="submit" name="submit" class="btn btn-submit">
                <i class="fas fa-save mr-2"></i> Update Meter Reading
            </button>
        </div>

    </form>

</div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
function calculateRow(nzId) {
    var start = parseFloat($('#start_' + nzId).val()) || 0;
    var curr  = parseFloat($('#curr_' + nzId).val()) || 0;
    var test  = parseFloat($('#test_' + nzId).val()) || 0;
    var price = parseFloat($('#price_' + nzId).val()) || 0;

    var sale = curr - start;
    var net  = sale - test;
    var amt  = net * price;

    $('#sale_display_' + nzId).text(sale.toFixed(2));
    $('#net_display_' + nzId).text(net.toFixed(2));
    $('#amount_display_' + nzId).text(amt.toFixed(2));

    calculateGrandTotal();
}

function calculateGrandTotal() {
    var grand = 0;
    $('.row-amount').each(function() {
        var val = parseFloat($(this).text().replace(/,/g, '')) || 0;
        grand += val;
    });
    $('#grand_total_display').text(grand.toFixed(2));
}

$(document).ready(function() {
    calculateGrandTotal();
});
</script>
</body>
</html>
