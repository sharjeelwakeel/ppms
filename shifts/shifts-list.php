<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing shifts
check_access('shifts', 'show');

// Auto-migrate tbl_shifts if missing deleted_at
$chk_sd = mysqli_query($connection, "SHOW COLUMNS FROM tbl_shifts LIKE 'deleted_at'");
if ($chk_sd && mysqli_num_rows($chk_sd) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_shifts ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$canAdd    = has_permission('shifts', 'add');
$canEdit   = has_permission('shifts', 'edit');
$canDelete = has_permission('shifts', 'delete');
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
        #shiftsListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Shifts</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-clock mr-2 text-primary"></i>View Shifts</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-shift.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Shift</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="shiftsListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Start Time</th>
							<th>End Time</th>
							<th style="text-align: center;">Status</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT * FROM tbl_shifts WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
								$statusBadge = ($row['status'] == 'Active') ? 'badge-success' : 'badge-secondary';
                                $shiftNameDisplay = $canEdit 
                                    ? '<a href="edit-shift.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a>'
                                    : '<strong>'.htmlspecialchars($row['name']).'</strong>';
								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$shiftNameDisplay.'</td>
										<td>'.date("h:i A", strtotime($row['start_time'])).'</td>
										<td>'.date("h:i A", strtotime($row['end_time'])).'</td>
										<td class="text-center"><span class="badge '.$statusBadge.'">'.htmlspecialchars($row['status']).'</span></td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteshift('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
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
		$('#shiftsListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deleteshift(id){
		if(confirm('Are you sure you want to delete this shift?')) {
			$.ajax({
				type: "POST",
				url: "../include/deleteshift.php",
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
