<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';
require '../include/permissions.php';

// Enforce access check for viewing meter readings
check_access('meter_readings', 'show');

// Active records
$sql_active = "SELECT mr.*, sh.name AS shift_name
               FROM tbl_meter_readings mr
               LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
               WHERE mr.deleted_at IS NULL
               ORDER BY mr.id DESC";
$result_active = mysqli_query($connection, $sql_active);
// If deleted_at column doesn't exist yet, fall back to all records
if ($result_active === false) {
    $sql_active   = "SELECT mr.*, sh.name AS shift_name FROM tbl_meter_readings mr LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id ORDER BY mr.id DESC";
    $result_active = mysqli_query($connection, $sql_active);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700,900&display=swap">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.20/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Meter Readings</title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }

        .page-header {
            background: var(--gradient-header);
            color:#fff; padding:18px 28px; border-radius:10px;
            margin-bottom:22px; display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.25rem; }

        .list-card {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07);
            overflow:hidden;
        }
        .list-card-title {
            background: var(--primary-gradient);
            color:#fff; padding:12px 20px;
            font-weight:600; font-size:14px;
        }

        #meterReadingTable thead th {
            background: var(--primary-color) !important; color:#fff;
            font-size:12px; font-weight:600;
        }
        #meterReadingTable tbody tr { transition:background .15s; }
        #meterReadingTable tbody tr:hover { background: var(--primary-light); }
        #meterReadingTable td { vertical-align:middle; font-size:13px; }

        .pt-cash   { background:#c8e6c9; color:#1b5e20; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }
        .pt-credit { background:#fff3e0; color:#e65100; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }
        .pt-online { background:#e3f2fd; color:#1565c0; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }

        .btn-new {
            background: var(--primary-gradient);
            border:none; color:#fff; font-weight:600;
            padding:8px 20px; border-radius:8px; font-size:13px;
            box-shadow:0 4px 12px rgba(4,32,78,0.25);
            transition:all .2s;
        }
        .btn-new:hover { background: var(--primary-hover); color:#fff; transform:translateY(-1px); }

        .dataTables_wrapper { padding:16px 20px 20px; }
    </style>
</head>
<body>
<?php include('../include/navbar.php'); ?>
<main class="main">
<div class="container-fluid pt-4 pb-5 px-4">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h4><i class="fas fa-tachometer-alt mr-2"></i>Meter Readings</h4>
            <small style="opacity:.8;">All recorded meter readings — click <i class="fas fa-eye"></i> to view details</small>
        </div>
        <?php if (has_permission('meter_readings', 'add')): ?>
        <a href="add-meter-reading.php" class="btn-new btn">
            <i class="fas fa-plus mr-1"></i> New Meter Reading
        </a>
        <?php endif; ?>
    </div>

    <!-- List Table -->
    <div class="list-card">
        <div class="list-card-title">
            <i class="fas fa-list mr-2"></i>All Readings
        </div>
        <div class="table-responsive" style="padding:0;">
    <!-- ── Active Records Table ──────────────────── -->
        <table id="meterReadingTable" class="table table-bordered table-striped" style="width:100%;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Shift</th>
                        <th style="text-align:right;">Grand Total</th>
                        <th>Created At</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result_active && mysqli_num_rows($result_active) > 0):
                    while ($row = mysqli_fetch_assoc($result_active)):
                ?>
                <tr>
                    <td data-order="<?php echo $row['id']; ?>"><strong>#<?php echo $row['id']; ?></strong></td>
                    <td data-order="<?php echo strtotime($row['date']); ?>"><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['shift_name'] ?? 'N/A'); ?></td>

                    <td style="text-align:right;font-weight:700;color: var(--primary-color);" data-order="<?php echo $row['grand_total']; ?>">
                        <?php echo number_format($row['grand_total'], 2); ?>
                    </td>
                    <td data-order="<?php echo strtotime($row['created_at']); ?>"><?php echo date('d-m-Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td style="text-align:center;">
                        <a href="view-meter-reading.php?id=<?php echo $row['id']; ?>"
                           class="btn btn-sm btn-info" title="View" style="background: var(--primary-gradient); border: none;">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="generate-pdf-meter-reading.php?id=<?php echo $row['id']; ?>" target="_blank"
                           class="btn btn-sm btn-secondary" title="Generate PDF"
                           style="background:linear-gradient(135deg,#6a1b9a,#8e24aa);border:none;color:#fff;">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No active meter readings. <a href="add-meter-reading.php">Create one now.</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>


        </div>
    </div>

</div>
</main>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.10.20/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function () {
    $('#meterReadingTable').DataTable({ order: [[0, 'desc']] });
});
</script>
</body>
</html>
