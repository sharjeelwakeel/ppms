<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding dip lookup
check_access('tanks', 'add');

$message = '';
$tank_capacity_val = '23500';
$dip_mm_val = '';
$dip_litre_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tank_capacity = isset($_POST['tank_capacity']) ? mysqli_real_escape_string($connection, trim($_POST['tank_capacity'])) : '23500';
    $dip_mm = isset($_POST['dip_mm']) ? mysqli_real_escape_string($connection, trim($_POST['dip_mm'])) : '';
    $dip_litre = isset($_POST['dip_litre']) ? mysqli_real_escape_string($connection, trim($_POST['dip_litre'])) : '';
    $update_id = isset($_POST['update_id']) ? mysqli_real_escape_string($connection, trim($_POST['update_id'])) : '';

    $tank_capacity_val = $tank_capacity;
    $dip_mm_val = $dip_mm;
    $dip_litre_val = $dip_litre;

    if ($dip_mm !== '' && $dip_litre !== '') {
        if (!empty($update_id)) {
            // Update confirmed existing record
            $query = "UPDATE tbl_dip_lookup SET tank_capacity = '$tank_capacity', dip_litre = '$dip_litre', updated_at = NOW() WHERE id = '$update_id'";
            if (mysqli_query($connection, $query)) {
                header('Location: dip-lookup-list.php?msg=updated');
                exit;
            } else {
                $message = '<div class="alert alert-danger">Error updating record: ' . mysqli_error($connection) . '</div>';
            }
        } else {
            // Check duplicate dip_mm + tank_capacity in active DB records
            $check_sql = "SELECT id, dip_mm, dip_litre FROM tbl_dip_lookup WHERE dip_mm = '$dip_mm' AND tank_capacity = '$tank_capacity' AND deleted_at IS NULL LIMIT 1";
            $check_res = mysqli_query($connection, $check_sql);

            if ($check_res && mysqli_num_rows($check_res) > 0) {
                // Backend duplicate found fallback if JS bypass
                $existing = mysqli_fetch_assoc($check_res);
                $query = "UPDATE tbl_dip_lookup SET tank_capacity = '$tank_capacity', dip_litre = '$dip_litre', updated_at = NOW() WHERE id = '" . $existing['id'] . "'";
                if (mysqli_query($connection, $query)) {
                    header('Location: dip-lookup-list.php?msg=updated');
                    exit;
                } else {
                    $message = '<div class="alert alert-danger">Error updating record: ' . mysqli_error($connection) . '</div>';
                }
            } else {
                // Insert new record
                $query = "INSERT INTO tbl_dip_lookup (tank_capacity, dip_mm, dip_litre) VALUES ('$tank_capacity', '$dip_mm', '$dip_litre')";
                if (mysqli_query($connection, $query)) {
                    header('Location: dip-lookup-list.php?msg=added');
                    exit;
                } else {
                    $message = '<div class="alert alert-danger">Error saving record: ' . mysqli_error($connection) . '</div>';
                }
            }
        }
    } else {
        $message = '<div class="alert alert-warning">Both Dip (mm) and Dip (Litre) are required fields.</div>';
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
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{ margin-top:20px; }
		.txt-center{ text-align:center; }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
		</style>
		<title>PPMS - Add Dip Lookup</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form id="dipForm" action="add-dip-lookup.php" method="POST">
                    <input type="hidden" name="update_id" id="update_id" value="">
					<h4 class="mb-4"><i class="fas fa-plus-circle mr-2 text-primary"></i>Add Dip Lookup Entry</h4>
                    <?php echo $message; ?>
					<div class="card mb-4 shadow-sm">
						<div class="card-body">
							<div class="row">
								<div class="col-md-4">
									<div class="form-group">
										<label class="font-weight-bold">Tank Capacity Calibration <span class="text-danger">*</span></label>
										<select name="tank_capacity" id="tank_capacity" class="form-control" required>
											<option value="23500" <?php echo ($tank_capacity_val == '23500') ? 'selected' : ''; ?>>23,500 Litres Tank</option>
											<option value="50000" <?php echo ($tank_capacity_val == '50000') ? 'selected' : ''; ?>>50,000 Litres Tank</option>
										</select>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label class="font-weight-bold">Dip (mm) <span class="text-danger">*</span></label>
										<input type="number" step="0.01" name="dip_mm" id="dip_mm" class="form-control" placeholder="e.g. 150.00" value="<?php echo htmlspecialchars($dip_mm_val); ?>" required>
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label class="font-weight-bold">Dip (Litre) <span class="text-danger">*</span></label>
										<input type="number" step="0.01" name="dip_litre" id="dip_litre" class="form-control" placeholder="e.g. 850.50" value="<?php echo htmlspecialchars($dip_litre_val); ?>" required>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" id="saveBtn" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Save Dip Lookup</button>
                        <a href="dip-lookup-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>

        <!-- Duplicate Warning Modal -->
        <div class="modal fade" id="duplicateModal" tabindex="-1" role="dialog" aria-labelledby="duplicateModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
              <div class="modal-header" style="background: var(--gradient-header) !important; color: #fff !important;">
                <h5 class="modal-title font-weight-bold" id="duplicateModalLabel"><i class="fas fa-exclamation-triangle text-warning mr-2"></i> Dip (mm) Already Exists</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body">
                <p>Dip value <strong id="modal_dip_mm" style="color: var(--primary-color);"></strong> mm is already registered for tank capacity <strong id="modal_tank_capacity" style="color: var(--primary-color);"></strong> Ltrs with volume <strong id="modal_existing_litre" style="color: var(--primary-color);"></strong> Litres.</p>
                <div class="alert py-3" style="background-color: var(--primary-light); color: var(--primary-color); border-left: 4px solid var(--primary-color);">
                    <i class="fas fa-info-circle mr-1"></i> Do you want to edit/overwrite this existing record with the new volume of <strong id="modal_new_litre"></strong> Litres?
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" id="confirmUpdateBtn" class="btn btn-primary font-weight-bold"><i class="fas fa-edit mr-1"></i> Yes, Overwrite Record</button>
              </div>
            </div>
          </div>
        </div>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
    <script>
    $(document).ready(function() {
        var allowSubmit = false;

        $('#dipForm').on('submit', function(e) {
            if (allowSubmit) {
                return true;
            }

            var tank_capacity = $('#tank_capacity').val();
            var dip_mm = $('#dip_mm').val().trim();
            var dip_litre = $('#dip_litre').val().trim();

            if (dip_mm === '' || dip_litre === '') {
                return true;
            }

            e.preventDefault();

            // Check if dip_mm + tank_capacity exists via AJAX
            $.ajax({
                url: 'check-duplicate.php',
                type: 'POST',
                data: { dip_mm: dip_mm, tank_capacity: tank_capacity },
                dataType: 'json',
                success: function(response) {
                    if (response.exists) {
                        $('#update_id').val(response.id);
                        $('#modal_dip_mm').text(response.dip_mm);
                        $('#modal_tank_capacity').text(parseFloat(response.tank_capacity).toLocaleString());
                        $('#modal_existing_litre').text(response.dip_litre);
                        $('#modal_new_litre').text(dip_litre);
                        $('#duplicateModal').modal('show');
                    } else {
                        allowSubmit = true;
                        $('#dipForm').submit();
                    }
                },
                error: function() {
                    allowSubmit = true;
                    $('#dipForm').submit();
                }
            });
        });

        $('#confirmUpdateBtn').on('click', function() {
            allowSubmit = true;
            $('#duplicateModal').modal('hide');
            $('#dipForm').submit();
        });
    });
    </script>
</html>
