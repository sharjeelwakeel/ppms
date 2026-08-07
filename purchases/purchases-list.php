<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for purchases list
check_access('purchases', 'show');

// Auto-migrate tbl_purchase_tank_links schema if needed
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_purchase_tank_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `tank_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_tank_id` (`tank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
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
		.m-top{
			margin-top:20px;
		}
		.m-bot{
			margin-bottom:20px;
		}
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #purchasesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            white-space: nowrap;
        }
		</style>
		<title>PPMS - Purchases</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container-fluid pt-4 pb-4 px-lg-5">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4>View Purchases</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if (has_permission('purchases', 'add')): ?>
						<a href="add-purchase.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Purchase</a>
                        <?php endif; ?>
					</div>
				</div>
				<div class="table-responsive">
					<table id="purchasesTable" class="table table-striped table-bordered">
						<thead>
							<tr>
								<th>ID</th>
								<th>Item Name</th>
								<th>Quantity</th>
								<th>Unit Price</th>
								<th>Total Amount</th>
								<th>Paid Amount</th>
								<th>Remaining Amount</th>
								<th>Date</th>
								<th>Status</th>
								<th>Route</th>
								<th>Invoice No</th>
								<th>Carriage Invoice No</th>
								<th style="text-align: center;">Linked To</th>
                                <?php if (has_permission('purchases', 'delete')): ?>
								<th style="text-align: center;">Delete</th>
                                <?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php 
							$sql = "SELECT p.*, i.name as item_name, 
									       COALESCE((SELECT SUM(pay.amount) FROM tbl_purchase_payments pay WHERE pay.purchase_id = p.id AND pay.deleted_at IS NULL), 0) as paid_amount,
									       COALESCE((SELECT SUM(link.quantity) FROM tbl_purchase_tank_links link WHERE link.purchase_id = p.id), 0) as stored_quantity
									FROM tbl_purchases p 
									LEFT JOIN tbl_items i ON p.item_id = i.id 
									WHERE p.deleted_at IS NULL
									ORDER BY p.id DESC";
							$result = mysqli_query($connection, $sql);
							if($result && mysqli_num_rows($result) > 0){
								while($row = mysqli_fetch_assoc($result)){
									$total_amount = floatval($row['quantity']) * floatval($row['price']);
									$paid_amount = floatval($row['paid_amount']);
									$remaining_amount = $total_amount - $paid_amount;
									
									$purchased_qty = floatval($row['quantity']);
									$stored_qty = floatval($row['stored_quantity']);

									$linkStatusColor = 'text-danger';
									if ($stored_qty >= $purchased_qty && $purchased_qty > 0) {
										$linkStatusColor = 'text-success';
									} else if ($stored_qty > 0) {
										$linkStatusColor = 'text-warning';
									}
									
									$statusBadge = 'badge-danger';
									if ($row['payment_status'] == 'paid') {
										$statusBadge = 'badge-success';
									} else if ($row['payment_status'] == 'in process') {
										$statusBadge = 'badge-warning';
									}

                                    $canEdit = has_permission('purchases', 'edit');
                                    $itemLink = $canEdit 
                                        ? '<a href="edit-purchase.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['item_name'] ?? 'N/A').'</a>'
                                        : '<strong>'.htmlspecialchars($row['item_name'] ?? 'N/A').'</strong>';
									
									echo' 
										<tr>
											<td>'.$row['id'].'</td>
											<td>'.$itemLink.'</td>
											<td>'.number_format($row['quantity'], 2).'</td>
											<td>'.number_format($row['price'], 2).'</td>
											<td class="font-weight-bold">'.number_format($total_amount, 2).'</td>
											<td class="text-success">'.number_format($paid_amount, 2).'</td>
											<td class="text-danger">'.number_format($remaining_amount, 2).'</td>
											<td>'.date("d-m-Y", strtotime($row['date'])).'</td>
											<td><span class="badge '.$statusBadge.'">'.ucfirst(htmlspecialchars($row['payment_status'])).'</span></td>
											<td>'.htmlspecialchars($row['route']).'</td>
											<td>'.htmlspecialchars($row['invoice_number']).'</td>
											<td>'.htmlspecialchars($row['carriage_invoice_number']).'</td>
											<td class="text-center" style="white-space:nowrap;">
												<a href="link-purchase-tank.php?id='.$row['id'].'" class="btn btn-sm font-weight-bold px-2 py-1 mb-1" style="background:linear-gradient(135deg, #17a2b8 0%, #117a8b 100%); color:#fff; border-radius:6px; font-size:12px; text-decoration:none; display:inline-block; border:none; box-shadow:0 2px 6px rgba(23,162,184,0.25);">
													<i class="fas fa-link mr-1"></i> Linked To
												</a>
												<br>
												<small class="font-weight-bold '.$linkStatusColor.'">'.number_format($stored_qty, 2).' / '.number_format($purchased_qty, 2).' Ltr</small>
											</td>';
                                    if (has_permission('purchases', 'delete')) {
                                        echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletePurchase('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a></td>';
                                    }
									echo '</tr>';
								}
							}
							?>
						</tbody>
					</table>
				</div>
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
		if(confirm('Are you sure you want to delete this purchase record?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletepurchase.php",
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
