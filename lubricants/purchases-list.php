<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing lubricant purchases
check_access('items', 'show');

// Auto-migrate tbl_lubricant_purchases if missing deleted_at
$chk_lpur = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_purchases LIKE 'deleted_at'");
if ($chk_lpur && mysqli_num_rows($chk_lpur) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_purchases ADD COLUMN deleted_at DATETIME DEFAULT NULL");
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
        #purchasesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Stock Purchases</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-truck-loading mr-2 text-primary"></i>Stock Purchases (Inflow)</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-purchase.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Purchase</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="purchasesTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Product Name</th>
							<th>Quantity</th>
							<th>Purchase Price (Rs.)</th>
							<th>Date</th>
							<th>Payment Status</th>
							<th>Created At</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT pur.*, p.name as product_name 
                                FROM tbl_lubricant_purchases pur 
                                LEFT JOIN tbl_lubricant_products p ON pur.product_id = p.id 
                                WHERE (pur.deleted_at IS NULL OR pur.deleted_at = '0000-00-00 00:00:00')
                                ORDER BY pur.id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $statusBadge = ($row['payment_status'] == 'paid') ? 'badge-success' : 'badge-warning';
                                $productNameDisplay = $canEdit 
                                    ? '<a href="edit-purchase.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['product_name'] ?? 'N/A').'</a>'
                                    : '<strong>'.htmlspecialchars($row['product_name'] ?? 'N/A').'</strong>';
								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$productNameDisplay.'</td>
										<td>'.number_format($row['quantity'], 2).'</td>
										<td>'.number_format($row['purchase_price'], 2).'</td>
										<td>'.date("d-m-Y", strtotime($row['date'])).'</td>
										<td><span class="badge '.$statusBadge.'">'.ucfirst(htmlspecialchars($row['payment_status'])).'</span></td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletePurchase('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
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
		$('#purchasesTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deletePurchase(id){
		if(confirm('Are you sure you want to delete this purchase entry?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletelubricantpurchase.php",
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
