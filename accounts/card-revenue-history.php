<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// Fetch active card machines for filter
$mach_res = mysqli_query($connection, "SELECT id, name FROM tbl_card_machines WHERE deleted_at IS NULL ORDER BY name ASC");
$all_machines = [];
if ($mach_res) {
    while ($m = mysqli_fetch_assoc($mach_res)) {
        $all_machines[] = $m;
    }
}

// Fetch active banks for filter
$bank_res = mysqli_query($connection, "SELECT id, name, account_number FROM tbl_banks WHERE deleted_at IS NULL ORDER BY name ASC");
$all_banks = [];
if ($bank_res) {
    while ($b = mysqli_fetch_assoc($bank_res)) {
        $all_banks[] = $b;
    }
}

// Filters
$fromDate  = trim($_GET['from_date'] ?? '');
$toDate    = trim($_GET['to_date'] ?? '');
$machineId = intval($_GET['card_machine_id'] ?? 0);
$payMode   = trim($_GET['payment_mode'] ?? '');

$where = ["(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')"];

if (!empty($fromDate)) {
    $f_safe = mysqli_real_escape_string($connection, $fromDate);
    $where[] = "p.payment_date >= '$f_safe'";
}
if (!empty($toDate)) {
    $t_safe = mysqli_real_escape_string($connection, $toDate);
    $where[] = "p.payment_date <= '$t_safe'";
}
if ($machineId > 0) {
    $where[] = "p.card_machine_id = '$machineId'";
}
if (!empty($payMode) && in_array($payMode, ['Cash', 'Bank'])) {
    $where[] = "p.payment_mode = '$payMode'";
}

$where_sql = implode(' AND ', $where);

// Fetch revenue collection payments
$sql_payments = "SELECT p.*, 
                        cm.name AS machine_name,
                        b.name AS bank_name,
                        b.account_number AS bank_account_no,
                        acc.username AS created_by_name
                 FROM tbl_card_revenue_payments p
                 LEFT JOIN tbl_card_machines cm ON (p.card_machine_id = cm.id)
                 LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
                 LEFT JOIN tbl_accounts acc ON (p.created_by = acc.id)
                 WHERE $where_sql
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

// Preload allocations for instant modal lookup
$sql_alloc = "SELECT al.*, 
                     s.batch_no, 
                     s.settlement_date, 
                     s.amount AS gross_amount, 
                     s.revenue_percentage, 
                     s.revenue_amount
              FROM tbl_card_revenue_payment_allocations al
              LEFT JOIN tbl_card_sale_settlements s ON (al.settlement_id = s.id)
              WHERE (al.deleted_at IS NULL OR al.deleted_at = '0000-00-00 00:00:00')
              ORDER BY al.id ASC";

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

