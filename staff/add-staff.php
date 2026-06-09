<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

$message = '';
if (
    isset($_POST['first_name']) && 
    isset($_POST['last_name']) && 
    isset($_POST['role_id']) && 
    isset($_POST['joining_date']) && 
    isset($_POST['shift_id']) && 
    isset($_POST['salary']) && 
    isset($_POST['phone'])
) {
    $first_name = mysqli_real_escape_string($connection, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($connection, $_POST['last_name']);
    $role_id = mysqli_real_escape_string($connection, $_POST['role_id']);
    $joining_date = mysqli_real_escape_string($connection, $_POST['joining_date']);
    $shift_id = mysqli_real_escape_string($connection, $_POST['shift_id']);
    $salary = mysqli_real_escape_string($connection, $_POST['salary']);
    $address = isset($_POST['address']) ? mysqli_real_escape_string($connection, $_POST['address']) : '';
    $phone = mysqli_real_escape_string($connection, $_POST['phone']);

    $query = "INSERT INTO tbl_staff (first_name, last_name, role_id, joining_date, shift_id, salary, address, phone) 
              VALUES ('$first_name', '$last_name', '$role_id', '$joining_date', '$shift_id', '$salary', " . ($address === '' ? "NULL" : "'$address'") . ", '$phone')";
    
    if (mysqli_query($connection, $query)) {
        header('Location: staff-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving staff: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch roles
$roles_sql = "SELECT id, name FROM tbl_roles ORDER BY name ASC";
$roles_result = mysqli_query($connection, $roles_sql);

// Fetch shifts
$shifts_sql = "SELECT id, name FROM tbl_shifts ORDER BY name ASC";
$shifts_result = mysqli_query($connection, $shifts_sql);
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
		<title>PPMS - Add Staff</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-staff.php" method="POST">
					<h4 class="mb-5">Add Staff</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">First Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="first_name" class="form-control" placeholder="e.g. John" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Last Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="last_name" class="form-control" placeholder="e.g. Doe" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Role</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="role_id" class="form-control" required>
                                                <option value="">Select Role</option>
                                                <?php 
                                                if (mysqli_num_rows($roles_result) > 0) {
                                                    while ($role = mysqli_fetch_assoc($roles_result)) {
                                                        echo '<option value="' . $role['id'] . '">' . htmlspecialchars($role['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Joining Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="joining_date" class="form-control" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Shift</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="shift_id" class="form-control" required>
                                                <option value="">Select Shift</option>
                                                <?php 
                                                if (mysqli_num_rows($shifts_result) > 0) {
                                                    while ($shift = mysqli_fetch_assoc($shifts_result)) {
                                                        echo '<option value="' . $shift['id'] . '">' . htmlspecialchars($shift['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Per Day Salary</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="salary" class="form-control" placeholder="0.00" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Phone</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="phone" class="form-control" placeholder="e.g. 03001234567" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Address</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<textarea name="address" class="form-control" rows="3" placeholder="Optional address"></textarea>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top">Save Staff</button>
                        <a href="staff-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
