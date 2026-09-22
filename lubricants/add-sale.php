<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding lubricant sales
check_access('items', 'add');

// Ensure shift_id exists (self-healing migration)
$chk1 = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sale_invoices LIKE 'shift_id'");
if ($chk1 && mysqli_num_rows($chk1) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sale_invoices ADD COLUMN `shift_id` INT(11) NOT NULL DEFAULT 0 AFTER `date`, ADD KEY `idx_shift_id` (`shift_id`)");
}
$chk2 = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sales LIKE 'shift_id'");
if ($chk2 && mysqli_num_rows($chk2) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sales ADD COLUMN `shift_id` INT(11) NOT NULL DEFAULT 0 AFTER `date`, ADD KEY `idx_shift_id` (`shift_id`)");
}

$message = '';

// Generate next suggested invoice number
$today_str = date('Ymd');
$q_last = mysqli_query($connection, "SELECT id FROM tbl_lubricant_sale_invoices ORDER BY id DESC LIMIT 1");
$next_id = 1;
if ($q_last && $r = mysqli_fetch_assoc($q_last)) {
    $next_id = intval($r['id']) + 1;
}
$default_invoice_no = "INV-" . $today_str . "-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sale') {
    $sale_date    = mysqli_real_escape_string($connection, $_POST['date'] ?? date('Y-m-d'));
    $shift_id     = intval($_POST['shift_id'] ?? 0);
    $payment_type = mysqli_real_escape_string($connection, $_POST['payment_type'] ?? 'Cash');
    if (!in_array($payment_type, ['Cash', 'Card', 'Credit'])) {
        $payment_type = 'Cash';
    }

    $card_machine_id = ($payment_type === 'Card') ? intval($_POST['card_machine_id'] ?? 0) : null;
    $bank_id         = ($payment_type === 'Card') ? intval($_POST['bank_id'] ?? 0) : null;
    
    // Credit specific fields
    $slip_type       = ($payment_type === 'Credit') ? mysqli_real_escape_string($connection, trim($_POST['slip_type'] ?? 'Permanent Slip')) : 'Permanent Slip';
    if (!in_array($slip_type, ['Permanent Slip', 'Balanced Slip', 'Temporary Slip'])) {
        $slip_type = 'Permanent Slip';
    }
    $slip_no         = ($payment_type === 'Credit') ? mysqli_real_escape_string($connection, trim($_POST['slip_no'] ?? '')) : '';
    $slip_date       = ($payment_type === 'Credit' && !empty($_POST['slip_date'])) ? mysqli_real_escape_string($connection, trim($_POST['slip_date'])) : $sale_date;
    $customer_id     = ($payment_type === 'Credit') ? intval($_POST['customer_id'] ?? 0) : null;
    $vehicle_number  = ($payment_type === 'Credit') ? mysqli_real_escape_string($connection, trim($_POST['vehicle_number'] ?? '')) : '';
    $ref_slip_no     = ($slip_type === 'Balanced Slip') ? mysqli_real_escape_string($connection, trim($_POST['ref_slip_no'] ?? '')) : '';
    $ref_slip_date   = ($slip_type === 'Balanced Slip' && !empty($_POST['ref_slip_date'])) ? mysqli_real_escape_string($connection, trim($_POST['ref_slip_date'])) : null;
    $temp_slip_id    = ($slip_type === 'Permanent Slip' && !empty($_POST['temp_slip_id'])) ? intval($_POST['temp_slip_id']) : null;
    $temp_wasoli_amt = ($slip_type === 'Permanent Slip' && !empty($_POST['temp_wasoli_amount'])) ? floatval($_POST['temp_wasoli_amount']) : 0.00;

    // Auto-resolve customer_id from registered vehicle if missing
    if ($payment_type === 'Credit' && empty($customer_id) && !empty($vehicle_number)) {
        $q_veh_resolve = mysqli_query($connection, "SELECT customer_id FROM tbl_customer_vehicles WHERE (reg_number = '$vehicle_number' OR numeric_number = '$vehicle_number') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if ($q_veh_resolve && $vr = mysqli_fetch_assoc($q_veh_resolve)) {
            $customer_id = intval($vr['customer_id']);
        }
    }

    $invoice_no   = mysqli_real_escape_string($connection, trim($_POST['invoice_no'] ?? $default_invoice_no));
    if (empty($invoice_no)) {
        $invoice_no = $default_invoice_no;
    }
    $details      = mysqli_real_escape_string($connection, trim($_POST['details'] ?? ''));
    $user_id      = intval($_SESSION['loggedInUser'] ?? 0);

    $prod_ids     = $_POST['product_id'] ?? [];
    $qtys         = $_POST['quantity'] ?? [];
    $issue_qtys   = $_POST['issue_quantity'] ?? [];
    $rates        = $_POST['rate'] ?? [];

    $valid_lines = [];
    $prod_aggregated_physical_qty = [];

    for ($i = 0; $i < count($prod_ids); $i++) {
        $pId  = intval($prod_ids[$i] ?? 0);
        $qVal = intval($qtys[$i] ?? 0);
        $rVal = floatval($rates[$i] ?? 0);

        if ($payment_type !== 'Credit') {
            $issVal = $qVal;
            $balVal = 0;
        } else {
            $issVal = isset($issue_qtys[$i]) ? intval($issue_qtys[$i]) : $qVal;
            if ($slip_type === 'Temporary Slip' || $slip_type === 'Balanced Slip') {
                $issVal = $qVal;
                $balVal = 0;
            } else {
                if ($issVal > $qVal) {
                    $issVal = $qVal;
                }
                $balVal = max(0, $qVal - $issVal);
            }
        }

        if ($pId > 0 && $qVal > 0) {
            $line_amt = $qVal * $rVal;
            $valid_lines[] = [
                'product_id'       => $pId,
                'quantity'         => $qVal,
                'issue_quantity'   => $issVal,
                'balance_quantity' => $balVal,
                'rate'             => $rVal,
                'amount'           => $line_amt
            ];

            if (!isset($prod_aggregated_physical_qty[$pId])) {
                $prod_aggregated_physical_qty[$pId] = 0;
            }
            $prod_aggregated_physical_qty[$pId] += $issVal;
        }
    }

    // Validation
    if (empty($shift_id) || $shift_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please select a Shift before saving.</div>';
    } elseif (empty($valid_lines)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please add at least one product with a valid quantity greater than zero.</div>';
    } elseif ($payment_type === 'Card' && (empty($card_machine_id) || $card_machine_id <= 0)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please select a valid Card Machine / POS Terminal.</div>';
    } elseif ($payment_type === 'Card' && (empty($bank_id) || $bank_id <= 0)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please select a valid Destination Bank Account.</div>';
    } elseif ($payment_type === 'Credit' && empty($vehicle_number)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please enter a vehicle registration number for Credit sale.</div>';
    } elseif ($payment_type === 'Credit' && (empty($customer_id) || $customer_id <= 0)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>The vehicle "'.htmlspecialchars($vehicle_number).'" is not registered to an active customer account. Credit sales require a registered customer vehicle.</div>';
    } elseif ($payment_type === 'Credit' && empty($slip_no)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Slip / Voucher # is mandatory for Credit transactions.</div>';
    } else {
        // Verify server-side physical stock against requested physical issue quantities
        $stock_error = '';
        foreach ($prod_aggregated_physical_qty as $chkPid => $reqPhysicalQty) {
            if ($reqPhysicalQty <= 0) continue;
            $stock_sql = "
                SELECT p.name,
                    (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = $chkPid AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) -
                     COALESCE((SELECT SUM(COALESCE(issue_quantity, quantity)) FROM tbl_lubricant_sales WHERE product_id = $chkPid AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0)) AS avail_stock
                FROM tbl_lubricant_products p
                WHERE p.id = $chkPid
            ";
            $stk_res = mysqli_query($connection, $stock_sql);
            if ($stk_res && $stk_row = mysqli_fetch_assoc($stk_res)) {
                $avail = intval($stk_row['avail_stock']);
                if ($reqPhysicalQty > $avail) {
                    $stock_error = "Insufficient stock for product <strong>" . htmlspecialchars($stk_row['name']) . "</strong>. Available: " . number_format($avail, 0) . " units. Requested Issue Qty: " . number_format($reqPhysicalQty, 0) . " units.";
                    break;
                }
            }
        }

        if (!empty($stock_error)) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>' . $stock_error . '</div>';
        } else {
            // Compute invoice totals and customer charge
            $total_items    = count($valid_lines);
            $total_quantity = array_sum(array_column($valid_lines, 'quantity'));
            $total_amount   = array_sum(array_column($valid_lines, 'amount'));

            // Customer charge amount based on business rules
            if ($payment_type === 'Credit') {
                if ($slip_type === 'Permanent Slip') {
                    $charge_amount = round($total_amount + $temp_wasoli_amt, 2);
                } elseif ($slip_type === 'Balanced Slip') {
                    $charge_amount = 0.00; // Customer was pre-billed on original Permanent Slip
                } elseif ($slip_type === 'Temporary Slip') {
                    $charge_amount = 0.00; // Loan chit: billed later upon Permanent Slip settlement
                } else {
                    $charge_amount = $total_amount;
                }
                $payment_status = 'Unpaid';
                $paid_amount    = 0.00;
            } else {
                $charge_amount  = $total_amount;
                $payment_status = 'Paid';
                $paid_amount    = $total_amount;
            }

            mysqli_begin_transaction($connection);
            try {
                $cm_sql     = ($card_machine_id > 0) ? "'$card_machine_id'" : "NULL";
                $b_sql      = ($bank_id > 0) ? "'$bank_id'" : "NULL";
                $cust_sql   = ($customer_id > 0) ? "'$customer_id'" : "NULL";
                $ref_date_s = !empty($ref_slip_date) ? "'$ref_slip_date'" : "NULL";
                $temp_id_s  = ($temp_slip_id > 0) ? "'$temp_slip_id'" : "NULL";

                // 1. Insert master invoice
                $ins_inv = "INSERT INTO tbl_lubricant_sale_invoices 
                            (invoice_no, slip_no, date, shift_id, slip_date, customer_id, vehicle_number, payment_type, slip_type,
                             card_machine_id, bank_id, details, ref_slip_no, ref_slip_date, temp_slip_id, temp_wasoli_amount,
                             is_returned, settled_in_slip_id, total_items, total_quantity, total_amount, charge_amount,
                             payment_status, paid_amount, created_by) 
                            VALUES 
                            ('$invoice_no', '$slip_no', '$sale_date', '$shift_id', '$slip_date', $cust_sql, '$vehicle_number', '$payment_type', '$slip_type',
                             $cm_sql, $b_sql, '$details', '$ref_slip_no', $ref_date_s, $temp_id_s, '$temp_wasoli_amt',
                             0, NULL, '$total_items', '$total_quantity', '$total_amount', '$charge_amount',
                             '$payment_status', '$paid_amount', '$user_id')";
                $inv_ok = mysqli_query($connection, $ins_inv);
                if (!$inv_ok) {
                    throw new Exception("Error saving sale invoice: " . mysqli_error($connection));
                }
                $invoice_id = mysqli_insert_id($connection);

                // 2. Insert itemized product lines into tbl_lubricant_sales
                foreach ($valid_lines as $vl) {
                    $l_pid     = $vl['product_id'];
                    $l_qty     = $vl['quantity'];
                    $l_iss_qty = $vl['issue_quantity'];
                    $l_bal_qty = $vl['balance_quantity'];
                    $l_rate    = $vl['rate'];
                    $l_amt     = $vl['amount'];

                    $ins_line = "INSERT INTO tbl_lubricant_sales 
                                 (invoice_id, invoice_no, product_id, quantity, issue_quantity, balance_quantity, rate, amount, payment_type, details, date, shift_id) 
                                 VALUES 
                                 ('$invoice_id', '$invoice_no', '$l_pid', '$l_qty', '$l_iss_qty', '$l_bal_qty', '$l_rate', '$l_amt', '$payment_type', '$details', '$sale_date', '$shift_id')";
                    $line_ok = mysqli_query($connection, $ins_line);
                    if (!$line_ok) {
                        throw new Exception("Error saving product sale line: " . mysqli_error($connection));
                    }
                }

                // 3. If settled an open Temporary Slip on this Permanent Slip, mark the temp slip as returned & settled
                if ($payment_type === 'Credit' && $slip_type === 'Permanent Slip' && $temp_slip_id > 0) {
                    $up_temp = "UPDATE tbl_lubricant_sale_invoices 
                                SET is_returned = 1, settled_in_slip_id = '$invoice_id', updated_at = NOW() 
                                WHERE id = '$temp_slip_id'";
                    if (!mysqli_query($connection, $up_temp)) {
                        throw new Exception("Error settling linked temporary slip: " . mysqli_error($connection));
                    }
                }

                mysqli_commit($connection);
                header('Location: sales-list.php?msg=saved');
                exit;
            } catch (Exception $e) {
                mysqli_rollback($connection);
                $message = '<div class="alert alert-danger"><i class="fas fa-times-circle mr-2"></i>' . $e->getMessage() . '</div>';
            }
        }
    }
}

