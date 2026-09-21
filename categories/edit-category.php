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
    header('Location: categories-list.php');
    exit;
}

// Fetch category record
$chk = mysqli_query($connection, "SELECT * FROM tbl_product_categories WHERE id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
if (!$chk || mysqli_num_rows($chk) === 0) {
    header('Location: categories-list.php');
    exit;
}
$category = mysqli_fetch_assoc($chk);

$name = $category['name'];
$description = $category['description'];
$status = $category['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

    if (empty($name)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Category name is required.</div>';
    } else {
        $safeName = mysqli_real_escape_string($connection, $name);
        $safeDesc = mysqli_real_escape_string($connection, $description);
        $safeStatus = mysqli_real_escape_string($connection, $status);

        // Check duplicate name excluding current id
        $dupChk = mysqli_query($connection, "SELECT id FROM tbl_product_categories WHERE name = '$safeName' AND id != '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if ($dupChk && mysqli_num_rows($dupChk) > 0) {
            $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-1"></i> Another category with this name already exists.</div>';
        } else {
            $updateSql = "UPDATE tbl_product_categories 
                          SET name = '$safeName', description = '$safeDesc', status = '$safeStatus', updated_at = NOW() 
                          WHERE id = '$id'";
            if (mysqli_query($connection, $updateSql)) {
                header('Location: categories-list.php');
                exit;
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-times-circle mr-1"></i> Error updating category: ' . mysqli_error($connection) . '</div>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Edit Product Category</title>
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
                <h4><i class="fas fa-edit mr-2 text-warning"></i> Edit Product Category</h4>
                <small class="text-white-50">Update category details and active availability</small>
            </div>
            <div>
                <a href="categories-list.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-arrow-left mr-1 text-primary"></i> Back to Categories
                </a>
            </div>
        </div>

        <?php echo $message; ?>

        <!-- Form Card -->
        <div class="data-card">
            <div class="data-card-body">
                <form action="edit-category.php?id=<?php echo $id; ?>" method="POST">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Category Name <span class="text-danger">*</span></label>
                                <div class="col-md-9">
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Engine Oils, Brake Fluids" value="<?php echo htmlspecialchars($name); ?>" required autofocus>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-md-3 col-form-label font-weight-bold">Description</label>
                                <div class="col-md-9">
                                    <textarea name="description" rows="3" class="form-control" placeholder="Optional brief details about this category"><?php echo htmlspecialchars($description); ?></textarea>
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
                            <i class="fas fa-save mr-1"></i> Update Category
                        </button>
                        <a href="categories-list.php" class="btn btn-secondary px-4 py-2 font-weight-bold ml-2">
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
