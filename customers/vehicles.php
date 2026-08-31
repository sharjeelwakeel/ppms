<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing vehicles
check_access('vehicles', 'show');

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

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$customer = null;

if ($customer_id > 0) {
    $c_res = mysqli_query($connection, "SELECT * FROM tbl_customers WHERE id = '$customer_id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
    if ($c_res && mysqli_num_rows($c_res) > 0) {
        $customer = mysqli_fetch_assoc($c_res);
    }
}

$canAdd    = has_permission('vehicles', 'add');
$canEdit   = has_permission('vehicles', 'edit');
$canDelete = has_permission('vehicles', 'delete');

$alert_message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Vehicle registered successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'updated') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Vehicle details updated successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'deleted') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Vehicle record removed successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Customer Vehicles</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
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
        #vehiclesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    
    <?php include('../include/navbar.php');?>

    <main class="main">
        <div class="container-fluid pt-4 pb-4 px-lg-5">
            <?php echo $alert_message; ?>

            <div class="row mb-4 align-items-center">
                <div class="col-md-7">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-car mr-2 text-primary"></i>
                        <?php if ($customer): ?>
                            Vehicles of <?php echo htmlspecialchars($customer['name']); ?>
                        <?php else: ?>
                            All Customer Vehicles
                        <?php endif; ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?php if ($customer): ?>
                            Managing fleet &amp; vehicles authorized for credit and fuel intake under customer account #<?php echo $customer['id']; ?>.
                        <?php else: ?>
                            Master catalog of all authorized customer vehicles, registration numbers, and fuel quotas.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-5 text-right">
                    <a href="customers-list.php" class="btn btn-outline-secondary mr-2 font-weight-bold shadow-sm">
                        <i class="fas fa-user-friends mr-1"></i> Customers List
                    </a>
                    <?php if ($canAdd): ?>
                    <a href="add-vehicle.php<?php echo ($customer_id > 0) ? '?customer_id='.$customer_id : ''; ?>" class="btn btn-primary font-weight-bold shadow-sm">
                        <i class="fas fa-plus mr-1"></i> Add Vehicle
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($customer): ?>
            <div class="alert alert-light border shadow-sm mb-4 d-flex justify-content-between align-items-center">
                <div>
                    <span class="font-weight-bold text-dark"><i class="fas fa-user-circle mr-1 text-primary"></i> Customer:</span> <?php echo htmlspecialchars($customer['name']); ?>
                    <?php if (!empty($customer['phone'])): ?>
                    <span class="ml-3 text-muted"><i class="fas fa-phone-alt mr-1"></i> <?php echo htmlspecialchars($customer['phone']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($customer['address'])): ?>
                    <span class="ml-3 text-muted"><i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($customer['address']); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <a href="vehicles.php" class="btn btn-sm btn-link text-primary font-weight-bold">
                        <i class="fas fa-list mr-1"></i> View All Customers' Vehicles
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0" style="border-radius:10px; overflow:hidden;">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="vehiclesTable" class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th width="5%">ID</th>
                                    <?php if (!$customer): ?>
                                    <th>Customer</th>
                                    <?php endif; ?>
                                    <th>Vehicle Name</th>
                                    <th>Registration No.</th>
                                    <th>Numeric No.</th>
                                    <th style="text-align:center;">Fuel Type</th>
                                    <th style="text-align:center;">Fuel Limit</th>
                                    <th style="text-align:center;">Status</th>
                                    <th>Created At</th>
                                    <?php if ($canDelete): ?>
                                    <th style="text-align: center; width: 6%;">Delete</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $where_clause = " WHERE (v.deleted_at IS NULL OR v.deleted_at = '0000-00-00 00:00:00') ";
                                if ($customer_id > 0) {
                                    $where_clause .= " AND v.customer_id = '$customer_id' ";
                                }
                                $sql = "SELECT v.*, c.name AS customer_name 
                                        FROM tbl_customer_vehicles v 
                                        LEFT JOIN tbl_customers c ON v.customer_id = c.id 
                                        $where_clause 
                                        ORDER BY v.id DESC";
                                $result = mysqli_query($connection, $sql);
                                if ($result && mysqli_num_rows($result) > 0) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $vehicleNameDisplay = $canEdit 
                                            ? '<a href="edit-vehicle.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['vehicle_name']).'</a>'
                                            : '<strong>'.htmlspecialchars($row['vehicle_name']).'</strong>';
                                        
                                        $typeBadge = (strcasecmp($row['vehicle_type'], 'Diesel') === 0) 
                                            ? '<span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-oil-can mr-1"></i> Diesel</span>' 
                                            : '<span class="badge badge-primary px-2 py-1"><i class="fas fa-gas-pump mr-1"></i> Petrol</span>';
                                        
                                        $limitBadge = (floatval($row['fuel_limit']) > 0) 
                                            ? '<span class="badge badge-info px-2 py-1 font-weight-bold">'.number_format($row['fuel_limit'], 2).' Ltr</span>' 
                                            : '<span class="badge badge-secondary px-2 py-1">Unlimited</span>';
                                        
                                        $statusBadge = (strcasecmp($row['status'], 'Active') === 0)
                                            ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>'
                                            : '<span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactive</span>';
                                        
                                        echo ' 
                                            <tr>
                                                <td>'.$row['id'].'</td>';
                                        if (!$customer) {
                                            $custLink = !empty($row['customer_name']) 
                                                ? '<a href="vehicles.php?customer_id='.$row['customer_id'].'" class="font-weight-bold text-dark">'.htmlspecialchars($row['customer_name']).'</a>'
                                                : '<span class="text-muted">Unknown</span>';
                                            echo '<td>'.$custLink.'</td>';
                                        }
                                        echo '  <td>'.$vehicleNameDisplay.'</td>
                                                <td><span class="badge badge-light border text-monospace font-weight-bold px-2 py-1">'.htmlspecialchars($row['reg_number']).'</span></td>
                                                <td>'.(!empty($row['numeric_number']) ? '<code>'.htmlspecialchars($row['numeric_number']).'</code>' : '<span class="text-muted">—</span>').'</td>
                                                <td class="text-center">'.$typeBadge.'</td>
                                                <td class="text-center">'.$limitBadge.'</td>
                                                <td class="text-center">'.$statusBadge.'</td>
                                                <td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>';
                                        if ($canDelete) {
                                            echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteVehicle('.$row['id'].', \''.addslashes(htmlspecialchars($row['reg_number'])).'\')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
                                        }
                                        echo '</tr>';
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
    <script>
    $(document).ready(function() {
        $('#vehiclesTable').DataTable({
            "order": [[ 0, "desc" ]],
            "pageLength": 25
        });
    });

    function deleteVehicle(id, reg){
        if(confirm('Are you sure you want to remove vehicle "' + reg + '"?')) {
            $.ajax({
                type: "POST",
                url: "../include/deletevehicle.php",
                data: {id: id},
                success: function (data) {
                    if (data.trim() === 'deleted') {
                        var returnUrl = window.location.href;
                        if (returnUrl.indexOf('msg=') > -1) {
                            returnUrl = returnUrl.replace(/msg=[^&]+/, 'msg=deleted');
                        } else {
                            returnUrl += (returnUrl.indexOf('?') > -1 ? '&' : '?') + 'msg=deleted';
                        }
                        window.location.href = returnUrl;
                    } else {
                        alert('Error: ' + data);
                    }
                },
                error: function (xhr, status, error) {
                    alert('Server error: ' + error);
                }
            });
        }
    }
    </script>
</body>
</html>
