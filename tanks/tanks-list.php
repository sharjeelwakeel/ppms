<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing tanks
check_access('tanks', 'show');

// Auto-migrate tbl_tanks to include deleted_at if missing
$chk_td = mysqli_query($connection, "SHOW COLUMNS FROM tbl_tanks LIKE 'deleted_at'");
if ($chk_td && mysqli_num_rows($chk_td) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_tanks ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$canAdd    = has_permission('tanks', 'add');
$canEdit   = has_permission('tanks', 'edit');
$canDelete = has_permission('tanks', 'delete');
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
        #tanksListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS Tanks</title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-gas-pump mr-2 text-primary"></i>View Tanks</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-tank.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Tank</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="tanksListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Tank Name</th>
							<th>Item</th>
							<th>Storage Capacity</th>
							<th>Created At</th>
							<th>Updated At</th>
							<th style="text-align: center;">Dip Chart</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT t.id, t.tank_name, t.storage_capacity, t.created_at, t.updated_at, i.name AS item_name, i.unit AS item_unit
								FROM tbl_tanks t
								LEFT JOIN tbl_items i ON t.item_id = i.id
								WHERE (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')
								ORDER BY t.id DESC";
						$result = mysqli_query($connection, $sql);
						if ($result && mysqli_num_rows($result) > 0) {
							while ($row = mysqli_fetch_assoc($result)) {
								$unit_suffix = !empty($row['item_unit']) ? ' ' . htmlspecialchars($row['item_unit']) : ' Litres';
                                $tankNameDisplay = $canEdit 
                                    ? '<a href="edit-tank.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['tank_name']).'</a>'
                                    : '<strong>'.htmlspecialchars($row['tank_name']).'</strong>';

								echo '
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$tankNameDisplay.'</td>
										<td>'.htmlspecialchars($row['item_name'] ?? '-').'</td>
										<td>'.number_format($row['storage_capacity'], 2) . $unit_suffix . '</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>
										<td class="text-center">
											<a href="dip-chart.php?tank_id='.$row['id'].'" class="btn btn-sm btn-info text-white font-weight-bold" style="border-radius:5px;" title="View Dip Chart"><i class="fas fa-chart-line mr-1"></i> Dip Chart</a>
										</td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletetank('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size:18px;"></i></a></td>';
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
		$('#tanksListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deletetank(id){
		if(confirm('Are you sure you want to delete this tank?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletetank.php",
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
