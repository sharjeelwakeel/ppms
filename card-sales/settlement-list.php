<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('card_sales', 'show');

// Self-healing migration for tbl_card_sale_settlements
$chk_tbl = mysqli_query($connection, "SHOW TABLES LIKE 'tbl_card_sale_settlements'");
if ($chk_tbl && mysqli_num_rows($chk_tbl) == 0) {
    mysqli_query($connection, "CREATE TABLE IF NOT EXISTS `tbl_card_sale_settlements` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `card_machine_id` INT(11) NOT NULL,
      `settlement_date` DATE NOT NULL,
      `batch_no` VARCHAR(64) NOT NULL,
      `no_of_cards` INT(11) NOT NULL DEFAULT 1,
      `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `charges_percentage` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
      `service_charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `notes` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `deleted_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_card_machine_id` (`card_machine_id`),
      KEY `idx_settlement_date` (`settlement_date`),
      KEY `idx_deleted_at` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
}

// Filter handling
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$where = "(s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND s.settlement_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND s.settlement_date <= '$to_safe'";
}

// Summary Metrics
$sql_summary = "SELECT 
                  COUNT(s.id) AS total_settlements,
                  COALESCE(SUM(s.no_of_cards), 0) AS total_cards,
                  COALESCE(SUM(s.amount), 0) AS total_gross,
                  COALESCE(SUM(s.service_charges), 0) AS total_charges,
                  COALESCE(SUM(s.net_amount), 0) AS total_net
                FROM tbl_card_sale_settlements s
                WHERE $where";
$res_summary = mysqli_query($connection, $sql_summary);
$metrics = mysqli_fetch_assoc($res_summary) ?: [
    'total_settlements' => 0, 'total_cards' => 0, 'total_gross' => 0, 'total_charges' => 0, 'total_net' => 0
];

// Main Listing Query
$sql_list = "SELECT s.*, cm.name AS machine_name, cm.charges_percentage AS machine_fee_rate
             FROM tbl_card_sale_settlements s
             LEFT JOIN tbl_card_machines cm ON (s.card_machine_id = cm.id)
             WHERE $where
             ORDER BY s.settlement_date DESC, s.id DESC";
$res_list = mysqli_query($connection, $sql_list);

