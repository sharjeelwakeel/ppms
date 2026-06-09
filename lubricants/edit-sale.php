<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

$message = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch current details
$sql = "SELECT * FROM tbl_lubricant_sales WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$sale = mysqli_fetch_assoc($result);

if (!$sale) {
    header('Location: sales-list.php');
    exit;
}

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $product_id = intval($_POST['product_id']);
    $quantity = floatval($_POST['quantity']);
    $rate = floatval($_POST['rate']);
    $amount = floatval($_POST['amount']);
    $payment_type = mysqli_real_escape_string($connection, $_POST['payment_type']);
    $details = mysqli_real_escape_string($connection, $_POST['details'] ?? '');
    $date = mysqli_real_escape_string($connection, $_POST['date']);

    // Calculate available stock (excluding the current sale record itself)
    $stock_sql = "
        SELECT 
            (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = $product_id), 0) -
             COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = $product_id AND id != $id), 0)) AS avail_stock
    ";
    $stock_res = mysqli_query($connection, $stock_sql);
    $avail_stock = 0;
    if ($stock_res) {
        $stock_row = mysqli_fetch_assoc($stock_res);
        $avail_stock = floatval($stock_row['avail_stock']);
    }

    if ($quantity > $avail_stock) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Error: Insufficient stock. Available: ' . number_format($avail_stock, 2) . ' units. Requested: ' . number_format($quantity, 2) . ' units.</div>';
    } else {
        $query = "UPDATE tbl_lubricant_sales SET 
                  product_id='$product_id', 
                  quantity='$quantity', 
                  rate='$rate', 
                  amount='$amount', 
                  payment_type='$payment_type', 
                  details='$details', 
                  date='$date' 
                  WHERE id='$id'";
        
        if (mysqli_query($connection, $query)) {
            header('Location: sales-list.php');
            exit;
        } else {
            $message = '<div class="alert alert-danger">Error updating sale: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch products with their available stock (excluding this sale's quantity from the deduction)
$products_sql = "
    SELECT p.id, p.name, p.price,
           (COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id), 0) -
            COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id AND id != $id), 0)) AS avail_stock
    FROM tbl_lubricant_products p
    ORDER BY p.name ASC
";
$products_result = mysqli_query($connection, $products_sql);
$products_list = [];
if ($products_result) {
    while ($r = mysqli_fetch_assoc($products_result)) {
        $products_list[] = $r;
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
		<title>PPMS - Edit Sale</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-sale.php?id=<?php echo $id; ?>" method="POST" id="saleForm" onsubmit="return validateStock();">
					<input type="hidden" name="id" value="<?php echo $sale['id']; ?>">
					<h4 class="mb-5">Edit Stock Sale</h4>
                    <?php echo $message; ?>
                    <div id="jsWarning" class="alert alert-danger d-none"><i class="fas fa-exclamation-triangle mr-2"></i><span></span></div>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Product</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="product_id" id="productId" class="form-control" onchange="updateProductDetails()" required>
                                                <option value="">Select Product</option>
                                                <?php 
                                                foreach ($products_list as $prod) {
                                                    $selected = ($prod['id'] == $sale['product_id']) ? 'selected' : '';
                                                    echo '<option value="' . $prod['id'] . '" data-price="' . $prod['price'] . '" data-stock="' . $prod['avail_stock'] . '" ' . $selected . '>' . htmlspecialchars($prod['name']) . '</option>';
                                                }
                                                ?>
											</select>
                                            <small class="text-muted" id="availStockDisplay">Select a product to view available stock.</small>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Quantity</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0.01" name="quantity" id="quantity" class="form-control" placeholder="0.00" value="<?php echo htmlspecialchars($sale['quantity']); ?>" oninput="calculateAmount()" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Rate</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="rate" id="rate" class="form-control" placeholder="0.00" value="<?php echo htmlspecialchars($sale['rate']); ?>" oninput="calculateAmount()" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Total Amount</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="amount" id="amount" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($sale['amount']); ?>" readonly style="background:#e8eaf6; color:var(--primary-color);" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Payment Type</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="payment_type" class="form-control" required>
												<option value="Cash" <?php echo ($sale['payment_type'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
												<option value="Credit" <?php echo ($sale['payment_type'] == 'Credit') ? 'selected' : ''; ?>>Credit</option>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Details / Cust.</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="details" class="form-control" placeholder="e.g. Staff Name, Customer name or Invoice #" value="<?php echo htmlspecialchars($sale['details'] ?? ''); ?>">
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($sale['date']); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top">Update Sale</button>
                        <a href="sales-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script>
    $(document).ready(function() {
        updateStockDisplayOnly();
    });

    function updateStockDisplayOnly() {
        var selectedOpt = $('#productId option:selected');
        if (selectedOpt.val() !== '') {
            var stock = parseFloat(selectedOpt.data('stock')) || 0;
            $('#availStockDisplay').html('<strong class="text-success">Available Stock (incl. this sale): ' + stock.toFixed(2) + ' units</strong>');
        }
    }

    function updateProductDetails() {
        var selectedOpt = $('#productId option:selected');
        if (selectedOpt.val() !== '') {
            var price = parseFloat(selectedOpt.data('price')) || 0;
            var stock = parseFloat(selectedOpt.data('stock')) || 0;
            
            $('#rate').val(price.toFixed(2));
            $('#availStockDisplay').html('<strong class="text-success">Available Stock (incl. this sale): ' + stock.toFixed(2) + ' units</strong>');
        } else {
            $('#rate').val('');
            $('#availStockDisplay').html('Select a product to view available stock.');
        }
        calculateAmount();
    }

    function calculateAmount() {
        var qty = parseFloat($('#quantity').val()) || 0;
        var rate = parseFloat($('#rate').val()) || 0;
        var total = qty * rate;
        $('#amount').val(total.toFixed(2));
    }

    function validateStock() {
        var selectedOpt = $('#productId option:selected');
        if (selectedOpt.val() === '') return true;
        
        var stock = parseFloat(selectedOpt.data('stock')) || 0;
        var qty = parseFloat($('#quantity').val()) || 0;
        
        if (qty > stock) {
            $('#jsWarning').removeClass('d-none');
            $('#jsWarning span').text('Insufficient stock. Only ' + stock.toFixed(2) + ' units available.');
            $('html, body').animate({ scrollTop: 0 }, 'slow');
            return false;
        }
        $('#jsWarning').addClass('d-none');
        return true;
    }
    </script>
</html>
