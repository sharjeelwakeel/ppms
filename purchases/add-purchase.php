<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

$message = '';
if (
    isset($_POST['item_id']) && 
    isset($_POST['quantity']) && 
    isset($_POST['price']) && 
    isset($_POST['date']) && 
    isset($_POST['route']) && 
    isset($_POST['invoice_number']) && 
    isset($_POST['carriage_invoice_number'])
) {
    $item_id = mysqli_real_escape_string($connection, $_POST['item_id']);
    $quantity = mysqli_real_escape_string($connection, $_POST['quantity']);
    $price = mysqli_real_escape_string($connection, $_POST['price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $route = mysqli_real_escape_string($connection, $_POST['route']);
    $invoice_number = mysqli_real_escape_string($connection, $_POST['invoice_number']);
    $carriage_invoice_number = mysqli_real_escape_string($connection, $_POST['carriage_invoice_number']);

    mysqli_begin_transaction($connection);
    try {
        $query = "INSERT INTO tbl_purchases (item_id, quantity, price, date, route, invoice_number, carriage_invoice_number, payment_status) 
                  VALUES ('$item_id', '$quantity', '$price', '$date', '$route', '$invoice_number', '$carriage_invoice_number', 'unpaid')";
        mysqli_query($connection, $query);
        mysqli_commit($connection);
        header('Location: purchases-list.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error saving purchase record: ' . $e->getMessage() . '</div>';
    }
}

// Fetch items
$items_sql = "SELECT id, name FROM tbl_items ORDER BY name ASC";
$items_result = mysqli_query($connection, $items_sql);
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
					<h4 class="mb-5">Add Purchase</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Item</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="item_id" class="form-control" required>
                                                <option value="">Select Item</option>
                                                <?php 
                                                if (mysqli_num_rows($items_result) > 0) {
                                                    while ($item = mysqli_fetch_assoc($items_result)) {
                                                        echo '<option value="' . $item['id'] . '">' . htmlspecialchars($item['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Quantity</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="quantity" class="form-control" placeholder="0.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Unit Price</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Route</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="route" class="form-control" placeholder="e.g. Route A" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Invoice Number</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="invoice_number" class="form-control" placeholder="e.g. INV-1002" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Carriage Invoice</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="carriage_invoice_number" class="form-control" placeholder="e.g. CAR-5022" required>
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
