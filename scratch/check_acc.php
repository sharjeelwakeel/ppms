<?php
require 'include/config.php';
$res = mysqli_query($connection, "SELECT id, slip_no, slip_type, account_number, vehicle_number, quantity, wasoli, temp_slip_id, temp_slip_no FROM tbl_meter_reading_credit_sales WHERE id IN (78, 81, 82)");
while ($r = mysqli_fetch_assoc($res)) {
    echo json_encode($r) . "\n";
}
