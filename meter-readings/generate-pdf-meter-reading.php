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

if ($header_result === false || !($header = mysqli_fetch_assoc($header_result))) {
    header('Location: meter-reading-list.php'); exit;
}

// Fetch detail rows
$details_result = mysqli_query($connection,
    "SELECT mrd.*,
            n.name AS nozzle_name,
            CONCAT(st.first_name,' ',st.last_name) AS exec_name
     FROM tbl_meter_reading_details mrd
     LEFT JOIN tbl_nozzles n  ON mrd.nozzle_id = n.id
     LEFT JOIN tbl_staff   st ON st.id = mrd.staff_id
     WHERE mrd.meter_reading_id = $id
     ORDER BY mrd.id ASC");

if ($details_result === false) {
    $details_result = mysqli_query($connection,
        "SELECT mrd.*, n.name AS nozzle_name, NULL AS exec_name
         FROM tbl_meter_reading_details mrd
         LEFT JOIN tbl_nozzles n ON mrd.nozzle_id = n.id
         WHERE mrd.meter_reading_id = $id
         ORDER BY mrd.id ASC");
}

$details = [];
if ($details_result) {
    while ($r = mysqli_fetch_assoc($details_result)) { $details[] = $r; }
}

$calcGrand = 0;
foreach ($details as $d) { $calcGrand += floatval($d['amount']); }
$grandDisplay = $calcGrand > 0 ? $calcGrand : floatval($header['grand_total']);
$hasDetails   = count($details) > 0;

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
<title>Meter Reading #<?php echo $id; ?> - PDF</title>
<link rel="stylesheet" href="../include/style.css?v=1.0.1">
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, sans-serif;
    font-size: 12px;
    color: #000;
    background: #ccc;
    padding: 20px;
}

/* Screen toolbar */
.screen-bar {
    background: var(--primary-color);
    color: #fff;
    padding: 10px 18px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    max-width: 1050px;
    margin-left: auto;
    margin-right: auto;
    border-radius: 5px;
}
.screen-bar span { font-size: 14px; font-weight: bold; }
.btn-print {
    background: #fff;
    color: var(--primary-color);
    border: none;
    padding: 7px 20px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
}
.btn-back {
    background: transparent;
    color: #fff;
    border: 1px solid rgba(255,255,255,0.5);
    padding: 7px 16px;
    border-radius: 4px;
    font-size: 13px;
    text-decoration: none;
    margin-right: 8px;
    display: inline-block;
}

/* Paper */
.paper {
    background: #fff;
    width: 1050px;
    margin: 0 auto;
    padding: 24px 28px;
    border: 1px solid #aaa;
}

/* Document header */
.doc-header {
    text-align: center;
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 8px;
    margin-bottom: 12px;
}
.doc-header h1 { font-size: 16px; color: var(--primary-color); font-weight: bold; }
.doc-header p  { font-size: 11px; color: #555; margin-top: 2px; }

/* Info grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 0;
    border: 1px solid #ccc;
    margin-bottom: 14px;
}
.info-cell {
    padding: 6px 10px;
    border-right: 1px solid #ccc;
    border-bottom: 1px solid #ccc;
}
.info-cell:nth-child(4n) { border-right: none; }
.info-cell .lbl {
    font-size: 9px;
    text-transform: uppercase;
    color: #888;
    font-weight: bold;
    letter-spacing: 0.5px;
}
.info-cell .val { font-size: 13px; font-weight: bold; color: var(--primary-color); margin-top: 2px; }
.info-cell .val.big { font-size: 17px; }

/* Section bar */
.sec-bar {
    background: var(--primary-color);
    color: #fff;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 0.3px;
}
.sec-bar.dark { background: #263238; }

/* Main nozzle table */
.nozzle-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 10.5px;
    table-layout: fixed;
}
.nozzle-tbl th {
    background: #2c3e50;
    color: #fff;
    padding: 5px 4px;
    text-align: center;
    border: 1px solid #444;
    font-size: 9.5px;
    font-weight: bold;
    line-height: 1.3;
    word-wrap: break-word;
}
.nozzle-tbl td {
    padding: 5px 4px;
    border: 1px solid #ccc;
    vertical-align: middle;
    word-wrap: break-word;
}
.nozzle-tbl tbody tr:nth-child(even) { background: #f5f5f5; }
.nozzle-tbl td.r  { text-align: right; }
.nozzle-tbl td.c  { text-align: center; }
.nozzle-tbl .th-sale { background: #1b5e20; }
.nozzle-tbl .th-test { background: #bf360c; }
.nozzle-tbl .th-net  { background: #0d47a1; }
.nozzle-tbl .th-amt  { background: #4a148c; }

.col-sale { background: #e8f5e9; font-weight: bold; }
.col-test { background: #fff3e0; }
.col-net  { background: #e3f2fd; font-weight: bold; }
.col-amt  { background: #ede7f6; font-weight: bold; }
.col-rate { background: #fff8e1; font-weight: bold; }

.sub-formula { font-size: 8.5px; color: #777; display: block; margin-top: 1px; }

/* Totals row */
.totals-row td {
    background: #eceff1;
    font-weight: bold;
    border-top: 2px solid #888;
    padding: 5px 4px;
    font-size: 10.5px;
}

/* Grand total row */
.grand-row td {
    background: var(--primary-color);
    color: #fff;
    font-weight: bold;
    padding: 7px 6px;
    font-size: 11px;
}
.grand-row td.r { text-align: right; font-size: 13px; }

/* Calc breakdown table */
.calc-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
    table-layout: fixed;
}
.calc-tbl th {
    background: #37474f;
    color: #fff;
    padding: 4px 6px;
    text-align: center;
    border: 1px solid #555;
    font-size: 9.5px;
}
.calc-tbl td {
    padding: 4px 6px;
    border: 1px solid #ccc;
    text-align: right;
}
.calc-tbl td.name { text-align: left; font-weight: bold; }
.calc-tbl td.op   { text-align: center; color: #999; background: #fafafa; }
.calc-tbl tfoot td {
    background: var(--primary-color);
    color: #fff;
    font-weight: bold;
    text-align: right;
    padding: 5px 6px;
    font-size: 11px;
}

/* Signatures */
.sig-section {
    display: flex;
    justify-content: space-between;
    margin-top: 28px;
    gap: 30px;
}
.sig-box {
    flex: 1;
    text-align: center;
    font-size: 10px;
    font-weight: bold;
    color: #333;
    padding-top: 6px;
    border-top: 1px solid #000;
    margin-top: 34px;
}

/* Footer */
.doc-footer {
    margin-top: 14px;
    border-top: 1px solid #ccc;
    padding-top: 7px;
    display: flex;
    justify-content: space-between;
    font-size: 9.5px;
    color: #666;
}

@media print {
    body    { background:#fff !important; padding:0 !important; }
    .screen-bar { display:none !important; }
    .paper  { border:none; padding:6mm 8mm; width:100%; }
    * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    @page   { size: A4 landscape; margin: 6mm 8mm; }
    .nozzle-tbl { font-size: 9px !important; }
    .nozzle-tbl th { font-size: 8.5px !important; padding: 4px 3px !important; }
    .nozzle-tbl td { padding: 4px 3px !important; }
    .calc-tbl   { font-size: 9px !important; }
    .calc-tbl th, .calc-tbl td { padding: 3px 4px !important; }
    .grand-row td { padding: 5px 4px !important; }
}
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="screen-bar">
    <span>Meter Reading #<?php echo $id; ?> &mdash; Print / Save as PDF</span>
    <div>
        <a href="view-meter-reading.php?id=<?php echo $id; ?>" class="btn-back">&larr; Back</a>
        <button onclick="window.print()" class="btn-print">&#128438; Print / Save PDF</button>
    </div>
</div>

<!-- Paper (A4 landscape) -->
<div class="paper">

    <!-- Document header -->
    <div class="doc-header">
        <h1>PPMS &mdash; Petrol Pump Management System</h1>
        <p>Meter Reading Report &nbsp;&bull;&nbsp; Reading #<?php echo $id; ?></p>
    </div>

    <!-- Info grid -->
    <div class="info-grid">
        <div class="info-cell">
            <div class="lbl">Reading No.</div>
            <div class="val">#<?php echo $id; ?></div>
        </div>
        <div class="info-cell">
            <div class="lbl">Date</div>
            <div class="val"><?php echo date('d-m-Y', strtotime($header['date'])); ?></div>
        </div>
        <div class="info-cell">
            <div class="lbl">Shift</div>
            <div class="val"><?php echo htmlspecialchars($header['shift_name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-cell">
            <div class="lbl">Total Nozzles</div>
            <div class="val"><?php echo $hasDetails ? count($details) : 'N/A'; ?></div>
        </div>
        <div class="info-cell">
            <div class="lbl">Created At</div>
            <div class="val" style="font-size:11px;"><?php echo date('d-m-Y h:i A', strtotime($header['created_at'])); ?></div>
        </div>
        <div class="info-cell">
            <div class="lbl">Printed At</div>
            <div class="val" style="font-size:11px;"><?php echo date('d-m-Y h:i A'); ?></div>
        </div>
        <div class="info-cell" style="background:#e8eaf6;">
            <div class="lbl">Grand Total (Rs.)</div>
            <div class="val big">Rs. <?php echo number_format($grandDisplay, 2); ?></div>
        </div>
        <?php if (!empty($header['deleted_at'])): ?>
        <div class="info-cell full" style="background:#ffebee;">
            <div class="lbl" style="color:#b71c1c;">Status</div>
            <div class="val" style="color:#b71c1c;font-size:11px;">
                &#9888; SOFT DELETED &mdash; <?php echo date('d-m-Y h:i A', strtotime($header['deleted_at'])); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($hasDetails): ?>

    <!-- Nozzle readings table -->
    <div class="sec-bar">
        Nozzle-wise Reading Details &nbsp;&mdash;&nbsp;
        Formula: Sale = Current &minus; Last &nbsp;|&nbsp; Net = Sale &minus; Test &nbsp;|&nbsp; Amount (Rs.) = Net &times; Rate
    </div>
    <table class="nozzle-tbl">
        <colgroup>
            <col style="width:3%">
            <col style="width:8%">
            <col style="width:7%">
            <col style="width:10%">
            <col style="width:6%">
            <col style="width:8%">
            <col style="width:8%">
            <col style="width:9%">
            <col style="width:7%">
            <col style="width:9%">
            <col style="width:10%">
        </colgroup>
        <thead>
            <tr>
                <th>#</th>
                <th>Nozzle</th>
                <th>Fuel / Item</th>
                <th>Sales Staff</th>
                <th>Rate<br>(Rs.)</th>
                <th>Last<br>Reading</th>
                <th>Current<br>Reading</th>
                <th class="th-sale">Sale Reading<br><span style="font-size:8px;font-weight:400;">(Curr&minus;Last)</span></th>
                <th class="th-test">Test<br>Reading</th>
                <th class="th-net">Net Sale<br><span style="font-size:8px;font-weight:400;">(Sale&minus;Test)</span></th>
                <th class="th-amt">Amount (Rs.)<br><span style="font-size:8px;font-weight:400;">(Net&times;Rate)</span></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rn = 1;
        $sumSale = $sumTest = $sumNet = $sumAmt = 0;
        foreach ($details as $d):
            $price = floatval($d['price']);
            $lastR = floatval($d['last_reading']);
            $currR = floatval($d['current_reading']);
            $saleR = floatval($d['sale_reading']);
            $testR = floatval($d['test_reading']);
            $netS  = floatval($d['net_sale']);
            $amt   = floatval($d['amount']);
            $sumSale += $saleR; $sumTest += $testR; $sumNet += $netS; $sumAmt += $amt;
        ?>
        <tr>
            <td class="c"><?php echo $rn++; ?></td>
            <td><strong><?php echo htmlspecialchars($d['nozzle_name'] ?? 'N/A'); ?></strong></td>
            <td class="c"><?php echo htmlspecialchars($d['item_type'] ?? 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($d['exec_name'] ?? '—'); ?></td>
            <td class="r col-rate"><?php echo number_format($price, 2); ?></td>
            <td class="r"><?php echo number_format($lastR, 2); ?></td>
            <td class="r"><?php echo number_format($currR, 2); ?></td>
            <td class="r col-sale">
                <?php echo number_format($saleR, 2); ?>
            </td>
            <td class="r col-test"><?php echo number_format($testR, 2); ?></td>
            <td class="r col-net">
                <?php echo number_format($netS, 2); ?>
            </td>
            <td class="r col-amt">
                Rs. <?php echo number_format($amt, 2); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="totals-row">
                <td colspan="7" class="r" style="font-size:9.5px;letter-spacing:0.3px;">
                    TOTALS (<?php echo count($details); ?> nozzle<?php echo count($details)!=1?'s':''; ?>)
                </td>
                <td class="r col-sale"><?php echo number_format($sumSale,2); ?></td>
                <td class="r col-test"><?php echo number_format($sumTest,2); ?></td>
                <td class="r col-net"><?php echo number_format($sumNet,2); ?></td>
                <td class="r col-amt">Rs. <?php echo number_format($sumAmt,2); ?></td>
            </tr>
            <tr class="grand-row">
                <td colspan="10" style="text-align:right;letter-spacing:0.8px;">
                    GRAND TOTAL &mdash; Sum of All Nozzle Amounts
                </td>
                <td class="r">Rs. <?php echo number_format($grandDisplay,2); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php endif; ?>

    <?php if (!empty($header['remarks'])): ?>
    <div style="margin-top:14px; border:1px solid #ccc; padding:8px 12px; border-radius:4px; background:#fafafa;">
        <span style="font-size:9px; text-transform:uppercase; color:#777; font-weight:700; display:block; margin-bottom:4px;">Remarks</span>
        <div style="font-size:11px; color:#333; line-height:1.4; white-space:pre-line;"><?php echo htmlspecialchars($header['remarks']); ?></div>
    </div>
    <?php endif; ?>

    <!-- Card Sale Details Table (PDF Export - ALL Entries) -->
    <?php if (!empty($card_sales)): ?>
    <div style="margin-top:14px;">
        <div class="sec-bar" style="background:#17a2b8;">
            Card Sale Details (<?php echo count($card_sales); ?> Entries)
        </div>
        <table class="nozzle-tbl" style="margin-top:5px; font-size:9.5px;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="width:4%; color:#000; background:#ddd;">#</th>
                    <th style="width:16%; color:#000; background:#ddd;">Nozzle</th>
                    <th style="width:20%; color:#000; background:#ddd;">Card Machine</th>
                    <th style="width:14%; color:#000; background:#ddd;">Batch No</th>
                    <th style="width:10%; color:#000; background:#ddd;" class="c">Cards</th>
                    <th style="width:12%; color:#000; background:#ddd;" class="r">Amount (Rs.)</th>
                    <th style="width:12%; color:#000; background:#ddd;" class="r">Service Charges</th>
                    <th style="width:12%; color:#000; background:#ddd;" class="r">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $pcsn = 1; foreach ($card_sales as $cs): ?>
                <tr>
                    <td class="c"><?php echo $pcsn++; ?></td>
                    <td><strong><?php echo htmlspecialchars($cs['nozzle_name'] ?? '—'); ?></strong></td>
                    <td><?php echo htmlspecialchars($cs['machine_name'] ?? '—'); ?></td>
                    <td class="c"><?php echo htmlspecialchars($cs['batch_no'] ?? '—'); ?></td>
                    <td class="c"><?php echo htmlspecialchars($cs['no_of_cards'] ?? '0'); ?></td>
                    <td class="r" style="font-weight:bold;">Rs. <?php echo number_format($cs['amount'], 2); ?></td>
                    <td class="r">Rs. <?php echo number_format($cs['service_charges'], 2); ?></td>
                    <td class="r" style="font-weight:bold;">Rs. <?php echo number_format($cs['net_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="totals-row">
                    <td colspan="5" class="r">Total Card Sale:</td>
                    <td class="r col-amt">Rs. <?php echo number_format($card_sales_total, 2); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Credit Sale Details Table (PDF Export - ALL Entries) -->
    <?php if (!empty($credit_sales)): ?>
    <div style="margin-top:14px;">
        <div class="sec-bar" style="background:#ffc107; color:#000;">
            Credit Sale Details (<?php echo count($credit_sales); ?> Entries)
        </div>
        <table class="nozzle-tbl" style="margin-top:5px; font-size:9.5px;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="width:3%; color:#000; background:#ddd;">#</th>
                    <th style="width:10%; color:#000; background:#ddd;">Nozzle</th>
                    <th style="width:10%; color:#000; background:#ddd;">Item</th>
                    <th style="width:8%; color:#000; background:#ddd;">Slip Date</th>
                    <th style="width:8%; color:#000; background:#ddd;">Slip No</th>
                    <th style="width:10%; color:#000; background:#ddd;">Account No</th>
                    <th style="width:10%; color:#000; background:#ddd;">Vehicle No</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Qty</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Rate</th>
                    <th style="width:8%; color:#000; background:#ddd;" class="r">Amount</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Cash Rate</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Issue Qty</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Bal 1</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Bal 2</th>
                    <th style="width:6%; color:#000; background:#ddd;" class="r">Wasoli</th>
                </tr>
            </thead>
            <tbody>
                <?php $pcrn = 1; foreach ($credit_sales as $crs): ?>
                <tr>
                    <td class="c"><?php echo $pcrn++; ?></td>
                    <td><strong><?php echo htmlspecialchars($crs['nozzle_name'] ?? '—'); ?></strong></td>
                    <td class="c"><?php echo htmlspecialchars($crs['item_name'] ?? '—'); ?></td>
                    <td class="c"><?php echo date('d-m-Y', strtotime($crs['slip_date'])); ?></td>
                    <td class="c"><?php echo htmlspecialchars($crs['slip_no'] ?? '—'); ?></td>
                    <td class="c"><?php echo htmlspecialchars($crs['account_number'] ?? '—'); ?></td>
                    <td class="c"><?php echo htmlspecialchars($crs['vehicle_number'] ?? '—'); ?></td>
                    <td class="r"><?php echo number_format($crs['quantity'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['rate'], 2); ?></td>
                    <td class="r col-amt" style="font-weight:bold; color:#000;">Rs. <?php echo number_format($crs['amount'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['cash_rate'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['issue_quantity'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['balance_1'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['balance_2'], 2); ?></td>
                    <td class="r"><?php echo number_format($crs['wasoli'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="totals-row">
                    <td colspan="9" class="r">Total Credit Sale:</td>
                    <td class="r col-amt">Rs. <?php echo number_format($credit_sales_total, 2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="sig-section">
        <div class="sig-box">Prepared By</div>
        <div class="sig-box">Verified By</div>
        <div class="sig-box">Authorized By</div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>PPMS &mdash; Petrol Pump Management System</span>
        <span>Reading #<?php echo $id; ?> &nbsp;|&nbsp; <?php echo date('d-m-Y', strtotime($header['date'])); ?></span>
        <span>Printed: <?php echo date('d-m-Y h:i A'); ?></span>
    </div>

</div><!-- /.paper -->
</body>
</html>
