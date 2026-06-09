<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: import-items-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $item_id = mysqli_real_escape_string($connection, $_POST['item_id']);
    $quantity = mysqli_real_escape_string($connection, $_POST['quantity']);
    $price = mysqli_real_escape_string($connection, $_POST['price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $payment_status = mysqli_real_escape_string($connection, $_POST['payment_status']);

    $query = "UPDATE tbl_import_items SET 
                item_id='$item_id', 
                quantity='$quantity', 
                price='$price', 
                date='$date', 
                payment_status='$payment_status' 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        header('Location: import-items-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating import record: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch record
$sql = "SELECT * FROM tbl_import_items WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$importItem = mysqli_fetch_assoc($result);

if (!$importItem) {
    header('Location: import-items-list.php');
    exit;
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
		<title>PPMS - Edit Import Item</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-import-item.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Import Item</h4>
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
                                                        $selected = ($importItem['item_id'] == $item['id']) ? 'selected' : '';
                                                        echo '<option value="' . $item['id'] . '" ' . $selected . '>' . htmlspecialchars($item['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Quantity</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="quantity" class="form-control" value="<?php echo htmlspecialchars($importItem['quantity']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Price</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="price" class="form-control" value="<?php echo htmlspecialchars($importItem['price']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($importItem['date']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Payment Status</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="payment_status" class="form-control" required>
												<option value="unpaid" <?php if ($importItem['payment_status'] == 'unpaid') echo 'selected'; ?>>Unpaid</option>
												<option value="paid" <?php if ($importItem['payment_status'] == 'paid') echo 'selected'; ?>>Paid</option>
											</select>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<input type="submit" name="submit" value="Save Import" class="btn btn-primary m-top">
                        <a href="import-items-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
