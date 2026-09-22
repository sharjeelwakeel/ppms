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
            font-size: 13px;
        }
        #salesInvoicesTable td {
            vertical-align: middle;
            font-size: 13px;
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
                                <th style="width: 40px;">#</th>
                                <th style="width: 150px;">Invoice / Slip #</th>
                                <th style="width: 90px;">Date</th>
                                <th style="width: 85px;">Shift</th>
                                <th style="text-align: left; min-width: 180px;">Customer / Vehicle / Remarks</th>
                                <th style="width: 120px;">Payment / Type</th>
                                <th style="width: 80px;">Products</th>
                                <th style="width: 100px;">Total Qty</th>
                                <th style="width: 130px;">Amount / Charge</th>
                                <th style="width: 80px;">Receipt</th>
                                <?php if ($canDelete): ?>
                                <th style="width: 50px;">Delete</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Unified query: Master Invoices + any legacy unlinked sales
                            $sql = "
                                SELECT inv.id, inv.invoice_no, inv.slip_no, inv.date, inv.shift_id, inv.slip_date, inv.payment_type, inv.slip_type,
                                       inv.customer_id, inv.vehicle_number, inv.details, inv.ref_slip_no, inv.ref_slip_date,
                                       inv.temp_slip_id, inv.temp_wasoli_amount, inv.is_returned,
                                       inv.total_items, inv.total_quantity, inv.total_amount, inv.charge_amount, inv.payment_status,
                                       cm.name AS card_machine_name, b.name AS bank_name, c.name AS customer_name,
                                       sh.name AS shift_name,
                                       (SELECT COALESCE(SUM(GREATEST(0, sal.balance_quantity - COALESCE((SELECT SUM(bsal.quantity) FROM tbl_lubricant_sales bsal JOIN tbl_lubricant_sale_invoices binv ON (bsal.invoice_id = binv.id) WHERE binv.slip_type = 'Balanced Slip' AND (binv.ref_slip_no = inv.slip_no OR binv.ref_slip_no = inv.invoice_no) AND bsal.product_id = sal.product_id AND (binv.deleted_at IS NULL OR binv.deleted_at = '0000-00-00 00:00:00') AND (bsal.deleted_at IS NULL OR bsal.deleted_at = '0000-00-00 00:00:00')), 0))), 0) FROM tbl_lubricant_sales sal WHERE sal.invoice_id = inv.id AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')) AS pending_balance,
                                       1 AS is_invoice
                                FROM tbl_lubricant_sale_invoices inv 
                                LEFT JOIN tbl_card_machines cm ON inv.card_machine_id = cm.id
                                LEFT JOIN tbl_banks b ON inv.bank_id = b.id
                                LEFT JOIN tbl_customers c ON inv.customer_id = c.id
                                LEFT JOIN tbl_shifts sh ON inv.shift_id = sh.id
                                WHERE (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
                                UNION ALL
                                SELECT sal.id, CONCAT('SALE-#', sal.id) AS invoice_no, '' AS slip_no, sal.date, sal.shift_id, sal.date AS slip_date,
                                       sal.payment_type, 'Permanent Slip' AS slip_type,
                                       NULL AS customer_id, '' AS vehicle_number, sal.details, NULL AS ref_slip_no, NULL AS ref_slip_date,
                                       NULL AS temp_slip_id, 0.00 AS temp_wasoli_amount, 0 AS is_returned,
                                       1 AS total_items, sal.quantity AS total_quantity, sal.amount AS total_amount, sal.amount AS charge_amount, 'Paid' AS payment_status,
                                       NULL AS card_machine_name, NULL AS bank_name, NULL AS customer_name,
                                       sh_sal.name AS shift_name,
                                       0 AS pending_balance,
                                       0 AS is_invoice
                                FROM tbl_lubricant_sales sal 
                                LEFT JOIN tbl_shifts sh_sal ON sal.shift_id = sh_sal.id
                                WHERE sal.invoice_id IS NULL AND (sal.deleted_at IS NULL OR sal.deleted_at = '0000-00-00 00:00:00')
                                ORDER BY date DESC, id DESC
                            ";
                            $result = mysqli_query($connection, $sql);
                            if($result && mysqli_num_rows($result) > 0){
                                $counter = 1;
                                while($row = mysqli_fetch_assoc($result)){
                                    $paymentType = $row['payment_type'];
                                    $slipType    = $row['slip_type'] ?? 'Permanent Slip';

                                    // Payment badge rendering
                                    if ($paymentType === 'Card') {
                                        $paymentHtml = '<span class="badge badge-info px-2 py-1"><i class="fas fa-credit-card mr-1"></i>Card</span>';
                                        if (!empty($row['card_machine_name'])) {
                                            $paymentHtml .= '<div class="small text-muted mt-1" style="font-size: 11px;"><i class="fas fa-calculator mr-1"></i>' . htmlspecialchars($row['card_machine_name']) . '</div>';
                                        }
                                        if (!empty($row['bank_name'])) {
                                            $paymentHtml .= '<div class="small text-muted" style="font-size: 11px;"><i class="fas fa-university mr-1"></i>' . htmlspecialchars($row['bank_name']) . '</div>';
                                        }
                                    } elseif ($paymentType === 'Credit') {
                                        if ($slipType === 'Balanced Slip') {
                                            $paymentHtml = '<span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-history mr-1"></i>Balanced Slip</span>';
                                            if (!empty($row['ref_slip_no'])) {
                                                $paymentHtml .= '<div class="small text-muted mt-1" style="font-size: 11px;">Ref: #' . htmlspecialchars($row['ref_slip_no']) . '</div>';
                                            }
                                        } elseif ($slipType === 'Temporary Slip') {
                                            $paymentHtml = '<span class="badge badge-secondary px-2 py-1"><i class="fas fa-hand-holding mr-1"></i>Temp Loan</span>';
                                            if (!empty($row['is_returned'])) {
                                                $paymentHtml .= '<div class="small text-success mt-1" style="font-size: 11px;"><i class="fas fa-check-circle mr-1"></i>Settled</div>';
                                            } else {
                                                $paymentHtml .= '<div class="small text-danger mt-1 font-weight-bold" style="font-size: 11px;"><i class="fas fa-clock mr-1"></i>Open Chit</div>';
                                            }
                                        } else {
                                            $paymentHtml = '<span class="badge badge-primary px-2 py-1"><i class="fas fa-file-invoice mr-1"></i>Credit Voucher</span>';
                                            if (!empty($row['temp_slip_id'])) {
                                                $paymentHtml .= '<div class="small text-info mt-1" style="font-size: 11px;"><i class="fas fa-link mr-1"></i>Temp Receive</div>';
                                            }
                                        }
                                    } else {
                                        $paymentHtml = '<span class="badge badge-success px-2 py-1"><i class="fas fa-money-bill-wave mr-1"></i>Cash</span>';
                                    }

                                    $isInv = intval($row['is_invoice']);
                                    $editUrl = $isInv ? "edit-sale.php?invoice_id=".$row['id'] : "edit-sale.php?id=".$row['id'];
                                    $invNoSafe = htmlspecialchars($row['invoice_no']);
                                    $invoiceDisplay = $canEdit
                                        ? '<a href="' . $editUrl . '" class="font-weight-bold" style="color: var(--primary-color); text-decoration: underline;" title="Edit Sale Invoice">' . $invNoSafe . '</a>'
                                        : '<span class="font-weight-bold" style="color: var(--primary-color);">' . $invNoSafe . '</span>';

                                    if (!empty($row['slip_no'])) {
                                        $invoiceDisplay .= '<div class="small text-muted font-italic mt-1"><i class="fas fa-tag mr-1 text-primary"></i>Slip: <strong>' . htmlspecialchars($row['slip_no']) . '</strong></div>';
                                    }

                                    // Customer / Vehicle / Remarks column
                                    $custVehHtml = '';
                                    if (!empty($row['customer_name'])) {
                                        $custVehHtml .= '<div class="font-weight-bold text-dark"><i class="fas fa-user mr-1 text-primary"></i>' . htmlspecialchars($row['customer_name']) . '</div>';
                                    }
                                    if (!empty($row['vehicle_number'])) {
                                        $custVehHtml .= '<div class="small text-muted"><i class="fas fa-truck mr-1"></i>' . htmlspecialchars($row['vehicle_number']) . '</div>';
                                    }
                                    if (!empty($row['details'])) {
                                        $custVehHtml .= '<div class="small text-secondary font-italic">' . htmlspecialchars($row['details']) . '</div>';
                                    }
                                    if (empty($custVehHtml)) {
                                        $custVehHtml = '<span class="text-muted">—</span>';
                                    }

                                    // Quantity & pending balance
                                    $qtyDisplay = '<span class="font-weight-bold text-primary">' . number_format($row['total_quantity'], 0) . '</span>';
                                    if (intval($row['pending_balance']) > 0) {
                                        $qtyDisplay .= '<div class="small text-warning font-weight-bold mt-1" title="Uncollected balance pending"><i class="fas fa-clock mr-1"></i>' . intval($row['pending_balance']) . ' pending</div>';
                                    }

                                    // Amount / Charge display
                                    if ($paymentType === 'Credit') {
                                        if ($slipType === 'Balanced Slip') {
                                            $amtDisplay = '<div class="text-muted small">Orig: Rs. ' . number_format($row['total_amount'], 2) . '</div><div class="font-weight-bold text-info" style="font-size: 13px;">Charge: Rs. 0.00</div>';
                                        } elseif ($slipType === 'Temporary Slip') {
                                            $amtDisplay = '<div class="text-muted small">Loan: Rs. ' . number_format($row['total_amount'], 2) . '</div><div class="font-weight-bold text-secondary" style="font-size: 13px;">Charge: Rs. 0.00</div>';
                                        } else {
                                            $amtDisplay = '<div class="font-weight-bold text-success" style="font-size: 13px;">Rs. ' . number_format(floatval($row['charge_amount']), 2) . '</div>';
                                            if (floatval($row['temp_wasoli_amount']) > 0) {
                                                $amtDisplay .= '<div class="small text-muted" style="font-size: 11px;">incl. Temp Receive: Rs. ' . number_format(floatval($row['temp_wasoli_amount']), 2) . '</div>';
                                            }
                                        }
                                    } else {
                                        $amtDisplay = '<div class="font-weight-bold text-success" style="font-size: 13px;">Rs. ' . number_format($row['total_amount'], 2) . '</div>';
                                    }

                                    echo '<tr>
                                            <td>' . $counter++ . '</td>
                                            <td>' . $invoiceDisplay . '</td>
                                            <td class="text-nowrap">' . date("d-m-Y", strtotime($row['date'])) . '</td>
                                            <td class="text-nowrap"><span class="badge badge-info px-2 py-1"><i class="fas fa-clock mr-1"></i>' . htmlspecialchars($row['shift_name'] ?? 'General') . '</span></td>
                                            <td class="text-left">' . $custVehHtml . '</td>
                                            <td>' . $paymentHtml . '</td>
                                            <td><span class="badge badge-light border text-dark font-weight-bold"><i class="fas fa-boxes mr-1 text-primary"></i>' . number_format($row['total_items'], 0) . '</span></td>
                                            <td>' . $qtyDisplay . '</td>
                                            <td>' . $amtDisplay . '</td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-info font-weight-bold py-1 px-2" style="font-size: 12px;" onclick="viewInvoiceModal(' . $row['id'] . ', ' . $isInv . ')" title="View Receipt Breakdown">
                                                    <i class="fas fa-receipt mr-1"></i> Receipt
                                                </button>
                                            </td>';
                                    if ($canDelete) {
                                        echo '<td class="text-center">
                                                <a class="btn btn-large btn-link p-0 text-danger" onclick="deleteSaleInvoice(' . $row['id'] . ', ' . $isInv . ')" title="Delete Sale">
                                                    <i class="fas fa-trash-alt" style="font-size: 16px;"></i>
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
                                    <div id="receiptCustomerInfoRow" class="d-none">
                                        <strong>Customer:</strong> <span id="receiptCustomerName" class="font-weight-bold text-dark">—</span><br>
                                        <strong>Vehicle #:</strong> <span id="receiptVehicleNumber" class="font-weight-bold text-dark">—</span><br>
                                        <strong>Slip #:</strong> <span id="receiptSlipNumber" class="font-weight-bold text-primary">—</span><br>
                                    </div>
                                    <strong>Date:</strong> <span id="receiptDateText">—</span><br>
                                    <strong>Shift:</strong> <span id="receiptShiftText" class="font-weight-bold text-dark">—</span><br>
                                    <strong>Remarks:</strong> <span id="receiptCustomerText">—</span>
                                </div>
                                <div class="col-sm-6 text-sm-right">
                                    <strong>Slip Classification:</strong> <span id="receiptSlipTypeBadge" class="font-weight-bold text-dark">Standard Sale</span><br>
                                    <strong>Total Items:</strong> <span id="receiptItemCountText">0</span><br>
                                    <strong>Total Quantity:</strong> <span id="receiptTotalUnitsText">0</span> units
                                </div>
                            </div>
                        </div>

                        <!-- Itemized Products Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 13px;">
                                <thead class="bg-light font-weight-bold">
                                    <tr>
                                        <th style="width: 35px;">#</th>
                                        <th style="text-align: left;">Product Description</th>
                                        <th style="width: 80px;">Quota</th>
                                        <th style="width: 80px;">Issued</th>
                                        <th style="width: 80px;">Balance</th>
                                        <th style="width: 110px;">Unit Rate</th>
                                        <th style="width: 120px;">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody id="receiptItemsTbody">
                                    <tr><td colspan="7" class="py-3 text-muted">Loading invoice items...</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="bg-light font-weight-bold" style="font-size: 14px;">
                                        <td colspan="2" class="text-right align-middle">Overall Totals:</td>
                                        <td class="align-middle text-secondary" id="receiptFooterQuota">0</td>
                                        <td class="align-middle text-primary" id="receiptFooterQty">0</td>
                                        <td class="align-middle text-warning" id="receiptFooterBal">0</td>
                                        <td class="text-right align-middle text-muted small">Voucher Value:</td>
                                        <td class="text-right align-middle text-dark font-weight-bold" id="receiptFooterGrandTotal">Rs. 0.00</td>
                                    </tr>
                                    <tr class="table-totals-row font-weight-bold" style="font-size: 15px; background: #e8eaf6;">
                                        <td colspan="6" class="text-right align-middle text-primary">Customer Receivable (Charge Amount):</td>
                                        <td class="text-right align-middle text-success font-weight-bold" id="receiptFooterChargeAmount">Rs. 0.00</td>
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
        $('#receiptItemsTbody').html('<tr><td colspan="7" class="py-3 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading details...</td></tr>');
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
                    $('#receiptShiftText').text(inv.shift_name || 'General');
                    $('#receiptCustomerText').text(inv.details ? inv.details : '—');
                    $('#receiptItemCountText').text(inv.total_items);
                    $('#receiptTotalUnitsText').text(inv.total_quantity);

                    if (inv.customer_name) {
                        $('#receiptCustomerInfoRow').removeClass('d-none');
                        $('#receiptCustomerName').text(inv.customer_name);
                        $('#receiptVehicleNumber').text(inv.vehicle_number || '—');
                        $('#receiptSlipNumber').text(inv.slip_no || '—');
                    } else {
                        $('#receiptCustomerInfoRow').addClass('d-none');
                    }

                    $('#receiptSlipTypeBadge').text(inv.slip_type || 'Standard Sale');

                    if (inv.payment_type === 'Card') {
                        $('#receiptPaymentBadge').removeClass('badge-success badge-primary').addClass('badge-info').html('<i class="fas fa-credit-card mr-1"></i>Card');
                        var cardBankParts = [];
                        if (inv.card_machine_name) cardBankParts.push('POS: ' + inv.card_machine_name);
                        if (inv.bank_name) cardBankParts.push('Bank: ' + inv.bank_name + (inv.bank_account ? ' (' + inv.bank_account + ')' : ''));
                        if (cardBankParts.length > 0) {
                            $('#receiptCardBankInfo').removeClass('d-none').text(cardBankParts.join(' | '));
                        } else {
                            $('#receiptCardBankInfo').addClass('d-none');
                        }
                    } else if (inv.payment_type === 'Credit') {
                        $('#receiptPaymentBadge').removeClass('badge-success badge-info').addClass('badge-primary').html('<i class="fas fa-file-invoice mr-1"></i>Credit');
                        $('#receiptCardBankInfo').addClass('d-none');
                    } else {
                        $('#receiptPaymentBadge').removeClass('badge-info badge-primary').addClass('badge-success').html('<i class="fas fa-money-bill-wave mr-1"></i>Cash');
                        $('#receiptCardBankInfo').addClass('d-none');
                    }

                    var tbodyHtml = '';
                    var calcQuota = 0;
                    var calcIssued = 0;
                    var calcBal = 0;
                    var calcAmt = 0;

                    for (var i = 0; i < items.length; i++) {
                        var itm = items[i];
                        var qVal = parseInt(itm.quantity, 10) || 0;
                        var issVal = parseInt(itm.issue_quantity, 10) || qVal;
                        var balVal = parseInt(itm.balance_quantity, 10) || Math.max(0, qVal - issVal);
                        var aVal = parseFloat(itm.amount) || 0;

                        calcQuota += qVal;
                        calcIssued += issVal;
                        calcBal += balVal;
                        calcAmt += aVal;

                        tbodyHtml += '<tr>' +
                            '<td>' + (i + 1) + '</td>' +
                            '<td class="text-left font-weight-bold">' + itm.product_name + '</td>' +
                            '<td class="text-secondary">' + qVal + '</td>' +
                            '<td class="font-weight-bold text-primary">' + issVal + '</td>' +
                            '<td>' + (balVal > 0 ? '<span class="badge badge-warning">' + balVal + '</span>' : '<span class="text-muted">0</span>') + '</td>' +
                            '<td class="text-right">Rs. ' + parseFloat(itm.rate).toFixed(2) + '</td>' +
                            '<td class="text-right font-weight-bold text-dark">Rs. ' + aVal.toFixed(2) + '</td>' +
                        '</tr>';
                    }

                    if (items.length === 0) {
                        tbodyHtml = '<tr><td colspan="7" class="py-3 text-muted">No product items found for this invoice.</td></tr>';
                    }

                    $('#receiptItemsTbody').html(tbodyHtml);
                    $('#receiptFooterQuota').text(calcQuota);
                    $('#receiptFooterQty').text(calcIssued);
                    $('#receiptFooterBal').text(calcBal);
                    $('#receiptFooterGrandTotal').text('Rs. ' + parseFloat(inv.total_amount || calcAmt).toFixed(2));
                    $('#receiptFooterChargeAmount').text('Rs. ' + parseFloat(inv.charge_amount || 0).toFixed(2));
                } else {
                    $('#receiptItemsTbody').html('<tr><td colspan="7" class="py-3 text-danger">' + (res.message || 'Error loading invoice') + '</td></tr>');
                }
            },
            error: function() {
                $('#receiptItemsTbody').html('<tr><td colspan="7" class="py-3 text-danger">Error connecting to server.</td></tr>');
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
					alert('Error communicating with server.');
				}
			});
		}
	}
    </script>
</html>
