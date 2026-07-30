<?php
require '../include/session.php';
if (!userloggedin()) { header('Location:../login.php'); exit; }
require '../include/config.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: meter-reading-list.php'); exit;
}

$id = intval($_GET['id']);

// Fetch header
$header_result = mysqli_query($connection,
    "SELECT mr.*, sh.name AS shift_name
     FROM tbl_meter_readings mr
     LEFT JOIN tbl_shifts sh ON mr.shift_id = sh.id
     WHERE mr.id = $id LIMIT 1");

if ($header_result === false) {
    die('<div style="font-family:sans-serif;padding:30px;color:red;">
         <strong>DB Error:</strong> ' . mysqli_error($connection) . '
         <br><br><a href="meter-reading-list.php">Go Back</a></div>');
}
$header = mysqli_fetch_assoc($header_result);
if (!$header) { header('Location: meter-reading-list.php'); exit; }

$isSoftDeleted = !empty($header['deleted_at']);

// Fetch ALL detail rows — try with staff join first, fallback without
$details_sql = "SELECT mrd.*,
                       n.name AS nozzle_name,
                       CONCAT(st.first_name,' ',st.last_name) AS exec_name
                FROM tbl_meter_reading_details mrd
                LEFT JOIN tbl_nozzles n  ON mrd.nozzle_id = n.id
                LEFT JOIN tbl_staff   st ON st.id = mrd.staff_id
                WHERE mrd.meter_reading_id = $id
                ORDER BY mrd.id ASC";
$details_result = mysqli_query($connection, $details_sql);

if ($details_result === false) {
    // Fallback without staff_id column
    $details_result = mysqli_query($connection,
        "SELECT mrd.*, n.name AS nozzle_name, NULL AS exec_name
         FROM tbl_meter_reading_details mrd
         LEFT JOIN tbl_nozzles n ON mrd.nozzle_id = n.id
         WHERE mrd.meter_reading_id = $id
         ORDER BY mrd.id ASC");
}

if ($details_result === false) {
    die('<div style="font-family:sans-serif;padding:30px;color:red;">
         <strong>DB Error (details):</strong> ' . mysqli_error($connection) . '
         <br><br><a href="meter-reading-list.php">Go Back</a></div>');
}

$details = [];
while ($r = mysqli_fetch_assoc($details_result)) { $details[] = $r; }

// Re-calculate grand total from actual detail rows (cross-check)
$calcGrand = 0;
foreach ($details as $d) { $calcGrand += floatval($d['amount']); }

// Fetch Card Sales (All entries)
$card_sales_sql = "SELECT mrcs.*,
                          cm.name AS machine_name,
                          n.name AS nozzle_name
                   FROM tbl_meter_reading_card_sales mrcs
                   LEFT JOIN tbl_card_machines cm ON mrcs.card_machine_id = cm.id
                   LEFT JOIN tbl_nozzles n ON mrcs.nozzle_id = n.id
                   WHERE mrcs.meter_reading_id = $id
                   ORDER BY mrcs.id ASC";
$card_sales_result = mysqli_query($connection, $card_sales_sql);
$card_sales = [];
$card_sales_total = 0;
if ($card_sales_result) {
    while ($cs = mysqli_fetch_assoc($card_sales_result)) {
        $card_sales[] = $cs;
        $card_sales_total += floatval($cs['amount']);
    }
}

// Fetch Credit Sales (All entries)
$credit_sales_sql = "SELECT mrcs.*,
                            n.name AS nozzle_name,
                            i.name AS item_name
                     FROM tbl_meter_reading_credit_sales mrcs
                     LEFT JOIN tbl_nozzles n ON mrcs.nozzle_id = n.id
                     LEFT JOIN tbl_items i ON n.item_id = i.id
                     WHERE mrcs.meter_reading_id = $id
                     ORDER BY mrcs.id ASC";
$credit_sales_result = mysqli_query($connection, $credit_sales_sql);
$credit_sales = [];
$credit_sales_total = 0;
if ($credit_sales_result) {
    while ($cs = mysqli_fetch_assoc($credit_sales_result)) {
        $credit_sales[] = $cs;
        $credit_sales_total += floatval($cs['amount']);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.11.2/css/all.min.css">
    <link rel="stylesheet" href="../include/style.css?v=1.0.1">
    <title>PPMS - Meter Reading #<?php echo $id; ?></title>
    <style>
        body { background:#f4f6fb; font-family:'Roboto',sans-serif; }

        /* Page header */
        .page-header {
            background: var(--gradient-header);
            color:#fff; padding:16px 24px; border-radius:10px;
            margin-bottom:20px; display:flex;
            align-items:center; justify-content:space-between;
            box-shadow:0 4px 18px rgba(4,32,78,0.18);
        }
        .page-header h4 { margin:0; font-weight:700; font-size:1.2rem; }

        /* Info bar */
        .info-bar {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 10px rgba(0,0,0,0.07);
            padding:16px 22px; margin-bottom:18px;
            display:flex; flex-wrap:wrap; gap:24px; align-items:center;
        }
        .info-item .lbl { font-size:10px; text-transform:uppercase;
            color:#999; font-weight:700; letter-spacing:.5px; }
        .info-item .val { font-size:15px; font-weight:700; color: var(--primary-color); margin-top:1px; }
        .info-item .val.grand { font-size:22px; color: var(--primary-hover); }

        /* Deleted banner */
        .deleted-banner {
            background:#ffebee; border-left:5px solid #c62828;
            color:#b71c1c; padding:10px 18px; border-radius:6px;
            margin-bottom:14px; font-weight:600; font-size:13px;
        }

        /* Card wrapper */
        .card-wrap {
            background:#fff; border-radius:10px;
            box-shadow:0 2px 10px rgba(0,0,0,0.07);
            overflow:hidden; margin-bottom:20px;
        }
        .card-title {
            background: var(--primary-gradient);
            color:#fff; padding:11px 20px;
            font-weight:600; font-size:13px;
            display:flex; align-items:center; justify-content:space-between;
        }

        /* Main reading table */
        .rtable { margin:0; font-size:13px; }
        .rtable thead th {
            background:#2c3e50; color:#fff;
            font-size:11px; font-weight:600;
            padding:10px 10px; white-space:nowrap;
            border:none; vertical-align:middle;
            text-align:center;
        }
        .rtable tbody tr { transition:background .12s; }
        .rtable tbody tr:hover { background: var(--primary-light) !important; }
        .rtable td { vertical-align:middle; padding:9px 10px; border-color:#e8ecf0; font-size:13px; }

        .td-nozzle  { font-weight:700; color: var(--primary-color); }
        .td-rate    { background:#fff8e1; font-weight:700; color:#e65100; text-align:right; }
        .td-last    { background:#fafafa; text-align:right; color:#555; }
        .td-curr    { background:#fafafa; font-weight:600; text-align:right; color:#333; }
        .td-sale    { background:#e8f5e9; font-weight:700; color:#2e7d32; text-align:right; font-size:14px; }
        .td-test    { background:#fff3e0; text-align:right; color:#e65100; }
        .td-net     { background:#e3f2fd; font-weight:700; color:#1565c0; text-align:right; font-size:14px; }
        .td-amount  { background:#ede7f6; font-weight:700; color:#4527a0; text-align:right; font-size:14px; }

        /* Grand total row */
        .grand-row td {
            background: var(--primary-gradient);
            color:#fff; font-weight:700; padding:13px 10px;
            font-size:15px;
        }
        .grand-row td.grand-val { font-size:20px; text-align:right; }

        /* Empty state */
        .empty-details {
            text-align:center; padding:40px 20px; color:#999;
        }
        .empty-details .icon { font-size:48px; color:#ddd; margin-bottom:12px; }

        /* badges */
        .badge-nozzle { background: var(--primary-gradient);
            color:#fff; padding:3px 10px; border-radius:20px;
            font-size:12px; font-weight:700; }
        .badge-petrol { background:#2e7d32; color:#fff; padding:2px 9px; border-radius:20px; font-size:11px; }
        .badge-diesel { background:#bf360c; color:#fff; padding:2px 9px; border-radius:20px; font-size:11px; }
        .badge-item   { background:#4a148c; color:#fff; padding:2px 9px; border-radius:20px; font-size:11px; }

        /* Print btn */
        .btn-print {
            background: var(--primary-gradient);
            border:none; color:#fff; font-weight:600;
            padding:8px 20px; border-radius:8px; font-size:13px;
            box-shadow:0 4px 12px rgba(4,32,78,0.2);
            cursor:pointer; transition:all .2s;
        }
        .btn-print:hover { background: var(--primary-hover); transform:translateY(-1px); color:#fff; }

        /* Print Layout */
        .print-only { display:none; }

        @media print {
            .no-print, .navbar, .page-header { display:none !important; }
            body  { background:#fff !important; font-size:11px; }
            main  { padding:0 !important; }
            .container-fluid { padding:0 !important; }
            .card-wrap, .info-bar { box-shadow:none !important; border-radius:0 !important; margin-bottom:8px !important; }
            .screen-only { display:none !important; }
            .print-only  { display:block !important; }

            .ph { text-align:center; border-bottom:2px solid var(--primary-color); padding-bottom:8px; margin-bottom:10px; }
            .ph h2 { font-size:18px; font-weight:700; color: var(--primary-color); margin:0 0 2px; }
            .ph p  { margin:0; font-size:11px; color:#666; }

            .pm { display:grid; grid-template-columns:repeat(4,1fr); gap:5px;
                  border:1px solid #ddd; padding:8px 10px; margin-bottom:10px; border-radius:3px; }
            .pm-item .lbl2 { font-size:8px; text-transform:uppercase; color:#999; font-weight:700; }
            .pm-item .val2 { font-size:12px; font-weight:700; color: var(--primary-color); }

            .pt { width:100%; border-collapse:collapse; font-size:10.5px; }
            .pt th { background: var(--primary-color) !important; color:#fff !important;
                     padding:6px 5px; font-size:9.5px; font-weight:700;
                     border:1px solid var(--primary-color); text-align:center;
                     -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pt td { padding:5px 5px; border:1px solid #ccc; }
            .pt td.r { text-align:right; font-weight:600; }
            .pt td.c { text-align:center; }
            .pt tbody tr:nth-child(even) td { background:#f5f7ff; }
            .pfooter { margin-top:14px; border-top:1px solid #ccc; padding-top:6px;
                       display:flex; justify-content:space-between; font-size:9px; color:#999; }
        }
    </style>
</head>
<body>
<?php include('../include/navbar.php'); ?>
<main class="main">
<div class="container-fluid pt-4 pb-5 px-4">

<?php
$grandDisplay = $calcGrand > 0 ? $calcGrand : floatval($header['grand_total']);
?>

<!-- SCREEN LAYOUT -->
<div class="page-header no-print">
    <div>
        <h4><i class="fas fa-tachometer-alt mr-2"></i>Meter Reading &nbsp;#<?php echo $id; ?></h4>
        <small style="opacity:.8;">
            <?php echo date('d M Y', strtotime($header['date'])); ?>
            &nbsp;|&nbsp; <?php echo htmlspecialchars($header['shift_name'] ?? 'N/A'); ?>
            <?php if ($isSoftDeleted): ?>
            <span style="background:#c62828;color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;margin-left:8px;">
                <i class="fas fa-trash-alt mr-1"></i>DELETED
            </span>
            <?php endif; ?>
        </small>
    </div>
    <div>
        <a href="meter-reading-list.php" class="btn btn-outline-light btn-sm mr-2">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
        <a href="generate-pdf-meter-reading.php?id=<?php echo $id; ?>" target="_blank"
           class="btn-print mr-2" style="display:inline-block;text-decoration:none;background:linear-gradient(135deg,#6a1b9a,#8e24aa);">
            <i class="fas fa-file-pdf mr-1"></i> Full PDF
        </a>
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print mr-1"></i> Print
        </button>
    </div>
</div>

<?php if ($isSoftDeleted): ?>
<div class="deleted-banner no-print">
    <i class="fas fa-trash-alt mr-2"></i>
    Soft-deleted on <strong><?php echo date('d-m-Y h:i A', strtotime($header['deleted_at'])); ?></strong>.
    All data is preserved below.
</div>
<?php endif; ?>

<!-- Info bar -->
<div class="info-bar screen-only">
    <div class="info-item">
        <div class="lbl">Reading #</div>
        <div class="val"><?php echo $id; ?></div>
    </div>
    <div class="info-item">
        <div class="lbl">Date</div>
        <div class="val"><?php echo date('d-m-Y', strtotime($header['date'])); ?></div>
    </div>
    <div class="info-item">
        <div class="lbl">Shift</div>
        <div class="val"><?php echo htmlspecialchars($header['shift_name'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
        <div class="lbl">Nozzles</div>
        <div class="val"><?php echo count($details); ?></div>
    </div>
    <div class="info-item" style="margin-left:auto;">
        <div class="lbl">Grand Total</div>
        <div class="val grand">PKR <?php echo number_format($grandDisplay, 2); ?></div>
    </div>
</div>

<!-- Main Nozzle Detail Table -->
<div class="card-wrap screen-only">
    <div class="card-title">
        <span><i class="fas fa-gas-pump mr-2"></i>Nozzle Readings &amp; Calculations</span>
        <span style="font-size:11px;opacity:.8;font-weight:400;">
            Sale = Current − Last &nbsp;|&nbsp; Net Sale = Sale − Test &nbsp;|&nbsp; Amount = Net Sale × Rate
        </span>
    </div>

    <?php if (empty($details)): ?>
    <div class="empty-details">
        <div class="icon"><i class="fas fa-database"></i></div>
        <p><strong>No nozzle detail rows found for this reading.</strong></p>
    </div>
    <?php else: ?>

    <div class="table-responsive">
        <table class="table table-bordered rtable">
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Nozzle</th>
                    <th>Item</th>
                    <th>Sales Executive</th>
                    <th>Rate</th>
                    <th>Last Reading</th>
                    <th>Current Reading</th>
                    <th style="background:#1b5e20;">Sale Reading</th>
                    <th style="background:#bf360c;">Test Reading</th>
                    <th style="background:#0d47a1;">Net Sale</th>
                    <th style="background:#4a148c;">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php $rn = 1; foreach ($details as $d):
                $itLow   = strtolower($d['item_type'] ?? '');
                $itClass = strpos($itLow,'petrol')!==false ? 'badge-petrol'
                         : (strpos($itLow,'diesel')!==false ? 'badge-diesel' : 'badge-item');

                $saleR  = floatval($d['sale_reading']);
                $testR  = floatval($d['test_reading']);
                $netS   = floatval($d['net_sale']);
                $amt    = floatval($d['amount']);
                $price  = floatval($d['price']);
                $lastR  = floatval($d['last_reading']);
                $currR  = floatval($d['current_reading']);
            ?>
            <tr>
                <td class="text-center text-muted font-weight-bold"><?php echo $rn++; ?></td>
                <td class="td-nozzle">
                    <span class="badge-nozzle"><?php echo htmlspecialchars($d['nozzle_name'] ?? 'N/A'); ?></span>
                </td>
                <td><span class="<?php echo $itClass; ?>"><?php echo htmlspecialchars($d['item_type'] ?? 'N/A'); ?></span></td>
                <td class="td-exec"><?php echo htmlspecialchars($d['exec_name'] ?? '—'); ?></td>
                <td class="td-rate"><?php echo number_format($price, 2); ?></td>
                <td class="td-last"><?php echo number_format($lastR, 2); ?></td>
                <td class="td-curr"><?php echo number_format($currR, 2); ?></td>
                <td class="td-sale"><?php echo number_format($saleR, 2); ?></td>
                <td class="td-test"><?php echo number_format($testR, 2); ?></td>
                <td class="td-net"><?php echo number_format($netS, 2); ?></td>
                <td class="td-amount"><?php echo number_format($amt, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="grand-row">
                    <td colspan="10" class="text-right pr-3" style="letter-spacing:1px;">
                        <i class="fas fa-sigma mr-2"></i>GRAND TOTAL
                    </td>
                    <td class="grand-val"><?php echo number_format($grandDisplay, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($header['remarks'])): ?>
<!-- Remarks -->
<div class="card-wrap screen-only no-print mt-4">
    <div class="card-title">
        <span><i class="fas fa-comment-dots mr-2"></i>Remarks / Notes</span>
    </div>
    <div class="card-body py-3" style="font-size:14px; color:#444; background:#fafbff;">
        <?php echo nl2br(htmlspecialchars($header['remarks'])); ?>
    </div>
</div>
<?php endif; ?>

<!-- Card Sale Details (ALL Entries Table) -->
<?php if (!empty($card_sales)): ?>
<div class="card-wrap screen-only mt-4">
    <div class="card-title" style="background:linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);">
        <span><i class="fas fa-credit-card mr-2"></i>Card Sale Details (<?php echo count($card_sales); ?> Entries)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-striped mb-0" style="font-size:13px;">
                <thead class="bg-light">
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Nozzle</th>
                        <th>Card Machine</th>
                        <th>Batch No</th>
                        <th class="text-center">No. of Cards</th>
                        <th class="text-right">Amount (Rs.)</th>
                        <th class="text-right">Service Charges</th>
                        <th class="text-right">Net Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $csn = 1; foreach ($card_sales as $cs): ?>
                    <tr>
                        <td class="text-center font-weight-bold text-muted"><?php echo $csn++; ?></td>
                        <td><strong><?php echo htmlspecialchars($cs['nozzle_name'] ?? '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars($cs['machine_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($cs['batch_no'] ?? '—'); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($cs['no_of_cards'] ?? '0'); ?></td>
                        <td class="text-right font-weight-bold text-info"><?php echo number_format($cs['amount'], 2); ?></td>
                        <td class="text-right text-danger"><?php echo number_format($cs['service_charges'], 2); ?></td>
                        <td class="text-right font-weight-bold text-success"><?php echo number_format($cs['net_amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="font-weight-bold bg-light">
                        <td colspan="5" class="text-right">Total Card Sale:</td>
                        <td class="text-right text-info">PKR <?php echo number_format($card_sales_total, 2); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Credit Sale Details (ALL Entries Table) -->
<?php if (!empty($credit_sales)): ?>
<div class="card-wrap screen-only mt-4">
    <div class="card-title" style="background:linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
        <span><i class="fas fa-file-invoice mr-2"></i>Credit Sale Details (<?php echo count($credit_sales); ?> Entries)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-striped mb-0" style="font-size:12px;">
                <thead class="bg-light">
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Nozzle</th>
                        <th>Item</th>
                        <th>Slip Date</th>
                        <th>Slip No</th>
                        <th>Account No</th>
                        <th>Vehicle No</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Sale Rate</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Cash Rate</th>
                        <th class="text-right">Issue Qty</th>
                        <th class="text-right">Balance 1</th>
                        <th class="text-right">Balance 2</th>
                        <th class="text-right">Wasoli</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $crn = 1; foreach ($credit_sales as $crs): ?>
                    <tr>
                        <td class="text-center font-weight-bold text-muted"><?php echo $crn++; ?></td>
                        <td><strong><?php echo htmlspecialchars($crs['nozzle_name'] ?? '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars($crs['item_name'] ?? '—'); ?></td>
                        <td><?php echo date('d-m-Y', strtotime($crs['slip_date'])); ?></td>
                        <td><?php echo htmlspecialchars($crs['slip_no'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($crs['account_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($crs['vehicle_number'] ?? '—'); ?></td>
                        <td class="text-right"><?php echo number_format($crs['quantity'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['rate'], 2); ?></td>
                        <td class="text-right font-weight-bold text-warning">PKR <?php echo number_format($crs['amount'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['cash_rate'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['issue_quantity'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['balance_1'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['balance_2'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($crs['wasoli'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="font-weight-bold bg-light">
                        <td colspan="9" class="text-right">Total Credit Sale:</td>
                        <td class="text-right text-warning">PKR <?php echo number_format($credit_sales_total, 2); ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- PRINT LAYOUT -->
<div class="print-only">

    <div class="ph">
        <h2>PPMS &mdash; Petrol Pump Management System</h2>
        <p>Meter Reading Report &nbsp;&bull;&nbsp; Document #<?php echo $id; ?></p>
        <?php if ($isSoftDeleted): ?>
        <span class="del-stamp">&#x26A0; SOFT DELETED &mdash; <?php echo date('d-m-Y h:i A', strtotime($header['deleted_at'])); ?></span>
        <?php endif; ?>
    </div>

    <div class="pm">
        <div class="pm-item"><div class="lbl2">Reading #</div><div class="val2"><?php echo $id; ?></div></div>
        <div class="pm-item"><div class="lbl2">Date</div><div class="val2"><?php echo date('d-m-Y', strtotime($header['date'])); ?></div></div>
        <div class="pm-item"><div class="lbl2">Shift</div><div class="val2"><?php echo htmlspecialchars($header['shift_name'] ?? 'N/A'); ?></div></div>
        <div class="pm-item"><div class="lbl2">Nozzles</div><div class="val2"><?php echo count($details); ?></div></div>
        <div class="pm-item"><div class="lbl2">Created At</div><div class="val2"><?php echo date('d-m-Y h:i A', strtotime($header['created_at'])); ?></div></div>
        <div class="pm-item"><div class="lbl2">Grand Total</div><div class="val2" style="font-size:15px;"><?php echo number_format($grandDisplay, 2); ?></div></div>
        <div class="pm-item"><div class="lbl2">Printed At</div><div class="val2"><?php echo date('d-m-Y h:i A'); ?></div></div>
    </div>

    <table class="pt">
        <thead>
            <tr>
                <th>#</th>
                <th>Nozzle</th>
                <th>Item</th>
                <th>Sales Exec.</th>
                <th class="r">Rate</th>
                <th class="r">Last Rdg.</th>
                <th class="r">Curr. Rdg.</th>
                <th class="r">Sale Rdg.</th>
                <th class="r">Test Rdg.</th>
                <th class="r">Net Sale</th>
                <th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php $pn = 1; foreach ($details as $d): ?>
        <tr>
            <td class="c"><?php echo $pn++; ?></td>
            <td><?php echo htmlspecialchars($d['nozzle_name'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($d['item_type'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($d['exec_name'] ?? '—'); ?></td>
            <td class="r"><?php echo number_format($d['price'], 2); ?></td>
            <td class="r"><?php echo number_format($d['last_reading'], 2); ?></td>
            <td class="r"><?php echo number_format($d['current_reading'], 2); ?></td>
            <td class="r pt-sale"><?php echo number_format($d['sale_reading'], 2); ?></td>
            <td class="r"><?php echo number_format($d['test_reading'], 2); ?></td>
            <td class="r pt-net"><?php echo number_format($d['net_sale'], 2); ?></td>
            <td class="r pt-amount"><?php echo number_format($d['amount'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="10" class="pt-grand" style="text-align:right;">GRAND TOTAL</td>
                <td class="pt-grand" style="font-size:14px;"><?php echo number_format($grandDisplay, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($header['remarks'])): ?>
    <div style="margin-top:10px; border:1px solid #ddd; padding:8px 10px; border-radius:3px;">
        <span style="font-size:8px; text-transform:uppercase; color:#999; font-weight:700; display:block; margin-bottom:3px;">Remarks</span>
        <div style="font-size:10.5px; color:#333;"><?php echo nl2br(htmlspecialchars($header['remarks'])); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($card_sales)): ?>
    <h3 style="font-size:12px; font-weight:700; margin:15px 0 5px; color:#2c3e50; border-bottom:1px solid #2c3e50; padding-bottom:3px;"><i class="fas fa-credit-card mr-1"></i>Card Sale Details</h3>
    <table class="pt" style="font-size:9.5px;">
        <thead>
            <tr style="background:#f5f5f5;">
                <th>#</th>
                <th>Nozzle</th>
                <th>Card Machine</th>
                <th>Batch No</th>
                <th class="c">Cards</th>
                <th class="r">Amount (Rs.)</th>
                <th class="r">Service Charges</th>
                <th class="r">Net Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php $pcsn = 1; foreach ($card_sales as $cs): ?>
            <tr>
                <td class="c"><?php echo $pcsn++; ?></td>
                <td><strong><?php echo htmlspecialchars($cs['nozzle_name'] ?? '—'); ?></strong></td>
                <td><?php echo htmlspecialchars($cs['machine_name'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($cs['batch_no'] ?? '—'); ?></td>
                <td class="c"><?php echo htmlspecialchars($cs['no_of_cards'] ?? '0'); ?></td>
                <td class="r" style="font-weight:bold;">Rs. <?php echo number_format($cs['amount'], 2); ?></td>
                <td class="r">Rs. <?php echo number_format($cs['service_charges'], 2); ?></td>
                <td class="r" style="font-weight:bold;">Rs. <?php echo number_format($cs['net_amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold; background:#eceff1;">
                <td colspan="5" style="text-align:right;">Total Card Sale:</td>
                <td class="r">Rs. <?php echo number_format($card_sales_total, 2); ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <?php if (!empty($credit_sales)): ?>
    <h3 style="font-size:12px; font-weight:700; margin:15px 0 5px; color:#2c3e50; border-bottom:1px solid #2c3e50; padding-bottom:3px;"><i class="fas fa-file-invoice mr-1"></i>Credit Sale Details</h3>
    <table class="pt" style="font-size:9.5px;">
        <thead>
            <tr style="background:#f5f5f5;">
                <th>#</th>
                <th>Nozzle</th>
                <th>Item</th>
                <th>Slip Date</th>
                <th>Slip No</th>
                <th>Account No</th>
                <th>Vehicle No</th>
                <th class="r">Qty</th>
                <th class="r">Rate</th>
                <th class="r">Amount</th>
                <th class="r">Cash Rate</th>
                <th class="r">Issue Qty</th>
                <th class="r">Bal 1</th>
                <th class="r">Bal 2</th>
                <th class="r">Wasoli</th>
            </tr>
        </thead>
        <tbody>
            <?php $pcrn = 1; foreach ($credit_sales as $crs): ?>
            <tr>
                <td class="c"><?php echo $pcrn++; ?></td>
                <td><strong><?php echo htmlspecialchars($crs['nozzle_name'] ?? '—'); ?></strong></td>
                <td><?php echo htmlspecialchars($crs['item_name'] ?? '—'); ?></td>
                <td><?php echo date('d-m-Y', strtotime($crs['slip_date'])); ?></td>
                <td><?php echo htmlspecialchars($crs['slip_no'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($crs['account_number'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($crs['vehicle_number'] ?? '—'); ?></td>
                <td class="r"><?php echo number_format($crs['quantity'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['rate'], 2); ?></td>
                <td class="r" style="font-weight:bold;">Rs. <?php echo number_format($crs['amount'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['cash_rate'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['issue_quantity'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['balance_1'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['balance_2'], 2); ?></td>
                <td class="r"><?php echo number_format($crs['wasoli'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:bold; background:#eceff1;">
                <td colspan="9" style="text-align:right;">Total Credit Sale:</td>
                <td class="r">Rs. <?php echo number_format($credit_sales_total, 2); ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <div class="pfooter">
        <span>PPMS &mdash; Petrol Pump Management System</span>
        <span>Reading #<?php echo $id; ?> &nbsp;|&nbsp; Printed: <?php echo date('d-m-Y h:i A'); ?></span>
        <?php if ($isSoftDeleted): ?>
        <span style="color:#c62828;font-weight:700;">&#x26A0; SOFT DELETED RECORD</span>
        <?php else: ?>
        <span>Active Record</span>
        <?php endif; ?>
    </div>
</div>

</div>
</main>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>
