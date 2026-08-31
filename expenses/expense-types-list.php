<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Access check
check_access('expenses', 'show');

$canAdd    = has_permission('expenses', 'add');
$canEdit   = has_permission('expenses', 'edit');
$canDelete = has_permission('expenses', 'delete');

$alert_message = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense Type added successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'updated') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense Type updated successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'deleted') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Expense Type deleted successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'error') {
        $alert_message = '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle mr-1"></i> An error occurred processing your request.<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    }
}

// Handle Add / Edit form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save_type') {
        $type_id     = isset($_POST['type_id']) ? mysqli_real_escape_string($connection, trim($_POST['type_id'])) : '';
        $name        = mysqli_real_escape_string($connection, trim($_POST['name']));
        $description = mysqli_real_escape_string($connection, trim($_POST['description']));
        $status      = isset($_POST['status']) && $_POST['status'] === 'Inactive' ? 'Inactive' : 'Active';

        if (!empty($name)) {
            if (!empty($type_id) && $canEdit) {
                $sql = "UPDATE tbl_expense_types SET name = '$name', description = '$description', status = '$status', updated_at = NOW() WHERE id = '$type_id'";
                if (mysqli_query($connection, $sql)) {
                    header('Location: expense-types-list.php?msg=updated');
                    exit;
                }
            } elseif (empty($type_id) && $canAdd) {
                $sql = "INSERT INTO tbl_expense_types (name, description, status) VALUES ('$name', '$description', '$status')";
                if (mysqli_query($connection, $sql)) {
                    header('Location: expense-types-list.php?msg=added');
                    exit;
                }
            }
        }
    }
}

// Fetch all active Expense Types
$query = "SELECT * FROM tbl_expense_types WHERE deleted_at IS NULL ORDER BY name ASC";
$result = mysqli_query($connection, $query);
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.2" />
    <style>
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
            font-weight: 500;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline-primary {
            color: #04204e !important;
            border: 1.5px solid #04204e !important;
            background-color: transparent !important;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn-outline-primary:hover {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(4, 32, 78, 0.2);
        }
        #typeTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
        .modal-header-navy {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            color: #fff !important;
        }
    </style>
    <title>PPMS - Expense Categories</title>
</head>
<body>
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container pt-4 pb-4">
            <?php echo $alert_message; ?>
            
            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4><i class="fas fa-tags mr-2" style="color: var(--primary-color);"></i>Expense Categories / Types</h4>
                </div>
                <div class="col-md-6 text-right">
                    <a href="expenses-list.php" class="btn btn-outline-primary mr-2 font-weight-bold shadow-sm"><i class="fas fa-list-alt mr-1"></i> View Expenses</a>
                    <?php if ($canAdd): ?>
                    <button type="button" class="btn btn-primary font-weight-bold shadow-sm" onclick="openAddModal()"><i class="fas fa-plus mr-1"></i> Add New Category</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="typeTable" class="table table-bordered table-hover w-100">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th width="12%">Status</th>
                                    <th width="18%">Created At</th>
                                    <th width="15%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sn = 1;
                                if ($result && mysqli_num_rows($result) > 0):
                                    while ($row = mysqli_fetch_assoc($result)):
                                        $statusBadge = ($row['status'] === 'Active') 
                                            ? '<span class="badge badge-success">Active</span>' 
                                            : '<span class="badge badge-secondary">Inactive</span>';
                                ?>
                                <tr>
                                    <td><?php echo $sn++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                                    <td><?php echo $statusBadge; ?></td>
                                    <td><?php echo date("d-m-Y h:i A", strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <?php if ($canEdit): ?>
                                        <button class="btn btn-sm btn-link text-primary p-0 mr-2" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                            <i class="fas fa-edit" style="font-size: 16px;"></i>
                                        </button>
                                        <?php endif; ?>

                                        <?php if ($canDelete): ?>
                                        <a href="#" onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')" class="btn btn-sm btn-link text-danger p-0" title="Delete">
                                            <i class="fas fa-trash-alt" style="font-size: 16px;"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal for Add/Edit Expense Type -->
    <div class="modal fade" id="typeModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_type">
                    <input type="hidden" name="type_id" id="type_id" value="">
                    
                    <div class="modal-header modal-header-navy">
                        <h5 class="modal-title" id="modalTitle"><i class="fas fa-tags mr-2"></i>Add Expense Category</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="font-weight-bold">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Electricity Bill, Office Rent" required>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3" placeholder="Optional details or category purpose"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i> Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Category</button>
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
            $('#typeTable').DataTable({
                "pageLength": 25,
                "order": [[ 1, "asc" ]]
            });
        });

        function openAddModal() {
            $('#modalTitle').html('<i class="fas fa-tags mr-2"></i>Add Expense Category');
            $('#type_id').val('');
            $('#name').val('');
            $('#description').val('');
            $('#status').val('Active');
            $('#typeModal').modal('show');
        }

        function openEditModal(data) {
            $('#modalTitle').html('<i class="fas fa-edit mr-2"></i>Edit Expense Category');
            $('#type_id').val(data.id);
            $('#name').val(data.name);
            $('#description').val(data.description);
            $('#status').val(data.status);
            $('#typeModal').modal('show');
        }

        function confirmDelete(id, name) {
            if (confirm("Are you sure you want to delete the expense category '" + name + "'?")) {
                window.location.href = "../include/deleteexpensetype.php?id=" + id;
            }
        }
    </script>
</body>
</html>
