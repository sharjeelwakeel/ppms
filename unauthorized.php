<?php
require 'include/session.php';
if (!userloggedin()) { header('Location:login.php'); exit; }
require 'include/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="include/style.css?v=1.0.1">
    <title>PPMS - Access Denied</title>
    <style>
        body { background: #f4f6fb; font-family: 'Roboto', sans-serif; }
        .unauthorized-card {
            max-width: 600px;
            margin: 80px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            text-align: center;
            padding: 40px 30px;
            border-top: 6px solid #dc3545;
        }
        .icon-lock {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<?php include('include/navbar.php');?>
<main class="main">
    <div class="container pt-5 pb-5">
        <div class="unauthorized-card">
            <div class="icon-lock">
                <i class="fas fa-user-lock"></i>
            </div>
            <h3 class="font-weight-bold text-dark mb-2">Access Restricted</h3>
            <p class="text-muted mb-4" style="font-size: 15px;">
                You do not have permission to access this module or perform this operation. Please contact your system administrator to update your role permissions.
            </p>
            <div>
                <a href="dashboard.php" class="btn btn-primary px-4 py-2 font-weight-bold" style="border-radius: 8px;">
                    <i class="fas fa-home mr-1"></i> Go to Dashboard
                </a>
            </div>
        </div>
    </div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>
