<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for purchases list
check_access('purchases', 'show');

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

// Fetch active banks for payment modal
$banks_res = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC");
$banks_list = [];
if ($banks_res) {
    while ($b = mysqli_fetch_assoc($banks_res)) {
        $banks_list[] = $b;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{
			margin-top:20px;
		}
		.m-bot{
			margin-bottom:20px;
		}
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #purchasesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            white-space: nowrap;
        }
		</style>
		<title>PPMS - Purchases</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container-fluid pt-4 pb-4 px-lg-5">
				<div class="row mb-5 align-items-center">
					<div class="col-md-6">
						<h4>View Purchases</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if (has_permission('purchases', 'add')): ?>
						<a href="add-purchase.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Purchase</a>
                        <?php endif; ?>
					</div>
				</div>
				<div class="table-responsive">
					<table id="purchasesTable" class="table table-striped table-bordered">
						<thead>
							<tr>
								<th>ID</th>
								<th>Item Name</th>
								<th>Quantity</th>
								<th>Unit Price</th>
								<th>Total Amount</th>
								<th>Paid Amount</th>
								<th>Remaining Amount</th>
								<th style="min-width: 105px; white-space: nowrap;">Date</th>
								<th>Status</th>
								<th>Route</th>
								<th>Invoice No</th>
								<th>Carriage Invoice No</th>
								<th style="text-align: center;">Linked To</th>
								<th style="text-align: center; min-width: 95px;">Payment</th>
                                <?php if (has_permission('purchases', 'delete')): ?>
								<th style="text-align: center;">Delete</th>
                                <?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php 
							$sql = "SELECT p.*, i.name as item_name, 
									       COALESCE((SELECT SUM(pay.amount) FROM tbl_purchase_payments pay WHERE pay.purchase_id = p.id AND pay.deleted_at IS NULL), 0) as paid_amount,
									       COALESCE((SELECT SUM(link.quantity) FROM tbl_purchase_tank_links link WHERE link.purchase_id = p.id), 0) as stored_quantity
									FROM tbl_purchases p 
									LEFT JOIN tbl_items i ON p.item_id = i.id 
									WHERE p.deleted_at IS NULL
									ORDER BY p.id DESC";
							$result = mysqli_query($connection, $sql);
							if($result && mysqli_num_rows($result) > 0){
								while($row = mysqli_fetch_assoc($result)){
									$total_amount = floatval($row['quantity']) * floatval($row['price']);
									$paid_amount = floatval($row['paid_amount']);
									$remaining_amount = $total_amount - $paid_amount;
									
									$purchased_qty = floatval($row['quantity']);
									$stored_qty = floatval($row['stored_quantity']);

									$linkStatusColor = 'text-danger';
									if ($stored_qty >= $purchased_qty && $purchased_qty > 0) {
										$linkStatusColor = 'text-success';
									} else if ($stored_qty > 0) {
										$linkStatusColor = 'text-warning';
									}
									
									$statusBadge = 'badge-danger';
									if ($row['payment_status'] == 'paid') {
										$statusBadge = 'badge-success';
									} else if ($row['payment_status'] == 'in process') {
										$statusBadge = 'badge-warning';
									}

                                    $canEdit = has_permission('purchases', 'edit');
                                    $itemLink = $canEdit 
                                        ? '<a href="edit-purchase.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['item_name'] ?? 'N/A').'</a>'
                                        : htmlspecialchars($row['item_name'] ?? 'N/A');
									$isFullyPaid = ($remaining_amount <= 0.001);
									$payBtnClass = $isFullyPaid ? 'btn-outline-secondary' : 'btn-outline-success';
									$payBtnIcon  = $isFullyPaid ? 'fa-history' : 'fa-money-bill-wave';
									$payBtnText  = $isFullyPaid ? 'History' : 'Pay';
									$payBtnTitle = $isFullyPaid ? 'View Payment History' : 'Pay Remaining (Rs. ' . number_format($remaining_amount, 2) . ')';

									echo' 
										<tr>
											<td>'.$row['id'].'</td>
											<td>'.$itemLink.'</td>
											<td>'.number_format($row['quantity'], 2).'</td>
											<td>'.number_format($row['price'], 2).'</td>
											<td class="font-weight-bold">'.number_format($total_amount, 2).'</td>
											<td class="text-success font-weight-bold">'.number_format($paid_amount, 2).'</td>
											<td class="text-danger font-weight-bold">'.number_format($remaining_amount, 2).'</td>
											<td class="text-nowrap font-weight-bold">'.date("d-m-Y", strtotime($row['date'])).'</td>
											<td><span class="badge '.$statusBadge.'">'.ucfirst(htmlspecialchars($row['payment_status'])).'</span></td>
											<td>'.htmlspecialchars($row['route']).'</td>
											<td>'.htmlspecialchars($row['invoice_number']).'</td>
											<td>'.htmlspecialchars($row['carriage_invoice_number']).'</td>
											<td class="text-center" style="white-space:nowrap;">
												<a href="link-purchase-tank.php?id='.$row['id'].'" class="btn btn-sm font-weight-bold px-2 py-1 mb-1" style="background:linear-gradient(135deg, #17a2b8 0%, #117a8b 100%); color:#fff; border-radius:6px; font-size:12px; text-decoration:none; display:inline-block; border:none; box-shadow:0 2px 6px rgba(23,162,184,0.25);">
													<i class="fas fa-link mr-1"></i> Linked To
												</a>
												<br>
												<small class="font-weight-bold '.$linkStatusColor.'">'.number_format($stored_qty, 2).' / '.number_format($purchased_qty, 2).' Ltr</small>
											</td>
											<td class="text-center" style="white-space:nowrap;">
												<button type="button" class="btn btn-sm '.$payBtnClass.' font-weight-bold px-2 py-1" onclick="openPaymentModal('.$row['id'].')" title="'.$payBtnTitle.'">
													<i class="fas '.$payBtnIcon.' mr-1"></i> '.$payBtnText.'
												</button>
											</td>';
                                    if (has_permission('purchases', 'delete')) {
                                        echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deletePurchase('.$row['id'].')"><i class="fas fa-trash-alt" style="font-size: 20px;"></i></a></td>';
                                    }
									echo '</tr>';
								}
							}
							?>
						</tbody>
					</table>
				</div>
			</div>
		</main>

        <!-- Modal: Purchase Payment & History -->
        <div class="modal fade" id="purchasePaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow" style="border-radius: 10px; overflow: hidden;">
                    <div class="modal-header text-white" style="background: var(--primary-gradient);">
                        <h5 class="modal-title font-weight-bold">
                            <i class="fas fa-file-invoice-dollar mr-2 text-warning"></i>
                            Purchase Payment &amp; History: <span id="modalPurchaseTitle">Loading...</span>
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <div id="modalAlertBox"></div>

                        <!-- Financial Summary Cards -->
                        <div class="row mb-4 text-center">
                            <div class="col-md-4 mb-2 mb-md-0">
                                <div class="p-3 bg-light border rounded">
                                    <span class="text-muted d-block small font-weight-bold text-uppercase">Total Cost</span>
                                    <span class="h5 font-weight-bold text-dark mb-0" id="modalTotalCost">Rs. 0.00</span>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2 mb-md-0">
                                <div class="p-3 bg-light border rounded">
                                    <span class="text-muted d-block small font-weight-bold text-uppercase">Total Paid</span>
                                    <span class="h5 font-weight-bold text-success mb-0" id="modalTotalPaid">Rs. 0.00</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light border rounded">
                                    <span class="text-muted d-block small font-weight-bold text-uppercase">Remaining Balance</span>
                                    <span class="h5 font-weight-bold text-danger mb-0" id="modalRemainingAmount">Rs. 0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Add Payment Section (Active only when Remaining Balance > 0) -->
                        <div id="addPaymentSection" class="card mb-4 border-primary shadow-sm" style="display: none;">
                            <div class="card-header bg-light py-2">
                                <h6 class="mb-0 font-weight-bold text-primary">
                                    <i class="fas fa-plus-circle mr-1"></i> Add Partial / Full Payment
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <form id="purchasePaymentForm" onsubmit="submitPurchasePayment(event)">
                                    <input type="hidden" name="purchase_id" id="modal_purchase_id">
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label class="font-weight-bold small"><i class="fas fa-calendar-day mr-1 text-primary"></i> Date <span class="text-danger">*</span></label>
                                            <input type="date" name="payment_date" id="modal_payment_date" class="form-control form-control-sm font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label class="font-weight-bold small"><i class="fas fa-university mr-1 text-primary"></i> Bank Source <span class="text-danger">*</span></label>
                                            <select name="bank_id" id="modal_bank_id" class="form-control form-control-sm font-weight-bold" required>
                                                <option value="">Select Bank Master</option>
                                                <?php foreach ($banks_list as $bk): ?>
                                                    <option value="<?php echo $bk['id']; ?>">
                                                        <?php echo htmlspecialchars($bk['name'] . ' (' . $bk['account_number'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label class="font-weight-bold small"><i class="fas fa-money-bill-wave mr-1 text-success"></i> Amount (Rs.) <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="0.01" name="payment_amount" id="modal_payment_amount" class="form-control form-control-sm font-weight-bold text-success" placeholder="0.00" oninput="validateModalPaymentAmount(this)" required>
                                            <small id="overpaymentWarning" class="text-danger font-weight-bold" style="display: none;">Cannot exceed remaining balance!</small>
                                        </div>
                                        <div class="form-group col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-outline-info btn-sm btn-block font-weight-bold mb-0" onclick="fillExactRemaining()" title="Pay full remaining balance">
                                                <i class="fas fa-bolt mr-1"></i> Pay Full
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-right mt-1">
                                        <button type="submit" id="btnSubmitPayment" class="btn btn-primary btn-sm px-4 font-weight-bold shadow-sm">
                                            <i class="fas fa-save mr-1"></i> Save Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Fully Paid Alert Banner (Displayed when Remaining Balance == 0) -->
                        <div id="fullyPaidBanner" class="alert alert-success text-center py-3 mb-4 shadow-sm" style="display: none; border-radius: 8px;">
                            <i class="fas fa-check-circle fa-2x mb-2 text-success d-block"></i>
                            <h6 class="font-weight-bold mb-1">Fully Paid!</h6>
                            <p class="small mb-0 text-muted">This purchase order has been settled in full. No additional payments required.</p>
                        </div>

                        <!-- Payment History Table -->
                        <div class="card border">
                            <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 font-weight-bold text-dark">
                                    <i class="fas fa-history mr-1 text-info"></i> Recorded Payment History
                                </h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm text-center mb-0" style="font-size: 13px;">
                                        <thead class="bg-light font-weight-bold">
                                            <tr>
                                                <th style="width: 45px;">#</th>
                                                <th>Date</th>
                                                <th>Bank Account</th>
                                                <th>Amount (Rs.)</th>
                                                <th style="width: 60px;">Delete</th>
                                            </tr>
                                        </thead>
                                        <tbody id="modalPaymentsTableBody">
                                            <tr><td colspan="5" class="py-3 text-muted">Loading payments...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-secondary btn-sm font-weight-bold" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	var _currentRemainingBalance = 0.0;
	var _currentPurchaseId = 0;
	var _tableNeedsReload = false;

	$(document).ready(function() {
		$('#purchasesTable').DataTable({
			"order": [[ 0, "desc" ]]
		});

		$('#purchasePaymentModal').on('hidden.bs.modal', function () {
			if (_tableNeedsReload) {
				location.reload();
			}
		});
	});

	function openPaymentModal(purchaseId) {
		_currentPurchaseId = purchaseId;
		$('#modal_purchase_id').val(purchaseId);
		$('#modalAlertBox').html('');
		$('#modalPurchaseTitle').text('#' + purchaseId + ' (Loading...)');
		$('#modalTotalCost').text('Rs. ...');
		$('#modalTotalPaid').text('Rs. ...');
		$('#modalRemainingAmount').text('Rs. ...');
		$('#modalPaymentsTableBody').html('<tr><td colspan="5" class="py-3 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading payments...</td></tr>');
		$('#addPaymentSection').hide();
		$('#fullyPaidBanner').hide();
		$('#modal_payment_amount').val('');
		$('#overpaymentWarning').hide();
		$('#btnSubmitPayment').prop('disabled', false);

		$('#purchasePaymentModal').modal('show');
		loadPaymentModalData(purchaseId);
	}

	function loadPaymentModalData(purchaseId) {
		$.ajax({
			url: 'ajax-purchase-payment.php',
			type: 'GET',
			dataType: 'json',
			data: { action: 'get_history', purchase_id: purchaseId },
			success: function(res) {
				if (res.status === 'success') {
					_currentRemainingBalance = parseFloat(res.remaining_amount) || 0.0;

					var p = res.purchase;
					$('#modalPurchaseTitle').html('#' + p.id + ' - <strong>' + escapeHtml(p.item_name) + '</strong> (' + escapeHtml(p.invoice_number) + ')');
					$('#modalTotalCost').text('Rs. ' + res.total_cost_fmt);
					$('#modalTotalPaid').text('Rs. ' + res.total_paid_fmt);
					$('#modalRemainingAmount').text('Rs. ' + res.remaining_fmt);

					$('#modal_payment_amount').attr('max', _currentRemainingBalance.toFixed(2));

					if (_currentRemainingBalance > 0.001) {
						$('#addPaymentSection').show();
						$('#fullyPaidBanner').hide();
					} else {
						$('#addPaymentSection').hide();
						$('#fullyPaidBanner').show();
					}

					var payList = res.payments || [];
					if (payList.length === 0) {
						$('#modalPaymentsTableBody').html('<tr><td colspan="5" class="py-3 text-muted">No payments recorded yet for this purchase.</td></tr>');
					} else {
						var html = '';
						for (var i = 0; i < payList.length; i++) {
							var item = payList[i];
							var bankInfo = escapeHtml(item.bank_name);
							if (item.bank_account) {
								bankInfo += ' <br><small class="text-muted">' + escapeHtml(item.bank_account) + '</small>';
							}
							html += '<tr>' +
								'<td>' + (i + 1) + '</td>' +
								'<td class="font-weight-bold text-nowrap">' + item.date_fmt + '</td>' +
								'<td>' + bankInfo + '</td>' +
								'<td class="font-weight-bold text-success text-nowrap">Rs. ' + item.amount_fmt + '</td>' +
								'<td><button type="button" class="btn btn-sm btn-link text-danger p-0" title="Delete payment" onclick="deleteModalPayment(' + item.id + ')"><i class="fas fa-trash-alt"></i></button></td>' +
							'</tr>';
						}
						$('#modalPaymentsTableBody').html(html);
					}
				} else {
					$('#modalAlertBox').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> ' + (res.message || 'Error loading purchase.') + '</div>');
				}
			},
			error: function() {
				$('#modalAlertBox').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-1"></i> Failed to communicate with server.</div>');
			}
		});
	}

	function validateModalPaymentAmount(input) {
		var entered = parseFloat($(input).val()) || 0.0;
		if (entered > (_currentRemainingBalance + 0.0001)) {
			$('#overpaymentWarning').text('Cannot exceed remaining balance of Rs. ' + _currentRemainingBalance.toFixed(2) + '!').show();
			$('#btnSubmitPayment').prop('disabled', true);
		} else {
			$('#overpaymentWarning').hide();
			$('#btnSubmitPayment').prop('disabled', false);
		}
	}

	function fillExactRemaining() {
		if (_currentRemainingBalance > 0) {
			$('#modal_payment_amount').val(_currentRemainingBalance.toFixed(2));
			$('#overpaymentWarning').hide();
			$('#btnSubmitPayment').prop('disabled', false);
		}
	}

	function submitPurchasePayment(e) {
		e.preventDefault();
		var entered = parseFloat($('#modal_payment_amount').val()) || 0.0;
		if (entered <= 0) {
			alert('Please enter a valid positive payment amount.');
			return;
		}
		if (entered > (_currentRemainingBalance + 0.0001)) {
			alert('Payment amount cannot exceed the remaining balance of Rs. ' + _currentRemainingBalance.toFixed(2));
			return;
		}

		var formData = $('#purchasePaymentForm').serialize() + '&action=add_payment';
		$('#btnSubmitPayment').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

		$.ajax({
			url: 'ajax-purchase-payment.php',
			type: 'POST',
			dataType: 'json',
			data: formData,
			success: function(res) {
				$('#btnSubmitPayment').prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Payment');
				if (res.status === 'success') {
					_tableNeedsReload = true;
					$('#modalAlertBox').html('<div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle mr-1"></i> ' + res.message + '<button type="button" class="close py-2" data-dismiss="alert">&times;</button></div>');
					$('#modal_payment_amount').val('');
					loadPaymentModalData(_currentPurchaseId);
				} else {
					$('#modalAlertBox').html('<div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle mr-1"></i> ' + (res.message || 'Error saving payment.') + '</div>');
				}
			},
			error: function() {
				$('#btnSubmitPayment').prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Payment');
				$('#modalAlertBox').html('<div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle mr-1"></i> Server communication error.</div>');
			}
		});
	}

	function deleteModalPayment(payId) {
		if (confirm('Are you sure you want to remove this payment? The remaining balance and purchase status will be recalculated.')) {
			$.ajax({
				type: "POST",
				url: "../include/deletepurchasepayment.php",
				data: { id: payId },
				success: function (data) {
					if (data.trim() === 'deleted') {
						_tableNeedsReload = true;
						$('#modalAlertBox').html('<div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle mr-1"></i> Payment deleted successfully. Balance updated.<button type="button" class="close py-2" data-dismiss="alert">&times;</button></div>');
						loadPaymentModalData(_currentPurchaseId);
					} else {
						alert('Error deleting payment: ' + data);
					}
				},
				error: function (xhr, status, error) {
					alert('Server communication error: ' + error);
				}
			});
		}
	}

	function deletePurchase(id){
		if(confirm('Are you sure you want to delete this purchase record?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletepurchase.php",
				data: {id: id},
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}

	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}
	</script>
</html>
