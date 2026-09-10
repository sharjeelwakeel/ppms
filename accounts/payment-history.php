<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// Fetch all active payments
$sql_payments = "SELECT p.*, 
                        c.name AS customer_name,
                        c.phone AS customer_phone,
                        b.name AS bank_name,
                        b.account_number AS bank_account_no,
                        sh.name AS shift_name
                 FROM tbl_customer_payments p
                 LEFT JOIN tbl_customers c ON (p.customer_id = c.id)
                 LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
                 LEFT JOIN tbl_shifts sh ON (p.filter_shift_id = sh.id)
                 WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                 ORDER BY p.payment_date DESC, p.id DESC";

$res_payments = mysqli_query($connection, $sql_payments);
$payments = [];
$total_collected = 0.00;

if ($res_payments) {
    while ($p = mysqli_fetch_assoc($res_payments)) {
        $payments[] = $p;
        $total_collected += floatval($p['total_amount']);
    }
}

// Preload allocations for quick modal viewing
$sql_alloc = "SELECT a.*, 
                     s.slip_no, 
                     s.slip_date, 
                     s.vehicle_number, 
                     s.charge_amount, 
                     s.paid_amount,
                     i.name AS item_name
              FROM tbl_customer_payment_allocations a
              LEFT JOIN tbl_meter_reading_credit_sales s ON (a.credit_sale_id = s.id)
              LEFT JOIN tbl_nozzles n ON (s.nozzle_id = n.id)
              LEFT JOIN tbl_items i ON (n.item_id = i.id)
              WHERE (a.deleted_at IS NULL OR a.deleted_at = '0000-00-00 00:00:00')
              ORDER BY a.id ASC";

