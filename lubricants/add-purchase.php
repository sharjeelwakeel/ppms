<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding lubricant purchase
check_access('items', 'add');

// Auto-migrate tbl_lubricant_purchases & tbl_lubricant_purchase_payments
$chk_pur_qty = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_purchases LIKE 'quantity'");
if ($chk_pur_qty && $col = mysqli_fetch_assoc($chk_pur_qty)) {
    if (stripos($col['Type'], 'int') === false) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_purchases MODIFY COLUMN quantity INT(11) NOT NULL DEFAULT 0");
    }
}
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_lubricant_purchase_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lubricant_purchase_id` (`purchase_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$message = '';
$preselected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

if (isset($_POST['product_id']) && isset($_POST['quantity']) && isset($_POST['purchase_price']) && isset($_POST['date'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $purchase_price = floatval($_POST['purchase_price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    
    $initial_payment_amount = floatval($_POST['initial_payment_amount'] ?? 0);
    $bank_id = intval($_POST['bank_id'] ?? 0);

    $total_cost = $quantity * $purchase_price;

    $payment_status = 'unpaid';
    if ($initial_payment_amount >= $total_cost && $total_cost > 0) {
        $payment_status = 'paid';
    } else if ($initial_payment_amount > 0) {
        $payment_status = 'in process';
    }

    mysqli_begin_transaction($connection);
    try {
        $query = "INSERT INTO tbl_lubricant_purchases (product_id, quantity, purchase_price, date, payment_status) 
                  VALUES ('$product_id', '$quantity', '$purchase_price', '$date', '$payment_status')";
        $ins_pur = mysqli_query($connection, $query);
        if (!$ins_pur) {
            throw new Exception(mysqli_error($connection));
        }
        $purchase_id = mysqli_insert_id($connection);

        // If an initial partial or full payment was specified
        if ($initial_payment_amount > 0 && $bank_id > 0) {
            $insert_pay = "INSERT INTO tbl_lubricant_purchase_payments (purchase_id, date, amount, bank_id) 
                           VALUES ('$purchase_id', '$date', '$initial_payment_amount', '$bank_id')";
            $ins_pay_res = mysqli_query($connection, $insert_pay);
            if (!$ins_pay_res) {
                throw new Exception(mysqli_error($connection));
            }
        }

        mysqli_commit($connection);
        header('Location: purchases-list.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error saving purchase: ' . $e->getMessage() . '</div>';
    }
}

// Fetch products
$products_sql = "SELECT id, name FROM tbl_lubricant_products WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC";
$products_result = mysqli_query($connection, $products_sql);

// Fetch active banks for payment disbursement
$banks_sql = "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC";
$banks_result = mysqli_query($connection, $banks_sql);
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
        .payment-section {
            background: #f8fafc;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            border-radius: 4px;
        }
		</style>
		<title>PPMS - Add Purchase</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-purchase.php" method="POST" id="purchaseForm">
					<h4 class="mb-4"><i class="fas fa-boxes mr-2 text-primary"></i>Add Stock Purchase (Inflow)</h4>
                    <?php echo $message; ?>
					<div class="card mb-4">
						<div class="card-body">
							<h6 class="font-weight-bold text-muted mb-4"><i class="fas fa-info-circle mr-1"></i>Purchase Details</h6>
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Product</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="product_id" class="form-control" required>
                                                <option value="">Select Product</option>
                                                <?php 
                                                if ($products_result && mysqli_num_rows($products_result) > 0) {
                                                    while ($product = mysqli_fetch_assoc($products_result)) {
                                                        $selected = ($product['id'] == $preselected_product_id) ? 'selected' : '';
                                                        echo '<option value="' . $product['id'] . '" ' . $selected . '>' . htmlspecialchars($product['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Quantity</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="1" min="1" name="quantity" id="quantity" class="form-control" placeholder="e.g. 10" oninput="calculateTotal()" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Purchase Price</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="purchase_price" id="purchasePrice" class="form-control" placeholder="0.00" oninput="calculateTotal()" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Total Cost</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" id="totalCostDisplay" class="form-control font-weight-bold" readonly style="background:#e8eaf6; color:var(--primary-color);" value="0.00">
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
										</div>
									</div>
								</div>
							</div>

                            <hr class="my-4">

                            <!-- Optional Initial Payment Section -->
                            <div class="payment-section">
                                <h6 class="font-weight-bold text-dark mb-3"><i class="fas fa-money-bill-wave mr-2 text-success"></i>Initial Payment (Optional)</h6>
                                <p class="text-muted small mb-3">You can disburse an initial partial or full payment now from a bank account, or leave blank to make payments later in installments.</p>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group row mb-md-0">
                                            <label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Bank Account</label>
                                            <div class="col-lg-9 col-md-7 col-sm-8">
                                                <select name="bank_id" id="bankId" class="form-control">
                                                    <option value="">-- No Initial Payment --</option>
                                                    <?php 
                                                    if ($banks_result && mysqli_num_rows($banks_result) > 0) {
                                                        while ($bank = mysqli_fetch_assoc($banks_result)) {
                                                            echo '<option value="' . $bank['id'] . '">' . htmlspecialchars($bank['name'] . ' (' . $bank['account_number'] . ')') . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group row mb-0">
                                            <label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Paid Amount (Rs.)</label>
                                            <div class="col-lg-9 col-md-7 col-sm-8">
                                                <input type="number" step="0.01" min="0" name="initial_payment_amount" id="initialPayment" class="form-control" placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Save Purchase</button>
                        <a href="purchases-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script>
    function calculateTotal() {
        var qty = parseInt($('#quantity').val(), 10) || 0;
        var price = parseFloat($('#purchasePrice').val()) || 0;
        var total = qty * price;
        $('#totalCostDisplay').val(total.toFixed(2));
    }
    </script>
</html>
