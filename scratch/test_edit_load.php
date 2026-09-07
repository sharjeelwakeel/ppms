<?php
require 'include/config.php';

$target_date = '2026-09-07';
$target_shift = 1;

$del_shift_clause = " AND shift_id = '$target_shift'";
$sql_existing = "SELECT * FROM tbl_meter_reading_credit_sales 
                WHERE slip_date = '$target_date' $del_shift_clause 
                  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
                ORDER BY id ASC";
$res_existing = mysqli_query($connection, $sql_existing);
$existing_rows = [];
while ($r = mysqli_fetch_assoc($res_existing)) {
    // Self-heal logic
    if (!empty($r['temp_slip_id']) || !empty($r['temp_slip_no'])) {
        if (floatval($r['wasoli'] ?? 0) == 0) {
            $ts_id = intval($r['temp_slip_id'] ?? 0);
            $ts_q = null;
            if ($ts_id > 0) {
                $ts_q = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE id = '$ts_id'");
            }
            if ((!$ts_q || mysqli_num_rows($ts_q) == 0) && !empty($r['temp_slip_no'])) {
                $ts_no = mysqli_real_escape_string($connection, $r['temp_slip_no']);
                $ts_acc = mysqli_real_escape_string($connection, $r['account_number']);
                $ts_q = mysqli_query($connection, "SELECT quantity, rate FROM tbl_meter_reading_credit_sales WHERE slip_no = '$ts_no' AND slip_type = 'Temporary Slip' AND account_number = '$ts_acc' ORDER BY id DESC LIMIT 1");
            }
            if ($ts_q && $ts_row = mysqli_fetch_assoc($ts_q)) {
                $r['wasoli'] = $ts_row['quantity'];
                if (floatval($r['temp_rate'] ?? 0) == 0) {
                    $r['temp_rate'] = $ts_row['rate'];
                }
            }
        }
    }
    $existing_rows[] = $r;
}

echo "Found " . count($existing_rows) . " rows:\n";
foreach ($existing_rows as $row) {
    echo "ID: {$row['id']} | Slip: {$row['slip_no']} | Type: {$row['slip_type']} | Qty: {$row['quantity']} | Rate: {$row['rate']} | Wasoli: {$row['wasoli']} | TempID: {$row['temp_slip_id']} | TempNo: {$row['temp_slip_no']}\n";
}
