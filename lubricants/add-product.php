<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/price_helper.php';

// Enforce access check for adding lubricant products
check_access('items', 'add');

// Initialize polymorphic tbl_prices and table auto-migrations
init_prices_table($connection);

// Auto-migrate tbl_lubricant_products: ensure reorder_level column exists and deleted_at exists
$chk_ro = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'reorder_level'");
if ($chk_ro && mysqli_num_rows($chk_ro) == 0) {
    $chk_sq = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'shelf_quantity'");
    if ($chk_sq && mysqli_num_rows($chk_sq) > 0) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products CHANGE COLUMN shelf_quantity reorder_level INT(11) NOT NULL DEFAULT 0");
    } else {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products ADD COLUMN reorder_level INT(11) NOT NULL DEFAULT 0 AFTER category");
    }
} else {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_products MODIFY COLUMN reorder_level INT(11) NOT NULL DEFAULT 0");
}
$chk_del = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'deleted_at'");
if ($chk_del && mysqli_num_rows($chk_del) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_products ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$message = '';
$category_id_val = 0;
$subcategory_id_val = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name = mysqli_real_escape_string($connection, trim($_POST['name']));
    $cash_rate = isset($_POST['cash_rate']) ? floatval($_POST['cash_rate']) : floatval($_POST['price'] ?? 0);
    $credit_rate = isset($_POST['credit_rate']) ? floatval($_POST['credit_rate']) : $cash_rate;
    $purchase_rate = isset($_POST['purchase_rate']) ? floatval($_POST['purchase_rate']) : 0.00;
    $price = $cash_rate;
    $reorder_level = isset($_POST['reorder_level']) ? intval($_POST['reorder_level']) : 0;
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : "NULL";
    $subcategory_id = !empty($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : "NULL";
    $effective_date = !empty($_POST['effective_date']) ? mysqli_real_escape_string($connection, trim($_POST['effective_date'])) : date('Y-m-d');
    $notes = !empty($_POST['notes']) ? mysqli_real_escape_string($connection, trim($_POST['notes'])) : 'Initial base price';
    $user_id = intval($_SESSION['loggedInUser'] ?? 0);

    $query = "INSERT INTO tbl_lubricant_products (name, cash_rate, credit_rate, purchase_rate, price, reorder_level, category_id, subcategory_id) 
              VALUES ('$name', '$cash_rate', '$credit_rate', '$purchase_rate', '$price', '$reorder_level', $category_id, $subcategory_id)";
    
    if (mysqli_query($connection, $query)) {
        $product_id = mysqli_insert_id($connection);
        set_active_price($connection, 'tbl_lubricant_products', $product_id, $cash_rate, $credit_rate, $purchase_rate, $effective_date, $notes, $user_id);
        header('Location: products-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving product: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch active categories
$categoriesList = mysqli_query($connection, "SELECT id, name FROM tbl_product_categories WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND status = 'Active' ORDER BY name ASC");
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
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover { opacity: 0.9; }
		</style>
		<title>PPMS - Add Product</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-product.php" method="POST">
					<h4 class="mb-4"><i class="fas fa-boxes mr-2 text-primary"></i>Add Product &amp; Lubricant</h4>
                    <?php echo $message; ?>
					<div class="card mb-4 border-0 shadow-sm">
						<div class="card-header text-white" style="background: var(--primary-color);">
							<h6 class="mb-0 font-weight-bold"><i class="fas fa-info-circle mr-2"></i>Product Details &amp; Classification</h6>
						</div>
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Category</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<select name="category_id" id="category_id" class="form-control">
												<option value="">-- Select Category (Optional) --</option>
												<?php 
												if ($categoriesList && mysqli_num_rows($categoriesList) > 0) {
													while ($c = mysqli_fetch_assoc($categoriesList)) {
														echo '<option value="' . $c['id'] . '">' . htmlspecialchars($c['name']) . '</option>';
													}
												}
												?>
											</select>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Subcategory</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<select name="subcategory_id" id="subcategory_id" class="form-control" disabled>
												<option value="">-- Select Category First --</option>
											</select>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-2">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Product Name <span class="text-danger">*</span></label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="name" class="form-control" placeholder="e.g. Helix HX7 10W-40 (4L)" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Reordering Level</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="1" min="0" name="reorder_level" class="form-control" placeholder="e.g. 10" value="0" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>

					<div class="card mb-4 border-0 shadow-sm">
						<div class="card-header text-white" style="background: var(--primary-gradient);">
							<h6 class="mb-0 font-weight-bold"><i class="fas fa-tags mr-2 text-warning"></i>Pricing Structure &amp; Tariffs</h6>
						</div>
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-success">
											<i class="fas fa-money-bill-wave mr-1"></i> Cash Rate (Rs.) <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="cash_rate" id="cash_rate" class="form-control font-weight-bold text-success" placeholder="e.g. 350.00" required>
											<small class="text-muted">Standard rate applied to cash sales.</small>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-primary">
											<i class="fas fa-file-invoice-dollar mr-1"></i> Credit Rate (Rs.) <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="credit_rate" id="credit_rate" class="form-control font-weight-bold text-primary" placeholder="e.g. 360.00" required>
											<small class="text-muted">Tariff applied to credit customers.</small>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-2">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-muted">
											<i class="fas fa-shopping-cart mr-1"></i> Purchase Rate (Rs.) <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="purchase_rate" id="purchase_rate" class="form-control font-weight-bold text-muted" placeholder="e.g. 310.00" value="0.00" required>
											<small class="text-muted">Cost price per unit paid to supplier.</small>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-dark">
											<i class="fas fa-calendar-day mr-1 text-primary"></i> Effective Date <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="date" name="effective_date" class="form-control font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
											<small class="text-muted">Date from which this price applies.</small>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-2">
								<div class="col-md-12">
									<div class="form-group row">
										<label class="col-lg-2 col-md-3 col-sm-4 col-form-label font-weight-bold text-dark">
											<i class="fas fa-sticky-note mr-1 text-info"></i> Price Notes
										</label>
										<div class="col-lg-10 col-md-9 col-sm-8">
											<input type="text" name="notes" class="form-control" placeholder="e.g. Initial baseline product pricing">
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Save Product</button>
                        <a href="products-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#category_id').on('change', function() {
            var catId = $(this).val();
            var $subSelect = $('#subcategory_id');
            $subSelect.empty();

            if (!catId) {
                $subSelect.append('<option value="">-- Select Category First --</option>');
                $subSelect.prop('disabled', true);
                return;
            }

            $subSelect.append('<option value="">Loading subcategories...</option>');
            $subSelect.prop('disabled', true);

            $.getJSON('../categories/ajax-get-subcategories.php', { category_id: catId }, function(data) {
                $subSelect.empty();
                $subSelect.append('<option value="">-- Select Subcategory (Optional) --</option>');
                if (data && data.length > 0) {
                    $.each(data, function(index, item) {
                        $subSelect.append('<option value="' + item.id + '">' + item.name + '</option>');
                    });
                }
                $subSelect.prop('disabled', false);
            }).fail(function() {
                $subSelect.empty();
                $subSelect.append('<option value="">Error loading subcategories</option>');
                $subSelect.prop('disabled', false);
            });
        });

        // Quick helper: if credit rate is blank, mirror cash rate
        $('#cash_rate').on('input', function() {
            var val = $(this).val();
            if ($('#credit_rate').val() === '') {
                $('#credit_rate').val(val);
            }
        });
    });
    </script>
</html>
