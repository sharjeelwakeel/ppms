<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

$message = '';
if (isset($_POST['product_id']) && isset($_POST['quantity']) && isset($_POST['purchase_price']) && isset($_POST['date']) && isset($_POST['payment_status'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = floatval($_POST['quantity']);
    $purchase_price = floatval($_POST['purchase_price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $payment_status = mysqli_real_escape_string($connection, $_POST['payment_status']);

    $query = "INSERT INTO tbl_lubricant_purchases (product_id, quantity, purchase_price, date, payment_status) 
              VALUES ('$product_id', '$quantity', '$purchase_price', '$date', '$payment_status')";
    
    if (mysqli_query($connection, $query)) {
        header('Location: purchases-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving purchase: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch products
$products_sql = "SELECT id, name FROM tbl_lubricant_products ORDER BY name ASC";
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
		<title>PPMS - Add Purchase</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-purchase.php" method="POST">
					<h4 class="mb-5">Add Stock Purchase (Inflow)</h4>
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
                                                        echo '<option value="' . $product['id'] . '">' . htmlspecialchars($product['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Quantity</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0.01" name="quantity" class="form-control" placeholder="0.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Purchase Price</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="purchase_price" class="form-control" placeholder="0.00" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Payment Status</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="payment_status" class="form-control" required>
												<option value="paid">Paid</option>
												<option value="unpaid">Unpaid</option>
											</select>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top">Save Purchase</button>
                        <a href="purchases-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
