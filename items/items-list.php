<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';
require_once '../include/price_helper.php';

// Enforce access check for viewing items
check_access('items', 'show');

// Auto-migrate tbl_items if missing deleted_at
$chk_id = mysqli_query($connection, "SHOW COLUMNS FROM tbl_items LIKE 'deleted_at'");
if ($chk_id && mysqli_num_rows($chk_id) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_items ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

// Self-healing initialization of polymorphic tbl_prices and seed baseline
init_prices_table($connection);

$canAdd    = has_permission('items', 'add');
$canEdit   = has_permission('items', 'edit');
$canDelete = has_permission('items', 'delete');

// Handle Price Revision from Modal
$alert_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_price') {
    check_access('items', 'edit');
    $up_item_id   = intval($_POST['item_id'] ?? 0);
    $up_cash      = floatval($_POST['cash_rate'] ?? 0);
    $up_credit    = floatval($_POST['credit_rate'] ?? 0);
    $up_purchase  = floatval($_POST['purchase_rate'] ?? 0);
    $up_date      = !empty($_POST['effective_date']) ? mysqli_real_escape_string($connection, $_POST['effective_date']) : date('Y-m-d');
    $up_notes     = !empty($_POST['notes']) ? mysqli_real_escape_string($connection, $_POST['notes']) : 'Price revised from items list';
    $user_id      = intval($_SESSION['loggedInUser'] ?? 0);

    if ($up_item_id > 0) {
        if (set_active_price($connection, 'tbl_items', $up_item_id, $up_cash, $up_credit, $up_purchase, $up_date, $up_notes, $user_id)) {
            $alert_msg = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i> Fuel price successfully updated and activated.
                            <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
                          </div>';
        } else {
            $alert_msg = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i> Error updating price: ' . mysqli_error($connection) . '
                            <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
                          </div>';
        }
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
        #itemsListTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
		</style>
		<title>PPMS - Items / Fuel Products</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
                <?php echo $alert_msg; ?>
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-boxes mr-2 text-primary"></i>View Items / Fuel Products</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if ($canAdd): ?>
						<a href="add-item.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Item</a>
                        <?php endif; ?>
					</div>
				</div>
                <div class="table-responsive">
                    <table id="itemsListTable" class="table table-striped table-bordered text-center mb-0">
                        <thead>
                            <tr>
                                <th style="width: 50px;">ID</th>
                                <th>Name</th>
                                <th style="width: 70px;">Unit</th>
                                <th>Active Cash Rate</th>
                                <th>Active Credit Rate</th>
                                <th>Purchase Rate</th>
                                <th style="min-width: 200px;">Price History &amp; Actions</th>
                                <?php if ($canDelete): ?>
                                <th style="width: 60px;">Delete</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sql = "SELECT * FROM tbl_items WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC";
                            $result = mysqli_query($connection, $sql);
                            if($result && mysqli_num_rows($result) > 0){
                                while($row = mysqli_fetch_assoc($result)){
                                    $itemNameDisplay = $canEdit 
                                        ? '<a href="edit-item.php?id='.$row['id'].'" class="font-weight-bold" style="color: var(--primary-color);">'.htmlspecialchars($row['name']).'</a>'
                                        : '<strong>'.htmlspecialchars($row['name']).'</strong>';
                                    echo' 
                                        <tr>
                                            <td>'.$row['id'].'</td>
                                            <td class="text-left">'.$itemNameDisplay.'</td>
                                            <td><span class="badge badge-light border">'.htmlspecialchars($row['unit']).'</span></td>
                                            <td class="font-weight-bold text-success">Rs. '.number_format($row['cash_rate'], 2).'</td>
                                            <td class="font-weight-bold text-primary">Rs. '.number_format($row['credit_rate'], 2).'</td>
                                            <td class="font-weight-bold text-muted">Rs. '.number_format($row['purchase_rate'], 2).'</td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-info font-weight-bold" onclick="openPriceHistoryModal('.$row['id'].', \''.addslashes($row['name']).'\')" title="View Price History">
                                                        <i class="fas fa-history mr-1"></i> History
                                                    </button>';
                                    if ($canEdit) {
                                        echo '      <button type="button" class="btn btn-outline-primary font-weight-bold" onclick="openUpdatePriceModal('.$row['id'].', \''.addslashes($row['name']).'\', '.$row['cash_rate'].', '.$row['credit_rate'].', '.$row['purchase_rate'].')" title="Revise Price">
                                                        <i class="fas fa-tag mr-1"></i> Revise Price
                                                    </button>';
                                    }
                                    echo '      </div>
                                            </td>';
                                    if ($canDelete) {
                                        echo '<td class="text-center"><a class="btn btn-large btn-link p-0 text-danger" onclick="deleteitem('.$row['id'].')" title="Delete Item"><i class="fas fa-trash-alt" style="font-size: 18px;"></i></a></td>';
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

        <!-- Modal: Update Price -->
        <div class="modal fade" id="updatePriceModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow" style="border-radius: 10px; overflow: hidden;">
                    <form method="POST" action="items-list.php">
                        <input type="hidden" name="action" value="update_price">
                        <input type="hidden" name="item_id" id="modal_item_id">
                        
                        <div class="modal-header text-white" style="background: var(--primary-gradient);">
                            <h5 class="modal-title font-weight-bold">
                                <i class="fas fa-tag mr-2 text-warning"></i> Revise Fuel Price: <span id="modal_item_name_title"></span>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="form-group">
                                <label class="font-weight-bold text-dark"><i class="fas fa-calendar-day mr-1 text-primary"></i> Effective Date <span class="text-danger">*</span></label>
                                <input type="date" name="effective_date" id="modal_effective_date" class="form-control font-weight-bold" value="<?php echo date('Y-m-d'); ?>" required>
                                <small class="text-muted">The calendar date from which this new price applies.</small>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label class="font-weight-bold text-success"><i class="fas fa-money-bill-wave mr-1"></i> Cash Rate (Rs.) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="cash_rate" id="modal_cash_rate" class="form-control font-weight-bold text-success" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="font-weight-bold text-primary"><i class="fas fa-file-invoice-dollar mr-1"></i> Credit Rate (Rs.) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" name="credit_rate" id="modal_credit_rate" class="form-control font-weight-bold text-primary" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold text-muted"><i class="fas fa-shopping-cart mr-1"></i> Purchase Rate (Rs.) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="purchase_rate" id="modal_purchase_rate" class="form-control font-weight-bold text-muted" required>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold text-dark"><i class="fas fa-sticky-note mr-1 text-info"></i> Revision Note / Reference</label>
                                <input type="text" name="notes" id="modal_notes" class="form-control" placeholder="e.g. Fortnightly revision notification #123">
                            </div>
                        </div>
                        <div class="modal-footer bg-light py-2">
                            <button type="button" class="btn btn-secondary btn-sm font-weight-bold" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm font-weight-bold px-3">
                                <i class="fas fa-save mr-1"></i> Save &amp; Activate Price
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal: Price History Timeline -->
        <div class="modal fade" id="priceHistoryModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content border-0 shadow" style="border-radius: 10px; overflow: hidden;">
                    <div class="modal-header text-white" style="background: var(--primary-gradient);">
                        <h5 class="modal-title font-weight-bold">
                            <i class="fas fa-history mr-2 text-warning"></i> Price History: <span id="histItemName"></span>
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 13px;">
                                <thead class="bg-light font-weight-bold">
                                    <tr>
                                        <th style="width: 45px;">#</th>
                                        <th>Effective Date</th>
                                        <th>Cash Rate</th>
                                        <th>Credit Rate</th>
                                        <th>Purchase Rate</th>
                                        <th>Status</th>
                                        <th>Notes / Remarks</th>
                                        <th>Recorded At</th>
                                        <?php if ($canDelete || $canEdit): ?>
                                        <th style="width: 50px;">Delete</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="priceHistoryTableBody">
                                    <tr><td colspan="9" class="py-3 text-muted">Loading price history...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer bg-light py-2">
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
    var canDeletePrice = <?php echo ($canDelete || $canEdit) ? 'true' : 'false'; ?>;
    window._priceChanged = false;

	$(document).ready(function() {
		$('#itemsListTable').DataTable({
			"order": [[ 0, "desc" ]]
		});

        $('#priceHistoryModal').on('hidden.bs.modal', function() {
            if (window._priceChanged) {
                location.reload();
            }
        });
	});

    function openUpdatePriceModal(itemId, itemName, cashRate, creditRate, purchaseRate) {
        $('#modal_item_id').val(itemId);
        $('#modal_item_name_title').text(itemName);
        $('#modal_cash_rate').val(parseFloat(cashRate).toFixed(2));
        $('#modal_credit_rate').val(parseFloat(creditRate).toFixed(2));
        $('#modal_purchase_rate').val(parseFloat(purchaseRate).toFixed(2));
        $('#modal_effective_date').val(new Date().toISOString().split('T')[0]);
        $('#modal_notes').val('');
        $('#updatePriceModal').modal('show');
    }

    function openPriceHistoryModal(itemId, itemName) {
        $('#histItemName').text(itemName);
        var colSpan = canDeletePrice ? 9 : 8;
        $('#priceHistoryTableBody').html('<tr><td colspan="' + colSpan + '" class="py-3 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading price history...</td></tr>');
        $('#priceHistoryModal').modal('show');

        $.ajax({
            url: 'get-price-history.php',
            type: 'GET',
            dataType: 'json',
            data: { table_name: 'tbl_items', table_id: itemId },
            success: function(res) {
                if (res.status === 'success' && res.data) {
                    if (res.data.length === 0) {
                        $('#priceHistoryTableBody').html('<tr><td colspan="' + colSpan + '" class="py-3 text-muted">No price history recorded yet.</td></tr>');
                    } else {
                        var html = '';
                        for (var i = 0; i < res.data.length; i++) {
                            var p = res.data[i];
                            var statusBadge = parseInt(p.is_active) === 1 
                                ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>'
                                : '<span class="badge badge-secondary px-2 py-1">Historical</span>';
                            
                            var cashDisplay = 'Rs. ' + parseFloat(p.cash_rate).toFixed(2);
                            var creditDisplay = 'Rs. ' + parseFloat(p.credit_rate).toFixed(2);
                            var purchDisplay = 'Rs. ' + parseFloat(p.purchase_rate).toFixed(2);
                            var notesDisplay = p.notes ? p.notes : '<span class="text-muted">—</span>';
                            var recordedAt = p.created_at ? p.created_at : '—';

                            var delBtn = '';
                            if (canDeletePrice) {
                                delBtn = '<td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Soft delete price record" onclick="deletePrice(' + p.id + ', ' + itemId + ', \'' + itemName.replace(/'/g, "\\'") + '\')"><i class="fas fa-trash-alt"></i></button></td>';
                            }

                            html += '<tr>' +
                                '<td>' + (i + 1) + '</td>' +
                                '<td class="font-weight-bold text-nowrap">' + p.effective_date + '</td>' +
                                '<td class="font-weight-bold text-success">' + cashDisplay + '</td>' +
                                '<td class="font-weight-bold text-primary">' + creditDisplay + '</td>' +
                                '<td class="font-weight-bold text-muted">' + purchDisplay + '</td>' +
                                '<td>' + statusBadge + '</td>' +
                                '<td class="text-left">' + notesDisplay + '</td>' +
                                '<td class="small text-muted text-nowrap">' + recordedAt + '</td>' +
                                delBtn +
                            '</tr>';
                        }
                        $('#priceHistoryTableBody').html(html);
                    }
                } else {
                    $('#priceHistoryTableBody').html('<tr><td colspan="' + colSpan + '" class="py-3 text-danger">Failed to load price history.</td></tr>');
                }
            },
            error: function() {
                $('#priceHistoryTableBody').html('<tr><td colspan="' + colSpan + '" class="py-3 text-danger">Error contacting server.</td></tr>');
            }
        });
    }

    function deletePrice(priceId, itemId, itemName) {
        if (confirm('Are you sure you want to delete this price record?')) {
            $.ajax({
                url: 'delete-price.php',
                type: 'POST',
                dataType: 'json',
                data: { id: priceId },
                success: function(res) {
                    if (res.status === 'success') {
                        window._priceChanged = true;
                        openPriceHistoryModal(itemId, itemName);
                    } else {
                        alert(res.message || 'Error deleting price record.');
                    }
                },
                error: function() {
                    alert('Error contacting server.');
                }
            });
        }
    }

	function deleteitem(id){
		if(confirm('Are you sure you want to delete this item?')) {
			$.ajax({
				type: "POST",
				url: "../include/deleteitem.php",
				data: {id: id},
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}
	</script>
</html>
