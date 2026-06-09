<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';

$message = '';
$date = isset($_GET['date']) ? mysqli_real_escape_string($connection, $_GET['date']) : date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    $attendance_data = isset($_POST['attendance']) ? $_POST['attendance'] : [];
    $post_date = mysqli_real_escape_string($connection, $_POST['date']);
    
    $success = true;
    foreach ($attendance_data as $staff_id => $status) {
        $staff_id = (int)$staff_id;
        $status = mysqli_real_escape_string($connection, $status);
        
        if ($staff_id > 0 && in_array($status, ['Present', 'Absent', 'Late', 'Leave'])) {
            $query = "INSERT INTO tbl_staff_attendance (staff_id, date, status) 
                      VALUES ($staff_id, '$post_date', '$status') 
                      ON DUPLICATE KEY UPDATE status = '$status'";
            if (!mysqli_query($connection, $query)) {
                $success = false;
                break;
            }
        }
    }
    
    if ($success) {
        $message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                        <strong>Success!</strong> Attendance for ' . date('d-m-Y', strtotime($post_date)) . ' saved successfully.
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>';
        $date = $post_date; // Update the display date to the saved date
    } else {
        $message = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Error!</strong> Failed to save attendance: ' . mysqli_error($connection) . '
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>';
    }
}

// Fetch all active staff members
$staff_sql = "SELECT s.id, s.first_name, s.last_name, r.name as role_name, sh.name as shift_name
              FROM tbl_staff s
              LEFT JOIN tbl_roles r ON s.role_id = r.id
              LEFT JOIN tbl_shifts sh ON s.shift_id = sh.id
              ORDER BY s.id DESC";
$staff_result = mysqli_query($connection, $staff_sql);

// Fetch already marked attendance for the selected date
$att_sql = "SELECT staff_id, status FROM tbl_staff_attendance WHERE date = '$date'";
$att_result = mysqli_query($connection, $att_sql);
$attendance_marked = [];
if ($att_result) {
    while ($row = mysqli_fetch_assoc($att_result)) {
        $attendance_marked[$row['staff_id']] = $row['status'];
    }
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Staff Attendance</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header) !important;
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(21,101,192,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }
        .list-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden;
            padding:20px;
        }
        .table thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        .table td { vertical-align:middle; font-size:13px; }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .btn-success {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-success:hover {
            opacity: 0.9;
        }
        
        /* Custom radio styling for attendance */
        .att-radio {
            position: relative;
            display: inline-block;
            margin-right: 15px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }
        .att-radio input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        .att-label {
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid #ddd;
            transition: all 0.2s;
            display: inline-block;
        }
        
        /* Present Colors */
        .att-radio-p input:checked ~ .att-label {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        /* Absent Colors */
        .att-radio-a input:checked ~ .att-label {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        /* Late Colors */
        .att-radio-l input:checked ~ .att-label {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }
        /* Leave Colors */
        .att-radio-le input:checked ~ .att-label {
            background-color: #e2e3e5;
            color: #383d41;
            border-color: #d6d8db;
        }
        
        .att-radio:hover .att-label {
            border-color: #bbb;
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
                    <h4><i class="fas fa-calendar-check mr-2"></i>Staff Attendance</h4>
                    <small style="opacity:.8;">Select date and mark daily attendance for staff members</small>
                </div>
            </div>

            <?php echo $message; ?>

            <!-- Filter Card -->
            <div class="card mb-4" style="border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.07);">
                <div class="card-body">
                    <form method="GET" action="attendance-list.php" class="form-inline">
                        <label class="my-1 mr-2 font-weight-bold" for="date">Select Date:</label>
                        <input type="date" name="date" id="date" class="form-control mr-sm-2" value="<?php echo htmlspecialchars($date); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        <button type="submit" class="btn btn-primary my-1"><i class="fas fa-search mr-1"></i> Load Date</button>
                    </form>
                </div>
            </div>

            <!-- List Card -->
            <div class="list-card">
                <form action="attendance-list.php" method="POST">
                    <input type="hidden" name="action" value="save_attendance">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 text-muted">Attendance Sheet for: <strong><?php echo date('d-M-Y (l)', strtotime($date)); ?></strong></h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-success mr-2" onclick="markAll('Present')">Mark All Present</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAll()">Clear All</button>
                        </div>
                    </div>
                    
                    <table class="table table-bordered table-striped" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <th>Staff Name</th>
                                <th>Role</th>
                                <th>Shift</th>
                                <th style="width: 450px; text-align: center;">Attendance Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($staff_result && mysqli_num_rows($staff_result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($staff_result)): 
                                    $fullName = $row['first_name'] . ' ' . $row['last_name'];
                                    $staff_id = $row['id'];
                                    $status = isset($attendance_marked[$staff_id]) ? $attendance_marked[$staff_id] : '';
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo $staff_id; ?></strong></td>
                                        <td><?php echo htmlspecialchars($fullName); ?></td>
                                        <td><?php echo htmlspecialchars($row['role_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['shift_name'] ?? 'N/A'); ?></td>
                                        <td style="text-align: center;">
                                            <label class="att-radio att-radio-p">
                                                <input type="radio" name="attendance[<?php echo $staff_id; ?>]" value="Present" class="radio-present" <?php echo ($status === 'Present') ? 'checked' : ''; ?> required>
                                                <span class="att-label"><i class="fas fa-check-circle mr-1"></i>Present</span>
                                            </label>
                                            <label class="att-radio att-radio-a">
                                                <input type="radio" name="attendance[<?php echo $staff_id; ?>]" value="Absent" class="radio-absent" <?php echo ($status === 'Absent') ? 'checked' : ''; ?>>
                                                <span class="att-label"><i class="fas fa-times-circle mr-1"></i>Absent</span>
                                            </label>
                                            <label class="att-radio att-radio-l">
                                                <input type="radio" name="attendance[<?php echo $staff_id; ?>]" value="Late" class="radio-late" <?php echo ($status === 'Late') ? 'checked' : ''; ?>>
                                                <span class="att-label"><i class="fas fa-clock mr-1"></i>Late</span>
                                            </label>
                                            <label class="att-radio att-radio-le">
                                                <input type="radio" name="attendance[<?php echo $staff_id; ?>]" value="Leave" class="radio-leave" <?php echo ($status === 'Leave') ? 'checked' : ''; ?>>
                                                <span class="att-label"><i class="fas fa-plane-departure mr-1"></i>Leave</span>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No staff members found. <a href="add-staff.php">Add some now.</a></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($staff_result && mysqli_num_rows($staff_result) > 0): ?>
                        <div class="text-center pt-3">
                            <button type="submit" class="btn btn-success px-5 py-2" style="font-weight:600; font-size:15px; box-shadow: 0 4px 12px rgba(46,125,50,0.25);">
                                <i class="fas fa-save mr-2"></i>Save Attendance Sheet
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
        function markAll(status) {
            if (status === 'Present') {
                $('.radio-present').prop('checked', true);
            }
        }
        function clearAll() {
            $('input[type="radio"]').prop('checked', false);
        }
    </script>
</body>
</html>
