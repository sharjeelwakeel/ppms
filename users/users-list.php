<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing system users
check_access('users', 'show');

// Auto-migrate tbl_accounts if needed
$chk_role = mysqli_query($connection, "SHOW COLUMNS FROM tbl_accounts LIKE 'role_id'");
if ($chk_role && mysqli_num_rows($chk_role) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_accounts ADD COLUMN role_id INT DEFAULT NULL");
}
$chk_del = mysqli_query($connection, "SHOW COLUMNS FROM tbl_accounts LIKE 'deleted_at'");
if ($chk_del && mysqli_num_rows($chk_del) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_accounts ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}
$chk_ca = mysqli_query($connection, "SHOW COLUMNS FROM tbl_accounts LIKE 'created_at'");
if ($chk_ca && mysqli_num_rows($chk_ca) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_accounts ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
$chk_rd = mysqli_query($connection, "SHOW COLUMNS FROM tbl_roles LIKE 'deleted_at'");
if ($chk_rd && mysqli_num_rows($chk_rd) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_roles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}
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
    <title>PPMS - System Users</title>
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

        #usersTable thead th {
            background: var(--primary-color) !important;
            color: #fff !important;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<?php include('../include/navbar.php');?>
<main class="main">
    <div class="container-fluid pt-4 pb-4 px-lg-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-users-cog mr-2"></i>System Users</h4>
                <small class="text-white-50">Manage web system login credentials and role assignments</small>
            </div>
            <div>
                <?php if (has_permission('users', 'add')): ?>
                <a href="add-user.php" class="btn btn-primary font-weight-bold" style="border-radius:8px;">
                    <i class="fas fa-user-plus mr-1"></i> Add New User
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive bg-white p-3 rounded shadow-sm">
            <table id="usersTable" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Phone Number</th>
                        <th style="text-align: center;">Role</th>
                        <th style="text-align: center;">Account Type</th>
                        <th>Created At</th>
                        <th style="text-align: center; width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sql = "SELECT a.*, r.name as role_name 
                            FROM tbl_accounts a 
                            LEFT JOIN tbl_roles r ON a.role_id = r.id 
                            WHERE a.deleted_at IS NULL 
                            ORDER BY a.id DESC";
                    $result = mysqli_query($connection, $sql);
                    if ($result && mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $isAdmin = ($row['id'] == 1 || strtolower(trim($row['type'] ?? '')) === 'admin' || strtolower(trim($row['username'] ?? '')) === 'admin' || strtolower(trim($row['role_name'] ?? '')) === 'admin');
                            
                            $canEdit = has_permission('users', 'edit');
                            $canDelete = has_permission('users', 'delete') && !$isAdmin;

                            $nameDisplay = $canEdit 
                                ? '<a href="edit-user.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);"><i class="fas fa-user-circle mr-1 text-muted"></i>'.htmlspecialchars($row['name'] ?? 'N/A').'</a>'
                                : '<strong>'.htmlspecialchars($row['name'] ?? 'N/A').'</strong>';

                            $roleTitle = !empty($row['role_name']) ? $row['role_name'] : ($isAdmin ? 'Super Admin' : 'Standard User');
                            $roleBadge = '<span class="badge badge-info px-2 py-1" style="font-size:11px;"><i class="fas fa-user-shield mr-1"></i>'.htmlspecialchars($roleTitle).'</span>';

                            if ($isAdmin) {
                                $typeBadge = '<span class="badge badge-primary px-2 py-1" style="font-size:11px;"><i class="fas fa-crown mr-1"></i>Super Admin</span>';
                            } else {
                                $typeBadge = '<span class="badge badge-light border px-2 py-1" style="font-size:11px;">Standard User</span>';
                            }

                            $createdDate = (!empty($row['created_at'])) ? date("d-m-Y h:i A", strtotime($row['created_at'])) : '—';

                            echo '<tr>
                                <td class="font-weight-bold text-muted">'.$row['id'].'</td>
                                <td>'.$nameDisplay.'</td>
                                <td><code style="font-size:13px; color:#04204e;">'.htmlspecialchars($row['username'] ?? '').'</code></td>
                                <td>'.htmlspecialchars($row['phonenumber'] ?? '—').'</td>
                                <td class="text-center">'.$roleBadge.'</td>
                                <td class="text-center">'.$typeBadge.'</td>
                                <td>'.$createdDate.'</td>
                                <td class="text-center">';
                            
                            if ($canEdit) {
                                echo '<a href="edit-user.php?id='.$row['id'].'" class="text-primary mr-2" title="Edit User"><i class="fas fa-edit" style="font-size: 16px;"></i></a>';
                            }
                            if ($canDelete) {
                                echo '<a class="text-danger" onclick="deleteUser('.$row['id'].')" title="Delete User" style="cursor:pointer;"><i class="fas fa-trash-alt" style="font-size: 16px;"></i></a>';
                            } else if ($isAdmin) {
                                echo '<span class="text-muted" style="font-size:11px;">Protected</span>';
                            } else if (!$canEdit) {
                                echo '<span class="text-muted" style="font-size:11px;">No Access</span>';
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
    $('#usersTable').DataTable({
        "order": [[ 0, "desc" ]]
    });
});

function deleteUser(id) {
    if (confirm('Are you sure you want to delete this system user account?')) {
        $.ajax({
            type: "POST",
            url: "../include/deleteuser.php",
            data: { id: id },
            success: function (res) {
                if (res.trim() === 'User deleted.') {
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
