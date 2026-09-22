<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// Fetch all active product payments
$sql_payments = "SELECT p.*, 
                        c.name AS customer_name,
                        c.phone AS customer_phone,
                        b.name AS bank_name,
                        b.account_number AS bank_account_no
                 FROM tbl_product_payments p
                 LEFT JOIN tbl_customers c ON (p.customer_id = c.id)
                 LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
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

// Preload allocations for modal viewing
$sql_alloc = "SELECT a.*, 
                     s.invoice_no,
                     s.slip_no, 
                     s.slip_date, 
                     s.vehicle_number, 
                     s.charge_amount, 
                     s.paid_amount,
                     (SELECT GROUP_CONCAT(CONCAT(p.name, ' (', ls.quantity, ' units)') SEPARATOR ', ')
                      FROM tbl_lubricant_sales ls
                      JOIN tbl_lubricant_products p ON ls.product_id = p.id
                      WHERE ls.invoice_id = s.id AND (ls.deleted_at IS NULL OR ls.deleted_at = '0000-00-00 00:00:00')
                     ) AS products_summary
              FROM tbl_product_payment_allocations a
              LEFT JOIN tbl_lubricant_sale_invoices s ON (a.invoice_id = s.id)
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.6">
    <title>PPMS - Product Payment Receipts &amp; History</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: var(--primary-gradient);
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
        .mode-badge {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 700;
            white-space: nowrap;
        }
        .mode-cash   { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .mode-online { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .mode-cheque { background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../include/navbar.php'; ?>

    <div class="container-fluid px-lg-5 pt-4 pb-5">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h4 class="mb-0 font-weight-bold"><i class="fas fa-history mr-2 text-warning"></i> Product Payment Receipts &amp; History</h4>
                <small style="opacity:0.85;">Comprehensive audit trail of customer payments collected against product credit sales.</small>
            </div>
            <div>
                <a href="product-receivables.php" class="btn btn-warning btn-sm font-weight-bold shadow-sm">
                    <i class="fas fa-hand-holding-usd mr-1"></i> Product Receivables Workspace
                </a>
            </div>
        </div>

        <!-- Total Collected Banner -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card border-0 shadow-sm rounded-lg" style="border-left: 5px solid #28a745 !important;">
                    <div class="card-body p-3">
                        <span class="text-muted small text-uppercase font-weight-bold d-block">Lifetime Product Collections</span>
                        <h4 class="font-weight-bold text-success mb-0">Rs. <?php echo number_format($total_collected, 2); ?></h4>
                        <small class="text-muted"><?php echo count($payments); ?> total receipt voucher(s)</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payments Table Card -->
        <div class="data-card">
            <div class="data-card-header">
                <div>
                    <i class="fas fa-receipt mr-2 text-warning"></i> Customer Product Payment Vouchers
                    <span class="badge badge-light text-primary ml-2 font-weight-bold"><?php echo count($payments); ?> Receipts</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped text-center mb-0" id="paymentsTable">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th>Receipt #</th>
                                <th>Receipt Date</th>
                                <th>Payment Date</th>
                                <th>Customer Account</th>
                                <th>Payment Mode</th>
                                <th>Bank / Reference / Cheque</th>
                                <th>Amount Paid</th>
                                <th>Settled Invoices</th>
                                <th>Remarks</th>
                                <th style="width:130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $idx = 0;
                            foreach ($payments as $p): 
                                $idx++;
                                $pId    = intval($p['id']);
                                $amt    = floatval($p['total_amount']);
                                $mode   = $p['payment_mode'];
                                $cName  = $p['customer_name'] ?? '—';
                                $allocs = $allocations_by_payment[$pId] ?? [];
                                $allocCount = count($allocs);
                            ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?php echo $idx; ?></td>
                                <td class="font-weight-bold text-primary text-monospace">
                                    <?php echo htmlspecialchars($p['receipt_no']); ?>
                                </td>
                                <td><?php echo !empty($p['receipt_date']) ? date('d-m-Y', strtotime($p['receipt_date'])) : '—'; ?></td>
                                <td class="font-weight-bold"><?php echo date('d-m-Y', strtotime($p['payment_date'])); ?></td>
                                <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($cName); ?></td>
                                <td>
                                    <?php if ($mode === 'Cash'): ?>
                                        <span class="mode-badge mode-cash"><i class="fas fa-money-bill-alt mr-1"></i> Cash</span>
                                    <?php elseif ($mode === 'Online Payment'): ?>
                                        <span class="mode-badge mode-online"><i class="fas fa-university mr-1"></i> Online / Bank</span>
                                    <?php else: ?>
                                        <span class="mode-badge mode-cheque"><i class="fas fa-money-check mr-1"></i> Cheque</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?php 
                                    if ($mode === 'Online Payment') {
                                        echo '<strong>' . htmlspecialchars($p['bank_name'] ?? 'Bank') . '</strong>';
                                        if (!empty($p['transaction_ref'])) {
                                            echo '<br><span class="text-monospace">Ref: ' . htmlspecialchars($p['transaction_ref']) . '</span>';
                                        }
                                    } elseif ($mode === 'Cheque') {
                                        echo '<strong>Chq #: ' . htmlspecialchars($p['cheque_no'] ?? '') . '</strong>';
                                        if (!empty($p['cheque_date'])) {
                                            echo '<br>Date: ' . date('d-m-Y', strtotime($p['cheque_date']));
                                        }
                                    } else {
                                        echo 'Direct Counter Cash';
                                    }
                                    ?>
                                </td>
                                <td class="font-weight-bold text-success" style="font-size:13.5px;">
                                    Rs. <?php echo number_format($amt, 2); ?>
                                </td>
                                <td>
                                    <span class="badge badge-info px-2 py-1" style="font-size:11.5px; cursor:pointer;" onclick="viewBreakdown(<?php echo $pId; ?>)">
                                        <i class="fas fa-list-ol mr-1"></i> <?php echo $allocCount; ?> invoice(s)
                                    </span>
                                </td>
                                <td class="small text-muted text-truncate" style="max-width:140px;">
                                    <?php echo htmlspecialchars($p['remarks'] ?: '—'); ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="generate-pdf-product-receipt.php?payment_id=<?php echo $pId; ?>" target="_blank" class="btn btn-xs btn-outline-primary py-1 px-2 font-weight-bold" title="Print PDF Receipt">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <button type="button" class="btn btn-xs btn-outline-info py-1 px-2 font-weight-bold" title="View Allocation Breakdown" onclick="viewBreakdown(<?php echo $pId; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (has_permission('accounts', 'delete') || has_permission('items', 'delete')): ?>
                                        <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2 font-weight-bold" title="Delete Payment &amp; Rollback Debt" onclick="confirmDelete(<?php echo $pId; ?>, '<?php echo htmlspecialchars($p['receipt_no']); ?>', <?php echo $amt; ?>)">
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

    <!-- Breakdown Modal -->
    <div class="modal fade" id="breakdownModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius:12px; overflow:hidden;">
                <div class="modal-header text-white" style="background: var(--primary-gradient);">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-receipt mr-2 text-warning"></i> Payment Allocation Breakdown
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div id="breakdownContent">
                        <p class="text-muted text-center py-3">Loading breakdown...</p>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
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
    var allocationsData = <?php echo json_encode($allocations_by_payment); ?>;

    $(document).ready(function() {
        $('#paymentsTable').DataTable({
            "order": [[ 3, "desc" ]],
            "pageLength": 25,
            "autoWidth": false
        });
    });

    function viewBreakdown(paymentId) {
        var allocs = allocationsData[paymentId] || [];
        if (allocs.length === 0) {
            $('#breakdownContent').html('<div class="alert alert-info text-center">No individual invoice allocations found for this payment.</div>');
        } else {
            var html = '<table class="table table-bordered table-sm text-center mb-0" style="font-size:12.5px;">' +
                       '<thead class="bg-light font-weight-bold"><tr>' +
                       '<th>#</th><th>Slip / Invoice #</th><th>Slip Date</th><th>Vehicle #</th><th style="text-align:left;">Products Breakdown</th><th>Charge Amount</th><th>Allocated Paid</th>' +
                       '</tr></thead><tbody>';
            var total = 0;
            allocs.forEach(function(al, i) {
                var amt = parseFloat(al.allocated_amount) || 0;
                total += amt;
                html += '<tr>' +
                        '<td>' + (i + 1) + '</td>' +
                        '<td class="font-weight-bold text-primary">' + (al.slip_no || al.invoice_no) + '</td>' +
                        '<td>' + (al.slip_date || '—') + '</td>' +
                        '<td class="font-weight-bold text-uppercase">' + (al.vehicle_number || '—') + '</td>' +
                        '<td class="text-left small">' + (al.products_summary || 'Products') + '</td>' +
                        '<td class="font-weight-bold text-dark">Rs. ' + (parseFloat(al.charge_amount) || 0).toFixed(2) + '</td>' +
                        '<td class="font-weight-bold text-success">Rs. ' + amt.toFixed(2) + '</td>' +
                        '</tr>';
            });
            html += '</tbody><tfoot class="bg-light font-weight-bold"><tr>' +
                    '<td colspan="6" class="text-right">TOTAL ALLOCATED:</td>' +
                    '<td class="text-success" style="font-size:14px;">Rs. ' + total.toFixed(2) + '</td>' +
                    '</tr></tfoot></table>';
            $('#breakdownContent').html(html);
        }
        $('#breakdownModal').modal('show');
    }

    function confirmDelete(paymentId, receiptNo, amount) {
        Swal.fire({
            title: 'Delete Product Payment?',
            html: 'Are you sure you want to delete payment receipt <strong>' + receiptNo + '</strong> (Rs. ' + amount.toFixed(2) + ')?<br><br>' +
                  '<span class="text-danger small font-weight-bold"><i class="fas fa-exclamation-triangle mr-1"></i> This will safely roll back the payment and restore customer invoice debts.</span>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Rollback & Delete'
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = '../include/delete_product_payment.php?id=' + paymentId;
            }
        });
    }
    </script>
</body>
</html>
