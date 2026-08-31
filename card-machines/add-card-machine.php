<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding card machines
check_access('card_machines', 'add');

// Auto-migrate tbl_card_machines charges_percentage to DECIMAL(8,4) and ensure deleted_at exists
$chk_del = mysqli_query($connection, "SHOW COLUMNS FROM tbl_card_machines LIKE 'deleted_at'");
if ($chk_del && mysqli_num_rows($chk_del) == 0) {
    mysqli_query($connection, "ALTER TABLE tbl_card_machines ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}
mysqli_query($connection, "ALTER TABLE tbl_card_machines MODIFY COLUMN charges_percentage DECIMAL(8,4) NOT NULL DEFAULT 0.0000");

$message = '';
if (isset($_POST['name']) && isset($_POST['contact_person_name']) && isset($_POST['contact_person_number'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $charges_percentage = floatval($_POST['charges_percentage']);
    $contact_person_name = mysqli_real_escape_string($connection, $_POST['contact_person_name']);
    $contact_person_number = mysqli_real_escape_string($connection, $_POST['contact_person_number']);

    $query = "INSERT INTO tbl_card_machines (name, charges_percentage, contact_person_name, contact_person_number) VALUES ('$name', '$charges_percentage', '$contact_person_name', '$contact_person_number')";
    
    if (mysqli_query($connection, $query)) {
        header('Location: card-machines-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error saving machine: ' . mysqli_error($connection) . '</div>';
    }
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
		<title>PPMS - Add Card Machine</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-card-machine.php" method="POST">
					<h4 class="mb-5"><i class="fas fa-credit-card mr-2 text-primary"></i>Add Card Machine</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Machine Name</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="name" class="form-control" placeholder="e.g. Mezan Bank" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-5 col-md-6 col-sm-4 col-form-label">Service Charges (%)</label>
										<div class="col-lg-7 col-md-6 col-sm-8">
											<input type="number" step="0.0001" min="0" max="100" name="charges_percentage" class="form-control" placeholder="e.g. 0.3456" value="0.0000" required>
										</div>
									</div>
								</div>
							</div>
							<div class="row mt-3">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Contact Person</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="contact_person_name" class="form-control" placeholder="e.g. John Smith" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-5 col-md-6 col-sm-4 col-form-label">Contact Number</label>
										<div class="col-lg-7 col-md-6 col-sm-8">
											<input type="text" name="contact_person_number" class="form-control" placeholder="e.g. 03001234567" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top"><i class="fas fa-save mr-1"></i> Save Machine</button>
                        <a href="card-machines-list.php" class="btn btn-secondary m-top ml-2"><i class="fas fa-times mr-1"></i> Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
