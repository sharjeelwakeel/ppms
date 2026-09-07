<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/price_helper.php';

// Enforce access check for editing items
check_access('items', 'edit');

// Initialize polymorphic prices table
init_prices_table($connection);

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    header('Location: items-list.php');
    exit;
}

// Fetch the existing item data first
$sql = "SELECT * FROM tbl_items WHERE id='$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
$result = mysqli_query($connection, $sql);
$item = mysqli_fetch_assoc($result);

if (!$item) {
    header('Location: items-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $name = mysqli_real_escape_string($connection, trim($_POST['name']));
    $cash_rate = floatval($_POST['cash_rate']);
    $credit_rate = floatval($_POST['credit_rate']);
    $purchase_rate = floatval($_POST['purchase_rate']);
    $unit = mysqli_real_escape_string($connection, trim($_POST['unit']));
    $effective_date = !empty($_POST['effective_date']) ? mysqli_real_escape_string($connection, trim($_POST['effective_date'])) : date('Y-m-d');
    $notes = !empty($_POST['notes']) ? mysqli_real_escape_string($connection, trim($_POST['notes'])) : 'Price revised from edit item';
    $user_id = intval($_SESSION['loggedInUser'] ?? 0);

    // Detect if rates were changed
    $rates_changed = (abs($cash_rate - floatval($item['cash_rate'])) > 0.0001) ||
                     (abs($credit_rate - floatval($item['credit_rate'])) > 0.0001) ||
                     (abs($purchase_rate - floatval($item['purchase_rate'])) > 0.0001);

    // Check if active price entry exists
    $chk_act = mysqli_query($connection, "SELECT id FROM tbl_prices WHERE table_name = 'tbl_items' AND table_id = '$id' AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
    $has_active = ($chk_act && mysqli_num_rows($chk_act) > 0);

    $query = "UPDATE tbl_items SET 
                name='$name', 
                cash_rate='$cash_rate', 
                credit_rate='$credit_rate', 
                purchase_rate='$purchase_rate', 
                unit='$unit' 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        if ($rates_changed || !$has_active) {
            set_active_price($connection, 'tbl_items', $id, $cash_rate, $credit_rate, $purchase_rate, $effective_date, $notes, $user_id);
        }
        header('Location: items-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating item: ' . mysqli_error($connection) . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{
			margin-top:20px;
		}
		.txt-center{
			text-align:center;
		}
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        .table thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS Edit Item</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-item.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-4"><i class="fas fa-boxes mr-2 text-primary"></i>Edit Item</h4>
                    <?php echo $message; ?>
					<div class="card mb-4">
                        <div class="card-header bg-white font-weight-bold">
                            <i class="fas fa-info-circle mr-1 text-primary"></i> Item Information &amp; Rates
                        </div>
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Item Name</label>
										<div class="col-lg-8 col-md-7">
											<input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($item['name']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Unit</label>
										<div class="col-lg-8 col-md-7">
											<select name="unit" class="form-control" required>
												<option value="Ltr" selected>Ltr</option>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Effective Date</label>
										<div class="col-lg-8 col-md-7">
											<input type="date" name="effective_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
											<small class="form-text text-muted">Applicable date if rates are revised.</small>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Revision Notes</label>
										<div class="col-lg-8 col-md-7">
											<input type="text" name="notes" class="form-control" placeholder="e.g. Government price revision">
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Cash Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="cash_rate" class="form-control" value="<?php echo htmlspecialchars($item['cash_rate']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Credit Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="credit_rate" class="form-control" value="<?php echo htmlspecialchars($item['credit_rate']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-form-label font-weight-bold">Purchase Rate (Rs.)</label>
										<div class="col-lg-8 col-md-7">
											<input type="number" step="0.01" name="purchase_rate" class="form-control" value="<?php echo htmlspecialchars($item['purchase_rate']); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center mb-5">
						<button type="submit" name="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update Item</button>
                        <a href="items-list.php" class="btn btn-secondary ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>

                <!-- Price Change History Section -->
                <?php $history = get_price_history($connection, 'tbl_items', $id); ?>
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold"><i class="fas fa-history mr-2 text-primary"></i>Price Change History (<?php echo htmlspecialchars($item['name']); ?>)</span>
                        <span class="badge badge-info"><?php echo count($history); ?> Record(s)</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0 text-center">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Status</th>
                                        <th>Effective Date</th>
                                        <th>Cash Rate (Rs.)</th>
                                        <th>Credit Rate (Rs.)</th>
                                        <th>Purchase Rate (Rs.)</th>
                                        <th>Notes</th>
                                        <th>Recorded At</th>
                                        <th style="width: 60px;">Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($history)): ?>
                                        <?php foreach ($history as $p): ?>
                                            <tr class="<?php echo $p['is_active'] ? 'table-success font-weight-bold' : ''; ?>">
                                                <td>
                                                    <?php if ($p['is_active']): ?>
                                                        <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Current</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><i class="fas fa-history mr-1"></i>Past</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($p['effective_date']) ? date('d-m-Y', strtotime($p['effective_date'])) : '-'; ?></td>
                                                <td class="text-success font-weight-bold">Rs. <?php echo number_format($p['cash_rate'], 2); ?></td>
                                                <td class="text-primary font-weight-bold">Rs. <?php echo number_format($p['credit_rate'], 2); ?></td>
                                                <td class="text-muted">Rs. <?php echo number_format($p['purchase_rate'], 2); ?></td>
                                                <td class="text-left"><?php echo htmlspecialchars($p['notes'] ?? '-'); ?></td>
                                                <td class="text-muted small"><?php echo !empty($p['created_at']) ? date('d-m-Y H:i', strtotime($p['created_at'])) : '-'; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Soft delete price" onclick="deletePrice(<?php echo $p['id']; ?>)">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-muted p-3">No price history recorded yet for this item.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script>
    function deletePrice(priceId) {
        if (confirm('Are you sure you want to delete this price record?')) {
            $.ajax({
                url: 'delete-price.php',
                type: 'POST',
                dataType: 'json',
                data: { id: priceId },
                success: function(res) {
                    if (res.status === 'success') {
                        location.reload();
                    } else {
                        alert(res.message || 'Error deleting price record.');
                    }
                },
                error: function() {
                    alert('Server communication error.');
                }
            });
        }
    }
    </script>
</html>
