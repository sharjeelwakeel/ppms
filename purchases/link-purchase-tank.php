<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';

// Auto-migrate tbl_purchase_tank_links schema if needed
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_purchase_tank_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `tank_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_tank_id` (`tank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    header('Location: purchases-list.php');
    exit;
}

$message = '';

// Fetch Purchase Record
$sql = "SELECT p.*, i.name as item_name 
        FROM tbl_purchases p 
        LEFT JOIN tbl_items i ON p.item_id = i.id 
        WHERE p.id='$id' AND p.deleted_at IS NULL LIMIT 1";
$result = mysqli_query($connection, $sql);
$purchase = mysqli_fetch_assoc($result);

if (!$purchase) {
    header('Location: purchases-list.php');
    exit;
}

// Calculate Stored and Remaining Quantities
$purchased_qty = floatval($purchase['quantity']);

$stored_sum_res = mysqli_query($connection, "SELECT SUM(quantity) as total_stored FROM tbl_purchase_tank_links WHERE purchase_id = '$id'");
$stored_sum_row = mysqli_fetch_assoc($stored_sum_res);
$stored_qty = floatval($stored_sum_row['total_stored'] ?? 0);
$remaining_qty = $purchased_qty - $stored_qty;
if ($remaining_qty < 0) $remaining_qty = 0;

// Handle Adding a Tank Link Entry
if (isset($_POST['add_tank_link'])) {
    $tank_id = intval($_POST['tank_id']);
    $link_quantity = floatval($_POST['quantity']);

    if ($tank_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Please select a valid tank.</div>';
    } else if ($link_quantity <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Quantity must be greater than 0.</div>';
    } else if ($link_quantity > ($remaining_qty + 0.001)) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Quantity cannot exceed the remaining unallocated balance (' . number_format($remaining_qty, 2) . ' Ltr).</div>';
    } else {
        $insert_sql = "INSERT INTO tbl_purchase_tank_links (purchase_id, tank_id, quantity) VALUES ('$id', '$tank_id', '$link_quantity')";
        if (mysqli_query($connection, $insert_sql)) {
            $message = '<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>Successfully allocated ' . number_format($link_quantity, 2) . ' Ltr to tank.</div>';
            
            // Recalculate quantities for immediate display
            $stored_sum_res = mysqli_query($connection, "SELECT SUM(quantity) as total_stored FROM tbl_purchase_tank_links WHERE purchase_id = '$id'");
            $stored_sum_row = mysqli_fetch_assoc($stored_sum_res);
            $stored_qty = floatval($stored_sum_row['total_stored'] ?? 0);
            $remaining_qty = $purchased_qty - $stored_qty;
            if ($remaining_qty < 0) $remaining_qty = 0;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error saving tank link: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch Tanks for Dropdown
$tanks_sql = "SELECT id, tank_name FROM tbl_tanks ORDER BY tank_name ASC";
$tanks_result = mysqli_query($connection, $tanks_sql);

// Fetch Existing Tank Allocations
$links_sql = "SELECT l.*, t.tank_name 
              FROM tbl_purchase_tank_links l 
              LEFT JOIN tbl_tanks t ON l.tank_id = t.id 
              WHERE l.purchase_id = '$id' 
              ORDER BY l.id DESC";
$links_result = mysqli_query($connection, $links_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Tank Allocation for Purchase #<?php echo $id; ?></title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }

        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 18px 28px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.3rem; }

        .summary-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border-left: 5px solid var(--primary-color);
            padding: 20px 24px;
            margin-bottom: 22px;
        }

        .stat-box {
            text-align: center;
            padding: 12px 16px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .stat-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; }
        .stat-value { font-size: 20px; font-weight: 700; margin-top: 2px; }

        .card-custom {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border: none;
            overflow: hidden;
            margin-bottom: 22px;
        }
        .card-custom .card-header {
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            padding: 12px 20px;
            font-size: 14px;
        }

        .btn-link-save {
            background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
            color: #fff;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(23,162,184,0.25);
        }
        .btn-link-save:hover { opacity: 0.95; color: #fff; }
    </style>
</head>
<body>
<?php include('../include/navbar.php');?>
<main class="main">
    <div class="container pt-4 pb-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-link mr-2"></i>Tank Allocation for Purchase #<?php echo $id; ?></h4>
                <small class="text-white-50">
                    Item: <strong><?php echo htmlspecialchars($purchase['item_name'] ?? 'N/A'); ?></strong> &nbsp;|&nbsp;
                    Invoice: <strong><?php echo htmlspecialchars($purchase['invoice_number'] ?? 'N/A'); ?></strong> &nbsp;|&nbsp;
                    Date: <strong><?php echo date('d-m-Y', strtotime($purchase['date'])); ?></strong>
                </small>
            </div>
            <a href="purchases-list.php" class="btn btn-sm btn-light font-weight-bold" style="border-radius:6px; color:#04204e;">
                <i class="fas fa-arrow-left mr-1"></i> Back to Purchases
            </a>
        </div>

        <?php echo $message; ?>

        <!-- Quantity Summary Bar -->
        <div class="summary-card mb-4">
            <div class="row align-items-center">
                <div class="col-md-4 mb-2 mb-md-0">
                    <div class="stat-box">
                        <div class="stat-label"><i class="fas fa-shopping-bag mr-1 text-primary"></i> Total Purchased</div>
                        <div class="stat-value text-primary"><?php echo number_format($purchased_qty, 2); ?> <small style="font-size:12px;">Ltr</small></div>
                    </div>
                </div>
                <div class="col-md-4 mb-2 mb-md-0">
                    <div class="stat-box" style="background:#f0fdf4; border-color:#bbf7d0;">
                        <div class="stat-label"><i class="fas fa-oil-can mr-1 text-success"></i> Stored in Tanks</div>
                        <div class="stat-value text-success"><?php echo number_format($stored_qty, 2); ?> <small style="font-size:12px;">Ltr</small></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box" style="background:#fff5f5; border-color:#fecaca;">
                        <div class="stat-label"><i class="fas fa-balance-scale-left mr-1 text-danger"></i> Remaining Unallocated</div>
                        <div class="stat-value text-danger"><?php echo number_format($remaining_qty, 2); ?> <small style="font-size:12px;">Ltr</small></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Add Allocation Form -->
            <div class="col-lg-5 mb-4">
                <div class="card-custom h-100">
                    <div class="card-header">
                        <i class="fas fa-plus-circle mr-1"></i> Store Purchase in Tank
                    </div>
                    <div class="card-body p-4">
                        <?php if ($remaining_qty <= 0): ?>
                            <div class="alert alert-success text-center font-weight-bold py-3 mb-0">
                                <i class="fas fa-check-circle mr-1"></i> Entire purchase quantity has been fully allocated to tanks!
                            </div>
                        <?php else: ?>
                            <form action="link-purchase-tank.php?id=<?php echo $id; ?>" method="POST">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold" style="font-size:13px; color:#444;"><i class="fas fa-oil-can mr-1 text-info"></i> Target Tank <span class="text-danger">*</span></label>
                                    <select name="tank_id" class="form-control" style="border-radius:7px;" required>
                                        <option value="">-- Select Tank --</option>
                                        <?php
                                        if (mysqli_num_rows($tanks_result) > 0) {
                                            mysqli_data_seek($tanks_result, 0);
                                            while ($tank = mysqli_fetch_assoc($tanks_result)) {
                                                echo '<option value="' . $tank['id'] . '">' . htmlspecialchars($tank['tank_name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group mb-4">
                                    <label class="font-weight-bold" style="font-size:13px; color:#444;"><i class="fas fa-fill-drip mr-1 text-info"></i> Stored Quantity (Ltr) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" max="<?php echo $remaining_qty; ?>" name="quantity" class="form-control" placeholder="Enter quantity in liters" style="border-radius:7px;" required>
                                    <small class="form-text text-muted">Maximum available: <strong><?php echo number_format($remaining_qty, 2); ?> Ltr</strong></small>
                                </div>
                                <button type="submit" name="add_tank_link" class="btn btn-link-save btn-block py-2">
                                    <i class="fas fa-save mr-1"></i> Store to Tank
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Existing Allocations List -->
            <div class="col-lg-7 mb-4">
                <div class="card-custom h-100">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-list mr-1"></i> Tank Storage History
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered mb-0" style="font-size:13px;">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:40px;" class="text-center">#</th>
                                        <th>Tank Name</th>
                                        <th class="text-right">Quantity Stored (Ltr)</th>
                                        <th>Date Stored</th>
                                        <th style="width:60px;" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($links_result && mysqli_num_rows($links_result) > 0) {
                                        $ln = 1;
                                        while ($link = mysqli_fetch_assoc($links_result)) {
                                            echo '<tr>
                                                <td class="text-center font-weight-bold text-muted">' . $ln++ . '</td>
                                                <td><i class="fas fa-oil-can mr-1 text-info"></i> <strong>' . htmlspecialchars($link['tank_name'] ?? 'N/A') . '</strong></td>
                                                <td class="text-right font-weight-bold text-success">' . number_format($link['quantity'], 2) . '</td>
                                                <td>' . date('d-m-Y h:i A', strtotime($link['created_at'])) . '</td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-link text-danger p-0" onclick="deleteTankLink(' . $link['id'] . ')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center text-muted py-4">No tank allocations recorded yet for this purchase.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                                <?php if ($stored_qty > 0): ?>
                                <tfoot>
                                    <tr class="font-weight-bold bg-light">
                                        <td colspan="2" class="text-right">Total Stored:</td>
                                        <td class="text-right text-success"><?php echo number_format($stored_qty, 2); ?> Ltr</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script>
function deleteTankLink(linkId) {
    if (confirm('Are you sure you want to remove this tank allocation? Allocated quantity will be restored to remaining balance.')) {
        $.ajax({
            type: "POST",
            url: "../include/deletepurchasetanklink.php",
            data: { id: linkId },
            success: function (res) {
                if (res.trim() === 'deleted') {
                    location.reload();
                } else {
                    alert('Error: ' + res);
                }
            },
            error: function (err) {
                console.log(err);
                alert('Server error occurred.');
            }
        });
    }
}
</script>
</body>
</html>
