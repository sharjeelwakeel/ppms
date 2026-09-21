<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing lubricant products
check_access('items', 'edit');

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
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (isset($_POST['id']) && isset($_POST['name']) && isset($_POST['price'])) {
    $id = intval($_POST['id']);
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $price = floatval($_POST['price']);
    $reorder_level = isset($_POST['reorder_level']) ? intval($_POST['reorder_level']) : 0;
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : "NULL";
    $subcategory_id = !empty($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : "NULL";

    $query = "UPDATE tbl_lubricant_products 
              SET name='$name', price='$price', reorder_level='$reorder_level', category_id=$category_id, subcategory_id=$subcategory_id 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        header('Location: products-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating product: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch current details
$sql = "SELECT * FROM tbl_lubricant_products WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$product = mysqli_fetch_assoc($result);

if (!$product) {
    header('Location: products-list.php');
    exit;
}

// Fetch categories for dropdown (including product's current category)
$currentCatId = intval($product['category_id'] ?? 0);
$currentSubId = intval($product['subcategory_id'] ?? 0);

$categoriesList = mysqli_query($connection, "SELECT id, name FROM tbl_product_categories 
                                             WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                             AND (status = 'Active' OR id = '$currentCatId') 
                                             ORDER BY name ASC");

$subcategoriesList = null;
if ($currentCatId > 0) {
    $subcategoriesList = mysqli_query($connection, "SELECT id, name FROM tbl_product_subcategories 
                                                   WHERE category_id = '$currentCatId' 
                                                   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                                   AND (status = 'Active' OR id = '$currentSubId') 
                                                   ORDER BY name ASC");
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
		<title>PPMS - Edit Product</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-product.php" method="POST">
					<input type="hidden" name="id" value="<?php echo $product['id']; ?>">
					<h4 class="mb-5"><i class="fas fa-boxes mr-2 text-primary"></i>Edit Lubricant Product</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
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
														$sel = ($c['id'] == $currentCatId) ? 'selected' : '';
														echo '<option value="' . $c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['name']) . '</option>';
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
											<select name="subcategory_id" id="subcategory_id" class="form-control" <?php echo ($currentCatId <= 0) ? 'disabled' : ''; ?>>
												<option value="">-- Select Subcategory (Optional) --</option>
												<?php 
												if ($subcategoriesList && mysqli_num_rows($subcategoriesList) > 0) {
													while ($sc = mysqli_fetch_assoc($subcategoriesList)) {
														$subSel = ($sc['id'] == $currentSubId) ? 'selected' : '';
														echo '<option value="' . $sc['id'] . '" ' . $subSel . '>' . htmlspecialchars($sc['name']) . '</option>';
													}
												}
												?>
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
											<input type="text" name="name" class="form-control" placeholder="e.g. Grease (250g)" value="<?php echo htmlspecialchars($product['name']); ?>" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Selling Price <span class="text-danger">*</span></label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="price" class="form-control" placeholder="e.g. 350.00" value="<?php echo htmlspecialchars($product['price']); ?>" required>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-2">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Reordering Level</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="1" min="0" name="reorder_level" class="form-control" placeholder="e.g. 10" value="<?php echo intval($product['reorder_level'] ?? ($product['shelf_quantity'] ?? 0)); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Update Product</button>
                        <a href="products-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
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
    });
    </script>
</html>
