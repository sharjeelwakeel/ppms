<?php

	require 'include/config.php';

	$username = '';
	$password = '';
	$empty_error = '';
	$sql_error = '';
	$user_error = '';
	$pass_error = '';
	
	if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit'])){
		$username = $_POST['username'];
		$password = $_POST['password'];

		if(empty(trim($username)) || empty(trim($password))){
			$empty_error = "Please enter a username or password.";
			header("Location:index.php?error=$empty_error");
			exit();
		}
		else{
			$sql = "SELECT * FROM tbl_accounts WHERE username = ?;";
			$stmt = mysqli_stmt_init($connection);
			if(!mysqli_stmt_prepare($stmt, $sql)){
				$sql_error = 'Something is wrong.';
				header("Location:index.php?error=$sql_error");
				exit();
			}
			else{
				mysqli_stmt_bind_param($stmt, "s", $username);
				mysqli_stmt_execute($stmt);
				$result = mysqli_stmt_get_result($stmt);
				if($row = mysqli_fetch_assoc($result)){
					$pass_check = password_verify($password, $row['password']);
					if($pass_check == false){
						$pass_error = 'Invalid username or password.';
						header("Location:index.php?error=$pass_error");
						exit();
					}
					else if($pass_check == true){
						session_start();
						$_SESSION['loggedInUser'] = $row['id'];
						header("Location:dashboard.php");
						exit();
					}
					else{
						$pass_error = 'Something went wrong. Please try again.';
						header("Location:index.php?error=$pass_error");
						exit();
					}
				}
				else{
					$user_error = 'No user found.';
					header("Location:index.php?error=$user_error");
					exit();
				}
			}
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
		<link rel="stylesheet" href="include/style.css?v=1.0.1" />

		<title>PPMS - Login</title>
		<style>
			body {
				background: #f4f6fb;
				font-family: 'Roboto', sans-serif;
			}
			.login-card {
				background: #ffffff;
				border-radius: 12px;
				box-shadow: 0 8px 30px rgba(4, 32, 78, 0.1);
				padding: 40px;
				width: 100%;
				max-width: 450px;
			}
			.login-title {
				color: var(--primary-color);
				font-weight: 700;
				margin-bottom: 5px;
			}
			.login-subtitle {
				color: #6c757d;
				font-weight: 400;
				margin-bottom: 30px;
				font-size: 14px;
			}
			.form-control {
				border-radius: 8px;
				padding: 12px 15px;
				height: auto;
				font-size: 14px;
				border: 1px solid #ced4da;
				transition: all 0.2s;
			}
			.form-control:focus {
				border-color: var(--primary-color);
				box-shadow: 0 0 0 0.2rem rgba(4, 32, 78, 0.15);
			}
			.btn-login {
				background: var(--primary-gradient);
				border: none;
				color: #fff;
				font-weight: 600;
				padding: 12px;
				border-radius: 8px;
				font-size: 15px;
				letter-spacing: 0.5px;
				box-shadow: 0 4px 12px rgba(4, 32, 78, 0.2);
				transition: all 0.2s;
			}
			.btn-login:hover {
				background: var(--primary-hover);
				color: #fff;
				transform: translateY(-1px);
				box-shadow: 0 6px 18px rgba(4, 32, 78, 0.25);
			}
			.btn-login:active {
				transform: translateY(0);
			}
		</style>
	</head>
	<body>
		<main class="main">
			<div class="container">
				<div class="row">
					<div class="col-12 d-flex align-items-center justify-content-center" style="height: 100vh;">
						<div class="login-card">
							<div class="text-center">
								<h3 class="login-title">PPMS</h3>
								<p class="login-subtitle">Petroleum Pump Management System</p>
							</div>
							<form action="" method="POST">
								<div class="form-group">
									<label class="font-weight-bold" style="font-size: 13px; color: #495057;">Username</label>
									<div class="input-group">
										<div class="input-group-prepend">
											<span class="input-group-text bg-light border-right-0" style="border-radius: 8px 0 0 8px;"><i class="fas fa-user text-muted"></i></span>
										</div>
										<input type="text" name="username" class="form-control border-left-0" style="border-radius: 0 8px 8px 0;" placeholder="Enter your username" autocomplete="off" required>
									</div>
								</div>
								<div class="form-group">
									<label class="font-weight-bold" style="font-size: 13px; color: #495057;">Password</label>
									<div class="input-group">
										<div class="input-group-prepend">
											<span class="input-group-text bg-light border-right-0" style="border-radius: 8px 0 0 8px;"><i class="fas fa-lock text-muted"></i></span>
										</div>
										<input type="password" name="password" class="form-control border-left-0" style="border-radius: 0 8px 8px 0;" placeholder="Enter your password" required>
									</div>
								</div>
								<div class="form-group text-center mt-4 mb-0">
									<button type="submit" name="submit" class="btn btn-login btn-block">Sign In</button>
									<?php if(isset($_GET['error'])): ?>
										<div class="alert alert-danger mt-3 mb-0 py-2" style="font-size: 13px; border-radius: 8px;">
											<i class="fas fa-exclamation-circle mr-1"></i> <?php echo htmlspecialchars($_GET['error']); ?>
										</div>
									<?php endif; ?>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>
		</main>
	</body>

	<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script src="https://cdn.jsdelivr.net/gh/fancyapps/fancybox@3.5.7/dist/jquery.fancybox.min.js"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
</html>
