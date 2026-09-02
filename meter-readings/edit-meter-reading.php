<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce edit access check
check_access('meter_readings', 'edit');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: meter-reading-list.php');
    exit;
}
$id = intval($_GET['id']);

// Auto-migrate is_returned and returned_at columns if not yet present
$chk_ret = mysqli_query($connection, "SHOW COLUMNS FROM tbl_meter_reading_credit_sales LIKE 'is_returned'");
if ($chk_ret && mysqli_num_rows($chk_ret) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN is_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER wasoli");
    mysqli_query($connection, "ALTER TABLE tbl_meter_reading_credit_sales ADD COLUMN returned_at DATETIME DEFAULT NULL AFTER is_returned");
}

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

// Fetch existing card sales
$card_sales_query = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_card_sales WHERE meter_reading_id = '$id' ORDER BY id ASC");
$existing_card_sales = [];
if ($card_sales_query) {
    while ($cs = mysqli_fetch_assoc($card_sales_query)) {
        $existing_card_sales[] = $cs;
    }
}

// Fetch existing credit sales
$credit_sales_query = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_credit_sales WHERE meter_reading_id = '$id' ORDER BY id ASC");
$existing_credit_sales = [];
if ($credit_sales_query) {
    while ($crs = mysqli_fetch_assoc($credit_sales_query)) {
        $existing_credit_sales[] = $crs;
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
        // Validate Credit Sales mandatory slip number
        $credit_slip_errors = [];
        if (isset($_POST['credit_nozzle_id']) && is_array($_POST['credit_nozzle_id'])) {
            foreach ($_POST['credit_nozzle_id'] as $idx => $c_nozzle_id) {
                $c_nozzle_id   = intval($c_nozzle_id);
                $c_slip_no     = trim($_POST['credit_slip_no'][$idx] ?? '');
                $c_qty         = floatval($_POST['credit_quantity'][$idx] ?? 0);
                $c_wasoli      = floatval($_POST['credit_wasoli'][$idx] ?? 0);
                $c_amount      = floatval($_POST['credit_amount'][$idx] ?? 0);
                $c_vehicle     = trim($_POST['credit_vehicle_number'][$idx] ?? '');

                if ($c_nozzle_id > 0 && ($c_qty > 0 || $c_wasoli > 0 || $c_amount > 0 || !empty($c_vehicle))) {
                    if (empty($c_slip_no)) {
                        $lineNum = $idx + 1;
                        $credit_slip_errors[] = "Line #{$lineNum}: Slip No is required.";
                    }
                }
            }
        }

        if (!empty($credit_slip_errors)) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i><strong>Validation Error in Credit Sales:</strong><br>' . implode('<br>', $credit_slip_errors) . '</div>';
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
                    }
                }

                // Re-sync Card Sales
                mysqli_query($connection, "DELETE FROM tbl_meter_reading_card_sales WHERE meter_reading_id = '$id'");
                if (isset($_POST['card_nozzle_id']) && is_array($_POST['card_nozzle_id'])) {
                    foreach ($_POST['card_nozzle_id'] as $idx => $c_nozzle_id) {
                        $c_nozzle_id   = intval($c_nozzle_id);
                        $c_machine_id  = intval($_POST['card_machine_id'][$idx] ?? 0);
                        $c_batch_no    = mysqli_real_escape_string($connection, $_POST['card_batch_no'][$idx] ?? '');
                        $c_no_of_cards = intval($_POST['card_no_of_cards'][$idx] ?? 0);
                        $card_amount   = floatval($_POST['card_amount'][$idx] ?? 0);

                        if ($c_nozzle_id > 0 && $card_amount > 0) {
                            $item_query = mysqli_query($connection, "SELECT item_id FROM tbl_nozzles WHERE id='$c_nozzle_id'");
                            $item_row = mysqli_fetch_assoc($item_query);
                            $c_item_id = intval($item_row['item_id'] ?? 0);

                            $charge_query = mysqli_query($connection, "SELECT charges_percentage FROM tbl_card_machines WHERE id='$c_machine_id'");
                            $charge_row   = mysqli_fetch_assoc($charge_query);
                            $charges_pct  = floatval($charge_row['charges_percentage'] ?? 0);

                            $service_charges = $card_amount * ($charges_pct / 100);
                            $net_amount      = $card_amount - $service_charges;

                            $card_sales_sql = "INSERT INTO tbl_meter_reading_card_sales 
                                (meter_reading_id, staff_id, card_machine_id, item_id, quantity, rate, amount, batch_no, service_charges, net_amount, nozzle_id, no_of_cards, created_at)
                                VALUES 
                                ('$id', 0, '$c_machine_id', '$c_item_id', 0, 0, '$card_amount', '$c_batch_no', '$service_charges', '$net_amount', '$c_nozzle_id', '$c_no_of_cards', NOW())";
                            mysqli_query($connection, $card_sales_sql);
                        }
                    }
                }

                // Re-sync Credit Sales
                mysqli_query($connection, "DELETE FROM tbl_meter_reading_credit_sales WHERE meter_reading_id = '$id'");
                if (isset($_POST['credit_nozzle_id']) && is_array($_POST['credit_nozzle_id'])) {
                    foreach ($_POST['credit_nozzle_id'] as $idx => $c_nozzle_id) {
                        $c_nozzle_id      = intval($c_nozzle_id);
                        $c_slip_date      = mysqli_real_escape_string($connection, $_POST['credit_slip_date'][$idx] ?? '');
                        $c_slip_no        = mysqli_real_escape_string($connection, trim($_POST['credit_slip_no'][$idx] ?? ''));
                        $c_slip_type      = mysqli_real_escape_string($connection, trim($_POST['credit_slip_type'][$idx] ?? 'Permanent Slip'));
                        if (!in_array($c_slip_type, ['Permanent Slip', 'Balanced Slip', 'Temporary Slip'])) {
                            $c_slip_type = 'Permanent Slip';
                        }
                        $c_account_number = mysqli_real_escape_string($connection, trim($_POST['credit_account_number'][$idx] ?? ''));
                        $c_vehicle_number = mysqli_real_escape_string($connection, trim($_POST['credit_vehicle_number'][$idx] ?? ''));
                        $c_quantity       = floatval($_POST['credit_quantity'][$idx] ?? 0);
                        $c_rate           = floatval($_POST['credit_rate'][$idx] ?? 0);
                        $c_amount         = floatval($_POST['credit_amount'][$idx] ?? 0);
                        $c_cash_rate      = floatval($_POST['credit_cash_rate'][$idx] ?? 0);
                        $c_issue_quantity = floatval($_POST['credit_issue_quantity'][$idx] ?? 0);
                        $c_balance_1      = floatval($_POST['credit_balance_1'][$idx] ?? 0);
                        $c_balance_2      = floatval($_POST['credit_balance_2'][$idx] ?? 0);
                        $c_wasoli         = floatval($_POST['credit_wasoli'][$idx] ?? 0);

                        // If Received check is ticked, is_returned = 1 (we received it), otherwise 0 (we don't receive)
                        $c_is_returned = 0;
                        if ($c_slip_type === 'Temporary Slip') {
                            $c_is_returned = (isset($_POST['credit_is_returned'][$idx]) && intval($_POST['credit_is_returned'][$idx]) === 1) ? 1 : 0;
                        }

                        // Calculation strictly on QTY
                        if ($c_slip_type === 'Balanced Slip') {
                            $c_charge_amount = 0.00;
                        } elseif ($c_slip_type === 'Temporary Slip') {
                            $effective_qty = $c_wasoli > 0 ? $c_wasoli : $c_quantity;
                            // If Received is checked (is_returned = 1), charge is 0; if NOT checked (we don't receive), charge is effective_qty * rate (must collect!)
                            $c_charge_amount = ($c_is_returned === 1) ? 0.00 : ($effective_qty * $c_rate);
                        } else { // Permanent Slip
                            $effective_qty = $c_quantity > 0 ? $c_quantity : $c_issue_quantity;
                            $c_charge_amount = $effective_qty * $c_rate;
                        }

                        if ($c_nozzle_id > 0 && ($c_amount > 0 || $c_quantity > 0 || $c_issue_quantity > 0 || $c_wasoli > 0) && !empty($c_slip_no)) {
                            if (empty($c_account_number) && !empty($c_vehicle_number)) {
                                $vh_chk = mysqli_query($connection, "SELECT customer_id FROM tbl_customer_vehicles WHERE (reg_number = '$c_vehicle_number' OR numeric_number = '$c_vehicle_number') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
                                if ($vh_chk && ($vh_row = mysqli_fetch_assoc($vh_chk))) {
                                    $c_account_number = $vh_row['customer_id'];
                                }
                            }

                            $ret_date_val = ($c_is_returned === 1) ? 'NOW()' : 'NULL';
                            $credit_sales_sql = "INSERT INTO tbl_meter_reading_credit_sales 
                                (meter_reading_id, nozzle_id, slip_date, slip_no, slip_type, account_number, vehicle_number, quantity, rate, amount, charge_amount, cash_rate, issue_quantity, balance_1, balance_2, wasoli, is_returned, returned_at, created_at)
                                VALUES 
                                ('$id', '$c_nozzle_id', '$c_slip_date', '$c_slip_no', '$c_slip_type', '$c_account_number', '$c_vehicle_number', '$c_quantity', '$c_rate', '$c_amount', '$c_charge_amount', '$c_cash_rate', '$c_issue_quantity', '$c_balance_1', '$c_balance_2', '$c_wasoli', '$c_is_returned', $ret_date_val, NOW())";
                            mysqli_query($connection, $credit_sales_sql);
                        }
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
}

// Fetch active nozzles
$nozzles_sql = "SELECT n.id, n.name AS nozzle_name, n.start_reading, i.name AS item_name, i.cash_rate AS price, i.credit_rate, t.tank_name
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

// Fetch staff
$staff_sql    = "SELECT id, CONCAT(first_name,' ',last_name) AS full_name FROM tbl_staff ORDER BY first_name ASC";
$staff_result = mysqli_query($connection, $staff_sql);
$staff_list   = [];
while ($s = mysqli_fetch_assoc($staff_result)) { $staff_list[] = $s; }

// Fetch card machines
$machines_sql = "SELECT id, name, charges_percentage FROM tbl_card_machines ORDER BY name ASC";
$machines_result = mysqli_query($connection, $machines_sql);
$machines_list = [];
while ($m = mysqli_fetch_assoc($machines_result)) { $machines_list[] = $m; }

// Fetch items
$items_sql = "SELECT id, name, cash_rate AS price FROM tbl_items ORDER BY name ASC";
$items_result = mysqli_query($connection, $items_sql);
$items_list = [];
while ($item = mysqli_fetch_assoc($items_result)) { $items_list[] = $item; }

// Fetch vehicles
$vehicles_sql = "SELECT v.id, v.customer_id, v.vehicle_name, v.reg_number, v.numeric_number, v.fuel_limit, v.vehicle_type, c.name AS customer_name, c.fuel_rate, c.status AS customer_status
                 FROM tbl_customer_vehicles v
                 LEFT JOIN tbl_customers c ON v.customer_id = c.id
                 WHERE (v.deleted_at IS NULL OR v.deleted_at = '0000-00-00 00:00:00') 
                   AND v.status = 'Active'
                 ORDER BY v.reg_number ASC";
$vehicles_res = mysqli_query($connection, $vehicles_sql);
$vehicles_list = [];
if ($vehicles_res && mysqli_num_rows($vehicles_res) > 0) {
    while ($vh = mysqli_fetch_assoc($vehicles_res)) {
        $vehicles_list[] = $vh;
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
            <small style="opacity:.8;">Modify shift meter readings, card transactions, and credit slips</small>
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

        <!-- Card Sales Table Card -->
        <div class="table-card">
            <div class="table-card-title">
                <span><i class="fas fa-credit-card mr-2 text-success"></i>Card Machine Sales</span>
                <div>
                    <button type="button" class="btn btn-outline-success btn-sm font-weight-bold mr-2" data-toggle="modal" data-target="#cardSummaryModal">
                        <i class="fas fa-calculator mr-1"></i> Summary (<span id="card_sale_total_display">0.00</span>)
                    </button>
                    <button type="button" class="btn btn-success btn-sm font-weight-bold" onclick="addCardRow()">
                        <i class="fas fa-plus mr-1"></i> Add Card Row
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 text-center" id="cardSalesTable">
                    <thead>
                        <tr class="bg-light">
                            <th style="width: 25%;">Nozzle</th>
                            <th style="width: 25%;">Card Machine</th>
                            <th style="width: 20%;">Batch #</th>
                            <th style="width: 12%;">No of Cards</th>
                            <th style="width: 18%;">Amount (Rs.)</th>
                            <th style="width: 40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="cardSalesBody">
                        <!-- Rendered via PHP/JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Credit Sales Table Card -->
        <div class="table-card">
            <div class="table-card-title">
                <span><i class="fas fa-file-invoice-dollar mr-2 text-warning"></i>Credit Sales</span>
                <div>
                    <button type="button" class="btn btn-outline-warning text-dark btn-sm font-weight-bold mr-2" data-toggle="modal" data-target="#creditSummaryModal">
                        <i class="fas fa-list-alt mr-1"></i> Summary (<span id="credit_sale_total_display">0.00</span>)
                    </button>
                    <button type="button" class="btn btn-primary btn-sm font-weight-bold" onclick="addCreditRow()">
                        <i class="fas fa-plus mr-1"></i> Add Credit Row
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <datalist id="registeredVehiclesList">
                    <?php foreach ($vehicles_list as $vh): ?>
                        <option value="<?php echo htmlspecialchars($vh['reg_number']); ?>">
                            <?php echo htmlspecialchars($vh['customer_name']); ?> (ID: <?php echo $vh['customer_id']; ?>) - <?php echo htmlspecialchars($vh['vehicle_name']); ?>
                        </option>
                        <?php if (!empty($vh['numeric_number'])): ?>
                            <option value="<?php echo htmlspecialchars($vh['numeric_number']); ?>">
                                <?php echo htmlspecialchars($vh['customer_name']); ?> (Numeric: <?php echo htmlspecialchars($vh['numeric_number']); ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </datalist>

                <table class="table table-bordered table-sm mb-0 text-center" id="creditSalesTable" style="font-size: 11.5px;">
                    <thead>
                        <tr class="bg-light">
                            <th style="width: 140px;">Nozzle</th>
                            <th style="width: 105px;">Slip Date</th>
                            <th style="width: 90px;">Slip # <span class="text-danger">*</span></th>
                            <th style="width: 140px;">Slip Type</th>
                            <th style="width: 120px;">Vehicle #</th>
                            <th style="width: 75px;">Account #</th>
                            <th style="width: 85px;">Item</th>
                            <th style="width: 80px;" class="text-primary font-weight-bold">QTY</th>
                            <th style="width: 80px;">Rate</th>
                            <th style="width: 90px;">Amount</th>
                            <th style="width: 95px;" class="text-primary font-weight-bold">Charge Amt</th>
                            <th style="width: 80px;">Cash Rate</th>
                            <th style="width: 80px;">Issue Qty</th>
                            <th style="width: 75px;">Balance 1</th>
                            <th style="width: 75px;">Balance 2</th>
                            <th style="width: 80px;" class="text-warning font-weight-bold">Tmp. Receive</th>
                            <th style="width: 35px;"></th>
                        </tr>
                    </thead>
                    <tbody id="creditSalesBody">
                        <!-- Rendered via JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Submit & Actions Footer -->
        <div class="d-flex justify-content-between align-items-center mt-4">
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

<!-- Card Summary Modal -->
<div class="modal fade" id="cardSummaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="fas fa-credit-card mr-2"></i>Card Sales Summary</h6>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex justify-content-between font-weight-bold py-2 border-bottom">
                    <span>Total Card Transactions:</span>
                    <span id="modal_card_count">0</span>
                </div>
                <div class="d-flex justify-content-between font-weight-bold py-2 text-success" style="font-size: 16px;">
                    <span>Total Card Amount:</span>
                    <span>Rs. <span id="modal_card_total_display">0.00</span></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Credit Summary Modal -->
<div class="modal fade" id="creditSummaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="fas fa-file-invoice-dollar mr-2"></i>Credit Sales Summary</h6>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-3">
                <div class="d-flex justify-content-between font-weight-bold py-2 border-bottom">
                    <span>Total Slips:</span>
                    <span id="modal_credit_count">0</span>
                </div>
                <div class="d-flex justify-content-between font-weight-bold py-2 border-bottom">
                    <span>Total QTY (Fuel Volume):</span>
                    <span><span id="modal_credit_qty">0.00</span> Ltr</span>
                </div>
                <div class="d-flex justify-content-between font-weight-bold py-2 text-danger" style="font-size: 16px;">
                    <span>Total Charge Amount (Must Pay):</span>
                    <span>Rs. <span id="modal_credit_total_display">0.00</span></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

<script>
var nozzlesData  = <?php echo json_encode($nozzles); ?>;
var machinesData = <?php echo json_encode($machines_list); ?>;
var vehiclesData = <?php echo json_encode($vehicles_list); ?>;

var existingCardSales   = <?php echo json_encode($existing_card_sales); ?>;
var existingCreditSales = <?php echo json_encode($existing_credit_sales); ?>;

function escapeHtml(text) {
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return (text || '').toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}

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

// Card Sales Logic
var cardRowCounter = 0;
function addCardRow(prefill) {
    cardRowCounter++;
    var rowId = cardRowCounter;
    prefill = prefill || {};

    var nozzleOptions = '<option value="">-- Select Nozzle --</option>';
    nozzlesData.forEach(function(nz) {
        var sel = (prefill.nozzle_id == nz.id) ? 'selected' : '';
        nozzleOptions += '<option value="' + nz.id + '" ' + sel + '>' + escapeHtml(nz.nozzle_name) + '</option>';
    });

    var machineOptions = '<option value="">-- Select Machine --</option>';
    machinesData.forEach(function(m) {
        var sel = (prefill.card_machine_id == m.id) ? 'selected' : '';
        machineOptions += '<option value="' + m.id + '" ' + sel + '>' + escapeHtml(m.name) + '</option>';
    });

    var amountVal = prefill.amount ? parseFloat(prefill.amount).toFixed(2) : '0';
    var batchVal  = prefill.batch_no ? escapeHtml(prefill.batch_no) : '';
    var cardsVal  = prefill.no_of_cards ? parseInt(prefill.no_of_cards) : '0';

    var rowHtml = '<tr id="card_row_' + rowId + '">' +
        '<td><select name="card_nozzle_id[]" class="form-control form-control-sm" required>' + nozzleOptions + '</select></td>' +
        '<td><select name="card_machine_id[]" class="form-control form-control-sm" required>' + machineOptions + '</select></td>' +
        '<td><input type="text" name="card_batch_no[]" class="form-control form-control-sm" placeholder="Batch #" value="' + batchVal + '"></td>' +
        '<td><input type="number" name="card_no_of_cards[]" class="form-control form-control-sm" value="' + cardsVal + '"></td>' +
        '<td><input type="number" step="0.01" name="card_amount[]" class="form-control form-control-sm card-amount font-weight-bold text-right" value="' + amountVal + '" oninput="calculateCardTotal()" required></td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeCardRow(this)"><i class="fas fa-trash-alt"></i></button></td>' +
        '</tr>';

    $('#cardSalesBody').append(rowHtml);
    calculateCardTotal();
}

function calculateCardTotal() {
    var total = 0;
    var count = 0;
    $('.card-amount').each(function() {
        var a = parseFloat($(this).val()) || 0;
        total += a;
        if (a > 0) count++;
    });
    $('#card_sale_total_display').text(total.toFixed(2));
    $('#modal_card_total_display').text(total.toFixed(2));
    $('#modal_card_count').text(count);
}

function removeCardRow(btn) {
    $(btn).closest('tr').remove();
    calculateCardTotal();
}

// Credit Sales Logic
var creditRowCounter = 0;
function addCreditRow(prefill) {
    creditRowCounter++;
    var rowId = creditRowCounter;
    prefill = prefill || {};
    var today = prefill.slip_date || new Date().toISOString().slice(0, 10);
    var slipType = prefill.slip_type || 'Permanent Slip';

    var nozzleOptions = '<option value="">-- Select Nozzle --</option>';
    nozzlesData.forEach(function(nz) {
        var sel = (prefill.nozzle_id == nz.id) ? 'selected' : '';
        nozzleOptions += '<option value="' + nz.id + '" ' + sel + '>' + escapeHtml(nz.nozzle_name) + '</option>';
    });

    var slipNoVal   = prefill.slip_no ? escapeHtml(prefill.slip_no) : '';
    var vehicleVal  = prefill.vehicle_number ? escapeHtml(prefill.vehicle_number) : '';
    var accountVal  = prefill.account_number ? escapeHtml(prefill.account_number) : '';
    var qtyVal      = prefill.quantity ? parseFloat(prefill.quantity).toFixed(2) : '0';
    var rateVal     = prefill.rate ? parseFloat(prefill.rate).toFixed(2) : '0';
    var amountVal   = prefill.amount ? parseFloat(prefill.amount).toFixed(2) : '0';
    var chargeVal   = prefill.charge_amount ? parseFloat(prefill.charge_amount).toFixed(2) : '0';
    var cashRateVal = prefill.cash_rate ? parseFloat(prefill.cash_rate).toFixed(2) : '0';
    var issueVal    = prefill.issue_quantity ? parseFloat(prefill.issue_quantity).toFixed(2) : '0';
    var bal1Val     = prefill.balance_1 ? parseFloat(prefill.balance_1).toFixed(2) : '0';
    var bal2Val     = prefill.balance_2 ? parseFloat(prefill.balance_2).toFixed(2) : '0';
    var wasoliVal   = prefill.wasoli ? parseFloat(prefill.wasoli).toFixed(2) : '0';

    var isReturnedVal = (prefill.is_returned == 1);

    var rowHtml = '<tr id="credit_row_' + rowId + '">' +
        '<td><select name="credit_nozzle_id[]" class="form-control form-control-sm credit-nozzle-select" onchange="updateCreditItem(this)" required>' + nozzleOptions + '</select></td>' +
        '<td><input type="date" name="credit_slip_date[]" class="form-control form-control-sm" value="' + today + '"></td>' +
        '<td><input type="text" name="credit_slip_no[]" class="form-control form-control-sm credit-slip-no font-weight-bold" placeholder="Slip #" value="' + slipNoVal + '" required></td>' +
        '<td>' +
            '<div class="d-flex flex-column text-left px-1" style="gap:2px;">' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_perm_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Permanent Slip" ' + (slipType === 'Permanent Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-primary" for="st_perm_' + rowId + '" style="font-size:11px; cursor:pointer;">Permanent Slip</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_bal_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Balanced Slip" ' + (slipType === 'Balanced Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-info" for="st_bal_' + rowId + '" style="font-size:11px; cursor:pointer;">Balanced Slip</label>' +
                '</div>' +
                '<div class="custom-control custom-radio custom-control-inline m-0">' +
                    '<input type="radio" id="st_temp_' + rowId + '" name="slip_type_radio_' + rowId + '" class="custom-control-input" value="Temporary Slip" ' + (slipType === 'Temporary Slip' ? 'checked' : '') + ' onchange="onSlipTypeChange(this, ' + rowId + ')">' +
                    '<label class="custom-control-label font-weight-bold text-warning" for="st_temp_' + rowId + '" style="font-size:11px; cursor:pointer; color:#b07800 !important;">Temporary Slip</label>' +
                '</div>' +
                '<div class="mt-1 return-slip-box return-slip-box_' + rowId + '" style="' + (slipType === 'Temporary Slip' ? '' : 'display:none;') + '">' +
                    '<div class="custom-control custom-checkbox">' +
                        '<input type="hidden" name="credit_is_returned[]" class="credit-is-returned-val" value="' + (isReturnedVal ? '1' : '0') + '">' +
                        '<input type="checkbox" class="custom-control-input credit-is-returned" id="chk_ret_' + rowId + '" ' + (isReturnedVal ? 'checked' : '') + ' onchange="onReturnCheckboxChange(this)">' +
                        '<label class="custom-control-label font-weight-bold text-success" for="chk_ret_' + rowId + '" style="font-size:11px; cursor:pointer;">Received</label>' +
                    '</div>' +
                    '<span class="badge ' + (isReturnedVal ? 'badge-success text-white' : 'badge-warning text-dark') + ' font-weight-bold p-1 mt-1 slip-return-status-text" style="font-size:9.5px; display:block; text-align:left;">' +
                        (isReturnedVal ? '<i class="fas fa-check-circle mr-1"></i> Received (Settled)' : '<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)') +
                    '</span>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="credit_slip_type[]" class="credit-slip-type-val" value="' + slipType + '">' +
        '</td>' +
        '<td>' +
            '<input type="text" name="credit_vehicle_number[]" list="registeredVehiclesList" class="form-control form-control-sm credit-vehicle-number font-weight-bold text-monospace" placeholder="Type / Pick Vehicle" value="' + vehicleVal + '" oninput="onCreditVehicleInput(this)" onchange="onCreditVehicleInput(this)" required>' +
            '<div class="vehicle-match-info small text-left mt-1" style="display:none; font-size:10.5px; line-height:1.2;"></div>' +
        '</td>' +
        '<td><input type="text" name="credit_account_number[]" class="form-control form-control-sm credit-account-number font-weight-bold" placeholder="Customer ID" value="' + accountVal + '" readonly style="background-color:#e9ecef; cursor:not-allowed;" required></td>' +
        '<td><input type="text" class="form-control form-control-sm credit-item-name" disabled></td>' +
        '<td><input type="number" step="0.01" name="credit_quantity[]" class="form-control form-control-sm credit-qty font-weight-bold text-primary" value="' + qtyVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_rate[]" class="form-control form-control-sm credit-rate font-weight-bold" value="' + rateVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><input type="number" step="0.01" name="credit_amount[]" class="form-control form-control-sm credit-amount-field" value="' + amountVal + '" readonly style="background-color:#f8f9fa;"></td>' +
        '<td><input type="number" step="0.01" name="credit_charge_amount[]" class="form-control form-control-sm credit-charge-amount-field font-weight-bold text-primary" value="' + chargeVal + '" readonly style="background-color:#eef2ff;" required></td>' +
        '<td><input type="number" step="0.01" name="credit_cash_rate[]" class="form-control form-control-sm credit-cash-rate" value="' + cashRateVal + '"></td>' +
        '<td>' +
            '<input type="number" step="0.01" name="credit_issue_quantity[]" class="form-control form-control-sm credit-issue-qty" value="' + issueVal + '" oninput="calculateCreditRow(this)">' +
            '<div class="fuel-limit-warning text-danger small text-left mt-1" style="display:none; font-size:10px;"></div>' +
        '</td>' +
        '<td><input type="number" step="0.01" name="credit_balance_1[]" class="form-control form-control-sm" value="' + bal1Val + '"></td>' +
        '<td><input type="number" step="0.01" name="credit_balance_2[]" class="form-control form-control-sm" value="' + bal2Val + '"></td>' +
        '<td><input type="number" step="0.01" name="credit_wasoli[]" class="form-control form-control-sm credit-wasoli font-weight-bold text-warning" value="' + wasoliVal + '" oninput="calculateCreditRow(this)"></td>' +
        '<td><button type="button" class="btn btn-danger btn-sm" onclick="removeCreditRow(this)"><i class="fas fa-trash-alt"></i></button></td>' +
        '</tr>';

    $('#creditSalesBody').append(rowHtml);

    var $newRow = $('#credit_row_' + rowId);
    updateCreditItem($newRow.find('.credit-nozzle-select')[0]);
    onSlipTypeChange($newRow.find('input[name="slip_type_radio_' + rowId + '"]:checked')[0], rowId);
    if (vehicleVal) {
        onCreditVehicleInput($newRow.find('.credit-vehicle-number')[0]);
    }
    calculateCreditRow($newRow.find('.credit-qty')[0]);
}

function onReturnCheckboxChange(checkboxElement) {
    var $box = $(checkboxElement).closest('.return-slip-box');
    var isChecked = $(checkboxElement).is(':checked');
    $box.find('.credit-is-returned-val').val(isChecked ? 1 : 0);
    var $statusLabel = $box.find('.slip-return-status-text');
    if (isChecked) {
        $statusLabel.removeClass('badge-warning text-dark').addClass('badge-success text-white')
                    .html('<i class="fas fa-check-circle mr-1"></i> Received (Settled)');
    } else {
        $statusLabel.removeClass('badge-success text-white').addClass('badge-warning text-dark')
                    .html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
    }
    calculateCreditRow(checkboxElement);
}

function onSlipTypeChange(radioElement, rowId) {
    if (!radioElement) return;
    var val = $(radioElement).val();
    var $row = $(radioElement).closest('tr');
    $row.find('.credit-slip-type-val').val(val);

    var $issueQty  = $row.find('.credit-issue-qty');
    var $wasoli    = $row.find('.credit-wasoli');
    var $chargeAmt = $row.find('.credit-charge-amount-field');
    var $retBox    = $row.find('.return-slip-box');

    if (val === 'Balanced Slip') {
        $issueQty.prop('disabled', false).css('background-color', '');
        $wasoli.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $retBox.hide();
        $retBox.find('input[type="checkbox"]').prop('checked', false);
        $retBox.find('.credit-is-returned-val').val(0);
        $retBox.find('.slip-return-status-text').removeClass('badge-success text-white').addClass('badge-warning text-dark').html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
        $chargeAmt.val('0.00');
    } else if (val === 'Temporary Slip') {
        $issueQty.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $wasoli.prop('disabled', false).css('background-color', '');
        $retBox.show();
        var isChecked = $retBox.find('input[type="checkbox"]').is(':checked');
        var $statusLabel = $retBox.find('.slip-return-status-text');
        if (isChecked) {
            $statusLabel.removeClass('badge-warning text-dark').addClass('badge-success text-white').html('<i class="fas fa-check-circle mr-1"></i> Received (Settled)');
        } else {
            $statusLabel.removeClass('badge-success text-white').addClass('badge-warning text-dark').html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
        }
    } else { // Permanent Slip
        $issueQty.prop('disabled', false).css('background-color', '');
        $wasoli.prop('disabled', true).css('background-color', '#e9ecef').val(0);
        $retBox.hide();
        $retBox.find('input[type="checkbox"]').prop('checked', false);
        $retBox.find('.credit-is-returned-val').val(0);
        $retBox.find('.slip-return-status-text').removeClass('badge-success text-white').addClass('badge-warning text-dark').html('<i class="fas fa-hand-holding mr-1"></i> Giving (Loan Petrol)');
    }

    calculateCreditRow($row.find('.credit-qty')[0]);
}

function updateCreditItem(selectElement) {
    var nzId = $(selectElement).val();
    var $row = $(selectElement).closest('tr');
    var nz = nozzlesData.find(function(n) { return n.id == nzId; });
    if (nz) {
        $row.find('.credit-item-name').val(nz.item_name || '');
        if (parseFloat($row.find('.credit-rate').val()) === 0 || !$row.find('.credit-rate').val()) {
            var defaultCreditRate = (nz.credit_rate && parseFloat(nz.credit_rate) > 0) ? parseFloat(nz.credit_rate) : parseFloat(nz.price);
            $row.find('.credit-rate').val(defaultCreditRate.toFixed(2));
        }
        if (parseFloat($row.find('.credit-cash-rate').val()) === 0 || !$row.find('.credit-cash-rate').val()) {
            $row.find('.credit-cash-rate').val(parseFloat(nz.price).toFixed(2));
        }
        calculateCreditRow(selectElement);
    } else {
        $row.find('.credit-item-name').val('');
    }
}

function onCreditVehicleInput(inputElement) {
    var $row = $(inputElement).closest('tr');
    var enteredVal = $(inputElement).val().trim().toUpperCase();
    var $accountInput = $row.find('.credit-account-number');
    var $matchInfo = $row.find('.vehicle-match-info');
    
    if (!enteredVal) {
        $accountInput.val('');
        $matchInfo.hide().html('');
        $(inputElement).removeClass('is-valid is-invalid');
        return;
    }

    var match = vehiclesData.find(function(v) {
        var reg = (v.reg_number || '').trim().toUpperCase();
        var num = (v.numeric_number || '').trim().toUpperCase();
        return reg === enteredVal || (num && num === enteredVal);
    });

    if (!match && enteredVal.length >= 2) {
        match = vehiclesData.find(function(v) {
            var reg = (v.reg_number || '').trim().toUpperCase();
            return reg.indexOf(enteredVal) === 0 || reg.indexOf(enteredVal) !== -1;
        });
    }

    if (match) {
        $accountInput.val(match.customer_id);
        $row.data('fuel_limit', parseFloat(match.fuel_limit) || 0);
        $row.data('vehicle_type', match.vehicle_type || '');
        
        var badgeHtml = '<span class="badge badge-success px-1 py-0.5"><i class="fas fa-user-check mr-1"></i>' + 
            escapeHtml(match.customer_name) + ' (ID: ' + match.customer_id + ')</span>';
        if (parseFloat(match.fuel_limit) > 0) {
            badgeHtml += '<br><span class="badge badge-info px-1 py-0.5 mt-0.5"><i class="fas fa-gas-pump mr-1"></i>Limit: ' + 
                parseFloat(match.fuel_limit).toFixed(2) + ' Ltr</span>';
        }
        $matchInfo.show().html(badgeHtml);
        $(inputElement).removeClass('is-invalid').addClass('is-valid');
    } else {
        if (!$accountInput.val()) {
            $matchInfo.show().html('<span class="badge badge-warning text-dark px-1"><i class="fas fa-exclamation-triangle mr-1"></i>Unregistered Vehicle</span>');
        }
    }
}

function calculateCreditRow(inputElement) {
    var $row = $(inputElement).closest('tr');
    var slipType = $row.find('.credit-slip-type-val').val() || 'Permanent Slip';
    var rate = parseFloat($row.find('.credit-rate').val()) || 0;
    var baseQty = parseFloat($row.find('.credit-qty').val()) || 0;
    var issueQty = parseFloat($row.find('.credit-issue-qty').val()) || 0;
    var wasoli = parseFloat($row.find('.credit-wasoli').val()) || 0;
    var limit = parseFloat($row.data('fuel_limit')) || 0;
    var isReturned = $row.find('.credit-is-returned').is(':checked');
    var $limitWarning = $row.find('.fuel-limit-warning');

    var effectiveQty = 0;
    var nominalAmount = 0;
    var chargeAmount = 0;

    if (slipType === 'Balanced Slip') {
        effectiveQty = baseQty > 0 ? baseQty : issueQty;
        nominalAmount = effectiveQty * rate;
        chargeAmount = 0; // Free / already charged previously
    } else if (slipType === 'Temporary Slip') {
        effectiveQty = wasoli > 0 ? wasoli : baseQty;
        nominalAmount = effectiveQty * rate;
        // If Received is checked: we received it (charge to collect = 0).
        // If Received is NOT checked: we don't receive (unpaid loan petrol, must collect!).
        chargeAmount = isReturned ? 0 : (effectiveQty * rate);
    } else { // Permanent Slip
        effectiveQty = baseQty > 0 ? baseQty : issueQty;
        nominalAmount = effectiveQty * rate;
        chargeAmount = effectiveQty * rate; // Always calculated on Qty
    }

    if (limit > 0 && effectiveQty > limit) {
        $limitWarning.show().html('<i class="fas fa-exclamation-circle mr-1"></i>Exceeds quota (' + limit.toFixed(2) + ' Ltr)');
    } else {
        $limitWarning.hide().html('');
    }

    $row.find('.credit-amount-field').val(nominalAmount.toFixed(2));
    $row.find('.credit-charge-amount-field').val(chargeAmount.toFixed(2));

    calculateCreditTotals();
}

function calculateCreditTotals() {
    var totalCharge = 0;
    var totalQty = 0;
    var count = 0;

    $('#creditSalesBody tr').each(function() {
        var chg = parseFloat($(this).find('.credit-charge-amount-field').val()) || 0;
        var qty = parseFloat($(this).find('.credit-qty').val()) || 0;
        var wasoli = parseFloat($(this).find('.credit-wasoli').val()) || 0;
        var st = $(this).find('.credit-slip-type-val').val() || '';

        totalCharge += chg;
        totalQty += (st === 'Temporary Slip' && wasoli > 0) ? wasoli : qty;
        count++;
    });

    $('#credit_sale_total_display').text(totalCharge.toFixed(2));
    $('#modal_credit_total_display').text(totalCharge.toFixed(2));
    $('#modal_credit_qty').text(totalQty.toFixed(2));
    $('#modal_credit_count').text(count);
}

function removeCreditRow(btn) {
    $(btn).closest('tr').remove();
    calculateCreditTotals();
}

// Pre-fill existing card and credit sales on load
$(document).ready(function() {
    if (existingCardSales && existingCardSales.length > 0) {
        existingCardSales.forEach(function(cs) {
            addCardRow(cs);
        });
    }

    if (existingCreditSales && existingCreditSales.length > 0) {
        existingCreditSales.forEach(function(crs) {
            addCreditRow(crs);
        });
    }

    calculateGrandTotal();
});
</script>
</body>
</html>
