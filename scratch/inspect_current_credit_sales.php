<?php
require 'include/config.php';
$res = mysqli_query($connection, "SELECT id, slip_no, slip_type, slip_date, shift_id, quantity, rate, amount, charge_amount, wasoli, temp_slip_id, temp_slip_no, temp_rate, deleted_at FROM tbl_meter_reading_credit_sales ORDER BY id DESC LIMIT 20");
while ($r = mysqli_fetch_assoc($res)) {
    echo json_encode($r) . "\n";
}
