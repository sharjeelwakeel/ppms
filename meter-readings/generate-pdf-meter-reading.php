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

/* ── Screen toolbar ── */
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

/* ── A4 Landscape paper ── */
.paper {
    background: #fff;
    width: 1050px;          /* ~A4 landscape width at 96dpi */
    margin: 0 auto;
    padding: 24px 28px;
    border: 1px solid #aaa;
}

/* ── Document header ── */
.doc-header {
    text-align: center;
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 8px;
    margin-bottom: 12px;
}
.doc-header h1 { font-size: 16px; color: var(--primary-color); font-weight: bold; }
.doc-header p  { font-size: 11px; color: #555; margin-top: 2px; }

/* ── Info grid ── */
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
.info-cell.full { grid-column: span 4; }
.info-cell.half { grid-column: span 2; }

/* ── Section bar ── */
.sec-bar {
    background: var(--primary-color);
    color: #fff;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 0.3px;
}
.sec-bar.dark { background: #263238; }

/* ── Main nozzle table ── */
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

/* ── Totals row ── */
.totals-row td {
    background: #eceff1;
    font-weight: bold;
    border-top: 2px solid #888;
    padding: 5px 4px;
    font-size: 10.5px;
}

/* ── Grand total row ── */
.grand-row td {
    background: var(--primary-color);
    color: #fff;
    font-weight: bold;
    padding: 7px 6px;
    font-size: 11px;
}
.grand-row td.r { text-align: right; font-size: 13px; }

/* ── Calc breakdown table ── */
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

/* ── No-detail box ── */
.no-detail-box {
    border: 2px solid #e65100;
    background: #fff3e0;
    padding: 16px 20px;
    margin-top: 10px;
    text-align: center;
}
.no-detail-box p { font-size: 12px; color: #333; margin-bottom: 8px; }
.grand-stored {
    font-size: 20px;
    font-weight: bold;
    color: var(--primary-color);
    border: 2px solid var(--primary-color);
    display: inline-block;
    padding: 8px 28px;
    margin-top: 4px;
    background: #fff;
}

/* ── Signatures ── */
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

/* ── Footer ── */
.doc-footer {
    margin-top: 14px;
    border-top: 1px solid #ccc;
    padding-top: 7px;
    display: flex;
    justify-content: space-between;
    font-size: 9.5px;
    color: #666;
}

/* ════════════════════
   PRINT
   ════════════════════ */
@media print {
    body    { background:#fff !important; padding:0 !important; }
    .screen-bar { display:none !important; }
    .paper  { border:none; padding:6mm 8mm; width:100%; }
    * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    @page   { size: A4 landscape; margin: 6mm 8mm; }

    /* Shrink fonts slightly for print */
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
            <div class="lbl">Payment Type</div>
            <div class="val"><?php echo htmlspecialchars($header['payment_type']); ?></div>
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
            <col style="width:3%">   <!-- # -->
            <col style="width:8%">   <!-- Nozzle -->
            <col style="width:7%">   <!-- Fuel -->
            <col style="width:10%">  <!-- Staff -->
            <col style="width:6%">   <!-- Rate -->
            <col style="width:8%">   <!-- Last -->
            <col style="width:8%">   <!-- Current -->
            <col style="width:9%">   <!-- Sale -->
            <col style="width:7%">   <!-- Test -->
            <col style="width:9%">   <!-- Net -->
            <col style="width:10%">  <!-- Amount -->
            <col style="width:7%">   <!-- Payment -->
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
                <th>Payment</th>
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
                <span class="sub-formula">(<?php echo number_format($currR,2); ?>&minus;<?php echo number_format($lastR,2); ?>)</span>
            </td>
            <td class="r col-test"><?php echo number_format($testR, 2); ?></td>
            <td class="r col-net">
                <?php echo number_format($netS, 2); ?>
                <span class="sub-formula">(<?php echo number_format($saleR,2); ?>&minus;<?php echo number_format($testR,2); ?>)</span>
            </td>
            <td class="r col-amt">
                Rs. <?php echo number_format($amt, 2); ?>
                <span class="sub-formula">(<?php echo number_format($netS,2); ?>&times;<?php echo number_format($price,2); ?>)</span>
            </td>
            <td class="c"><?php echo htmlspecialchars($d['payment_type']); ?></td>
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
                <td></td>
            </tr>
            <tr class="grand-row">
                <td colspan="10" style="text-align:right;letter-spacing:0.8px;">
                    GRAND TOTAL &mdash; Sum of All Nozzle Amounts
                </td>
                <td class="r">Rs. <?php echo number_format($grandDisplay,2); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <!-- Calculation breakdown -->
    <div style="margin-top:14px;">
        <div class="sec-bar dark">
            Grand Total Calculation &mdash; Step-by-Step per Nozzle
        </div>
        <table class="calc-tbl">
            <colgroup>
                <col style="width:10%">
                <col style="width:8%"><col style="width:3%"><col style="width:8%">
                <col style="width:3%"><col style="width:8%">
                <col style="width:3%"><col style="width:8%">
                <col style="width:3%"><col style="width:8%">
                <col style="width:3%"><col style="width:8%">
                <col style="width:3%"><col style="width:10%">
            </colgroup>
            <thead>
                <tr>
                    <th style="text-align:left;">Nozzle</th>
                    <th>Current</th><th>&minus;</th><th>Last</th>
                    <th>=</th><th>Sale</th>
                    <th>&minus;</th><th>Test</th>
                    <th>=</th><th>Net Sale</th>
                    <th>&times;</th><th>Rate (Rs.)</th>
                    <th>=</th><th>Amount (Rs.)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($details as $d):
                $p = floatval($d['price']); $l = floatval($d['last_reading']);
                $c = floatval($d['current_reading']); $s = floatval($d['sale_reading']);
                $t = floatval($d['test_reading']); $n = floatval($d['net_sale']);
                $a = floatval($d['amount']);
            ?>
            <tr>
                <td class="name"><?php echo htmlspecialchars($d['nozzle_name'] ?? 'N/A'); ?></td>
                <td><?php echo number_format($c,2); ?></td>
                <td class="op">&minus;</td>
                <td><?php echo number_format($l,2); ?></td>
                <td class="op">=</td>
                <td style="background:#e8f5e9;font-weight:bold;"><?php echo number_format($s,2); ?></td>
                <td class="op">&minus;</td>
                <td><?php echo number_format($t,2); ?></td>
                <td class="op">=</td>
                <td style="background:#e3f2fd;font-weight:bold;"><?php echo number_format($n,2); ?></td>
                <td class="op">&times;</td>
                <td style="background:#fff8e1;font-weight:bold;"><?php echo number_format($p,2); ?></td>
                <td class="op">=</td>
                <td style="background:#ede7f6;font-weight:bold;color:#4a148c;">Rs. <?php echo number_format($a,2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="13" style="text-align:right;">
                        GRAND TOTAL = Sum of all Amount columns above
                    </td>
                    <td>Rs. <?php echo number_format($grandDisplay,2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php else: ?>

    <!-- Old record — no detail rows -->
    <div class="no-detail-box">
        <p>
            <strong>Note:</strong> This record (ID #<?php echo $id; ?>) was saved before the nozzle detail
            tracking system was set up. Individual nozzle data is not available.
        </p>
        <p>Grand Total stored at the time of saving:</p>
        <div class="grand-stored">Rs. <?php echo number_format(floatval($header['grand_total']),2); ?></div>
        <p style="margin-top:10px;font-size:11px;color:#777;">
            Create a new meter reading to get a full nozzle-wise breakdown.
        </p>
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
