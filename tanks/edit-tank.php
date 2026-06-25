<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: tanks-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $tank_name        = mysqli_real_escape_string($connection, $_POST['tank_name']);
    $storage_capacity = mysqli_real_escape_string($connection, $_POST['storage_capacity']);
    $item_id          = mysqli_real_escape_string($connection, $_POST['item_id']);

    mysqli_begin_transaction($connection);
    try {
        // Update tank details
        $query = "UPDATE tbl_tanks SET tank_name='$tank_name', storage_capacity='$storage_capacity', item_id='$item_id' WHERE id='$id'";
        mysqli_query($connection, $query);

        // Delete old dip chart entries
        mysqli_query($connection, "DELETE FROM tbl_tank_dip_charts WHERE tank_id='$id'");

        // Insert new dip chart entries
        if (isset($_POST['dip_labels']) && is_array($_POST['dip_labels'])) {
            foreach ($_POST['dip_labels'] as $idx => $label) {
                $label = mysqli_real_escape_string($connection, $label);
                $value = floatval($_POST['dip_values'][$idx]);
                if (trim($label) !== '') {
                    $insert_dip = "INSERT INTO tbl_tank_dip_charts (tank_id, dip_label, dip_value) VALUES ('$id', '$label', '$value')";
                    mysqli_query($connection, $insert_dip);
                }
            }
        }

        mysqli_commit($connection);
        header('Location: tanks-list.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error updating tank: ' . $e->getMessage() . '</div>';
    }
}

// Fetch existing tank data
$sql  = "SELECT * FROM tbl_tanks WHERE id='$id'";
$res  = mysqli_query($connection, $sql);
$tank = mysqli_fetch_assoc($res);
if (!$tank) {
    header('Location: tanks-list.php');
    exit;
}

// Fetch dip chart entries
$dip_sql = "SELECT * FROM tbl_tank_dip_charts WHERE tank_id='$id' ORDER BY id ASC";
$dip_res = mysqli_query($connection, $dip_sql);
$dip_entries = [];
if ($dip_res) {
    while ($row = mysqli_fetch_assoc($dip_res)) {
        $dip_entries[] = $row;
    }
}

