<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing system users
check_access('users', 'edit');

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    header('Location: users-list.php');
    exit;
}

// Auto-migrate tbl_roles to add deleted_at if missing
$chk_rd = mysqli_query($connection, "SHOW COLUMNS FROM tbl_roles LIKE 'deleted_at'");
if ($chk_rd && mysqli_num_rows($chk_rd) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_roles ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

// Fetch user record
$sql = "SELECT * FROM tbl_accounts WHERE id='$id' AND deleted_at IS NULL LIMIT 1";
$result = mysqli_query($connection, $sql);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    header('Location: users-list.php');
    exit;
}

$message = '';

if (isset($_POST['submit'])) {
    $name        = mysqli_real_escape_string($connection, trim($_POST['name']));
    $username    = mysqli_real_escape_string($connection, trim($_POST['username']));
    $phonenumber = mysqli_real_escape_string($connection, trim($_POST['phonenumber']));
    $role_id     = intval($_POST['role_id']);
    $new_pass    = trim($_POST['password'] ?? '');

    if (empty($name) || empty($username)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Name and Username are required.</div>';
    } else {
        // Check username uniqueness (excluding current user ID)
        $check_user = mysqli_query($connection, "SELECT id FROM tbl_accounts WHERE username = '$username' AND id != '$id' AND deleted_at IS NULL LIMIT 1");
        if ($check_user && mysqli_num_rows($check_user) > 0) {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Username "' . htmlspecialchars($username) . '" is already in use by another user.</div>';
        } else {
            $pass_update_sql = "";
            if (!empty($new_pass)) {
                $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT);
                $pass_update_sql = ", password = '$hashed_pass'";
            }

            $query = "UPDATE tbl_accounts SET 
                        name = '$name', 
                        username = '$username', 
                        phonenumber = '$phonenumber', 
                        role_id = '$role_id' 
                        $pass_update_sql 
                      WHERE id = '$id'";
            
            if (mysqli_query($connection, $query)) {
                header('Location: users-list.php');
                exit;
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error updating user: ' . mysqli_error($connection) . '</div>';
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
    <title>PPMS - Edit System User #<?php echo $id; ?></title>
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
                <h4><i class="fas fa-user-edit mr-2"></i>Edit System User #<?php echo $id; ?></h4>
                <small class="text-white-50">Update login account details, assigned role, or generate a new password</small>
            </div>
            <a href="users-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to Users
            </a>
        </div>

        <?php echo $message; ?>

        <form action="edit-user.php?id=<?php echo $id; ?>" method="POST" id="userForm">
            <div class="card-custom">
                <div class="card-body p-4">
                    <div class="row">
                        <!-- Full Name -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-user text-primary mr-1"></i> Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" style="border-radius:7px;" required>
                        </div>

                        <!-- Username -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-at text-primary mr-1"></i> Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" style="border-radius:7px;" required>
                        </div>

                        <!-- Phone Number -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-phone text-primary mr-1"></i> Phone Number
                            </label>
                            <input type="text" name="phonenumber" class="form-control" value="<?php echo htmlspecialchars($user['phonenumber'] ?? ''); ?>" style="border-radius:7px;">
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
                                        $selected = ($user['role_id'] == $role['id']) ? 'selected' : '';
                                        echo '<option value="' . $role['id'] . '" ' . $selected . '>' . htmlspecialchars($role['name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Password (Optional on Edit) -->
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold" style="font-size:13px; color:#444;">
                                <i class="fas fa-key text-warning mr-1"></i> Update Password
                                <small class="text-muted ml-2">(Leave blank to keep current password)</small>
                            </label>
                            <div class="input-group">
                                <input type="text" name="password" id="passwordInput" class="form-control font-weight-bold" placeholder="Enter new password or click Generate" style="border-radius:7px 0 0 7px; font-family:monospace; letter-spacing:1px;">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-gen font-weight-bold" onclick="generateRandomPassword()" title="Generate New Password">
                                        <i class="fas fa-magic mr-1"></i> Generate New Password
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
                    <i class="fas fa-save mr-1"></i> Update System User
                </button>
            </div>
        </form>

    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
function generateRandomPassword() {
    var chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789#@$%!";
    var pass = "Ppms#";
    for (var i = 0; i < 7; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    $('#passwordInput').val(pass);
}

function copyPasswordToClipboard() {
    var passVal = $('#passwordInput').val();
    if (passVal) {
        navigator.clipboard.writeText(passVal).then(function() {
            $('#copyNotice').fadeIn().delay(2000).fadeOut();
        });
    }
}
</script>
</body>
</html>
