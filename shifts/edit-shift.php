<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: shifts-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $start_time = mysqli_real_escape_string($connection, $_POST['start_time']);
    $end_time = mysqli_real_escape_string($connection, $_POST['end_time']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);

    $query = "UPDATE tbl_shifts SET name='$name', start_time='$start_time', end_time='$end_time', status='$status' WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        header('Location: shifts-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating shift: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch the existing shift data
$sql = "SELECT * FROM tbl_shifts WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$shift = mysqli_fetch_assoc($result);

if (!$shift) {
    header('Location: shifts-list.php');
    exit;
}
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
		<title>PPMS - Edit Shift</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-shift.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Shift</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Shift Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($shift['name']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Start Time</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="time" name="start_time" class="form-control" value="<?php echo htmlspecialchars($shift['start_time']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">End Time</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="time" name="end_time" class="form-control" value="<?php echo htmlspecialchars($shift['end_time']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Status</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="status" class="form-control" required>
												<option value="Active" <?php if ($shift['status'] == 'Active') echo 'selected'; ?>>Active</option>
												<option value="Inactive" <?php if ($shift['status'] == 'Inactive') echo 'selected'; ?>>Inactive</option>
											</select>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<input type="submit" name="submit" value="Save Shift" class="btn btn-primary m-top">
                        <a href="shifts-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
