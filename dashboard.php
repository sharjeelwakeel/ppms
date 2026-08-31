<?php
require 'include/session.php';
if (!userloggedin()) {
    header('Location:login.php');
    exit;
}
require 'include/config.php';

// Auto-migrate tbl_lubricant_products: ensure reorder_level column exists and deleted_at exists
$chk_ro = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'reorder_level'");
if ($chk_ro && mysqli_num_rows($chk_ro) == 0) {
    $chk_sq = mysqli_query($connection, "SHOW COLUMNS FROM tbl_lubricant_products LIKE 'shelf_quantity'");
    if ($chk_sq && mysqli_num_rows($chk_sq) > 0) {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products CHANGE COLUMN shelf_quantity reorder_level INT(11) NOT NULL DEFAULT 0");
    } else {
        mysqli_query($connection, "ALTER TABLE tbl_lubricant_products ADD COLUMN reorder_level INT(11) NOT NULL DEFAULT 0 AFTER category");
    }
} else {
    mysqli_query($connection, "ALTER TABLE tbl_lubricant_products MODIFY COLUMN reorder_level INT(11) NOT NULL DEFAULT 0");
}

// Fetch all products and evaluate real-time stock levels vs reorder level
$sql = "
    SELECT p.id, p.name, p.price, 
           COALESCE(p.reorder_level, 0) AS reorder_level,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_purchases WHERE product_id = p.id), 0) AS total_purchased,
           COALESCE((SELECT SUM(quantity) FROM tbl_lubricant_sales WHERE product_id = p.id), 0) AS total_sold
    FROM tbl_lubricant_products p
    WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
    ORDER BY p.name ASC
";
$res = mysqli_query($connection, $sql);
$total_products_count = 0;
$low_stock_items = [];
$total_inventory_valuation = 0;

