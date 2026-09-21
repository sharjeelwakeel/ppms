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

// Self-healing migration for tbl_lubricant_sale_invoices & tbl_lubricant_sales
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_lubricant_sale_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(64) NOT NULL,
  `date` DATE NOT NULL,
  `payment_type` VARCHAR(32) NOT NULL DEFAULT 'Cash',
  `details` TEXT DEFAULT NULL,
  `total_items` INT(11) NOT NULL DEFAULT 0,
  `total_quantity` INT(11) NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_date` (`date`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$chk_inv_id = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sales LIKE 'invoice_id'");
if ($chk_inv_id && mysqli_num_rows($chk_inv_id) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sales ADD COLUMN invoice_id INT(11) DEFAULT NULL AFTER id, ADD KEY `idx_invoice_id` (`invoice_id`)");
}
$chk_inv_no = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sales LIKE 'invoice_no'");
if ($chk_inv_no && mysqli_num_rows($chk_inv_no) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sales ADD COLUMN invoice_no VARCHAR(64) DEFAULT NULL AFTER invoice_id, ADD KEY `idx_invoice_no` (`invoice_no`)");
}
$chk_cm = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sale_invoices LIKE 'card_machine_id'");
if ($chk_cm && mysqli_num_rows($chk_cm) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sale_invoices ADD COLUMN card_machine_id INT(11) DEFAULT NULL AFTER payment_type, ADD KEY `idx_card_machine_id` (`card_machine_id`)");
}
$chk_bank = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sale_invoices LIKE 'bank_id'");
if ($chk_bank && mysqli_num_rows($chk_bank) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sale_invoices ADD COLUMN bank_id INT(11) DEFAULT NULL AFTER card_machine_id, ADD KEY `idx_bank_id` (`bank_id`)");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sale') {
    $sale_date    = mysqli_real_escape_string($connection, $_POST['date'] ?? date('Y-m-d'));
    $payment_type = mysqli_real_escape_string($connection, $_POST['payment_type'] ?? 'Cash');
    if ($payment_type !== 'Card') {
        $payment_type = 'Cash';
    }
    $card_machine_id = ($payment_type === 'Card') ? intval($_POST['card_machine_id'] ?? 0) : null;
    $bank_id         = ($payment_type === 'Card') ? intval($_POST['bank_id'] ?? 0) : null;
    $invoice_no   = mysqli_real_escape_string($connection, trim($_POST['invoice_no'] ?? $default_invoice_no));
    if (empty($invoice_no)) {
        $invoice_no = $default_invoice_no;
    }
    $details      = mysqli_real_escape_string($connection, trim($_POST['details'] ?? ''));
    $user_id      = intval($_SESSION['loggedInUser'] ?? 0);

    $prod_ids = $_POST['product_id'] ?? [];
    $qtys     = $_POST['quantity'] ?? [];
    $rates    = $_POST['rate'] ?? [];

    $valid_lines = [];
    $prod_aggregated_qty = [];

    for ($i = 0; $i < count($prod_ids); $i++) {
        $pId = intval($prod_ids[$i] ?? 0);
        $qVal = intval($qtys[$i] ?? 0);
        $rVal = floatval($rates[$i] ?? 0);

        if ($pId > 0 && $qVal > 0) {
            $line_amt = $qVal * $rVal;
            $valid_lines[] = [
                'product_id' => $pId,
                'quantity'   => $qVal,
                'rate'       => $rVal,
                'amount'     => $line_amt
            ];

            if (!isset($prod_aggregated_qty[$pId])) {
                $prod_aggregated_qty[$pId] = 0;
            }
            $prod_aggregated_qty[$pId] += $qVal;
        }
    }

    if (empty($valid_lines)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please add at least one product with a valid quantity greater than zero.</div>';
    } elseif ($payment_type === 'Card' && (empty($card_machine_id) || $card_machine_id <= 0)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please select a valid Card Machine / POS Terminal.</div>';
    } elseif ($payment_type === 'Card' && (empty($bank_id) || $bank_id <= 0)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Please select a valid Destination Bank Account.</div>';
    } else {
        // Verify server-side stock for each product
        $stock_error = '';
        foreach ($prod_aggregated_qty as $chkPid => $reqQty) {
            $stock_sql = "
                SELECT p.name,
                    (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = $chkPid AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) -
                     COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = $chkPid AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0)) AS avail_stock
                FROM tbl_lubricant_products p
                WHERE p.id = $chkPid
            ";
            $stk_res = mysqli_query($connection, $stock_sql);
            if ($stk_res && $stk_row = mysqli_fetch_assoc($stk_res)) {
                $avail = intval($stk_row['avail_stock']);
                if ($reqQty > $avail) {
                    $stock_error = "Insufficient stock for product <strong>" . htmlspecialchars($stk_row['name']) . "</strong>. Available: " . number_format($avail, 0) . " units. Requested: " . number_format($reqQty, 0) . " units.";
                    break;
                }
            }
        }

        if (!empty($stock_error)) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>' . $stock_error . '</div>';
        } else {
            // Compute invoice totals
            $total_items    = count($valid_lines);
            $total_quantity = array_sum(array_column($valid_lines, 'quantity'));
            $total_amount   = array_sum(array_column($valid_lines, 'amount'));

            mysqli_begin_transaction($connection);
            try {
                $cm_sql = ($card_machine_id > 0) ? "'$card_machine_id'" : "NULL";
                $b_sql  = ($bank_id > 0) ? "'$bank_id'" : "NULL";

                // 1. Insert master invoice
                $ins_inv = "INSERT INTO tbl_lubricant_sale_invoices 
                            (invoice_no, date, payment_type, card_machine_id, bank_id, details, total_items, total_quantity, total_amount, created_by) 
                            VALUES 
                            ('$invoice_no', '$sale_date', '$payment_type', $cm_sql, $b_sql, '$details', '$total_items', '$total_quantity', '$total_amount', '$user_id')";
                $inv_ok = mysqli_query($connection, $ins_inv);
                if (!$inv_ok) {
                    throw new Exception("Error saving sale invoice: " . mysqli_error($connection));
                }
                $invoice_id = mysqli_insert_id($connection);

                // 2. Insert itemized product lines into tbl_lubricant_sales
                foreach ($valid_lines as $vl) {
                    $l_pid  = $vl['product_id'];
                    $l_qty  = $vl['quantity'];
                    $l_rate = $vl['rate'];
                    $l_amt  = $vl['amount'];

                    $ins_line = "INSERT INTO tbl_lubricant_sales 
                                 (invoice_id, invoice_no, product_id, quantity, rate, amount, payment_type, details, date) 
                                 VALUES 
                                 ('$invoice_id', '$invoice_no', '$l_pid', '$l_qty', '$l_rate', '$l_amt', '$payment_type', '$details', '$sale_date')";
                    $line_ok = mysqli_query($connection, $ins_line);
                    if (!$line_ok) {
                        throw new Exception("Error saving product sale line: " . mysqli_error($connection));
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

// Fetch active products with available stock and rate tiers
$products_sql = "
    SELECT p.id, p.name, p.price,
           COALESCE(p.cash_rate, p.price) AS cash_rate,
           COALESCE(p.credit_rate, p.price) AS credit_rate,
           c.name AS category_name,
           (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0) -
            COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')), 0)) AS avail_stock
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
            padding: 6px 6px !important;
            font-size: 13px;
        }
        .table-compact th, .table-compact td {
            padding: 4px 6px !important;
            vertical-align: middle !important;
            font-size: 13px;
        }
        .form-control-compact {
            height: 31px !important;
            padding: 2px 8px !important;
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
		</style>
		<title>PPMS - Add Multi-Product Sale</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container-fluid px-3 pt-3 pb-3">
				<form action="add-sale.php" method="POST" id="multiSaleForm" onsubmit="return validateBeforeSubmit();">
                    <input type="hidden" name="action" value="save_sale">

					<div class="row mb-2 align-items-center">
						<div class="col-md-6">
							<h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-cart-plus mr-2 text-primary"></i>Add Multi-Product Sale (Outflow)</h5>
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
								<div class="col-md-3">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-calendar-day mr-1 text-primary"></i> Sale Date <span class="text-danger">*</span></label>
										<input type="date" name="date" class="form-control form-control-sm form-control-compact font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-credit-card mr-1 text-info"></i> Payment Type <span class="text-danger">*</span></label>
										<select name="payment_type" id="payment_type" class="form-control form-control-sm form-control-compact font-weight-bold" onchange="onPaymentTypeChange()" required>
											<option value="Cash">Cash</option>
											<option value="Card">Card</option>
										</select>
									</div>
								</div>
								<div class="col-md-3">
									<div class="form-group mb-1">
										<label class="header-card-label"><i class="fas fa-hashtag mr-1 text-secondary"></i> Invoice / Receipt # <span class="text-danger">*</span></label>
										<input type="text" name="invoice_no" id="invoice_no" class="form-control form-control-sm form-control-compact font-weight-bold text-primary" value="<?php echo htmlspecialchars($default_invoice_no); ?>" required>
									</div>
								</div>
								<div class="col-md-3">
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
											<th style="width: 35px;">#</th>
											<th style="min-width: 240px; text-align: left;">Product Name</th>
											<th style="width: 110px;">Avail. Stock</th>
											<th style="width: 95px;">Quantity</th>
											<th style="width: 115px;">Rate (Rs.)</th>
											<th style="width: 130px;">Line Total (Rs.)</th>
											<th style="width: 45px;">Action</th>
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
											<td colspan="3" class="text-right font-weight-bold align-middle py-1" style="font-size: 13px;">
												<i class="fas fa-calculator mr-1 text-primary"></i> Overall Totals:
											</td>
											<td class="align-middle py-1">
												<span id="overallQtyDisplay" class="text-primary font-weight-bold" style="font-size: 14px;">0</span> <span class="small text-muted">units</span>
											</td>
											<td class="align-middle text-muted small text-right py-1">
												Grand Total:
											</td>
											<td class="align-middle text-right py-1">
												<span id="overallAmtDisplay" class="text-success font-weight-bold" style="font-size: 15px;">Rs. 0.00</span>
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
								<div class="col-6 col-md-3 text-left mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 11px; letter-spacing: 0.5px;">Distinct Products</span>
									<h5 class="mb-0 font-weight-bold" id="summaryDistinctProducts">0</h5>
								</div>
								<div class="col-6 col-md-3 text-left mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 11px; letter-spacing: 0.5px;">Total Units</span>
									<h5 class="mb-0 font-weight-bold" id="summaryTotalUnits">0</h5>
								</div>
								<div class="col-6 col-md-3 text-left text-md-center mb-1 mb-md-0">
									<span class="text-white-50 text-uppercase d-block" style="font-size: 11px; letter-spacing: 0.5px;">Grand Total</span>
									<h4 class="mb-0 font-weight-bold text-warning" id="summaryGrandTotal">Rs. 0.00</h4>
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

    $(document).ready(function() {
        recalculateAll();
    });

    function addNewProductRow() {
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

        $('#itemsTableBody').append(html);
        reindexRows();
        recalculateAll();
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
            var cashRate = parseFloat(selectedOpt.data('cash-rate')) || 0;
            var stock = parseInt(selectedOpt.data('stock'), 10) || 0;

            $row.find('.item-rate').val(cashRate.toFixed(2));

            var stockBadgeClass = (stock > 0) ? 'badge-success' : 'badge-danger';
            $row.find('.stock-display').html('<span class="badge stock-pill ' + stockBadgeClass + '">' + stock + ' units</span>');

            if ($row.find('.item-qty').val() === '') {
                $row.find('.item-qty').val(1);
            }

            // AUTO-EXPANSION: If the user selected a product in the last row, automatically append a fresh row!
            if ($row.is(':last-child')) {
                addNewProductRow();
            }
        } else {
            $row.find('.item-rate').val('');
            $row.find('.stock-display').html('<span class="text-muted small">—</span>');
            $row.find('.item-total').val('0.00');
        }

        onQtyOrRateChange(selectElem);
    }

    function onQtyOrRateChange(inputElem) {
        var $row = $(inputElem).closest('tr');
        var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
        var rate = parseFloat($row.find('.item-rate').val()) || 0;
        var total = qty * rate;
        $row.find('.item-total').val(total.toFixed(2));

        // Check stock warning for this row
        var selectedOpt = $row.find('.product-select option:selected');
        if (selectedOpt.val() !== '') {
            var stock = parseInt(selectedOpt.data('stock'), 10) || 0;
            if (qty > stock) {
                $row.find('.item-qty').addClass('is-invalid');
                $row.find('.stock-display').html('<span class="badge stock-pill badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Only ' + stock + '</span>');
            } else {
                $row.find('.item-qty').removeClass('is-invalid');
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
            $('#card_machine_id').prop('required', true);
            $('#bank_id').prop('required', true);
        } else {
            $('#cardDetailsRow').addClass('d-none');
            $('#card_machine_id').prop('required', false).val('');
            $('#bank_id').prop('required', false).val('');
        }
    }

    function recalculateAll() {
        var overallQty = 0;
        var grandTotal = 0;
        var distinctProducts = 0;

        $('#itemsTableBody tr.item-row').each(function() {
            var $row = $(this);
            var prodId = $row.find('.product-select').val();
            var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
            var total = parseFloat($row.find('.item-total').val()) || 0;

            if (prodId !== '' && qty > 0) {
                distinctProducts++;
                overallQty += qty;
                grandTotal += total;
            }
        });

        $('#overallQtyDisplay').text(overallQty);
        $('#overallAmtDisplay').text('Rs. ' + grandTotal.toFixed(2));

        $('#summaryDistinctProducts').text(distinctProducts);
        $('#summaryTotalUnits').text(overallQty);
        $('#summaryGrandTotal').text('Rs. ' + grandTotal.toFixed(2));
    }

    function validateBeforeSubmit() {
        // Automatically prune trailing empty rows where no product is selected (Pillar 5)
        $('#itemsTableBody tr.item-row').each(function() {
            var $r = $(this);
            var prodId = $r.find('.product-select').val();
            if (!prodId && $('#itemsTableBody tr.item-row').length > 1) {
                $r.remove();
            }
        });
        reindexRows();
        recalculateAll();

        var hasValidItem = false;
        var errorMsg = '';

        $('#itemsTableBody tr.item-row').each(function(idx) {
            var $row = $(this);
            var prodId = $row.find('.product-select').val();
            var qty = parseInt($row.find('.item-qty').val(), 10) || 0;
            var selectedOpt = $row.find('.product-select option:selected');
            var stock = parseInt(selectedOpt.data('stock'), 10) || 0;

            if (prodId !== '') {
                if (qty <= 0) {
                    errorMsg = 'Row #' + (idx + 1) + ': Quantity must be at least 1.';
                    return false;
                }
                if (qty > stock) {
                    errorMsg = 'Row #' + (idx + 1) + ': Quantity (' + qty + ') exceeds available stock (' + stock + ').';
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

        if ($('#payment_type').val() === 'Card') {
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

        $('#jsWarning').addClass('d-none');
        return true;
    }
    </script>
</html>
