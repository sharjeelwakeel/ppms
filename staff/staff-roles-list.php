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

// Auto-migrate tbl_staff_roles table if needed
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_staff_roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Staff Designations</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }

        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 18px 28px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.3rem; }

        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }

        #staffRolesTable thead th {
            background: var(--primary-color) !important;
            color: #fff !important;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<?php include('../include/navbar.php');?>
<main class="main">
    <div class="container pt-4 pb-4">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-id-badge mr-2"></i>Staff Designations / Job Titles</h4>
                <small class="text-white-50">Manage employee job roles (Sales Executive, Fuel Attendant, Helper, etc.)</small>
            </div>
            <div>
                <a href="staff-list.php" class="btn btn-sm btn-light font-weight-bold mr-2" style="border-radius:6px; color:#04204e;">
                    <i class="fas fa-users mr-1"></i> View Staff Members
                </a>
                <?php if (has_permission('staff', 'add')): ?>
                <a href="add-staff-role.php" class="btn btn-primary font-weight-bold" style="border-radius:8px;">
                    <i class="fas fa-plus mr-1"></i> Add Designation
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive bg-white p-3 rounded shadow-sm">
            <table id="staffRolesTable" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Designation Name</th>
                        <th style="text-align: center;">Assigned Staff Members</th>
                        <th>Created At</th>
                        <th style="text-align: center; width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sql = "SELECT sr.*, 
                                   (SELECT COUNT(*) FROM tbl_staff s WHERE s.role_id = sr.id) as staff_count 
                            FROM tbl_staff_roles sr 
                            WHERE sr.deleted_at IS NULL 
                            ORDER BY sr.id ASC";
                    $result = mysqli_query($connection, $sql);
                    if ($result && mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $canEdit = has_permission('staff', 'edit');
                            $canDelete = has_permission('staff', 'delete');

                            $nameDisplay = $canEdit 
                                ? '<a href="edit-staff-role.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);"><i class="fas fa-user-tag mr-1 text-muted"></i>'.htmlspecialchars($row['name']).'</a>'
                                : '<strong>'.htmlspecialchars($row['name']).'</strong>';

                            echo '<tr>
                                <td class="font-weight-bold text-muted">'.$row['id'].'</td>
                                <td>'.$nameDisplay.'</td>
                                <td class="text-center">
                                    <span class="badge badge-info px-2 py-1" style="font-size:11px;">
                                        <i class="fas fa-user mr-1"></i>'.$row['staff_count'].' Employees
                                    </span>
                                </td>
                                <td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>
                                <td class="text-center">';
                            
                            if ($canEdit) {
                                echo '<a href="edit-staff-role.php?id='.$row['id'].'" class="text-primary mr-2" title="Edit Designation"><i class="fas fa-edit" style="font-size: 16px;"></i></a>';
                            }
                            if ($canDelete) {
                                echo '<a class="text-danger" onclick="deleteStaffRole('.$row['id'].')" title="Delete Designation" style="cursor:pointer;"><i class="fas fa-trash-alt" style="font-size: 16px;"></i></a>';
                            }
                            
                            echo '</td>
                            </tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>

    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    $('#staffRolesTable').DataTable({
        "order": [[ 0, "asc" ]]
    });
});

function deleteStaffRole(id) {
    if (confirm('Are you sure you want to delete this staff designation?')) {
        $.ajax({
            type: "POST",
            url: "../include/deletestaffrole.php",
            data: { id: id },
            success: function (res) {
                if (res.trim() === 'Staff designation deleted.') {
                    location.reload();
                } else {
                    alert(res);
                }
            },
            error: function (err) {
                console.log(err);
                alert('Server error occurred.');
            }
        });
    }
}
</script>
</body>
</html>
