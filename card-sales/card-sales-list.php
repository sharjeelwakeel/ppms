<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

check_access('card_sales', 'show');

// Date filter handling
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';

$where = "(mrcs.deleted_at IS NULL OR mrcs.deleted_at = '0000-00-00 00:00:00')";
if (!empty($from_date)) {
    $from_safe = mysqli_real_escape_string($connection, $from_date);
    $where .= " AND mrcs.sale_date >= '$from_safe'";
}
if (!empty($to_date)) {
    $to_safe = mysqli_real_escape_string($connection, $to_date);
    $where .= " AND mrcs.sale_date <= '$to_safe'";
}

// Group by sale_date and shift_id to show date & shift with totals
$sql_daily = "SELECT 
                mrcs.sale_date,
                mrcs.shift_id,
                sh.name AS shift_name,
                COUNT(mrcs.id) AS total_entries,
                SUM(mrcs.no_of_cards) AS total_cards,
                SUM(mrcs.amount) AS total_gross,
                SUM(mrcs.service_charges) AS total_charges,
                SUM(mrcs.net_amount) AS total_net
              FROM tbl_meter_reading_card_sales mrcs
              LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
              WHERE $where
              GROUP BY mrcs.sale_date, mrcs.shift_id
              ORDER BY mrcs.sale_date DESC, mrcs.shift_id ASC";
$result_daily = mysqli_query($connection, $sql_daily);

// Fetch itemized details for instant modal viewing
$sql_all_cards = "SELECT mrcs.*,
                         sh.name AS shift_name,
                         cm.name AS machine_name,
                         cm.charges_percentage,
                         n.name AS nozzle_name,
                         i.name AS item_name
                  FROM tbl_meter_reading_card_sales mrcs
                  LEFT JOIN tbl_shifts sh ON (mrcs.shift_id = sh.id)
                  LEFT JOIN tbl_card_machines cm ON (mrcs.card_machine_id = cm.id)
                  LEFT JOIN tbl_nozzles n ON (mrcs.nozzle_id = n.id)
                  LEFT JOIN tbl_items i ON (mrcs.item_id = i.id)
                  WHERE $where
                  ORDER BY mrcs.sale_date DESC, mrcs.shift_id ASC, mrcs.id ASC";
