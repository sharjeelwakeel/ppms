<?php
/**
 * Staff Leave Policy & Weekly Off Overview
 * PPMS (Petrol Pump Management System)
 */

require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';
require_once __DIR__ . '/../include/salary_payment_helper.php';

auto_migrate_salary_payment_tables($connection);

// Enforce access check for viewing leave setup
check_access('staff', 'show');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_global_leaves') {
    if (!has_permission('staff', 'edit')) {
        header('Location: ../unauthorized.php');
        exit;
    }
    $global_leaves = isset($_POST['global_leaves']) ? max(0, (int)$_POST['global_leaves']) : 2;

    if (set_global_paid_leaves($connection, $global_leaves)) {
        $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-1"></i> <strong>Success!</strong> Global monthly paid leave policy updated to <strong>' . $global_leaves . ' day(s)</strong> for all staff members.
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>';
    } else {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-1"></i> <strong>Error!</strong> Could not update leave policy: ' . mysqli_error($connection) . '
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>';
    }
}

$current_global_leaves = get_global_paid_leaves($connection);

// Fetch active staff members with their designations and weekly off
$sql = "SELECT s.id, s.first_name, s.last_name, s.weekly_off, r.name as role_name, sh.name as shift_name
        FROM tbl_staff s 
        LEFT JOIN tbl_staff_roles r ON s.role_id = r.id 
        LEFT JOIN tbl_shifts sh ON s.shift_id = sh.id
        WHERE (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
        ORDER BY s.id DESC";
$result = mysqli_query($connection, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="../include/css/roboto.css">
    <link rel="stylesheet" href="../include/css/bootstrap.min.css">
    <link rel="stylesheet" href="../include/css/all.min.css">
    <link rel="stylesheet" href="../include/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Staff Leave Policy &amp; Weekly Off</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header) !important;
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(4,32,78,0.18);
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
        .policy-banner {
            background: linear-gradient(135deg, #04204e 0%, #07347a 100%);
            color: #fff;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .policy-val {
            font-size: 26px;
            font-weight: 800;
            color: #28a745;
            background: #fff;
            padding: 2px 14px;
            border-radius: 6px;
            display: inline-block;
            margin: 0 6px;
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
                    <h4><i class="fas fa-calendar-minus mr-2"></i>Staff Leave Policy &amp; Weekly Off</h4>
                    <small style="opacity:.85;">Configure pump-wide monthly paid leaves policy and review weekly employee holidays</small>
                </div>
                <div>
                    <a href="salary-calculator.php" class="btn btn-outline-light btn-sm font-weight-bold">
                        <i class="fas fa-calculator mr-1"></i> Salary Calculator
                    </a>
                </div>
            </div>

            <?php echo $message; ?>

            <!-- Global Policy Banner -->
            <div class="policy-banner">
                <div>
                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1 mb-1 text-uppercase" style="font-size:11px;">
                        <i class="fas fa-globe mr-1"></i> Pump-Wide Policy (Option A: Same for All Staff)
                    </span>
                    <h4 class="mb-1 font-weight-bold">
                        Monthly Allowed Paid Leaves: <span class="policy-val"><?php echo $current_global_leaves; ?> Day(s)</span> / Month
                    </h4>
                    <p class="mb-0 text-light" style="font-size:13px; opacity:0.9;">
                        Every staff member is entitled to <strong><?php echo $current_global_leaves; ?> paid leave(s)</strong> per month without salary deduction. Any leaves beyond this limit are automatically deducted.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn btn-light font-weight-bold px-4 py-2" data-toggle="modal" data-target="#editGlobalLeaveModal" style="color:var(--primary-color); border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                        <i class="fas fa-edit mr-1"></i> Change Leave Policy
                    </button>
                </div>
            </div>

            <!-- List Card -->
            <div class="list-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0 font-weight-bold">
                        Active Staff Members &amp; Weekly Rest Days
                    </h5>
                    <span class="badge badge-light border p-2 text-dark font-weight-bold">
                        <i class="fas fa-info-circle text-info mr-1"></i> Weekly Holiday is set when adding/editing staff
                    </span>
                </div>
                <table id="leaveSetupTable" class="table table-bordered table-striped" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th>Employee Name</th>
                            <th>Role / Designation</th>
                            <th>Shift</th>
                            <th style="text-align:center;">Weekly Holiday</th>
                            <th style="text-align:center;">Monthly Paid Leaves</th>
                            <th style="text-align:center; width:120px;">Staff Profile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): 
                                $fullName = $row['first_name'] . ' ' . $row['last_name'];
                                $woff = !empty($row['weekly_off']) ? $row['weekly_off'] : 'Friday';
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $row['id']; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($fullName); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['role_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['shift_name'] ?? 'N/A'); ?></td>
                                    <td style="text-align:center;">
                                        <span class="badge badge-info px-3 py-2 font-weight-bold" style="font-size:12px;">
                                            <i class="fas fa-umbrella-beach mr-1"></i> <?php echo htmlspecialchars($woff); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="badge badge-success px-3 py-2" style="font-size:12px;">
                                            <i class="fas fa-check-circle mr-1"></i> <?php echo $current_global_leaves; ?> Day(s) / Month
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <a href="edit-staff.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-primary btn-sm font-weight-bold">
                                            <i class="fas fa-edit mr-1"></i> Edit Staff
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Edit Global Leave Modal -->
    <div class="modal fade" id="editGlobalLeaveModal" tabindex="-1" role="dialog" aria-labelledby="editGlobalLeaveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow">
                <form action="leave-setup.php" method="POST">
                    <input type="hidden" name="action" value="save_global_leaves">
                    <div class="modal-header text-white" style="background:var(--primary-gradient);">
                        <h5 class="modal-title font-weight-bold" id="editGlobalLeaveModalLabel">
                            <i class="fas fa-calendar-minus mr-2"></i> Update Global Paid Leaves Policy
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 mb-3" style="font-size:12px;">
                            <i class="fas fa-info-circle mr-1"></i> This monthly paid leave quota will automatically apply to <strong>all staff members</strong> across the petrol pump.
                        </div>
                        <div class="form-group mb-0">
                            <label for="global_leaves" class="font-weight-bold">Monthly Allowed Paid Leaves (for all staff):</label>
                            <input type="number" name="global_leaves" id="global_leaves" class="form-control form-control-lg font-weight-bold text-center" min="0" max="31" value="<?php echo $current_global_leaves; ?>" required placeholder="e.g. 2">
                            <small class="form-text text-muted mt-2">
                                Leaves taken up to this limit will be fully paid. Excess leaves will be deducted. (Set to 0 if no paid leaves allowed).
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold px-4" style="background:var(--primary-gradient); border:none;">
                            <i class="fas fa-save mr-1"></i> Save Policy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../include/js/jquery.min.js"></script>
    <script src="../include/js/popper.min.js"></script>
    <script src="../include/js/bootstrap.min.js"></script>
    <script src="../include/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#leaveSetupTable').DataTable({
                "order": [[0, "desc"]],
                "pageLength": 25
            });
        });
    </script>
</body>
</html>
