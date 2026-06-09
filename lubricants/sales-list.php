<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
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
        #salesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Stock Sales</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4>Stock Sales (Outflow)</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="add-sale.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Sale</a>
					</div>
				</div>
				<table id="salesTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Product Name</th>
							<th>Quantity</th>
							<th>Rate (Rs.)</th>
							<th>Total Amount (Rs.)</th>
							<th>Payment Type</th>
							<th>Customer/Details</th>
							<th>Date</th>
							<th>Delete</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT sal.*, p.name as product_name 
                                FROM tbl_lubricant_sales sal 
                                LEFT JOIN tbl_lubricant_products p ON sal.product_id = p.id 
                                ORDER BY sal.id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $paymentBadge = ($row['payment_type'] == 'Cash') ? 'badge-success' : 'badge-warning';
								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td><a href="edit-sale.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['product_name'] ?? 'N/A').'</a></td>
										<td>'.number_format($row['quantity'], 2).'</td>
										<td>'.number_format($row['rate'], 2).'</td>
										<td>'.number_format($row['amount'], 2).'</td>
										<td><span class="badge '.$paymentBadge.'">'.htmlspecialchars($row['payment_type']).'</span></td>
										<td>'.htmlspecialchars($row['details'] ?? '—').'</td>
										<td>'.date("d-m-Y", strtotime($row['date'])).'</td>
										<td><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteSale('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a></td>
									</tr>';
							}
						}
						?>
					</tbody>
				</table>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	$(document).ready(function() {
		$('#salesTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deleteSale(id){
		if(confirm('Are you sure you want to delete this sale record?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletesale.php",
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