$canAdd    = has_permission('card_sales', 'add');
$canEdit   = has_permission('card_sales', 'edit');
$canDelete = has_permission('card_sales', 'delete');
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Card Sale Settlements</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }
        .page-header {
            background: var(--gradient-header);
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }
        .metric-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 10px rgba(0,0,0,0.06);
            padding:16px 20px; margin-bottom:20px;
            border-left:4px solid var(--primary-color);
            transition:transform .15s ease;
        }
        .metric-card:hover { transform:translateY(-2px); }
        .metric-card.accent-green { border-left-color:#10b981; }
        .metric-card.accent-red   { border-left-color:#ef4444; }
        .metric-card.accent-blue  { border-left-color:#0284c7; }
        .metric-label { font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px; }
        .metric-value { font-size:1.25rem; font-weight:800; color:#0f172a; }
        .list-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden;
        }
        .list-card-title {
            background: var(--primary-gradient);
            color:#fff; padding:12px 20px;
            font-weight:600; font-size:14px;
        }
        #settlementsTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600; text-align:center; vertical-align:middle;
        }
        #settlementsTable tbody tr:hover { background: var(--primary-light); }
        #settlementsTable td { vertical-align:middle; font-size:13px; }
        .btn-new {
            background: var(--primary-gradient);
            color:#fff!important; border:none;
            padding:7px 16px; border-radius:6px;
            font-size:13px; font-weight:600;
            display:inline-flex; align-items:center; gap:6px;
            box-shadow:0 2px 8px rgba(4,32,78,0.22);
            transition:opacity .15s;
        }
        .btn-new:hover { opacity:0.9; }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-file-invoice-dollar mr-2 text-warning"></i> Card Sale Settlements</h4>
            <small class="text-white-50">Manage bank POS batch settlements, swipe counts, fee reconciliation, and net receivables</small>
        </div>
        <div class="d-flex align-items-center">
            <a href="card-sales-list.php" class="btn btn-outline-light mr-2 font-weight-bold" title="Go to Card Sale Reading">
                <i class="fas fa-credit-card mr-1"></i> Card Sales List
            </a>
            <?php if ($canAdd): ?>
            <a href="add-settlement.php" class="btn btn-new">
                <i class="fas fa-plus"></i> Add Settlement
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="metric-card">
                <div class="metric-label">Total Settlements</div>
                <div class="metric-value text-dark"><?php echo intval($metrics['total_settlements']); ?> <small class="text-muted font-weight-normal" style="font-size:12px;">Batches</small></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="metric-card accent-blue">
                <div class="metric-label">Total Cards / Swipes</div>
                <div class="metric-value text-primary"><?php echo intval($metrics['total_cards']); ?> <small class="text-muted font-weight-normal" style="font-size:12px;">Cards</small></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="metric-card accent-red">
                <div class="metric-label">Gross Settled Amount</div>
                <div class="metric-value text-dark">Rs. <?php echo number_format($metrics['total_gross'], 2); ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="metric-card accent-green">
                <div class="metric-label">Net Bank Receivable</div>
                <div class="metric-value text-success">Rs. <?php echo number_format($metrics['total_net'], 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:10px;">
        <div class="card-body py-3">
            <form method="GET" class="form-inline d-flex flex-wrap justify-content-between align-items-center">
                <div class="d-flex align-items-center flex-wrap">
                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> From Date:</label>
                    <input type="date" name="from_date" class="form-control form-control-sm mr-3 mb-2 mb-md-0" value="<?php echo htmlspecialchars($from_date); ?>">
                    
                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> To Date:</label>
                    <input type="date" name="to_date" class="form-control form-control-sm mr-3 mb-2 mb-md-0" value="<?php echo htmlspecialchars($to_date); ?>">
                    
                    <button type="submit" class="btn btn-primary btn-sm px-3 mr-2 mb-2 mb-md-0">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
                    <?php if (!empty($from_date) || !empty($to_date)): ?>
                    <a href="settlement-list.php" class="btn btn-outline-secondary btn-sm mb-2 mb-md-0">
                        <i class="fas fa-times mr-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-2 mt-md-0">
                    <i class="fas fa-info-circle mr-1 text-primary"></i> Filter settlements by settlement date
                </div>
            </form>
        </div>
    </div>

    <!-- Main List Card -->
    <div class="list-card">
        <div class="list-card-title d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list mr-2"></i> POS Terminal Settlements</span>
            <?php if ($res_list && mysqli_num_rows($res_list) > 0): ?>
            <a href="generate-pdf-settlement.php<?php echo (!empty($from_date) || !empty($to_date)) ? '?from_date='.urlencode($from_date).'&to_date='.urlencode($to_date) : ''; ?>" target="_blank" class="btn btn-sm btn-light text-primary font-weight-bold">
                <i class="fas fa-file-pdf text-danger mr-1"></i> Print PDF Statement
            </a>
            <?php endif; ?>
        </div>
        <div class="p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-center mb-0" id="settlementsTable">
                    <thead>
                        <tr>
                            <th style="width: 45px;">#</th>
                            <th style="width: 110px;">Date</th>
                            <th>Machine (Bank Terminal)</th>
                            <th>Batch No</th>
                            <th style="width: 80px;">Cards</th>
                            <th>Amount (Rs.)</th>
                            <th style="width: 80px;">Fee %</th>
                            <th>Charges (Rs.)</th>
                            <th>Net Amount (Rs.)</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        if ($res_list && mysqli_num_rows($res_list) > 0):
                            while ($row = mysqli_fetch_assoc($res_list)): 
                                $dateVal = $row['settlement_date'];
                                $displayDate = date('d-m-Y', strtotime($dateVal));
                                $id = intval($row['id']);
                                $machineName = !empty($row['machine_name']) ? $row['machine_name'] : ('Machine #' . $row['card_machine_id']);
                                $feePct = floatval($row['charges_percentage']);
                        ?>
                        <tr>
                            <td class="font-weight-bold text-muted"><?php echo $counter++; ?></td>
                            <td class="font-weight-bold text-nowrap">
                                <?php if ($canEdit): ?>
                                    <a href="edit-settlement.php?id=<?php echo $id; ?>" style="color:var(--primary-color); text-decoration:underline;" title="Edit Settlement #<?php echo $id; ?>">
                                        <i class="fas fa-calendar-day mr-1 text-muted"></i><?php echo $displayDate; ?>
                                    </a>
                                <?php else: ?>
                                    <i class="fas fa-calendar-day mr-1 text-muted"></i><?php echo $displayDate; ?>
                                <?php endif; ?>
                            </td>
                            <td class="font-weight-bold text-left text-primary">
                                <i class="fas fa-credit-card mr-1 text-muted"></i><?php echo htmlspecialchars($machineName); ?>
                            </td>
                            <td class="font-weight-bold text-monospace">
                                <?php echo htmlspecialchars($row['batch_no']); ?>
                            </td>
                            <td>
                                <span class="badge badge-info px-2 py-1 font-weight-bold">
                                    <?php echo intval($row['no_of_cards']); ?>
                                </span>
                            </td>
                            <td class="font-weight-bold text-dark">
                                Rs. <?php echo number_format($row['amount'], 2); ?>
                            </td>
                            <td class="text-muted small">
                                <?php echo number_format($feePct, 4); ?>%
                            </td>
                            <td class="text-danger font-weight-bold">
                                -Rs. <?php echo number_format($row['service_charges'], 2); ?>
                            </td>
                            <td>
                                <strong class="text-success font-weight-bold">
                                    Rs. <?php echo number_format($row['net_amount'], 2); ?>
                                </strong>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <?php if ($canEdit): ?>
                                    <a href="edit-settlement.php?id=<?php echo $id; ?>" class="btn btn-primary" title="Edit Settlement">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete): ?>
                                    <button type="button" class="btn btn-danger" onclick="deleteSettlement(<?php echo $id; ?>, '<?php echo htmlspecialchars(addslashes($row['batch_no'])); ?>', '<?php echo $displayDate; ?>')" title="Delete Settlement">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        endif; 
                        ?>
                    </tbody>
                </table>
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
$(document).ready(function() {
    $('#settlementsTable').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 15,
        "autoWidth": false,
        "language": {
            "emptyTable": "No card sale settlements recorded yet."
        }
    });
});

function deleteSettlement(id, batchNo, dateDisplay) {
    Swal.fire({
        title: 'Delete Settlement?',
        html: 'Are you sure you want to delete Settlement for Batch <strong>#' + batchNo + '</strong> on <strong>' + dateDisplay + '</strong>?<br><small class="text-danger">This will soft-delete this settlement record.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: '../include/deletesettlement.php',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(resp) {
                    if (resp.status === 'success') {
                        Swal.fire('Deleted!', resp.message || 'Settlement has been deleted.', 'success')
                            .then(function() { location.reload(); });
                    } else {
                        Swal.fire('Error', resp.message || 'Unable to delete settlement.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Unable to delete settlement due to a network error.', 'error');
                }
            });
        }
    });
}
</script>
</body>
</html>
