<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing vehicles
check_access('vehicles', 'edit');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: vehicles.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id    = intval($_POST['customer_id'] ?? 0);
    $vehicle_name   = mysqli_real_escape_string($connection, trim($_POST['vehicle_name'] ?? ''));
    $reg_number     = mysqli_real_escape_string($connection, trim($_POST['reg_number'] ?? ''));
    $numeric_number = mysqli_real_escape_string($connection, trim($_POST['numeric_number'] ?? ''));
    $fuel_limit     = floatval($_POST['fuel_limit'] ?? 0.00);
    $vehicle_type   = (isset($_POST['vehicle_type']) && strcasecmp($_POST['vehicle_type'], 'Diesel') === 0) ? 'Diesel' : 'Petrol';
    $status         = (isset($_POST['status']) && strcasecmp($_POST['status'], 'Inactive') === 0) ? 'Inactive' : 'Active';

    if ($customer_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Please select a valid customer.</div>';
    } elseif (empty($vehicle_name) || empty($reg_number)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Vehicle Name and Registration Number are required.</div>';
    } else {
        $query = "UPDATE tbl_customer_vehicles SET 
                  customer_id='$customer_id', 
                  vehicle_name='$vehicle_name', 
                  reg_number='$reg_number', 
                  numeric_number='$numeric_number', 
                  fuel_limit='$fuel_limit', 
                  vehicle_type='$vehicle_type', 
                  status='$status' 
                  WHERE id='$id'";
        if (mysqli_query($connection, $query)) {
            header('Location: vehicles.php?customer_id=' . $customer_id . '&msg=updated');
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Error updating vehicle: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch existing vehicle record
$sql = "SELECT * FROM tbl_customer_vehicles WHERE id='$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1";
$res = mysqli_query($connection, $sql);
$vehicle = mysqli_fetch_assoc($res);

if (!$vehicle) {
    header('Location: vehicles.php');
    exit;
}

// Fetch list of active customers for dropdown
$cust_res = mysqli_query($connection, "SELECT id, name FROM tbl_customers WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Edit Customer Vehicle</title>
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
                        <i class="fas fa-edit mr-2 text-primary"></i>Edit Vehicle #<?php echo $vehicle['id']; ?>
                    </h4>
                    <p class="text-muted small mb-0">Update vehicle details, registration plate, and fuel quota.</p>
                </div>
                <div class="col-md-6 text-right">
                    <a href="vehicles.php?customer_id=<?php echo $vehicle['customer_id']; ?>" class="btn btn-outline-secondary font-weight-bold">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Vehicles
                    </a>
                </div>
            </div>

            <?php echo $message; ?>

            <div class="card shadow-sm border-0" style="border-radius:10px;">
                <div class="card-body p-4">
                    <form action="edit-vehicle.php?id=<?php echo $id; ?>" method="POST">
                        
                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-user-circle mr-1 text-primary"></i> Customer Ownership
                        </h6>
                        <div class="row">
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">Select Customer <span class="text-danger">*</span></label>
                                <select name="customer_id" class="form-control font-weight-bold" required>
                                    <option value="">-- Choose Customer --</option>
                                    <?php 
                                    if ($cust_res && mysqli_num_rows($cust_res) > 0) {
                                        while ($c = mysqli_fetch_assoc($cust_res)) {
                                            $selected = ($c['id'] == $vehicle['customer_id']) ? 'selected' : '';
                                            echo '<option value="'.$c['id'].'" '.$selected.'>'.htmlspecialchars($c['name']).' (#'.$c['id'].')</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3 mt-3">
                            <i class="fas fa-info-circle mr-1 text-primary"></i> Vehicle Specifications
                        </h6>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Name <span class="text-danger">*</span></label>
                                <input type="text" name="vehicle_name" class="form-control" value="<?php echo htmlspecialchars($vehicle['vehicle_name']); ?>" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Registration Number <span class="text-danger">*</span></label>
                                <input type="text" name="reg_number" class="form-control font-weight-bold text-monospace" value="<?php echo htmlspecialchars($vehicle['reg_number']); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Numeric Number</label>
                                <input type="text" name="numeric_number" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($vehicle['numeric_number'] ?? ''); ?>" placeholder="e.g. 2024 / 8956">
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Fuel Limit (Litres)</label>
                                <input type="number" step="0.01" min="0" name="fuel_limit" class="form-control" value="<?php echo htmlspecialchars($vehicle['fuel_limit']); ?>" placeholder="0.00 for unlimited">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Type <span class="text-danger">*</span></label>
                                <select name="vehicle_type" class="form-control font-weight-bold">
                                    <option value="Petrol" <?php echo (strcasecmp($vehicle['vehicle_type'], 'Petrol') === 0) ? 'selected' : ''; ?>>Petrol</option>
                                    <option value="Diesel" <?php echo (strcasecmp($vehicle['vehicle_type'], 'Diesel') === 0) ? 'selected' : ''; ?>>Diesel</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Suspended <span class="text-danger">*</span></label>
                                <select name="status" class="form-control font-weight-bold">
                                    <option value="Active" <?php echo (strcasecmp($vehicle['status'], 'Active') === 0) ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo (strcasecmp($vehicle['status'], 'Inactive') === 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12 text-right">
                                <a href="vehicles.php?customer_id=<?php echo $vehicle['customer_id']; ?>" class="btn btn-secondary mr-2">
                                    <i class="fas fa-times mr-1"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-save mr-1"></i> Update Vehicle
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
