<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing leave setup
check_access('staff', 'show');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_leaves') {
    if (!has_permission('staff', 'edit')) {
        header('Location: ../unauthorized.php');
        exit;
    }
    $staff_id = (int)$_POST['staff_id'];
    $allowed_leaves = (int)$_POST['allowed_leaves'];

    if ($staff_id > 0 && $allowed_leaves >= 0) {
        $query = "INSERT INTO tbl_leave_setup (staff_id, allowed_leaves) 
                  VALUES ($staff_id, $allowed_leaves) 
                  ON DUPLICATE KEY UPDATE allowed_leaves = $allowed_leaves";
        
        if (mysqli_query($connection, $query)) {
            $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                            <strong>Success!</strong> Leave limit updated successfully.
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>';
        } else {
            $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> Could not update leave setup: ' . mysqli_error($connection) . '
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>';
        }
    }
}

// Fetch staff members with their roles and allowed leaves
$sql = "SELECT s.id, s.first_name, s.last_name, r.name as role_name, ls.monthly_allowed_leaves, ls.leave_deduction_rate 
        FROM tbl_staff s 
        LEFT JOIN tbl_staff_roles r ON s.role_id = r.id 
        LEFT JOIN tbl_leave_setup ls ON s.id = ls.staff_id 
        ORDER BY s.id DESC";
$result = mysqli_query($connection, $sql);
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
    <title>PPMS - Leave Setup</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header) !important;
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(46,125,50,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }
        .list-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden;
            padding: 20px;
        }
        .table thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        .table td { vertical-align:middle; font-size:13px; }
        .btn-edit {
            background: var(--primary-gradient) !important;
            border: none; color: #fff; font-weight: 600;
            padding: 5px 12px; border-radius: 6px; font-size: 12px;
            transition: all .2s;
        }
        .btn-edit:hover { opacity: .9; color: #fff; transform: translateY(-1px); }
        .btn-success {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-success:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <?php include('../include/navbar.php'); ?>
    <main class="main">
        <div class="container pt-4 pb-5">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h4><i class="fas fa-calendar-minus mr-2"></i>Leave Setup</h4>
                    <small style="opacity:.8;">Set the monthly allowed paid leaves for each employee</small>
                </div>
            </div>

            <?php echo $message; ?>

            <!-- List Card -->
            <div class="list-card">
                <table id="leaveSetupTable" class="table table-bordered table-striped" style="width:100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Employee Name</th>
                            <th>Role</th>
                            <th>Allowed Leaves (per Month)</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): 
                                $fullName = $row['first_name'] . ' ' . $row['last_name'];
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $row['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($fullName); ?></td>
                                    <td><?php echo htmlspecialchars($row['role_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-success px-3 py-2" style="font-size:13px; background: var(--primary-color) !important;">
                                            <?php echo htmlspecialchars($row['allowed_leaves']); ?> Day(s)
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <button class="btn btn-edit btn-sm" 
                                                onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($fullName)); ?>', <?php echo $row['allowed_leaves']; ?>)">
                                            <i class="fas fa-edit mr-1"></i> Edit Setup
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Edit Leave Modal -->
    <div class="modal fade" id="editLeaveModal" tabindex="-1" role="dialog" aria-labelledby="editLeaveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form action="leave-setup.php" method="POST">
                    <input type="hidden" name="action" value="save_leaves">
                    <input type="hidden" name="staff_id" id="modal_staff_id">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="editLeaveModalLabel"><i class="fas fa-calendar-minus mr-2"></i>Configure Allowed Leaves</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Employee Name</label>
                            <input type="text" id="modal_staff_name" class="form-control" readonly style="background:#e9ecef;">
                        </div>
                        <div class="form-group">
                            <label for="modal_allowed_leaves">Monthly Allowed Leaves</label>
                            <input type="number" name="allowed_leaves" id="modal_allowed_leaves" class="form-control" min="0" max="31" required placeholder="e.g. 2">
                            <small class="form-text text-muted">The employee will not face salary deduction for leaves up to this limit.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#leaveSetupTable').DataTable({
                "order": [[0, "desc"]]
            });
        });

        function openEditModal(id, name, leaves) {
            $('#modal_staff_id').val(id);
            $('#modal_staff_name').val(name);
            $('#modal_allowed_leaves').val(leaves);
            $('#editLeaveModal').modal('show');
        }
    </script>
</body>
</html>
