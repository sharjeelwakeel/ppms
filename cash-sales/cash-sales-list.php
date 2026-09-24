<?php
require_once '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/cash_helper.php';

init_cash_sales_schema($connection);
check_access('cash_sales', 'show');

// Flash messages
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

// Filters
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';
$filter_shift  = intval($_GET['shift_id'] ?? 0);
$filter_nozzle = intval($_GET['nozzle_id'] ?? 0);
$filter_item   = intval($_GET['item_id'] ?? 0);

// Default to current month if no date filter specified
if (empty($from_date) && empty($to_date)) {
    $from_date = date('Y-m-01');
    $to_date   = date('Y-m-d');
}

$where = "cs.deleted_at IS NULL";

if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND cs.sale_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND cs.sale_date <= '$to_safe'";
}
if ($filter_shift > 0) {
    $where .= " AND cs.shift_id = '$filter_shift'";
}
if ($filter_nozzle > 0) {
    $where .= " AND cs.nozzle_id = '$filter_nozzle'";
}
if ($filter_item > 0) {
    $where .= " AND cs.item_id = '$filter_item'";
}

// Fetch all filtered cash sales
$sql = "SELECT cs.*,
               sh.name AS shift_name,
               n.name AS nozzle_name,
               i.name AS item_name,
               i.unit AS item_unit,
               u.name AS created_by_name
        FROM tbl_meter_reading_cash_sales cs
        LEFT JOIN tbl_shifts sh ON (cs.shift_id = sh.id)
        LEFT JOIN tbl_nozzles n ON (cs.nozzle_id = n.id)
        LEFT JOIN tbl_items i ON (cs.item_id = i.id)
        LEFT JOIN tbl_accounts u ON (cs.created_by = u.id)
        WHERE $where
        ORDER BY cs.sale_date DESC, cs.id DESC";

$result = mysqli_query($connection, $sql);

// Aggregate KPI metrics
$kpi_total_cash = 0.00;
$kpi_total_litres = 0.00;
$kpi_count = 0;
$sales_data = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $kpi_total_cash += floatval($row['amount']);
        $kpi_total_litres += floatval($row['quantity']);
        $kpi_count++;
        $sales_data[] = $row;
    }
}

