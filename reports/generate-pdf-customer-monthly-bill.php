<?php
/**
 * Printable / PDF Generator: Customer Overall Monthly Credit Bill
 * PPMS (Petrol Pump Management System)
 * 
 * Generates an exact-match printable sheet replicating the paper credit bill voucher.
 * Automatically configured for standard A4 printing and browser PDF export.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedInUser'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../include/config.php';
require_once '../include/permissions.php';
require_once '../include/settings_helper.php';
require_once '../include/customer_monthly_bill_helper.php';

// RBAC Authorization Gate
if (!has_permission('reports', 'show') && !has_permission('customers', 'show') && !has_permission('meter_readings', 'show')) {
    header('Location: ../dashboard.php');
    exit;
}

$customer_id    = intval($_GET['customer_id'] ?? 0);
$vehicle_number = trim($_GET['vehicle_number'] ?? '');
$from_date      = trim($_GET['from_date'] ?? '');
$to_date        = trim($_GET['to_date'] ?? '');
$bill_no_input  = trim($_GET['bill_no'] ?? '');
$attention_to   = trim($_GET['attention_to'] ?? '');

// If bill_no is provided without dates, resolve directly from tbl_customer_monthly_bills
if (!empty($bill_no_input) && ($customer_id <= 0 || empty($from_date) || empty($to_date))) {
    $found_bill = get_monthly_bill_by_number($connection, $bill_no_input);
    if ($found_bill) {
        $customer_id    = intval($found_bill['customer_id']);
        $from_date      = $found_bill['from_date'];
        $to_date        = $found_bill['to_date'];
        $vehicle_number = $found_bill['vehicle_number'] ?? '';
        $bill_no_input  = $found_bill['bill_no'];
        $attention_to   = $found_bill['attention_to'] ?? '';
    }
}

if ($customer_id <= 0 || empty($from_date) || empty($to_date)) {
    die("Invalid request: Bill not found or invalid customer and date parameters.");
}

$bill_data = get_customer_monthly_bill_data($connection, $customer_id, $from_date, $to_date, $vehicle_number, [
    'bill_no'       => $bill_no_input,
    'attention_to'  => $attention_to
]);

if (!$bill_data || !$bill_data['customer']) {
    die("Customer not found or invalid customer ID.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit_Bill_<?php echo htmlspecialchars($bill_data['bill_no']); ?>_<?php echo htmlspecialchars($bill_data['customer']['name']); ?></title>
    <link rel="stylesheet" href="../include/css/bootstrap.min.css">
    <link rel="stylesheet" href="../include/css/all.min.css">
    <style>
        /* Screen and Print Typography & Layout */
        body {
            background-color: #525659;
            margin: 0;
            padding: 20px 0;
            font-family: 'Times New Roman', Times, Georgia, serif;
            color: #000;
        }

        .no-print-bar {
            background: #ffffff;
            border-bottom: 1px solid #ddd;
            padding: 10px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .bill-paper {
            background: #ffffff;
            width: 210mm;
            min-height: 280mm;
            margin: 0 auto;
            padding: 16mm 18mm 18mm 18mm;
            box-shadow: 0 4px 15px rgba(0,0,0,0.25);
            box-sizing: border-box;
            position: relative;
        }

        .bill-header-title {
            font-size: 23pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #000;
            margin-bottom: 2px;
            text-align: center;
        }

        .bill-header-sub {
            font-size: 13pt;
            font-weight: bold;
            color: #000;
            margin-bottom: 1px;
            text-align: center;
        }

        .bill-header-dates {
            font-size: 13.5pt;
            font-weight: bold;
            margin-top: 8px;
            margin-bottom: 12px;
            text-align: center;
        }

        /* Tables & Grids */
        table.voucher-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        table.voucher-grid, table.voucher-grid th, table.voucher-grid td {
            border: 1.2px solid #000;
        }

        table.meta-grid td {
            padding: 4px 8px;
            font-size: 11pt;
            font-weight: bold;
            vertical-align: middle;
        }

        table.items-grid th {
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
            padding: 4px 6px;
            background: #fff;
        }

        table.items-grid td {
            font-size: 11pt;
            padding: 3.5px 6px;
            vertical-align: middle;
        }

        .sig-block {
            font-size: 11.5pt;
            font-weight: bold;
            text-transform: uppercase;
            text-align: right;
            margin-top: 14px;
        }

        .disclaimer-note {
            font-size: 9.5pt;
            line-height: 1.28;
            margin-top: 6px;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .bill-paper {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            @page {
                size: A4 portrait;
                margin: 12mm 14mm 12mm 14mm;
            }
        }
    </style>
</head>
<body>

    <!-- Non-Printing Control Toolbar -->
    <div class="no-print-bar text-center no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg px-4 mr-2 shadow-sm font-weight-bold">
            <i class="fas fa-print mr-2"></i> Print / Save as PDF
        </button>
        <button onclick="window.close()" class="btn btn-outline-secondary btn-lg px-4 shadow-sm">
            <i class="fas fa-times mr-2"></i> Close
        </button>
        <span class="ml-4 badge badge-dark p-2 font-weight-normal" style="font-size: 13px;">
            <i class="fas fa-file-invoice mr-1"></i> Official Demand Credit Bill
        </span>
    </div>

    <!-- Official Paper Bill Container -->
    <div class="bill-paper">
        <!-- Station Letterhead -->
        <div class="bill-header-title">
            <?php echo htmlspecialchars($bill_data['station']['pump_name'] ?? 'KHURRAM PETROLEUM SERVICE & CNG'); ?>
        </div>
        <div class="bill-header-sub">
            <?php echo htmlspecialchars($bill_data['station']['tagline'] ?? 'Dealers :- Pakistan State Oil Ltd.'); ?>
        </div>
        <div class="bill-header-sub">
            <?php echo htmlspecialchars($bill_data['station']['address'] ?? 'Hasilpur Road'); ?>, 
            <?php echo htmlspecialchars($bill_data['station']['city'] ?? 'Bahawalpur (City)'); ?>.
        </div>
        <div class="bill-header-sub">
            <?php echo htmlspecialchars($bill_data['station']['phone'] ?? '062-2283577'); ?>
        </div>
        <div class="bill-header-dates">
            Credit Bill <?php echo htmlspecialchars($bill_data['date_range_label']); ?>
        </div>

        <!-- Customer & Bill Metadata Table -->
        <table class="voucher-grid meta-grid" style="margin-bottom: 0;">
            <tr>
                <td style="width: 10%;">A/cNo</td>
                <td style="width: 50%;"><?php echo htmlspecialchars($bill_data['customer_id']); ?></td>
                <td style="width: 14%;">Vehicle</td>
                <td style="width: 26%;"><?php echo htmlspecialchars($bill_data['vehicle_display']); ?></td>
            </tr>
            <tr>
                <td>To</td>
                <td><?php echo htmlspecialchars($bill_data['attention_to']); ?></td>
                <td>Bill No.</td>
                <td><?php echo htmlspecialchars($bill_data['bill_no']); ?></td>
            </tr>
            <tr>
                <td>Name</td>
                <td><?php echo htmlspecialchars($bill_data['customer']['name'] ?? 'N/A'); ?></td>
                <td></td>
                <td></td>
            </tr>
        </table>

        <!-- Itemized Transactions Grid -->
        <table class="voucher-grid items-grid" style="border-top: none; margin-bottom: 0;">
            <thead>
                <tr>
                    <th style="width: 7%; border-top: none;">S.No.</th>
                    <th style="width: 14%; border-top: none;">Date</th>
                    <th style="width: 12%; border-top: none;">Coupon</th>
                    <th style="width: 27%; border-top: none;">Description</th>
                    <th style="width: 13%; border-top: none;">Quantity</th>
                    <th style="width: 13%; border-top: none;">Rate</th>
                    <th style="width: 14%; border-top: none;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($bill_data['transactions'])): ?>
                    <?php foreach ($bill_data['transactions'] as $row): ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $row['s_no']; ?></td>
                            <td style="text-align: center;"><?php echo $row['date_formatted']; ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($row['coupon']); ?></td>
                            <td style="text-align: left; padding-left: 8px;"><?php echo htmlspecialchars($row['description']); ?></td>
                            <td style="text-align: right; padding-right: 8px;"><?php echo number_format($row['quantity'], 2); ?></td>
                            <td style="text-align: right; padding-right: 8px;"><?php echo number_format($row['rate'], 2); ?></td>
                            <td style="text-align: right; padding-right: 8px;"><?php echo number_format($row['billed_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px; color: #555;">
                            No outstanding credit vouchers found for this billing period.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals, Words, Notes, and Signature Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 4px;">
            <!-- Left Info Block -->
            <div style="width: 62%; padding-right: 15px;">
                <div style="font-size: 11pt; font-weight: bold; margin-bottom: 4px;">
                    Total No of Coupons &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo intval($bill_data['total_coupons']); ?>
                </div>
                <div style="font-size: 10.5pt; font-weight: bold; line-height: 1.35; margin-bottom: 6px;">
                    <?php echo htmlspecialchars($bill_data['amount_in_words']); ?>
                </div>
                <div class="disclaimer-note">
                    <strong>Note:</strong> <?php echo htmlspecialchars($bill_data['station']['receipt_footer'] ?? 'If payment is not made within 7 days after receipt of bills supplies may withheld without further information and 10% surcharge will be recoverable per month. Includes service charges & taxes'); ?>
                </div>
            </div>

            <!-- Right Total Block -->
            <div style="width: 38%;">
                <table class="voucher-grid" style="margin-bottom: 0;">
                    <tr>
                        <td style="width: 55%; font-size: 11pt; font-weight: bold; padding: 4px 6px;">Total Bill Amount</td>
                        <td style="width: 45%; font-size: 11pt; font-weight: bold; text-align: right; padding: 4px 6px;">
                            <?php echo number_format($bill_data['total_amount'], 2); ?>
                        </td>
                    </tr>
                    <tr style="height: 22px;">
                        <td></td>
                        <td></td>
                    </tr>
                </table>
                <div class="sig-block">
                    <?php echo htmlspecialchars($bill_data['station']['pump_name'] ?? 'KHURRAM PETROLEUM SERVICE & CNG'); ?>
                </div>
            </div>
        </div>

        <!-- Category Breakdown Box (Bottom Left) -->
        <div style="width: 50%; margin-top: 10px;">
            <table class="voucher-grid">
                <thead>
                    <tr>
                        <th style="width: 45%; text-align: left; font-size: 10.5pt; font-weight: bold; padding: 3px 6px;">Items Description</th>
                        <th style="width: 25%; text-align: center; font-size: 10.5pt; font-weight: bold; padding: 3px 6px;">Quantity</th>
                        <th style="width: 30%; text-align: right; font-size: 10.5pt; font-weight: bold; padding: 3px 6px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bill_data['category_summary'] as $cat): ?>
                        <tr>
                            <td style="font-size: 10.5pt; padding: 3px 6px;"><?php echo htmlspecialchars($cat['name']); ?></td>
                            <td style="text-align: center; font-size: 10.5pt; padding: 3px 6px;">
                                <?php if ($cat['quantity'] > 0): ?>
                                    <?php echo number_format($cat['quantity'], 0); ?> <?php echo htmlspecialchars($cat['unit']); ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-size: 10.5pt; padding: 3px 6px;"><?php echo number_format($cat['amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold;">
                        <td style="font-size: 10.5pt; padding: 3px 6px;">Total</td>
                        <td style="padding: 3px 6px;"></td>
                        <td style="text-align: right; font-size: 10.5pt; padding: 3px 6px;"><?php echo number_format($bill_data['total_amount'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

</body>
</html>
