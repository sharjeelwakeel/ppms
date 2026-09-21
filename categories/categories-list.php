<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

check_access('items', 'show');

$canAdd    = has_permission('items', 'add');
$canEdit   = has_permission('items', 'edit');
$canDelete = has_permission('items', 'delete');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Product Categories</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.6">
    <style>
        body { background-color: #f4f6fa; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: linear-gradient(135deg, #04204e 0%, #07347a 100%);
            color: #fff;
            padding: 20px 24px;
            border-radius: 8px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(4, 32, 78, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.35rem; }
        .data-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            margin-bottom: 24px;
        }
        .data-card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .data-card-body { padding: 22px; }
        table.dataTable thead th {
            background-color: #04204e !important;
            color: #fff !important;
            border: none !important;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        table.dataTable tbody td {
            font-size: 0.86rem;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<?php include('../include/navbar.php');?>

<main class="main">
    <div class="container-fluid px-4 py-3">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-tags mr-2 text-warning"></i> Product Categories Master</h4>
                <small class="text-white-50">Manage high-level lubricant and auxiliary stock categories</small>
            </div>
            <div>
                <a href="subcategories-list.php" class="btn btn-outline-light btn-sm font-weight-bold mr-2">
                    <i class="fas fa-tag mr-1"></i> View Subcategories
                </a>
                <?php if ($canAdd): ?>
                <a href="add-category.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-plus mr-1 text-primary"></i> Add New Category
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="data-card">
            <div class="data-card-body p-0">
                <div class="table-responsive p-3">
                    <table id="categoriesTable" class="table table-hover table-striped mb-0" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width: 40px;" class="text-center">#</th>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th class="text-center" style="width: 130px;">Subcategories</th>
                                <th class="text-center" style="width: 110px;">Products</th>
                                <th class="text-center" style="width: 90px;">Status</th>
                                <th style="width: 140px;">Created At</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sql = "SELECT c.*, 
                                           (SELECT COUNT(*) FROM tbl_product_subcategories sc 
                                            WHERE sc.category_id = c.id AND (sc.deleted_at IS NULL OR sc.deleted_at = '0000-00-00 00:00:00')) AS subcategory_count,
                                           (SELECT COUNT(*) FROM tbl_lubricant_products p 
                                            WHERE p.category_id = c.id AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')) AS product_count
                                    FROM tbl_product_categories c
                                    WHERE (c.deleted_at IS NULL OR c.deleted_at = '0000-00-00 00:00:00')
                                    ORDER BY c.name ASC";
                            $result = mysqli_query($connection, $sql);
                            $sr = 1;
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $catId = intval($row['id']);
                                    $nameDisplay = $canEdit 
                                        ? '<a href="edit-category.php?id='.$catId.'" class="font-weight-bold text-primary">'.htmlspecialchars($row['name']).'</a>'
                                        : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                    
                                    $statusBadge = ($row['status'] === 'Active') 
                                        ? '<span class="badge badge-success">Active</span>'
                                        : '<span class="badge badge-secondary">Inactive</span>';

                                    $subCount = intval($row['subcategory_count']);
                                    $prodCount = intval($row['product_count']);
                            ?>
                            <tr>
                                <td class="text-center font-weight-bold text-muted"><?php echo $sr++; ?></td>
                                <td>
                                    <i class="fas fa-folder mr-1 text-warning"></i> <?php echo $nameDisplay; ?>
                                </td>
                                <td class="text-muted small">
                                    <?php echo !empty($row['description']) ? htmlspecialchars($row['description']) : '—'; ?>
                                </td>
                                <td class="text-center">
                                    <a href="subcategories-list.php?category_id=<?php echo $catId; ?>" class="badge badge-info px-2 py-1" title="View subcategories in this category">
                                        <i class="fas fa-tag mr-1"></i><?php echo $subCount; ?> Subcategories
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-light border px-2 py-1 font-weight-bold">
                                        <i class="fas fa-boxes mr-1 text-primary"></i><?php echo $prodCount; ?> Products
                                    </span>
                                </td>
                                <td class="text-center"><?php echo $statusBadge; ?></td>
                                <td class="text-muted small"><?php echo date("d-m-Y h:i A", strtotime($row['created_at'])); ?></td>
                                <td class="text-center">
                                    <?php if ($canEdit): ?>
                                    <a href="edit-category.php?id=<?php echo $catId; ?>" class="btn btn-outline-primary btn-sm py-0 px-2 mr-1" title="Edit Category">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" 
                                            onclick="deleteCategory(<?php echo $catId; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')" 
                                            title="Delete Category">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#categoriesTable').DataTable({
        pageLength: 25,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6"f>>rt<"row mt-2"<"col-md-6"i><"col-md-6"p>>',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search categories..."
        }
    });
});

function deleteCategory(id, name) {
    Swal.fire({
        title: 'Delete Category?',
        html: 'Are you sure you want to delete category <strong>' + name + '</strong>?<br><small class="text-muted">Categories with active subcategories or products cannot be deleted.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: '../include/deletecategory.php',
                type: 'POST',
                data: { id: id },
                success: function(response) {
                    if (response.indexOf('successfully') !== -1) {
                        Swal.fire('Deleted!', response, 'success').then(function() {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Cannot Delete', response, 'warning');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'An error occurred while communicating with the server.', 'error');
                }
            });
        }
    });
}
</script>

</body>
</html>
