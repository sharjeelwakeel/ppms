<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for adding banks
check_access('banks', 'add');

$message = '';
if (isset($_POST['name']) && isset($_POST['account_number'])) {
    $name = mysqli_real_escape_string($connection, $_POST['name']);
    $account_number = mysqli_real_escape_string($connection, $_POST['account_number']);

    mysqli_begin_transaction($connection);
    try {
        $query = "INSERT INTO tbl_banks (name, account_number) VALUES ('$name', '$account_number')";
        mysqli_query($connection, $query);
        mysqli_commit($connection);
        header('Location: banks-list.php');
        exit;
    } catch (Exception $e) {
        mysqli_rollback($connection);
        $message = '<div class="alert alert-danger">Error saving bank: ' . $e->getMessage() . '</div>';
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
		<title>PPMS - Add Bank</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="add-bank.php" method="POST">
					<h4 class="mb-5">Add Bank</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Bank Name</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="name" class="form-control" placeholder="e.g. Allied Bank" required>
										</div>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Account Number</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="account_number" class="form-control" placeholder="e.g. 1234567890123" required>
										</div>
									</div>
								</div>
							</div>
						</div>	
					</div>
					<div class="txt-center">
						<button type="submit" class="btn btn-primary m-top">Save Bank</button>
                        <a href="banks-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
