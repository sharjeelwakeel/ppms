<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: nozzle-filling-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $nozzle_id = mysqli_real_escape_string($connection, $_POST['nozzle_id']);
    $tank_id = mysqli_real_escape_string($connection, $_POST['tank_id']);
    $last_reading = mysqli_real_escape_string($connection, $_POST['last_reading']);
    $current_reading = mysqli_real_escape_string($connection, $_POST['current_reading']);

    $query = "UPDATE tbl_nozzle_filling SET 
                nozzle_id='$nozzle_id', 
                tank_id='$tank_id', 
                last_reading='$last_reading', 
                current_reading='$current_reading' 
              WHERE id='$id'";
    
    if (mysqli_query($connection, $query)) {
        header('Location: nozzle-filling-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating nozzle filling record: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch record
$sql = "SELECT * FROM tbl_nozzle_filling WHERE id='$id'";
$result = mysqli_query($connection, $sql);
$nozzleFilling = mysqli_fetch_assoc($result);

if (!$nozzleFilling) {
    header('Location: nozzle-filling-list.php');
    exit;
}

// Fetch nozzles
$nozzles_sql = "SELECT id, name FROM tbl_nozzles ORDER BY name ASC";
$nozzles_result = mysqli_query($connection, $nozzles_sql);

// Fetch tanks
$tanks_sql = "SELECT id, tank_name FROM tbl_tanks ORDER BY tank_name ASC";
$tanks_result = mysqli_query($connection, $tanks_sql);
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
		<title>PPMS - Edit Nozzle Filling</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-nozzle-filling.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Nozzle Filling</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Nozzle</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="nozzle_id" class="form-control" required>
                                                <option value="">Select Nozzle</option>
                                                <?php 
                                                if (mysqli_num_rows($nozzles_result) > 0) {
                                                    while ($nozzle = mysqli_fetch_assoc($nozzles_result)) {
                                                        $selected = ($nozzleFilling['nozzle_id'] == $nozzle['id']) ? 'selected' : '';
                                                        echo '<option value="' . $nozzle['id'] . '" ' . $selected . '>' . htmlspecialchars($nozzle['name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Tank</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<select name="tank_id" class="form-control" required>
                                                <option value="">Select Tank</option>
                                                <?php 
                                                if (mysqli_num_rows($tanks_result) > 0) {
                                                    while ($tank = mysqli_fetch_assoc($tanks_result)) {
                                                        $selected = ($nozzleFilling['tank_id'] == $tank['id']) ? 'selected' : '';
                                                        echo '<option value="' . $tank['id'] . '" ' . $selected . '>' . htmlspecialchars($tank['tank_name']) . '</option>';
                                                    }
                                                }
                                                ?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Last Reading</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="last_reading" class="form-control" value="<?php echo htmlspecialchars($nozzleFilling['last_reading']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-3 col-md-5 col-sm-4 col-form-label">Current Reading</label>
										<div class="col-lg-9 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="current_reading" class="form-control" value="<?php echo htmlspecialchars($nozzleFilling['current_reading']); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<input type="submit" name="submit" value="Save Record" class="btn btn-primary m-top">
                        <a href="nozzle-filling-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
