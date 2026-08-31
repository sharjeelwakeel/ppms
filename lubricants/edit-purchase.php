<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for editing lubricant purchase
check_access('items', 'edit');

// Auto-migrate tbl_lubricant_purchases: ensure quantity is integer
$chk_pur_qty = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_purchases LIKE 'quantity'");
if ($chk_pur_qty && $col = mysqli_fetch_assoc($chk_pur_qty)) {
    if (stripos($col['Type'], 'int') === false) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_purchases MODIFY COLUMN quantity INT(11) NOT NULL DEFAULT 0");
    }
}
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_lubricant_purchase_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bank_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lubricant_purchase_id` (`purchase_id`),
  KEY `idx_bank_id` (`bank_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$message = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: purchases-list.php');
    exit;
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'payment_added') {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i> Payment recorded successfully.</div>';
    } else if ($_GET['msg'] == 'payment_deleted') {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i> Payment removed successfully and status recalculated.</div>';
    } else if ($_GET['msg'] == 'purchase_updated') {
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i> Purchase details updated successfully.</div>';
    }
}

// Handle Purchase Details Update
if (isset($_POST['update_purchase'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $purchase_price = floatval($_POST['purchase_price']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);

    mysqli_begin_transaction($connection);
    try {
        $total_cost = $quantity * $purchase_price;

        // Fetch sum of existing active payments
        $pay_sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_lubricant_purchase_payments WHERE purchase_id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $pay_sum_row = mysqli_fetch_assoc($pay_sum_res);
        $total_paid = floatval($pay_sum_row['total_paid'] ?? 0);

        $new_status = 'unpaid';
        if ($total_paid >= $total_cost && $total_cost > 0) {
            $new_status = 'paid';
        } else if ($total_paid > 0) {
            $new_status = 'in process';
        }

        $query = "UPDATE tbl_lubricant_purchases SET 
                  product_id='$product_id', 
                  quantity='$quantity', 
                  purchase_price='$purchase_price', 
                  date='$date', 
                  payment_status='$new_status' 
                  WHERE id='$id'";
        
        if (!mysqli_query($connection, $query)) {
            throw new Exception(mysqli_error($connection));
        }

        mysqli_commit($connection);
        header('Location: edit-purchase.php?id=' . $id . '&msg=purchase_updated');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error updating purchase: ' . $e->getMessage() . '</div>';
    }
}

// Handle Adding a Partial Payment
if (isset($_POST['add_payment'])) {
    $payment_date = mysqli_real_escape_string($connection, $_POST['payment_date']);
    $payment_amount = floatval($_POST['payment_amount']);
    $bank_id = intval($_POST['bank_id']);

    if ($payment_amount <= 0 || $bank_id <= 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> Please provide a valid payment amount and select a bank account.</div>';
    } else {
        mysqli_begin_transaction($connection);
        try {
            // 1. Insert Payment
            $insert_payment = "INSERT INTO tbl_lubricant_purchase_payments (purchase_id, date, amount, bank_id) 
                               VALUES ('$id', '$payment_date', '$payment_amount', '$bank_id')";
            if (!mysqli_query($connection, $insert_payment)) {
                throw new Exception(mysqli_error($connection));
            }

            // 2. Fetch Purchase details for recalculating status
            $purch_res = mysqli_query($connection, "SELECT quantity, purchase_price FROM tbl_lubricant_purchases WHERE id = '$id' LIMIT 1");
            $purch_row = mysqli_fetch_assoc($purch_res);
            $total_cost = floatval($purch_row['quantity'] ?? 0) * floatval($purch_row['purchase_price'] ?? 0);

            // 3. Fetch Sum of Payments
            $sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_lubricant_purchase_payments WHERE purchase_id = '$id' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $sum_row = mysqli_fetch_assoc($sum_res);
            $total_paid = floatval($sum_row['total_paid'] ?? 0);

            // 4. Update Purchase Status
            $new_status = 'unpaid';
            if ($total_paid >= $total_cost && $total_cost > 0) {
                $new_status = 'paid';
            } else if ($total_paid > 0) {
                $new_status = 'in process';
            }

            if (!mysqli_query($connection, "UPDATE tbl_lubricant_purchases SET payment_status = '$new_status' WHERE id = '$id'")) {
                throw new Exception(mysqli_error($connection));
            }

            mysqli_commit($connection);
            header('Location: edit-purchase.php?id=' . $id . '&msg=payment_added');
            exit;
        } catch (Exception $e) {
            mysqli_rollback($connection);
            $message = '<div class="alert alert-danger">Error recording payment: ' . $e->getMessage() . '</div>';
        }
    }
}

// Fetch current purchase record
$sql = "SELECT pur.*, p.name as product_name 
        FROM tbl_lubricant_purchases pur 
        LEFT JOIN tbl_lubricant_products p ON pur.product_id = p.id 
        WHERE pur.id='$id' AND (pur.deleted_at IS NULL OR pur.deleted_at = '0000-00-00 00:00:00')";
$result = mysqli_query($connection, $sql);
$purchase = mysqli_fetch_assoc($result);

if (!$purchase) {
    header('Location: purchases-list.php');
    exit;
}

// Fetch products
$products_sql = "SELECT id, name FROM tbl_lubricant_products WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY name ASC";
$products_result = mysqli_query($connection, $products_sql);

// Fetch active banks for payment dropdown
$banks_sql = "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC";
$banks_result = mysqli_query($connection, $banks_sql);

// Fetch Payment History
$payments_sql = "SELECT pay.*, b.name as bank_name, b.account_number as bank_account
                 FROM tbl_lubricant_purchase_payments pay
                 LEFT JOIN tbl_banks b ON pay.bank_id = b.id
                 WHERE pay.purchase_id = '$id' AND pay.deleted_at IS NULL
                 ORDER BY pay.id DESC";
$payments_result = mysqli_query($connection, $payments_sql);

// Financial summary calculations
$total_cost = intval($purchase['quantity']) * floatval($purchase['purchase_price']);
$payments_sum_res = mysqli_query($connection, "SELECT SUM(amount) as total_paid FROM tbl_lubricant_purchase_payments WHERE purchase_id = '$id' AND deleted_at IS NULL");
$payments_sum_row = mysqli_fetch_assoc($payments_sum_res);
$total_paid = floatval($payments_sum_row['total_paid'] ?? 0);
$remaining_amount = max(0, $total_cost - $total_paid);

$status_badge = 'badge-danger';
$status_label = 'Unpaid';
if (strcasecmp($purchase['payment_status'], 'paid') == 0 || $total_paid >= $total_cost && $total_cost > 0) {
    $status_badge = 'badge-success';
    $status_label = 'Paid';
} else if ($total_paid > 0) {
    $status_badge = 'badge-warning';
    $status_label = 'In Process';
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
		.m-top{ margin-top:20px; }
		.txt-center{ text-align:center; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        .summary-kpi-card {
            border-radius: 8px;
            padding: 16px 20px;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid var(--primary-color);
            margin-bottom: 20px;
        }
        .summary-kpi-title {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .summary-kpi-val {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 0;
            color: #212529;
        }
		</style>
		<title>PPMS - Edit Inflow Purchase & Payments</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-7">
						<h4><i class="fas fa-boxes mr-2 text-primary"></i>Edit Inflow Purchase #<?php echo $purchase['id']; ?></h4>
					</div>
					<div class="col-md-5 text-right">
                        <a href="purchases-list.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Purchases</a>
					</div>
				</div>

                <?php echo $message; ?>

                <!-- Summary KPI Row -->
                <div class="row mb-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="summary-kpi-card" style="border-left-color: var(--primary-color);">
                            <div class="summary-kpi-title">Total Cost</div>
                            <div class="summary-kpi-val" style="color: var(--primary-color);">Rs. <?php echo number_format($total_cost, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="summary-kpi-card" style="border-left-color: #28a745;">
                            <div class="summary-kpi-title">Total Paid</div>
                            <div class="summary-kpi-val text-success">Rs. <?php echo number_format($total_paid, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="summary-kpi-card" style="border-left-color: #dc3545;">
                            <div class="summary-kpi-title">Remaining Balance</div>
                            <div class="summary-kpi-val text-danger">Rs. <?php echo number_format($remaining_amount, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="summary-kpi-card" style="border-left-color: #ffc107;">
                            <div class="summary-kpi-title">Payment Status</div>
                            <div class="summary-kpi-val">
                                <span class="badge <?php echo $status_badge; ?> px-3 py-2" style="font-size: 14px;"><?php echo $status_label; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left: Purchase Details Form -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="font-weight-bold mb-0" style="color: var(--primary-color);"><i class="fas fa-edit mr-2"></i>Purchase Details</h6>
                            </div>
                            <div class="card-body">
                                <form action="edit-purchase.php?id=<?php echo $id; ?>" method="POST">
                                    <div class="form-group row">
                                        <label class="col-sm-4 col-form-label font-weight-bold">Product</label>
                                        <div class="col-sm-8">
                                            <select name="product_id" class="form-control" required>
                                                <option value="">Select Product</option>
                                                <?php 
                                                if ($products_result && mysqli_num_rows($products_result) > 0) {
                                                    while ($product = mysqli_fetch_assoc($products_result)) {
                                                        $selected = ($product['id'] == $purchase['product_id']) ? 'selected' : '';
                                                        echo '<option value="' . $product['id'] . '" ' . $selected . '>' . htmlspecialchars($product['name']) . '</option>';
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label class="col-sm-4 col-form-label font-weight-bold">Quantity</label>
                                        <div class="col-sm-8">
                                            <input type="number" step="1" min="1" name="quantity" class="form-control" placeholder="e.g. 10" value="<?php echo intval($purchase['quantity']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label class="col-sm-4 col-form-label font-weight-bold">Purchase Price</label>
                                        <div class="col-sm-8">
                                            <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" placeholder="0.00" value="<?php echo htmlspecialchars($purchase['purchase_price']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label class="col-sm-4 col-form-label font-weight-bold">Purchase Date</label>
                                        <div class="col-sm-8">
                                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($purchase['date']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="text-right mt-4">
                                        <button type="submit" name="update_purchase" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update Details</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Add Partial Payment Form -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="font-weight-bold mb-0 text-success"><i class="fas fa-money-bill-wave mr-2"></i>Record Bank Payment</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($remaining_amount <= 0 && $total_cost > 0): ?>
                                    <div class="alert alert-success my-4 text-center py-4">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5 class="font-weight-bold">Payment Completed!</h5>
                                        <p class="mb-0 text-muted">This stock purchase has been paid in full (Rs. <?php echo number_format($total_paid, 2); ?>).</p>
                                    </div>
                                <?php else: ?>
                                    <form action="edit-purchase.php?id=<?php echo $id; ?>" method="POST">
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label font-weight-bold">Payment Date</label>
                                            <div class="col-sm-8">
                                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label font-weight-bold">Bank Account</label>
                                            <div class="col-sm-8">
                                                <select name="bank_id" class="form-control" required>
                                                    <option value="">Select Bank Account</option>
                                                    <?php 
                                                    if ($banks_result && mysqli_num_rows($banks_result) > 0) {
                                                        while ($bank = mysqli_fetch_assoc($banks_result)) {
                                                            echo '<option value="' . $bank['id'] . '">' . htmlspecialchars($bank['name'] . ' (' . $bank['account_number'] . ')') . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label font-weight-bold">Amount (Rs.)</label>
                                            <div class="col-sm-8">
                                                <input type="number" step="0.01" min="0.01" max="<?php echo ($remaining_amount > 0) ? $remaining_amount : '99999999'; ?>" name="payment_amount" class="form-control font-weight-bold" placeholder="Rs. 0.00" value="<?php echo ($remaining_amount > 0) ? $remaining_amount : ''; ?>" required>
                                                <small class="text-muted">Remaining Balance: Rs. <?php echo number_format($remaining_amount, 2); ?></small>
                                            </div>
                                        </div>
                                        <div class="text-right mt-4">
                                            <button type="submit" name="add_payment" class="btn btn-success font-weight-bold px-4"><i class="fas fa-check-circle mr-1"></i> Record Payment</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment History Table -->
                <div class="card shadow-sm mb-5">
                    <div class="card-header bg-white py-3">
                        <h6 class="font-weight-bold mb-0 text-dark"><i class="fas fa-history mr-2 text-primary"></i>Disbursed Payment History</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered mb-0">
                                <thead>
                                    <tr style="background:#04204e; color:#fff;">
                                        <th style="width:70px;">ID</th>
                                        <th>Date</th>
                                        <th>Bank Name</th>
                                        <th>Account Number</th>
                                        <th>Amount (Rs.)</th>
                                        <th style="text-align:center; width:90px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($payments_result && mysqli_num_rows($payments_result) > 0) {
                                        while ($pay = mysqli_fetch_assoc($payments_result)) {
                                            echo '
                                            <tr>
                                                <td>' . $pay['id'] . '</td>
                                                <td>' . date("d-m-Y", strtotime($pay['date'])) . '</td>
                                                <td>' . htmlspecialchars($pay['bank_name'] ?? 'N/A') . '</td>
                                                <td>' . htmlspecialchars($pay['bank_account'] ?? 'N/A') . '</td>
                                                <td class="font-weight-bold text-success">Rs. ' . number_format($pay['amount'], 2) . '</td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-link p-0 text-danger" onclick="deletePayment(' . $pay['id'] . ')" title="Delete Payment">
                                                        <i class="fas fa-trash-alt" style="font-size: 16px;"></i>
                                                    </button>
                                                </td>
                                            </tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-info-circle mr-1"></i> No payment disbursements recorded yet for this purchase.</td></tr>';
                                    }
                                    ?>
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
    function deletePayment(id) {
        if (confirm('Are you sure you want to remove this payment entry? The purchase payment status will be recalculated.')) {
            $.ajax({
                type: "POST",
                url: "../include/deletelubricantpurchasepayment.php",
                data: { id: id },
                success: function(response) {
                    if (response.trim() === 'deleted') {
                        window.location.href = 'edit-purchase.php?id=' + encodeURIComponent('<?php echo $id; ?>') + '&msg=payment_deleted';
                    } else {
                        alert('Error: ' + response);
                    }
                },
                error: function(xhr, status, error) {
                    alert('An error occurred while deleting the payment entry: ' + error);
                }
            });
        }
    }
    </script>
</html>
