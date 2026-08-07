<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding system users
check_access('users', 'add');

// Auto-migrate tbl_roles to add deleted_at if missing
$chk_rd = mysqli_query($connection, "SHOW COLUMNS FROM tbl_roles LIKE 'deleted_at'");
if ($chk_rd && mysqli_num_rows($chk_rd) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_roles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

$message = '';

if (isset($_POST['submit'])) {
    $name        = mysqli_real_escape_string($connection, trim($_POST['name']));
    $username    = mysqli_real_escape_string($connection, trim($_POST['username']));
    $phonenumber = mysqli_real_escape_string($connection, trim($_POST['phonenumber']));
    $role_id     = intval($_POST['role_id']);
    $raw_pass    = trim($_POST['password']);

    if (empty($name) || empty($username) || empty($raw_pass)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Please fill in all required fields.</div>';
    } else {
        // Check username uniqueness
        $check_user = mysqli_query($connection, "SELECT id FROM tbl_accounts WHERE username = '$username' AND deleted_at IS NULL LIMIT 1");
        if ($check_user && mysqli_num_rows($check_user) > 0) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Username "' . htmlspecialchars($username) . '" is already taken. Please choose another.</div>';
        } else {
            $hashed_pass = password_hash($raw_pass, PASSWORD_BCRYPT);
            
            $query = "INSERT INTO tbl_accounts (name, username, password, phonenumber, role_id, type) 
                      VALUES ('$name', '$username', '$hashed_pass', '$phonenumber', '$role_id', 'user')";
            
            if (mysqli_query($connection, $query)) {
                header('Location: users-list.php');
                exit;
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error creating user: ' . mysqli_error($connection) . '</div>';
            }
        }
    }
}

// Fetch active roles for dropdown
$roles_sql    = "SELECT id, name FROM tbl_roles WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC";
$roles_result = mysqli_query($connection, $roles_sql);
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
    <title>PPMS - Add System User</title>
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

        .btn-gen {
            background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
            color: #fff;
            font-weight: 700;
            border: none;
        }
        .btn-gen:hover { color: #fff; opacity: 0.95; }

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
                <h4><i class="fas fa-user-plus mr-2"></i>Add System User</h4>
                <small class="text-white-50">Create a new web application login account and assign a role</small>
            </div>
            <a href="users-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to Users
            </a>
        </div>

        <?php echo $message; ?>

        <form action="add-user.php" method="POST" id="userForm">
            <div class="card-custom">
                <div class="card-body p-4">
                    <div class="row">
                        <!-- Full Name -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-user text-primary mr-1"></i> Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. John Doe" style="border-radius:7px;" required>
                        </div>

                        <!-- Username -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-at text-primary mr-1"></i> Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. user123" style="border-radius:7px;" required>
                        </div>

                        <!-- Phone Number -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-phone text-primary mr-1"></i> Phone Number
                            </label>
                            <input type="text" name="phonenumber" class="form-control" placeholder="e.g. 03001234567" style="border-radius:7px;">
                        </div>

                        <!-- Assigned Role -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-user-shield text-primary mr-1"></i> Assign Role <span class="text-danger">*</span>
                            </label>
                            <select name="role_id" class="form-control" style="border-radius:7px;" required>
                                <option value="">-- Select Role --</option>
                                <?php
                                if ($roles_result && mysqli_num_rows($roles_result) > 0) {
                                    while ($role = mysqli_fetch_assoc($roles_result)) {
                                        echo '<option value="' . $role['id'] . '">' . htmlspecialchars($role['name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Password (Automatically Generated) -->
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-key text-warning mr-1"></i> Password <span class="text-danger">*</span>
                                <small class="text-success ml-2">(Automatically Generated Secure Password)</small>
                            </label>
                            <div class="input-group">
                                <input type="text" name="password" id="passwordInput" class="form-control font-weight-bold" style="border-radius:7px 0 0 7px; font-family:monospace; letter-spacing:1px;" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-gen font-weight-bold" onclick="generateRandomPassword()" title="Generate New Password">
                                        <i class="fas fa-sync-alt mr-1"></i> Regenerate
                                    </button>
                                    <button type="button" class="btn btn-secondary font-weight-bold" onclick="togglePasswordVisibility()" id="btnToggle">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </button>
                                    <button type="button" class="btn btn-dark font-weight-bold" onclick="copyPasswordToClipboard()" title="Copy to Clipboard">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted" id="copyNotice" style="display:none; color:#28a745 !important;">
                                <i class="fas fa-check-circle"></i> Password copied to clipboard!
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="text-right">
                <a href="users-list.php" class="btn btn-secondary mr-2" style="border-radius:8px;">Cancel</a>
                <button type="submit" name="submit" class="btn-save btn">
                    <i class="fas fa-user-check mr-1"></i> Create System User
                </button>
            </div>
        </form>

    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
/** Generate strong random password combining uppercase, lowercase, numbers, and symbols */
function generateRandomPassword() {
    var chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789#@$%!";
    var pass = "Ppms#";
    for (var i = 0; i < 7; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    $('#passwordInput').val(pass);
}

function togglePasswordVisibility() {
    var field = $('#passwordInput');
    var icon = $('#toggleIcon');
    if (field.attr('type') === 'password') {
        field.attr('type', 'text');
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
    } else {
        field.attr('type', 'password');
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
    }
}

function copyPasswordToClipboard() {
    var passVal = $('#passwordInput').val();
    if (passVal) {
        navigator.clipboard.writeText(passVal).then(function() {
            $('#copyNotice').fadeIn().delay(2000).fadeOut();
        });
    }
}

$(document).ready(function() {
    generateRandomPassword();
});
</script>
</body>
</html>
