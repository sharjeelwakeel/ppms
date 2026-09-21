<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing lubricant sales
check_access('items', 'show');

// Self-healing migration for tbl_lubricant_sale_invoices & tbl_lubricant_sales
mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_lubricant_sale_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(64) NOT NULL,
  `date` DATE NOT NULL,
  `payment_type` VARCHAR(32) NOT NULL DEFAULT 'Cash',
  `details` TEXT DEFAULT NULL,
  `total_items` INT(11) NOT NULL DEFAULT 0,
  `total_quantity` INT(11) NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_no` (`invoice_no`),
  KEY `idx_date` (`date`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

$chk_inv_id = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sales LIKE 'invoice_id'");
if ($chk_inv_id && mysqli_num_rows($chk_inv_id) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sales ADD COLUMN invoice_id INT(11) DEFAULT NULL AFTER id, ADD KEY `idx_invoice_id` (`invoice_id`)");
}
$chk_inv_no = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_sales LIKE 'invoice_no'");
if ($chk_inv_no && mysqli_num_rows($chk_inv_no) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_sales ADD COLUMN invoice_no VARCHAR(64) DEFAULT NULL AFTER invoice_id, ADD KEY `idx_invoice_no` (`invoice_no`)");
}

$canAdd    = has_permission('items', 'add');
$canEdit   = has_permission('items', 'edit');
$canDelete = has_permission('items', 'delete');
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
		.m-top{ margin-top:20px; }
		.m-bot{ margin-bottom:20px; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        #salesInvoicesTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            vertical-align: middle;
        }
        @media print {
            body * { visibility: hidden; }
            #printableReceiptArea, #printableReceiptArea * { visibility: visible; }
            #printableReceiptArea { position: absolute; left: 0; top: 0; width: 100%; }
        }
		</style>
		<title>PPMS - Stock Sales &amp; Invoices</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container-fluid px-4 pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-shopping-bag mr-2 text-primary"></i>Product Sales &amp; Invoices</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-sale.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Sale</a>
                        <?php endif; ?>
					</div>
				</div>

                <div class="table-responsive">
                    <table id="salesInvoicesTable" class="table table-striped table-bordered text-center mb-0">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 160px;">Invoice #</th>
                                <th style="width: 100px;">Date</th>
                                <th style="text-align: left;">Remarks</th>
                                <th style="width: 100px;">Payment</th>
                                <th style="width: 110px;">Products</th>
                                <th style="width: 110px;">Total Qty</th>
                                <th style="width: 150px;">Grand Total</th>
                                <th style="width: 100px;">Receipt</th>
                                <?php if ($canDelete): ?>
                                <th style="width: 60px;">Delete</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Unified query: Master Invoices + any legacy unlinked sales
                            $sql = "
                                SELECT inv.id, inv.invoice_no, inv.date, inv.payment_type, inv.details, 
                                       inv.total_items, inv.total_quantity, inv.total_amount, 
                                       cm.name AS card_machine_name, b.name AS bank_name, 1 AS is_invoice
                                FROM tbl_lubricant_sale_invoices inv 
                                LEFT JOIN tbl_card_machines cm ON inv.card_machine_id = cm.id
                                LEFT JOIN tbl_banks b ON inv.bank_id = b.id
                                WHERE (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
                                UNION ALL
                                SELECT sal.id, CONCAT('SALE-#', sal.id) AS invoice_no, sal.date, sal.payment_type, sal.details, 
                                       1 AS total_items, sal.quantity AS total_quantity, sal.amount AS total_amount, 
                                       NULL AS card_machine_name, NULL AS bank_name, 0 AS is_invoice
                                FROM tbl_lubricant_sales sal 
                                WHERE sal.invoice_id IS NULL AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')
                                ORDER BY date DESC, id DESC
                            ";
                            $result = mysqli_query($connection, $sql);
                            if($result && mysqli_num_rows($result) > 0){
                                $counter = 1;
                                while($row = mysqli_fetch_assoc($result)){
                                    $isCard = ($row['payment_type'] === 'Card');
                                    $paymentHtml = $isCard 
                                        ? '<span class="badge badge-info px-2 py-1"><i class="fas fa-credit-card mr-1"></i>Card</span>'
                                        : '<span class="badge badge-success px-2 py-1"><i class="fas fa-money-bill-wave mr-1"></i>Cash</span>';
                                    
                                    if ($isCard) {
                                        if (!empty($row['card_machine_name'])) {
                                            $paymentHtml .= '<div class="small text-muted mt-1" style="font-size: 11px;"><i class="fas fa-calculator mr-1"></i>' . htmlspecialchars($row['card_machine_name']) . '</div>';
                                        }
                                        if (!empty($row['bank_name'])) {
                                            $paymentHtml .= '<div class="small text-muted" style="font-size: 11px;"><i class="fas fa-university mr-1"></i>' . htmlspecialchars($row['bank_name']) . '</div>';
                                        }
                                    }

                                    $isInv = intval($row['is_invoice']);
                                    $editUrl = $isInv ? "edit-sale.php?invoice_id=".$row['id'] : "edit-sale.php?id=".$row['id'];
                                    $invNoSafe = htmlspecialchars($row['invoice_no']);
                                    $invoiceDisplay = $canEdit
                                        ? '<a href="' . $editUrl . '" class="font-weight-bold" style="color: var(--primary-color); text-decoration: underline;" title="Edit Sale Invoice">' . $invNoSafe . '</a>'
                                        : '<span class="font-weight-bold" style="color: var(--primary-color);">' . $invNoSafe . '</span>';

                                    echo '<tr>
                                            <td>' . $counter++ . '</td>
                                            <td>' . $invoiceDisplay . '</td>
                                            <td class="text-nowrap">' . date("d-m-Y", strtotime($row['date'])) . '</td>
                                            <td class="text-left">' . htmlspecialchars($row['details'] ?? '—') . '</td>
                                            <td>' . $paymentHtml . '</td>
                                            <td><span class="badge badge-light border text-dark font-weight-bold"><i class="fas fa-boxes mr-1 text-primary"></i>' . number_format($row['total_items'], 0) . '</span></td>
                                            <td class="font-weight-bold text-primary">' . number_format($row['total_quantity'], 0) . '</td>
                                            <td class="font-weight-bold text-success">Rs. ' . number_format($row['total_amount'], 2) . '</td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-info font-weight-bold" onclick="viewInvoiceModal(' . $row['id'] . ', ' . $isInv . ')" title="View Receipt Breakdown">
                                                    <i class="fas fa-receipt mr-1"></i> Receipt
                                                </button>
                                            </td>';
                                    if ($canDelete) {
                                        echo '<td class="text-center">
                                                <a class="btn btn-large btn-link p-0 text-danger" onclick="deleteSaleInvoice(' . $row['id'] . ', ' . $isInv . ')" title="Delete Sale">
                                                    <i class="fas fa-trash-alt" style="font-size: 18px;"></i>
                                                </a>
                                              </td>';
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

        <!-- Modal: View Invoice Receipt Breakdown -->
        <div class="modal fade" id="viewInvoiceModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow" style="border-radius: 10px; overflow: hidden;">
                    <div class="modal-header text-white" style="background: var(--primary-gradient);">
                        <h5 class="modal-title font-weight-bold">
                            <i class="fas fa-receipt mr-2 text-warning"></i> Sale Invoice Receipt: <span id="modalReceiptInvNo"></span>
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4" id="printableReceiptArea">
                        <!-- Invoice Header Details -->
                        <div class="border-bottom pb-3 mb-3">
                            <div class="row">
                                <div class="col-sm-6">
                                    <h5 class="font-weight-bold text-dark mb-1" id="receiptShopTitle">PPMS - Lubricants &amp; Products</h5>
                                    <p class="text-muted small mb-0">Official Product Sales Voucher</p>
                                </div>
                                <div class="col-sm-6 text-sm-right mt-2 mt-sm-0">
                                    <span class="badge badge-success px-3 py-1 font-weight-bold" id="receiptPaymentBadge">Cash</span>
                                    <div id="receiptCardBankInfo" class="small text-muted mt-1 d-none"></div>
                                    <h6 class="font-weight-bold text-primary mt-2 mb-0" id="receiptInvNoText"></h6>
                                </div>
                            </div>
                            <div class="row mt-3 text-muted small">
                                <div class="col-sm-6">
                                    <strong>Date:</strong> <span id="receiptDateText">—</span><br>
                                    <strong>Remarks:</strong> <span id="receiptCustomerText">—</span>
                                </div>
                                <div class="col-sm-6 text-sm-right">
                                    <strong>Total Items:</strong> <span id="receiptItemCountText">0</span><br>
                                    <strong>Total Quantity:</strong> <span id="receiptTotalUnitsText">0</span> units
                                </div>
                            </div>
                        </div>

                        <!-- Itemized Products Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 14px;">
                                <thead class="bg-light font-weight-bold">
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th style="text-align: left;">Product Description</th>
                                        <th style="width: 100px;">Quantity</th>
                                        <th style="width: 120px;">Unit Rate</th>
                                        <th style="width: 140px;">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody id="receiptItemsTbody">
                                    <tr><td colspan="5" class="py-3 text-muted">Loading invoice items...</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-light font-weight-bold" style="font-size: 15px;">
                                        <td colspan="2" class="text-right align-middle">Overall Totals:</td>
                                        <td class="align-middle text-primary" id="receiptFooterQty">0</td>
                                        <td class="text-right align-middle text-muted small">Grand Total:</td>
                                        <td class="text-right align-middle text-success font-weight-bold" id="receiptFooterGrandTotal">Rs. 0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
                        <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" onclick="window.print()">
                            <i class="fas fa-print mr-1"></i> Print Receipt
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
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
	$(document).ready(function() {
		$('#salesInvoicesTable').DataTable({
			"order": [[ 0, "asc" ]]
		});
	});

    function viewInvoiceModal(id, isInvoice) {
        $('#modalReceiptInvNo').text('Loading...');
        $('#receiptItemsTbody').html('<tr><td colspan="5" class="py-3 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading details...</td></tr>');
        $('#viewInvoiceModal').modal('show');

        var params = isInvoice ? { invoice_id: id } : { id: id };

        $.ajax({
            url: 'ajax-get-invoice-details.php',
            type: 'GET',
            dataType: 'json',
            data: params,
            success: function(res) {
                if (res.status === 'success') {
                    var inv = res.invoice;
                    var items = res.items || [];

                    $('#modalReceiptInvNo').text(inv.invoice_no);
                    $('#receiptInvNoText').text(inv.invoice_no);
                    $('#receiptDateText').text(inv.date);
                    $('#receiptCustomerText').text(inv.details ? inv.details : '—');
                    $('#receiptItemCountText').text(inv.total_items);
                    $('#receiptTotalUnitsText').text(inv.total_quantity);

                    if (inv.payment_type === 'Card') {
                        $('#receiptPaymentBadge').removeClass('badge-success').addClass('badge-info').html('<i class="fas fa-credit-card mr-1"></i>Card');
                        var cardBankParts = [];
                        if (inv.card_machine_name) cardBankParts.push('POS: ' + inv.card_machine_name);
                        if (inv.bank_name) cardBankParts.push('Bank: ' + inv.bank_name + (inv.bank_account ? ' (' + inv.bank_account + ')' : ''));
                        if (cardBankParts.length > 0) {
                            $('#receiptCardBankInfo').removeClass('d-none').text(cardBankParts.join(' | '));
                        } else {
                            $('#receiptCardBankInfo').addClass('d-none');
                        }
                    } else {
                        $('#receiptPaymentBadge').removeClass('badge-info').addClass('badge-success').html('<i class="fas fa-money-bill-wave mr-1"></i>Cash');
                        $('#receiptCardBankInfo').addClass('d-none');
                    }

                    var tbodyHtml = '';
                    var calcQty = 0;
                    var calcAmt = 0;

                    for (var i = 0; i < items.length; i++) {
                        var itm = items[i];
                        calcQty += parseInt(itm.quantity, 10) || 0;
                        calcAmt += parseFloat(itm.amount) || 0;

                        tbodyHtml += '<tr>' +
                            '<td>' + (i + 1) + '</td>' +
                            '<td class="text-left font-weight-bold">' + itm.product_name + '</td>' +
                            '<td class="font-weight-bold text-primary">' + itm.quantity + '</td>' +
                            '<td class="text-right">Rs. ' + parseFloat(itm.rate).toFixed(2) + '</td>' +
                            '<td class="text-right font-weight-bold text-dark">Rs. ' + parseFloat(itm.amount).toFixed(2) + '</td>' +
                        '</tr>';
                    }

                    if (items.length === 0) {
                        tbodyHtml = '<tr><td colspan="5" class="py-3 text-muted">No product items found for this invoice.</td></tr>';
                    }

                    $('#receiptItemsTbody').html(tbodyHtml);
                    $('#receiptFooterQty').text(inv.total_quantity || calcQty);
                    $('#receiptFooterGrandTotal').text('Rs. ' + parseFloat(inv.total_amount || calcAmt).toFixed(2));
                } else {
                    $('#receiptItemsTbody').html('<tr><td colspan="5" class="py-3 text-danger">' + (res.message || 'Error loading invoice') + '</td></tr>');
                }
            },
            error: function() {
                $('#receiptItemsTbody').html('<tr><td colspan="5" class="py-3 text-danger">Error connecting to server.</td></tr>');
            }
        });
    }

	function deleteSaleInvoice(id, isInvoice){
		if(confirm('Are you sure you want to delete this sale invoice and its product records?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletelubricantsale.php",
				data: isInvoice ? { invoice_id: id } : { id: id },
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					alert('Error deleting sale.');
				}
			});
		}
	}
	</script>
</html>