if ($res) {
    while ($p = mysqli_fetch_assoc($res)) {
        $total_products_count++;
        $current_stock = floatval($p['total_purchased']) - floatval($p['total_sold']);
        $reorder_level = floatval($p['reorder_level']);
        $p['current_stock'] = $current_stock;
        $total_inventory_valuation += ($current_stock * floatval($p['price']));
        
        // Low Stock condition: current_stock <= reorder_level (or <= 0)
        if (($reorder_level > 0 && $current_stock <= $reorder_level) || ($current_stock <= 0)) {
            $p['deficit'] = max(0, $reorder_level - $current_stock);
            $low_stock_items[] = $p;
        }
    }
}
$low_stock_count = count($low_stock_items);
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
		<link rel="stylesheet" href="include/style.css?v=1.0.1" />
		<style>
		body {
			background: #f4f6fb;
			font-family: 'Roboto', sans-serif;
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
		.dashboard-card {
			border-radius: 12px;
			border: none;
			color: #fff;
			padding: 20px;
			box-shadow: 0 4px 18px rgba(0,0,0,0.08);
			margin-bottom: 22px;
			position: relative;
			overflow: hidden;
			transition: transform 0.2s ease, box-shadow 0.2s ease;
		}
		.dashboard-card:hover {
			transform: translateY(-3px);
			box-shadow: 0 8px 25px rgba(0,0,0,0.12);
		}
		.dashboard-card .card-title {
			font-size: 13px;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			opacity: 0.85;
			margin-bottom: 8px;
			font-weight: 500;
		}
		.dashboard-card .card-value {
			font-size: 26px;
			font-weight: 700;
		}
		.dashboard-card .card-icon {
			position: absolute;
			right: 18px;
			bottom: 15px;
			font-size: 42px;
			opacity: 0.18;
		}
		.card-bg-primary { background: linear-gradient(135deg, #04204e 0%, #07347a 100%); }
		.card-bg-danger  { background: linear-gradient(135deg, #e53935 0%, #b71c1c 100%); }
		.card-bg-success { background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%); }
		.card-bg-info    { background: linear-gradient(135deg, #0288d1 0%, #01579b 100%); }
		
		#lowStockTable thead th {
			background-color: #04204e !important;
			background: var(--primary-color) !important;
			color: #fff !important;
		}
		</style>
		<title>PPMS - Dashboard</title>
	</head>
	<body>
        <?php include('include/navbar.php');?>
		<main class="main">
			<div class="container pt-4 pb-4">
				
				<div class="row mb-4 align-items-center">
					<div class="col-md-6">
						<h4><i class="fas fa-tachometer-alt mr-2 text-primary"></i>Dashboard Overview</h4>
					</div>
					<div class="col-md-6 text-right">
						<a href="lubricants/stock-report.php" class="btn btn-outline-primary btn-sm font-weight-bold mr-2" style="border-radius:6px;"><i class="fas fa-chart-bar mr-1"></i> Stock Report</a>
						<a href="lubricants/add-purchase.php" class="btn btn-primary btn-sm" style="border-radius:6px;"><i class="fas fa-plus mr-1"></i> Add Purchase</a>
					</div>
				</div>

				<?php if ($low_stock_count > 0): ?>
				<!-- Low Stock Alert Banner -->
				<div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4 p-3" style="border-radius:12px; background:#fff5f5; border-left: 6px solid #dc3545 !important;">
					<div class="mr-3 text-center" style="width:48px; height:48px; border-radius:50%; background:#ffebee; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
						<i class="fas fa-exclamation-triangle fa-2x text-danger"></i>
					</div>
					<div class="flex-grow-1">
						<h5 class="mb-1 text-danger font-weight-bold">
							<i class="fas fa-bell mr-1"></i> Low Stock Alert: <?php echo $low_stock_count; ?> <?php echo $low_stock_count == 1 ? 'Product is' : 'Products are'; ?> Below Reorder Level!
						</h5>
						<span class="text-muted" style="font-size:14px;">The inventory for these products has dropped below or reached the designated minimum threshold. Please order new stock promptly.</span>
					</div>
					<div class="ml-3 d-none d-md-block">
						<a href="lubricants/stock-report.php" class="btn btn-sm btn-outline-danger font-weight-bold" style="border-radius:6px;"><i class="fas fa-chart-bar mr-1"></i> View Stock Report</a>
					</div>
				</div>
				<?php endif; ?>

				<!-- Dashboard Metrics Row -->
				<div class="row">
					<div class="col-xl-4 col-md-6 col-sm-12">
						<div class="dashboard-card card-bg-primary">
							<div class="card-title">Total Registered Products</div>
							<div class="card-value"><?php echo number_format($total_products_count); ?></div>
							<i class="fas fa-boxes card-icon"></i>
						</div>
					</div>
					<div class="col-xl-4 col-md-6 col-sm-12">
						<div class="dashboard-card <?php echo $low_stock_count > 0 ? 'card-bg-danger' : 'card-bg-success'; ?>">
							<div class="card-title"><?php echo $low_stock_count > 0 ? 'Low Stock Alerts' : 'Inventory Status'; ?></div>
							<div class="card-value"><?php echo $low_stock_count > 0 ? number_format($low_stock_count) . ' Items Low' : 'All Healthy'; ?></div>
							<i class="fas <?php echo $low_stock_count > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> card-icon"></i>
						</div>
					</div>
					<div class="col-xl-4 col-md-6 col-sm-12">
						<div class="dashboard-card card-bg-info">
							<div class="card-title">Total Inventory Valuation</div>
							<div class="card-value">Rs. <?php echo number_format($total_inventory_valuation, 2); ?></div>
							<i class="fas fa-coins card-icon"></i>
						</div>
					</div>
				</div>

				<?php if ($low_stock_count > 0): ?>
				<!-- Low Stock Notification Table -->
				<div class="card shadow-sm border-0 mb-4" style="border-radius:12px; overflow:hidden;">
					<div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
						<h5 class="mb-0 font-weight-bold text-danger">
							<i class="fas fa-exclamation-circle mr-2"></i>Products Requiring Reorder (Stock &le; Reorder Level)
						</h5>
						<span class="badge badge-danger px-3 py-2" style="font-size:12px; border-radius:20px;">
							<?php echo $low_stock_count; ?> Alert<?php echo $low_stock_count > 1 ? 's' : ''; ?>
						</span>
					</div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table id="lowStockTable" class="table table-striped table-bordered mb-0">
								<thead>
									<tr>
										<th style="width: 60px;">#</th>
										<th>Product Name</th>
										<th>Current Stock</th>
										<th>Reorder Level</th>
										<th>Deficit</th>
										<th style="text-align:center;">Status</th>
										<th style="text-align:center; width:160px;">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php 
									$sr = 1;
									foreach ($low_stock_items as $item) {
										$cur = $item['current_stock'];
										$reorder = $item['reorder_level'];
										
										if ($cur <= 0) {
											$status = '<span class="badge badge-dark py-1 px-2"><i class="fas fa-times-circle mr-1 text-danger"></i> Out of Stock</span>';
										} else {
											$status = '<span class="badge badge-danger py-1 px-2"><i class="fas fa-exclamation-triangle mr-1"></i> Reorder Required</span>';
										}
										
										echo '
										<tr>
											<td>' . $sr++ . '</td>
											<td class="font-weight-bold" style="color:var(--primary-color);">' . htmlspecialchars($item['name']) . '</td>
											<td>
												<span class="badge badge-danger px-2 py-1 font-weight-bold" style="font-size:13px; background:#ffebee; color:#c62828 !important; border:1px solid #ffcdd2;">
													' . number_format($cur, 0) . '
												</span>
											</td>
											<td class="font-weight-bold">' . number_format($reorder, 0) . '</td>
											<td class="text-danger font-weight-bold">' . number_format($item['deficit'], 0) . '</td>
											<td class="text-center">' . $status . '</td>
											<td class="text-center">
												<a href="lubricants/add-purchase.php?product_id=' . $item['id'] . '" class="btn btn-primary btn-sm px-2 py-1 font-weight-bold" style="border-radius:6px; font-size:12px;">
													<i class="fas fa-plus mr-1"></i> Add Purchase
												</a>
											</td>
										</tr>';
									}
									?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
				<?php else: ?>
				<!-- Healthy Inventory Banner -->
				<div class="card shadow-sm border-0 mb-4 p-4 text-center" style="border-radius:12px; background:#f8fff9; border: 1px solid #c8e6c9 !important;">
					<div class="py-2">
						<i class="fas fa-check-circle fa-3x text-success mb-3"></i>
						<h5 class="text-success font-weight-bold mb-1">Inventory Levels are Healthy</h5>
						<p class="text-muted mb-0">All products are currently stocked above their configured reorder levels.</p>
					</div>
				</div>
				<?php endif; ?>

			</div>
		</main>
		<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
		<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
		<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
		<script>
		$(document).ready(function() {
			if ($('#lowStockTable').length) {
				$('#lowStockTable').DataTable({
					"order": [[ 3, "asc" ]],
					"pageLength": 10
				});
			}
		});
		</script>
	</body>
</html>