$res_alloc = mysqli_query($connection, $sql_alloc);
$allocations_by_payment = [];
if ($res_alloc) {
    while ($al = mysqli_fetch_assoc($res_alloc)) {
        $pid = intval($al['payment_id']);
        if (!isset($allocations_by_payment[$pid])) {
            $allocations_by_payment[$pid] = [];
        }
        $allocations_by_payment[$pid][] = $al;
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.5">
    <title>PPMS - Payment Receipts &amp; History</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: var(--gradient-header);
            color: #fff;
            padding: 20px 28px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 18px rgba(4,32,78,0.18);
        }
        .data-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .data-card-header {
            background: var(--primary-gradient);
            color: #fff;
            padding: 12px 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .table thead th {
            background: #f1f5f9;
            color: #04204e;
            font-size: 11.5px;
            font-weight: 800;
            text-align: center;
            vertical-align: middle;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        .table tbody td {
            font-size: 12px;
            vertical-align: middle;
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../include/navbar.php'; ?>

    <div class="container-fluid px-lg-5 pt-4 pb-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4><i class="fas fa-history mr-2 text-warning"></i> Customer Payment Receipts &amp; Settlement History</h4>
                <small style="opacity:0.85;">Audit log of all payments collected from customers, payment modes, and slip settlement breakdowns.</small>
            </div>
            <div>
                <a href="credit-sale-receivables.php" class="btn btn-warning btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-hand-holding-usd mr-1"></i> Open Receivables Ledger
                </a>
            </div>
        </div>


        <!-- Data Card -->
        <div class="data-card">
            <div class="data-card-header">
                <span><i class="fas fa-receipt mr-2"></i> All Recorded Customer Payment Receipts</span>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover text-center mb-0" id="paymentsTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Receipt No</th>
                                <th>Payment Date</th>
                                <th>Customer Account</th>
                                <th>Amount Paid (Rs.)</th>
                                <th>Payment Mode</th>
                                <th>Bank / Cheque Details</th>
                                <th>Covered Period / Filter</th>
                                <th>Remarks</th>
                                <th style="width: 130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($payments as $p):
                                $pid = intval($p['id']);
                                $mode = $p['payment_mode'];
                                $cName = $p['customer_name'] ?: 'Account #' . $p['customer_id'];
                                $allocList = $allocations_by_payment[$pid] ?? [];
                                $allocCount = count($allocList);

                                // Filter context string
                                $filterContext = [];
                                if (!empty($p['filter_from_date']) || !empty($p['filter_to_date'])) {
                                    $filterContext[] = (!empty($p['filter_from_date']) ? date('d-m-Y', strtotime($p['filter_from_date'])) : 'Start') . ' to ' . (!empty($p['filter_to_date']) ? date('d-m-Y', strtotime($p['filter_to_date'])) : 'End');
                                }
                                if (!empty($p['filter_vehicle_number'])) {
                                    $filterContext[] = 'Veh: ' . htmlspecialchars($p['filter_vehicle_number']);
                                }
                                if (!empty($p['shift_name'])) {
                                    $filterContext[] = 'Shift: ' . htmlspecialchars($p['shift_name']);
                                }
                                $filterStr = !empty($filterContext) ? implode('<br>', $filterContext) : '<span class="text-muted">Account-Wide</span>';

                                // Mode badge & details
                                $modeBadge = '<span class="badge badge-success px-2 py-1"><i class="fas fa-money-bill-wave mr-1"></i> Cash</span>';
                                $bankDetails = '—';
                                if ($mode === 'Online Payment') {
                                    $modeBadge = '<span class="badge badge-primary px-2 py-1" style="background:#04204e;"><i class="fas fa-university mr-1"></i> Online</span>';
                                    $bankDetails = '<strong>' . htmlspecialchars($p['bank_name'] ?: 'Bank') . '</strong>';
                                    if (!empty($p['transaction_ref'])) {
                                        $bankDetails .= '<br><small class="text-muted">Ref: ' . htmlspecialchars($p['transaction_ref']) . '</small>';
                                    }
                                } elseif ($mode === 'Cheque') {
                                    $modeBadge = '<span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-money-check mr-1"></i> Cheque</span>';
                                    $bankDetails = '<strong>No: ' . htmlspecialchars($p['cheque_no'] ?: '') . '</strong>';
                                    if (!empty($p['cheque_date'])) {
                                        $bankDetails .= '<br><small class="text-muted">Date: ' . date('d-m-Y', strtotime($p['cheque_date'])) . '</small>';
                                    }
                                }
                            ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?php echo $counter++; ?></td>
                                <td class="font-weight-bold text-monospace text-primary">
                                    <?php echo htmlspecialchars($p['receipt_no']); ?>
                                </td>
                                <td class="text-nowrap font-weight-bold">
                                    <?php echo date('d-m-Y', strtotime($p['payment_date'])); ?>
                                </td>
                                <td class="text-left font-weight-bold">
                                    <?php echo htmlspecialchars($cName); ?>
                                    <small class="text-muted d-block font-weight-normal"><?php echo htmlspecialchars($p['customer_phone'] ?: ''); ?></small>
                                </td>
                                <td class="font-weight-bold text-success" style="font-size:13.5px;">
                                    Rs. <?php echo number_format($p['total_amount'], 2); ?>
                                </td>
                                <td><?php echo $modeBadge; ?></td>
                                <td><?php echo $bankDetails; ?></td>
                                <td class="small"><?php echo $filterStr; ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($p['remarks'] ?: '—'); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- View Settled Slips -->
                                        <button type="button" class="btn btn-info" onclick="viewSettledSlips(<?php echo $pid; ?>, '<?php echo addslashes($p['receipt_no']); ?>', '<?php echo addslashes($cName); ?>', <?php echo $p['total_amount']; ?>, '<?php echo $p['payment_date']; ?>')" title="View <?php echo $allocCount; ?> Settled Slips">
                                            <i class="fas fa-list"></i>
                                        </button>

                                        <!-- PDF Receipt -->
                                        <a href="generate-pdf-receipt.php?payment_id=<?php echo $pid; ?>" target="_blank" class="btn btn-secondary" style="background:#04204e; border-color:#04204e;" title="Print Official Payment Receipt">
                                            <i class="fas fa-file-pdf text-danger"></i>
                                        </a>

                                        <!-- Soft Delete / Void -->
                                        <?php if (has_permission('accounts', 'delete') || has_permission('credit_sales', 'delete')): ?>
                                        <button type="button" class="btn btn-danger" onclick="deletePayment(<?php echo $pid; ?>, '<?php echo addslashes($p['receipt_no']); ?>', <?php echo $p['total_amount']; ?>)" title="Soft-Delete Payment &amp; Rollback Balances">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Modal for Viewing Settled Slips -->
    <div class="modal fade" id="settledSlipsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius:12px; overflow:hidden;">
                <div class="modal-header text-white" style="background: var(--gradient-header);">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-receipt mr-2 text-warning"></i> Settled Slips for <span id="modalReceiptTitle"></span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 mb-3 rounded d-flex justify-content-between align-items-center" style="background:#f1f5f9;">
                        <div>
                            <span class="text-muted small text-uppercase font-weight-bold">Customer:</span>
                            <h6 class="font-weight-bold mb-0" id="modalCustName" style="color:#04204e;">—</h6>
                        </div>
                        <div class="text-right">
                            <span class="text-muted small text-uppercase font-weight-bold">Total Payment Amount:</span>
                            <h5 class="font-weight-bold text-success mb-0" id="modalPayAmt">Rs. 0.00</h5>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm table-striped text-center mb-0" style="font-size:12.5px;">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th>Slip No</th>
                                    <th>Slip Date</th>
                                    <th>Vehicle No</th>
                                    <th>Item / Fuel</th>
                                    <th>Slip Total (Rs.)</th>
                                    <th>Amount Settled (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody id="settledSlipsBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <a href="#" id="modalPdfBtn" target="_blank" class="btn btn-sm btn-secondary font-weight-bold" style="background:#04204e; border-color:#04204e;">
                        <i class="fas fa-file-pdf text-danger mr-1"></i> Print Receipt
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary font-weight-bold" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    var allAllocations = <?php echo json_encode($allocations_by_payment); ?>;

    $(document).ready(function() {
        $('#paymentsTable').DataTable({
            "order": [[ 2, "desc" ]],
            "pageLength": 25,
            "autoWidth": false,
            "language": {
                "emptyTable": "No payment receipts recorded yet."
            }
        });
    });

    function viewSettledSlips(paymentId, receiptNo, customerName, totalAmount, paymentDate) {
        $('#modalReceiptTitle').text(receiptNo);
        $('#modalCustName').text(customerName);
        $('#modalPayAmt').text('Rs. ' + parseFloat(totalAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        $('#modalPdfBtn').attr('href', 'generate-pdf-receipt.php?payment_id=' + paymentId);

        var list = allAllocations[paymentId] || [];
        var html = '';

        if (list.length === 0) {
            html = '<tr><td colspan="7" class="text-muted py-3">No specific slip allocations mapped to this payment.</td></tr>';
        } else {
            var sumAlloc = 0;
            for (var i = 0; i < list.length; i++) {
                var al = list[i];
                var cut = parseFloat(al.allocated_amount) || 0;
                var chg = parseFloat(al.charge_amount) || 0;
                sumAlloc += cut;

                html += '<tr>' +
                    '<td>' + (i + 1) + '</td>' +
                    '<td class="font-weight-bold text-monospace text-primary">' + (al.slip_no || '—') + '</td>' +
                    '<td>' + (al.slip_date || '—') + '</td>' +
                    '<td class="font-weight-bold text-monospace">' + (al.vehicle_number || '—') + '</td>' +
                    '<td>' + (al.item_name || 'Fuel') + '</td>' +
                    '<td>Rs. ' + chg.toFixed(2) + '</td>' +
                    '<td class="font-weight-bold text-success">Rs. ' + cut.toFixed(2) + '</td>' +
                '</tr>';
            }
            html += '<tr class="bg-light font-weight-bold">' +
                '<td colspan="6" class="text-right">TOTAL ALLOCATED:</td>' +
                '<td class="text-success">Rs. ' + sumAlloc.toFixed(2) + '</td>' +
            '</tr>';
        }

        $('#settledSlipsBody').html(html);
        $('#settledSlipsModal').modal('show');
    }

    function deletePayment(paymentId, receiptNo, amount) {
        Swal.fire({
            title: 'Delete Payment Receipt?',
            html: 'Are you sure you want to delete payment receipt <strong>' + receiptNo + '</strong> (Rs. ' + parseFloat(amount).toFixed(2) + ')?<br><br><span class="text-danger font-weight-bold"><i class="fas fa-exclamation-triangle mr-1"></i> This will soft-delete the payment and automatically ROLL BACK the balances on all settled slips!</span>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete &amp; Rollback'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: '../include/deletepayment.php',
                    type: 'POST',
                    data: { payment_id: paymentId },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.status === 'success') {
                            Swal.fire('Deleted!', resp.message, 'success').then(function() {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Unable to delete payment.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Server Error', 'Failed to communicate with server.', 'error');
                    }
                });
            }
        });
    }
    </script>
</body>
</html>
