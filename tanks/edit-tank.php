<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
}
require '../include/config.php';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $id = mysqli_real_escape_string($connection, $_GET['id']);
} else {
    header('Location: tanks-list.php');
    exit;
}

$message = '';
if (isset($_POST['submit'])) {
    $tank_name     = mysqli_real_escape_string($connection, $_POST['tank_name']);
    $start_reading = mysqli_real_escape_string($connection, $_POST['start_reading']);
    $item_id       = mysqli_real_escape_string($connection, $_POST['item_id']);

    $query = "UPDATE tbl_tanks SET tank_name='$tank_name', start_reading='$start_reading', item_id='$item_id' WHERE id='$id'";

    if (mysqli_query($connection, $query)) {
        header('Location: tanks-list.php');
        exit;
    } else {
        $message = '<div class="alert alert-danger">Error updating tank: ' . mysqli_error($connection) . '</div>';
    }
}

// Fetch existing tank data
$sql  = "SELECT * FROM tbl_tanks WHERE id='$id'";
$res  = mysqli_query($connection, $sql);
$tank = mysqli_fetch_assoc($res);
if (!$tank) {
    header('Location: tanks-list.php');
    exit;
}

// Fetch items for dropdown
$items_sql    = "SELECT id, name FROM tbl_items ORDER BY name ASC";
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
		.m-top  { margin-top: 20px; }
		.txt-center { text-align: center; }
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
		</style>
		<title>PPMS Edit Tank</title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				<form action="edit-tank.php?id=<?php echo $id; ?>" method="POST">
					<h4 class="mb-5">Edit Tank</h4>
                    <?php echo $message; ?>
					<div class="card mb-5">
						<div class="card-body">
							<div class="row">
								<div class="col-md-6">
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Tank Name</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="text" name="tank_name" class="form-control" value="<?php echo htmlspecialchars($tank['tank_name']); ?>" required>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Item</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<select name="item_id" class="form-control" required>
												<option value="">-- Select Item --</option>
												<?php
												if ($items_result && mysqli_num_rows($items_result) > 0) {
													while ($item = mysqli_fetch_assoc($items_result)) {
														$selected = ($item['id'] == $tank['item_id']) ? 'selected' : '';
														echo '<option value="'.$item['id'].'" '.$selected.'>'.htmlspecialchars($item['name']).'</option>';
													}
												}
												?>
											</select>
										</div>
									</div>
									<div class="form-group row">
										<label class="col-lg-4 col-md-5 col-sm-4 col-form-label">Start Reading</label>
										<div class="col-lg-8 col-md-7 col-sm-8">
											<input type="number" step="0.01" name="start_reading" class="form-control" value="<?php echo htmlspecialchars($tank['start_reading']); ?>" required>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="txt-center">
						<input type="submit" name="submit" value="Save Tank" class="btn btn-primary m-top">
                        <a href="tanks-list.php" class="btn btn-secondary m-top ml-2">Cancel</a>
					</div>
				</form>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
</html>
