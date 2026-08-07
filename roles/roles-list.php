<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing roles list
check_access('roles', 'show');

$admin_blocked_msg = '';
if (isset($_SESSION['admin_edit_blocked'])) {
    $admin_blocked_msg = $_SESSION['admin_edit_blocked'];
    unset($_SESSION['admin_edit_blocked']);
}
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
        #rolesListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Roles & Permissions</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
                <?php echo $admin_blocked_msg; ?>
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-user-shield text-primary mr-2"></i>Roles &amp; Permissions</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if (has_permission('roles', 'add')): ?>
						<a href="add-role.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Role</a>
                        <?php endif; ?>
					</div>
				</div>
				<table id="rolesListTable" class="table table-striped table-bordered">
					<thead>
						<tr>
							<th style="width: 50px;">ID</th>
							<th>Role Name</th>
							<th style="text-align: center;">Assigned Modules</th>
							<th>Created At</th>
							<th>Updated At</th>
                            <?php if (has_permission('roles', 'delete')): ?>
							<th style="text-align: center; width: 60px;">Delete</th>
                            <?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php 
						$sql = "SELECT r.*, 
								       (SELECT COUNT(*) FROM tbl_role_permissions rp WHERE rp.role_id = r.id AND (rp.can_show=1 OR rp.can_add=1 OR rp.can_edit=1 OR rp.can_delete=1)) as perm_count
								FROM tbl_roles r 
								ORDER BY r.id ASC";
						$result = mysqli_query($connection, $sql);
						if($result && mysqli_num_rows($result) > 0){
							while($row = mysqli_fetch_assoc($result)){
                                $isAdminRole = ($row['id'] == 1 || strtolower(trim($row['name'])) === 'admin');
                                $canEditRole = has_permission('roles', 'edit') && !$isAdminRole;

                                if ($isAdminRole) {
                                    $roleNameHtml = '<strong><i class="fas fa-shield-alt text-primary mr-1"></i>'.htmlspecialchars($row['name']).'</strong> <span class="badge badge-primary ml-1" style="font-size:10px;">System Admin (Protected)</span>';
                                    $permBadgeHtml = '<span class="badge badge-success px-2 py-1" style="font-size:11px;"><i class="fas fa-check-circle mr-1"></i>Full Access (All Modules)</span>';
                                } else {
                                    $roleNameHtml = $canEditRole 
                                        ? '<a href="edit-role.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);"><i class="fas fa-user-tag mr-1 text-muted"></i>'.htmlspecialchars($row['name']).'</a>'
                                        : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                    $permBadgeHtml = '<span class="badge badge-info px-2 py-1" style="font-size:11px;"><i class="fas fa-key mr-1"></i>'.$row['perm_count'].' Modules Active</span>';
                                }

								echo ' 
									<tr>
										<td class="font-weight-bold text-muted">'.$row['id'].'</td>
										<td>'.$roleNameHtml.'</td>
										<td class="text-center">'.$permBadgeHtml.'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
										<td>'.date("d-m-Y h:i A", strtotime($row['updated_at'])).'</td>';
                                if (has_permission('roles', 'delete')) {
                                    if ($isAdminRole) {
                                        echo '<td class="text-center"><span class="text-muted" style="font-size:11px;">Protected</span></td>';
                                    } else {
                                        echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleterole('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
                                    }
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
		$('#rolesListTable').DataTable({
			"order": [[ 0, "asc" ]]
		});
	});

	function deleterole(id){
		if(confirm('Are you sure you want to delete this role and its permissions?')) {
			$.ajax({
				type: "POST",
				url: "../include/deleterole.php",
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
