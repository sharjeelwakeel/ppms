<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';

$alert_message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Dip Lookup entry added successfully!<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
    } elseif ($_GET['msg'] === 'updated') {
        $alert_message = '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle mr-1"></i> Dip Lookup entry updated successfully!<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>';
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
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{
			margin-top:20px;
		}
		.m-bot{
			margin-bottom:20px;
		}
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover {
            opacity: 0.9;
        }
        #dipLookupTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
        }
        .calculator-card {
            background: #f8f9fa;
            border-left: 4px solid var(--primary-color);
        }
		</style>
		<title>PPMS - Dip Lookup</title>
	</head>
	<body>
        
        <?php include('../include/navbar.php');?>

		<main class="main">
			<div class="container pt-4 pb-4">
                <?php echo $alert_message; ?>
				<div class="row mb-3 align-items-center">
					<div class="col-md-6">
						<h4>View Dip Lookup</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="add-dip-lookup.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Dip Lookup</a>
					</div>
				</div>

                <!-- Instant Dip Lookup Widget -->
                <div class="card calculator-card mb-4 shadow-sm" style="background-color: var(--primary-light); border-left: 4px solid var(--primary-color);">
                    <div class="card-body py-3">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="m-0 font-weight-bold" style="color: var(--primary-color);"><i class="fas fa-search-location mr-2"></i> Search Dip Lookup</h6>
                                <small class="text-muted">Type any dip in mm to find exact volume in liters</small>
                            </div>
                            <div class="col-md-5 my-2 my-md-0">
                                <div class="input-group">
                                    <input type="number" step="0.01" id="search_dip_mm" class="form-control" placeholder="Enter Dip in mm (e.g. 150.00)">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="button" id="btnQuickLookup"><i class="fas fa-search"></i> Search</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div id="quickLookupResult" class="h5 m-0 font-weight-bold text-md-right" style="color: var(--primary-color);">-- Litres</div>
                            </div>
                        </div>
                    </div>
                </div>

				<table id="dipLookupTable" class="table table-striped table-bordered w-100">
					<thead>
						<tr>
							<th>ID</th>
							<th>Dip (mm)</th>
							<th>Dip (Litre)</th>
							<th>Created At</th>
							<th>Updated At</th>
							<th>Delete</th>
						</tr>
					</thead>
					<tbody>
						<!-- Loaded dynamically via Server-Side DataTables AJAX -->
					</tbody>
				</table>
			</div>
		</main>

    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	var table;
	$(document).ready(function() {
		table = $('#dipLookupTable').DataTable({
			"processing": true,
			"serverSide": true,
			"ajax": {
				"url": "dip-lookup-ajax.php",
				"type": "POST"
			},
			"pageLength": 25,
			"lengthMenu": [[10, 25, 50, 100, 250], [10, 25, 50, 100, 250]],
			"order": [[ 1, "asc" ]],
			"columnDefs": [
				{ "orderable": false, "targets": 5 }
			]
		});

        // Quick Dip Calculator Widget Lookup
        $('#btnQuickLookup, #search_dip_mm').on('click keyup', function(e) {
            if (e.type === 'keyup' && e.keyCode !== 13) {
                return;
            }
            var mm = $('#search_dip_mm').val().trim();
            if (mm === '') {
                $('#quickLookupResult').html('<span class="text-muted">-- Litres</span>');
                return;
            }

            $.ajax({
                url: 'check-duplicate.php',
                type: 'POST',
                data: { dip_mm: mm },
                dataType: 'json',
                success: function(res) {
                    if (res.exists) {
                        $('#quickLookupResult').html('<i class="fas fa-gas-pump mr-1"></i> ' + parseFloat(res.dip_litre).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' Litres');
                    } else {
                        $('#quickLookupResult').html('<span class="text-danger font-weight-normal"><i class="fas fa-times-circle mr-1"></i> Not Found</span>');
                    }
                }
            });
        });
	});

	function deleteDipLookup(id, dip_mm){
		if(confirm('Are you sure you want to delete dip lookup for ' + dip_mm + ' mm?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletediplookup.php",
				data: {id: id},
				success: function (data) {
					if (data.trim() === 'deleted') {
                        table.ajax.reload(null, false); // Reload DataTables server-side preserving current page
                    } else {
                        alert('Error deleting record: ' + data);
                    }
				},
				error: function (data) {
					console.log(data);
                    alert('Server error occurred while deleting.');
				}
			});
		}
	}
	</script>
</html>