// Fetch active products with available physical stock and rate tiers
$products_sql = "
    SELECT p.id, p.name, p.price,
           COALESCE(p.cash_rate, p.price) AS cash_rate,
           COALESCE(p.credit_rate, p.price) AS credit_rate,
           c.name AS category_name,
           (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) -
            COALESCE((SELECT SUM(COALESCE(issue_quantity, quantity)) FROM tbl_lubricant_sales WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0)) AS avail_stock
    FROM tbl_lubricant_products p
    LEFT JOIN tbl_product_categories c ON p.category_id = c.id
    WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
    ORDER BY p.name ASC
";
$products_result = mysqli_query($connection, $products_sql);
$products_list = [];
if ($products_result) {
    while ($r = mysqli_fetch_assoc($products_result)) {
        $products_list[] = $r;
    }
}

// Fetch active shifts (tbl_shifts)
$shifts_list = [];
$q_shift = mysqli_query($connection, "SELECT id, name FROM tbl_shifts WHERE status = 'Active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
if ($q_shift) {
    while ($s = mysqli_fetch_assoc($q_shift)) {
        $shifts_list[] = $s;
    }
}

// Fetch active card machines (tbl_card_machines)
$card_machines_list = [];
$q_cm = mysqli_query($connection, "SELECT id, name FROM tbl_card_machines WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' ORDER BY name ASC");
if ($q_cm) {
    while ($m = mysqli_fetch_assoc($q_cm)) {
        $card_machines_list[] = $m;
    }
}

// Fetch active banks (tbl_banks)
$banks_list = [];
$q_b = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' ORDER BY name ASC");
if ($q_b) {
    while ($b = mysqli_fetch_assoc($q_b)) {
        $banks_list[] = $b;
    }
}

// Fetch active customers (tbl_customers)
$customers_list = [];
$q_cust = mysqli_query($connection, "SELECT id, name, phone, other_rate FROM tbl_customers WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND (status IS NULL OR status = 'Active') ORDER BY name ASC");
if ($q_cust) {
    while ($c = mysqli_fetch_assoc($q_cust)) {
        $customers_list[] = $c;
    }
}

// Fetch active vehicles for datalist autocomplete
$vehicles_list = [];
$q_veh = mysqli_query($connection, "SELECT v.id, v.customer_id, v.reg_number, v.numeric_number, v.vehicle_name, c.name AS customer_name, c.other_rate 
                                    FROM tbl_customer_vehicles v 
                                    LEFT JOIN tbl_customers c ON v.customer_id = c.id 
                                    WHERE (v.deleted_at IS NULL OR v.deleted_at = '0000-00-00 00:00:00') AND (v.status IS NULL OR v.status = 'Active') 
                                    ORDER BY v.reg_number ASC");
if ($q_veh) {
    while ($v = mysqli_fetch_assoc($q_veh)) {
        $vehicles_list[] = $v;
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
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{ margin-top:20px; }
		.txt-center{ text-align:center; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        #salesItemsTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            vertical-align: middle;
            padding: 6px 4px !important;
            font-size: 12px;
        }
        .table-compact th, .table-compact td {
            padding: 4px 4px !important;
            vertical-align: middle !important;
            font-size: 13px;
        }
        .form-control-compact {
            height: 31px !important;
            padding: 2px 6px !important;
            font-size: 13px !important;
            border-radius: 4px !important;
        }
        select.form-control-compact {
            height: 31px !important;
            padding: 2px 6px !important;
        }
        .header-card-label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 2px;
            color: #333;
        }
        .btn-compact-del {
            width: 28px;
            height: 28px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            border-radius: 4px;
        }
        .table-totals-row {
            background: #f1f3f9;
            font-weight: bold;
            font-size: 13px;
        }
        .stock-pill {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .credit-info-box {
            background: #f0f4f8;
            border-left: 4px solid #04204e;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
        }
		</style>
		<title>PPMS - Add Product Sale</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container-fluid px-3 pt-3 pb-3">
				<form action="add-sale.php" method="POST" id="multiSaleForm" onsubmit="return validateBeforeSubmit();">
                    <input type="hidden" name="action" value="save_sale">

					<div class="row mb-2 align-items-center">
						<div class="col-md-6">
							<h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-cart-plus mr-2 text-primary"></i>Add Product Sale (Outflow)</h5>
						</div>
						<div class="col-md-6 text-right">
							<a href="sales-list.php" class="btn btn-outline-secondary btn-sm py-1 px-2 font-weight-bold"><i class="fas fa-list mr-1"></i> View Sales List</a>
						</div>
					</div>

                    <?php echo $message; ?>
                    <div id="jsWarning" class="alert alert-danger py-2 d-none" style="font-size: 13px;"><i class="fas fa-exclamation-triangle mr-2"></i><span></span></div>

					<!-- Invoice & Transaction Header Card -->
					<div class="card mb-2 border-0 shadow-sm">
						<div class="card-header text-white py-1 px-3" style="background: var(--primary-color);">
							<h6 class="mb-0 font-weight-bold" style="font-size: 13px;"><i class="fas fa-file-invoice mr-2"></i>Invoice Details</h6>
						</div>
						<div class="card-body py-2 px-3">
							<div class="row">
								<div class="col-md-2 col-sm-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-calendar-day mr-1 text-primary"></i> Sale Date <span class="text-danger">*</span></label>
										<input type="date" name="date" id="sale_date" class="form-control form-control-sm form-control-compact font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
									</div>
								</div>
								<div class="col-md-2 col-sm-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-clock mr-1 text-primary"></i> Shift <span class="text-danger">*</span></label>
										<select name="shift_id" id="shift_id" class="form-control form-control-sm form-control-compact font-weight-bold" required>
											<option value="">-- Select Shift --</option>
											<?php foreach ($shifts_list as $sh): ?>
												<option value="<?php echo $sh['id']; ?>" <?php echo (isset($_POST['shift_id']) && $_POST['shift_id'] == $sh['id']) ? 'selected' : (count($shifts_list) === 1 ? 'selected' : ''); ?>>
													<?php echo htmlspecialchars($sh['name']); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-md-2 col-sm-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-credit-card mr-1 text-info"></i> Payment Type <span class="text-danger">*</span></label>
										<select name="payment_type" id="payment_type" class="form-control form-control-sm form-control-compact font-weight-bold" onchange="onPaymentTypeChange()" required>
											<option value="Cash">Cash</option>
											<option value="Card">Card</option>
											<option value="Credit">Credit (Voucher / Loan)</option>
										</select>
									</div>
								</div>
								<div class="col-md-3 col-sm-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-hashtag mr-1 text-secondary"></i> Invoice / Receipt # <span class="text-danger">*</span></label>
										<input type="text" name="invoice_no" id="invoice_no" class="form-control form-control-sm form-control-compact font-weight-bold text-primary" value="<?php echo htmlspecialchars($default_invoice_no); ?>" required>
									</div>
								</div>
								<div class="col-md-3 col-sm-12">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-comment-dots mr-1 text-secondary"></i> Remarks <small class="text-muted font-italic">(Optional)</small></label>
										<input type="text" name="details" class="form-control form-control-sm form-control-compact" placeholder="Optional remarks...">
									</div>
								</div>
							</div>

							<!-- Dynamic Card Details Row (Visible only when Card is selected) -->
							<div class="row mt-2 pt-2 border-top d-none" id="cardDetailsRow">
								<div class="col-md-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-credit-card mr-1 text-primary"></i> Card Machine / POS Terminal <span class="text-danger">*</span></label>
										<select name="card_machine_id" id="card_machine_id" class="form-control form-control-sm form-control-compact">
											<option value="">-- Select POS Machine --</option>
											<?php foreach ($card_machines_list as $cm): ?>
												<option value="<?php echo $cm['id']; ?>"><?php echo htmlspecialchars($cm['name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-university mr-1 text-success"></i> Destination Bank Account <span class="text-danger">*</span></label>
										<select name="bank_id" id="bank_id" class="form-control form-control-sm form-control-compact">
											<option value="">-- Select Bank Account --</option>
											<?php foreach ($banks_list as $b): ?>
												<option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name'] . ' (' . $b['account_number'] . ')'); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>

							<!-- Dynamic Credit Details Section (Visible only when Credit is selected) -->
							<div class="mt-2 pt-2 border-top d-none" id="creditDetailsRow">
								<div class="row">
									<div class="col-md-3">
										<div class="form-group mb-1">
											<label class="header-card-label"><i class="fas fa-layer-group mr-1 text-primary"></i> Slip Classification <span class="text-danger">*</span></label>
											<select name="slip_type" id="slip_type" class="form-control form-control-sm form-control-compact font-weight-bold" onchange="onSlipTypeChange()">
												<option value="Permanent Slip">Permanent Slip (Voucher)</option>
												<option value="Balanced Slip">Balanced Slip (Claim Balance)</option>
												<option value="Temporary Slip">Temporary Slip (Product Loan)</option>
											</select>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group mb-1">
											<label class="header-card-label"><i class="fas fa-truck mr-1 text-secondary"></i> Vehicle # <span class="text-danger">*</span></label>
											<input type="text" name="vehicle_number" id="vehicle_number" list="customerVehiclesDatalist" class="form-control form-control-sm form-control-compact text-uppercase font-weight-bold" placeholder="Select or type vehicle..." oninput="onCreditVehicleInput(this.value)" autocomplete="off">
											<input type="hidden" name="customer_id" id="customer_id" value="">
											<div id="vehicleMatchInfo" class="mt-1 small" style="min-height: 20px;"></div>
											<datalist id="customerVehiclesDatalist">
												<?php foreach ($vehicles_list as $v): ?>
													<option value="<?php echo htmlspecialchars($v['reg_number']); ?>">
														<?php echo htmlspecialchars($v['reg_number'] . ' — ' . ($v['customer_name'] ?? '') . ' (' . ($v['other_rate'] ?? 'Credit') . ' Rate)'); ?>
													</option>
												<?php endforeach; ?>
											</datalist>
										</div>
									</div>
									<div class="col-md-3">
										<div class="form-group mb-1">
											<label class="header-card-label"><i class="fas fa-tag mr-1 text-primary"></i> Slip / Voucher # <span class="text-danger">*</span></label>
											<input type="text" name="slip_no" id="slip_no" class="form-control form-control-sm form-control-compact font-weight-bold text-primary" placeholder="Slip #">
										</div>
									</div>
									<div class="col-md-2">
										<div class="form-group mb-1">
											<label class="header-card-label"><i class="fas fa-calendar-alt mr-1 text-secondary"></i> Slip Date <span class="text-danger">*</span></label>
											<input type="date" name="slip_date" id="slip_date" class="form-control form-control-sm form-control-compact font-weight-bold" value="<?php echo date('Y-m-d'); ?>" onchange="resolveAllProductRowRates()">
										</div>
									</div>
								</div>

								<!-- Sub-section: Balanced Slip Reference Selection -->
								<div class="credit-info-box mt-2 d-none" id="balancedSlipBox">
									<div class="d-flex justify-content-between align-items-center">
										<div>
											<i class="fas fa-history mr-1 text-warning"></i>
											<strong>Balanced Slip Claim:</strong> Claim uncollected products from a prior Permanent Slip at locked historical rates.
											<span class="badge badge-info ml-2">Customer Charge: Rs. 0.00</span>
										</div>
										<button type="button" class="btn btn-xs btn-primary font-weight-bold py-1 px-2" onclick="openFindBalanceSlipModal()">
											<i class="fas fa-search mr-1"></i> Find Original Permanent Slip
										</button>
									</div>
									<div id="balancedSlipSelectedDisplay" class="mt-2 d-none font-weight-bold text-dark">
										<input type="hidden" name="ref_slip_no" id="ref_slip_no" value="">
										<input type="hidden" name="ref_slip_date" id="ref_slip_date" value="">
										<span class="badge badge-success p-1"><i class="fas fa-check mr-1"></i> Linked Ref Slip: <span id="refSlipNoText"></span> (Date: <span id="refSlipDateText"></span>)</span>
										<button type="button" class="btn btn-xs btn-outline-danger ml-2 py-0 px-1" onclick="clearBalancedSlipRef()" title="Clear reference">&times; Clear</button>
									</div>
								</div>

								<!-- Sub-section: Temp Receive (Attach Open Temporary Slip to Permanent Slip) -->
								<div class="credit-info-box mt-2" id="tempReceiveBox">
									<div class="d-flex justify-content-between align-items-center">
										<div>
											<i class="fas fa-receipt mr-1 text-success"></i>
											<strong>Temp Receive (Loan Settlement):</strong> Settle an outstanding Temporary Slip with this voucher at historical loan rates.
										</div>
										<button type="button" class="btn btn-xs btn-outline-success font-weight-bold py-1 px-2" onclick="openFindTempSlipModal()">
											<i class="fas fa-plus-circle mr-1"></i> Attach Temp Slip
										</button>
									</div>
									<div id="tempSlipSelectedDisplay" class="mt-2 d-none font-weight-bold text-dark">
										<input type="hidden" name="temp_slip_id" id="temp_slip_id" value="">
										<input type="hidden" name="temp_wasoli_amount" id="temp_wasoli_amount" value="0.00">
										<span class="badge badge-info p-1"><i class="fas fa-link mr-1"></i> Settling Temp Slip: <span id="tempSlipNoText"></span> (Loan Date: <span id="tempSlipDateText"></span>) - Temp Receive: Rs. <span id="tempSlipWasoliText">0.00</span></span>
										<button type="button" class="btn btn-xs btn-outline-danger ml-2 py-0 px-1" onclick="clearTempSlipLink()" title="Detach">&times; Detach</button>
									</div>
								</div>

								<!-- Sub-section: Temporary Loan Notice -->
								<div class="alert alert-warning py-1 px-2 mt-2 mb-0 d-none" id="tempLoanNotice" style="font-size: 12px;">
									<i class="fas fa-info-circle mr-1"></i>
									<strong>Temporary Product Loan:</strong> Physical products will decrement inventory immediately upon handover. The customer's account will <strong>NOT</strong> be charged today (Charge: Rs. 0.00). This open chit will be settled later when the customer presents an official Permanent Slip.
								</div>
							</div>
						</div>
					</div>

					<!-- Multi-Row Products Spreadsheet Card -->
					<div class="card mb-2 border-0 shadow-sm">
						<div class="card-header bg-white d-flex justify-content-between align-items-center py-1 px-3">
							<h6 class="mb-0 font-weight-bold text-dark" style="font-size: 13px;">
								<i class="fas fa-boxes mr-2 text-primary"></i>Product Line Items
								<span class="badge badge-primary ml-2" id="lineCountBadge" style="font-size: 11px;">1 Item(s)</span>
							</h6>
							<button type="button" class="btn btn-xs btn-outline-primary font-weight-bold py-1 px-2" style="font-size: 12px;" onclick="addNewProductRow()">
								<i class="fas fa-plus mr-1"></i> Add Product Line
							</button>
						</div>
						<div class="card-body p-0">
							<div class="table-responsive">
								<table id="salesItemsTable" class="table table-bordered table-sm table-compact mb-0 text-center">
									<thead>
										<tr>
											<th style="width: 30px;">#</th>
											<th style="min-width: 220px; text-align: left;">Product Name</th>
											<th style="width: 90px;">Avail. Stock</th>
											<th style="width: 85px;" id="thVoucherQty">Voucher Qty</th>
											<th style="width: 85px;" id="thIssueQty">Issue Qty</th>
											<th style="width: 85px;" id="thBalanceQty">Balance</th>
											<th style="width: 105px;">Rate (Rs.)</th>
											<th style="width: 120px;">Line Total (Rs.)</th>
											<th style="width: 40px;">Action</th>
										</tr>
									</thead>
									<tbody id="itemsTableBody">
										<!-- Row 1 (Initial Row) -->
										<tr class="item-row" data-row-idx="0">
											<td class="align-middle row-num font-weight-bold">1</td>
											<td class="text-left">
												<select name="product_id[]" class="form-control form-control-sm form-control-compact product-select" onchange="onProductSelect(this)">
													<option value="">-- Select Product --</option>
													<?php 
													foreach ($products_list as $prod) {
														$catBadge = !empty($prod['category_name']) ? ' [' . $prod['category_name'] . ']' : '';
														echo '<option value="' . $prod['id'] . '" 
																	data-cash-rate="' . floatval($prod['cash_rate']) . '" 
																	data-credit-rate="' . floatval($prod['credit_rate']) . '" 
																	data-stock="' . intval($prod['avail_stock']) . '">' . 
																	htmlspecialchars($prod['name'] . $catBadge) . 
															  '</option>';
													}
													?>
												</select>
											</td>
											<td class="align-middle">
												<span class="stock-display text-muted small">—</span>
											</td>
											<td>
												<input type="number" step="1" min="1" name="quantity[]" class="form-control form-control-sm form-control-compact item-qty font-weight-bold text-center" placeholder="1" oninput="onQtyOrRateChange(this)">
											</td>
											<td>
												<input type="number" step="1" min="0" name="issue_quantity[]" class="form-control form-control-sm form-control-compact item-issue-qty font-weight-bold text-center text-info" placeholder="1" oninput="onQtyOrRateChange(this)">
											</td>
											<td class="align-middle">
												<span class="item-balance-display font-weight-bold text-muted small">0</span>
											</td>
											<td>
												<input type="number" step="0.01" min="0" name="rate[]" class="form-control form-control-sm form-control-compact item-rate font-weight-bold text-right" placeholder="0.00" oninput="onQtyOrRateChange(this)">
											</td>
											<td>
												<input type="text" name="line_total[]" class="form-control form-control-sm form-control-compact item-total font-weight-bold text-right text-primary" readonly style="background:#e8eaf6;" value="0.00">
											</td>
											<td class="align-middle">
												<button type="button" class="btn btn-sm btn-outline-danger btn-compact-del" onclick="removeProductRow(this)" title="Remove this line">
													<i class="fas fa-trash-alt"></i>
												</button>
											</td>
										</tr>
									</tbody>
									<tfoot>
										<tr class="table-totals-row text-dark">
											<td colspan="3" class="text-right font-weight-bold align-middle py-1" style="font-size: 12px;">
												<i class="fas fa-calculator mr-1 text-primary"></i> Overall Totals:
											</td>
											<td class="align-middle py-1">
												<span id="overallQuotaQtyDisplay" class="text-secondary font-weight-bold">0</span>
											</td>
											<td class="align-middle py-1">
												<span id="overallIssueQtyDisplay" class="text-primary font-weight-bold">0</span>
											</td>
											<td class="align-middle py-1">
												<span id="overallBalQtyDisplay" class="text-warning font-weight-bold">0</span>
											</td>
											<td class="align-middle text-muted small text-right py-1">
												Voucher Total:
											</td>
											<td class="align-middle text-right py-1">
												<span id="overallAmtDisplay" class="text-success font-weight-bold" style="font-size: 14px;">Rs. 0.00</span>
											</td>
											<td></td>
										</tr>
									</tfoot>
								</table>
							</div>
						</div>
						<div class="card-footer bg-light py-1 px-3 text-left">
							<button type="button" class="btn btn-sm btn-outline-primary font-weight-bold py-1 px-2" style="font-size: 12px;" onclick="addNewProductRow()">
								<i class="fas fa-plus mr-1"></i> Add Another Product Line
							</button>
						</div>
					</div>

					<!-- Bottom Summary & Submission Bar -->
					<div class="card border-0 shadow-sm mb-3" style="background: var(--primary-gradient);">
						<div class="card-body py-2 px-3 text-white">
							<div class="row align-items-center">
								<div class="col-6 col-md-2 text-left mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 10px; letter-spacing: 0.5px;">Products</span>
									<h5 class="mb-0 font-weight-bold" id="summaryDistinctProducts">0</h5>
								</div>
								<div class="col-6 col-md-2 text-left mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 10px; letter-spacing: 0.5px;">Voucher Quota</span>
									<h5 class="mb-0 font-weight-bold" id="summaryTotalQuota">0</h5>
								</div>
								<div class="col-6 col-md-2 text-left mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 10px; letter-spacing: 0.5px;">Physical Outflow</span>
									<h5 class="mb-0 font-weight-bold text-info" id="summaryPhysicalOutflow">0</h5>
								</div>
								<div class="col-6 col-md-3 text-left text-md-center mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 10px; letter-spacing: 0.5px;">Customer Charge (Receivable)</span>
									<h4 class="mb-0 font-weight-bold text-warning" id="summaryChargeAmount">Rs. 0.00</h4>
								</div>
								<div class="col-6 col-md-3 text-right">
									<button type="submit" class="btn btn-success font-weight-bold px-3 py-2 shadow-sm" style="font-size: 14px; border-radius: 4px;">
										<i class="fas fa-check-circle mr-1"></i> Save &amp; Complete Sale
									</button>
								</div>
							</div>
						</div>
					</div>

				</form>
			</div>
		</main>

        <!-- Modal: Find Balance Slips -->
        <div class="modal fade" id="modalFindBalanceSlip" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header text-white" style="background: var(--primary-gradient);">
                        <h6 class="modal-title font-weight-bold"><i class="fas fa-history mr-2"></i>Select Original Permanent Slip (Uncollected Balances)</h6>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="searchBalanceSlipInput" class="form-control" placeholder="Search by Slip # or Invoice #...">
                            <div class="input-group-append">
                                <button class="btn btn-outline-primary" type="button" onclick="loadBalanceSlips()"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </div>
                        <div id="balanceSlipsLoading" class="text-center py-3 d-none">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                            <p class="small text-muted mt-2">Searching pending balance slips...</p>
                        </div>
                        <div id="balanceSlipsContainer" class="table-responsive" style="max-height: 350px;">
                            <!-- Slips loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Find Open Temporary Slips (Temp. Receive) -->
        <div class="modal fade" id="modalFindTempSlip" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header text-white" style="background: var(--primary-gradient);">
                        <h6 class="modal-title font-weight-bold"><i class="fas fa-receipt mr-2"></i>Select Outstanding Temporary Slip (Loan Settlement)</h6>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="searchTempSlipInput" class="form-control" placeholder="Search by Temp Slip # or Invoice #...">
                            <div class="input-group-append">
                                <button class="btn btn-outline-primary" type="button" onclick="loadTempSlips()"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </div>
                        <div id="tempSlipsLoading" class="text-center py-3 d-none">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                            <p class="small text-muted mt-2">Searching open temporary slips...</p>
                        </div>
                        <div id="tempSlipsContainer" class="table-responsive" style="max-height: 350px;">
                            <!-- Slips loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
    // Cached products options template for rapid dynamic row generation
    var productsOptionsHtml = <?php 
        $opts = '<option value="">-- Select Product --</option>';
        foreach ($products_list as $prod) {
            $catBadge = !empty($prod['category_name']) ? ' [' . $prod['category_name'] . ']' : '';
            $opts .= '<option value="' . $prod['id'] . '" ' .
                     'data-cash-rate="' . floatval($prod['cash_rate']) . '" ' .
                     'data-credit-rate="' . floatval($prod['credit_rate']) . '" ' .
                     'data-stock="' . intval($prod['avail_stock']) . '">' . 
                     htmlspecialchars($prod['name'] . $catBadge) . 
                     '</option>';
        }
        echo json_encode($opts);
    ?>;

    // Vehicles master cache for datalist matching and customer resolution
    var vehiclesData = <?php echo json_encode($vehicles_list); ?>;

    // Customer policy rate cache
    var currentCustomerRatePolicy = 'Cash';

    $(document).ready(function() {
        onPaymentTypeChange();
        recalculateAll();
    });

    function addNewProductRow(prodId, quotaQty, issueQty, lockedRate) {
        var rowCount = $('#itemsTableBody tr.item-row').length + 1;
        var html = '<tr class="item-row" data-row-idx="' + (rowCount - 1) + '">' +
            '<td class="align-middle row-num font-weight-bold">' + rowCount + '</td>' +
            '<td class="text-left">' +
                '<select name="product_id[]" class="form-control form-control-sm form-control-compact product-select" onchange="onProductSelect(this)">' +
                    productsOptionsHtml +
                '</select>' +
            '</td>' +
            '<td class="align-middle">' +
                '<span class="stock-display text-muted small">—</span>' +
            '</td>' +
            '<td>' +
                '<input type="number" step="1" min="1" name="quantity[]" class="form-control form-control-sm form-control-compact item-qty font-weight-bold text-center" placeholder="1" oninput="onQtyOrRateChange(this)">' +
            '</td>' +
            '<td>' +
                '<input type="number" step="1" min="0" name="issue_quantity[]" class="form-control form-control-sm form-control-compact item-issue-qty font-weight-bold text-center text-info" placeholder="1" oninput="onQtyOrRateChange(this)">' +
            '</td>' +
            '<td class="align-middle">' +
                '<span class="item-balance-display font-weight-bold text-muted small">0</span>' +
            '</td>' +
            '<td>' +
                '<input type="number" step="0.01" min="0" name="rate[]" class="form-control form-control-sm form-control-compact item-rate font-weight-bold text-right" placeholder="0.00" oninput="onQtyOrRateChange(this)">' +
            '</td>' +
            '<td>' +
                '<input type="text" name="line_total[]" class="form-control form-control-sm form-control-compact item-total font-weight-bold text-right text-primary" readonly style="background:#e8eaf6;" value="0.00">' +
            '</td>' +
            '<td class="align-middle">' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-compact-del" onclick="removeProductRow(this)" title="Remove this line">' +
                    '<i class="fas fa-trash-alt"></i>' +
                '</button>' +
            '</td>' +
        '</tr>';

        var $newRow = $(html);
        $('#itemsTableBody').append($newRow);

        if (prodId) {
            $newRow.find('.product-select').val(prodId);
            onProductSelect($newRow.find('.product-select')[0]);
            if (quotaQty !== undefined) $newRow.find('.item-qty').val(quotaQty);
            if (issueQty !== undefined) $newRow.find('.item-issue-qty').val(issueQty);
            if (lockedRate !== undefined) {
                $newRow.find('.item-rate').val(parseFloat(lockedRate).toFixed(2)).prop('readonly', true);
            }
            onQtyOrRateChange($newRow.find('.item-qty')[0]);
        }

        reindexRows();
        recalculateAll();
        return $newRow;
    }

    function removeProductRow(btn) {
        var rowCount = $('#itemsTableBody tr.item-row').length;
        if (rowCount <= 1) {
            alert('At least one product line is required.');
            return;
        }
        $(btn).closest('tr').remove();
        reindexRows();
        recalculateAll();
    }

    function reindexRows() {
        $('#itemsTableBody tr.item-row').each(function(idx) {
            $(this).find('.row-num').text(idx + 1);
            $(this).attr('data-row-idx', idx);
        });
        var totalRows = $('#itemsTableBody tr.item-row').length;
        $('#lineCountBadge').text(totalRows + (totalRows === 1 ? ' Item' : ' Items'));
    }

    function onProductSelect(selectElem) {
        var $row = $(selectElem).closest('tr');
        var selectedOpt = $(selectElem).find('option:selected');
        var prodId = selectedOpt.val();

        if (prodId !== '') {
            var cashRate   = parseFloat(selectedOpt.data('cash-rate')) || 0;
            var creditRate = parseFloat(selectedOpt.data('credit-rate')) || 0;
            var stock      = parseInt(selectedOpt.data('stock'), 10) || 0;

            var paymentType = $('#payment_type').val();
            var slipType    = $('#slip_type').val();
            var slipDate    = $('#slip_date').val() || '<?php echo date('Y-m-d'); ?>';
            var policy      = currentCustomerRatePolicy || 'Cash';

            var targetRate = (paymentType === 'Credit' && policy === 'Credit' && creditRate > 0) ? creditRate : cashRate;

            // Only overwrite rate if not locked by balanced slip claim
            if (!$row.find('.item-rate').prop('readonly')) {
                $row.find('.item-rate').val(targetRate.toFixed(2));
            }

            var stockBadgeClass = (stock > 0) ? 'badge-success' : 'badge-danger';
            $row.find('.stock-display').html('<span class="badge stock-pill ' + stockBadgeClass + '">' + stock + ' units</span>');

            if ($row.find('.item-qty').val() === '') {
                $row.find('.item-qty').val(1);
            }
            if ($row.find('.item-issue-qty').val() === '') {
                $row.find('.item-issue-qty').val($row.find('.item-qty').val());
            }

            // If Credit sale, query date-effective rate for this slip_date from tbl_prices
            if (paymentType === 'Credit' && slipType !== 'Balanced Slip' && !$row.find('.item-rate').prop('readonly')) {
                $.getJSON('ajax-credit-slip-lookup.php', {
                    action: 'get_product_price_for_date',
                    product_id: prodId,
                    slip_date: slipDate,
                    policy: policy
                }, function(res) {
                    if (res && res.status === 'success') {
                        $row.find('.item-rate').val(parseFloat(res.applicable_rate).toFixed(2));
                        onQtyOrRateChange($row.find('.item-rate')[0]);
                    }
                });
            }

            // AUTO-EXPANSION: If selected in the last row and not in Balanced claim mode, append fresh row
            if ($row.is(':last-child') && $('#slip_type').val() !== 'Balanced Slip') {
                addNewProductRow();
            }
        } else {
            $row.find('.item-rate').val('');
            $row.find('.stock-display').html('<span class="text-muted small">—</span>');
            $row.find('.item-total').val('0.00');
            $row.find('.item-balance-display').text('0');
        }

        onQtyOrRateChange(selectElem);
    }

    function onQtyOrRateChange(inputElem) {
        var $row = $(inputElem).closest('tr');
        var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
        var paymentType = $('#payment_type').val();
        var slipType    = $('#slip_type').val();

        var issueQty = parseInt($row.find('.item-issue-qty').val(), 10) || 0;
        if (paymentType !== 'Credit' || slipType === 'Temporary Slip' || slipType === 'Balanced Slip') {
            issueQty = qty;
            $row.find('.item-issue-qty').val(qty);
        }

        // Strict invariant: issue_quantity <= quantity
        if (issueQty > qty) {
            issueQty = qty;
            $row.find('.item-issue-qty').val(qty);
        }

        // Calculate pending balance
        var balance = Math.max(0, qty - issueQty);
        if (balance > 0) {
            $row.find('.item-balance-display').html('<span class="badge badge-warning">' + balance + ' pending</span>');
        } else {
            $row.find('.item-balance-display').html('<span class="text-muted small">0</span>');
        }

        var rate = parseFloat($row.find('.item-rate').val()) || 0;
        var total = qty * rate;
        $row.find('.item-total').val(total.toFixed(2));

        // Check stock warning against physical issue quantity
        var selectedOpt = $row.find('.product-select option:selected');
        if (selectedOpt.val() !== '') {
            var stock = parseInt(selectedOpt.data('stock'), 10) || 0;
            if (issueQty > stock) {
                $row.find('.item-issue-qty').addClass('is-invalid');
                $row.find('.stock-display').html('<span class="badge stock-pill badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Only ' + stock + '</span>');
            } else {
                $row.find('.item-issue-qty').removeClass('is-invalid');
                var stockBadgeClass = (stock > 0) ? 'badge-success' : 'badge-danger';
                $row.find('.stock-display').html('<span class="badge stock-pill ' + stockBadgeClass + '">' + stock + ' units</span>');
            }
        }

        recalculateAll();
    }

    function onPaymentTypeChange() {
        var paymentType = $('#payment_type').val();
        if (paymentType === 'Card') {
            $('#cardDetailsRow').removeClass('d-none');
            $('#creditDetailsRow').addClass('d-none');
            $('#card_machine_id').prop('required', true);
            $('#bank_id').prop('required', true);
            $('#vehicle_number').prop('required', false);
            $('#slip_no').prop('required', false);
            currentCustomerRatePolicy = 'Cash';
        } else if (paymentType === 'Credit') {
            $('#cardDetailsRow').addClass('d-none');
            $('#creditDetailsRow').removeClass('d-none');
            $('#card_machine_id').prop('required', false).val('');
            $('#bank_id').prop('required', false).val('');
            $('#vehicle_number').prop('required', true);
            $('#slip_no').prop('required', true);
            onSlipTypeChange();
            if ($('#vehicle_number').val()) {
                onCreditVehicleInput($('#vehicle_number').val());
            }
        } else {
            // Cash
            $('#cardDetailsRow').addClass('d-none');
            $('#creditDetailsRow').addClass('d-none');
            $('#card_machine_id').prop('required', false).val('');
            $('#bank_id').prop('required', false).val('');
            $('#vehicle_number').prop('required', false);
            $('#slip_no').prop('required', false);
            currentCustomerRatePolicy = 'Cash';
        }
        resolveAllProductRowRates();
        recalculateAll();
    }

    function onSlipTypeChange() {
        var slipType = $('#slip_type').val();
        if (slipType === 'Balanced Slip') {
            $('#balancedSlipBox').removeClass('d-none');
            $('#tempReceiveBox').addClass('d-none');
            $('#tempLoanNotice').addClass('d-none');
            clearTempSlipLink();
            // In Balanced Slip, claimed balance items are physically issued immediately
            $('#itemsTableBody tr.item-row').each(function() {
                var q = $(this).find('.item-qty').val();
                $(this).find('.item-issue-qty').val(q).prop('readonly', true);
            });
        } else if (slipType === 'Temporary Slip') {
            $('#balancedSlipBox').addClass('d-none');
            $('#tempReceiveBox').addClass('d-none');
            $('#tempLoanNotice').removeClass('d-none');
            clearBalancedSlipRef();
            clearTempSlipLink();
            // In Temporary Slip, issue quantity equals quota quantity
            $('#itemsTableBody tr.item-row').each(function() {
                var q = $(this).find('.item-qty').val();
                $(this).find('.item-issue-qty').val(q).prop('readonly', true);
            });
        } else {
            // Permanent Slip
            $('#balancedSlipBox').addClass('d-none');
            $('#tempReceiveBox').removeClass('d-none');
            $('#tempLoanNotice').addClass('d-none');
            clearBalancedSlipRef();
            $('#itemsTableBody tr.item-row .item-issue-qty').prop('readonly', false);
        }
        recalculateAll();
    }

    function onCreditVehicleInput(val) {
        val = (val || '').trim().toUpperCase();
        var $infoDiv = $('#vehicleMatchInfo');

        if (!val) {
            $('#customer_id').val('');
            currentCustomerRatePolicy = 'Cash';
            $infoDiv.html('');
            resolveAllProductRowRates();
            return;
        }

        var matched = vehiclesData.find(function(v) {
            return (v.reg_number && v.reg_number.toUpperCase() === val) ||
                   (v.numeric_number && v.numeric_number.toUpperCase() === val);
        });

        if (matched) {
            $('#customer_id').val(matched.customer_id);
            currentCustomerRatePolicy = matched.other_rate || 'Credit';

            var rateBadgeClass = (currentCustomerRatePolicy === 'Cash') ? 'badge-success' : 'badge-primary';
            var rateBadgeText  = (currentCustomerRatePolicy === 'Cash') ? 'Cash Rate' : 'Credit Rate';
            var rateBadgeIcon  = (currentCustomerRatePolicy === 'Cash') ? 'fa-money-bill-wave' : 'fa-credit-card';

            $infoDiv.html(
                '<div class="d-flex flex-wrap align-items-center mt-1">' +
                    '<span class="text-success font-weight-bold mr-1"><i class="fas fa-check-circle"></i> ' + matched.customer_name + '</span> ' +
                    '<span class="badge ' + rateBadgeClass + '" title="Non-Fuel Tariff Policy"><i class="fas ' + rateBadgeIcon + ' mr-1"></i>' + rateBadgeText + '</span>' +
                '</div>'
            );
            resolveAllProductRowRates();
        } else {
            $('#customer_id').val('');
            currentCustomerRatePolicy = 'Cash';
            $infoDiv.html('<span class="text-warning"><i class="fas fa-exclamation-circle"></i> Unregistered Vehicle</span>');
            resolveAllProductRowRates();
        }
    }

    function resolveAllProductRowRates() {
        var paymentType = $('#payment_type').val();
        var slipType    = $('#slip_type').val();
        var slipDate    = $('#slip_date').val() || '<?php echo date('Y-m-d'); ?>';
        var policy      = currentCustomerRatePolicy || 'Cash';

        if (paymentType !== 'Credit') {
            $('#itemsTableBody tr.item-row').each(function() {
                var $row = $(this);
                if ($row.find('.item-rate').prop('readonly')) return;
                var $opt = $row.find('.product-select option:selected');
                if ($opt.val() !== '') {
                    var cashRate = parseFloat($opt.data('cash-rate')) || 0;
                    $row.find('.item-rate').val(cashRate.toFixed(2));
                    onQtyOrRateChange($row.find('.item-rate')[0]);
                }
            });
            return;
        }

        // Do not alter locked historical rates on Balanced Slips
        if (slipType === 'Balanced Slip') {
            return;
        }

        var prodIds = [];
        $('#itemsTableBody tr.item-row').each(function() {
            var $row = $(this);
            if ($row.find('.item-rate').prop('readonly')) return;
            var pId = $row.find('.product-select').val();
            if (pId && prodIds.indexOf(pId) === -1) {
                prodIds.push(pId);
            }
        });

        if (prodIds.length === 0) {
            return;
        }

        $.getJSON('ajax-credit-slip-lookup.php', {
            action: 'get_batch_product_prices_for_date',
            product_ids: prodIds.join(','),
            slip_date: slipDate,
            policy: policy
        }, function(res) {
            if (res && res.status === 'success' && res.rates) {
                $('#itemsTableBody tr.item-row').each(function() {
                    var $row = $(this);
                    if ($row.find('.item-rate').prop('readonly')) return;
                    var pId = $row.find('.product-select').val();
                    if (pId && res.rates[pId]) {
                        var newRate = parseFloat(res.rates[pId].applicable_rate) || 0;
                        $row.find('.item-rate').val(newRate.toFixed(2));
                        onQtyOrRateChange($row.find('.item-rate')[0]);
                    }
                });
            }
        });
    }

    function recalculateAll() {
        var totalQuota = 0;
        var physicalOutflow = 0;
        var totalBal = 0;
        var voucherTotal = 0;
        var distinctProducts = 0;

        var paymentType = $('#payment_type').val();
        var slipType    = $('#slip_type').val();

        $('#itemsTableBody tr.item-row').each(function() {
            var $row = $(this);
            var prodId = $row.find('.product-select').val();
            var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
            var issQty = parseInt($row.find('.item-issue-qty').val(), 10) || 0;
            var total = parseFloat($row.find('.item-total').val()) || 0;

            if (prodId !== '' && qty > 0) {
                distinctProducts++;
                totalQuota += qty;
                physicalOutflow += issQty;
                totalBal += Math.max(0, qty - issQty);
                voucherTotal += total;
            }
        });

        $('#overallQuotaQtyDisplay').text(totalQuota);
        $('#overallIssueQtyDisplay').text(physicalOutflow);
        $('#overallBalQtyDisplay').text(totalBal);
        $('#overallAmtDisplay').text('Rs. ' + voucherTotal.toFixed(2));

        $('#summaryDistinctProducts').text(distinctProducts);
        $('#summaryTotalQuota').text(totalQuota);
        $('#summaryPhysicalOutflow').text(physicalOutflow);

        // Compute Customer Charge Amount (Billed Debt)
        var customerCharge = 0.00;
        if (paymentType === 'Credit') {
            if (slipType === 'Permanent Slip') {
                var tempWasoli = parseFloat($('#temp_wasoli_amount').val()) || 0;
                customerCharge = voucherTotal + tempWasoli;
            } else if (slipType === 'Balanced Slip') {
                customerCharge = 0.00; // Zero charge for balance claims
            } else if (slipType === 'Temporary Slip') {
                customerCharge = 0.00; // Zero charge for loan chits
            }
        } else {
            customerCharge = voucherTotal;
        }

        $('#summaryChargeAmount').text('Rs. ' + customerCharge.toFixed(2));
    }

    // Modal & AJAX helpers for Balanced Slips
    function openFindBalanceSlipModal() {
        var custId = $('#customer_id').val();
        if (!custId) {
            alert('Please enter or select a registered vehicle number first to identify the customer account.');
            $('#vehicle_number').focus();
            return;
        }
        $('#modalFindBalanceSlip').modal('show');
        loadBalanceSlips();
    }

    function loadBalanceSlips() {
        var custId = $('#customer_id').val();
        var query = $('#searchBalanceSlipInput').val();
        $('#balanceSlipsLoading').removeClass('d-none');
        $('#balanceSlipsContainer').html('');

        $.getJSON('ajax-credit-slip-lookup.php', {
            action: 'find_balance_slips',
            customer_id: custId,
            slip_no: query
        }, function(res) {
            $('#balanceSlipsLoading').addClass('d-none');
            if (res.status === 'success' && res.slips && res.slips.length > 0) {
                var html = '<table class="table table-bordered table-sm table-hover text-center mb-0" style="font-size: 12px;">' +
                    '<thead class="bg-light font-weight-bold"><tr>' +
                    '<th>Slip #</th><th>Slip Date</th><th>Vehicle #</th><th>Uncollected Products</th><th>Action</th>' +
                    '</tr></thead><tbody>';

                res.slips.forEach(function(slip) {
                    var itemsSummary = '';
                    slip.items.forEach(function(it) {
                        itemsSummary += '<div><strong>' + it.product_name + '</strong>: ' + it.balance_quantity + ' bal (Orig. Rate: Rs. ' + it.original_rate.toFixed(2) + ')</div>';
                    });

                    var slipJson = encodeURIComponent(JSON.stringify(slip));
                    html += '<tr>' +
                        '<td class="font-weight-bold text-primary">' + slip.slip_no + '</td>' +
                        '<td>' + slip.slip_date + '</td>' +
                        '<td>' + slip.vehicle_number + '</td>' +
                        '<td class="text-left">' + itemsSummary + '</td>' +
                        '<td><button type="button" class="btn btn-xs btn-success font-weight-bold" onclick="selectBalancedSlip(\'' + slipJson + '\')"><i class="fas fa-check"></i> Claim</button></td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
                $('#balanceSlipsContainer').html(html);
            } else {
                $('#balanceSlipsContainer').html('<div class="alert alert-info py-2 mb-0 small"><i class="fas fa-info-circle mr-1"></i> No uncollected balance slips found for this customer.</div>');
            }
        }).fail(function() {
            $('#balanceSlipsLoading').addClass('d-none');
            $('#balanceSlipsContainer').html('<div class="alert alert-danger py-2 mb-0 small">Error loading balance slips.</div>');
        });
    }

    function selectBalancedSlip(encodedSlip) {
        var slip = JSON.parse(decodeURIComponent(encodedSlip));
        $('#ref_slip_no').val(slip.slip_no);
        $('#ref_slip_date').val(slip.slip_date);
        $('#refSlipNoText').text(slip.slip_no);
        $('#refSlipDateText').text(slip.slip_date);
        $('#balancedSlipSelectedDisplay').removeClass('d-none');

        if (slip.vehicle_number && slip.vehicle_number !== '—') {
            $('#vehicle_number').val(slip.vehicle_number);
            onCreditVehicleInput(slip.vehicle_number);
        }

        // Clear existing product rows and inject uncollected items with locked historical rates
        $('#itemsTableBody').empty();
        slip.items.forEach(function(it) {
            addNewProductRow(it.product_id, it.balance_quantity, it.balance_quantity, it.original_rate);
        });

        $('#modalFindBalanceSlip').modal('hide');
        recalculateAll();
    }

    function clearBalancedSlipRef() {
        $('#ref_slip_no').val('');
        $('#ref_slip_date').val('');
        $('#refSlipNoText').text('');
        $('#refSlipDateText').text('');
        $('#balancedSlipSelectedDisplay').addClass('d-none');
    }

    // Modal & AJAX helpers for Temp. Receive
    function openFindTempSlipModal() {
        var custId = $('#customer_id').val();
        if (!custId) {
            alert('Please enter or select a registered vehicle number first to identify the customer account.');
            $('#vehicle_number').focus();
            return;
        }
        $('#modalFindTempSlip').modal('show');
        loadTempSlips();
    }

    function loadTempSlips() {
        var custId = $('#customer_id').val();
        var query = $('#searchTempSlipInput').val();
        $('#tempSlipsLoading').removeClass('d-none');
        $('#tempSlipsContainer').html('');

        $.getJSON('ajax-credit-slip-lookup.php', {
            action: 'find_temp_slips',
            customer_id: custId,
            slip_no: query
        }, function(res) {
            $('#tempSlipsLoading').addClass('d-none');
            if (res.status === 'success' && res.slips && res.slips.length > 0) {
                var html = '<table class="table table-bordered table-sm table-hover text-center mb-0" style="font-size: 12px;">' +
                    '<thead class="bg-light font-weight-bold"><tr>' +
                    '<th>Temp Slip #</th><th>Loan Date</th><th>Vehicle #</th><th>Loaned Items</th><th>Loan Amount</th><th>Action</th>' +
                    '</tr></thead><tbody>';

                res.slips.forEach(function(slip) {
                    var itemsSummary = '';
                    slip.items.forEach(function(it) {
                        itemsSummary += '<div><strong>' + it.product_name + '</strong>: ' + it.loan_quantity + ' units @ Rs. ' + it.temp_rate.toFixed(2) + '</div>';
                    });

                    var slipJson = encodeURIComponent(JSON.stringify(slip));
                    html += '<tr>' +
                        '<td class="font-weight-bold text-primary">' + slip.slip_no + '</td>' +
                        '<td>' + slip.loan_date + '</td>' +
                        '<td>' + slip.vehicle_number + '</td>' +
                        '<td class="text-left">' + itemsSummary + '</td>' +
                        '<td class="font-weight-bold text-success">Rs. ' + slip.total_amount.toFixed(2) + '</td>' +
                        '<td><button type="button" class="btn btn-xs btn-success font-weight-bold" onclick="selectTempSlip(\'' + slipJson + '\')"><i class="fas fa-link"></i> Attach</button></td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
                $('#tempSlipsContainer').html(html);
            } else {
                $('#tempSlipsContainer').html('<div class="alert alert-info py-2 mb-0 small"><i class="fas fa-info-circle mr-1"></i> No outstanding temporary slips found for this customer.</div>');
            }
        }).fail(function() {
            $('#tempSlipsLoading').addClass('d-none');
            $('#tempSlipsContainer').html('<div class="alert alert-danger py-2 mb-0 small">Error loading temporary slips.</div>');
        });
    }

    function selectTempSlip(encodedSlip) {
        var slip = JSON.parse(decodeURIComponent(encodedSlip));
        $('#temp_slip_id').val(slip.invoice_id);
        $('#temp_wasoli_amount').val(slip.total_amount.toFixed(2));
        $('#tempSlipNoText').text(slip.slip_no);
        $('#tempSlipDateText').text(slip.loan_date);
        $('#tempSlipWasoliText').text(slip.total_amount.toFixed(2));
        $('#tempSlipSelectedDisplay').removeClass('d-none');

        $('#modalFindTempSlip').modal('hide');
        recalculateAll();
    }

    function clearTempSlipLink() {
        $('#temp_slip_id').val('');
        $('#temp_wasoli_amount').val('0.00');
        $('#tempSlipNoText').text('');
        $('#tempSlipDateText').text('');
        $('#tempSlipWasoliText').text('0.00');
        $('#tempSlipSelectedDisplay').addClass('d-none');
        recalculateAll();
    }

    function validateBeforeSubmit() {
        // Automatically prune trailing empty rows where no product is selected
        $('#itemsTableBody tr.item-row').each(function() {
            var $r = $(this);
            var prodId = $r.find('.product-select').val();
            if (!prodId && $('#itemsTableBody tr.item-row').length > 1) {
                $r.remove();
            }
        });
        reindexRows();
        recalculateAll();

        var paymentType = $('#payment_type').val();
        var slipType    = $('#slip_type').val();
        var hasValidItem = false;
        var errorMsg = '';

        $('#itemsTableBody tr.item-row').each(function(idx) {
            var $row = $(this);
            var prodId = $row.find('.product-select').val();
            var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
            var issQty = parseInt($row.find('.item-issue-qty').val(), 10) || 0;
            var selectedOpt = $row.find('.product-select option:selected');
            var stock = parseInt(selectedOpt.data('stock'), 10) || 0;

            if (prodId !== '') {
                if (qty <= 0) {
                    errorMsg = 'Row #' + (idx + 1) + ': Quota quantity must be at least 1.';
                    return false;
                }
                if (paymentType === 'Credit' && issQty > qty) {
                    errorMsg = 'Row #' + (idx + 1) + ': Issue Quantity (' + issQty + ') cannot exceed Quota Quantity (' + qty + ').';
                    return false;
                }
                if (issQty > stock) {
                    errorMsg = 'Row #' + (idx + 1) + ': Physical Issue Quantity (' + issQty + ') exceeds available stock (' + stock + ').';
                    return false;
                }
                hasValidItem = true;
            } else if ($('#itemsTableBody tr.item-row').length === 1) {
                errorMsg = 'Please select at least one product.';
                return false;
            }
        });

        if (errorMsg !== '') {
            $('#jsWarning').removeClass('d-none');
            $('#jsWarning span').html(errorMsg);
            $('html, body').animate({ scrollTop: 0 }, 'slow');
            return false;
        }

        if (!hasValidItem) {
            $('#jsWarning').removeClass('d-none');
            $('#jsWarning span').text('Please add at least one product with a valid quantity.');
            $('html, body').animate({ scrollTop: 0 }, 'slow');
            return false;
        }

        if (paymentType === 'Card') {
            if (!$('#card_machine_id').val()) {
                $('#jsWarning').removeClass('d-none');
                $('#jsWarning span').text('Please select a Card Machine / POS Terminal for Card sale.');
                $('html, body').animate({ scrollTop: 0 }, 'slow');
                return false;
            }
            if (!$('#bank_id').val()) {
                $('#jsWarning').removeClass('d-none');
                $('#jsWarning span').text('Please select a Destination Bank Account for Card sale.');
                $('html, body').animate({ scrollTop: 0 }, 'slow');
                return false;
            }
        }

        if (paymentType === 'Credit') {
            if (!$('#vehicle_number').val().trim()) {
                $('#jsWarning').removeClass('d-none');
                $('#jsWarning span').text('Please enter a vehicle registration number for Credit sale.');
                $('html, body').animate({ scrollTop: 0 }, 'slow');
                return false;
            }
            if (!$('#customer_id').val()) {
                $('#jsWarning').removeClass('d-none');
                $('#jsWarning span').text('Please enter a registered vehicle linked to an authorized Customer Account.');
                $('html, body').animate({ scrollTop: 0 }, 'slow');
                return false;
            }
            if (!$('#slip_no').val().trim()) {
                $('#jsWarning').removeClass('d-none');
                $('#jsWarning span').text('Slip / Voucher # is required for Credit sale.');
                $('html, body').animate({ scrollTop: 0 }, 'slow');
                return false;
            }
        }

        $('#jsWarning').addClass('d-none');
        return true;
    }
    </script>
</html>
