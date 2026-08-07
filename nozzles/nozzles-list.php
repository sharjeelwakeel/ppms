<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing nozzles
check_access('nozzles', 'show');

// Auto-migrate tbl_nozzles if missing deleted_at
$chk_nd = mysqli_query($connection, "SHOW COLUMNS FROM tbl_nozzles LIKE 'deleted_at'");
if ($chk_nd && mysqli_num_rows($chk_nd) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_nozzles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$canAdd    = has_permission('nozzles', 'add');
$canEdit   = has_permission('nozzles', 'edit');
$canDelete = has_permission('nozzles', 'delete');
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
		.m-top { margin-top: 20px; }
		.m-bot  { margin-bottom: 20px; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #nozzlesListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS Nozzles</title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-burn mr-2 text-primary"></i>View Nozzles</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-nozzle.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Nozzle</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="nozzlesListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Nozzle Name</th>
							<th>Tank</th>
							<th>Item</th>
							<th>Created At</th>
							<th>Updated At</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT n.id, n.name AS nozzle_name, n.created_at, n.updated_at, t.tank_name, i.name AS item_name
								FROM tbl_nozzles n
								LEFT JOIN tbl_tanks t ON n.tank_id = t.id
								LEFT JOIN tbl_items i ON t.item_id = i.id
								WHERE (n.deleted_at IS NULL OR n.deleted_at = '0000-00-00 00:00:00')
								ORDER BY n.id DESC";
						$result = mysqli_query($connection, $sql);
						if ($result && mysqli_num_rows($result) > 0) {
							while ($row = mysqli_fetch_assoc($result)) {
                                $nozzleNameDisplay = $canEdit 
                                    ? '<a href="edit-nozzle.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['nozzle_name']).'</a>'
                                    : '<strong>'.htmlspecialchars($row['nozzle_name']).'</strong>';

								echo '
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$nozzleNameDisplay.'</td>
										<td>'.htmlspecialchars($row['tank_name'] ?? '-').'</td>
										<td>'.htmlspecialchars($row['item_name'] ?? '-').'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletenozzle('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size:18px;"></i></a></td>';
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