$res_cards = mysqli_query($connection, $sql_all_cards);
$cards_by_date = [];
if ($res_cards) {
    while ($row = mysqli_fetch_assoc($res_cards)) {
        $key = $row['sale_date'] . '_' . intval($row['shift_id']);
        if (!isset($cards_by_date[$key])) {
            $cards_by_date[$key] = [];
        }
        $cards_by_date[$key][] = $row;
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
    <title>PPMS - Card Sale Reading</title>
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
        #cardSalesTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        #cardSalesTable tbody tr:hover { background: var(--primary-light); }
        #cardSalesTable td { vertical-align:middle; font-size:13px; }
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
            <h4><i class="fas fa-credit-card mr-2 text-warning"></i> Card Sale Reading</h4>
            <small class="text-white-50">Track bank POS terminal swipes, batch numbers, bank service charges, and net receivables</small>
        </div>
        <?php if (has_permission('card_sales', 'add')): ?>
        <a href="add-card-sale.php" class="btn btn-new">
            <i class="fas fa-plus"></i> Add Card Sale
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
                    <a href="card-sales-list.php" class="btn btn-outline-secondary btn-sm mb-2 mb-md-0">
                        <i class="fas fa-times mr-1"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
                <div class="text-muted small mt-2 mt-md-0">
                    <i class="fas fa-info-circle mr-1 text-primary"></i> Grouped by daily date with total swipe amounts &amp; net bank settlements
                </div>
            </form>
        </div>
    </div>

    <!-- Main List Card -->
    <div class="list-card">
        <div class="list-card-title d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list mr-2"></i> Daily Card Sales Statements</span>
        </div>
        <div class="p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-center mb-0" id="cardSalesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th class="col-date">Date</th>
                            <th>Shift</th>
                            <th>Batches / Entries</th>
                            <th>Total Swipes (Cards)</th>
                            <th>Gross Card Sale (Rs.)</th>
                            <th>Service Charges (Rs.)</th>
                            <th>Net Bank Receivable (Rs.)</th>
                            <th style="width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        if ($result_daily && mysqli_num_rows($result_daily) > 0):
                            while ($row = mysqli_fetch_assoc($result_daily)): 
                                $dateVal = $row['sale_date'];
                                $shiftId = intval($row['shift_id'] ?? 0);
                                $shiftName = !empty($row['shift_name']) ? $row['shift_name'] : 'General';
                                $displayDate = date('d-m-Y', strtotime($dateVal));
                        ?>
                        <tr>
                            <td class="font-weight-bold text-muted"><?php echo $counter++; ?></td>
                            <td class="col-date text-nowrap">
                                <?php if (has_permission('card_sales', 'edit')): ?>
                                    <a href="edit-card-sale.php?date=<?php echo urlencode($dateVal); ?>&shift_id=<?php echo $shiftId; ?>" class="font-weight-bold text-nowrap" style="color:var(--primary-color); text-decoration:underline; font-size: 13.5px;" title="Click to Edit Card Sales for <?php echo $displayDate; ?> (<?php echo htmlspecialchars($shiftName); ?>)">
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
                                <span class="badge badge-secondary px-2 py-1">
                                    <?php echo intval($row['total_entries']); ?> Batch Entries
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-info px-2 py-1 font-weight-bold">
                                    <i class="fas fa-credit-card mr-1"></i><?php echo intval($row['total_cards']); ?> Cards
                                </span>
                            </td>
                            <td class="font-weight-bold text-dark">
                                Rs. <?php echo number_format($row['total_gross'], 2); ?>
                            </td>
                            <td class="text-danger font-weight-bold">
                                -Rs. <?php echo number_format($row['total_charges'], 2); ?>
                            </td>
                            <td>
                                <strong class="text-success" style="font-size: 14px;">
                                    Rs. <?php echo number_format($row['total_net'], 2); ?>
                                </strong>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <!-- PDF Statement -->
                                    <a href="generate-pdf-card-sale.php?date=<?php echo urlencode($dateVal); ?>&shift_id=<?php echo $shiftId; ?>" target="_blank" class="btn btn-secondary" style="background:#04204e; border-color:#04204e;" title="Download / Print PDF">
                                        <i class="fas fa-file-pdf text-danger"></i>
                                    </a>

                                    <?php if (has_permission('card_sales', 'delete')): ?>
                                    <!-- Delete Day Transactions -->
                                    <button type="button" class="btn btn-danger" onclick="deleteDayCards('<?php echo $dateVal; ?>', <?php echo $shiftId; ?>, '<?php echo $displayDate; ?>', '<?php echo addslashes($shiftName); ?>')" title="Delete All Card Sales for this Shift">
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

<!-- Modal for Viewing Day Card Swipes -->
<div class="modal fade" id="dayCardsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius:10px; overflow:hidden;">
            <div class="modal-header text-white" style="background: var(--gradient-header);">
                <h5 class="modal-title font-weight-bold" id="dayCardsModalTitle">
                    <i class="fas fa-credit-card mr-2 text-warning"></i> Card Machine Transactions for <span id="modalDateLabel"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-striped text-center mb-0" id="modalCardsTable" style="font-size:12.5px;">
                        <thead class="bg-light">
                            <tr>
                                <th>#</th>
                                <th>Card Machine (Bank)</th>
                                <th>Batch No</th>
                                <th>Nozzle / Item</th>
                                <th>No. of Cards</th>
                                <th>Gross Amount (Rs.)</th>
                                <th>Fee %</th>
                                <th>Service Charges (Rs.)</th>
                                <th>Net Receivable (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody id="modalCardsBody">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <a href="#" id="modalPdfBtn" target="_blank" class="btn btn-sm btn-secondary" style="background:#04204e; border-color:#04204e;">
                    <i class="fas fa-file-pdf text-danger mr-1"></i> Print PDF Statement
                </a>
                <a href="#" id="modalEditBtn" class="btn btn-sm btn-primary">
                    <i class="fas fa-edit mr-1"></i> Edit Transactions
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
var allCardsData = <?php echo json_encode($cards_by_date); ?>;

$(document).ready(function() {
    $('#cardSalesTable').DataTable({
        "order": [[ 0, "asc" ]],
        "pageLength": 15,
        "autoWidth": false,
        "columnDefs": [
            { "targets": 1, "width": "145px", "className": "col-date text-nowrap" }
        ],
        "language": {
            "emptyTable": "No card sales recorded yet."
        }
    });
});

function viewDayCards(rawDate, shiftId, formattedDate, shiftName) {
    $('#modalDateLabel').text(formattedDate + ' (' + shiftName + ')');
    $('#modalPdfBtn').attr('href', 'generate-pdf-card-sale.php?date=' + encodeURIComponent(rawDate) + '&shift_id=' + encodeURIComponent(shiftId));
    $('#modalEditBtn').attr('href', 'edit-card-sale.php?date=' + encodeURIComponent(rawDate) + '&shift_id=' + encodeURIComponent(shiftId));
    
    var key = rawDate + '_' + shiftId;
    var items = allCardsData[key] || [];
    var html = '';
    
    if (items.length === 0) {
        html = '<tr><td colspan="9" class="text-muted py-3">No card transaction details available.</td></tr>';
    } else {
        var totCards = 0, totGross = 0, totFee = 0, totNet = 0;
        for (var i = 0; i < items.length; i++) {
            var c = items[i];
            var cards = parseInt(c.no_of_cards) || 0;
            var gross = parseFloat(c.amount) || 0;
            var fee   = parseFloat(c.service_charges) || 0;
            var net   = parseFloat(c.net_amount) || 0;
            totCards += cards; totGross += gross; totFee += fee; totNet += net;
            
            var feePct = c.charges_percentage ? parseFloat(c.charges_percentage).toFixed(4) + '%' : '—';

            html += '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td><strong class="text-primary">' + (c.machine_name || 'Machine #' + c.card_machine_id) + '</strong></td>' +
                '<td class="font-weight-bold text-monospace">' + (c.batch_no || '—') + '</td>' +
                '<td>' + (c.nozzle_name || 'Nozzle') + ' <small class="text-muted">(' + (c.item_name || 'Fuel') + ')</small></td>' +
                '<td><span class="badge badge-info">' + cards + '</span></td>' +
                '<td class="font-weight-bold">Rs. ' + gross.toFixed(2) + '</td>' +
                '<td class="text-muted small">' + feePct + '</td>' +
                '<td class="text-danger font-weight-bold">Rs. ' + fee.toFixed(2) + '</td>' +
                '<td class="text-success font-weight-bold">Rs. ' + net.toFixed(2) + '</td>' +
            '</tr>';
        }
        
        html += '<tr class="bg-light font-weight-bold" style="font-size:13px;">' +
            '<td colspan="4" class="text-right">SHIFT TOTALS:</td>' +
            '<td class="text-info">' + totCards + ' Cards</td>' +
            '<td>Rs. ' + totGross.toFixed(2) + '</td>' +
            '<td>—</td>' +
            '<td class="text-danger">Rs. ' + totFee.toFixed(2) + '</td>' +
            '<td class="text-success">Rs. ' + totNet.toFixed(2) + '</td>' +
        '</tr>';
    }
    
    $('#modalCardsBody').html(html);
    $('#dayCardsModal').modal('show');
}

function deleteDayCards(rawDate, shiftId, formattedDate, shiftName) {
    Swal.fire({
        title: 'Delete Card Sales?',
        html: 'Are you sure you want to delete all card sales recorded for <strong>' + formattedDate + ' (' + shiftName + ')</strong>?<br><small class="text-danger">This will soft-delete all card machine transactions for this shift.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Yes, Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: '../include/deletecardsale.php',
                type: 'POST',
                data: { date: rawDate, shift_id: shiftId },
                success: function(response) {
                    Swal.fire('Deleted!', 'Card sales for ' + formattedDate + ' (' + shiftName + ') have been deleted.', 'success')
                        .then(function() {
                            location.reload();
                        });
                },
                error: function() {
                    Swal.fire('Error', 'Unable to delete card sales.', 'error');
                }
            });
        }
    });
}
</script>
</body>
</html>
