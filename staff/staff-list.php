<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing staff
check_access('staff', 'show');

// Auto-migrate tbl_staff if missing deleted_at or experience
$chk_st = mysqli_query($connection, "SHOW COLUMNS FROM tbl_staff LIKE 'deleted_at'");
if ($chk_st && mysqli_num_rows($chk_st) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_staff ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}
$chk_exp = mysqli_query($connection, "SHOW COLUMNS FROM tbl_staff LIKE 'experience'");
if ($chk_exp && mysqli_num_rows($chk_exp) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_staff ADD COLUMN experience VARCHAR(255) DEFAULT NULL AFTER salary");
}

$canAdd    = has_permission('staff', 'add');
$canEdit   = has_permission('staff', 'edit');
$canDelete = has_permission('staff', 'delete');
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
        #staffListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Staff</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-users mr-2 text-primary"></i>View Staff</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="staff-roles-list.php" class="btn btn-info font-weight-bold mr-2" style="border-radius:6px; background:linear-gradient(135deg, #17a2b8 0%, #117a8b 100%); border:none;"><i class="fas fa-id-badge mr-1"></i> Staff Designations</a>
                        <?php if ($canAdd): ?>
						<a href="add-staff.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Staff</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="staffListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Designation</th>
							<th>Joining Date</th>
							<th>Shift</th>
							<th>Salary</th>
							<th>Phone</th>
							<th>Guarantor</th>
                            <?php if ($canDelete): ?>
							<th style="text-align: center;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT s.*, r.name as role_name, sh.name as shift_name, g.name as guarantor_name, g.phone as guarantor_phone 
                                FROM tbl_staff s 
                                LEFT JOIN tbl_staff_roles r ON s.role_id = r.id 
                                LEFT JOIN tbl_shifts sh ON s.shift_id = sh.id 
                                LEFT JOIN tbl_staff_guarantors g ON s.id = g.staff_id
                                WHERE (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
                                ORDER BY s.id DESC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $fullName = $row['first_name'] . ' ' . $row['last_name'];
                                $guarantor_display = 'N/A';
                                if (!empty($row['guarantor_name'])) {
                                    $guarantor_display = htmlspecialchars($row['guarantor_name']);
                                    if (!empty($row['guarantor_phone'])) {
                                        $guarantor_display .= ' (' . htmlspecialchars($row['guarantor_phone']) . ')';
                                    }
                                }
                                $staffNameDisplay = $canEdit 
                                    ? '<a href="edit-staff.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($fullName).'</a>'
                                    : '<strong>'.htmlspecialchars($fullName).'</strong>';

								echo' 
									<tr>
										<td>'.$row['id'].'</td>
										<td>'.$staffNameDisplay.'</td>
										<td>'.htmlspecialchars($row['role_name'] ?? 'N/A').'</td>
										<td>'.date("d-m-Y", strtotime($row['joining_date'])).'</td>
										<td>'.htmlspecialchars($row['shift_name'] ?? 'N/A').'</td>
										<td>'.number_format($row['salary'], 2).'</td>
										<td>'.htmlspecialchars($row['phone']).'</td>
										<td>'.$guarantor_display.'</td>';
                                if ($canDelete) {
                                    echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletestaff('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
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
		$('#staffListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deletestaff(id){
		if(confirm('Are you sure you want to delete this staff member?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletestaff.php",
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
