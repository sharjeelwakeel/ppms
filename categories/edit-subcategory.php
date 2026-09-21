<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

check_access('items', 'edit');

$message = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);

if ($id <= 0) {
    header('Location: subcategories-list.php');
    exit;
}

// Fetch current subcategory record
$chk = mysqli_query($connection, "SELECT * FROM tbl_product_subcategories WHERE id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
if (!$chk || mysqli_num_rows($chk) === 0) {
    header('Location: subcategories-list.php');
    exit;
}
$subcategory = mysqli_fetch_assoc($chk);

$category_id = $subcategory['category_id'];
$name = $subcategory['name'];
$description = $subcategory['description'];
$status = $subcategory['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = intval($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

    if ($category_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Please select a valid parent category.</div>';
    } elseif (empty($name)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Subcategory name is required.</div>';
    } else {
        $safeName = mysqli_real_escape_string($connection, $name);
        $safeDesc = mysqli_real_escape_string($connection, $description);
        $safeStatus = mysqli_real_escape_string($connection, $status);

        // Check duplicate within same parent category excluding self
        $dupChk = mysqli_query($connection, "SELECT id FROM tbl_product_subcategories 
                                             WHERE category_id = '$category_id' AND name = '$safeName' AND id != '$id'
                                             AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if ($dupChk && mysqli_num_rows($dupChk) > 0) {
            $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Another subcategory with this name already exists under the selected category.</div>';
        } else {
            $updateSql = "UPDATE tbl_product_subcategories 
                          SET category_id = '$category_id', name = '$safeName', description = '$safeDesc', status = '$safeStatus', updated_at = NOW() 
                          WHERE id = '$id'";
            if (mysqli_query($connection, $updateSql)) {
                header('Location: subcategories-list.php?category_id=' . $category_id);
                exit;
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-times-circle mr-1"></i> Error updating subcategory: ' . mysqli_error($connection) . '</div>';
            }
        }
    }
}

// Fetch all active categories for dropdown
$categoriesQuery = mysqli_query($connection, "SELECT id, name FROM tbl_product_categories WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Edit Product Subcategory</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
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
        .data-card-body { padding: 26px; }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover { opacity: 0.9; }
    </style>
</head>
<body>

<?php include('../include/navbar.php'); ?>

<main class="main">
    <div class="container py-4">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-edit mr-2 text-warning"></i> Edit Product Subcategory</h4>
                <small class="text-white-50">Update subcategory details and parent category linkage</small>
            </div>
            <div>
                <a href="subcategories-list.php?category_id=<?php echo $category_id; ?>" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-arrow-left mr-1 text-primary"></i> Back to Subcategories
                </a>
            </div>
        </div>

        <?php echo $message; ?>

        <!-- Form Card -->
        <div class="data-card">
            <div class="data-card-body">
                <form action="edit-subcategory.php?id=<?php echo $id; ?>" method="POST">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Parent Category <span class="text-danger">*</span></label>
                                <div class="col-md-9">
                                    <select name="category_id" class="form-control" required autofocus>
                                        <option value="">-- Select Parent Category --</option>
                                        <?php 
                                        if ($categoriesQuery && mysqli_num_rows($categoriesQuery) > 0) {
                                            while ($cat = mysqli_fetch_assoc($categoriesQuery)) {
                                                $selected = ($cat['id'] == $category_id) ? 'selected' : '';
                                                echo '<option value="' . $cat['id'] . '" ' . $selected . '>' . htmlspecialchars($cat['name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <small class="form-text text-muted">The higher-level category under which this subcategory belongs.</small>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Subcategory Name <span class="text-danger">*</span></label>
                                <div class="col-md-9">
                                    <input type="text" name="name" class="form-control" placeholder="e.g. 20W-50, Synthetic, DOT 4" value="<?php echo htmlspecialchars($name); ?>" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Description</label>
                                <div class="col-md-9">
                                    <textarea name="description" rows="3" class="form-control" placeholder="Optional brief details about this subcategory"><?php echo htmlspecialchars($description); ?></textarea>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Status</label>
                                <div class="col-md-9">
                                    <select name="status" class="form-control" style="max-width: 200px;">
                                        <option value="Active" <?php echo ($status === 'Active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($status === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div>
                        <button type="submit" class="btn btn-primary px-4 py-2 font-weight-bold shadow-sm">
                            <i class="fas fa-save mr-1"></i> Update Subcategory
                        </button>
                        <a href="subcategories-list.php?category_id=<?php echo $category_id; ?>" class="btn btn-secondary px-4 py-2 font-weight-bold ml-2">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>
