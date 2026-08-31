<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding nozzles
check_access('nozzles', 'add');

$message = '';
if (isset($_POST['name']) && isset($_POST['tank_id']) && isset($_POST['item_id']) && isset($_POST['start_reading']) && isset($_POST['status'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $tank_id = mysqli_real_escape_string($connection, $_POST['tank_id']);
    
    // Server-side database override validation query for item_id associated with the tank
    $tank_query = mysqli_query($connection, "SELECT item_id FROM tbl_tanks WHERE id = '$tank_id'");
    $tank_data = mysqli_fetch_assoc($tank_query);
    if ($tank_data) {
        $item_id = $tank_data['item_id'];
    } else {
        $item_id = mysqli_real_escape_string($connection, $_POST['item_id']);
    }

    $start_reading = mysqli_real_escape_string($connection, $_POST['start_reading']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);

    if (floatval($start_reading) < 0) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i>Error: Current reading must be greater than or equal to 0.</div>';
    } else {
        $query = "INSERT INTO tbl_nozzles (name, tank_id, item_id, start_reading, status) 
                  VALUES ('$name', '$tank_id', '$item_id', '$start_reading', '$status')";
        
        if (mysqli_query($connection, $query)) {
            header('Location: nozzles-list.php');
            exit;
        } else {
            $message = '<div class="alert alert-danger">Error saving nozzle: ' . mysqli_error($connection) . '</div>';
        }
    }
}

// Fetch tanks
$tanks_sql = "SELECT id, tank_name, item_id FROM tbl_tanks ORDER BY tank_name ASC";
$tanks_result = mysqli_query($connection, $tanks_sql);

// Fetch items
$items_sql = "SELECT id, name FROM tbl_items ORDER BY name ASC";
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
		.m-top{
			margin-top:20px;
		}
		.txt-center{
			text-align:center;
		}
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
		</style>
		<title>PPMS - Add Nozzle</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-nozzle.php" method="POST">
					<h4 class="mb-5"><i class="fas fa-burn mr-2 text-primary"></i>Add Nozzle</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-5 col-form-label">Nozzle Name</label>
										<div class="col-lg-8 col-md-7 col-sm-7">
											<input type="text" name="name" class="form-control" placeholder="e.g. Nozzle 1" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-5 col-form-label">Tank</label>
										<div class="col-lg-8 col-md-7 col-sm-7">
											<select name="tank_id" class="form-control" required>
                                                <option value="">Select Tank</option>
                                                <?php 
                                                if (mysqli_num_rows($tanks_result) > 0) {
                                                    while ($tank = mysqli_fetch_assoc($tanks_result)) {
                                                        echo '<option value="' . $tank['id'] . '" data-item-id="' . $tank['item_id'] . '">' . htmlspecialchars($tank['tank_name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-5 col-form-label">Item</label>
										<div class="col-lg-8 col-md-7 col-sm-7">
											<select name="item_id" class="form-control" required>
                                                <option value="">Select Item</option>
                                                <?php 
                                                if (mysqli_num_rows($items_result) > 0) {
                                                    while ($item = mysqli_fetch_assoc($items_result)) {
                                                        echo '<option value="' . $item['id'] . '">' . htmlspecialchars($item['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-5 col-form-label">Current Reading</label>
										<div class="col-lg-8 col-md-7 col-sm-7">
											<input type="number" step="0.01" min="0" name="start_reading" class="form-control" value="0.00" placeholder="0.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-5 col-form-label">Status</label>
										<div class="col-lg-8 col-md-7 col-sm-7">
											<select name="status" class="form-control" required>
												<option value="Active">Active</option>
												<option value="Inactive">Inactive</option>
											</select>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Save Nozzle</button>
                        <a href="nozzles-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script>
	$(document).ready(function() {
		// Listen to Tank changes
		$('select[name="tank_id"]').on('change', function() {
			var selectedOption = $(this).find(':selected');
			var itemId = selectedOption.data('item-id');
			
			if (itemId) {
				$('select[name="item_id"]').val(itemId).attr('disabled', true);
			} else {
				$('select[name="item_id"]').val('').attr('disabled', false);
			}
		});

		// Trigger initially to handle default state
		$('select[name="tank_id"]').trigger('change');

		// Enable select on form submit so value is posted
		$('form').on('submit', function() {
			$('select[name="item_id"]').attr('disabled', false);
		});
	});
	</script>
</html>
