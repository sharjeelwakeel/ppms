<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding shifts
check_access('shifts', 'add');

$message = '';
if (isset($_POST['name']) && isset($_POST['start_time']) && isset($_POST['end_time']) && isset($_POST['status'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $start_time = mysqli_real_escape_string($connection, $_POST['start_time']);
    $end_time = mysqli_real_escape_string($connection, $_POST['end_time']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);

    $query = "INSERT INTO tbl_shifts (name, start_time, end_time, status) 
              VALUES ('$name', '$start_time', '$end_time', '$status')";
    
    if (mysqli_query($connection, $query)) {
        header('Location: shifts-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving shift: ' . mysqli_error($connection) . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

		<link rel="stylesheet" href="../include/css/roboto.css">
		<link rel="stylesheet" href="../include/css/bootstrap.min.css">
		<link rel="stylesheet" href="../include/css/all.min.css">
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
		<title>PPMS - Add Shift</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-shift.php" method="POST">
					<h4 class="mb-5">Add Shift</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Shift Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="name" class="form-control" placeholder="e.g. Morning Shift" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Start Time</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="time" name="start_time" class="form-control" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">End Time</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="time" name="end_time" class="form-control" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Status</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
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
						<button type="submit" class="btn btn-primary m-top">Save Shift</button>
                        <a href="shifts-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="../include/js/jquery.min.js"></script>
	<script src="../include/js/popper.min.js"></script>
	<script src="../include/js/bootstrap.min.js"></script>
</html>
