<?php
require_once __DIR__ . '/../include/config.php';

$res = mysqli_query($connection, "SELECT * FROM tbl_meter_reading_credit_sales WHERE slip_date = '2026-09-07' ORDER BY id DESC");

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID: " . $row['id'] . " | Slip: " . $row['slip_no'] . " | Type: " . $row['slip_type'] . " | Del: " . $row['deleted_at'] . "\n";
        echo "  qty: " . $row['quantity'] . " | issue_qty: " . $row['issue_quantity'] . " | wasoli: " . $row['wasoli'] . " | charge: " . $row['charge_amount'] . "\n";
        echo "  temp_id: " . $row['temp_slip_id'] . " | temp_no: " . $row['temp_slip_no'] . " | temp_date: " . $row['temp_slip_date'] . " | temp_rate: " . $row['temp_rate'] . "\n";
        echo "  is_ret: " . $row['is_returned'] . " | ret_at: " . $row['returned_at'] . " | settled_in: " . $row['settled_in_slip_id'] . "\n\n";
    }
}
