<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing customers
check_access('customers', 'edit');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: customers-list.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = mysqli_real_escape_string($connection, trim($_POST['name'] ?? ''));
    $phone      = mysqli_real_escape_string($connection, trim($_POST['phone'] ?? ''));
    $address    = mysqli_real_escape_string($connection, trim($_POST['address'] ?? ''));
    $fuel_rate  = (isset($_POST['fuel_rate']) && strcasecmp($_POST['fuel_rate'], 'Credit') === 0) ? 'Credit' : 'Cash';
    $other_rate = (isset($_POST['other_rate']) && strcasecmp($_POST['other_rate'], 'Credit') === 0) ? 'Credit' : 'Cash';
    $status     = (isset($_POST['status']) && strcasecmp($_POST['status'], 'Inactive') === 0) ? 'Inactive' : 'Active';

    if (empty($name)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Customer name is required.</div>';
    } else {
        $query = "UPDATE tbl_customers SET 
                  name='$name', 
                  phone='$phone', 
                  address='$address', 
                  fuel_rate='$fuel_rate', 
                  other_rate='$other_rate', 
                  status='$status' 
                  WHERE id='$id'";
        if (mysqli_query($connection, $query)) {
            header('Location: customers-list.php?msg=updated');
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Error updating customer: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch existing customer
$sql = "SELECT * FROM tbl_customers WHERE id='$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1";
$res = mysqli_query($connection, $sql);
$customer = mysqli_fetch_assoc($res);

if (!$customer) {
    header('Location: customers-list.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Edit Customer</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="../include/style.css?v=1.0.2" />
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
            font-weight: 500;
        }
        .btn-primary:hover { opacity: 0.9; }
    </style>
</head>
<body>
    
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container pt-4 pb-4">
            
            <div class="row mb-3 align-items-center">
                <div class="col-md-6">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-user-edit mr-2 text-primary"></i>Edit Customer #<?php echo $customer['id']; ?>
                    </h4>
                    <p class="text-muted small mb-0">Update customer master profile, rate classifications, and active status.</p>
                </div>
                <div class="col-md-6 text-right">
                    <a href="customers-list.php" class="btn btn-outline-secondary font-weight-bold">
                        <i class="fas fa-arrow-left mr-1"></i> Back to List
                    </a>
                </div>
            </div>

            <?php echo $message; ?>

            <div class="card shadow-sm border-0" style="border-radius:10px;">
                <div class="card-body p-4">
                    <form action="edit-customer.php?id=<?php echo $id; ?>" method="POST">
                        
                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-id-card mr-1 text-primary"></i> Basic Information
                        </h6>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($customer['name']); ?>" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" placeholder="e.g. 0300-1234567">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 form-group">
                                <label class="font-weight-bold">Address / Location</label>
                                <textarea name="address" class="form-control" rows="2" placeholder="e.g. Plot # 45, Industrial Area, Karachi"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3 mt-3">
                            <i class="fas fa-sliders-h mr-1 text-primary"></i> Rates & Status
                        </h6>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Fuel Rate <span class="text-danger">*</span></label>
                                <select name="fuel_rate" class="form-control font-weight-bold">
                                    <option value="Cash" <?php echo (strcasecmp($customer['fuel_rate'], 'Cash') === 0) ? 'selected' : ''; ?>>Cash</option>
                                    <option value="Credit" <?php echo (strcasecmp($customer['fuel_rate'], 'Credit') === 0) ? 'selected' : ''; ?>>Credit</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Other Rate <span class="text-danger">*</span></label>
                                <select name="other_rate" class="form-control font-weight-bold">
                                    <option value="Cash" <?php echo (strcasecmp($customer['other_rate'], 'Cash') === 0) ? 'selected' : ''; ?>>Cash</option>
                                    <option value="Credit" <?php echo (strcasecmp($customer['other_rate'], 'Credit') === 0) ? 'selected' : ''; ?>>Credit</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Suspended <span class="text-danger">*</span></label>
                                <select name="status" class="form-control font-weight-bold">
                                    <option value="Active" <?php echo (strcasecmp($customer['status'], 'Active') === 0) ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo (strcasecmp($customer['status'], 'Inactive') === 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12 text-right">
                                <a href="customers-list.php" class="btn btn-secondary mr-2">
                                    <i class="fas fa-times mr-1"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-save mr-1"></i> Update Customer
                                </button>
                            </div>
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
