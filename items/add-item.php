<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding items
check_access('items', 'add');

$message = '';
if (isset($_POST['name']) && isset($_POST['cash_rate']) && isset($_POST['credit_rate']) && isset($_POST['purchase_rate']) && isset($_POST['unit'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $cash_rate = mysqli_real_escape_string($connection, $_POST['cash_rate']);
    $credit_rate = mysqli_real_escape_string($connection, $_POST['credit_rate']);
    $purchase_rate = mysqli_real_escape_string($connection, $_POST['purchase_rate']);
    $unit = mysqli_real_escape_string($connection, $_POST['unit']);

    $query = "INSERT INTO tbl_items (name, cash_rate, credit_rate, purchase_rate, unit) 
              VALUES ('$name', '$cash_rate', '$credit_rate', '$purchase_rate', '$unit')";
    
    if (mysqli_query($connection, $query)) {
        header('Location: items-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving item: ' . mysqli_error($connection) . '</div>';
    }
}
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
		<title>PPMS Add Item</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-item.php" method="POST">
					<h4 class="mb-5">Add Item</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label">Item Name</label>
										<div class="col-lg-8 col-md-7">
											<input type="text" name="name" class="form-control" placeholder="e.g. Petrol" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label">Unit</label>
										<div class="col-lg-8 col-md-7">
											<input type="text" name="unit" class="form-control" placeholder="e.g. Litre" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label">Cash Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="cash_rate" class="form-control" placeholder="e.g. 200.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label">Credit Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="credit_rate" class="form-control" placeholder="e.g. 205.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label">Purchase Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="purchase_rate" class="form-control" placeholder="e.g. 195.00" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top">Save Item</button>
                        <a href="items-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
