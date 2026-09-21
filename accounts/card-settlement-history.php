<?php
require_once __DIR__ . '/../include/session.php';
if (!userloggedin()) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/permissions.php';

check_access('accounts', 'show');

// Self-healing schema checks
$chk_p = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_settlement_payments'");
if ($chk_p && mysqli_num_rows($chk_p) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_settlement_payments` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_date` DATE NOT NULL,
      `card_machine_id` INT(11) NOT NULL,
      `bank_id` INT(11) NOT NULL,
      `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `filter_from_date` DATE DEFAULT NULL,
      `filter_to_date` DATE DEFAULT NULL,
      `transaction_ref` VARCHAR(128) DEFAULT NULL,
      `remarks` TEXT DEFAULT NULL,
      `created_by` INT(11) DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_machine` (`card_machine_id`),
      KEY `idx_payment_date` (`payment_date`),
      KEY `idx_bank` (`bank_id`),
      KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

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
$bankId    = intval($_GET['bank_id'] ?? 0);

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
if ($bankId > 0) {
    $where[] = "p.bank_id = '$bankId'";
}

$where_sql = implode(' AND ', $where);

// Fetch payments
$sql_payments = "SELECT p.*, 
                        cm.name AS machine_name,
                        b.name AS bank_name,
                        b.account_number AS bank_account_no,
                        acc.username AS created_by_name
                 FROM tbl_card_settlement_payments p
                 LEFT JOIN tbl_card_machines cm ON (p.card_machine_id = cm.id)
                 LEFT JOIN tbl_banks b ON (p.bank_id = b.id)
                 LEFT JOIN tbl_accounts acc ON (p.created_by = acc.id)
                 WHERE $where_sql
                 ORDER BY p.payment_date DESC, p.id DESC";

$res_payments = mysqli_query($connection, $sql_payments);
$payments = [];
$total_deposited = 0.00;

if ($res_payments) {
    while ($p = mysqli_fetch_assoc($res_payments)) {
        $payments[] = $p;
        $total_deposited += floatval($p['total_amount']);
    }
}

// Preload allocations for instant modal lookup
$sql_alloc = "SELECT al.*, 
                     s.batch_no, 
                     s.settlement_date, 
                     s.amount AS gross_amount, 
                     s.service_charges, 
                     s.net_amount
              FROM tbl_card_settlement_payment_allocations al
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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.6">
    <title>PPMS - Card Settlement Deposit History</title>
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
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .data-card-body { padding: 22px; }
        .metric-pill {
            display: inline-flex;
            align-items: center;
            background: #eef3fc;
            color: #04204e;
            padding: 7px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.95rem;
            border: 1px solid #c8d8f4;
        }
        table.dataTable thead th {
            background: #04204e !important;
            color: #fff !important;
            border: none !important;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.dataTable tbody td {
            font-size: 0.86rem;
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
            <h4 class="mb-1 font-weight-bold"><i class="fas fa-history mr-2"></i> Card Settlement Deposit Receipts</h4>
            <small class="text-white-50">Audit log of all bank settlement payouts deposited into Bank Master accounts</small>
        </div>
        <div>
            <a href="card-sale-receivables.php" class="btn btn-light btn-sm font-weight-bold shadow-sm">
                <i class="fas fa-wallet mr-1 text-primary"></i> Accounts Receivable
            </a>
            <a href="../card-sales/settlement-list.php" class="btn btn-outline-light btn-sm font-weight-bold ml-2">
                <i class="fas fa-list-alt mr-1"></i> Settlements List
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="data-card mb-4">
        <div class="data-card-body py-3">
            <form method="GET" action="" class="form-row align-items-end">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">From Deposit Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate); ?>">
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">To Deposit Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate); ?>">
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
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
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="font-weight-bold text-muted small mb-1">Destination Bank (Master)</label>
                    <select name="bank_id" class="form-control form-control-sm">
                        <option value="">All Bank Accounts</option>
                        <?php foreach ($all_banks as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($bankId == $b['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name'] . ' (' . $b['account_number'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-12 mb-2 d-flex">
                    <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold mr-2">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
                    <a href="card-settlement-history.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="data-card">
        <div class="data-card-header">
            <h6 class="mb-0 font-weight-bold text-dark">
                <i class="fas fa-receipt mr-2 text-primary"></i> Bank Deposit Receipts
                <span class="badge badge-secondary ml-2"><?php echo count($payments); ?> Vouchers</span>
            </h6>
            <div class="metric-pill">
                <i class="fas fa-coins mr-2 text-warning"></i>
                Total Deposited: <span class="ml-1 font-weight-bold">Rs. <?php echo number_format($total_deposited, 2); ?></span>
            </div>
        </div>
        <div class="data-card-body p-0">
            <div class="table-responsive p-3">
                <table id="paymentsTable" class="table table-hover table-striped table-bordered mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:65px;">Voucher #</th>
                            <th class="text-center">Deposit Date</th>
                            <th>POS Card Machine</th>
                            <th>Destination Bank Master</th>
                            <th>Settlement Range</th>
                            <th class="text-right">Amount (Rs.)</th>
                            <th>Transaction Ref</th>
                            <th>Recorded By</th>
                            <th class="text-center" style="width:115px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-center font-weight-bold text-monospace text-primary">
                                #<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?>
                            </td>
                            <td class="text-center font-weight-bold">
                                <?php echo date('d-M-Y', strtotime($p['payment_date'])); ?>
                            </td>
                            <td class="font-weight-bold">
                                <i class="fas fa-credit-card text-info mr-1"></i>
                                <?php echo htmlspecialchars($p['machine_name']); ?>
                            </td>
                            <td>
                                <i class="fas fa-university text-success mr-1"></i>
                                <strong><?php echo htmlspecialchars($p['bank_name']); ?></strong><br>
                                <small class="text-muted text-monospace"><?php echo htmlspecialchars($p['bank_account_no']); ?></small>
                            </td>
                            <td class="small">
                                <?php 
                                if (!empty($p['filter_from_date']) && !empty($p['filter_to_date'])) {
                                    echo date('d-M-y', strtotime($p['filter_from_date'])) . ' to ' . date('d-M-y', strtotime($p['filter_to_date']));
                                } elseif (!empty($p['filter_from_date'])) {
                                    echo 'From ' . date('d-M-y', strtotime($p['filter_from_date']));
                                } else {
                                    echo '<span class="text-muted">Open Batches</span>';
                                }
                                ?>
                            </td>
                            <td class="text-right font-weight-bold text-success" style="font-size:0.95rem;">
                                Rs. <?php echo number_format(floatval($p['total_amount']), 2); ?>
                            </td>
                            <td class="text-monospace small">
                                <?php echo !empty($p['transaction_ref']) ? htmlspecialchars($p['transaction_ref']) : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="small">
                                <?php echo htmlspecialchars($p['created_by_name'] ?? 'System'); ?><br>
                                <small class="text-muted"><?php echo date('d-M-y h:i A', strtotime($p['created_at'])); ?></small>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-info" onclick="viewAllocations(<?php echo $p['id']; ?>)" title="View Settled Batches">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="generate-pdf-card-receipt.php?payment_id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-secondary" title="Print Deposit Receipt">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($canDelete): ?>
                                    <button type="button" class="btn btn-danger" onclick="deleteDeposit(<?php echo $p['id']; ?>, '<?php echo number_format(floatval($p['total_amount']), 2); ?>')" title="Delete & Rollback Balance">
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

<!-- Modal: View Itemized Allocated Batches -->
<div class="modal fade" id="allocationsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-list-ol mr-2"></i> Itemized Settlement Batches (<span id="modalVoucherNo">#</span>)
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead class="thead-dark">
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th class="text-center">Batch Date</th>
                                <th class="text-center">Batch #</th>
                                <th class="text-right">Pure Sales (Rs.)</th>
                                <th class="text-right">Bank Fee (Rs.)</th>
                                <th class="text-right">Net Receivable (Rs.)</th>
                                <th class="text-right">Allocated (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody id="modalAllocationsBody">
                            <!-- Populated via JS -->
                        </tbody>
                        <tfoot class="font-weight-bold bg-light">
                            <tr>
                                <td colspan="6" class="text-right">Total Allocated:</td>
                                <td class="text-right text-primary" id="modalAllocTotal">Rs. 0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Close
                </button>
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
        "order": [[0, "desc"]],
        "pageLength": 25,
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search deposit vouchers..."
        }
    });
});

function viewAllocations(paymentId) {
    $('#modalVoucherNo').text('DEP-' + String(paymentId).padStart(5, '0'));
    var list = allocationsData[paymentId] || [];
    var $tbody = $('#modalAllocationsBody').empty();
    var totalAlloc = 0.0;

    if (list.length === 0) {
        $tbody.append('<tr><td colspan="7" class="text-center text-muted py-3">No batch allocations recorded for this voucher.</td></tr>');
    } else {
        $.each(list, function(idx, item) {
            var allocAmt = parseFloat(item.allocated_amount) || 0;
            totalAlloc += allocAmt;
            var grossAmt = parseFloat(item.gross_amount) || 0;
            var feeAmt   = parseFloat(item.service_charges) || 0;
            var netAmt   = parseFloat(item.net_amount) || 0;

            var tr = '<tr>' +
                '<td class="text-center">' + (idx + 1) + '</td>' +
                '<td class="text-center">' + (item.settlement_date || '—') + '</td>' +
                '<td class="text-center font-weight-bold text-monospace">' + (item.batch_no || '—') + '</td>' +
                '<td class="text-right">' + grossAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                '<td class="text-right text-danger">-' + feeAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                '<td class="text-right font-weight-bold">' + netAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                '<td class="text-right font-weight-bold text-primary">Rs. ' + allocAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
            '</tr>';
            $tbody.append(tr);
        });
    }

    $('#modalAllocTotal').text('Rs. ' + totalAlloc.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    $('#allocationsModal').modal('show');
}

function deleteDeposit(paymentId, amountStr) {
    Swal.fire({
        title: 'Delete Deposit Voucher?',
        html: 'Are you sure you want to delete deposit voucher <strong>#' + String(paymentId).padStart(5, '0') + '</strong> (Rs. ' + amountStr + ')?<br><br><span class="text-danger font-weight-bold">This will automatically revert the settled amounts on the batch records back to their outstanding due balance!</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete & Revert Balance',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing Rollback...',
                text: 'Reverting settlement balances and removing voucher.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: '../include/deletecardsettlementpayment.php',
                type: 'POST',
                dataType: 'json',
                data: { payment_id: paymentId },
                success: function(resp) {
                    if (resp.status === 'success') {
                        Swal.fire({
                            title: 'Rollback Complete!',
                            html: resp.message,
                            icon: 'success'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', resp.message || 'Failed to delete deposit voucher.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'Server communication error: ' + error, 'error');
                }
            });
        }
    });
}
</script>

</body>
</html>
