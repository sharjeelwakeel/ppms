<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/price_helper.php';

// Enforce access check for editing lubricant products
check_access('items', 'edit');

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

$id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);

// Fetch current details
$sql = "SELECT * FROM tbl_lubricant_products WHERE id='$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
$result = mysqli_query($connection, $sql);
$product = mysqli_fetch_assoc($result);

if (!$product) {
    header('Location: products-list.php');
    exit;
}

$message = '';
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
    $notes = !empty($_POST['notes']) ? mysqli_real_escape_string($connection, trim($_POST['notes'])) : 'Price revised from edit product';
    $user_id = intval($_SESSION['loggedInUser'] ?? 0);

    // Detect if rates were changed
    $rates_changed = (abs($cash_rate - floatval($product['cash_rate'])) > 0.0001) ||
                     (abs($credit_rate - floatval($product['credit_rate'])) > 0.0001) ||
                     (abs($purchase_rate - floatval($product['purchase_rate'])) > 0.0001);

    // Check if active price entry exists
    $chk_act = mysqli_query($connection, "SELECT id FROM tbl_prices WHERE table_name = 'tbl_lubricant_products' AND table_id = '$id' AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    $has_active = ($chk_act && mysqli_num_rows($chk_act) > 0);

    $query = "UPDATE tbl_lubricant_products 
              SET name='$name', 
                  cash_rate='$cash_rate', 
                  credit_rate='$credit_rate', 
                  purchase_rate='$purchase_rate', 
                  price='$price', 
                  reorder_level='$reorder_level', 
                  category_id=$category_id, 
                  subcategory_id=$subcategory_id 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        if ($rates_changed || !$has_active) {
            set_active_price($connection, 'tbl_lubricant_products', $id, $cash_rate, $credit_rate, $purchase_rate, $effective_date, $notes, $user_id);
        }
        header('Location: products-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating product: ' . mysqli_error($connection) . '</div>';
    }
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

$cRateVal = floatval($product['cash_rate'] ?? $product['price']);
$crRateVal = floatval($product['credit_rate'] ?? $product['price']);
$pRateVal = floatval($product['purchase_rate'] ?? 0);
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
		<title>PPMS - Edit Product</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-product.php?id=<?php echo $id; ?>" method="POST">
					<input type="hidden" name="id" value="<?php echo $product['id']; ?>">
					<h4 class="mb-4"><i class="fas fa-boxes mr-2 text-primary"></i>Edit Product &amp; Lubricant</h4>
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
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold">Reordering Level</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="1" min="0" name="reorder_level" class="form-control" placeholder="e.g. 10" value="<?php echo intval($product['reorder_level'] ?? ($product['shelf_quantity'] ?? 0)); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>

					<div class="card mb-4 border-0 shadow-sm">
						<div class="card-header text-white" style="background: var(--primary-gradient);">
							<h6 class="mb-0 font-weight-bold"><i class="fas fa-tags mr-2 text-warning"></i>Active Pricing &amp; Revisions</h6>
						</div>
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-success">
											<i class="fas fa-money-bill-wave mr-1"></i> Cash Rate (Rs.) <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="cash_rate" id="cash_rate" class="form-control font-weight-bold text-success" value="<?php echo number_format($cRateVal, 2, '.', ''); ?>" required>
											<small class="text-muted">Active rate applied to cash sales.</small>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label font-weight-bold text-primary">
											<i class="fas fa-file-invoice-dollar mr-1"></i> Credit Rate (Rs.) <span class="text-danger">*</span>
										</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" min="0" name="credit_rate" id="credit_rate" class="form-control font-weight-bold text-primary" value="<?php echo number_format($crRateVal, 2, '.', ''); ?>" required>
											<small class="text-muted">Active tariff applied to credit customers.</small>
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
											<input type="number" step="0.01" min="0" name="purchase_rate" id="purchase_rate" class="form-control font-weight-bold text-muted" value="<?php echo number_format($pRateVal, 2, '.', ''); ?>" required>
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
											<small class="text-muted">Effective date for revised price (if changed).</small>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-2">
								<div class="col-md-12">
									<div class="form-group row">
										<label class="col-lg-2 col-md-3 col-sm-4 col-form-label font-weight-bold text-dark">
											<i class="fas fa-sticky-note mr-1 text-info"></i> Revision Note
										</label>
										<div class="col-lg-10 col-md-9 col-sm-8">
											<input type="text" name="notes" class="form-control" placeholder="e.g. Rate revision note / reason">
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="txt-center mb-5">
						<button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update Product</button>
                        <a href="products-list.php" class="btn btn-secondary ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>

                <!-- Price Change History Section -->
                <?php $history = get_price_history($connection, 'tbl_lubricant_products', $id); ?>
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold"><i class="fas fa-history mr-2 text-primary"></i>Price Change History (<?php echo htmlspecialchars($product['name']); ?>)</span>
                        <span class="badge badge-info"><?php echo count($history); ?> Record(s)</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0 text-center">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Status</th>
                                        <th>Effective Date</th>
                                        <th>Cash Rate (Rs.)</th>
                                        <th>Credit Rate (Rs.)</th>
                                        <th>Purchase Rate (Rs.)</th>
                                        <th>Notes</th>
                                        <th>Recorded At</th>
                                        <th style="width: 60px;">Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($history)): ?>
                                        <?php foreach ($history as $p): ?>
                                            <tr class="<?php echo $p['is_active'] ? 'table-success font-weight-bold' : ''; ?>">
                                                <td>
                                                    <?php if ($p['is_active']): ?>
                                                        <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Current</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><i class="fas fa-history mr-1"></i>Past</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($p['effective_date']) ? date('d-m-Y', strtotime($p['effective_date'])) : '-'; ?></td>
                                                <td class="text-success font-weight-bold">Rs. <?php echo number_format($p['cash_rate'], 2); ?></td>
                                                <td class="text-primary font-weight-bold">Rs. <?php echo number_format($p['credit_rate'], 2); ?></td>
                                                <td class="text-muted">Rs. <?php echo number_format($p['purchase_rate'], 2); ?></td>
                                                <td class="text-left"><?php echo htmlspecialchars($p['notes'] ?? '-'); ?></td>
                                                <td class="text-muted small"><?php echo !empty($p['created_at']) ? date('d-m-Y H:i', strtotime($p['created_at'])) : '-'; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Soft delete price" onclick="deletePrice(<?php echo $p['id']; ?>)">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-muted p-3">No price history recorded yet for this product.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

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
    });

    function deletePrice(priceId) {
        if (confirm('Are you sure you want to soft delete this price record?')) {
            $.ajax({
                type: "POST",
                url: "delete-price.php",
                data: { id: priceId },
                dataType: "json",
                success: function(response) {
                    if (response.status === 'success') {
                        location.reload();
                    } else {
                        alert(response.message || 'Error deleting price record.');
                    }
                },
                error: function() {
                    alert('Server error while deleting price record.');
                }
            });
        }
    }
    </script>
</html>