// Fetch items for dropdown
$items_sql    = "SELECT id, name FROM tbl_items ORDER BY name ASC";
$items_result = mysqli_query($connection, $items_sql);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top  { margin-top: 20px; }
		.txt-center { text-align: center; }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
		</style>
		<title>PPMS Edit Tank</title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-tank.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Tank</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Tank Name</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="tank_name" class="form-control" value="<?php echo htmlspecialchars($tank['tank_name']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Item</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<select name="item_id" class="form-control" required>
												<option value="">-- Select Item --</option>
												<?php
												if ($items_result && mysqli_num_rows($items_result) > 0) {
													while ($item = mysqli_fetch_assoc($items_result)) {
														$selected = ($item['id'] == $tank['item_id']) ? 'selected' : '';
														echo '<option value="'.$item['id'].'" '.$selected.'>'.htmlspecialchars($item['name']).'</option>';
													}
												}
												?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Storage Capacity</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="storage_capacity" class="form-control" value="<?php echo htmlspecialchars($tank['storage_capacity']); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<!-- Tank Dip Chart Card -->
					<div class="card mb-5">
						<div class="card-header bg-dark text-white d-flex justify-content-between align-items-center" style="background: var(--primary-color) !important;">
							<h5 class="mb-0 text-white"><i class="fas fa-chart-line mr-2"></i>Tank Dip Chart</h5>
							<button type="button" class="btn btn-sm btn-light font-weight-bold" id="btnShowAddDipForm" style="color: var(--primary-color);">
								<i class="fas fa-plus mr-1"></i> Add Dip Chart
							</button>
						</div>
						<div class="card-body">
							<!-- Hidden Form to add new entry -->
							<div id="addDipFormContainer" style="display:none;" class="border p-3 rounded mb-4 bg-light">
								<h6 class="font-weight-bold mb-3"><i class="fas fa-plus-circle mr-1"></i> Add Dip Chart Entry</h6>
								<div class="row align-items-end">
									<div class="col-md-5">
										<div class="form-group mb-0">
											<label class="font-weight-bold small text-secondary">Dip Label / Reading (e.g. 10 cm, 5 inches)</label>
											<input type="text" id="new_dip_label" class="form-control form-control-sm" placeholder="e.g. 10 cm">
										</div>
									</div>
									<div class="col-md-5">
										<div class="form-group mb-0">
											<label class="font-weight-bold small text-secondary">Dip Value / Volume (e.g. 500.00)</label>
											<input type="number" step="0.01" id="new_dip_value" class="form-control form-control-sm" placeholder="e.g. 500.00">
										</div>
									</div>
									<div class="col-md-2 text-right">
										<button type="button" class="btn btn-sm btn-primary w-100" id="btnAddDipEntry">Add Row</button>
									</div>
								</div>
								<div id="dip_form_error" class="text-danger small mt-2" style="display:none;"></div>
							</div>

							<!-- Table displaying current entries -->
							<table class="table table-striped table-bordered mb-0" id="dipChartTable">
								<thead>
									<tr>
										<th>Dip Label (Key)</th>
										<th>Dip Value (Volume)</th>
										<th style="width: 80px; text-align:center;">Action</th>
									</tr>
								</thead>
								<tbody id="dipChartBody">
									<?php 
									if (!empty($dip_entries)) {
										foreach ($dip_entries as $idx => $entry) {
											echo '
											<tr id="dip_row_' . $idx . '">
												<td>
													<input type="text" name="dip_labels[]" class="form-control form-control-sm font-weight-bold" value="' . htmlspecialchars($entry['dip_label']) . '" readonly style="background:#f8f9fa;">
												</td>
												<td>
													<input type="number" step="0.01" name="dip_values[]" class="form-control form-control-sm font-weight-bold text-success" value="' . htmlspecialchars($entry['dip_value']) . '" readonly style="background:#f8f9fa;">
												</td>
												<td class="text-center" style="vertical-align:middle;">
													<button type="button" class="btn btn-link btn-sm text-danger p-0" onclick="removeDipRow(' . $idx . ')"><i class="fas fa-trash-alt" style="font-size:16px;"></i></button>
												</td>
											</tr>';
										}
									} else {
										echo '<tr id="no_dip_placeholder"><td colspan="3" class="text-center text-muted">No dip chart entries added yet. Click "Add Dip Chart" to insert entries.</td></tr>';
									}
									?>
								</tbody>
							</table>
						</div>
					</div>

					<div class="txt-center">
						<input type="submit" name="submit" value="Save Tank" class="btn btn-primary m-top">
                        <a href="tanks-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script>
	var dipRowCount = <?php echo count($dip_entries); ?>;

	$('#btnShowAddDipForm').on('click', function() {
		$('#addDipFormContainer').slideToggle();
	});

	$('#btnAddDipEntry').on('click', function() {
		var label = $.trim($('#new_dip_label').val());
		var value = parseFloat($('#new_dip_value').val());
		
		if (label === '') {
			$('#dip_form_error').text('Please enter a valid dip label.').show();
			return;
		}
		if (isNaN(value) || value < 0) {
			$('#dip_form_error').text('Please enter a valid positive volume/value.').show();
			return;
		}
		
		$('#dip_form_error').hide();
		$('#no_dip_placeholder').remove();
		
		var idx = dipRowCount++;
		var rowHtml = `
			<tr id="dip_row_${idx}">
				<td>
					<input type="text" name="dip_labels[]" class="form-control form-control-sm font-weight-bold" value="${label}" readonly style="background:#f8f9fa;">
				</td>
				<td>
					<input type="number" step="0.01" name="dip_values[]" class="form-control form-control-sm font-weight-bold text-success" value="${value.toFixed(2)}" readonly style="background:#f8f9fa;">
				</td>
				<td class="text-center" style="vertical-align:middle;">
					<button type="button" class="btn btn-link btn-sm text-danger p-0" onclick="removeDipRow(${idx})"><i class="fas fa-trash-alt" style="font-size:16px;"></i></button>
				</td>
			</tr>
		`;
		
		$('#dipChartBody').append(rowHtml);
		
		// Clear inputs
		$('#new_dip_label').val('');
		$('#new_dip_value').val('');
		
		// Auto-hide form container
		$('#addDipFormContainer').slideUp();
	});

	function removeDipRow(index) {
		$('#dip_row_' + index).remove();
		if ($('#dipChartBody tr').length === 0) {
			$('#dipChartBody').append('<tr id="no_dip_placeholder"><td colspan="3" class="text-center text-muted">No dip chart entries added yet. Click "Add Dip Chart" to insert entries.</td></tr>');
		}
	}
	</script>
</html>
