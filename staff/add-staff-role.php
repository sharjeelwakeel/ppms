<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding staff
check_access('staff', 'add');

$message = '';

if (isset($_POST['name']) && !empty($_POST['name'])) {
    $name = mysqli_real_escape_string($connection, trim($_POST['name']));

    $query = "INSERT INTO tbl_staff_roles (name) VALUES ('$name')";
    if (mysqli_query($connection, $query)) {
        header('Location: staff-roles-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error saving staff designation: ' . mysqli_error($connection) . '</div>';
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
    <title>PPMS - Add Staff Designation</title>
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

        .card-custom {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border: none;
            margin-bottom: 22px;
            overflow: hidden;
        }

        .btn-save {
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            padding: 10px 30px;
            border-radius: 8px;
            border: none;
            box-shadow: 0 4px 14px rgba(4,32,78,0.25);
        }
        .btn-save:hover { opacity: 0.95; color: #fff; }
    </style>
</head>
<body>
<?php include('../include/navbar.php');?>
<main class="main">
    <div class="container pt-4 pb-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-id-badge mr-2"></i>Add Staff Designation</h4>
                <small class="text-white-50">Create a new employee job role / title for physical staff members</small>
            </div>
            <a href="staff-roles-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to Designations
            </a>
        </div>

        <?php echo $message; ?>

        <form action="add-staff-role.php" method="POST">
            <div class="card-custom">
                <div class="card-body p-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark" style="font-size: 14px;">
                            <i class="fas fa-tag text-primary mr-1"></i> Designation Title / Job Role <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" class="form-control col-md-6" placeholder="e.g. Sales Executive / Fuel Attendant / Helper" style="border-radius: 7px; font-size: 14px;" required>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="text-right">
                <a href="staff-roles-list.php" class="btn btn-secondary mr-2" style="border-radius:8px;">Cancel</a>
                <button type="submit" class="btn-save btn">
                    <i class="fas fa-save mr-1"></i> Save Designation
                </button>
            </div>
        </form>

    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>