// Filter lookups
$shifts = get_active_shifts($connection);
$nozzles = get_active_nozzles_with_items($connection);
$fuel_items = get_fuel_items($connection);
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
    <title>PPMS - Cash Sale Reading</title>
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
        #cashSalesTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        #cashSalesTable tbody tr:hover { background: var(--primary-light); }
        #cashSalesTable td { vertical-align:middle; font-size:13px; }
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
        .stat-card {
            background:#fff; border-radius:10px; padding:18px 22px;
            box-shadow:0 2px 10px rgba(0,0,0,0.06); border-left:4px solid var(--primary-color);
            margin-bottom:20px;
        }
        .stat-label { font-size:11px; text-transform:uppercase; color:#6c757d; font-weight:700; letter-spacing:0.5px; }
        .stat-value { font-size:1.5rem; font-weight:700; color:#04204e; margin-top:4px; }
    </style>
</head>
<body>
<?php include '../include/navbar.php'; ?>

<div class="container-fluid mt-4 px-3 px-lg-4 mb-5">
    <div class="page-header">
        <div>
            <h4><i class="fas fa-money-bill-wave mr-2 text-warning"></i> Cash Sale Reading</h4>
            <small class="text-white-50">Track cash sales per nozzle, fuel volume dispensed, cash received, and shift totals</small>
        </div>
        <div>
            <?php if (has_permission('cash_sales', 'add')): ?>
            <a href="add-cash-sale.php" class="btn btn-new">
                <i class="fas fa-plus"></i> Add Cash Sale
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flash_success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($flash_success); ?>
        <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
    </div>
    <?php endif; ?>

    <!-- Summary KPI Cards -->
    <div class="row">
        <div class="col-md-4 col-sm-6">
            <div class="stat-card" style="border-left-color: #28a745;">
                <div class="stat-label"><i class="fas fa-rupee-sign mr-1"></i> Total Cash Received</div>
                <div class="stat-value text-success">Rs. <?php echo number_format($kpi_total_cash, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4 col-sm-6">
            <div class="stat-card" style="border-left-color: #007bff;">
                <div class="stat-label"><i class="fas fa-gas-pump mr-1"></i> Total Fuel Litres Sold</div>
                <div class="stat-value text-primary"><?php echo number_format($kpi_total_litres, 2); ?> Ltr</div>
            </div>
        </div>
        <div class="col-md-4 col-sm-12">
            <div class="stat-card" style="border-left-color: #6f42c1;">
                <div class="stat-label"><i class="fas fa-receipt mr-1"></i> Total Transactions</div>
                <div class="stat-value text-dark"><?php echo number_format($kpi_count); ?> Entries</div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:10px;">
        <div class="card-body py-3">
            <form method="GET" class="form-inline d-flex flex-wrap justify-content-between align-items-center">
                <div class="d-flex align-items-center flex-wrap">
                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> From:</label>
                    <input type="date" name="from_date" class="form-control form-control-sm mr-3 mb-2" value="<?php echo htmlspecialchars($from_date); ?>">
                    
                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-calendar-alt mr-1"></i> To:</label>
                    <input type="date" name="to_date" class="form-control form-control-sm mr-3 mb-2" value="<?php echo htmlspecialchars($to_date); ?>">

                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-clock mr-1"></i> Shift:</label>
                    <select name="shift_id" class="form-control form-control-sm mr-3 mb-2">
                        <option value="0">All Shifts</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo $sh['id']; ?>" <?php echo ($filter_shift == $sh['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sh['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mr-2 font-weight-bold text-muted small"><i class="fas fa-gas-pump mr-1"></i> Nozzle:</label>
                    <select name="nozzle_id" class="form-control form-control-sm mr-3 mb-2">
                        <option value="0">All Nozzles</option>
                        <?php foreach ($nozzles as $noz): ?>
                            <option value="<?php echo $noz['id']; ?>" <?php echo ($filter_nozzle == $noz['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($noz['name'] . ' (' . $noz['item_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="btn btn-primary btn-sm px-3 mr-2 mb-2">
                        <i class="fas fa-filter mr-1"></i> Filter
                    </button>
                    <a href="cash-sales-list.php" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-times mr-1"></i> Reset
                    </a>
                </div>

                <div>
                    <a href="generate-pdf-cash-sale.php?from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&shift_id=<?php echo $filter_shift; ?>&nozzle_id=<?php echo $filter_nozzle; ?>" target="_blank" class="btn btn-outline-danger btn-sm mb-2 font-weight-bold">
                        <i class="fas fa-file-pdf mr-1"></i> Print / PDF Voucher
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="list-card">
        <div class="list-card-title d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list mr-2"></i> Cash Sale Reading Records</span>
            <span class="badge badge-light text-dark font-weight-bold"><?php echo count($sales_data); ?> Records</span>
        </div>
        <div class="p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm text-center mb-0" id="cashSalesTable">
                    <thead>
                        <tr>
                            <th style="width: 4%;">#</th>
                            <th style="width: 11%;">Sale Date</th>
                            <th style="width: 10%;">Shift</th>
                            <th style="width: 14%;">Nozzle</th>
                            <th style="width: 12%;">Fuel Type</th>
                            <th style="width: 10%;">Rate (Rs.)</th>
                            <th style="width: 12%;">Litres Sold</th>
                            <th style="width: 13%;">Amount (Rs.)</th>
                            <th style="width: 14%;">Notes</th>
                            <th style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales_data)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
                                No cash sale records found for the selected period.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php 
                            $seq = 1;
                            foreach ($sales_data as $row): 
                                $rate_formatted = number_format(floatval($row['rate']), 2);
                                $qty_formatted  = number_format(floatval($row['quantity']), 2);
                                $amt_formatted  = number_format(floatval($row['amount']), 2);
                            ?>
                            <tr>
                                <td><?php echo $seq++; ?></td>
                                <td class="font-weight-bold text-nowrap"><?php echo date('d-m-Y', strtotime($row['sale_date'])); ?></td>
                                <td><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($row['shift_name'] ?? 'General'); ?></span></td>
                                <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['nozzle_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-primary px-2 py-1"><?php echo htmlspecialchars($row['item_name'] ?? 'Fuel'); ?></span>
                                </td>
                                <td class="text-right"><?php echo $rate_formatted; ?></td>
                                <td class="text-right font-weight-bold text-success"><?php echo $qty_formatted; ?> Ltr</td>
                                <td class="text-right font-weight-bold text-primary">Rs. <?php echo $amt_formatted; ?></td>
                                <td class="text-left text-muted small">
                                    <?php if (intval($row['is_manual_override'] ?? 0) === 1): ?>
                                        <span class="badge badge-warning text-dark mr-1" title="Manually edited by user"><i class="fas fa-user-edit mr-1"></i>Manual</span>
                                    <?php elseif (intval($row['meter_reading_id'] ?? 0) > 0): ?>
                                        <span class="badge badge-info mr-1" title="System auto-calculated from Meter Reading #<?php echo $row['meter_reading_id']; ?>"><i class="fas fa-robot mr-1"></i>Auto</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($row['notes'] ?? ''); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-info" title="View Details" onclick="viewCashSaleModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (has_permission('cash_sales', 'edit')): ?>
                                        <a href="edit-cash-sale.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-primary" title="Edit Record">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (has_permission('cash_sales', 'delete')): ?>
                                        <button type="button" class="btn btn-outline-danger" title="Delete Record" onclick="deleteCashSale(<?php echo $row['id']; ?>)">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Quick View Modal -->
<div class="modal fade" id="cashSaleModal" tabindex="-1" role="dialog" aria-labelledby="cashSaleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title" id="cashSaleModalLabel"><i class="fas fa-info-circle mr-2"></i> Cash Sale Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="font-weight-bold text-muted" style="width:40%;">Sale Date:</td>
                        <td id="m_sale_date" class="font-weight-bold"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Shift:</td>
                        <td id="m_shift"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Nozzle:</td>
                        <td id="m_nozzle" class="font-weight-bold text-primary"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Fuel Type:</td>
                        <td id="m_fuel_type"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Rate (Rs./Ltr):</td>
                        <td id="m_rate"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Litres Dispensed:</td>
                        <td id="m_quantity" class="font-weight-bold text-success"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Total Cash Amount:</td>
                        <td id="m_amount" class="font-weight-bold text-primary" style="font-size:16px;"></td>
                    </tr>
                    <tr>
                        <td class="font-weight-bold text-muted">Notes:</td>
                        <td id="m_notes" class="text-muted"></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    $('#cashSalesTable').DataTable({
        pageLength: 25,
        ordering: false,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search records..."
        }
    });
});

function viewCashSaleModal(data) {
    document.getElementById('m_sale_date').textContent = data.sale_date;
    document.getElementById('m_shift').textContent = data.shift_name || 'General';
    document.getElementById('m_nozzle').textContent = data.nozzle_name || 'N/A';
    document.getElementById('m_fuel_type').textContent = data.item_name || 'Fuel';
    document.getElementById('m_rate').textContent = parseFloat(data.rate).toFixed(2);
    document.getElementById('m_quantity').textContent = parseFloat(data.quantity).toFixed(2) + ' Ltr';
    document.getElementById('m_amount').textContent = 'Rs. ' + parseFloat(data.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('m_notes').textContent = data.notes || '—';
    $('#cashSaleModal').modal('show');
}

function deleteCashSale(saleId) {
    Swal.fire({
        title: 'Delete Cash Sale?',
        text: 'Are you sure you want to soft delete this cash sale transaction?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'ajax-delete-cash-sale.php',
                type: 'POST',
                data: { id: saleId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message || 'Record deleted successfully.',
                            confirmButtonColor: '#04204e'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed',
                            text: response.message || 'Could not delete record.',
                            confirmButtonColor: '#04204e'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Server communication failed.',
                        confirmButtonColor: '#04204e'
                    });
                }
            });
        }
    });
}
</script>
</body>
</html>
