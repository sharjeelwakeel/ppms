<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('credit_sales', 'show');

// Date filter handling
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$where = "(mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND mrcs.slip_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND mrcs.slip_date <= '$to_safe'";
}

// Group by slip_date and shift_id to show date & shift with totals
$sql_daily = "SELECT 
                mrcs.slip_date,
                mrcs.shift_id,
                sh.name AS shift_name,
                COUNT(mrcs.id) AS total_slips,
                SUM(mrcs.quantity) AS total_qty,
                SUM(mrcs.amount) AS total_amount,
                SUM(CASE 
                      WHEN mrcs.slip_type = 'Balanced Slip' THEN 0 
                      WHEN mrcs.slip_type = 'Temporary Slip' AND mrcs.is_returned = 1 THEN 0
                      ELSE COALESCE(mrcs.charge_amount, mrcs.amount) 
                    END) AS total_charge,
                SUM(CASE WHEN mrcs.slip_type = 'Temporary Slip' AND mrcs.is_returned = 0 THEN mrcs.quantity ELSE 0 END) AS giving_loan_qty,
                SUM(CASE WHEN mrcs.slip_type = 'Temporary Slip' AND mrcs.is_returned = 0 THEN mrcs.charge_amount ELSE 0 END) AS giving_loan_charge,
                SUM(CASE WHEN mrcs.slip_type = 'Temporary Slip' AND mrcs.is_returned = 1 THEN mrcs.quantity ELSE 0 END) AS received_loan_qty
              FROM tbl_meter_reading_credit_sales mrcs
              LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
              WHERE $where
              GROUP BY mrcs.slip_date, mrcs.shift_id
              ORDER BY mrcs.slip_date DESC, mrcs.shift_id ASC";
$result_daily = mysqli_query($connection, $sql_daily);

// Fetch all slips to support instant detail viewing in modal
$sql_all_slips = "SELECT mrcs.*, 
                         sh.name AS shift_name,
                         c.name AS customer_name,
                         n.name AS nozzle_name,
                         i.name AS item_name
                  FROM tbl_meter_reading_credit_sales mrcs
                  LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
                  LEFT JOIN tbl_customers c ON (mrcs.account_number = c.id)
                  LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
                  LEFT JOIN tbl_items i ON (n.item_id = i.id)
                  WHERE $where
                  ORDER BY mrcs.slip_date DESC, mrcs.shift_id ASC, mrcs.id ASC";
