<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding vehicles
check_access('vehicles', 'add');

// Auto-migrate tbl_customer_vehicles
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_customer_vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `vehicle_name` VARCHAR(128) NOT NULL,
  `reg_number` VARCHAR(64) NOT NULL,
  `numeric_number` VARCHAR(64) DEFAULT NULL,
  `fuel_limit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vehicle_type` ENUM('Petrol','Diesel') NOT NULL DEFAULT 'Petrol',
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_reg_number` (`reg_number`),
  KEY `idx_numeric_number` (`numeric_number`),
  KEY `idx_vehicle_type` (`vehicle_type`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$preset_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
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
        $query = "INSERT INTO tbl_customer_vehicles (customer_id, vehicle_name, reg_number, numeric_number, fuel_limit, vehicle_type, status) 
                  VALUES ('$customer_id', '$vehicle_name', '$reg_number', '$numeric_number', '$fuel_limit', '$vehicle_type', '$status')";
        if (mysqli_query($connection, $query)) {
            header('Location: vehicles.php?customer_id=' . $customer_id . '&msg=added');
            exit;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> Error saving vehicle: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch list of active customers for dropdown
$cust_res = mysqli_query($connection, "SELECT id, name FROM tbl_customers WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Add Customer Vehicle</title>
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
                        <i class="fas fa-car mr-2 text-primary"></i>Add Customer Vehicle
                    </h4>
                    <p class="text-muted small mb-0">Register authorized vehicle, registration plate, and fuel quota.</p>
                </div>
                <div class="col-md-6 text-right">
                    <a href="vehicles.php<?php echo ($preset_customer_id > 0) ? '?customer_id='.$preset_customer_id : ''; ?>" class="btn btn-outline-secondary font-weight-bold">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Vehicles
                    </a>
                </div>
            </div>

            <?php echo $message; ?>

            <div class="card shadow-sm border-0" style="border-radius:10px;">
                <div class="card-body p-4">
                    <form action="add-vehicle.php<?php echo ($preset_customer_id > 0) ? '?customer_id='.$preset_customer_id : ''; ?>" method="POST">
                        
                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-user-circle mr-1 text-primary"></i> Customer Ownership
                        </h6>
                        <div class="row">
                            <div class="col-md-12 form-group">
                                <label class="font-weight-bold">Customer <span class="text-danger">*</span></label>
                                <?php if ($preset_customer_id > 0): ?>
                                    <input type="hidden" name="customer_id" value="<?php echo $preset_customer_id; ?>">
                                    <select class="form-control font-weight-bold bg-light text-dark" disabled style="cursor: not-allowed;">
                                        <?php 
                                        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
                                            mysqli_data_seek($cust_res, 0);
                                            while ($c = mysqli_fetch_assoc($cust_res)) {
                                                $selected = ($c['id'] == $preset_customer_id) ? 'selected' : '';
                                                echo '<option value="'.$c['id'].'" '.$selected.'>'.htmlspecialchars($c['name']).' (#'.$c['id'].')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                <?php else: ?>
                                    <select name="customer_id" class="form-control font-weight-bold" required>
                                        <option value="">-- Choose Customer --</option>
                                        <?php 
                                        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
                                            mysqli_data_seek($cust_res, 0);
                                            while ($c = mysqli_fetch_assoc($cust_res)) {
                                                echo '<option value="'.$c['id'].'">'.htmlspecialchars($c['name']).' (#'.$c['id'].')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h6 class="font-weight-bold text-uppercase text-muted border-bottom pb-2 mb-3 mt-3">
                            <i class="fas fa-info-circle mr-1 text-primary"></i> Vehicle Specifications
                        </h6>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Name <span class="text-danger">*</span></label>
                                <input type="text" name="vehicle_name" class="form-control" placeholder="e.g. Toyota Corolla / Hino 500" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Registration Number <span class="text-danger">*</span></label>
                                <input type="text" name="reg_number" class="form-control font-weight-bold text-monospace" placeholder="e.g. LEA-2024 / KHI-8956" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Numeric Number</label>
                                <input type="text" name="numeric_number" class="form-control font-weight-bold" placeholder="e.g. 2024 / 8956">
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Fuel Limit (Litres)</label>
                                <input type="number" step="0.01" min="0" name="fuel_limit" class="form-control" value="0.00" placeholder="0.00 for unlimited">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Vehicle Type <span class="text-danger">*</span></label>
                                <select name="vehicle_type" class="form-control font-weight-bold">
                                    <option value="Petrol" selected>Petrol</option>
                                    <option value="Diesel">Diesel</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Suspended <span class="text-danger">*</span></label>
                                <select name="status" class="form-control font-weight-bold">
                                    <option value="Active" selected>Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12 text-right">
                                <a href="vehicles.php<?php echo ($preset_customer_id > 0) ? '?customer_id='.$preset_customer_id : ''; ?>" class="btn btn-secondary mr-2">
                                    <i class="fas fa-times mr-1"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-save mr-1"></i> Save Vehicle
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
