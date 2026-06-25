<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: purchases-list.php');
    exit;
}

$message = '';

// Handle Purchase Details Update
if (isset($_POST['update_purchase'])) {
    $item_id = mysqli_real_escape_string($connection, $_POST['item_id']);
    $quantity = mysqli_real_escape_string($connection, $_POST['quantity']);
    $price = mysqli_real_escape_string($connection, $_POST['price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $route = mysqli_real_escape_string($connection, $_POST['route']);
    $invoice_number = mysqli_real_escape_string($connection, $_POST['invoice_number']);
    $carriage_invoice_number = mysqli_real_escape_string($connection, $_POST['carriage_invoice_number']);

    mysqli_begin_transaction($connection);
    try {
        // Fetch current payment sum to update status if quantity/price changes
        $pay_sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_purchase_payments WHERE purchase_id = '$id' AND deleted_at IS NULL");
        $pay_sum_row = mysqli_fetch_assoc($pay_sum_res);
        $total_paid = floatval($pay_sum_row['total_paid'] ?? 0);
        $total_cost = floatval($quantity) * floatval($price);

        $new_status = 'unpaid';
        if ($total_paid >= $total_cost) {
            $new_status = 'paid';
        } else if ($total_paid > 0) {
            $new_status = 'in process';
        }

        $query = "UPDATE tbl_purchases SET 
                    item_id='$item_id', 
                    quantity='$quantity', 
                    price='$price', 
                    date='$date', 
                    route='$route', 
                    invoice_number='$invoice_number', 
                    carriage_invoice_number='$carriage_invoice_number',
                    payment_status='$new_status'
                  WHERE id='$id'";
        mysqli_query($connection, $query);
        mysqli_commit($connection);
        $message = '<div class="alert alert-success">Purchase details updated successfully.</div>';
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error updating purchase details: ' . $e->getMessage() . '</div>';
    }
}

// Handle Adding a Partial Payment
if (isset($_POST['add_payment'])) {
    $payment_date = mysqli_real_escape_string($connection, $_POST['payment_date']);
    $payment_amount = floatval($_POST['payment_amount']);
    $bank_id = intval($_POST['bank_id']);
    $tank_id = intval($_POST['tank_id']);

    mysqli_begin_transaction($connection);
    try {
        // 1. Insert Payment
        $insert_payment = "INSERT INTO tbl_purchase_payments (purchase_id, date, amount, bank_id, tank_id) 
                           VALUES ('$id', '$payment_date', '$payment_amount', '$bank_id', '$tank_id')";
        mysqli_query($connection, $insert_payment);

        // 2. Fetch Purchase details for recalculating status
        $purch_res = mysqli_query($connection, "SELECT quantity, price FROM tbl_purchases WHERE id = '$id' LIMIT 1");
        $purch_row = mysqli_fetch_assoc($purch_res);
        $total_cost = floatval($purch_row['quantity']) * floatval($purch_row['price']);

        // 3. Fetch Sum of Payments
        $sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_purchase_payments WHERE purchase_id = '$id' AND deleted_at IS NULL");
        $sum_row = mysqli_fetch_assoc($sum_res);
        $total_paid = floatval($sum_row['total_paid'] ?? 0);

        // 4. Update Purchase Status
        $new_status = 'unpaid';
        if ($total_paid >= $total_cost) {
            $new_status = 'paid';
        } else if ($total_paid > 0) {
            $new_status = 'in process';
        }

        mysqli_query($connection, "UPDATE tbl_purchases SET payment_status = '$new_status' WHERE id = '$id'");
        mysqli_commit($connection);
        $message = '<div class="alert alert-success">Payment of ' . number_format($payment_amount, 2) . ' recorded successfully.</div>';
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error recording payment: ' . $e->getMessage() . '</div>';
    }
}

// Fetch Purchase Record
$sql = "SELECT p.*, i.name as item_name FROM tbl_purchases p LEFT JOIN tbl_items i ON p.item_id = i.id WHERE p.id='$id' AND p.deleted_at IS NULL";
$result = mysqli_query($connection, $sql);
$purchase = mysqli_fetch_assoc($result);

if (!$purchase) {
    header('Location: purchases-list.php');
    exit;
}

// Fetch active banks for dropdown
$banks_sql = "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC";
$banks_result = mysqli_query($connection, $banks_sql);

// Fetch tanks for dropdown
$tanks_sql = "SELECT id, tank_name FROM tbl_tanks ORDER BY tank_name ASC";
$tanks_result = mysqli_query($connection, $tanks_sql);

// Fetch items for details dropdown
$items_sql = "SELECT id, name FROM tbl_items ORDER BY name ASC";
$items_result = mysqli_query($connection, $items_sql);

// Fetch Payment History
$payments_sql = "SELECT pay.*, b.name as bank_name, b.account_number as bank_account, t.tank_name 
                 FROM tbl_purchase_payments pay
                 LEFT JOIN tbl_banks b ON pay.bank_id = b.id
                 LEFT JOIN tbl_tanks t ON pay.tank_id = t.id
                 WHERE pay.purchase_id = '$id' AND pay.deleted_at IS NULL
                 ORDER BY pay.id DESC";
$payments_result = mysqli_query($connection, $payments_sql);

// Calculate Totals for Summary
$total_cost = $purchase['quantity'] * $purchase['price'];
$payments_sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_purchase_payments WHERE purchase_id = '$id' AND deleted_at IS NULL");
$payments_sum_row = mysqli_fetch_assoc($payments_sum_res);
$total_paid = floatval($payments_sum_row['total_paid'] ?? 0);
$remaining_amount = $total_cost - $total_paid;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{
			margin-top:20px;
		}
		.txt-center{
			text-align:center;
		}
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .summary-card {
            border-left: 5px solid var(--primary-color);
        }
        .status-badge {
            font-size: 1.1rem;
            padding: 8px 12px;
        }
		</style>
		<title>PPMS - Edit Purchase & Payments</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4>Edit Purchase & Payments #<?php echo $purchase['id']; ?></h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="purchases-list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
					</div>
				</div>
                
                <?php echo $message; ?>

				<!-- Top Section: Summary & Purchase Details -->
				<div class="row mb-5">
					<div class="col-lg-8">
						<div class="card h-100">
							<div class="card-header bg-light">
								<h5 class="card-title mb-0"><i class="fas fa-shopping-cart text-primary mr-2"></i>Purchase Information</h5>
							</div>
							<div class="card-body">
								<form action="edit-purchase.php?id=<?php echo $id; ?>" method="POST">
									<div class="row">
										<div class="col-md-6">
											<div class="form-group">
												<label class="font-weight-bold">Item</label>
												<select name="item_id" class="form-control" required>
													<option value="">Select Item</option>
													<?php 
													if (mysqli_num_rows($items_result) > 0) {
														while ($item = mysqli_fetch_assoc($items_result)) {
															$selected = ($purchase['item_id'] == $item['id']) ? 'selected' : '';
															echo '<option value="' . $item['id'] . '" ' . $selected . '>' . htmlspecialchars($item['name']) . '</option>';
														}
													}
													?>
												</select>
											</div>
											<div class="form-group">
												<label class="font-weight-bold">Quantity</label>
												<input type="number" step="0.01" name="quantity" class="form-control" value="<?php echo htmlspecialchars($purchase['quantity']); ?>" required>
											</div>
											<div class="form-group">
												<label class="font-weight-bold">Unit Price</label>
												<input type="number" step="0.01" name="price" class="form-control" value="<?php echo htmlspecialchars($purchase['price']); ?>" required>
											</div>
											<div class="form-group">
												<label class="font-weight-bold">Date</label>
												<input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($purchase['date']); ?>" required>
											</div>
										</div>
										<div class="col-md-6">
											<div class="form-group">
												<label class="font-weight-bold">Route</label>
												<input type="text" name="route" class="form-control" value="<?php echo htmlspecialchars($purchase['route']); ?>" required>
											</div>
											<div class="form-group">
												<label class="font-weight-bold">Invoice Number</label>
												<input type="text" name="invoice_number" class="form-control" value="<?php echo htmlspecialchars($purchase['invoice_number']); ?>" required>
											</div>
											<div class="form-group">
												<label class="font-weight-bold">Carriage Invoice Number</label>
												<input type="text" name="carriage_invoice_number" class="form-control" value="<?php echo htmlspecialchars($purchase['carriage_invoice_number']); ?>" required>
											</div>
											<div class="form-group text-right" style="margin-top: 35px;">
												<button type="submit" name="update_purchase" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update Details</button>
											</div>
										</div>
									</div>
								</form>
							</div>
						</div>
					</div>
					<div class="col-lg-4">
						<div class="card summary-card h-100">
							<div class="card-header bg-light">
								<h5 class="card-title mb-0"><i class="fas fa-calculator text-primary mr-2"></i>Payment Summary</h5>
							</div>
							<div class="card-body d-flex flex-column justify-content-between">
								<div>
									<div class="d-flex justify-content-between mb-3 border-bottom pb-2">
										<span class="text-muted">Total Cost:</span>
										<span class="font-weight-bold" style="font-size: 1.15rem;"><?php echo number_format($total_cost, 2); ?></span>
									</div>
									<div class="d-flex justify-content-between mb-3 border-bottom pb-2 text-success">
										<span>Total Paid:</span>
										<span class="font-weight-bold" style="font-size: 1.15rem;"><?php echo number_format($total_paid, 2); ?></span>
									</div>
									<div class="d-flex justify-content-between mb-4 border-bottom pb-2 text-danger">
										<span>Remaining Balance:</span>
										<span class="font-weight-bold" style="font-size: 1.15rem;"><?php echo number_format($remaining_amount, 2); ?></span>
									</div>
								</div>
								<div class="text-center pt-3 border-top">
									<div class="text-muted mb-2">Payment Status</div>
									<?php 
									$statusClass = 'badge-danger';
									if ($purchase['payment_status'] == 'paid') {
										$statusClass = 'badge-success';
									} else if ($purchase['payment_status'] == 'in process') {
										$statusClass = 'badge-warning';
									}
									?>
									<span class="badge status-badge <?php echo $statusClass; ?>"><?php echo strtoupper(htmlspecialchars($purchase['payment_status'])); ?></span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Bottom Section: Payments Log & Add Payment Card -->
				<div class="row">
					<div class="col-lg-7">
						<div class="card">
							<div class="card-header bg-light d-flex justify-content-between align-items-center">
								<h5 class="card-title mb-0"><i class="fas fa-history text-primary mr-2"></i>Payment History</h5>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-striped table-bordered mb-0">
										<thead>
											<tr>
												<th>Date</th>
												<th>Amount</th>
												<th>Bank Source</th>
												<th>Tank Deposited</th>
												<th>Delete</th>
											</tr>
										</thead>
										<tbody>
											<?php 
											if (mysqli_num_rows($payments_result) > 0) {
												while ($pay_row = mysqli_fetch_assoc($payments_result)) {
													echo '
													<tr>
														<td>' . date("d-m-Y", strtotime($pay_row['date'])) . '</td>
														<td class="font-weight-bold text-success">' . number_format($pay_row['amount'], 2) . '</td>
														<td>' . htmlspecialchars($pay_row['bank_name']) . '<br><small class="text-muted">' . htmlspecialchars($pay_row['bank_account']) . '</small></td>
														<td>' . htmlspecialchars($pay_row['tank_name'] ?? 'N/A') . '</td>
														<td><a class="btn btn-link text-danger p-0" onclick="deletePayment(' . $pay_row['id'] . ')"><i class="fas fa-trash-alt"></i></a></td>
													</tr>';
												}
											} else {
												echo '<tr><td colspan="5" class="text-center text-muted">No payments recorded yet for this purchase.</td></tr>';
											}
											?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
					<div class="col-lg-5">
						<div class="card">
							<div class="card-header bg-light">
								<h5 class="card-title mb-0"><i class="fas fa-plus text-primary mr-2"></i>Add Partial Payment</h5>
							</div>
							<div class="card-body">
								<form action="edit-purchase.php?id=<?php echo $id; ?>" method="POST">
									<div class="form-group row">
										<label class="col-sm-4 col-form-label font-weight-bold">Date</label>
										<div class="col-sm-8">
											<input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-sm-4 col-form-label font-weight-bold">Amount</label>
										<div class="col-sm-8">
											<input type="number" step="0.01" name="payment_amount" class="form-control" placeholder="0.00" min="0.01" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-sm-4 col-form-label font-weight-bold">Bank Source</label>
										<div class="col-sm-8">
											<select name="bank_id" class="form-control" required>
												<option value="">Select Bank Master</option>
												<?php 
												if (mysqli_num_rows($banks_result) > 0) {
													mysqli_data_seek($banks_result, 0);
													while ($bank_row = mysqli_fetch_assoc($banks_result)) {
														echo '<option value="' . $bank_row['id'] . '">' . htmlspecialchars($bank_row['name']) . ' (' . htmlspecialchars($bank_row['account_number']) . ')</option>';
													}
												}
												?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-sm-4 col-form-label font-weight-bold">Tank Deposited</label>
										<div class="col-sm-8">
											<select name="tank_id" class="form-control" required>
												<option value="">Select Tank</option>
												<?php 
												if (mysqli_num_rows($tanks_result) > 0) {
													mysqli_data_seek($tanks_result, 0);
													while ($tank_row = mysqli_fetch_assoc($tanks_result)) {
														echo '<option value="' . $tank_row['id'] . '">' . htmlspecialchars($tank_row['tank_name']) . '</option>';
													}
												}
												?>
											</select>
										</div>
									</div>
									<div class="txt-center pt-2">
										<button type="submit" name="add_payment" class="btn btn-primary btn-block"><i class="fas fa-check-circle mr-1"></i> Record Payment</button>
									</div>
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script>
	function deletePayment(payId){
		if(confirm('Are you sure you want to remove this payment?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletepurchasepayment.php",
				data: {id: payId},
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}
	</script>
</html>
