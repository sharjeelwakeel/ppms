<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing lubricant products
check_access('items', 'show');

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

$canAdd    = has_permission('items', 'add');
$canEdit   = has_permission('items', 'edit');
$canDelete = has_permission('items', 'delete');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{ margin-top:20px; }
		.m-bot{ margin-bottom:20px; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        #productsListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Lubricant Products</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-boxes mr-2 text-primary"></i>Lubricant Products</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="../categories/categories-list.php" class="btn btn-outline-secondary btn-sm mr-1"><i class="fas fa-tags mr-1"></i> Categories</a>
						<a href="../categories/subcategories-list.php" class="btn btn-outline-secondary btn-sm mr-2"><i class="fas fa-tag mr-1"></i> Subcategories</a>
                        <?php if ($canAdd): ?>
						<a href="add-product.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Product</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="productsListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th style="width: 50px;">ID</th>
							<th>Product Name</th>
							<th>Category</th>
							<th>Subcategory</th>
							<th style="width: 110px;">Reorder Level</th>
							<th style="width: 130px;">Selling Price (Rs.)</th>
							<th style="width: 150px;">Created At</th>
							<th style="width: 150px;">Updated At</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center; width: 70px;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT p.*, c.name AS category_name, sc.name AS subcategory_name 
                                FROM tbl_lubricant_products p 
                                LEFT JOIN tbl_product_categories c ON p.category_id = c.id 
                                LEFT JOIN tbl_product_subcategories sc ON p.subcategory_id = sc.id 
                                WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00') 
                                ORDER BY p.id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $productNameDisplay = $canEdit 
                                    ? '<a href="edit-product.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a>'
                                    : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                $reorder_val = isset($row['reorder_level']) ? $row['reorder_level'] : ($row['shelf_quantity'] ?? 0);
                                $catDisplay = !empty($row['category_name'])
                                    ? '<span class="badge badge-light border text-dark"><i class="fas fa-folder mr-1 text-warning"></i>'.htmlspecialchars($row['category_name']).'</span>'
                                    : '<span class="text-muted small">—</span>';
                                $subDisplay = !empty($row['subcategory_name'])
                                    ? '<span class="badge badge-light border text-info"><i class="fas fa-tag mr-1"></i>'.htmlspecialchars($row['subcategory_name']).'</span>'
                                    : '<span class="text-muted small">—</span>';

								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$productNameDisplay.'</td>
										<td>'.$catDisplay.'</td>
										<td>'.$subDisplay.'</td>
										<td>'.number_format($reorder_val, 0).'</td>
										<td>'.number_format($row['price'], 2).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteProduct('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
                                }
								echo '</tr>';
							}
						}
						?>
					</tbody>
				</table>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	$(document).ready(function() {
		$('#productsListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deleteProduct(id){
		if(confirm('Are you sure you want to delete this lubricant product?')) {
			$.ajax({
				type: "POST",
				url: "../include/deleteproduct.php",
				data: {id: id},
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}
	</script>
</html>