$canDelete = has_permission('accounts', 'delete') || has_permission('card_sales', 'delete');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PPMS - Card Revenue Collection History</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.6">
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .page-header {
            background: linear-gradient(135deg, #04204e 0%, #07347a 100%);
            color: #fff;
            padding: 20px 24px;
            border-radius: 8px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(4, 32, 78, 0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .page-header h4 { margin: 0; font-weight: 700; font-size: 1.35rem; }
        .data-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            margin-bottom: 24px;
        }
        .data-card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .data-card-body { padding: 22px; }
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #28a745;
            height: 100%;
        }
        .stat-label { font-size: 11.5px; text-transform: uppercase; font-weight: 700; color: #6c757d; margin-bottom: 4px; letter-spacing: 0.5px; }
        .stat-val   { font-size: 20px; font-weight: 900; line-height: 1.2; color: #28a745; }
        table.dataTable thead th {
            background: #04204e !important;
            color: #fff !important;
            border: none !important;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        table.dataTable tbody td {
            font-size: 0.85rem;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<?php include '../include/navbar.php'; ?>

<div class="container-fluid px-4 py-3">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-history mr-2"></i> Card Revenue Collection History</h4>
            <small class="text-white-50">Audit log of all collected card surcharge revenue vouchers</small>
        </div>
        <div>
            <a href="card-revenue-receivables.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                <i class="fas fa-arrow-left mr-1 text-primary"></i> Back to Revenue Receivables
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="data-card mb-4">
        <div class="data-card-body py-3">
            <form method="GET" action="" class="form-row align-items-end">
                <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">From Collection Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">To Collection Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-lg-3 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">POS Card Machine</label>
                    <select name="card_machine_id" class="form-control form-control-sm">
                        <option value="">All Card Machines</option>
                        <?php foreach ($all_machines as $m): ?>
                            <option value="<?php echo $m['id']; ?>" <?php echo ($machineId == $m['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">Payment Destination</label>
                    <select name="payment_mode" class="form-control form-control-sm">
                        <option value="">All Modes</option>
                        <option value="Cash" <?php echo ($payMode === 'Cash') ? 'selected' : ''; ?>>Cash in Hand</option>
                        <option value="Bank" <?php echo ($payMode === 'Bank') ? 'selected' : ''; ?>>Bank Account</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-12 col-sm-12 mb-2 d-flex">
                    <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold mr-2">
                        <i class="fas fa-filter mr-1"></i> Apply Filter
                    </button>
                    <a href="card-revenue-history.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-coins mr-1"></i> Total Card Surcharge Revenue Collected</div>
                <div class="stat-val">Rs. <?php echo number_format($total_collected, 2); ?></div>
                <small class="text-muted"><?php echo count($payments); ?> total collection voucher(s)</small>
            </div>
        </div>
    </div>

    <!-- Payment Vouchers Table Card -->
    <div class="data-card">
        <div class="data-card-header">
            <h6 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-receipt mr-2 text-primary"></i> Revenue Collection Vouchers
            </h6>
        </div>
        <div class="data-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0" id="historyTable" style="width:100%;">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:50px;">Voucher #</th>
                            <th>Collection Date</th>
                            <th>Card Machine</th>
                            <th>Destination Mode</th>
                            <th>Settlement Period Covered</th>
                            <th>Receipt Ref / Notes</th>
                            <th class="text-right">Amount (Rs.)</th>
                            <th>Collected By</th>
                            <th class="text-center" style="width:140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): 
                            $pid = intval($p['id']);
                            $voucherNo = 'REV-' . str_pad($pid, 5, '0', STR_PAD_LEFT);
                            $dateRangeStr = 'All Open Batches';
                            if (!empty($p['filter_from_date']) && !empty($p['filter_to_date'])) {
                                $dateRangeStr = date('d-m-Y', strtotime($p['filter_from_date'])) . ' to ' . date('d-m-Y', strtotime($p['filter_to_date']));
                            } elseif (!empty($p['filter_from_date'])) {
                                $dateRangeStr = 'From ' . date('d-m-Y', strtotime($p['filter_from_date']));
                            }

                            $destBadge = '<span class="badge badge-success"><i class="fas fa-money-bill-wave mr-1"></i> Cash in Hand</span>';
                            if ($p['payment_mode'] === 'Bank') {
                                $destBadge = '<span class="badge badge-info"><i class="fas fa-university mr-1"></i> ' . htmlspecialchars($p['bank_name'] ?: 'Bank') . '</span>';
                            }
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold" style="font-family: monospace;">
                                <?php echo $voucherNo; ?>
                            </td>
                            <td class="font-weight-bold">
                                <?php echo date('d-m-Y', strtotime($p['payment_date'])); ?>
                            </td>
                            <td>
                                <i class="fas fa-credit-card mr-1 text-primary"></i>
                                <strong><?php echo htmlspecialchars($p['machine_name']); ?></strong>
                            </td>
                            <td>
                                <?php echo $destBadge; ?>
                            </td>
                            <td class="text-muted small">
                                <?php echo $dateRangeStr; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['transaction_ref'])): ?>
                                    <span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($p['transaction_ref']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($p['remarks'])): ?>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($p['remarks']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-weight-bold text-success" style="font-size:0.95rem;">
                                Rs. <?php echo number_format(floatval($p['total_amount']), 2); ?>
                            </td>
                            <td class="text-muted small">
                                <i class="fas fa-user-circle mr-1"></i><?php echo htmlspecialchars($p['created_by_name'] ?: 'System'); ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-info btn-sm py-0 px-2 mr-1" 
                                        onclick="showAllocations(<?php echo $pid; ?>, '<?php echo $voucherNo; ?>', '<?php echo number_format(floatval($p['total_amount']), 2); ?>')"
                                        title="View Settled Batches">
                                    <i class="fas fa-layer-group"></i>
                                </button>
                                <a href="generate-pdf-revenue-receipt.php?payment_id=<?php echo $pid; ?>" 
                                   target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2 mr-1" title="Print PDF Receipt">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if ($canDelete): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" 
                                        onclick="deletePayment(<?php echo $pid; ?>, '<?php echo $voucherNo; ?>', '<?php echo number_format(floatval($p['total_amount']), 2); ?>')"
                                        title="Rollback Collection">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal: View Batch Allocations -->
<div class="modal fade" id="allocModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-layer-group mr-2 text-warning"></i> Batches Cleared &bull; <span id="modalVoucherNo"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small">Total Revenue Surcharge:</span>
                        <strong class="text-success ml-1" id="modalVoucherAmount">Rs. 0.00</strong>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="bg-secondary text-white">
                            <tr>
                                <th>#</th>
                                <th>Settlement Date</th>
                                <th class="text-center">Batch #</th>
                                <th class="text-right">Pure Fuel Sales</th>
                                <th class="text-center">Rev Rate %</th>
                                <th class="text-right">Total Surcharge</th>
                                <th class="text-right">Allocated Surcharge</th>
                            </tr>
                        </thead>
                        <tbody id="allocTableBody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
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
    $('#historyTable').DataTable({
        pageLength: 25,
        ordering: false,
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6"f>>rt<"row mt-2"<"col-md-6"i><"col-md-6"p>>',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search vouchers..."
        }
    });
});

function showAllocations(paymentId, voucherNo, totalAmt) {
    $('#modalVoucherNo').text(voucherNo);
    $('#modalVoucherAmount').text('Rs. ' + totalAmt);

    var rows = allocationsData[paymentId] || [];
    var html = '';

    if (rows.length === 0) {
        html = '<tr><td colspan="7" class="text-center text-muted py-3">No batch allocations recorded for this voucher.</td></tr>';
    } else {
        $.each(rows, function(idx, item) {
            html += '<tr>' +
                '<td>' + (idx + 1) + '</td>' +
                '<td>' + item.settlement_date + '</td>' +
                '<td class="text-center font-weight-bold" style="font-family:monospace;">' + (item.batch_no || '—') + '</td>' +
                '<td class="text-right">Rs. ' + parseFloat(item.gross_amount || 0).toFixed(2) + '</td>' +
                '<td class="text-center font-weight-bold text-muted">' + parseFloat(item.revenue_percentage || 0).toFixed(4) + '%</td>' +
                '<td class="text-right text-info font-weight-bold">+Rs. ' + parseFloat(item.revenue_amount || 0).toFixed(2) + '</td>' +
                '<td class="text-right font-weight-bold text-success">Rs. ' + parseFloat(item.allocated_amount || 0).toFixed(2) + '</td>' +
            '</tr>';
        });
    }

    $('#allocTableBody').html(html);
    $('#allocModal').modal('show');
}

function deletePayment(paymentId, voucherNo, totalAmt) {
    Swal.fire({
        title: 'Rollback Collection Voucher?',
        html: 'Are you sure you want to delete Voucher <strong>#' + voucherNo + '</strong> for <strong>Rs. ' + totalAmt + '</strong>?<br><br><span class="text-danger small"><i class="fas fa-exclamation-triangle mr-1"></i> This will soft-delete the collection and automatically restore the balance due on all attached settlement batches!</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Rollback Collection'
    }).then(function(result) {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Rolling back...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            $.ajax({
                url: '../include/deletecardrevenuepayment.php',
                type: 'POST',
                data: { payment_id: paymentId },
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire({
                            title: 'Rolled Back!',
                            html: res.message,
                            icon: 'success'
                        }).then(function() {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', res.message || 'Rollback failed.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'An error occurred while communicating with server.', 'error');
                }
            });
        }
    });
}
</script>

</body>
</html>
