<?php
require '../include/session.php';
if (!userloggedin()) {
    header('Location:../login.php');
    exit;
}
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing dip chart
check_access('tanks', 'show');

$canAdd    = has_permission('tanks', 'add');
$canEdit   = has_permission('tanks', 'edit');
$canDelete = has_permission('tanks', 'delete');

$tank_id = isset($_GET['tank_id']) ? intval($_GET['tank_id']) : 0;
if ($tank_id <= 0) {
    header('Location:tanks-list.php');
    exit;
}

// Fetch Tank Info
$stmt_tank = mysqli_prepare($connection, "SELECT t.*, i.name AS item_name, i.unit AS item_unit 
                                          FROM tbl_tanks t 
                                          LEFT JOIN tbl_items i ON t.item_id = i.id 
                                          WHERE t.id = ? LIMIT 1");
$tank = null;
if ($stmt_tank) {
    mysqli_stmt_bind_param($stmt_tank, "i", $tank_id);
    mysqli_stmt_execute($stmt_tank);
    $res_tank = mysqli_stmt_get_result($stmt_tank);
    $tank = mysqli_fetch_assoc($res_tank);
    mysqli_stmt_close($stmt_tank);
}

if (!$tank) {
    header('Location:tanks-list.php');
    exit;
}

// Fetch Attached Active Nozzles
$nozzles_list = [];
$stmt_noz = mysqli_prepare($connection, "SELECT name FROM tbl_nozzles WHERE tank_id = ? AND status = 'Active'");
if ($stmt_noz) {
    mysqli_stmt_bind_param($stmt_noz, "i", $tank_id);
    mysqli_stmt_execute($stmt_noz);
    $res_noz = mysqli_stmt_get_result($stmt_noz);
    while ($row_noz = mysqli_fetch_assoc($res_noz)) {
        $nozzles_list[] = $row_noz['name'];
    }
    mysqli_stmt_close($stmt_noz);
}
$attached_nozzles_str = !empty($nozzles_list) ? implode(', ', $nozzles_list) : 'None';

// Fetch Latest Log Stats
$latest_balance = 0.00;
$latest_book_balance = 0.00;
$latest_gain_loss = 0.00;

$stmt_latest = mysqli_prepare($connection, "SELECT balance, book_balance, per_dip_gain_loss FROM tbl_tank_dip_logs WHERE tank_id = ? AND deleted_at IS NULL ORDER BY date DESC, id DESC LIMIT 1");
if ($stmt_latest) {
    mysqli_stmt_bind_param($stmt_latest, "i", $tank_id);
    mysqli_stmt_execute($stmt_latest);
    $res_latest = mysqli_stmt_get_result($stmt_latest);
    if ($row_latest = mysqli_fetch_assoc($res_latest)) {
        $latest_balance = floatval($row_latest['balance']);
        $latest_book_balance = floatval($row_latest['book_balance']);
        $latest_gain_loss = floatval($row_latest['per_dip_gain_loss']);
    }
    mysqli_stmt_close($stmt_latest);
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
        .btn-primary {
            background-color: #04204e !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            color: #fff !important;
        }
        .btn-primary:hover { opacity: 0.9; }
        #dipLogsTable thead th {
            background-color: #04204e !important;
            background: var(--primary-color) !important;
            color: #fff !important;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        #dipLogsTable tbody td {
            white-space: nowrap;
            vertical-align: middle;
        }
        .card-stat {
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            background: #fff;
        }
        .badge-gain { background-color: #28a745; color: #fff; font-size: 0.85rem; }
        .badge-loss { background-color: #dc3545; color: #fff; font-size: 0.85rem; }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
		</style>
		<title>Dip Chart - <?php echo htmlspecialchars($tank['tank_name']); ?></title>
	</head>
	<body>
        <?php include('../include/navbar.php');?>
		<main class="main">
			<div class="container-fluid pl-4 pr-4 pt-4 pb-4">
				<div class="row mb-4 align-items-center">
					<div class="col-md-7 mb-2 mb-md-0">
						<h4>
                            <i class="fas fa-chart-line mr-2 text-primary"></i>Dip Chart Log - 
                            <span style="color: var(--primary-color);" class="font-weight-bold"><?php echo htmlspecialchars($tank['tank_name']); ?></span>
                            <span class="badge badge-secondary ml-2"><?php echo htmlspecialchars($tank['item_name'] ?? 'Product'); ?></span>
                        </h4>
                        <small class="text-muted"><i class="fas fa-gas-pump mr-1"></i> Attached Nozzles: <strong><?php echo htmlspecialchars($attached_nozzles_str); ?></strong></small>
					</div>
					<div class="col-md-5 text-md-right">
                        <?php if ($canAdd): ?>
						<a href="add-dip-log.php?tank_id=<?php echo $tank_id; ?>" class="btn btn-primary mr-2 mb-1"><i class="fas fa-plus mr-1"></i> Add Dip Log</a>
                        <?php endif; ?>
						<a href="tanks-list.php" class="btn btn-secondary mb-1"><i class="fas fa-arrow-left mr-1"></i> Back to Tanks</a>
					</div>
				</div>

                <!-- Metric Summary Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="card card-stat p-3 h-100">
                            <span class="text-muted small font-weight-bold text-uppercase">Storage Capacity</span>
                            <h4 class="mb-0 font-weight-bold mt-1" style="color: var(--primary-color);">
                                <?php echo number_format($tank['storage_capacity'], 2); ?> <small>Ltrs</small>
                            </h4>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="card card-stat p-3 h-100" style="border-left-color: #17a2b8;">
                            <span class="text-muted small font-weight-bold text-uppercase">Current Dip Balance</span>
                            <h4 class="mb-0 font-weight-bold mt-1 text-info">
                                <?php echo number_format($latest_balance, 2); ?> <small>Ltrs</small>
                            </h4>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="card card-stat p-3 h-100" style="border-left-color: #6c757d;">
                            <span class="text-muted small font-weight-bold text-uppercase">Latest Book Balance</span>
                            <h4 class="mb-0 font-weight-bold mt-1 text-secondary">
                                <?php echo number_format($latest_book_balance, 2); ?> <small>Ltrs</small>
                            </h4>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
                        <div class="card card-stat p-3 h-100" style="border-left-color: <?php echo $latest_gain_loss >= 0 ? '#28a745' : '#dc3545'; ?>;">
                            <span class="text-muted small font-weight-bold text-uppercase">Latest Per Dip Gain/Loss</span>
                            <h4 class="mb-0 font-weight-bold mt-1 <?php echo $latest_gain_loss >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($latest_gain_loss >= 0 ? '+' : '') . number_format($latest_gain_loss, 2); ?> <small>Ltrs</small>
                            </h4>
                        </div>
                    </div>
                </div>

                <!-- Dip Logs Data Table Card -->
				<div class="card shadow-sm border-0">
                    <div class="card-body p-3">
                        <div class="table-responsive">
                            <table id="dipLogsTable" class="table table-striped table-bordered w-100">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Shift</th>
                                        <th>Nozzle Meters</th>
                                        <th>Dip (mm)</th>
                                        <th>Balance (Ltrs)</th>
                                        <th>Addition (Ltrs)</th>
                                        <th>Usage (Ltrs)</th>
                                        <th>Book Balance</th>
                                        <th>Per Dip Gain/Loss</th>
                                        <th>Overall Gain/Loss</th>
                                        <th>Accumulative PMG</th>
                                        <?php if ($canEdit || $canDelete): ?>
                                        <th style="text-align: center;">Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Pre-fetch nozzle meters from tbl_tank_dip_meter_logs
                                    $meter_logs = [];
                                    $q_ml = mysqli_query($connection, "SELECT ml.dip_log_id, ml.nozzle_id, ml.reading, n.name AS nozzle_name 
                                                                        FROM tbl_tank_dip_meter_logs ml 
                                                                        JOIN tbl_tank_dip_logs dl ON ml.dip_log_id = dl.id 
                                                                        JOIN tbl_nozzles n ON ml.nozzle_id = n.id 
                                                                        WHERE dl.tank_id = $tank_id AND dl.deleted_at IS NULL 
                                                                        ORDER BY n.name ASC");
                                    if ($q_ml) {
                                        while ($m_row = mysqli_fetch_assoc($q_ml)) {
                                            $meter_logs[$m_row['dip_log_id']][] = $m_row;
                                        }
                                    }

                                    // Pre-fetch daily nozzle readings from tbl_daily_nozzle_readings as fallback
                                    $daily_readings = [];
                                    $q_dr = mysqli_query($connection, "SELECT dnr.date, dnr.shift_id, dnr.closing_reading, n.name AS nozzle_name 
                                                                       FROM tbl_daily_nozzle_readings dnr 
                                                                       JOIN tbl_nozzles n ON dnr.nozzle_id = n.id 
                                                                       WHERE dnr.tank_id = $tank_id 
                                                                       ORDER BY n.name ASC");
                                    if ($q_dr) {
                                        while ($d_row = mysqli_fetch_assoc($q_dr)) {
                                            $daily_readings[$d_row['date'] . '_' . $d_row['shift_id']][] = $d_row;
                                        }
                                    }

                                    $sql_logs = "SELECT d.*, s.name AS shift_name 
                                                 FROM tbl_tank_dip_logs d 
                                                 LEFT JOIN tbl_shifts s ON d.shift_id = s.id 
                                                 WHERE d.tank_id = $tank_id AND d.deleted_at IS NULL 
                                                 ORDER BY d.date DESC, d.id DESC";
                                    $result_logs = mysqli_query($connection, $sql_logs);
                                    if ($result_logs && mysqli_num_rows($result_logs) > 0) {
                                        while ($row = mysqli_fetch_assoc($result_logs)) {
                                            $gl_class = floatval($row['per_dip_gain_loss']) >= 0 ? 'badge-gain' : 'badge-loss';
                                            $gl_sign = floatval($row['per_dip_gain_loss']) >= 0 ? '+' : '';
                                            
                                            $ov_class = floatval($row['overall_gain_loss']) >= 0 ? 'text-success' : 'text-danger';
                                            $ov_sign = floatval($row['overall_gain_loss']) >= 0 ? '+' : '';

                                            // Format nozzle meters badges
                                            $m_html = '';
                                            if (!empty($meter_logs[$row['id']])) {
                                                foreach ($meter_logs[$row['id']] as $mlog) {
                                                    $m_html .= '<span class="badge badge-light border text-dark font-weight-normal mr-1 mb-1"><strong>' . htmlspecialchars($mlog['nozzle_name']) . ':</strong> ' . number_format($mlog['reading'], 2) . '</span>';
                                                }
                                            } elseif (!empty($daily_readings[$row['date'] . '_' . $row['shift_id']])) {
                                                foreach ($daily_readings[$row['date'] . '_' . $row['shift_id']] as $dlog) {
                                                    $m_html .= '<span class="badge badge-light border text-dark font-weight-normal mr-1 mb-1"><strong>' . htmlspecialchars($dlog['nozzle_name']) . ':</strong> ' . number_format($dlog['closing_reading'], 2) . '</span>';
                                                }
                                            } else {
                                                $m_html = '<span class="text-muted small font-italic">-</span>';
                                            }

                                            echo '
                                                <tr>
                                                    <td>'.$row['id'].'</td>
                                                    <td>'.date("d-m-Y", strtotime($row['date'])).'</td>
                                                    <td>'.htmlspecialchars($row['shift_name'] ?? 'Shift #'.$row['shift_id']).'</td>
                                                    <td>'.$m_html.'</td>
                                                    <td class="font-weight-bold">'.number_format($row['dip_mm'], 2).' mm</td>
                                                    <td class="font-weight-bold text-primary">'.number_format($row['balance'], 2).'</td>
                                                    <td>'.number_format($row['addition'], 2).'</td>
                                                    <td>'.number_format($row['usage_litre'], 2).'</td>
                                                    <td class="font-weight-bold">'.number_format($row['book_balance'], 2).'</td>
                                                    <td><span class="badge '.$gl_class.' px-2 py-1">'.$gl_sign.number_format($row['per_dip_gain_loss'], 2).'</span></td>
                                                    <td class="'.$ov_class.' font-weight-bold">'.$ov_sign.number_format($row['overall_gain_loss'], 2).'</td>
                                                    <td>'.number_format($row['accumulative_pmg'], 2).'</td>';
                                            
                                            if ($canEdit || $canDelete) {
                                                echo '<td class="text-center">';
                                                if ($canEdit) {
                                                    echo '<a href="edit-dip-log.php?id='.$row['id'].'&tank_id='.$tank_id.'" class="btn btn-sm btn-outline-primary mr-1" title="Edit"><i class="fas fa-edit"></i></a>';
                                                }
                                                if ($canDelete) {
                                                    echo '<a class="btn btn-sm btn-outline-danger" onclick="deletediplog('.$row['id'].')" title="Delete"><i class="fas fa-trash-alt"></i></a>';
                                                }
                                                echo '</td>';
                                            }
                                            
                                            echo '</tr>';
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
			</div>
		</main>
    </body>
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
	<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
	<script>
	$(document).ready(function() {
		$('#dipLogsTable').DataTable({
			"order": [[ 0, "desc" ]]
		});
	});

	function deletediplog(id){
		if(confirm('Are you sure you want to delete this dip log entry?')) {
			$.ajax({
				type: "POST",
				url: "../include/deletediplog.php",
				data: {id: id},
				success: function (data) {
					location.reload();
				},
				error: function (data) {
					console.log(data);
				}
			});
		}
	}
	</script>
</html>
