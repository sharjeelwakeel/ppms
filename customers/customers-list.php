<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing customers
check_access('customers', 'show');

// Auto-migrate tbl_customers
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `fuel_rate` ENUM('Cash','Credit') NOT NULL DEFAULT 'Cash',
  `other_rate` ENUM('Cash','Credit') NOT NULL DEFAULT 'Cash',
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_fuel_rate` (`fuel_rate`),
  KEY `idx_other_rate` (`other_rate`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$canAdd    = has_permission('customers', 'add');
$canEdit   = has_permission('customers', 'edit');
$canDelete = has_permission('customers', 'delete');

$alert_message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Customer registered successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'updated') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Customer details updated successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'deleted') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Customer record removed successfully!<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Customer Master</title>
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
        #customersTable thead th {
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
                <div class="col-md-6">
                    <h4 class="font-weight-bold" style="color:var(--primary-color);">
                        <i class="fas fa-user-friends mr-2 text-primary"></i>Customer Master
                    </h4>
                    <p class="text-muted small mb-0">Manage customer accounts, default rate tiers (Cash/Credit), contact details, and suspension status.</p>
                </div>
                <div class="col-md-6 text-right">
                    <?php if ($canAdd): ?>
                    <a href="add-customer.php" class="btn btn-primary font-weight-bold shadow-sm">
                        <i class="fas fa-plus mr-1"></i> Add New Customer
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0" style="border-radius:10px; overflow:hidden;">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="customersTable" class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th width="5%">ID</th>
                                    <th>Customer Name</th>
                                    <th>Phone</th>
                                    <th>Address</th>
                                    <th style="text-align:center;">Fuel Rate</th>
                                    <th style="text-align:center;">Other Rate</th>
                                    <th style="text-align:center;">Status</th>
                                    <th>Created At</th>
                                    <?php if ($canDelete): ?>
                                    <th style="text-align: center; width: 6%;">Delete</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sql = "SELECT * FROM tbl_customers 
                                        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                                        ORDER BY id DESC";
                                $result = mysqli_query($connection, $sql);
                                if ($result && mysqli_num_rows($result) > 0) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $customerNameDisplay = $canEdit 
                                            ? '<a href="edit-customer.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a>'
                                            : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                        
                                        $fuelBadge = (strcasecmp($row['fuel_rate'], 'Credit') === 0) 
                                            ? '<span class="badge badge-info px-2 py-1"><i class="fas fa-file-invoice mr-1"></i> Credit</span>' 
                                            : '<span class="badge badge-success px-2 py-1"><i class="fas fa-money-bill-wave mr-1"></i> Cash</span>';
                                        
                                        $otherBadge = (strcasecmp($row['other_rate'], 'Credit') === 0) 
                                            ? '<span class="badge badge-info px-2 py-1"><i class="fas fa-file-invoice mr-1"></i> Credit</span>' 
                                            : '<span class="badge badge-success px-2 py-1"><i class="fas fa-money-bill-wave mr-1"></i> Cash</span>';
                                        
                                        $statusBadge = (strcasecmp($row['status'], 'Active') === 0)
                                            ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>'
                                            : '<span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactive</span>';
                                        
                                        echo ' 
                                            <tr>
                                                <td>'.$row['id'].'</td>
                                                <td>'.$customerNameDisplay.'</td>
                                                <td>'.(!empty($row['phone']) ? '<code>'.htmlspecialchars($row['phone']).'</code>' : '<span class="text-muted">—</span>').'</td>
                                                <td>'.(!empty($row['address']) ? htmlspecialchars($row['address']) : '<span class="text-muted">—</span>').'</td>
                                                <td class="text-center">'.$fuelBadge.'</td>
                                                <td class="text-center">'.$otherBadge.'</td>
                                                <td class="text-center">'.$statusBadge.'</td>
                                                <td>'.date("d-m-Y h:i A", strtotime($row['created_at'])).'</td>';
                                        if ($canDelete) {
                                            echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteCustomer('.$row['id'].', \''.addslashes(htmlspecialchars($row['name'])).'\')"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
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
        $('#customersTable').DataTable({
            "order": [[ 0, "desc" ]],
            "pageLength": 25
        });
    });

    function deleteCustomer(id, name){
        if(confirm('Are you sure you want to remove customer "' + name + '"?')) {
            $.ajax({
                type: "POST",
                url: "../include/deletecustomer.php",
                data: {id: id},
                success: function (data) {
                    if (data.trim() === 'deleted') {
                        window.location.href = 'customers-list.php?msg=deleted';
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
