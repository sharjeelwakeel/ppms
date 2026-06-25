<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';

$message = '';

if (isset($_POST['submit'])) {
    $date         = mysqli_real_escape_string($connection, $_POST['date']);
    $shift_id     = mysqli_real_escape_string($connection, $_POST['shift_id']);
    $payment_type = mysqli_real_escape_string($connection, $_POST['payment_type']);
    $remarks      = mysqli_real_escape_string($connection, $_POST['remarks'] ?? '');
    $grand_total  = 0;

    // Insert header record (staff_id is stored per-nozzle in tbl_meter_reading_details)
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
                $row_payment     = mysqli_real_escape_string($connection, $_POST['row_payment_type'][$idx] ?? $payment_type);

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
            }
        }

        // Loop through card sales rows
        if (isset($_POST['card_staff_id']) && is_array($_POST['card_staff_id'])) {
            foreach ($_POST['card_staff_id'] as $c_idx => $c_staff_id) {
                $c_staff_id   = intval($c_staff_id);
                $c_machine_id = intval($_POST['card_machine_id'][$c_idx] ?? 0);
                $c_item_id    = intval($_POST['card_item_id'][$c_idx] ?? 0);
                $c_rate       = floatval($_POST['card_rate'][$c_idx] ?? 0);
                $c_amount     = floatval($_POST['card_amount'][$c_idx] ?? 0);
                $c_quantity   = floatval($_POST['card_quantity'][$c_idx] ?? 0);
                $c_batch_no   = mysqli_real_escape_string($connection, $_POST['card_batch_no'][$c_idx] ?? '');

                if ($c_staff_id > 0 && $c_machine_id > 0 && $c_item_id > 0 && $c_amount > 0) {
                    // Fetch charges percentage for this machine
                    $charge_query = mysqli_query($connection, "SELECT charges_percentage FROM tbl_card_machines WHERE id='$c_machine_id'");
                    $charge_row   = mysqli_fetch_assoc($charge_query);
                    $charges_pct  = floatval($charge_row['charges_percentage'] ?? 0);
                    
                    $service_charges = $c_amount * ($charges_pct / 100);
                    $net_amount      = $c_amount - $service_charges;

                    $card_sales_sql = "INSERT INTO tbl_meter_reading_card_sales 
                        (meter_reading_id, staff_id, card_machine_id, item_id, quantity, rate, amount, batch_no, service_charges, net_amount)
                        VALUES 
                        ('$reading_id', '$c_staff_id', '$c_machine_id', '$c_item_id', '$c_quantity', '$c_rate', '$c_amount', '$c_batch_no', '$service_charges', '$net_amount')";
                    mysqli_query($connection, $card_sales_sql);
                }
            }
        }

        // Update grand total in header
        mysqli_query($connection, "UPDATE tbl_meter_readings SET grand_total='$grand_total' WHERE id='$reading_id'");
        header('Location: view-meter-reading.php?id=' . $reading_id);
        exit;
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch all active nozzles with item info and price
$nozzles_sql = "SELECT n.id, n.name AS nozzle_name, i.name AS item_name, i.cash_rate AS price, t.tank_name
                FROM tbl_nozzles n
                LEFT JOIN tbl_items i ON n.item_id = i.id
                LEFT JOIN tbl_tanks t ON n.tank_id = t.id
                WHERE n.status = 'Active'
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

// Fetch card machines list
$machines_sql = "SELECT id, name, charges_percentage FROM tbl_card_machines ORDER BY name ASC";
$machines_result = mysqli_query($connection, $machines_sql);
$machines_list = [];
while ($m = mysqli_fetch_assoc($machines_result)) { $machines_list[] = $m; }

