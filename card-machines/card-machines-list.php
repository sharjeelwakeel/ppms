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
            background-color: #04204e !important; /* Fallback */
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #machinesListTable thead th {
            background-color: #04204e !important; /* Fallback */
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Card Machines</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4>View Card Machines</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="add-card-machine.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Machine</a>
					</div>
				</div>
				<table id="machinesListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Machine Name</th>
							<th>Service Charges (%)</th>
							<th>Contact Person</th>
							<th>Contact Number</th>
							<th>Created At</th>
							<th>Updated At</th>
							<th>Delete</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT * FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td><a href="edit-card-machine.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a></td>
										<td>'.number_format($row['charges_percentage'], 2).'%</td>
										<td>'.htmlspecialchars($row['contact_person_name']).'</td>
										<td>'.htmlspecialchars($row['contact_person_number']).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>
										<td><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteMachine('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a></td>
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
		$('#machinesListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deleteMachine(id){
		if(confirm('Are you sure you want to delete this card machine?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletecardmachine.php",
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
