<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing dip lookup
check_access('tanks', 'show');

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
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css" />
		<link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css" />
		<link rel="stylesheet" href="../include/style.css?v=1.0.1" />
		<style>
		.m-top{ margin-top:20px; }
		.m-bot{ margin-bottom:20px; }
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
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
						<h4><i class="fas fa-ruler-vertical mr-2 text-primary"></i>View Dip Lookup Master</h4>
					</div>
					<div class="col-md-6 text-right">
                        <?php if (has_permission('tanks', 'add')): ?>
						<a href="add-dip-lookup.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add New Dip Lookup</a>
                        <?php endif; ?>
					</div>
				</div>

                <!-- Instant Dip Lookup Widget -->
                <div class="card calculator-card mb-4 shadow-sm" style="background-color: var(--primary-light); border-left: 4px solid var(--primary-color);">
                    <div class="card-body py-3">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <h6 class="m-0 font-weight-bold" style="color: var(--primary-color);"><i class="fas fa-search-location mr-2"></i> Instant Dip Lookup</h6>
                                <small class="text-muted">Type dip in mm &amp; select capacity</small>
                            </div>
                            <div class="col-md-3 my-2 my-md-0">
                                <select id="search_tank_capacity" class="form-control">
                                    <option value="23500">23,500 Ltrs Tank</option>
                                    <option value="50000">50,000 Ltrs Tank</option>
                                </select>
                            </div>
                            <div class="col-md-4 my-2 my-md-0">
                                <div class="input-group">
                                    <input type="number" step="0.01" id="search_dip_mm" class="form-control" placeholder="Enter Dip in mm (e.g. 150.00)">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="button" id="btnQuickLookup"><i class="fas fa-search"></i> Search</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div id="quickLookupResult" class="h5 m-0 font-weight-bold text-md-right" style="color: var(--primary-color);">-- Litres</div>
                            </div>
                        </div>
                    </div>
                </div>

				<table id="dipLookupTable" class="table table-striped table-bordered w-100">
					<thead>
						<tr>
							<th>ID</th>
							<th>Tank Capacity</th>
							<th>Dip (mm)</th>
							<th>Dip (Litre)</th>
							<th>Created At</th>
							<th>Updated At</th>
							<th style="text-align: center;">Delete</th>
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
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
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
			"order": [[ 2, "asc" ]],
			"columnDefs": [
				{ "orderable": false, "targets": [6] }
			]
		});

        // Quick Lookup Search trigger
        $('#btnQuickLookup').on('click', function() {
            performQuickLookup();
        });

        $('#search_dip_mm').on('keypress', function(e) {
            if (e.which == 13) {
                performQuickLookup();
            }
        });

        function performQuickLookup() {
            var mmVal = $('#search_dip_mm').val().trim();
            var capVal = $('#search_tank_capacity').val();
            if (mmVal === '') {
                $('#quickLookupResult').html('<span class="text-danger">Enter dip mm</span>');
                return;
            }

            $.ajax({
                url: 'lookup-by-mm.php',
                type: 'GET',
                data: { dip_mm: mmVal, capacity: capVal },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#quickLookupResult').html('<span class="text-success">' + parseFloat(res.balance).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' Litres</span>');
                    } else {
                        $('#quickLookupResult').html('<span class="text-danger">Not Found</span>');
                    }
                },
                error: function() {
                    $('#quickLookupResult').html('<span class="text-danger">Error</span>');
                }
            });
        }
	});

	function deleteDipLookup(id, dip_mm){
		if(confirm('Are you sure you want to delete Dip Lookup entry for ' + dip_mm + ' mm?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletediplookup.php",
				data: {id: id},
				success: function (data) {
					table.ajax.reload(null, false);
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}
	</script>
</html>
