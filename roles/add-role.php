<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for creating roles
check_access('roles', 'add');

$modules = get_system_modules();
$message = '';

if (isset($_POST['submit']) && !empty($_POST['name'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);

    mysqli_begin_transaction($connection);
    try {
        $query = "INSERT INTO tbl_roles (name) VALUES ('$name')";
        if (mysqli_query($connection, $query)) {
            $role_id = mysqli_insert_id($connection);

            // Loop through modules and save permissions
            if (isset($_POST['perm']) && is_array($_POST['perm'])) {
                foreach ($modules as $slug => $label) {
                    $can_show   = isset($_POST['perm'][$slug]['show']) ? 1 : 0;
                    $can_add    = isset($_POST['perm'][$slug]['add']) ? 1 : 0;
                    $can_edit   = isset($_POST['perm'][$slug]['edit']) ? 1 : 0;
                    $can_delete = isset($_POST['perm'][$slug]['delete']) ? 1 : 0;

                    $perm_sql = "INSERT INTO tbl_role_permissions 
                                 (role_id, module_slug, can_show, can_add, can_edit, can_delete) 
                                 VALUES ('$role_id', '$slug', '$can_show', '$can_add', '$can_edit', '$can_delete')";
                    mysqli_query($connection, $perm_sql);
                }
            }

            mysqli_commit($connection);
            header('Location: roles-list.php');
            exit;
        } else {
            throw new Exception(mysqli_error($connection));
        }
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error saving role: ' . $e->getMessage() . '</div>';
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="../include/style.css?v=1.0.1" />
    <title>PPMS - Add Role & Permissions</title>
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

        .perm-table thead th {
            background: #04204e;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            vertical-align: middle;
            text-align: center;
            border: none;
        }
        .perm-table tbody td {
            vertical-align: middle;
            font-size: 13px;
            padding: 10px 14px;
        }
        .perm-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .custom-control-label { cursor: pointer; font-size: 12px; }
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
                <h4><i class="fas fa-user-shield mr-2"></i>Add Role &amp; Module Permissions</h4>
                <small class="text-white-50">Create a new system role and assign module access permissions</small>
            </div>
            <a href="roles-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to Roles
            </a>
        </div>

        <?php echo $message; ?>

        <form action="add-role.php" method="POST">

            <!-- Role Name Card -->
            <div class="card-custom">
                <div class="card-body p-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark" style="font-size: 14px;"><i class="fas fa-tag text-primary mr-1"></i> Role Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control col-md-6" placeholder="e.g. Shift Manager / Accountant" style="border-radius: 7px; font-size: 14px;" required>
                    </div>
                </div>
            </div>

            <!-- Permission Matrix Table Card -->
            <div class="card-custom">
                <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between border-bottom">
                    <h5 class="mb-0 font-weight-bold text-dark" style="font-size: 15px;"><i class="fas fa-key text-warning mr-2"></i>Module Access Permissions</h5>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="checkAllGlobal" onclick="toggleGlobalCheckboxes(this)">
                        <label class="custom-control-label font-weight-bold text-primary" for="checkAllGlobal">Select All Permissions</label>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered perm-table mb-0">
                        <thead>
                            <tr>
                                <th style="text-align:left; width: 30%;">Module Name</th>
                                <th style="width: 15%;"><i class="fas fa-eye text-info mr-1"></i> Show / View</th>
                                <th style="width: 15%;"><i class="fas fa-plus text-success mr-1"></i> Add / Create</th>
                                <th style="width: 15%;"><i class="fas fa-edit text-warning mr-1"></i> Edit / Update</th>
                                <th style="width: 15%;"><i class="fas fa-trash-alt text-danger mr-1"></i> Delete</th>
                                <th style="width: 10%;">Row Check</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modules as $slug => $label): ?>
                            <tr>
                                <td>
                                    <strong style="color: #04204e;"><i class="fas fa-cube text-muted mr-2"></i><?php echo htmlspecialchars($label); ?></strong>
                                </td>

                                <!-- Show -->
                                <td class="text-center">
                                    <div class="custom-control custom-checkbox d-inline-block">
                                        <input type="checkbox" name="perm[<?php echo $slug; ?>][show]" value="1" class="custom-control-input perm-cb perm-<?php echo $slug; ?>" id="show_<?php echo $slug; ?>" checked>
                                        <label class="custom-control-label" for="show_<?php echo $slug; ?>">Show</label>
                                    </div>
                                </td>

                                <!-- Add -->
                                <td class="text-center">
                                    <div class="custom-control custom-checkbox d-inline-block">
                                        <input type="checkbox" name="perm[<?php echo $slug; ?>][add]" value="1" class="custom-control-input perm-cb perm-<?php echo $slug; ?>" id="add_<?php echo $slug; ?>">
                                        <label class="custom-control-label" for="add_<?php echo $slug; ?>">Add</label>
                                    </div>
                                </td>

                                <!-- Edit -->
                                <td class="text-center">
                                    <div class="custom-control custom-checkbox d-inline-block">
                                        <input type="checkbox" name="perm[<?php echo $slug; ?>][edit]" value="1" class="custom-control-input perm-cb perm-<?php echo $slug; ?>" id="edit_<?php echo $slug; ?>">
                                        <label class="custom-control-label" for="edit_<?php echo $slug; ?>">Edit</label>
                                    </div>
                                </td>

                                <!-- Delete -->
                                <td class="text-center">
                                    <div class="custom-control custom-checkbox d-inline-block">
                                        <input type="checkbox" name="perm[<?php echo $slug; ?>][delete]" value="1" class="custom-control-input perm-cb perm-<?php echo $slug; ?>" id="delete_<?php echo $slug; ?>">
                                        <label class="custom-control-label" for="delete_<?php echo $slug; ?>">Delete</label>
                                    </div>
                                </td>

                                <!-- Row Toggle -->
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="toggleRowCheckboxes('<?php echo $slug; ?>')">Toggle Row</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="text-right">
                <a href="roles-list.php" class="btn btn-secondary mr-2" style="border-radius:8px;">Cancel</a>
                <button type="submit" name="submit" class="btn-save btn">
                    <i class="fas fa-save mr-1"></i> Save Role &amp; Permissions
                </button>
            </div>

        </form>
    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
function toggleGlobalCheckboxes(masterCb) {
    $('.perm-cb').prop('checked', masterCb.checked);
}

function toggleRowCheckboxes(slug) {
    var $rowCbs = $('.perm-' + slug);
    var allChecked = $rowCbs.filter(':checked').length === $rowCbs.length;
    $rowCbs.prop('checked', !allChecked);
}
</script>
</body>
</html>