$res_slips = mysqli_query($connection, $sql_all_slips);
$slips_by_date = [];
if ($res_slips) {
    while ($row = mysqli_fetch_assoc($res_slips)) {
        $key = $row['slip_date'] . '_' . intval($row['shift_id']);
        if (!isset($slips_by_date[$key])) {
            $slips_by_date[$key] = [];
        }
        $slips_by_date[$key][] = $row;
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
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Credit Sale Reading</title>
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
        #creditSalesTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        #creditSalesTable tbody tr:hover { background: var(--primary-light); }
        #creditSalesTable td { vertical-align:middle; font-size:13px; }
        .col-date {
            min-width: 145px !important;
            width: 145px !important;
            white-space: nowrap !important;
        }
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
    <div class="page-header">
        <div>
            <h4><i class="fas fa-file-invoice-dollar mr-2 text-warning"></i> Credit Sale Reading</h4>
            <small class="text-white-50">Manage customer credit sales, permanent/balanced slips, and loan fuel reconciliation</small>
        </div>
        <?php if (has_permission('credit_sales', 'add')): ?>
        <a href="add-credit-sale.php" class="btn btn-new">
            <i class="fas fa-plus"></i> Add Credit Sale
        </a>
        <?php endif; ?>
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
                    <a href="credit-sales-list.php" class="btn btn-outline-secondary btn-sm mb-2 mb-md-0">
                        <i class="fas fa-times mr-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-2 mt-md-0">
                    <i class="fas fa-info-circle mr-1 text-primary"></i> Grouped by daily date with total fuel &amp; billable charges
                </div>
            </form>
        </div>
    </div>

    <!-- Main List Card -->
    <div class="list-card">
        <div class="list-card-title d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list mr-2"></i> Daily Credit Sales Statements</span>
        </div>
        <div class="p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-center mb-0" id="creditSalesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th class="col-date">Date</th>
                            <th>Shift</th>
                            <th>Total Slips</th>
                            <th>Total Dispensed (Ltr)</th>
                            <th>Fuel Amount (Rs.)</th>
                            <th>Total Billable Charge (Rs.)</th>
                            <th>Giving Loan (Ltr)</th>
                            <th>Received (Ltr)</th>
                            <th style="width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        if ($result_daily && mysqli_num_rows($result_daily) > 0):
                            while ($row = mysqli_fetch_assoc($result_daily)): 
                                $dateVal = $row['slip_date'];
                                $shiftId = intval($row['shift_id'] ?? 0);
                                $shiftName = !empty($row['shift_name']) ? $row['shift_name'] : 'General';
                                $displayDate = date('d-m-Y', strtotime($dateVal));
                        ?>
                        <tr>
                            <td class="font-weight-bold text-muted"><?php echo $counter++; ?></td>
                            <td class="col-date text-nowrap">
                                <?php if (has_permission('credit_sales', 'edit')): ?>
                                    <a href="edit-credit-sale.php?date=<?php echo urlencode($dateVal); ?>&shift_id=<?php echo $shiftId; ?>" class="font-weight-bold text-nowrap" style="color:var(--primary-color); text-decoration:underline; font-size: 13.5px;" title="Click to Edit Credit Slips for <?php echo $displayDate; ?> (<?php echo htmlspecialchars($shiftName); ?>)">
                                        <i class="fas fa-calendar-day mr-1 text-muted"></i><?php echo $displayDate; ?>
                                    </a>
                                <?php else: ?>
                                    <strong class="text-primary font-weight-bold text-nowrap" style="font-size: 13.5px;">
                                        <i class="fas fa-calendar-day mr-1 text-muted"></i><?php echo $displayDate; ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary px-2 py-1 font-weight-bold" style="font-size: 11.5px; background:linear-gradient(135deg,#04204e,#07347a);">
                                    <i class="fas fa-clock mr-1"></i><?php echo htmlspecialchars($shiftName); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                    <i class="fas fa-receipt mr-1"></i><?php echo intval($row['total_slips']); ?> Slips
                                </span>
                            </td>
                            <td class="font-weight-bold text-dark">
                                <?php echo number_format($row['total_qty'], 2); ?> Ltr
                            </td>
                            <td class="text-muted font-weight-bold">
                                Rs. <?php echo number_format($row['total_amount'], 2); ?>
                            </td>
                            <td>
                                <strong class="text-danger" style="font-size: 13.5px;">
                                    Rs. <?php echo number_format($row['total_charge'], 2); ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($row['giving_loan_qty'] > 0): ?>
                                    <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold" title="Unpaid loan fuel to collect">
                                        <i class="fas fa-hand-holding mr-1"></i><?php echo number_format($row['giving_loan_qty'], 2); ?> Ltr
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['received_loan_qty'] > 0): ?>
                                    <span class="badge badge-success px-2 py-1 font-weight-bold text-white">
                                        <i class="fas fa-check-circle mr-1"></i><?php echo number_format($row['received_loan_qty'], 2); ?> Ltr
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <!-- PDF Statement -->
                                    <a href="generate-pdf-credit-sale.php?date=<?php echo urlencode($dateVal); ?>&shift_id=<?php echo $shiftId; ?>" target="_blank" class="btn btn-secondary" style="background:#04204e; border-color:#04204e;" title="Download / Print PDF">
                                        <i class="fas fa-file-pdf text-danger"></i>
                                    </a>

                                    <?php if (has_permission('credit_sales', 'delete')): ?>
                                    <!-- Delete Day Slips -->
                                    <button type="button" class="btn btn-danger" onclick="deleteDaySlips('<?php echo $dateVal; ?>', <?php echo $shiftId; ?>, '<?php echo $displayDate; ?>', '<?php echo addslashes($shiftName); ?>')" title="Delete All Slips for this Shift">
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

<!-- Modal for Viewing Day Slips -->
<div class="modal fade" id="daySlipsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius:10px; overflow:hidden;">
            <div class="modal-header text-white" style="background: var(--gradient-header);">
                <h5 class="modal-title font-weight-bold" id="daySlipsModalTitle">
                    <i class="fas fa-receipt mr-2 text-warning"></i> Credit Slips for <span id="modalDateLabel"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-striped text-center mb-0" id="modalSlipsTable" style="font-size:12.5px;">
                        <thead class="bg-light">
                            <tr>
                                <th>#</th>
                                <th>Slip No</th>
                                <th>Slip Type</th>
                                <th>Customer Account</th>
                                <th>Vehicle No</th>
                                <th>Nozzle / Item</th>
                                <th>Qty (Ltr)</th>
                                <th>Rate (Rs.)</th>
                                <th>Amount (Rs.)</th>
                                <th>Charge (Rs.)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="modalSlipsBody">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <a href="#" id="modalPdfBtn" target="_blank" class="btn btn-sm btn-secondary" style="background:#04204e; border-color:#04204e;">
                    <i class="fas fa-file-pdf text-danger mr-1"></i> Print PDF Statement
                </a>
                <a href="#" id="modalEditBtn" class="btn btn-sm btn-primary">
                    <i class="fas fa-edit mr-1"></i> Edit Slips
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Close</button>
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
var allSlipsData = <?php echo json_encode($slips_by_date); ?>;

$(document).ready(function() {
    $('#creditSalesTable').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 15,
        "autoWidth": false,
        "columnDefs": [
            { "targets": 1, "width": "145px", "className": "col-date text-nowrap" }
        ],
        "language": {
            "emptyTable": "No credit sales recorded yet."
        }
    });
});

