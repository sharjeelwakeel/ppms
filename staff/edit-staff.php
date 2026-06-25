<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: staff-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $first_name = mysqli_real_escape_string($connection, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($connection, $_POST['last_name']);
    $role_id = mysqli_real_escape_string($connection, $_POST['role_id']);
    $joining_date = mysqli_real_escape_string($connection, $_POST['joining_date']);
    $shift_id = mysqli_real_escape_string($connection, $_POST['shift_id']);
    $salary = mysqli_real_escape_string($connection, $_POST['salary']);
    $address = isset($_POST['address']) ? mysqli_real_escape_string($connection, $_POST['address']) : '';
    $phone = mysqli_real_escape_string($connection, $_POST['phone']);

    $guar_name = mysqli_real_escape_string($connection, $_POST['guarantor_name']);
    $guar_phone = mysqli_real_escape_string($connection, $_POST['guarantor_phone']);
    $guar_address = isset($_POST['guarantor_address']) ? mysqli_real_escape_string($connection, $_POST['guarantor_address']) : '';

    mysqli_begin_transaction($connection);
    try {
        $query = "UPDATE tbl_staff SET 
                    first_name='$first_name', 
                    last_name='$last_name', 
                    role_id='$role_id', 
                    joining_date='$joining_date', 
                    shift_id='$shift_id', 
                    salary='$salary', 
                    address=" . ($address === '' ? "NULL" : "'$address'") . ", 
                    phone='$phone' 
                  WHERE id='$id'";
        mysqli_query($connection, $query);

        // Check if guarantor exists, then update or insert
        $check_guar = mysqli_query($connection, "SELECT id FROM tbl_staff_guarantors WHERE staff_id='$id' LIMIT 1");
        if (mysqli_num_rows($check_guar) > 0) {
            $guar_row = mysqli_fetch_assoc($check_guar);
            $guar_id = $guar_row['id'];
            mysqli_query($connection, "UPDATE tbl_staff_guarantors SET name='$guar_name', phone='$guar_phone', address=" . ($guar_address === '' ? "NULL" : "'$guar_address'") . " WHERE id='$guar_id'");
        } else {
            mysqli_query($connection, "INSERT INTO tbl_staff_guarantors (staff_id, name, phone, address) VALUES ('$id', '$guar_name', '$guar_phone', " . ($guar_address === '' ? "NULL" : "'$guar_address'") . ")");
        }

        mysqli_commit($connection);
        header('Location: staff-list.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error updating staff: ' . $e->getMessage() . '</div>';
    }
}

// Fetch staff details
$sql = "SELECT * FROM tbl_staff WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$staff = mysqli_fetch_assoc($result);

if (!$staff) {
    header('Location: staff-list.php');
    exit;
}

// Fetch guarantor details
$guar_sql = "SELECT * FROM tbl_staff_guarantors WHERE staff_id='$id' LIMIT 1";
$guar_result = mysqli_query($connection, $guar_sql);
$guarantor = mysqli_fetch_assoc($guar_result) ?: [];

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
		<title>PPMS - Edit Staff</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-staff.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Staff</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">First Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($staff['first_name']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Last Name</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($staff['last_name']); ?>" required>
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
                                                        $selected = ($staff['role_id'] == $role['id']) ? 'selected' : '';
                                                        echo '<option value="' . $role['id'] . '" ' . $selected . '>' . htmlspecialchars($role['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Joining Date</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="date" name="joining_date" class="form-control" value="<?php echo htmlspecialchars($staff['joining_date']); ?>" required>
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
                                                        $selected = ($staff['shift_id'] == $shift['id']) ? 'selected' : '';
                                                        echo '<option value="' . $shift['id'] . '" ' . $selected . '>' . htmlspecialchars($shift['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Per Day Salary</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="salary" class="form-control" value="<?php echo htmlspecialchars($staff['salary']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Phone</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($staff['phone']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Address</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<textarea name="address" class="form-control" rows="3" placeholder="Optional address"><?php echo htmlspecialchars($staff['address'] ?? ''); ?></textarea>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<h5 class="mb-4 mt-5"><i class="fas fa-user-shield mr-2 text-primary"></i>Reference Person (Guarantor) Information</h5>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Guarantor Name</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="guarantor_name" class="form-control" value="<?php echo htmlspecialchars($guarantor['name'] ?? ''); ?>" placeholder="e.g. Robert Smith" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Guarantor Phone</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="guarantor_phone" class="form-control" value="<?php echo htmlspecialchars($guarantor['phone'] ?? ''); ?>" placeholder="e.g. 03009876543" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Guarantor Address</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<textarea name="guarantor_address" class="form-control" rows="3" placeholder="Optional guarantor address"><?php echo htmlspecialchars($guarantor['address'] ?? ''); ?></textarea>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="txt-center">
						<input type="submit" name="submit" value="Save Staff" class="btn btn-primary m-top">
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
