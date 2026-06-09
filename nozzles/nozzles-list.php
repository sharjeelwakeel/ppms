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
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/fancyapps/fancybox@3.5.7/dist/jquery.fancybox.min.css" />
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
            background-color: #04204e !important; /* Fallback */
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #nozzlesListTable thead th {
            background-color: #04204e !important; /* Fallback */
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Nozzles</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4>View Nozzles</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="add-nozzle.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Nozzle</a>
					</div>
				</div>
				<table id="nozzlesListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Tank</th>
							<th>Item</th>
							<th>Start Reading</th>
							<th>Status</th>
							<th>Created At</th>
							<th>Updated At</th>
							<th>Delete</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT n.*, t.tank_name, i.name as item_name 
                                FROM tbl_nozzles n 
                                LEFT JOIN tbl_tanks t ON n.tank_id = t.id 
                                LEFT JOIN tbl_items i ON n.item_id = i.id 
                                ORDER BY n.id DESC";
						$result = mysqli_query($connection, $sql);
						$resultcheck = mysqli_num_rows($result);
						if($resultcheck > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $statusBadge = ($row['status'] == 'Active') ? 'badge-success' : 'badge-secondary';
								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td><a href="edit-nozzle.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a></td>
										<td>'.htmlspecialchars($row['tank_name'] ?? 'N/A').'</td>
										<td>'.htmlspecialchars($row['item_name'] ?? 'N/A').'</td>
										<td>'.number_format($row['start_reading'], 2).'</td>
										<td><span class="badge '.$statusBadge.'">'.htmlspecialchars($row['status']).'</span></td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>
										<td><a class="btn btn-large btn-link p-0 text-danger" onclick="deletenozzle('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a></td>
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
	<script src="https://cdn.jsdelivr.net/gh/fancyapps/fancybox@3.5.7/dist/jquery.fancybox.min.js"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	$(document).ready(function() {
		$('#nozzlesListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deletenozzle(id){
		if(confirm('Are you sure you want to delete this nozzle?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletenozzle.php",
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