function viewDaySlips(rawDate, shiftId, formattedDate, shiftName) {
    $('#modalDateLabel').text(formattedDate + ' (' + shiftName + ')');
    $('#modalPdfBtn').attr('href', 'generate-pdf-credit-sale.php?date=' + encodeURIComponent(rawDate) + '&shift_id=' + encodeURIComponent(shiftId));
    $('#modalEditBtn').attr('href', 'edit-credit-sale.php?date=' + encodeURIComponent(rawDate) + '&shift_id=' + encodeURIComponent(shiftId));
    
    var key = rawDate + '_' + shiftId;
    var slips = allSlipsData[key] || [];
    var html = '';
    
    if (slips.length === 0) {
        html = '<tr><td colspan="11" class="text-muted py-3">No slip details available.</td></tr>';
    } else {
        var totQty = 0, totAmt = 0, totChg = 0;
        for (var i = 0; i < slips.length; i++) {
            var s = slips[i];
            var q = parseFloat(s.quantity) || 0;
            var a = parseFloat(s.amount) || 0;
            var c = parseFloat(s.charge_amount) || 0;
            totQty += q; totAmt += a; totChg += c;
            
            var typeBadge = '<span class="badge badge-primary">Permanent</span>';
            if (s.slip_type === 'Balanced Slip') {
                typeBadge = '<span class="badge badge-info">Balanced</span>';
            } else if (s.slip_type === 'Temporary Slip') {
                typeBadge = '<span class="badge badge-warning text-dark">Temporary</span>';
            }
            
            var statusBadge = '';
            if (s.slip_type === 'Temporary Slip') {
                if (parseInt(s.is_returned) === 1) {
                    statusBadge = '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Received (Settled)</span>';
                } else {
                    statusBadge = '<span class="badge badge-warning text-dark"><i class="fas fa-hand-holding mr-1"></i>Giving (Loan)</span>';
                }
            } else if (s.slip_type === 'Balanced Slip') {
                statusBadge = '<span class="badge badge-light border">Pre-Paid</span>';
            } else {
                statusBadge = '<span class="badge badge-secondary">Billed</span>';
            }
            
            html += '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td class="font-weight-bold text-monospace">' + (s.slip_no || '—') + '</td>' +
                '<td>' + typeBadge + '</td>' +
                '<td>' + (s.customer_name ? ('<strong>' + s.customer_name + '</strong> <small class="text-muted">(#' + s.account_number + ')</small>') : ('#' + s.account_number)) + '</td>' +
                '<td class="font-weight-bold text-monospace">' + (s.vehicle_number || '—') + '</td>' +
                '<td>' + (s.nozzle_name || 'Nozzle') + ' <small class="text-muted">(' + (s.item_name || 'Fuel') + ')</small></td>' +
                '<td class="font-weight-bold">' + q.toFixed(2) + '</td>' +
                '<td>' + (parseFloat(s.rate) || 0).toFixed(2) + '</td>' +
                '<td>Rs. ' + a.toFixed(2) + '</td>' +
                '<td class="font-weight-bold text-danger">Rs. ' + c.toFixed(2) + '</td>' +
                '<td>' + statusBadge + '</td>' +
            '</tr>';
        }
        
        html += '<tr class="bg-light font-weight-bold" style="font-size:13px;">' +
            '<td colspan="6" class="text-right">SHIFT TOTALS:</td>' +
            '<td class="text-primary">' + totQty.toFixed(2) + ' Ltr</td>' +
            '<td>—</td>' +
            '<td>Rs. ' + totAmt.toFixed(2) + '</td>' +
            '<td class="text-danger">Rs. ' + totChg.toFixed(2) + '</td>' +
            '<td></td>' +
        '</tr>';
    }
    
    $('#modalSlipsBody').html(html);
    $('#daySlipsModal').modal('show');
}

function deleteDaySlips(rawDate, shiftId, formattedDate, shiftName) {
    Swal.fire({
        title: 'Delete Credit Sales?',
        html: 'Are you sure you want to delete all credit sales recorded for <strong>' + formattedDate + ' (' + shiftName + ')</strong>?<br><small class="text-danger">This will soft-delete all credit slips for this shift.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: '../include/deletecreditsale.php',
                type: 'POST',
                data: { date: rawDate, shift_id: shiftId },
                success: function(response) {
                    Swal.fire('Deleted!', 'Credit sales for ' + formattedDate + ' (' + shiftName + ') have been deleted.', 'success')
                        .then(function() {
                            location.reload();
                        });
                },
                error: function() {
                    Swal.fire('Error', 'Unable to delete credit sales.', 'error');
                }
            });
        }
    });
}
</script>
</body>
</html>