// Fetch items list
$items_sql = "SELECT id, name, cash_rate AS price FROM tbl_items ORDER BY name ASC";
$items_result = mysqli_query($connection, $items_sql);
$items_list = [];
while ($item = mysqli_fetch_assoc($items_result)) { $items_list[] = $item; }
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
            overflow: hidden;
            margin-bottom: 22px;
        }
        .table-card-title {
            background: var(--primary-gradient);
            color: #fff;
            padding: 11px 20px;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: .3px;
        }

        /* Reading table */
        .reading-table { margin: 0; font-size: 13px; }
        .reading-table thead th {
            background: var(--primary-color);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            padding: 10px 8px;
            white-space: nowrap;
            vertical-align: middle;
            border: none;
        }
        .reading-table tbody tr { transition: background .15s; }
        .reading-table tbody tr:hover { background: var(--primary-light); }
        .reading-table td {
            vertical-align: middle;
            font-size: 13px;
            padding: 7px 8px;
            border-color: #e9ecef;
        }
        .reading-table input[type=number],
        .reading-table select.form-control {
            font-size: 13px;
            padding: 4px 7px;
            border-radius: 6px;
            height: 33px;
        }
        .reading-table input[type=number] { width: 115px; }
        .reading-table select.form-control { width: 130px; }

        .nozzle-badge {
            display: inline-block;
            background: var(--primary-gradient);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .item-badge {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .calc-field {
            background: #f8f9fa;
            font-weight: 700;
            text-align: right;
            color: var(--primary-color);
            font-size: 13px;
        }
        .amount-field {
            background: #e8eaf6;
            font-weight: 700;
            text-align: right;
            color: var(--primary-color);
            font-size: 13px;
        }
        .rate-field {
            background: #fff3e0;
            font-weight: 700;
            text-align: right;
            color: #e65100;
            font-size: 13px;
        }

        .grand-total-row td {
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 10px;
        }
        .grand-total-label { text-align: right; font-size: 14px; letter-spacing: 1px; }
        .grand-total-value { text-align: right; font-size: 18px; }

        .btn-save {
            background: var(--primary-gradient);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 9px 28px;
            border-radius: 8px;
            font-size: 14px;
            letter-spacing: .3px;
            box-shadow: 0 4px 12px rgba(4,32,78,0.25);
            transition: all .2s;
        }
        .btn-save:hover { background: var(--primary-hover); color: #fff; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(4,32,78,0.3); }

        .badge-petrol  { background: #2e7d32; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-diesel  { background: #bf360c; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-item    { background: #4a148c; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
    </style>
</head>
<body>
<?php include('../include/navbar.php'); ?>
<main class="main">
    <div class="container-fluid pt-4 pb-5 px-4">
        <form action="add-meter-reading.php" method="POST" id="meterForm">

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h4><i class="fas fa-tachometer-alt mr-2"></i>New Meter Reading</h4>
                    <small style="opacity:.8;">Fill nozzle readings below — calculations are automatic</small>
                </div>
                <div>
                    <a href="meter-reading-list.php" class="btn btn-outline-light btn-sm mr-2">
                        <i class="fas fa-arrow-left mr-1"></i> Back
                    </a>
                    <button type="submit" name="submit" class="btn-save btn">
                        <i class="fas fa-save mr-1"></i> Save Reading
                    </button>
                </div>
            </div>

            <?php echo $message; ?>

            <!-- Header Fields -->
            <div class="header-card">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt mr-1 text-primary"></i> Date</label>
                            <input type="date" name="date" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-clock mr-1 text-primary"></i> Shift</label>
                            <select name="shift_id" class="form-control" required>
                                <option value="">-- Select Shift --</option>
                                <?php
                                while ($s = mysqli_fetch_assoc($shifts_result)):
                                ?>
                                <option value="<?php echo $s['id']; ?>">
                                    <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card mr-1 text-primary"></i> Payment Type (Default)</label>
                            <select name="payment_type" class="form-control" id="globalPayment">
                                <option value="Cash">Cash</option>
                                <option value="Credit">Credit</option>
                                <option value="Online">Online</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-center">
                        <div class="form-group w-100">
                            <label>&nbsp;</label>
                            <div class="alert alert-info mb-0 py-2 px-3" style="font-size:12px; border-radius:8px;">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Sale Reading</strong> = Current − Last<br>
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
                                <th>Payment Type</th>
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

                                <!-- Last Reading -->
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="last_reading[]"
                                           id="last_<?php echo $i; ?>"
                                           class="form-control last_reading"
                                           value="0"
                                           oninput="calculate(<?php echo $i; ?>)">
                                </td>

                                <!-- Current Reading -->
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="current_reading[]"
                                           id="curr_<?php echo $i; ?>"
                                           class="form-control current_reading"
                                           value="0"
                                           oninput="calculate(<?php echo $i; ?>)">
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

                                <!-- Per-row Payment Type -->
                                <td>
                                    <select name="row_payment_type[]" class="form-control row-payment"
                                            id="rpt_<?php echo $i; ?>"
                                            style="width:110px;font-size:12px;">
                                        <option value="Cash">Cash</option>
                                        <option value="Credit">Credit</option>
                                        <option value="Online">Online</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="grand-total-row">
                                <td colspan="10" class="grand-total-label">
                                    <i class="fas fa-sigma mr-2"></i>GRAND TOTAL
                                </td>
                                <td class="grand-total-value" id="grand_total_display">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Bottom Details (Remarks, Card Sale Button & Total) -->
            <div class="row align-items-center mb-4 mt-3">
                <div class="col-md-5">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold" style="font-size:13px; color:#444;"><i class="fas fa-comment-dots mr-1 text-primary"></i> Remarks</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Enter any remarks or notes here..." style="border-radius:7px; font-size:13px;">
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <button type="button" class="btn btn-info font-weight-bold px-4 py-2" data-toggle="modal" data-target="#cardSaleModal" style="background:linear-gradient(135deg, #17a2b8 0%, #117a8b 100%); border:none; box-shadow:0 4px 12px rgba(23,162,184,0.25); border-radius:8px; font-size:13px;" onclick="if($('#cardSalesBody tr').length === 0) addCardRow();">
                        <i class="fas fa-credit-card mr-1"></i> Card Sale
                    </button>
                </div>
                <div class="col-md-4 text-right">
                    <div class="font-weight-bold text-muted" style="font-size:13px; letter-spacing:0.5px;">
                        CARD SALE TOTAL: <span id="card_sale_total_display" class="text-primary font-weight-bold" style="font-size:18px; margin-left:8px;">0.00</span>
                    </div>
                </div>
            </div>

            <!-- Bottom Save -->
            <div class="text-right">
                <a href="meter-reading-list.php" class="btn btn-secondary mr-2">
                    <i class="fas fa-times mr-1"></i> Cancel
                </a>
                <button type="submit" name="submit" class="btn-save btn">
                    <i class="fas fa-save mr-1"></i> Save Reading
                </button>
            </div>

            <!-- Card Sale Modal -->
            <div class="modal fade" id="cardSaleModal" tabindex="-1" role="dialog" aria-labelledby="cardSaleModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl" role="document">
                    <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                        <div class="modal-header bg-dark text-white py-3">
                            <h5 class="modal-title font-weight-bold" id="cardSaleModalLabel"><i class="fas fa-credit-card mr-2 text-info"></i>Card Sale</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-0">
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-bordered table-striped mb-0" id="cardSalesTable" style="font-size:13px;">
                                    <thead class="bg-light text-secondary">
                                        <tr>
                                            <th style="min-width: 160px;">Sales Ex.</th>
                                            <th style="min-width: 160px;">Ledger / Machine</th>
                                            <th style="min-width: 160px;">Item</th>
                                            <th style="width: 100px; text-align:right;">Rate</th>
                                            <th style="width: 120px; text-align:right;">Amount</th>
                                            <th style="width: 120px; text-align:right;">Quantity</th>
                                            <th style="width: 140px;">Batch No</th>
                                            <th style="width: 50px; text-align:center;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cardSalesBody">
                                        <!-- Rows added dynamically via JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer bg-light py-2 d-flex justify-content-between">
                            <div>
                                <button type="button" class="btn btn-outline-primary btn-sm font-weight-bold" onclick="addCardRow()">
                                    <i class="fas fa-plus mr-1"></i> Add Row
                                </button>
                            </div>
                            <div>
                                <span class="mr-3 font-weight-bold text-muted" style="font-size:13px;">Total Card Sale: <span id="modal_card_total_display" class="text-primary font-weight-bold" style="font-size:16px; margin-left:5px;">0.00</span></span>
                                <button type="button" class="btn btn-primary btn-sm px-4 font-weight-bold" data-dismiss="modal">Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
var totalRows = <?php echo count($nozzles); ?>;

/**
 * Recalculate a single nozzle row:
 *   Sale Reading = Current - Last  (min 0)
 *   Net Sale     = Sale - Test      (min 0)
 *   Amount       = Net Sale × Price
 */
function calculate(idx) {
    var price       = parseFloat($('#price_' + idx).val()) || 0;
    var last        = parseFloat($('#last_'  + idx).val()) || 0;
    var curr        = parseFloat($('#curr_'  + idx).val()) || 0;
    var test        = parseFloat($('#test_'  + idx).val()) || 0;

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

/** When global payment type changes, update all row selects */
$('#globalPayment').on('change', function () {
    var val = $(this).val();
    $('.row-payment').val(val);
});

// Dynamic card options from PHP
var staffOptionsHtml = '<?php 
    echo "<option value=\"\">-- Executive --</option>";
    foreach ($staff_list as $st) {
        echo "<option value=\"" . $st['id'] . "\">" . addslashes(htmlspecialchars($st['full_name'])) . "</option>";
    }
?>';

var machineOptionsHtml = '<?php 
    echo "<option value=\"\">-- Machine --</option>";
    foreach ($machines_list as $m) {
        echo "<option value=\"" . $m['id'] . "\">" . addslashes(htmlspecialchars($m['name'])) . " (" . $m['charges_percentage'] . "% )</option>";
    }
?>';

var itemOptionsHtml = '<?php 
    echo "<option value=\"\">-- Item --</option>";
    foreach ($items_list as $item) {
        echo "<option value=\"" . $item['id'] . "\" data-price=\"" . $item['price'] . "\">" . addslashes(htmlspecialchars($item['name'])) . "</option>";
    }
?>';

var cardRowCount = 0;

function addCardRow() {
    var index = cardRowCount++;
    var rowHtml = `
        <tr id="card_row_${index}">
            <td>
                <select name="card_staff_id[]" class="form-control form-control-sm" required>
                    ${staffOptionsHtml}
                </select>
            </td>
            <td>
                <select name="card_machine_id[]" class="form-control form-control-sm" required>
                    ${machineOptionsHtml}
                </select>
            </td>
            <td>
                <select name="card_item_id[]" class="form-control form-control-sm card-item-select" onchange="updateCardRowRate(${index})" required>
                    ${itemOptionsHtml}
                </select>
            </td>
            <td>
                <input type="text" name="card_rate[]" id="card_rate_${index}" class="form-control form-control-sm card-rate-field" readonly style="background:#f8f9fa; font-weight:bold; text-align:right;">
            </td>
            <td>
                <input type="number" step="0.01" min="0" name="card_amount[]" id="card_amount_${index}" class="form-control form-control-sm card-amount-field" oninput="calculateCardRowQuantity(${index})" placeholder="0.00" required>
            </td>
            <td>
                <input type="text" name="card_quantity[]" id="card_quantity_${index}" class="form-control form-control-sm card-quantity-field" readonly style="background:#f8f9fa; font-weight:bold; text-align:right;">
            </td>
            <td>
                <input type="text" name="card_batch_no[]" class="form-control form-control-sm" placeholder="Batch No">
            </td>
            <td style="text-align:center; vertical-align:middle;">
                <button type="button" class="btn btn-sm text-danger p-0" onclick="removeCardRow(${index})"><i class="fas fa-trash-alt" style="font-size:16px;"></i></button>
            </td>
        </tr>
    `;
    $('#cardSalesBody').append(rowHtml);
}

function updateCardRowRate(index) {
    var selectedOption = $('#card_row_' + index + ' .card-item-select option:selected');
    var price = parseFloat(selectedOption.data('price')) || 0;
    $('#card_rate_' + index).val(price.toFixed(2));
    calculateCardRowQuantity(index);
}

function calculateCardRowQuantity(index) {
    var rate = parseFloat($('#card_rate_' + index).val()) || 0;
    var amount = parseFloat($('#card_amount_' + index).val()) || 0;
    var quantity = 0;
    if (rate > 0 && amount > 0) {
        quantity = amount / rate;
    }
    $('#card_quantity_' + index).val(quantity.toFixed(2));
    updateCardSalesGrandTotal();
}

function removeCardRow(index) {
    $('#card_row_' + index).remove();
    updateCardSalesGrandTotal();
}

function updateCardSalesGrandTotal() {
    var total = 0;
    $('.card-amount-field').each(function() {
        total += parseFloat($(this).val()) || 0;
    });
    $('#modal_card_total_display').text(total.toFixed(2));
    $('#card_sale_total_display').text(total.toFixed(2));
}
</script>
</html>
