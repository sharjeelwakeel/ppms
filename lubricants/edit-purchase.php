<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing lubricant purchase
check_access('items', 'edit');

// Auto-migrate tbl_lubricant_purchases: ensure quantity is integer
$chk_pur_qty = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_purchases LIKE 'quantity'");
if ($chk_pur_qty && $col = mysqli_fetch_assoc($chk_pur_qty)) {
    if (stripos($col['Type'], 'int') === false) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_purchases MODIFY COLUMN quantity INT(11) NOT NULL DEFAULT 0");
    }
}

$message = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $purchase_price = floatval($_POST['purchase_price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $payment_status = mysqli_real_escape_string($connection, $_POST['payment_status']);

    $query = "UPDATE tbl_lubricant_purchases SET 
              product_id='$product_id', 
              quantity='$quantity', 
              purchase_price='$purchase_price', 
              date='$date', 
              payment_status='$payment_status' 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        header('Location: purchases-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating purchase: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch current details
$sql = "SELECT * FROM tbl_lubricant_purchases WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$purchase = mysqli_fetch_assoc($result);

if (!$purchase) {
    header('Location: purchases-list.php');
    exit;
}

// Fetch products
$products_sql = "SELECT id, name FROM tbl_lubricant_products WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC";
$products_result = mysqli_query($connection, $products_sql);
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
		</style>
		<title>PPMS - Edit Purchase</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-purchase.php" method="POST">
					<input type="hidden" name="id" value="<?php echo $purchase['id']; ?>">
					<h4 class="mb-5"><i class="fas fa-boxes mr-2 text-primary"></i>Edit Stock Purchase</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
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
                                                        $selected = ($product['id'] == $purchase['product_id']) ? 'selected' : '';
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
											<input type="number" step="1" min="1" name="quantity" class="form-control" placeholder="e.g. 10" value="<?php echo intval($purchase['quantity']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Purchase Price</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="purchase_price" class="form-control" placeholder="0.00" value="<?php echo htmlspecialchars($purchase['purchase_price']); ?>" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($purchase['date']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Payment Status</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="payment_status" class="form-control" required>
												<option value="Paid" <?php echo (strcasecmp($purchase['payment_status'], 'Paid') == 0) ? 'selected' : ''; ?>>Paid</option>
												<option value="Unpaid" <?php echo (strcasecmp($purchase['payment_status'], 'Unpaid') == 0) ? 'selected' : ''; ?>>Unpaid</option>
											</select>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Update Purchase</button>
                        <a href="purchases-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
