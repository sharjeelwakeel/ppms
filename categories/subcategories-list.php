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

// Filter by category if passed
$filterCatId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Product Subcategories</title>
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
        .filter-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
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

<?php include('../include/navbar.php'); ?>

<main class="main">
    <div class="container-fluid px-4 py-3">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-tag mr-2 text-warning"></i> Product Subcategories Master</h4>
                <small class="text-white-50">Manage detailed classifications linked to parent categories</small>
            </div>
            <div>
                <a href="categories-list.php" class="btn btn-outline-light btn-sm font-weight-bold mr-2">
                    <i class="fas fa-tags mr-1"></i> Product Categories
                </a>
                <?php if ($canAdd): ?>
                <a href="add-subcategory.php<?php echo ($filterCatId > 0) ? '?category_id=' . $filterCatId : ''; ?>" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-plus mr-1 text-primary"></i> Add New Subcategory
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-card">
            <form method="GET" action="subcategories-list.php" class="form-inline">
                <label class="mr-2 font-weight-bold text-muted"><i class="fas fa-filter mr-1"></i> Filter by Category:</label>
                <select name="category_id" class="form-control form-control-sm mr-3" onchange="this.form.submit()" style="min-width: 220px;">
                    <option value="0">-- All Categories --</option>
                    <?php 
                    $catQ = mysqli_query($connection, "SELECT id, name FROM tbl_product_categories WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC");
                    while ($cq = mysqli_fetch_assoc($catQ)) {
                        $selected = ($filterCatId == $cq['id']) ? 'selected' : '';
                        echo '<option value="' . $cq['id'] . '" ' . $selected . '>' . htmlspecialchars($cq['name']) . '</option>';
                    }
                    ?>
                </select>
                <?php if ($filterCatId > 0): ?>
                <a href="subcategories-list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times mr-1"></i> Clear Filter
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Data Table Card -->
        <div class="data-card">
            <div class="data-card-body p-0">
                <div class="table-responsive p-3">
                    <table id="subcategoriesTable" class="table table-hover table-striped mb-0" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width: 40px;" class="text-center">#</th>
                                <th>Subcategory Name</th>
                                <th>Parent Category</th>
                                <th>Description</th>
                                <th class="text-center" style="width: 110px;">Products</th>
                                <th class="text-center" style="width: 90px;">Status</th>
                                <th style="width: 140px;">Created At</th>
                                <th class="text-center" style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $whereClause = "WHERE (sc.deleted_at IS NULL OR sc.deleted_at = '0000-00-00 00:00:00')";
                            if ($filterCatId > 0) {
                                $whereClause .= " AND sc.category_id = '$filterCatId'";
                            }

                            $sql = "SELECT sc.*, c.name AS category_name,
                                           (SELECT COUNT(*) FROM tbl_lubricant_products p 
                                            WHERE p.subcategory_id = sc.id AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')) AS product_count
                                    FROM tbl_product_subcategories sc
                                    LEFT JOIN tbl_product_categories c ON sc.category_id = c.id
                                    $whereClause
                                    ORDER BY c.name ASC, sc.name ASC";
                            $result = mysqli_query($connection, $sql);
                            $sr = 1;
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $subId = intval($row['id']);
                                    $nameDisplay = $canEdit 
                                        ? '<a href="edit-subcategory.php?id='.$subId.'" class="font-weight-bold text-primary">'.htmlspecialchars($row['name']).'</a>'
                                        : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                    
                                    $statusBadge = ($row['status'] === 'Active') 
                                        ? '<span class="badge badge-success">Active</span>'
                                        : '<span class="badge badge-secondary">Inactive</span>';

                                    $prodCount = intval($row['product_count']);
                                    $parentCatDisplay = !empty($row['category_name']) 
                                        ? '<span class="badge badge-light border text-dark font-weight-bold"><i class="fas fa-folder mr-1 text-warning"></i>'.htmlspecialchars($row['category_name']).'</span>'
                                        : '<span class="text-muted italic">None</span>';
                            ?>
                            <tr>
                                <td class="text-center font-weight-bold text-muted"><?php echo $sr++; ?></td>
                                <td>
                                    <i class="fas fa-tag mr-1 text-info"></i> <?php echo $nameDisplay; ?>
                                </td>
                                <td><?php echo $parentCatDisplay; ?></td>
                                <td class="text-muted small">
                                    <?php echo !empty($row['description']) ? htmlspecialchars($row['description']) : '—'; ?>
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
                                    <a href="edit-subcategory.php?id=<?php echo $subId; ?>" class="btn btn-outline-primary btn-sm py-0 px-2 mr-1" title="Edit Subcategory">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" 
                                            onclick="deleteSubcategory(<?php echo $subId; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')" 
                                            title="Delete Subcategory">
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
    $('#subcategoriesTable').DataTable({
        pageLength: 25,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6"f>>rt<"row mt-2"<"col-md-6"i><"col-md-6"p>>',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search subcategories..."
        }
    });
});

function deleteSubcategory(id, name) {
    Swal.fire({
        title: 'Delete Subcategory?',
        html: 'Are you sure you want to delete subcategory <strong>' + name + '</strong>?<br><small class="text-muted">Subcategories with active products cannot be deleted.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: '../include/deletesubcategory.php',
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
